<?php

use App\Models\User;
use App\Modules\Messaging\Services\MessagingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpKernel\Exception\HttpException;

Broadcast::channel('messaging.user.{userId}', function (User $user, string $userId): bool {
    if ((string) $user->id !== $userId) {
        return false;
    }
    try {
        app(MessagingService::class)->actor($user);

        return true;
    } catch (HttpException|ModelNotFoundException $denied) {
        return false;
    }
});

Broadcast::channel('messaging.conversation.{conversationId}.user.{userId}', function (User $user, string $conversationId, string $userId): bool {
    if ((string) $user->id !== $userId || ! ctype_digit($conversationId) || strlen($conversationId) > 18) {
        return false;
    }
    try {
        app(MessagingService::class)->conversation($user, (int) $conversationId);

        return true;
    } catch (HttpException|ModelNotFoundException $denied) {
        return false;
    }
});
