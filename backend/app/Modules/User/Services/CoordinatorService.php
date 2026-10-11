<?php

namespace App\Modules\User\Services;

use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\UserRole;
use App\Shared\Services\AuditService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CoordinatorService
{
    public function create(array $data, User $actor): User
    {
        return $this->transaction(function () use ($data, $actor): User {
            $user = User::query()->create([
                ...Arr::only($data, ['first_name', 'last_name', 'email', 'phone', 'password']),
                'role_id' => Role::query()->where('name', UserRole::ACADEMIC_COORDINATOR->value)->sole()->id,
                'is_active' => true,
            ]);
            $user->academicCoordinatorProfile()->create(['academic_unit' => $data['academic_unit'] ?? null]);

            app(AuditService::class)->record($actor, 'coordinator.created', $user->id, [], []);

            return $user->load(['role', 'academicCoordinatorProfile']);
        });
    }

    public function update(User $user, array $data, User $actor): User
    {
        return $this->transaction(function () use ($user, $data, $actor): User {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if (! $user->hasRole(UserRole::ACADEMIC_COORDINATOR)) {
                throw ValidationException::withMessages(['user' => ['Only Academic Coordinator accounts can be edited here.']]);
            }

            $previous = $user->only(['first_name', 'last_name', 'email', 'phone']);
            $previousUnit = $user->academicCoordinatorProfile?->academic_unit;
            $user->fill(Arr::only($data, ['first_name', 'last_name', 'email', 'phone']))->save();
            if (array_key_exists('academic_unit', $data) || ! $user->academicCoordinatorProfile()->exists()) {
                $user->academicCoordinatorProfile()->updateOrCreate([], ['academic_unit' => $data['academic_unit'] ?? null]);
            }

            if ($previous !== $user->only(['first_name', 'last_name', 'email', 'phone']) || $previousUnit !== $user->academicCoordinatorProfile()->first()?->academic_unit) {
                app(AuditService::class)->record($actor, 'coordinator.updated', $user->id, [], []);
            }

            return $user->load(['role', 'academicCoordinatorProfile']);
        });
    }

    private function transaction(Closure $operation): User
    {
        try {
            return DB::transaction($operation);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23505' && str_contains((string) ($exception->errorInfo[2] ?? ''), 'users_email_unique')) {
                throw ValidationException::withMessages(['email' => ['The email has already been taken.']]);
            }

            throw $exception;
        }
    }
}
