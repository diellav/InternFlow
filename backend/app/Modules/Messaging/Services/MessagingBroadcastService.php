<?php

namespace App\Modules\Messaging\Services;

use App\Models\Message;
use App\Modules\Messaging\Events\MessagingChanged;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class MessagingBroadcastService
{
    public function publish(int $messageId): void
    {
        try {
            $message = Message::with('conversation.participants')->findOrFail($messageId);
            foreach ($message->conversation->participants as $recipient) {
                try {
                    app(MessagingService::class)->conversation($recipient, $message->conversation_id);
                } catch (HttpException|ModelNotFoundException $denied) {
                    continue;
                }
                try {
                    event(new MessagingChanged($message->conversation_id, $recipient->id));
                } catch (Throwable $failure) {
                    report($failure);
                }
            }
        } catch (Throwable $failure) {
            report($failure);
        }
    }
}
