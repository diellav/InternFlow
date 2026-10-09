<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskSubmissionFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->resource->only(['id', 'original_name', 'mime_type', 'size_bytes']),
            'download_path' => '/api/task-submission-files/'.$this->id.'/download'];
    }
}
