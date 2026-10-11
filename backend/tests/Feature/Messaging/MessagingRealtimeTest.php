<?php

namespace Tests\Feature\Messaging;

use App\Models\Company;
use App\Models\Internship;
use App\Models\Role;
use App\Models\User;
use App\Modules\Messaging\Events\MessagingChanged;
use App\Modules\Messaging\Services\MessagingService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class MessagingRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Realtime fixture', 'is_active' => true, 'verification_status' => 'APPROVED']);
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
            'coordinator_id' => $users[2]->id, 'position_title' => 'Secure messaging', 'description' => 'Private', 'status' => 'ACTIVE',
            'start_date' => '2026-10-01', 'end_date' => '2026-10-10']);
        $conversation = app(MessagingService::class)->open($users[0], $parent->id, 'group');

        return [$parent, $conversation, ...$users];
    }

    private function broadcasting(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'isolated-key',
            'broadcasting.connections.reverb.secret' => 'isolated-secret', 'broadcasting.connections.reverb.app_id' => 'isolated']);
        Broadcast::purge();
        require base_path('routes/channels.php');
    }

    private function authChannel(User $user, string $channel): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-'.$channel]);
    }

    public function test_private_channel_authorization_reuses_current_membership_and_all_three_roles(): void
    {
        [$parent, $conversation, $student, $supervisor, $coordinator] = $this->fixture();
        [, , $foreign] = $this->fixture();
        $this->broadcasting();
        foreach ([$student, $supervisor, $coordinator] as $user) {
            $this->authChannel($user, 'messaging.user.'.$user->id)->assertOk()->assertJsonStructure(['auth']);
            $this->authChannel($user, 'messaging.conversation.'.$conversation->id.'.user.'.$user->id)->assertOk()->assertJsonStructure(['auth']);
            $this->authChannel($user, 'messaging.user.'.$foreign->id)->assertForbidden();
        }
        $this->authChannel($foreign, 'messaging.conversation.'.$conversation->id.'.user.'.$foreign->id)->assertForbidden();
        $this->authChannel($student, 'messaging.conversation.'.$conversation->id.'.user.'.$supervisor->id)->assertForbidden();
        $parent->update(['coordinator_id' => null]);
        $this->authChannel($coordinator, 'messaging.conversation.'.$conversation->id.'.user.'.$coordinator->id)->assertForbidden();
        $supervisor->update(['is_active' => false]);
        $this->authChannel($supervisor, 'messaging.user.'.$supervisor->id)->assertForbidden();
        $this->authChannel($student, 'messaging.conversation.999999.user.'.$student->id)->assertForbidden();
        $this->authChannel($student, 'messaging.conversation.99999999999999999999.user.'.$student->id)->assertForbidden();
    }

    public function test_guests_admins_and_unapproved_supervisors_cannot_subscribe(): void
    {
        [, $conversation, $student, $supervisor] = $this->fixture();
        $this->broadcasting();
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-messaging.user.'.$student->id])->assertUnauthorized();
        $admin = User::factory()->create(['role_id' => Role::where('name', 'ADMIN')->sole()->id, 'is_active' => true]);
        $this->authChannel($admin, 'messaging.user.'.$admin->id)->assertForbidden();
        $supervisor->companySupervisorProfile()->update(['verification_status' => 'PENDING']);
        $this->authChannel($supervisor, 'messaging.conversation.'.$conversation->id.'.user.'.$supervisor->id)->assertForbidden();
    }

    public function test_broadcast_is_after_commit_and_rollback_suppresses_delivery(): void
    {
        [, $conversation, $student] = $this->fixture();
        Event::fake([MessagingChanged::class]);
        DB::transaction(function () use ($conversation, $student): void {
            app(MessagingService::class)->send($student, $conversation->id, 'Private body');
            Event::assertNotDispatched(MessagingChanged::class);
        });
        Event::assertDispatchedTimes(MessagingChanged::class, 3);
        Event::fake([MessagingChanged::class]);
        try {
            DB::transaction(function () use ($conversation, $student): void {
                app(MessagingService::class)->send($student, $conversation->id, 'Rollback');
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $failure) {
            $this->assertSame('rollback', $failure->getMessage());
        }
        Event::assertNotDispatched(MessagingChanged::class);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_delivery_rechecks_reassignment_and_contains_no_sensitive_payload(): void
    {
        [$parent, $conversation, $student, $supervisor, $coordinator] = $this->fixture();
        Event::fake([MessagingChanged::class]);
        DB::transaction(function () use ($parent, $conversation, $student, $supervisor): void {
            app(MessagingService::class)->send($student, $conversation->id, 'Private evidence');
            $parent->update(['coordinator_id' => null]);
            $supervisor->update(['is_active' => false]);
        });
        Event::assertDispatchedTimes(MessagingChanged::class, 1);
        Event::assertDispatched(MessagingChanged::class, function ($event) use ($conversation, $student): bool {
            $this->assertSame([], $event->broadcastWith());
            $this->assertSame('messaging.changed', $event->broadcastAs());
            $this->assertSame(['private-messaging.conversation.'.$conversation->id.'.user.'.$student->id, 'private-messaging.user.'.$student->id], array_map(fn ($channel) => $channel->name, $event->broadcastOn()));

            return true;
        });
    }

    public function test_transport_failure_does_not_undo_committed_message_or_notifications(): void
    {
        [, $conversation, $student] = $this->fixture();
        Event::listen(MessagingChanged::class, fn () => throw new RuntimeException('Isolated transport failure'));
        app(MessagingService::class)->send($student, $conversation->id, 'Persisted');
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_authorized_search_is_bounded_escaped_and_does_not_search_a_global_directory(): void
    {
        [$parent, $conversation, $student] = $this->fixture();
        [, , $foreign] = $this->fixture();
        $this->actingAs($student)->getJson('/api/messaging/conversations?search=Secure')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.internship.position_title', 'Secure messaging')->assertJsonPath('data.0.internship.company_name', 'Realtime fixture')
            ->assertJsonMissingPath('data.0.internship.description')->assertJsonMissingPath('data.0.internship.decision_comment');
        $this->getJson('/api/messaging/conversations?search='.urlencode($foreign->first_name.' '.$foreign->last_name))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/messaging/conversations?search=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/messaging/conversations?search='.str_repeat('x', 101))->assertUnprocessable();
        $parent->update(['status' => 'DRAFT']);
        $this->getJson('/api/messaging/conversations?search=Secure')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_eligible_internship_options_resolve_authorized_types_without_exposing_private_data(): void
    {
        [$parent, , $student, $supervisor, $coordinator] = $this->fixture();
        $this->fixture();
        foreach ([[$student, ['student_supervisor', 'student_coordinator', 'group']], [$supervisor, ['student_supervisor', 'group']], [$coordinator, ['student_coordinator', 'group']]] as [$user, $types]) {
            $this->actingAs($user)->getJson('/api/messaging/internships')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $parent->id)
                ->assertJsonPath('data.0.available_types', $types)->assertJsonMissingPath('data.0.description')->assertJsonMissingPath('data.0.student_id');
        }
        $parent->update(['status' => 'COMPLETED']);
        $this->getJson('/api/messaging/internships')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/messaging/internships?per_page=51')->assertUnprocessable();
    }

    public function test_latest_history_and_older_cursor_preserve_default_ordering_without_duplicates(): void
    {
        [, $conversation, $student] = $this->fixture();
        for ($i = 1; $i <= 5; $i++) {
            app(MessagingService::class)->send($student, $conversation->id, 'Message '.$i);
        }
        $path = '/api/messaging/conversations/'.$conversation->id.'/messages';
        $this->actingAs($student)->getJson($path)->assertOk()->assertJsonPath('data.0.content', 'Message 1');
        $latest = $this->getJson($path.'?latest=true&per_page=2')->assertOk()->assertJsonPath('data.0.content', 'Message 5')->assertJsonPath('data.1.content', 'Message 4');
        $older = $this->getJson($latest->json('links.next'))->assertOk()->assertJsonPath('data.0.content', 'Message 3')->assertJsonPath('data.1.content', 'Message 2');
        $this->assertEmpty(array_intersect(array_column($latest->json('data'), 'id'), array_column($older->json('data'), 'id')));
        $this->getJson($path.'?latest=wrong')->assertUnprocessable();
    }
}
