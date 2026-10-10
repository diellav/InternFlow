<?php

namespace App\Modules\Task\Services;

use App\Models\Task;
use App\Models\User;
use App\Modules\Internship\Services\SupervisorInternshipService;
use App\Modules\Notification\Services\NotificationDispatchService;
use App\Shared\Enums\TaskStatus;
use Illuminate\Support\Facades\DB;

class TaskReviewService
{
    public function review(User $actor, int $id, array $data): Task
    {
        return DB::transaction(function () use ($actor, $id, $data): Task {
            $tasks = app(TaskService::class);
            $parentId = $tasks->details($actor, $id)->internship_id;
            $internship = app(SupervisorInternshipService::class)->lockedOwned($actor, $parentId);
            abort_unless($internship->status === 'ACTIVE', 409, 'The internship is no longer active.');
            $task = Task::where('internship_id', $internship->id)->lockForUpdate()->findOrFail($id);
            abort_unless($task->status === TaskStatus::SUBMITTED->value, 409, 'This task is no longer awaiting review.');
            $submission = $task->submissions()->orderByDesc('version_no')->lockForUpdate()->first();
            abort_unless($submission !== null && $submission->id === (int) $data['expected_submission_id'] && ! $submission->feedback()->exists(), 409, 'The submission is stale or has already been reviewed.');
            $decision = TaskStatus::from($data['decision']);
            $submission->feedback()->create(['supervisor_id' => $actor->id, 'decision' => $decision->value, 'comment' => $data['comment'] ?? null]);
            $task->forceFill(['status' => $decision->value])->save();
            app(NotificationDispatchService::class)->taskReviewed($internship, $task);

            return $tasks->details($actor, $id);
        });
    }
}
