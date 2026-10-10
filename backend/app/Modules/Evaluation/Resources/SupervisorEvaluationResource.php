<?php

namespace App\Modules\Evaluation\Resources;

use App\Models\FinalEvaluation;
use App\Modules\Evaluation\Services\SupervisorEvaluationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupervisorEvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $evaluation = $this->finalEvaluation;
        $reason = app(SupervisorEvaluationService::class)->readinessReason($this->resource);

        return [
            'internship' => [...$this->resource->only(['id', 'position_title', 'status']), 'end_date' => $this->end_date?->format('Y-m-d')],
            'server_date' => today(config('app.timezone'))->format('Y-m-d'),
            'can_edit' => $this->status === 'ACTIVE' && $evaluation?->submitted_at === null,
            'can_submit' => $reason === null && $evaluation !== null,
            'submission_blocker' => $reason ?? ($evaluation === null ? 'NO_DRAFT' : null),
            'evaluation' => $evaluation === null ? null : [
                'id' => $evaluation->id, 'status' => $evaluation->submitted_at === null ? 'DRAFT' : 'SUBMITTED',
                ...collect(FinalEvaluation::RATINGS)->mapWithKeys(fn ($field) => [$field => $evaluation->$field === null ? null
                    : ($field === 'overall_score' ? (float) $evaluation->$field : (int) $evaluation->$field)])->all(),
                'comments' => $evaluation->comments, 'submitted_at' => $evaluation->submitted_at?->toIso8601String(),
                'draft_token' => $evaluation->submitted_at === null ? $evaluation->draftToken() : null,
            ],
        ];
    }
}
