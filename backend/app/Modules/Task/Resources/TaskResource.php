<?php

namespace App\Modules\Task\Resources;

use App\Modules\Internship\Services\SupervisorInternshipService;
use App\Shared\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->resource->only(['id', 'title', 'description', 'priority', 'status']),
            'has_submissions' => (bool) $this->submissions_exists,
            'can_resubmit' => $request->user()->hasRole(UserRole::STUDENT) && $this->internship->student_id === $request->user()->id
                && $this->internship->status === 'ACTIVE' && $this->status === 'REVISION_REQUIRED' && (bool) $this->submissions_exists,
            'can_review' => $request->user()->hasRole(UserRole::COMPANY_SUPERVISOR) && $this->internship->company_supervisor_id === $request->user()->id
                && app(SupervisorInternshipService::class)->canManageTasks($this->internship) && $this->status === 'SUBMITTED' && (bool) $this->submissions_exists,
            'can_start' => $request->user()->hasRole(UserRole::STUDENT) && $this->internship->student_id === $request->user()->id && $this->internship->status === 'ACTIVE' && $this->status === 'ASSIGNED' && ! $this->submissions_exists,
            'can_submit' => $request->user()->hasRole(UserRole::STUDENT) && $this->internship->student_id === $request->user()->id && $this->internship->status === 'ACTIVE' && $this->status === 'IN_PROGRESS' && ! $this->submissions_exists,
            'due_date' => $this->due_date?->format('Y-m-d'), 'created_at' => $this->created_at?->toIso8601String(), 'updated_at' => $this->updated_at?->toIso8601String(),
            'can_edit' => $request->user()->hasRole(UserRole::COMPANY_SUPERVISOR) && $this->internship->company_supervisor_id === $request->user()->id
                && app(SupervisorInternshipService::class)->canManageTasks($this->internship) && $this->status === 'ASSIGNED' && ! $this->submissions_exists,
            'internship' => ['id' => $this->internship->id, 'position_title' => $this->internship->position_title, 'status' => $this->internship->status,
                'company' => $this->internship->company?->only(['id', 'name']),
                'student' => $this->internship->student?->user?->only(['first_name', 'last_name'])],
        ];
    }
}
