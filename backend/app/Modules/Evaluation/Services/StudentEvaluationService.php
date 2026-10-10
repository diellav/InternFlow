<?php

namespace App\Modules\Evaluation\Services;

use App\Models\Internship;
use App\Models\User;
use App\Modules\Internship\Services\StudentInternshipService;
use App\Shared\Enums\UserRole;

class StudentEvaluationService
{
    public function view(User $actor, int $id): Internship
    {
        abort_unless($actor->is_active && $actor->hasRole(UserRole::STUDENT), 403);
        $parent = app(StudentInternshipService::class)->details($actor, $id);
        abort_unless($parent->status === 'COMPLETED' && $parent->completed_at !== null && $parent->coordinator_id !== null, 404);
        $evaluation = $parent->finalEvaluation()->whereNotNull('submitted_at')->with('evaluator.user:id,first_name,last_name')->firstOrFail();

        return $parent->setRelation('finalEvaluation', $evaluation);
    }
}
