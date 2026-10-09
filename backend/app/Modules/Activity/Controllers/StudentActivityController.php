<?php

namespace App\Modules\Activity\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Requests\ListActivitiesRequest;
use App\Modules\Activity\Requests\SaveActivityRequest;
use App\Modules\Activity\Resources\ActivityInternshipResource;
use App\Modules\Activity\Resources\ActivityResource;
use App\Modules\Activity\Services\StudentActivityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StudentActivityController extends Controller
{
    public function __construct(private readonly StudentActivityService $activities) {}

    public function index(ListActivitiesRequest $request, int $internship): AnonymousResourceCollection
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

    public function store(SaveActivityRequest $request, int $internship): ActivityResource
    {
        return new ActivityResource($this->activities->save($request->user(), $request->validated(), internshipId: $internship));
    }

    public function update(SaveActivityRequest $request, int $activity): ActivityResource
    {
        return new ActivityResource($this->activities->save($request->user(), $request->validated(), activityId: $activity));
    }
}
