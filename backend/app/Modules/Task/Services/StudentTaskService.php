<?php

namespace App\Modules\Task\Services;

use App\Models\Internship;
use App\Models\StudentProfile;
use App\Models\Task;
use App\Models\User;
use App\Shared\Enums\TaskStatus;
use App\Shared\Enums\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StudentTaskService
{
    public function owned(User $actor, int $id): Task
    {
        abort_unless(StudentProfile::whereKey($actor->id)->exists(), 403, 'A student profile is required.');

        return Task::whereHas('internship', fn ($query) => $query->where('student_id', $actor->id))->findOrFail($id);
    }

    public function transition(User $actor, int $id, ?array $submission = null, bool $resubmit = false): Task
    {
        $files = [];
        $paths = [];
        $committed = false;
        try {
            $this->owned($actor, $id);
            foreach ($submission['files'] ?? [] as $upload) {
                $path = Str::uuid().'.bin';
                $paths[] = $path;
                if (Storage::disk('task_attachments')->putFileAs('', $upload, $path, ['visibility' => 'private']) === false) {
                    throw new RuntimeException('The attachment could not be stored.');
                }
                $name = basename(str_replace('\\', '/', $upload->getClientOriginalName()));
                $name = preg_replace('/[\p{C}]/u', '', $name);
                $files[] = ['storage_path' => $path, 'original_name' => mb_strcut($name ?: 'attachment', 0, 240, 'UTF-8'),
                    'mime_type' => (new \finfo(FILEINFO_MIME_TYPE))->file($upload->getRealPath()), 'size_bytes' => $upload->getSize()];
            }
            $result = DB::transaction(function () use ($actor, $id, $submission, $resubmit, $files): Task {
                $user = User::with('role')->lockForUpdate()->findOrFail($actor->id);
                abort_unless($user->is_active && $user->hasRole(UserRole::STUDENT), 403);
                abort_unless(StudentProfile::whereKey($user->id)->sharedLock()->first(), 403);
                $parentId = $this->owned($user, $id)->internship_id;
                $internship = Internship::where('student_id', $user->id)->lockForUpdate()->findOrFail($parentId);
                abort_unless($internship->status === 'ACTIVE', 409, 'The internship is no longer active.');
                $task = Task::where('internship_id', $internship->id)->lockForUpdate()->findOrFail($id);
                $version = 1;
                if ($resubmit) {
                    abort_unless($task->status === TaskStatus::REVISION_REQUIRED->value, 409, 'This task is no longer awaiting corrections.');
                    $latest = $task->submissions()->orderByDesc('version_no')->lockForUpdate()->first();
                    abort_unless($latest !== null && $latest->id === (int) $submission['expected_submission_id']
                        && $latest->feedback()->sharedLock()->first()?->decision === TaskStatus::REVISION_REQUIRED->value, 409, 'The revision request is missing or stale.');
                    $version = $latest->version_no + 1;
                } else {
                    $expected = $submission === null ? 'ASSIGNED' : 'IN_PROGRESS';
                    abort_unless($task->status === $expected && ! $task->submissions()->exists(), 409, 'This task action is no longer available.');
                }
                if ($submission !== null) {
                    $record = $task->submissions()->create([
                        'version_no' => $version, 'submission_text' => $submission['submission_text'],
                        'resource_url' => $submission['resource_url'] ?? null, 'submitted_at' => now(),
                    ]);
                    $record->files()->createMany($files);
                }
                $task->forceFill(['status' => $submission === null ? 'IN_PROGRESS' : 'SUBMITTED'])->save();

                return app(TaskService::class)->details($user, $id, true);
            });
            $committed = true;

            return $result;
        } catch (QueryException $exception) {
            if ($resubmit && (string) $exception->getCode() === '23505' && str_contains((string) ($exception->errorInfo[2] ?? ''), 'task_submissions_task_id_version_no_unique')) {
                abort(409, 'Another submission version already exists. Refresh the task.');
            }

            throw $exception;
        } finally {
            if (! $committed && $paths !== []) {
                try {
                    if (! Storage::disk('task_attachments')->delete($paths)) {
                        throw new RuntimeException('Failed to clean up task attachment upload.');
                    }
                } catch (Throwable $cleanupFailure) {
                    report($cleanupFailure);
                }
            }
        }
    }
}
