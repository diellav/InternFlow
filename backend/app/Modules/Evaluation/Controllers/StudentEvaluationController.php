<?php

namespace App\Modules\Evaluation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Evaluation\Resources\StudentEvaluationResource;
use App\Modules\Evaluation\Services\StudentEvaluationService;
use Illuminate\Http\Request;

class StudentEvaluationController extends Controller
{
    public function show(Request $request, int $internship): StudentEvaluationResource
    {
        return new StudentEvaluationResource(app(StudentEvaluationService::class)->view($request->user(), $internship));
    }
}
