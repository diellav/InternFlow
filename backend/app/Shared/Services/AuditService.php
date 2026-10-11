<?php

namespace App\Shared\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class AuditService
{
    private const EVENTS = [
        'coordinator.created' => 'user', 'coordinator.updated' => 'user',
        'user.activated' => 'user', 'user.deactivated' => 'user',
        'company.approved' => 'company', 'company.rejected' => 'company',
        'supervisor.approved' => 'company_supervisor_profile', 'supervisor.rejected' => 'company_supervisor_profile',
        'company.application_updated' => 'company', 'company.resubmitted' => 'company',
        'supervisor.application_updated' => 'company_supervisor_profile', 'supervisor.resubmitted' => 'company_supervisor_profile',
        'internship.submitted' => 'internship', 'internship.resubmitted' => 'internship',
        'internship.claimed' => 'internship', 'internship.review_started' => 'internship',
        'internship.approved' => 'internship', 'internship.rejected' => 'internship',
        'internship.revision_required' => 'internship', 'internship.activated' => 'internship',
        'internship.completed' => 'internship',
        'task.created' => 'task', 'task.updated' => 'task', 'task.started' => 'task',
        'task.submitted' => 'task', 'task.approved' => 'task', 'task.revision_required' => 'task',
        'submission.created' => 'task_submission',
        'activity.created' => 'activity_log', 'activity.updated' => 'activity_log',
        'evaluation.submitted' => 'final_evaluation',
    ];

    public static function events(): array
    {
        return self::EVENTS;
    }

    public function record(User $actor, string $action, int $entityId, array $old = [], array $new = []): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Audit writes require the business transaction.');
        }
        if (! isset(self::EVENTS[$action]) || $entityId < 1 || ! $actor->exists) {
            throw new InvalidArgumentException('Invalid audit identity.');
        }
        AuditLog::create(['user_id' => $actor->id, 'action' => $action, 'entity_type' => self::EVENTS[$action],
            'entity_id' => $entityId, 'old_values' => $this->metadata($old), 'new_values' => $this->metadata($new)]);
    }

    private function metadata(array $values): ?array
    {
        foreach ($values as $key => $value) {
            $valid = match ($key) {
                'status' => in_array($value, ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'REVISION_REQUIRED', 'APPROVED', 'ACTIVE', 'COMPLETED', 'REJECTED', 'PENDING', 'ASSIGNED', 'IN_PROGRESS', 'CANCELLED'], true),
                'is_active', 'submitted' => is_bool($value),
                'coordinator_id' => $value === null || (is_int($value) && $value > 0),
                'version_no' => is_int($value) && $value > 0,
                'hours' => $value === null || ((is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d{1,2}(?:\.\d{1,2})?$/D', $value) === 1)) && is_numeric($value) && (float) $value >= 0 && (float) $value <= 24),
                default => false,
            };
            if (! $valid) {
                throw new InvalidArgumentException('Audit metadata must use safe allowlisted values.');
            }
        }

        return $values === [] ? null : $values;
    }
}
