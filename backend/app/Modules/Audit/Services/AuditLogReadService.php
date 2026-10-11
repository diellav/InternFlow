<?php

namespace App\Modules\Audit\Services;

use App\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class AuditLogReadService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = AuditLog::select(['id', 'user_id', 'action', 'entity_type', 'entity_id', 'created_at', 'old_values', 'new_values'])
            ->with(['user:id,role_id,first_name,last_name,email', 'user.role:id,name']);
        foreach (['action', 'entity_type'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['search'])) {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';
            $query->where(fn ($query) => $query->where('action', 'ilike', $pattern)->orWhere('entity_type', 'ilike', $pattern)
                ->orWhereRaw('CAST(entity_id AS TEXT) ILIKE ?', [$pattern])
                ->orWhereHas('user', fn ($users) => $users->whereRaw("(first_name || ' ' || last_name) ILIKE ?", [$pattern])->orWhere('email', 'ilike', $pattern)));
        }
        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'], config('app.timezone'))->startOfDay()->toIso8601String());
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<', Carbon::parse($filters['date_to'], config('app.timezone'))->startOfDay()->addDay()->toIso8601String());
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 15, ['*'], 'page', $filters['page'] ?? 1)->withQueryString();
    }
}
