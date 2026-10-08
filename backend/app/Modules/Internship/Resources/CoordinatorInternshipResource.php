<?php

namespace App\Modules\Internship\Resources;

use Illuminate\Http\Request;

class CoordinatorInternshipResource extends StudentInternshipResource
{
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request),
            'coordinator_id' => $this->coordinator_id,
            'student' => $this->whenLoaded('student', fn () => [
                ...$this->student->only(['student_number', 'study_program', 'study_year']),
                'first_name' => $this->student->user?->first_name,
                'last_name' => $this->student->user?->last_name,
            ]),
        ];
    }
}
