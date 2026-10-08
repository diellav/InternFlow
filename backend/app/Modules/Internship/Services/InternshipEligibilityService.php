<?php

namespace App\Modules\Internship\Services;

use App\Models\Company;
use App\Models\CompanySupervisorProfile;
use App\Models\Internship;
use App\Models\User;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InternshipEligibilityService
{
    public function validateCompleted(Internship $internship): void
    {
        Validator::make([
            'company_id' => $internship->company_id,
            'company_supervisor_id' => $internship->company_supervisor_id,
            'position_title' => $internship->position_title,
            'start_date' => $internship->start_date?->format('Y-m-d'),
            'end_date' => $internship->end_date?->format('Y-m-d'),
        ], [
            'company_id' => ['required', 'integer'],
            'company_supervisor_id' => ['required', 'integer'],
            'position_title' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ])->validate();
        $this->validateSelection($internship->company_id, $internship->company_supervisor_id);
    }

    public function validateSelection(int $companyId, ?int $supervisorId): void
    {
        if ($supervisorId !== null) {
            $supervisor = User::query()->sharedLock()->find($supervisorId);
            $profile = CompanySupervisorProfile::query()->sharedLock()->find($supervisorId);
            if ($supervisor === null || ! $supervisor->is_active || ! $supervisor->hasRole(UserRole::COMPANY_SUPERVISOR)
                || $profile === null || $profile->company_id !== $companyId || $profile->verification_status !== VerificationStatus::APPROVED) {
                throw ValidationException::withMessages(['company_supervisor_id' => ['Select an active, approved supervisor associated with the selected company.']]);
            }
        }
        $company = Company::query()->sharedLock()->find($companyId);
        if ($company === null || ! $company->is_active || $company->verification_status !== VerificationStatus::APPROVED) {
            throw ValidationException::withMessages(['company_id' => ['Select an active, approved company.']]);
        }
    }
}
