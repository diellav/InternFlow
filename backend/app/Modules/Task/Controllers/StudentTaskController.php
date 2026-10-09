<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Task\Requests\StudentTaskActionRequest;
use App\Modules\Task\Resources\TaskResource;
use App\Modules\Task\Resources\TaskSubmissionResource;
use App\Modules\Task\Services\StudentTaskService;
use App\Modules\Task\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StudentTaskController extends Controller
{
    public function start(StudentTaskActionRequest $request, int $task): TaskResource
    {
        return new TaskResource(app(StudentTaskService::class)->transition($request->user(), $task));
    }

    public function submit(StudentTaskActionRequest $request, int $task): TaskResource
    {
        return new TaskResource(app(StudentTaskService::class)->transition($request->user(), $task, $request->validated()));
    }

    public function resubmit(StudentTaskActionRequest $request, int $task): TaskResource
    {
        return new TaskResource(app(StudentTaskService::class)->transition($request->user(), $task, $request->validated(), true));
    }

    public function submissions(Request $request, int $task): AnonymousResourceCollection
    {
        $record = app(TaskService::class)->details($request->user(), $task, $request->routeIs('student.*'));

        return TaskSubmissionResource::collection($record->submissions()->with(['feedback', 'files'])->orderByDesc('version_no')->paginate(15));
    }
}
