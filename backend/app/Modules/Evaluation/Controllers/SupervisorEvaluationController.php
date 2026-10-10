<?php

namespace App\Modules\Evaluation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Evaluation\Requests\SaveEvaluationRequest;
use App\Modules\Evaluation\Requests\SubmitEvaluationRequest;
use App\Modules\Evaluation\Resources\SupervisorEvaluationResource;
use App\Modules\Evaluation\Services\SupervisorEvaluationService;
use Illuminate\Http\Request;

class SupervisorEvaluationController extends Controller
{
    public function __construct(private readonly SupervisorEvaluationService $evaluations) {}

    public function show(Request $request, int $internship): SupervisorEvaluationResource
    {
        return new SupervisorEvaluationResource($this->evaluations->owned($request->user(), $internship));
    }

    public function save(SaveEvaluationRequest $request, int $internship): SupervisorEvaluationResource
    {
        return new SupervisorEvaluationResource($this->evaluations->save($request->user(), $internship, $request->validated()));
    }

    public function submit(SubmitEvaluationRequest $request, int $internship): SupervisorEvaluationResource
    {
        return new SupervisorEvaluationResource($this->evaluations->submit($request->user(), $internship, $request->validated('draft_token')));
    }
}
