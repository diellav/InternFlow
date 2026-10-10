<?php

namespace App\Modules\Evaluation\Resources;

use App\Models\FinalEvaluation;
use App\Modules\Evaluation\Services\CoordinatorCompletionService;
use App\Modules\Monitoring\Resources\MonitoringInternshipResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoordinatorEvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $evaluation = $this->finalEvaluation;
        $reason = app(CoordinatorCompletionService::class)->readinessReason($this->resource);

        return [
            'internship' => new MonitoringInternshipResource($this->resource),
            'server_date' => today(config('app.timezone'))->format('Y-m-d'),
            'can_complete' => $reason === null,
            'completion_blocker' => $reason,
            'evaluation' => $evaluation === null || $evaluation->submitted_at === null ? null : [
                ...$evaluation->only(['id', ...FinalEvaluation::RATINGS, 'comments']),
                'submitted_at' => $evaluation->submitted_at->toIso8601String(),
                'evaluator' => $evaluation->evaluator?->user?->only(['first_name', 'last_name']),
            ],
        ];
    }
}
