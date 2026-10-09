<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Task\Requests\ReviewTaskRequest;
use App\Modules\Task\Resources\TaskResource;
use App\Modules\Task\Services\TaskReviewService;

class TaskReviewController extends Controller
{
    public function store(ReviewTaskRequest $request, int $task): TaskResource
    {
        return new TaskResource(app(TaskReviewService::class)->review($request->user(), $task, $request->validated()));
    }
}
