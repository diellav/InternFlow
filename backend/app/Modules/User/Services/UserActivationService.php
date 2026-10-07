<?php

namespace App\Modules\User\Services;

use App\Models\User;
use App\Shared\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserActivationService
{
    public function update(User $actor, User $user, bool $isActive): User
    {
        return DB::transaction(function () use ($actor, $user, $isActive): User {
            $admins = User::query()
                ->whereHas('role', fn (Builder $role) => $role->where('name', UserRole::ADMIN->value))
                ->orderBy('id')->lockForUpdate()->get();

            $currentActor = $admins->firstWhere('id', $actor->id);

            if ($currentActor === null || ! $currentActor->is_active) {
                throw new HttpException(403, 'Account is inactive or no longer authorized.');
            }

            $target = $admins->firstWhere('id', $user->id)
                ?? User::query()->lockForUpdate()->findOrFail($user->id);

            if (! $isActive && $target->is_active) {
                if ($admins->contains('id', $target->id) && $admins->where('is_active', true)->count() <= 1) {
                    throw ValidationException::withMessages([
                        'is_active' => ['The last active Admin cannot be deactivated.'],
                    ]);
                }

                if ($target->is($currentActor)) {
                    throw ValidationException::withMessages([
                        'is_active' => ['You cannot deactivate your own account.'],
                    ]);
                }
            }

            if ($target->is_active !== $isActive) {
                $target->is_active = $isActive;
                $target->save();
            }

            return $target->load('role');
        });
    }
}
