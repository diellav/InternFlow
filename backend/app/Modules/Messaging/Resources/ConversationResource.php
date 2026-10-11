<?php

namespace App\Modules\Messaging\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $participant = $this->participantRecords->first();
        $latest = $this->latestMessage;

        return ['id' => $this->id, 'internship_id' => $this->internship_id, 'type' => $this->participants->count() === 3 ? 'group' : 'direct',
            'internship' => ['id' => $this->internship_id, 'position_title' => $this->internship->position_title, 'company_name' => $this->internship->company?->name, 'status' => $this->internship->status],
            'unread_messages' => (int) $this->unread_messages, 'has_unread_messages' => $this->unread_messages > 0,
            'read_state' => ['last_read_message_id' => $participant?->last_read_message_id, 'last_read_at' => $participant?->last_read_at?->toIso8601String()],
            'latest_message' => $latest === null ? null : ['id' => $latest->id, 'preview' => mb_substr($latest->content, 0, 200), 'sent_at' => $latest->sent_at->toIso8601String(),
                'sender' => ['id' => $latest->sender->id, 'first_name' => $latest->sender->first_name, 'last_name' => $latest->sender->last_name]],
            'can_send' => in_array($this->internship->status, ['APPROVED', 'ACTIVE'], true), 'created_at' => $this->created_at?->toIso8601String(),
            'participants' => $this->participants->sortBy('id')->map(fn ($user) => ['id' => $user->id, 'first_name' => $user->first_name, 'last_name' => $user->last_name])->values()->all()];
    }
}
