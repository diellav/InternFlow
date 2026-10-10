<?php

namespace App\Modules\Task\Services;

use App\Models\StudentProfile;
use App\Models\Task;
use App\Models\User;
use App\Modules\Internship\Services\StudentInternshipService;
use App\Modules\Internship\Services\SupervisorInternshipService;
use App\Modules\Notification\Services\NotificationDispatchService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class TaskService
{
    public function __construct(private readonly SupervisorInternshipService $supervisors, private readonly StudentInternshipService $students) {}

    private array $relations = ['internship.student.user:id,first_name,last_name', 'internship.company:id,name,is_active,verification_status', 'internship.companySupervisor'];

    private function query(User $user, bool $student): Builder
    {
        if ($student) {
            abort_unless(StudentProfile::query()->whereKey($user->id)->exists(), 403, 'A student profile is required.');
        }

        return Task::query()->whereHas('internship', function (Builder $query) use ($user, $student): void {
            $query->where($student ? 'student_id' : 'company_supervisor_id', $user->id);
            if ($student) {
                $query->whereIn('status', ['ACTIVE', 'COMPLETED']);
            }
        })->with($this->relations)->withExists('submissions');
    }

    public function details(User $user, int $id, bool $student = false): Task
    {
        return $this->query($user, $student)->findOrFail($id);
    }

    public function paginate(User $user, int $internshipId, array $filters, bool $student = false): LengthAwarePaginator
    {
        $internship = $student ? $this->students->details($user, $internshipId) : $this->supervisors->details($user, $internshipId);
        abort_if($student && ! in_array($internship->status, ['ACTIVE', 'COMPLETED'], true), 404);
        $query = $this->query($user, $student)->where('internship_id', $internship->id);
        foreach (['status', 'priority'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $query->where('title', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%');
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 15, ['*'], 'page', $filters['page'] ?? 1)->withQueryString();
    }

    public function save(User $actor, array $data, ?int $internshipId = null, ?int $id = null): Task
    {
        return DB::transaction(function () use ($actor, $data, $internshipId, $id): Task {
            if ($id !== null) {
                $internshipId = $this->details($actor, $id)->internship_id;
            }
            $internship = $this->supervisors->lockedOwned($actor, $internshipId);
            abort_unless($internship->status === 'ACTIVE', 409, 'Tasks can be managed only for an active internship.');
            $task = $id === null ? new Task(['internship_id' => $internship->id, 'assigned_by' => $actor->id, 'status' => 'ASSIGNED'])
                : Task::query()->where('internship_id', $internship->id)->lockForUpdate()->findOrFail($id);
            abort_unless($task->status === 'ASSIGNED' && ! $task->submissions()->exists(), 409, 'This task is no longer editable.');
            $task->fill(Arr::only($data, ['title', 'description', 'priority', 'due_date']))->save();
            if ($id === null) {
                app(NotificationDispatchService::class)->taskAssigned($internship, $task);
            }

            return $task->load($this->relations)->loadExists('submissions');
        });
    }
}
