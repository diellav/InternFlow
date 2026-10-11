<?php

namespace App\Modules\Messaging\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class MessagingChanged implements ShouldBroadcastNow
{
    public function __construct(private readonly int $conversationId, private readonly int $userId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('messaging.conversation.'.$this->conversationId.'.user.'.$this->userId),
            new PrivateChannel('messaging.user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'messaging.changed';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
