<?php

namespace App\Modules\Evaluation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Evaluation\Requests\CompleteInternshipRequest;
use App\Modules\Evaluation\Resources\CoordinatorEvaluationResource;
use App\Modules\Evaluation\Services\CoordinatorCompletionService;
use App\Modules\Monitoring\Resources\MonitoringInternshipResource;
use Illuminate\Http\Request;

class CoordinatorEvaluationController extends Controller
{
    public function __construct(private readonly CoordinatorCompletionService $completion) {}

    public function show(Request $request, int $internship): CoordinatorEvaluationResource
    {
        return new CoordinatorEvaluationResource($this->completion->view($request->user(), $internship));
    }

    public function complete(CompleteInternshipRequest $request, int $internship): MonitoringInternshipResource
    {
        return new MonitoringInternshipResource($this->completion->complete($request->user(), $internship, (int) $request->validated('expected_evaluation_id')));
    }
}
