<?php

namespace App\Modules\User\Resources;

use App\Shared\Enums\UserRole;
use Illuminate\Http\Request;

class UserDetailResource extends UserResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        if (! $this->resource->relationLoaded('role')) {
            return $data;
        }

        $relation = match (UserRole::tryFrom($this->role->name)) {
            UserRole::STUDENT => 'studentProfile',
            UserRole::COMPANY_SUPERVISOR => 'companySupervisorProfile',
            UserRole::ACADEMIC_COORDINATOR => 'academicCoordinatorProfile',
            default => null,
        };

        if ($relation === null || ! $this->resource->relationLoaded($relation)) {
            return $data;
        }

        $profile = $this->resource->getRelation($relation);
        $data['profile'] = match ($relation) {
            'studentProfile' => $profile?->only(['student_number', 'study_program', 'study_year']),
            'academicCoordinatorProfile' => $profile?->only(['academic_unit']),
            default => $profile === null ? null : [
                'job_title' => $profile->job_title,
                'verification_status' => $profile->verification_status->value,
                'company' => $profile->relationLoaded('company') ? $profile->company?->only([
                    'id', 'name', 'is_active', 'verification_status',
                ]) : null,
            ],
        };

        return $data;
    }
}
