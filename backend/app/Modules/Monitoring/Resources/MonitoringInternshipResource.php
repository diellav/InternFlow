<?php

namespace App\Modules\Monitoring\Resources;

use App\Modules\Internship\Resources\CoordinatorInternshipResource;
use Illuminate\Http\Request;

class MonitoringInternshipResource extends CoordinatorInternshipResource
{
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'tasks_count' => $this->tasks_count, 'approved_tasks_count' => $this->approved_tasks_count];
    }
}
