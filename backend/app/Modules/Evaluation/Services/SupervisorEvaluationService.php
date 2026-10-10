<?php

namespace App\Modules\Evaluation\Services;

use App\Models\Company;
use App\Models\CompanySupervisorProfile;
use App\Models\FinalEvaluation;
use App\Models\Internship;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SupervisorEvaluationService
{
    public function owned(User $actor, int $id, bool $lock = false): Internship
    {
        $user = User::with('role')->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($actor->id);
        abort_unless($user->is_active && $user->hasRole(UserRole::COMPANY_SUPERVISOR), 403);
        $profile = CompanySupervisorProfile::when($lock, fn ($query) => $query->sharedLock())->find($user->id);
        abort_unless($profile !== null && $profile->verification_status === VerificationStatus::APPROVED, 403);
        $company = Company::when($lock, fn ($query) => $query->sharedLock())->find($profile->company_id);
        abort_unless($company !== null && $company->is_active && $company->verification_status === VerificationStatus::APPROVED, 403);

        $parent = Internship::where('company_supervisor_id', $user->id)->where('company_id', $company->id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
        $evaluation = $parent->finalEvaluation()->when($lock, fn ($query) => $query->lockForUpdate())->first();
        abort_if($evaluation !== null && $evaluation->evaluator_id !== $user->id, 404);

        return $parent->setRelation('finalEvaluation', $evaluation);
    }

    public function readinessReason(Internship $parent): ?string
    {
        if ($parent->finalEvaluation?->submitted_at !== null) {
            return 'SUBMITTED';
        }
        if ($parent->status !== 'ACTIVE') {
            return 'NOT_ACTIVE';
        }
        if ($parent->end_date === null || today(config('app.timezone'))->format('Y-m-d') < $parent->end_date->format('Y-m-d')) {
            return 'END_DATE_NOT_REACHED';
        }
        if ($parent->tasks()->whereIn('status', ['SUBMITTED', 'REVISION_REQUIRED'])->exists()
            || TaskSubmission::whereHas('task', fn ($query) => $query->where('internship_id', $parent->id))->whereDoesntHave('feedback')->exists()) {
            return 'UNRESOLVED_TASKS';
        }

        return null;
    }

    public function save(User $actor, int $id, array $data): Internship
    {
        return DB::transaction(function () use ($actor, $id, $data): Internship {
            $parent = $this->owned($actor, $id, true);
            abort_unless($parent->status === 'ACTIVE' && $parent->finalEvaluation?->submitted_at === null, 409, 'The evaluation is no longer editable.');
            $evaluation = $parent->finalEvaluation ?? new FinalEvaluation(['internship_id' => $parent->id, 'evaluator_id' => $actor->id]);
            $evaluation->fill(Arr::only($data, [...FinalEvaluation::RATINGS, 'comments']))->save();

            return $parent->setRelation('finalEvaluation', $evaluation);
        });
    }

    public function submit(User $actor, int $id, string $token): Internship
    {
        return DB::transaction(function () use ($actor, $id, $token): Internship {
            $parent = $this->owned($actor, $id, true);
            $evaluation = $parent->finalEvaluation;
            abort_unless($evaluation !== null && $evaluation->submitted_at === null
                && hash_equals($evaluation->draftToken(), $token), 409, 'The evaluation draft is missing, stale or already submitted.');
            abort_unless($this->readinessReason($parent) === null, 409, 'The internship is not ready for final evaluation.');
            $rules = array_fill_keys(FinalEvaluation::RATINGS, ['required', 'integer', 'between:1,5']);
            $values = $evaluation->only([...FinalEvaluation::RATINGS, 'comments']);
            $values['overall_score'] = $values['overall_score'] === null ? null : (float) $values['overall_score'];
            Validator::make($values, [...$rules, 'comments' => ['required', 'string', 'min:20', 'max:10000']])->validate();
            $timestamp = now()->toIso8601String();
            DB::table('final_evaluations')->where('id', $evaluation->id)->update(['submitted_at' => $timestamp]);

            return $parent->setRelation('finalEvaluation', $evaluation->refresh());
        });
    }
}
