<?php

namespace App\Modules\Evaluation\Services;

use App\Models\AcademicCoordinatorProfile;
use App\Models\Internship;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Modules\Monitoring\Services\CoordinatorMonitoringService;
use App\Modules\Notification\Services\NotificationDispatchService;
use App\Shared\Enums\UserRole;
use Illuminate\Support\Facades\DB;

class CoordinatorCompletionService
{
    public function owned(User $actor, int $id, bool $lock = false): Internship
    {
        $user = User::with('role')->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($actor->id);
        abort_unless($user->is_active && $user->hasRole(UserRole::ACADEMIC_COORDINATOR), 403);
        abort_unless(AcademicCoordinatorProfile::when($lock, fn ($query) => $query->sharedLock())->find($user->id) !== null, 403);
        $parent = Internship::where('coordinator_id', $user->id)->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
        $evaluation = $parent->finalEvaluation()->whereNotNull('submitted_at')->when($lock, fn ($query) => $query->lockForUpdate())->first();

        return $parent->setRelation('finalEvaluation', $evaluation);
    }

    public function view(User $actor, int $id): Internship
    {
        $parent = app(CoordinatorMonitoringService::class)->internship($actor, $id);

        return $parent->setRelation('finalEvaluation', $parent->finalEvaluation()->whereNotNull('submitted_at')->with('evaluator.user')->first());
    }

    public function readinessReason(Internship $parent): ?string
    {
        if ($parent->status === 'COMPLETED' || $parent->completed_at !== null) {
            return 'COMPLETED';
        }
        if ($parent->status !== 'ACTIVE') {
            return 'NOT_ACTIVE';
        }
        if ($parent->end_date === null || today(config('app.timezone'))->format('Y-m-d') < $parent->end_date->format('Y-m-d')) {
            return 'END_DATE_NOT_REACHED';
        }
        if ($parent->finalEvaluation === null || $parent->finalEvaluation->submitted_at === null || $parent->finalEvaluation->internship_id !== $parent->id) {
            return 'MISSING_SUBMITTED_EVALUATION';
        }
        if ($parent->tasks()->whereIn('status', ['SUBMITTED', 'REVISION_REQUIRED'])->exists()
            || TaskSubmission::whereHas('task', fn ($query) => $query->where('internship_id', $parent->id))->whereDoesntHave('feedback')->exists()) {
            return 'UNRESOLVED_TASKS';
        }

        return null;
    }

    public function complete(User $actor, int $id, int $evaluationId): Internship
    {
        return DB::transaction(function () use ($actor, $id, $evaluationId): Internship {
            $parent = $this->owned($actor, $id, true);
            abort_unless($this->readinessReason($parent) === null && $parent->finalEvaluation->id === $evaluationId, 409, 'The internship or final evaluation is no longer ready for completion.');
            $timestamp = now()->toIso8601String();
            DB::table('internships')->where('id', $parent->id)->update(['status' => 'COMPLETED', 'completed_at' => $timestamp, 'updated_at' => $timestamp]);
            app(NotificationDispatchService::class)->internshipCompleted($parent);

            return app(CoordinatorMonitoringService::class)->internship($actor, $id);
        });
    }
}
