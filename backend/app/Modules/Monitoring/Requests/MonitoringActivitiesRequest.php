<?php

namespace App\Modules\Monitoring\Requests;

use App\Modules\Activity\Requests\ActivityListRequest;
use App\Modules\Monitoring\Services\CoordinatorMonitoringService;

class MonitoringActivitiesRequest extends ActivityListRequest
{
    public function authorize(): bool
    {
        app(CoordinatorMonitoringService::class)->internship($this->user(), (int) $this->route('internship'));

        return true;
    }
}
