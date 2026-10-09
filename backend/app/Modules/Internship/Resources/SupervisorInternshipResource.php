<?php

namespace App\Modules\Internship\Resources;

use App\Modules\Internship\Services\SupervisorInternshipService;
use Illuminate\Http\Request;

class SupervisorInternshipResource extends CoordinatorInternshipResource
{
    public function toArray(Request $request): array
    {
        $reason = app(SupervisorInternshipService::class)->activationReason($this->resource);

        return [...parent::toArray($request), 'can_activate' => $reason === null, 'activation_reason' => $reason,
            'server_date' => today(config('app.timezone'))->format('Y-m-d'), 'can_create_tasks' => app(SupervisorInternshipService::class)->canManageTasks($this->resource)];
    }
}
