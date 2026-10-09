<?php

namespace App\Modules\Monitoring\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Resources\ActivityInternshipResource;
use App\Modules\Activity\Resources\ActivityResource;
use App\Modules\Activity\Services\ActivityQueryService;
use App\Modules\Monitoring\Requests\MonitoringActivitiesRequest;
use App\Modules\Monitoring\Requests\MonitoringListRequest;
use App\Modules\Monitoring\Resources\MonitoringInternshipResource;
use App\Modules\Monitoring\Resources\MonitoringTaskResource;
use App\Modules\Monitoring\Services\CoordinatorMonitoringService;
use App\Modules\Task\Resources\TaskSubmissionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CoordinatorMonitoringController extends Controller
{
    public function __construct(private readonly CoordinatorMonitoringService $monitoring) {}

    public function index(MonitoringListRequest $request): AnonymousResourceCollection
    {
        $query = $this->monitoring->internships($request->user())->with(['student.user', 'company', 'companySupervisor.user', 'coordinator.user'])
            ->withCount(['tasks', 'tasks as approved_tasks_count' => fn ($query) => $query->where('status', 'APPROVED')]);

        return MonitoringInternshipResource::collection($query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->validated('per_page', 15))->withQueryString());
    }

    public function show(Request $request, int $internship): MonitoringInternshipResource
    {
        return new MonitoringInternshipResource($this->monitoring->internship($request->user(), $internship));
    }

    public function activities(MonitoringActivitiesRequest $request, int $internship): AnonymousResourceCollection
    {
        $result = app(ActivityQueryService::class)->listing($this->monitoring->internship($request->user(), $internship), $request->validated());

        return ActivityResource::collection($result['activities'])->additional([
            'internship' => new ActivityInternshipResource($result['internship']),
            'total_recorded_hours' => $result['total_recorded_hours'], 'filtered_recorded_hours' => $result['filtered_recorded_hours'],
        ]);
    }

    public function activity(Request $request, int $activity): ActivityResource
    {
        return new ActivityResource($this->monitoring->activity($request->user(), $activity));
    }

    public function tasks(MonitoringListRequest $request, int $internship): AnonymousResourceCollection
    {
        $parent = $this->monitoring->internship($request->user(), $internship);

        return MonitoringTaskResource::collection($this->monitoring->tasks($request->user())->where('internship_id', $parent->id)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($request->validated('per_page', 15))->withQueryString());
    }

    public function task(Request $request, int $task): MonitoringTaskResource
    {
        return new MonitoringTaskResource($this->monitoring->task($request->user(), $task));
    }

    public function submissions(MonitoringListRequest $request, int $task): AnonymousResourceCollection
    {
        $record = $this->monitoring->task($request->user(), $task);

        return TaskSubmissionResource::collection($record->submissions()->with(['feedback', 'files'])->orderByDesc('version_no')
            ->paginate($request->validated('per_page', 15))->withQueryString());
    }
}
