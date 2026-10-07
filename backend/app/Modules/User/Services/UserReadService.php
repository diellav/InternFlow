<?php

namespace App\Modules\User\Services;

use App\Models\User;
use App\Shared\Enums\UserRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class UserReadService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = User::query()->with('role');

        if (isset($filters['search']) && $filters['search'] !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->where('first_name', 'ilike', $pattern)
                    ->orWhere('last_name', 'ilike', $pattern)
                    ->orWhere('email', 'ilike', $pattern)
                    ->orWhereRaw("concat_ws(' ', first_name, last_name) ILIKE ?", [$pattern]);
            });
        }

        if (isset($filters['role'])) {
            $query->whereHas('role', fn (Builder $role) => $role->where('name', $filters['role']));
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15, [
                'id', 'role_id', 'first_name', 'last_name', 'email', 'phone', 'is_active',
            ], 'page', $filters['page'] ?? 1)
            ->withQueryString();
    }

    public function details(User $user): User
    {
        $user->load('role');

        match (UserRole::tryFrom($user->role->name)) {
            UserRole::STUDENT => $user->load('studentProfile'),
            UserRole::COMPANY_SUPERVISOR => $user->load('companySupervisorProfile.company'),
            UserRole::ACADEMIC_COORDINATOR => $user->load('academicCoordinatorProfile'),
            default => null,
        };

        return $user;
    }
}
