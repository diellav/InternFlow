<?php

namespace App\Modules\Company\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'industry' => $this->industry,
            'address' => $this->address,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'is_active' => $this->is_active,
            'verification_status' => $this->verification_status->value,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
