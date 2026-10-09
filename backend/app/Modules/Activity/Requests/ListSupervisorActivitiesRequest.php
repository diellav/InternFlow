<?php

namespace App\Modules\Activity\Requests;

use App\Modules\Activity\Services\SupervisorActivityService;

class ListSupervisorActivitiesRequest extends ActivityListRequest
{
    public function authorize(): bool
    {
        app(SupervisorActivityService::class)->parent($this->user(), (int) $this->route('internship'));

        return true;
    }
}
