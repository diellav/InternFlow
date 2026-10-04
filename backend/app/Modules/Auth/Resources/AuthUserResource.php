<?php

namespace App\Modules\Auth\Resources;

use App\Models\AcademicCoordinatorProfile;
use App\Models\CompanySupervisorProfile;
use App\Models\StudentProfile;
use App\Shared\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'role' => $this->whenLoaded('role', fn () => $this->role->name),
            'profile' => $this->when($this->hasLoadedProfile(), fn (): ?array => $this->profileData()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hasLoadedProfile(): bool
    {
        return match (UserRole::tryFrom((string) $this->role?->name)) {
            UserRole::STUDENT => $this->resource->relationLoaded('studentProfile'),
            UserRole::COMPANY_SUPERVISOR => $this->resource->relationLoaded('companySupervisorProfile'),
            UserRole::ACADEMIC_COORDINATOR => $this->resource->relationLoaded('academicCoordinatorProfile'),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function profileData(): ?array
    {
        $role = UserRole::tryFrom((string) $this->role?->name);

        if ($role === UserRole::STUDENT) {
            /** @var StudentProfile $profile */
            $profile = $this->studentProfile;

            if ($profile === null) {
                return null;
            }

            return [
                'student_number' => $profile->student_number,
                'study_program' => $profile->study_program,
                'study_year' => $profile->study_year,
            ];
        }

        if ($role === UserRole::ACADEMIC_COORDINATOR) {
            /** @var AcademicCoordinatorProfile|null $profile */
            $profile = $this->academicCoordinatorProfile;

            return $profile === null ? null : ['academic_unit' => $profile->academic_unit];
        }

        /** @var CompanySupervisorProfile|null $profile */
        $profile = $this->companySupervisorProfile;

        if ($profile === null) {
            return null;
        }

        $company = $profile->company;

        return [
            'job_title' => $profile->job_title,
            'verification_status' => $profile->verification_status->value,
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'industry' => $company->industry,
                'address' => $company->address,
                'email' => $company->email,
                'phone' => $company->phone,
                'website' => $company->website,
                'verification_status' => $company->verification_status->value,
            ],
        ];
    }
}
