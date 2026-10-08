<?php

namespace App\Modules\Company\Services;

use App\Models\User;
use App\Shared\Enums\UserRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class SupervisorReadService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = User::query()->whereHas('role', fn (Builder $role) => $role->where('name', UserRole::COMPANY_SUPERVISOR->value))
            ->with('companySupervisorProfile.company:id,name,is_active,verification_status');

        if (isset($filters['search']) && $filters['search'] !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->where('first_name', 'ilike', $pattern)->orWhere('last_name', 'ilike', $pattern)
                    ->orWhere('email', 'ilike', $pattern)->orWhereRaw("concat_ws(' ', first_name, last_name) ILIKE ?", [$pattern]);
            });
        }

        if (isset($filters['verification_status']) || isset($filters['company_id'])) {
            $query->whereHas('companySupervisorProfile', function (Builder $profile) use ($filters): void {
                if (isset($filters['verification_status'])) {
                    $profile->where('verification_status', $filters['verification_status']);
                }
                if (isset($filters['company_id'])) {
                    $profile->where('company_id', $filters['company_id']);
                }
            });
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15, ['id', 'first_name', 'last_name', 'email', 'phone', 'is_active', 'created_at'], 'page', $filters['page'] ?? 1)
            ->withQueryString();
    }

    public function details(User $user): User
    {
        abort_unless($user->hasRole(UserRole::COMPANY_SUPERVISOR), 404);

        return $user->load('companySupervisorProfile.company:id,name,is_active,verification_status');
    }
}
