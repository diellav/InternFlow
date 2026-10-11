<?php

namespace App\Modules\Audit\Resources;

use App\Shared\Enums\UserRole;
use App\Shared\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    private function safeMetadata(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }
        $safe = [];
        foreach ($metadata as $key => $value) {
            $valid = match ($key) {
                'status' => in_array($value, ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'REVISION_REQUIRED', 'APPROVED', 'ACTIVE', 'COMPLETED', 'REJECTED', 'PENDING', 'ASSIGNED', 'IN_PROGRESS', 'CANCELLED'], true),
                'is_active', 'submitted' => is_bool($value),
                'coordinator_id' => $value === null || (is_int($value) && $value > 0),
                'version_no' => is_int($value) && $value > 0,
                'hours' => $value === null || ((is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d{1,2}(?:\.\d{1,2})?$/D', $value) === 1)) && is_numeric($value) && (float) $value >= 0 && (float) $value <= 24),
                default => false,
            };
            if ($valid) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    public function toArray(Request $request): array
    {
        $known = (AuditService::events()[$this->action] ?? null) === $this->entity_type;
        $demo = $this->action === 'internflow.demo.seed.v1' && $this->entity_type === 'demo_dataset';
        $actor = $this->user;

        return [
            'id' => $this->id, 'created_at' => $this->created_at?->toIso8601String(),
            'actor' => $actor ? ['id' => $actor->id, 'first_name' => $actor->first_name, 'last_name' => $actor->last_name,
                'email' => $actor->email, 'role' => in_array($actor->role?->name, array_column(UserRole::cases(), 'value'), true) ? $actor->role->name : null] : null,
            'action' => $known || $demo ? $this->action : 'unknown',
            'entity_type' => $known || $demo ? $this->entity_type : 'unknown', 'entity_id' => $this->entity_id,
            'details' => $known ? ['before' => $this->safeMetadata($this->old_values), 'after' => $this->safeMetadata($this->new_values)] : ['before' => [], 'after' => []],
        ];
    }
}
