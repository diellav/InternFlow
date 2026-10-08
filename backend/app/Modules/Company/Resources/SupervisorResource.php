<?php

namespace App\Modules\Company\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupervisorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'profile' => $this->whenLoaded('companySupervisorProfile', function (): ?array {
                $profile = $this->companySupervisorProfile;
                if ($profile === null) {
                    return null;
                }

                return [
                    'job_title' => $profile->job_title,
                    'verification_status' => $profile->verification_status->value,
                    'company' => [
                        'id' => $profile->company->id,
                        'name' => $profile->company->name,
                        'is_active' => $profile->company->is_active,
                        'verification_status' => $profile->company->verification_status->value,
                    ],
                ];
            }),
        ];
    }
}
