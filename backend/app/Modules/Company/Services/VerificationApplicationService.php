<?php

namespace App\Modules\Company\Services;

use App\Models\Company;
use App\Models\CompanySupervisorProfile;
use App\Models\User;
use App\Modules\User\Services\ProfileService;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VerificationApplicationService
{
    public function __construct(private readonly ProfileService $profiles) {}

    public function show(User $actor): array
    {
        $actor->load('companySupervisorProfile.company');
        $profile = $actor->companySupervisorProfile;
        $company = $profile?->company;
        $companyAllowed = $company !== null && $company->verification_status === VerificationStatus::REJECTED
            && $company->supervisors()->count() === 1;
        $supervisorAllowed = $profile?->verification_status === VerificationStatus::REJECTED;

        return [
            'supervisor' => [
                ...$actor->only(['first_name', 'last_name', 'email', 'phone', 'is_active']),
                'job_title' => $profile?->job_title, 'verification_status' => $profile?->verification_status?->value,
                'rejection_reason' => $supervisorAllowed ? $profile->review_comment : null,
            ],
            'company' => $company === null ? null : [
                ...$company->only(['name', 'industry', 'address', 'email', 'phone', 'website', 'is_active']),
                'verification_status' => $company->verification_status->value,
                'rejection_reason' => $company->verification_status === VerificationStatus::REJECTED ? $company->verification_note : null,
            ],
            'can_resubmit_supervisor' => $supervisorAllowed,
            'can_resubmit_company' => $companyAllowed,
            'editable_supervisor_fields' => $supervisorAllowed ? ['first_name', 'last_name', 'phone', 'job_title'] : [],
            'editable_company_fields' => $companyAllowed ? ['name', 'industry', 'address', 'email', 'phone', 'website'] : [],
            'company_editing_limitation' => $company?->verification_status === VerificationStatus::REJECTED && ! $companyAllowed
                ? 'Shared companies cannot be corrected through self-service. Contact the administrator.' : null,
        ];
    }

    public function update(User $actor, array $data): array
    {
        return $this->mutate($actor, function (User $user, CompanySupervisorProfile $profile, Company $company) use ($data): void {
            if (isset($data['supervisor'])) {
                abort_unless($profile->verification_status === VerificationStatus::REJECTED, 409, 'Supervisor application state changed.');
            }
            if (isset($data['company'])) {
                $this->authorizeCompany($company);
                $this->validateCompanyDuplicates(array_replace($company->only(['name', 'website']), $data['company']), $company);
            }
            if (isset($data['supervisor'])) {
                $this->profiles->update($user, $data['supervisor']);
            }
            if (isset($data['company'])) {
                $company->fill($data['company'])->save();
            }
        });
    }

    public function resubmit(User $actor, array $targets): array
    {
        return $this->mutate($actor, function (User $user, CompanySupervisorProfile $profile, Company $company) use ($targets): void {
            if (in_array('supervisor', $targets, true)) {
                abort_unless($profile->verification_status === VerificationStatus::REJECTED, 409, 'Supervisor application state changed.');
                Validator::make(['supervisor' => [...$user->only(['first_name', 'last_name', 'phone']), 'job_title' => $profile->job_title]], [
                    'supervisor.first_name' => ['required', 'string', 'max:255'], 'supervisor.last_name' => ['required', 'string', 'max:255'],
                    'supervisor.phone' => ['nullable', 'string', 'max:255'], 'supervisor.job_title' => ['nullable', 'string', 'max:255'],
                ])->validate();
            }
            if (in_array('company', $targets, true)) {
                $this->authorizeCompany($company);
                $values = $company->only(['name', 'industry', 'address', 'email', 'phone', 'website']);
                Validator::make(['company' => $values], [
                    'company.name' => ['required', 'string', 'max:255'], 'company.industry' => ['nullable', 'string', 'max:255'],
                    'company.address' => ['nullable', 'string'], 'company.email' => ['nullable', 'email', 'max:255'],
                    'company.phone' => ['nullable', 'string', 'max:255'], 'company.website' => ['nullable', 'url:http,https', 'max:255'],
                ])->validate();
                $this->validateCompanyDuplicates($values, $company);
            }
            if (in_array('supervisor', $targets, true)) {
                $profile->forceFill(['verification_status' => VerificationStatus::PENDING, 'reviewed_by' => null, 'reviewed_at' => null, 'review_comment' => null])->save();
            }
            if (in_array('company', $targets, true)) {
                $company->forceFill(['verification_status' => VerificationStatus::PENDING, 'verified_by' => null, 'verified_at' => null, 'verification_note' => null])->save();
            }
        });
    }

    private function mutate(User $actor, callable $operation): array
    {
        return DB::transaction(function () use ($actor, $operation): array {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($user->is_active && $user->hasRole(UserRole::COMPANY_SUPERVISOR), 403);
            $profile = CompanySupervisorProfile::query()->where('user_id', $user->id)->lockForUpdate()->first();
            abort_if($profile === null, 409, 'Supervisor profile is missing.');
            $company = Company::query()->lockForUpdate()->find($profile->company_id);
            abort_if($company === null, 409, 'Associated company is missing.');
            $operation($user, $profile, $company);

            return $this->show($user->fresh());
        });
    }

    private function authorizeCompany(Company $company): void
    {
        abort_unless($company->verification_status === VerificationStatus::REJECTED, 409, 'Company application state changed.');
        abort_unless($company->supervisors()->count() === 1, 403, 'Shared company information cannot be edited through self-service.');
    }

    private function validateCompanyDuplicates(array $values, Company $company): void
    {
        $others = Company::query()->where('id', '!=', $company->id)->get(['name', 'website']);
        if ($others->contains(fn (Company $other): bool => Str::lower(trim($other->name)) === Str::lower(trim($values['name'])))) {
            throw ValidationException::withMessages(['company.name' => ['A company with this name already exists.']]);
        }
        $domain = fn (string $website): string => preg_replace('/^www\./', '', Str::lower((string) parse_url($website, PHP_URL_HOST)));
        if ($values['website'] !== null && $others->contains(fn (Company $other): bool => $other->website !== null && $domain($other->website) === $domain($values['website']))) {
            throw ValidationException::withMessages(['company.website' => ['A company with this website already exists.']]);
        }
    }
}
