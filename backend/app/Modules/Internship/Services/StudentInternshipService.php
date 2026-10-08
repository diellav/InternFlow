<?php

namespace App\Modules\Internship\Services;

use App\Models\AcademicCoordinatorProfile;
use App\Models\Company;
use App\Models\CompanySupervisorProfile;
use App\Models\Internship;
use App\Models\StudentProfile;
use App\Models\User;
use App\Shared\Enums\InternshipStatus;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StudentInternshipService
{
    public function __construct(private readonly InternshipEligibilityService $eligibility) {}

    private array $relations = ['company:id,name,industry,is_active,verification_status', 'companySupervisor.user:id,first_name,last_name', 'coordinator.user:id,first_name,last_name'];

    private function studentId(User $user): int
    {
        $profile = StudentProfile::query()->find($user->id);
        abort_if($profile === null, 403, 'A student profile is required.');

        return $profile->user_id;
    }

    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = Internship::query()->where('student_id', $this->studentId($user))->with($this->relations);
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 15, ['*'], 'page', $filters['page'] ?? 1)->withQueryString();
    }

    public function details(User $user, int $id): Internship
    {
        return Internship::query()->where('student_id', $this->studentId($user))->with($this->relations)->findOrFail($id);
    }

    public function save(User $actor, array $data, ?int $id = null): Internship
    {
        return DB::transaction(function () use ($actor, $data, $id): Internship {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($user->is_active && $user->hasRole(UserRole::STUDENT), 403);
            $studentId = $this->studentId($user);
            $internship = $id === null ? new Internship(['student_id' => $studentId, 'status' => InternshipStatus::DRAFT->value])
                : Internship::query()->where('student_id', $studentId)->lockForUpdate()->findOrFail($id);
            abort_unless(in_array($internship->status, [InternshipStatus::DRAFT->value, InternshipStatus::REVISION_REQUIRED->value], true), 409, 'This internship is no longer editable.');
            $internship->fill(Arr::only($data, ['company_id', 'company_supervisor_id', 'position_title', 'description', 'start_date', 'end_date']));
            Validator::make(['start_date' => $internship->start_date?->format('Y-m-d'), 'end_date' => $internship->end_date?->format('Y-m-d')], [
                'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            ])->validate();
            $this->eligibility->validateSelection($internship->company_id, $internship->company_supervisor_id);
            $internship->save();

            return $internship->load($this->relations);
        });
    }

    public function submit(User $actor, int $id): Internship
    {
        return DB::transaction(function () use ($actor, $id): Internship {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($user->is_active && $user->hasRole(UserRole::STUDENT), 403);
            $internship = Internship::query()->where('student_id', $this->studentId($user))->lockForUpdate()->findOrFail($id);
            abort_unless($internship->status === InternshipStatus::DRAFT->value, 409, 'Only a draft can be submitted.');
            $this->eligibility->validateCompleted($internship);
            $internship->forceFill(['status' => InternshipStatus::SUBMITTED->value, 'submitted_at' => now(), 'coordinator_id' => null])->save();

            return $internship->load($this->relations);
        });
    }

    public function resubmit(User $actor, int $id): Internship
    {
        return DB::transaction(function () use ($actor, $id): Internship {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($user->is_active && $user->hasRole(UserRole::STUDENT), 403);
            $internship = Internship::query()->where('student_id', $this->studentId($user))->lockForUpdate()->findOrFail($id);
            abort_unless($internship->status === InternshipStatus::REVISION_REQUIRED->value, 409, 'Only an application requiring revision can be resubmitted.');
            Validator::make(['coordinator_id' => $internship->coordinator_id], ['coordinator_id' => ['required', 'integer']])->validate();
            abort_unless(AcademicCoordinatorProfile::query()->sharedLock()->find($internship->coordinator_id) !== null, 422, 'An assigned academic coordinator is required.');
            $this->eligibility->validateCompleted($internship);
            $internship->forceFill(['status' => InternshipStatus::SUBMITTED->value, 'submitted_at' => now(), 'decision_comment' => null, 'approved_at' => null])->save();

            return $internship->load($this->relations);
        });
    }

    public function companies(User $user): array
    {
        $this->studentId($user);

        return Company::query()->where('is_active', true)->where('verification_status', VerificationStatus::APPROVED)
            ->orderBy('name')->orderBy('id')->get(['id', 'name', 'industry'])->toArray();
    }

    public function supervisors(User $user, int $companyId): array
    {
        $this->studentId($user);
        Company::query()->where('is_active', true)->where('verification_status', VerificationStatus::APPROVED)->findOrFail($companyId);

        return CompanySupervisorProfile::query()->where('company_id', $companyId)->where('verification_status', VerificationStatus::APPROVED)
            ->whereHas('user', fn (Builder $query) => $query->where('is_active', true)->whereHas('role', fn (Builder $role) => $role->where('name', UserRole::COMPANY_SUPERVISOR->value)))
            ->with('user:id,first_name,last_name')->orderBy('user_id')->get(['user_id', 'job_title'])
            ->map(fn ($profile): array => ['user_id' => $profile->user_id, 'first_name' => $profile->user->first_name, 'last_name' => $profile->user->last_name, 'job_title' => $profile->job_title])->all();
    }
}
