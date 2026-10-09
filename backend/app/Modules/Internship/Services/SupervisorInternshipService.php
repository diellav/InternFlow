<?php

namespace App\Modules\Internship\Services;

use App\Models\Company;
use App\Models\CompanySupervisorProfile;
use App\Models\Internship;
use App\Models\User;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupervisorInternshipService
{
    public function __construct(private readonly InternshipEligibilityService $eligibility) {}

    private array $relations = ['student.user:id,first_name,last_name', 'company:id,name,industry,is_active,verification_status', 'companySupervisor.user:id,first_name,last_name', 'coordinator.user:id,first_name,last_name'];

    public function details(User $user, int $id): Internship
    {
        return Internship::query()->where('company_supervisor_id', $user->id)->with($this->relations)->findOrFail($id);
    }

    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = Internship::query()->where('company_supervisor_id', $user->id)->with($this->relations);
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';
            $query->where(fn (Builder $scope) => $scope->where('position_title', 'ilike', $search)
                ->orWhereHas('student.user', fn (Builder $student) => $student->whereRaw("concat_ws(' ', first_name, last_name) ILIKE ?", [$search])));
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 15, ['*'], 'page', $filters['page'] ?? 1)->withQueryString();
    }

    public function lockedOwned(User $actor, int $id): Internship
    {
        $user = User::query()->lockForUpdate()->findOrFail($actor->id);
        abort_unless($user->is_active && $user->hasRole(UserRole::COMPANY_SUPERVISOR), 403);
        $profile = CompanySupervisorProfile::query()->sharedLock()->find($user->id);
        abort_unless($profile !== null && $profile->verification_status === VerificationStatus::APPROVED, 403);
        $company = Company::query()->sharedLock()->find($profile->company_id);
        abort_unless($company !== null && $company->is_active && $company->verification_status === VerificationStatus::APPROVED, 403);
        $internship = Internship::query()->where('company_supervisor_id', $user->id)->lockForUpdate()->findOrFail($id);
        $this->eligibility->validateSelection($internship->company_id, $user->id);

        return $internship->load($this->relations);
    }

    public function canManageTasks(Internship $internship): bool
    {
        return $internship->status === 'ACTIVE' && $internship->company !== null && $internship->company->is_active
            && $internship->company->verification_status === VerificationStatus::APPROVED && $internship->companySupervisor !== null
            && $internship->companySupervisor->verification_status === VerificationStatus::APPROVED && $internship->companySupervisor->company_id === $internship->company_id;
    }

    public function activationReason(Internship $internship): ?string
    {
        if ($internship->status !== 'APPROVED') {
            return 'Only an approved internship can be activated.';
        }
        if ($internship->student === null || $internship->student->user === null) {
            return 'A valid assigned student is required.';
        }
        if ($internship->approved_at === null || $internship->start_date === null || $internship->end_date === null || $internship->end_date->lt($internship->start_date)) {
            return 'Approval timestamp and a valid internship date range are required.';
        }
        if ($internship->company === null || ! $internship->company->is_active || $internship->company->verification_status !== VerificationStatus::APPROVED
            || $internship->companySupervisor === null || $internship->companySupervisor->verification_status !== VerificationStatus::APPROVED || $internship->companySupervisor->company_id !== $internship->company_id) {
            return 'An active, approved company and associated approved supervisor are required.';
        }
        $date = today(config('app.timezone'))->format('Y-m-d');
        if ($date < $internship->start_date->format('Y-m-d') || $date > $internship->end_date->format('Y-m-d')) {
            return 'Activation is allowed only between the internship start and end dates, inclusive.';
        }

        return null;
    }

    public function activate(User $actor, int $id): Internship
    {
        return DB::transaction(function () use ($actor, $id): Internship {
            $internship = $this->lockedOwned($actor, $id);
            abort_unless($internship->status === 'APPROVED', 409, 'This internship is no longer awaiting activation.');
            $this->eligibility->validateCompleted($internship);
            $reason = $this->activationReason($internship);
            if ($reason !== null) {
                throw ValidationException::withMessages(['internship' => [$reason]]);
            }
            $internship->forceFill(['status' => 'ACTIVE'])->save();

            return $internship;
        });
    }
}
