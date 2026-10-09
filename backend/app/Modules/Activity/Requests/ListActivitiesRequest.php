<?php

namespace App\Modules\Activity\Requests;

use App\Modules\Activity\Services\StudentActivityService;

class ListActivitiesRequest extends ActivityListRequest
{
    public function authorize(): bool
    {
        $parent = app(StudentActivityService::class)->parent($this->user(), (int) $this->route('internship'));
        abort_unless($parent->status === 'ACTIVE', 404);

        return true;
    }
}
