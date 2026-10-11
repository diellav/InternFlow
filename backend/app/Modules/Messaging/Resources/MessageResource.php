<?php

namespace App\Modules\Messaging\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'conversation_id' => $this->conversation_id, 'sender_id' => $this->sender_id, 'content' => $this->content,
            'sent_at' => $this->sent_at?->toIso8601String(), 'sender' => ['id' => $this->sender->id, 'first_name' => $this->sender->first_name, 'last_name' => $this->sender->last_name]];
    }
}
