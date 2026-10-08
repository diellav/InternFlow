<?php

namespace App\Modules\Company\Resources;

use Illuminate\Http\Request;

class SupervisorDetailResource extends SupervisorResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        if ($this->resource->relationLoaded('companySupervisorProfile') && $this->companySupervisorProfile !== null) {
            $profile = $this->companySupervisorProfile;
            $data['profile'] = [
                ...$data['profile'],
                'reviewed_by' => $profile->reviewed_by,
                'reviewed_at' => $profile->reviewed_at?->toIso8601String(),
                'review_comment' => $profile->review_comment,
            ];
        }

        return $data;
    }
}
