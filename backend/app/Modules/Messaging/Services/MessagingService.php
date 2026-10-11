<?php

namespace App\Modules\Messaging\Services;

use App\Models\Conversation;
use App\Models\Internship;
use App\Models\Message;
use App\Models\User;
use App\Modules\Notification\Services\NotificationDispatchService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MessagingService
{
    public function actor(User $actor): User
    {
        $user = User::with(['role', 'studentProfile', 'academicCoordinatorProfile', 'companySupervisorProfile.company'])->findOrFail($actor->id);
        abort_unless($user->is_active, 403);
        $eligible = match ($user->role?->name) {
            'STUDENT' => $user->studentProfile !== null,
            'ACADEMIC_COORDINATOR' => $user->academicCoordinatorProfile !== null,
            'COMPANY_SUPERVISOR' => $user->companySupervisorProfile?->verification_status?->value === 'APPROVED'
                && $user->companySupervisorProfile->company?->is_active
                && $user->companySupervisorProfile->company->verification_status?->value === 'APPROVED',
            default => false,
        };
        abort_unless($eligible, 403, 'An eligible messaging account is required.');

        return $user;
    }

    private function internshipScope(Builder $query, User $user): void
    {
        $field = match ($user->role->name) {
            'STUDENT' => 'student_id', 'COMPANY_SUPERVISOR' => 'company_supervisor_id', 'ACADEMIC_COORDINATOR' => 'coordinator_id',
        };
        $query->where($field, $user->id)->whereIn('status', ['APPROVED', 'ACTIVE', 'COMPLETED']);
        if ($user->role->name === 'COMPANY_SUPERVISOR') {
            $query->where('company_id', $user->companySupervisorProfile->company_id);
        }
    }

    public function conversations(User $actor): Builder
    {
        $user = $this->actor($actor);

        return Conversation::whereHas('participants', fn (Builder $query) => $query->where('users.id', $user->id))
            ->whereHas('internship', fn (Builder $query) => $this->internshipScope($query, $user))
            ->with(['participants:id,first_name,last_name', 'internship:id,status,position_title,company_id', 'internship.company:id,name', 'latestMessage.sender:id,first_name,last_name',
                'participantRecords' => fn ($query) => $query->where('user_id', $user->id)])
            ->withCount(['messages as unread_messages' => fn (Builder $query) => $this->unreadScope($query, $user->id)]);
    }

    private function unreadScope(Builder $query, int $userId): void
    {
        $query->where('sender_id', '!=', $userId)->whereRaw('messages.id > COALESCE((SELECT last_read_message_id FROM conversation_participants WHERE conversation_id = messages.conversation_id AND user_id = ?), 0)', [$userId]);
    }

    public function listing(User $actor, string $filter = 'all', ?string $search = null): Builder
    {
        $query = $this->conversations($actor);
        if ($search !== null && trim($search) !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
            $query->where(fn ($query) => $query->whereHas('participants', fn ($users) => $users->whereRaw("(first_name || ' ' || last_name) ILIKE ?", [$pattern]))
                ->orWhereHas('internship', fn ($internships) => $internships->where('position_title', 'ilike', $pattern)->orWhereHas('company', fn ($companies) => $companies->where('name', 'ilike', $pattern))));
        }
        if ($filter === 'unread') {
            $query->whereHas('messages', fn (Builder $messages) => $this->unreadScope($messages, $actor->id));
        } elseif ($filter === 'read') {
            $query->whereDoesntHave('messages', fn (Builder $messages) => $this->unreadScope($messages, $actor->id));
        }

        return $query->orderByRaw('COALESCE((SELECT sent_at FROM messages WHERE conversation_id = conversations.id ORDER BY id DESC LIMIT 1), conversations.created_at) DESC')->orderByDesc('conversations.id');
    }

    public function eligibleInternships(User $actor, int $perPage, int $page): LengthAwarePaginator
    {
        $user = $this->actor($actor);
        $query = Internship::with(['student.user.role', 'companySupervisor.user.role', 'companySupervisor.company', 'coordinator.user.role', 'company']);
        $this->internshipScope($query, $user);

        return $query->whereIn('status', ['APPROVED', 'ACTIVE'])->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page)
            ->through(function ($internship) use ($user): array {
                $student = $internship->student?->user;
                $supervisor = $internship->companySupervisor?->user;
                $coordinator = $internship->coordinator?->user;
                $eligibleStudent = $student?->is_active && $student->role?->name === 'STUDENT';
                $eligibleSupervisor = $supervisor?->is_active && $supervisor->role?->name === 'COMPANY_SUPERVISOR'
                    && $internship->companySupervisor->company_id === $internship->company_id
                    && $internship->companySupervisor->verification_status?->value === 'APPROVED'
                    && $internship->company?->is_active && $internship->company->verification_status?->value === 'APPROVED';
                $eligibleCoordinator = $coordinator?->is_active && $coordinator->role?->name === 'ACADEMIC_COORDINATOR';
                $types = [];
                if ($eligibleStudent && $eligibleSupervisor && in_array($user->id, [$student->id, $supervisor->id], true)) {
                    $types[] = 'student_supervisor';
                }
                if ($eligibleStudent && $eligibleCoordinator && in_array($user->id, [$student->id, $coordinator->id], true)) {
                    $types[] = 'student_coordinator';
                }
                if ($eligibleStudent && $eligibleSupervisor && $eligibleCoordinator) {
                    $types[] = 'group';
                }

                return ['id' => $internship->id, 'position_title' => $internship->position_title, 'company_name' => $internship->company?->name,
                    'available_types' => $types];
            });
    }

    public function unreadCounts(User $actor): array
    {
        $query = $this->conversations($actor)->reorder()->withoutEagerLoads();
        $counts = DB::query()->fromSub($query, 'authorized_conversations')->selectRaw('COALESCE(SUM(unread_messages), 0) AS unread_messages, COUNT(*) FILTER (WHERE unread_messages > 0) AS unread_conversations')->first();

        return ['unread_messages' => (int) $counts->unread_messages, 'unread_conversations' => (int) $counts->unread_conversations];
    }

    public function markRead(User $actor, int $conversationId, int $messageId): Conversation
    {
        return DB::transaction(function () use ($actor, $conversationId, $messageId): Conversation {
            $conversation = $this->conversation($actor, $conversationId);
            Internship::whereKey($conversation->internship_id)->lockForUpdate()->firstOrFail();
            $this->conversation($actor, $conversationId);
            if (! $conversation->messages()->whereKey($messageId)->exists()) {
                throw ValidationException::withMessages(['last_read_message_id' => ['The message cursor is unavailable in this conversation.']]);
            }
            DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $actor->id)
                ->where(fn ($query) => $query->whereNull('last_read_message_id')->orWhere('last_read_message_id', '<', $messageId))
                ->update(['last_read_message_id' => $messageId, 'last_read_at' => now()->toIso8601String()]);

            return $this->conversation($actor, $conversationId);
        }, 3);
    }

    public function conversation(User $actor, int $id): Conversation
    {
        return $this->conversations($actor)->findOrFail($id);
    }

    public function open(User $actor, int $internshipId, string $type): Conversation
    {
        return DB::transaction(function () use ($actor, $internshipId, $type): Conversation {
            $user = $this->actor($actor);
            $query = Internship::query();
            $this->internshipScope($query, $user);
            $parent = $query->lockForUpdate()->findOrFail($internshipId);
            abort_unless(in_array($parent->status, ['APPROVED', 'ACTIVE'], true), 409, 'Completed messaging is read-only.');
            $ids = match ($type) {
                'student_supervisor' => [$parent->student_id, $parent->company_supervisor_id],
                'student_coordinator' => [$parent->student_id, $parent->coordinator_id],
                'group' => [$parent->student_id, $parent->company_supervisor_id, $parent->coordinator_id],
            };
            abort_unless(in_array($user->id, $ids, true), 403);
            abort_if(in_array(null, $ids, true) || count(array_unique($ids)) !== count($ids), 409, 'Required assignments are unavailable.');
            foreach ($ids as $id) {
                $recipient = User::findOrFail($id);
                $recipient = $this->actor($recipient);
                $scope = Internship::whereKey($parent->id);
                $this->internshipScope($scope, $recipient);
                abort_unless($scope->exists(), 409, 'A participant is no longer eligible.');
            }
            sort($ids);
            $existing = $parent->conversations()->whereHas('participants', fn (Builder $query) => $query->whereIn('users.id', $ids), '=', count($ids))
                ->has('participants', '=', count($ids))->orderBy('id')->first();
            if ($existing !== null) {
                return $this->conversation($user, $existing->id);
            }
            $timestamp = now()->toIso8601String();
            $id = DB::table('conversations')->insertGetId(['internship_id' => $parent->id, 'created_at' => $timestamp]);
            DB::table('conversation_participants')->insert(array_map(fn ($participant) => ['conversation_id' => $id, 'user_id' => $participant, 'joined_at' => $timestamp], $ids));

            return $this->conversation($user, $id);
        }, 3);
    }

    public function send(User $actor, int $conversationId, string $content): Message
    {
        return DB::transaction(function () use ($actor, $conversationId, $content): Message {
            $conversation = $this->conversation($actor, $conversationId);
            $parent = Internship::whereKey($conversation->internship_id)->lockForUpdate()->firstOrFail();
            $conversation = $this->conversation($actor, $conversationId);
            abort_unless(in_array($parent->status, ['APPROVED', 'ACTIVE'], true), 409, 'Completed messaging is read-only.');
            $id = DB::table('messages')->insertGetId(['conversation_id' => $conversation->id, 'sender_id' => $actor->id, 'content' => $content, 'sent_at' => now()->toIso8601String()]);

            $message = Message::with('sender:id,first_name,last_name')->findOrFail($id);
            app(NotificationDispatchService::class)->messageReceived($conversation, $message);
            DB::afterCommit(fn () => app(MessagingBroadcastService::class)->publish($id));

            return $message;
        }, 3);
    }
}
