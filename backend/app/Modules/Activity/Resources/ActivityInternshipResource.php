<?php

namespace App\Modules\Activity\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityInternshipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->resource->only(['id', 'position_title', 'status']),
            'start_date' => $this->start_date?->format('Y-m-d'), 'end_date' => $this->end_date?->format('Y-m-d'),
            'server_date' => today(config('app.timezone'))->format('Y-m-d')];
    }
}
