<?php

namespace App\Modules\Company\Resources;

use App\Models\CompanySupervisorProfile;
use Illuminate\Http\Request;

class CompanyDetailResource extends CompanyResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verification_note' => $this->verification_note,
            'supervisors' => $this->whenLoaded('supervisors', fn () => $this->supervisors
                ->map(fn (CompanySupervisorProfile $profile): array => [
                    'user_id' => $profile->user_id,
                    'first_name' => $profile->user->first_name,
                    'last_name' => $profile->user->last_name,
                    'email' => $profile->user->email,
                    'phone' => $profile->user->phone,
                    'is_active' => $profile->user->is_active,
                    'job_title' => $profile->job_title,
                    'verification_status' => $profile->verification_status->value,
                ])->all()),
        ];
    }
}
