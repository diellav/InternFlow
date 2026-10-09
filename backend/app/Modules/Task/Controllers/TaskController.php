<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Task\Requests\ListTasksRequest;
use App\Modules\Task\Requests\SaveTaskRequest;
use App\Modules\Task\Resources\TaskResource;
use App\Modules\Task\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    public function supervisorIndex(ListTasksRequest $request, int $internship): AnonymousResourceCollection
    {
        return TaskResource::collection($this->tasks->paginate($request->user(), $internship, $request->validated()));
    }

    public function studentIndex(ListTasksRequest $request, int $internship): AnonymousResourceCollection
    {
        return TaskResource::collection($this->tasks->paginate($request->user(), $internship, $request->validated(), true));
    }

    public function supervisorShow(Request $request, int $task): TaskResource
    {
        return new TaskResource($this->tasks->details($request->user(), $task));
    }

    public function studentShow(Request $request, int $task): TaskResource
    {
        return new TaskResource($this->tasks->details($request->user(), $task, true));
    }

    public function store(SaveTaskRequest $request, int $internship): TaskResource
    {
        return new TaskResource($this->tasks->save($request->user(), $request->validated(), $internship));
    }

    public function update(SaveTaskRequest $request, int $task): TaskResource
    {
        return new TaskResource($this->tasks->save($request->user(), $request->validated(), id: $task));
    }
}
