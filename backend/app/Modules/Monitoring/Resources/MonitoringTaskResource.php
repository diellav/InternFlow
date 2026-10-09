<?php

namespace App\Modules\Monitoring\Resources;

use App\Modules\Task\Resources\TaskResource;
use Illuminate\Http\Request;

class MonitoringTaskResource extends TaskResource
{
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'progress_percent' => $this->progress_percent,
            'assigned_by' => $this->assigner?->only(['first_name', 'last_name'])];
    }
}
