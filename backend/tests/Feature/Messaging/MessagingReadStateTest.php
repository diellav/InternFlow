<?php

namespace Tests\Feature\Messaging;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Internship;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Modules\Messaging\Services\MessagingService;
use App\Modules\Notification\Services\NotificationPayload;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class MessagingReadStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'UTC'));
    }

    private function fixture(string $type = 'group'): array
    {
        $company = Company::create(['name' => 'Read state fixture', 'is_active' => true, 'verification_status' => 'APPROVED']);
        $users = [];
        foreach (['STUDENT', 'COMPANY_SUPERVISOR', 'ACADEMIC_COORDINATOR'] as $role) {
            $user = User::factory()->create(['role_id' => Role::where('name', $role)->sole()->id, 'is_active' => true]);
            match ($role) {
                'STUDENT' => $user->studentProfile()->create(['student_number' => fake()->uuid(), 'study_program' => 'CS', 'study_year' => 2]),
                'COMPANY_SUPERVISOR' => $user->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => 'APPROVED']),
                'ACADEMIC_COORDINATOR' => $user->academicCoordinatorProfile()->create(['academic_unit' => 'Engineering']),
            };
            $users[] = $user;
        }
        $parent = Internship::create(['student_id' => $users[0]->id, 'company_id' => $company->id, 'company_supervisor_id' => $users[1]->id,
            'coordinator_id' => $users[2]->id, 'position_title' => 'Read fixture', 'description' => 'Private', 'status' => 'ACTIVE',
            'start_date' => '2026-10-01', 'end_date' => '2026-10-10']);
        $conversation = app(MessagingService::class)->open($users[0], $parent->id, $type);

        return [$parent, $conversation, ...$users];
    }

    private function send(User $sender, Conversation $conversation, string $content = 'Private body'): Message
    {
        return app(MessagingService::class)->send($sender, $conversation->id, $content);
    }

    private function read(User $actor, Conversation $conversation, int $id): TestResponse
    {
        return $this->actingAs($actor)->patchJson('/api/messaging/conversations/'.$conversation->id.'/read', ['last_read_message_id' => $id]);
    }

    private function counts(User $actor, int $messages, int $conversations): void
    {
        $this->actingAs($actor)->getJson('/api/messaging/unread-count')->assertOk()
            ->assertExactJson(['data' => ['unread_messages' => $messages, 'unread_conversations' => $conversations]]);
    }

    public function test_group_read_progress_is_independent_monotonic_and_does_not_acknowledge_later_arrivals(): void
    {
        [, $conversation, $student, $supervisor, $coordinator] = $this->fixture();
        $first = $this->send($student, $conversation);
        $second = $this->send($supervisor, $conversation);
        $third = $this->send($student, $conversation);
        $this->counts($student, 1, 1);
        $this->counts($supervisor, 2, 1);
        $this->counts($coordinator, 3, 1);
        $this->actingAs($coordinator)->getJson('/api/messaging/conversations')->assertOk()->assertJsonPath('data.0.unread_messages', 3)->assertJsonPath('data.0.read_state.last_read_message_id', null);
        $this->read($coordinator, $conversation, $second->id)->assertOk()->assertJsonPath('data.unread_messages', 1)->assertJsonPath('data.read_state.last_read_message_id', $second->id);
        $timestamp = DB::table('conversation_participants')->where('conversation_id', $conversation->id)->where('user_id', $coordinator->id)->value('last_read_at');
        $this->travel(1)->minutes();
        $this->read($coordinator, $conversation, $first->id)->assertOk()->assertJsonPath('data.read_state.last_read_message_id', $second->id);
        $this->read($coordinator, $conversation, $second->id)->assertOk()->assertJsonPath('data.read_state.last_read_message_id', $second->id);
        $this->assertSame($timestamp, DB::table('conversation_participants')->where('conversation_id', $conversation->id)->where('user_id', $coordinator->id)->value('last_read_at'));
        $this->counts($student, 1, 1);
        $this->counts($supervisor, 2, 1);
        $this->read($coordinator, $conversation, $third->id)->assertOk()->assertJsonPath('data.unread_messages', 0);
        $new = $this->send($supervisor, $conversation);
        $this->read($coordinator->fresh(), $conversation, $third->id)->assertOk()->assertJsonPath('data.unread_messages', 1);
        $this->read($coordinator, $conversation, $new->id)->assertOk();
        $this->counts($coordinator->fresh(), 0, 0);
    }

    public function test_direct_and_global_counts_exclude_foreign_conversations_and_other_read_state(): void
    {
        [, $direct, $student, $supervisor, $coordinator] = $this->fixture('student_supervisor');
        $group = app(MessagingService::class)->open($student, $direct->internship_id, 'group');
        [, $foreign, $foreignStudent] = $this->fixture();
        $first = $this->send($supervisor, $direct);
        $this->send($coordinator, $group);
        $this->send($foreignStudent, $foreign);
        $this->counts($student, 2, 2);
        $this->counts($supervisor, 1, 1);
        $this->counts($coordinator, 0, 0);
        $this->read($student, $direct, $first->id)->assertOk();
        $this->counts($student, 1, 1);
        $this->counts($supervisor, 1, 1);
    }

    public function test_foreign_future_missing_and_spoofed_read_cursors_are_rejected(): void
    {
        [, $conversation, $student] = $this->fixture();
        [, $foreign, $other] = $this->fixture();
        $message = $this->send($other, $foreign);
        $this->read($student, $conversation, $message->id)->assertUnprocessable();
        $this->read($student, $conversation, PHP_INT_MAX)->assertUnprocessable();
        $this->read($other, $conversation, $message->id)->assertNotFound();
        foreach ([[], ['last_read_message_id' => 0], ['last_read_message_id' => true], ['last_read_message_id' => 'bad'], ['last_read_message_id' => $message->id, 'user_id' => $other->id]] as $input) {
            $this->actingAs($student)->patchJson('/api/messaging/conversations/'.$conversation->id.'/read', $input)->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('conversation_participants')->whereNotNull('last_read_message_id')->count());
    }

    public function test_guest_admin_inactive_and_reassigned_users_are_denied(): void
    {
        [$parent, $conversation, $student, $supervisor, $coordinator] = $this->fixture();
        $message = $this->send($student, $conversation);
        $this->getJson('/api/messaging/unread-count')->assertUnauthorized();
        $this->patchJson('/api/messaging/conversations/'.$conversation->id.'/read', ['last_read_message_id' => $message->id])->assertUnauthorized();
        $admin = User::factory()->create(['role_id' => Role::where('name', 'ADMIN')->sole()->id, 'is_active' => true]);
        $this->actingAs($admin)->getJson('/api/messaging/unread-count')->assertForbidden();
        $this->read($admin, $conversation, $message->id)->assertForbidden();
        $supervisor->update(['is_active' => false]);
        $this->read($supervisor, $conversation, $message->id)->assertForbidden();
        $parent->update(['coordinator_id' => null]);
        $this->read($coordinator, $conversation, $message->id)->assertNotFound();
        $this->counts($coordinator, 0, 0);
    }

    public function test_completed_history_can_be_marked_read_but_not_written_and_unapproved_states_are_hidden(): void
    {
        [$parent, $conversation, $student, $supervisor] = $this->fixture();
        $message = $this->send($supervisor, $conversation);
        $parent->update(['status' => 'COMPLETED']);
        $this->counts($student, 1, 1);
        $this->read($student, $conversation, $message->id)->assertOk()->assertJsonPath('data.can_send', false)->assertJsonPath('data.unread_messages', 0);
        $this->postJson('/api/messaging/conversations/'.$conversation->id.'/messages', ['content' => 'Forbidden'])->assertConflict();
        foreach (['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'REVISION_REQUIRED', 'REJECTED'] as $status) {
            $parent->update(['status' => $status]);
            $this->counts($student, 0, 0);
            $this->read($student, $conversation, $message->id)->assertNotFound();
        }
    }

    public function test_list_previews_filters_activity_order_and_bounded_pagination(): void
    {
        [, $old, $student, $supervisor] = $this->fixture('student_supervisor');
        $group = app(MessagingService::class)->open($student, $old->internship_id, 'group');
        $groupMessage = $this->send($supervisor, $group);
        $this->travel(1)->seconds();
        $last = $this->send($supervisor, $old, str_repeat('ë', 210));
        $this->actingAs($student)->getJson('/api/messaging/conversations?filter=all&per_page=1')->assertOk()
            ->assertJsonPath('data.0.id', $old->id)->assertJsonPath('data.0.latest_message.id', $last->id)
            ->assertJsonPath('data.0.latest_message.preview', str_repeat('ë', 200))->assertJsonPath('data.0.latest_message.sender.id', $supervisor->id)
            ->assertJsonMissingPath('data.0.latest_message.sender.email')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/messaging/conversations?filter=all&per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $group->id);
        $this->read($student, $group, $groupMessage->id)->assertOk();
        $this->getJson('/api/messaging/conversations?filter=unread')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
        $this->getJson('/api/messaging/conversations?filter=read')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $group->id);
        $this->getJson('/api/messaging/conversations?filter=other')->assertUnprocessable();
        $this->getJson('/api/messaging/conversations/'.$old->id.'/messages?filter=read')->assertUnprocessable();
    }

    public function test_list_query_count_does_not_grow_with_conversation_count(): void
    {
        [, $first, $student] = $this->fixture('student_supervisor');
        $measure = function () use ($student): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->actingAs($student)->getJson('/api/messaging/conversations')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $this->send($student, $first);
        $this->actingAs($student)->getJson('/api/messaging/conversations')->assertOk();
        $one = $measure();
        foreach (['student_coordinator', 'group'] as $type) {
            $conversation = app(MessagingService::class)->open($student, $first->internship_id, $type);
            $this->send($student, $conversation);
        }
        $this->assertSame($one, $measure());
    }

    public function test_direct_and_group_notifications_are_safe_and_exclude_sender_and_foreign_users(): void
    {
        [, $direct, $student, $supervisor, $coordinator] = $this->fixture('student_supervisor');
        $this->send($student, $direct, 'Secret evidence');
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $supervisor->id, 'type' => 'message_received']);
        $group = app(MessagingService::class)->open($student, $direct->internship_id, 'group');
        $this->send($supervisor, $group);
        $this->assertDatabaseCount('notifications', 3);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $student->id)->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $coordinator->id)->count());
        foreach (DB::table('notifications')->get() as $notification) {
            $payload = json_decode($notification->data, true);
            $this->assertSame('conversation', $payload['related_type']);
            $this->assertSame('/messages/conversations/'.$payload['related_id'], $payload['action_url']);
            $this->assertEquals($payload, NotificationPayload::safe($payload));
            $this->assertStringNotContainsString('Secret evidence', $notification->data);
            $this->assertCount(6, $payload);
        }
    }

    public function test_stale_ineligible_recipients_are_not_notified_and_invalid_sends_create_none(): void
    {
        [$parent, $conversation, $student, $supervisor, $coordinator] = $this->fixture();
        $supervisor->update(['is_active' => false]);
        $parent->update(['coordinator_id' => null]);
        $this->send($student, $conversation);
        $this->assertDatabaseCount('notifications', 0);
        $this->actingAs($student)->postJson('/api/messaging/conversations/'.$conversation->id.'/messages', ['content' => '  '])->assertUnprocessable();
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_outer_rollback_removes_message_notifications_and_read_progress(): void
    {
        [, $conversation, $student, $supervisor] = $this->fixture();
        try {
            DB::transaction(function () use ($conversation, $student, $supervisor): void {
                $message = $this->send($student, $conversation);
                app(MessagingService::class)->markRead($supervisor, $conversation->id, $message->id);
                throw new RuntimeException('Rollback');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('Rollback', $error->getMessage());
        }
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(0, DB::table('conversation_participants')->whereNotNull('last_read_message_id')->count());
    }

    public function test_reassigned_member_is_skipped_without_suppressing_other_eligible_recipients(): void
    {
        [$parent, $conversation, $student, $supervisor] = $this->fixture();
        $parent->update(['coordinator_id' => null]);
        $this->send($student, $conversation);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $supervisor->id, 'type' => 'message_received']);
    }

    public function test_postgres_notification_failure_rolls_back_partial_delivery_without_losing_message(): void
    {
        [, $conversation, $student, $supervisor, $coordinator] = $this->fixture();
        DB::statement('CREATE FUNCTION pg_temp.reject_message_notice() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.notifiable_id = '.$coordinator->id.' THEN RAISE EXCEPTION \'isolated notification failure\'; END IF; RETURN NEW; END $$');
        DB::statement('CREATE TRIGGER messaging_failure BEFORE INSERT ON notifications FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_message_notice()');
        try {
            $message = $this->send($student, $conversation);
            $this->assertDatabaseHas('messages', ['id' => $message->id]);
            $this->assertDatabaseCount('notifications', 0);
            $this->counts($supervisor, 1, 1);
        } finally {
            DB::statement('DROP TRIGGER messaging_failure ON notifications');
        }
        $this->send($student, $conversation);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_notification_destination_contract_rejects_unsafe_variants(): void
    {
        foreach (['/messages/conversations/0', '/messages/conversations/01', '/messages/conversations/1?next=evil', '/messages/conversations/1#evil', '/messages/conversations/1'."\n", '//messages/conversations/1'] as $url) {
            $this->assertNull(NotificationPayload::safe(['action_url' => $url])['action_url']);
        }
    }
}
