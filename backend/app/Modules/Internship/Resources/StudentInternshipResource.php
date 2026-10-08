<?php

namespace App\Modules\Internship\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentInternshipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->only(['id', 'company_id', 'company_supervisor_id', 'position_title', 'description', 'status', 'decision_comment']),
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'company' => $this->whenLoaded('company', fn () => $this->company?->only(['id', 'name', 'industry', 'is_active', 'verification_status'])),
            'supervisor' => $this->whenLoaded('companySupervisor', function (): ?array {
                $profile = $this->companySupervisor;

                return $profile === null ? null : [
                    'user_id' => $profile->user_id, 'job_title' => $profile->job_title,
                    'first_name' => $profile->user?->first_name, 'last_name' => $profile->user?->last_name,
                ];
            }),
            'coordinator' => $this->whenLoaded('coordinator', function (): ?array {
                $profile = $this->coordinator;

                return $profile === null ? null : [
                    'first_name' => $profile->user?->first_name, 'last_name' => $profile->user?->last_name,
                    'academic_unit' => $profile->academic_unit,
                ];
            }),
        ];
    }
}
