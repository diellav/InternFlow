<?php

namespace App\Modules\Activity\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->resource->only(['id', 'title', 'description', 'hours']),
            'activity_date' => $this->activity_date->format('Y-m-d'), 'created_at' => $this->created_at->toIso8601String(),
            'can_edit' => $this->internship->status === 'ACTIVE' && $this->internship->student_id === $request->user()->id,
            'internship' => new ActivityInternshipResource($this->whenLoaded('internship'))];
    }
}
