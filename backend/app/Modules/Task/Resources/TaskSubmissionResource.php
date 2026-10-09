<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->only(['id', 'version_no', 'submission_text', 'resource_url']),
            'submitted_at' => $this->submitted_at->toIso8601String(),
            'feedback' => new TaskFeedbackResource($this->whenLoaded('feedback')),
            'files' => TaskSubmissionFileResource::collection($this->whenLoaded('files')),
        ];
    }
}
