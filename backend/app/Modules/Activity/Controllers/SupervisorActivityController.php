<?php

namespace App\Modules\Activity\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Requests\ListSupervisorActivitiesRequest;
use App\Modules\Activity\Resources\ActivityInternshipResource;
use App\Modules\Activity\Resources\ActivityResource;
use App\Modules\Activity\Services\SupervisorActivityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupervisorActivityController extends Controller
{
    public function __construct(private readonly SupervisorActivityService $activities) {}

    public function index(ListSupervisorActivitiesRequest $request, int $internship): AnonymousResourceCollection
    {
        $result = $this->activities->listing($request->user(), $internship, $request->validated());

        return ActivityResource::collection($result['activities'])->additional([
            'internship' => new ActivityInternshipResource($result['internship']),
            'total_recorded_hours' => $result['total_recorded_hours'],
            'filtered_recorded_hours' => $result['filtered_recorded_hours'],
        ]);
    }

    public function show(Request $request, int $activity): ActivityResource
    {
        return new ActivityResource($this->activities->details($request->user(), $activity));
    }
}
