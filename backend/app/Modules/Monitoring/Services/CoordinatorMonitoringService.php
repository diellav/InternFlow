<?php

namespace App\Modules\Monitoring\Services;

use App\Models\AcademicCoordinatorProfile;
use App\Models\ActivityLog;
use App\Models\Internship;
use App\Models\Task;
use App\Models\User;
use App\Shared\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;

class CoordinatorMonitoringService
{
    public function internships(User $user): Builder
    {
        abort_unless($user->is_active && $user->hasRole(UserRole::ACADEMIC_COORDINATOR)
            && AcademicCoordinatorProfile::whereKey($user->id)->exists(), 403);

        return Internship::where('coordinator_id', $user->id)->whereIn('status', ['APPROVED', 'ACTIVE', 'COMPLETED']);
    }

    public function internship(User $user, int $id): Internship
    {
        return $this->internships($user)->with(['student.user', 'company', 'companySupervisor.user', 'coordinator.user'])
            ->withCount(['tasks', 'tasks as approved_tasks_count' => fn ($query) => $query->where('status', 'APPROVED')])->findOrFail($id);
    }

    public function activity(User $user, int $id): ActivityLog
    {
        return ActivityLog::whereIn('internship_id', $this->internships($user)->select('id'))->with('internship')->findOrFail($id);
    }

    public function tasks(User $user): Builder
    {
        return Task::whereIn('internship_id', $this->internships($user)->select('id'))
            ->with(['internship.student.user', 'internship.company', 'internship.companySupervisor', 'assigner:id,first_name,last_name'])
            ->withExists('submissions');
    }

    public function task(User $user, int $id): Task
    {
        return $this->tasks($user)->findOrFail($id);
    }
}
