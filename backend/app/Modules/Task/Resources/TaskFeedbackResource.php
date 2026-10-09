<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskFeedbackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->resource->only(['decision', 'comment']), 'created_at' => $this->created_at->toIso8601String()];
    }
}
