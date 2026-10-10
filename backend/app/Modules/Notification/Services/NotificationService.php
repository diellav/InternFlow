<?php

namespace App\Modules\Notification\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

class NotificationService
{
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = $user->notifications()->reorder()->orderByDesc('created_at')->orderByDesc('id');
        if (($filters['status'] ?? 'all') === 'unread') {
            $query->unread();
        } elseif (($filters['status'] ?? 'all') === 'read') {
            $query->read();
        }

        return $query->paginate($filters['per_page'] ?? 15, ['*'], 'page', $filters['page'] ?? 1)->withQueryString();
    }

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    public function read(User $user, string $id): DatabaseNotification
    {
        $notification = $user->notifications()->whereKey($id)->firstOrFail();
        $user->unreadNotifications()->whereKey($id)->update(['read_at' => now()->toIso8601String()]);

        return $notification->refresh();
    }

    public function readAll(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => now()->toIso8601String()]);
    }
}
