<?php

namespace App\Modules\Evaluation\Resources;

use App\Models\FinalEvaluation;
use App\Modules\Internship\Resources\StudentInternshipResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentEvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $evaluation = $this->finalEvaluation;

        return ['internship' => new StudentInternshipResource($this->resource),
            'evaluation' => [...$evaluation->only(['id', ...FinalEvaluation::RATINGS, 'comments']),
                'submitted_at' => $evaluation->submitted_at->toIso8601String(),
                'evaluator' => $evaluation->evaluator?->user?->only(['first_name', 'last_name'])]];
    }
}
