<?php

namespace App\Modules\Auth\Services;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Modules\Auth\DTOs\StudentRegistrationDTO;
use App\Modules\Auth\DTOs\SupervisorRegistrationDTO;
use App\Modules\Auth\Enums\CompanyRegistrationMode;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class RegistrationService
{
    public function registerStudent(StudentRegistrationDTO $dto): User
    {
        return $this->runRegistrationTransaction(function () use ($dto): User {
            $user = $this->createUser(
                role: $this->resolveRole(UserRole::STUDENT),
                firstName: $dto->firstName,
                lastName: $dto->lastName,
                email: $dto->email,
                password: $dto->password,
                phone: $dto->phone,
            );

            $user->studentProfile()->create([
                'student_number' => $dto->studentNumber,
                'study_program' => $dto->studyProgram,
                'study_year' => $dto->studyYear,
            ]);

            return $user->load(['role', 'studentProfile']);
        });
    }

    public function registerSupervisor(SupervisorRegistrationDTO $dto): User
    {
        return $this->runRegistrationTransaction(function () use ($dto): User {
            $company = match ($dto->companyMode) {
                CompanyRegistrationMode::EXISTING => $this->resolveEligibleCompany($dto->companyId),
                CompanyRegistrationMode::NEW => $this->createPendingCompany($dto),
            };

            $user = $this->createUser(
                role: $this->resolveRole(UserRole::COMPANY_SUPERVISOR),
                firstName: $dto->firstName,
                lastName: $dto->lastName,
                email: $dto->email,
                password: $dto->password,
                phone: $dto->phone,
            );

            $user->companySupervisorProfile()->create([
                'company_id' => $company->id,
                'job_title' => $dto->jobTitle,
                'verification_status' => VerificationStatus::PENDING,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_comment' => null,
            ]);

            return $user->load(['role', 'companySupervisorProfile.company']);
        });
    }

    private function resolveRole(UserRole $role): Role
    {
        return Role::query()->where('name', $role->value)->first()
            ?? throw new LogicException("The {$role->value} role is not configured. Run the role seeder.");
    }

    private function createUser(
        Role $role,
        string $firstName,
        string $lastName,
        string $email,
        string $password,
        ?string $phone,
    ): User {
        return User::query()->create([
            'role_id' => $role->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => $password,
            'phone' => $phone,
            'is_active' => true,
        ]);
    }

    private function resolveEligibleCompany(?int $companyId): Company
    {
        $company = Company::query()->lockForUpdate()->find($companyId);

        if ($company === null) {
            throw ValidationException::withMessages([
                'company_id' => ['The selected company does not exist.'],
            ]);
        }

        if ($company->verification_status === VerificationStatus::REJECTED) {
            throw ValidationException::withMessages([
                'company_id' => ['The selected company is not eligible for supervisor registration.'],
            ]);
        }

        return $company;
    }

    private function createPendingCompany(SupervisorRegistrationDTO $dto): Company
    {
        $this->ensureCompanyIsNotAnObviousDuplicate($dto);

        return Company::query()->create([
            'name' => $dto->companyName,
            'industry' => $dto->companyIndustry,
            'address' => $dto->companyAddress,
            'email' => $dto->companyEmail,
            'phone' => $dto->companyPhone,
            'website' => $dto->companyWebsite,
            'is_active' => true,
            'verification_status' => VerificationStatus::PENDING,
            'verified_by' => null,
            'verified_at' => null,
            'verification_note' => null,
        ]);
    }

    private function ensureCompanyIsNotAnObviousDuplicate(SupervisorRegistrationDTO $dto): void
    {
        if ($dto->companyWebsite !== null) {
            $domain = $this->normalizedWebsiteDomain($dto->companyWebsite);
            $websiteMatch = Company::query()
                ->whereNotNull('website')
                ->get(['website'])
                ->contains(fn (Company $company): bool => $this->normalizedWebsiteDomain($company->website) === $domain);

            if ($websiteMatch) {
                throw ValidationException::withMessages([
                    'company_website' => ['A company with this website already exists. Select the existing company instead.'],
                ]);
            }
        }

        $normalizedName = Str::lower(trim((string) $dto->companyName));
        $nameMatch = Company::query()
            ->whereRaw('LOWER(BTRIM(name)) = ?', [$normalizedName])
            ->exists();

        if ($nameMatch) {
            throw ValidationException::withMessages([
                'company_name' => ['A company with this name already exists. Select the existing company instead.'],
            ]);
        }
    }

    private function normalizedWebsiteDomain(string $website): string
    {
        $host = Str::lower((string) parse_url($website, PHP_URL_HOST));

        return Str::startsWith($host, 'www.') ? Str::after($host, 'www.') : $host;
    }

    /**
     * @param  Closure(): User  $callback
     */
    private function runRegistrationTransaction(Closure $callback): User
    {
        try {
            return DB::transaction($callback);
        } catch (QueryException $exception) {
            $this->rethrowKnownUniqueViolation($exception);

            throw $exception;
        }
    }

    private function rethrowKnownUniqueViolation(QueryException $exception): void
    {
        if ((string) $exception->getCode() !== '23505') {
            return;
        }

        $databaseMessage = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        if (str_contains($databaseMessage, 'users_email_unique')) {
            throw ValidationException::withMessages([
                'email' => ['The email has already been taken.'],
            ]);
        }

        if (str_contains($databaseMessage, 'student_profiles_student_number_unique')) {
            throw ValidationException::withMessages([
                'student_number' => ['The student number has already been taken.'],
            ]);
        }
    }
}
