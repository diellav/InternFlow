<?php

namespace App\Modules\Internship\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminInternshipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $person = static fn ($user) => $user ? ['id' => $user->id, 'first_name' => $user->first_name, 'last_name' => $user->last_name] : null;

        return [
            'id' => $this->id,
            'student' => $person($this->student?->user),
            'company' => $this->company ? ['id' => $this->company->id, 'name' => $this->company->name] : null,
            'coordinator' => $person($this->coordinator?->user),
            'supervisor' => $person($this->companySupervisor?->user),
            'position_title' => $this->position_title,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'description' => $this->whenHas('description'),
        ];
    }
}
