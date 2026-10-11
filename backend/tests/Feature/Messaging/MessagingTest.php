<?php

namespace Tests\Feature\Messaging;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Internship;
use App\Models\Role;
use App\Models\User;
use App\Modules\Messaging\Services\MessagingService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'UTC'));
    }

    private function user(string $role, ?Company $company = null): User
    {
        $user = User::factory()->create(['role_id' => Role::where('name', $role)->sole()->id, 'is_active' => true]);
        match ($role) {
            'STUDENT' => $user->studentProfile()->create(['student_number' => fake()->uuid(), 'study_program' => 'CS', 'study_year' => 2]),
            'ACADEMIC_COORDINATOR' => $user->academicCoordinatorProfile()->create(['academic_unit' => 'Engineering']),
            'COMPANY_SUPERVISOR' => $user->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => 'APPROVED']),
            default => null,
        };

        return $user;
    }

    private function fixture(string $status = 'ACTIVE'): Internship
    {
        $company = Company::create(['name' => 'Isolated messaging company', 'is_active' => true, 'verification_status' => 'APPROVED']);
        $student = $this->user('STUDENT');
        $supervisor = $this->user('COMPANY_SUPERVISOR', $company);
        $coordinator = $this->user('ACADEMIC_COORDINATOR');

        return Internship::create(['student_id' => $student->id, 'company_id' => $company->id, 'company_supervisor_id' => $supervisor->id,
            'coordinator_id' => $coordinator->id, 'position_title' => 'Messaging fixture', 'description' => 'Private evidence', 'status' => $status,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-10']);
    }

    private function open(Internship $parent, string $type = 'group'): Conversation
    {
        return app(MessagingService::class)->open($parent->student->user, $parent->id, $type);
    }

    private function path(Conversation $conversation, string $suffix = ''): string
    {
        return '/api/messaging/conversations/'.$conversation->id.$suffix;
    }

    public function test_guest_and_administrator_are_excluded_from_all_endpoints(): void
    {
        $parent = $this->fixture();
        $conversation = $this->open($parent);
        foreach ([null, $this->user('ADMIN')] as $user) {
            if ($user !== null) {
                $this->actingAs($user);
            }
            $status = $user === null ? 401 : 403;
            $this->getJson('/api/messaging/conversations')->assertStatus($status);
            $this->getJson($this->path($conversation))->assertStatus($status);
            $this->getJson($this->path($conversation, '/messages'))->assertStatus($status);
            $this->postJson($this->path($conversation, '/messages'), ['content' => 'Hello'])->assertStatus($status);
            $this->postJson('/api/internships/'.$parent->id.'/conversations', ['type' => 'group'])->assertStatus($status);
        }
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_all_three_roles_can_read_and_send_only_as_members(): void
    {
        $parent = $this->fixture();
        $conversation = $this->open($parent);
        foreach ([$parent->student->user, $parent->companySupervisor->user, $parent->coordinator->user] as $user) {
            $this->actingAs($user)->getJson('/api/messaging/conversations')->assertOk()->assertJsonCount(1, 'data');
            $this->getJson($this->path($conversation))->assertOk()->assertJsonPath('data.can_send', true)->assertJsonMissingPath('data.participants.0.email');
            $this->postJson($this->path($conversation, '/messages'), ['content' => "  Hello\nsecond line  "])->assertOk()
                ->assertJsonPath('data.sender_id', $user->id)->assertJsonPath('data.content', "Hello\nsecond line")
                ->assertJsonPath('data.sent_at', fn ($timestamp) => Carbon::parse($timestamp)->equalTo(now()));
        }
        $this->assertDatabaseCount('messages', 3);
        $this->assertDatabaseCount('notifications', 6);
    }

    public function test_foreign_roles_and_same_company_supervisor_cannot_access_or_inject_membership(): void
    {
        $parent = $this->fixture();
        $conversation = $this->open($parent);
        $other = $this->fixture();
        foreach ([$other->student->user, $other->companySupervisor->user, $other->coordinator->user, $this->user('COMPANY_SUPERVISOR', $parent->company)] as $user) {
            $this->actingAs($user)->getJson('/api/messaging/conversations')->assertOk()->assertJsonCount(0, 'data');
            $this->getJson($this->path($conversation))->assertNotFound();
            $this->getJson($this->path($conversation, '/messages'))->assertNotFound();
            $this->postJson($this->path($conversation, '/messages'), ['content' => 'Forged'])->assertNotFound();
            $this->postJson('/api/internships/'.$parent->id.'/conversations', ['type' => 'group'])->assertNotFound();
        }
        $this->actingAs($parent->student->user)->postJson('/api/internships/'.$parent->id.'/conversations', ['type' => 'group', 'user_ids' => [$other->student_id]])->assertUnprocessable();
        $this->assertDatabaseCount('conversation_participants', 3);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_direct_and_group_sets_are_idempotent_and_nonmembers_cannot_open_other_direct_pair(): void
    {
        $parent = $this->fixture('APPROVED');
        foreach (['student_supervisor', 'student_coordinator', 'group'] as $type) {
            $first = $this->actingAs($parent->student->user)->postJson('/api/internships/'.$parent->id.'/conversations', ['type' => $type])->assertOk()->json('data.id');
            $this->postJson('/api/internships/'.$parent->id.'/conversations', ['type' => $type])->assertOk()->assertJsonPath('data.id', $first);
            $counterpart = $type === 'student_coordinator' ? $parent->coordinator->user : $parent->companySupervisor->user;
            $this->actingAs($counterpart)->postJson('/api/internships/'.$parent->id.'/conversations', ['type' => $type])->assertOk()->assertJsonPath('data.id', $first);
        }
        $this->assertDatabaseCount('conversations', 3);
        $this->assertDatabaseCount('conversation_participants', 7);
        $direct = $this->open($parent, 'student_supervisor');
        $this->actingAs($parent->coordinator->user)->getJson($this->path($direct))->assertNotFound();
        $this->postJson('/api/internships/'.$parent->id.'/conversations', ['type' => 'student_supervisor'])->assertForbidden();
    }

    public static function forbiddenStates(): array
    {
        return array_map(fn ($state) => [$state], ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'REVISION_REQUIRED', 'REJECTED']);
    }

    #[DataProvider('forbiddenStates')]
    public function test_unapproved_lifecycle_states_are_not_visible_or_writable(string $status): void
    {
        $parent = $this->fixture();
        $conversation = $this->open($parent);
        $parent->update(['status' => $status]);
        $this->actingAs($parent->student->user)->getJson('/api/messaging/conversations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->path($conversation))->assertNotFound();
        $this->getJson($this->path($conversation, '/messages'))->assertNotFound();
        $this->postJson($this->path($conversation, '/messages'), ['content' => 'Hello'])->assertNotFound();
        $this->postJson('/api/internships/'.$parent->id.'/conversations', ['type' => 'group'])->assertNotFound();
    }

    public function test_completed_history_is_immutable_and_existing_messages_are_preserved(): void
    {
        $parent = $this->fixture();
        $conversation = $this->open($parent);
        $record = app(MessagingService::class)->send($parent->student->user, $conversation->id, 'Historical message');
        $before = $record->getAttributes();
        $parent->update(['status' => 'COMPLETED', 'completed_at' => now()]);
        foreach ([$parent->student->user, $parent->companySupervisor->user, $parent->coordinator->user] as $user) {
            $this->actingAs($user)->getJson($this->path($conversation))->assertOk()->assertJsonPath('data.can_send', false);
            $this->getJson($this->path($conversation, '/messages'))->assertOk()->assertJsonPath('data.0.content', 'Historical message');
            $this->postJson($this->path($conversation, '/messages'), ['content' => 'Forbidden'])->assertConflict();
            $this->postJson('/api/internships/'.$parent->id.'/conversations', ['type' => 'group'])->assertConflict();
        }
        $this->patchJson($this->path($conversation, '/messages/'.$record->id), ['content' => 'Edit'])->assertNotFound();
        $this->deleteJson($this->path($conversation, '/messages/'.$record->id))->assertNotFound();
        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertDatabaseCount('messages', 1);
    }

    public static function invalidMessages(): array
    {
        return array_map(fn ($body) => [$body], [[], ['content' => '  '], ['content' => "\n\t\u{00a0}"], ['content' => str_repeat('a', 2001)], ['content' => []], ['content' => "bad\0text"], ['content' => 'Hello', 'sender_id' => 1], ['content' => 'Hello', 'conversation_id' => 1], ['content' => 'Hello', 'files' => []], ['body' => 'Wrong field']]);
    }

    #[DataProvider('invalidMessages')]
    public function test_message_validation_and_spoofing_reject_without_writes(array $body): void
    {
        $parent = $this->fixture();
        $conversation = $this->open($parent);
        $this->actingAs($parent->student->user)->postJson($this->path($conversation, '/messages'), $body)->assertUnprocessable();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_cursor_history_is_chronological_with_ties_and_isolated_after_new_insert(): void
    {
        $parent = $this->fixture();
        $conversation = $this->open($parent);
        $other = $this->open($this->fixture());
        $service = app(MessagingService::class);
        for ($i = 1; $i <= 5; $i++) {
            $service->send($parent->student->user, $conversation->id, 'Message '.$i);
        }
        $service->send($other->participants->first(), $other->id, 'Foreign message');
        $this->actingAs($parent->student->user);
        $first = $this->getJson($this->path($conversation, '/messages?per_page=2'))->assertOk()->assertJsonPath('data.0.content', 'Message 1')->assertJsonPath('data.1.content', 'Message 2');
        $service->send($parent->student->user, $conversation->id, '<script>literal text, not HTML</script>');
        $second = $this->getJson($first->json('links.next'))->assertOk()->assertJsonPath('data.0.content', 'Message 3')->assertJsonPath('data.1.content', 'Message 4');
        $this->assertEmpty(array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));
        $third = $this->getJson($second->json('links.next'))->assertOk()->assertJsonPath('data.0.content', 'Message 5')->assertJsonPath('data.1.content', '<script>literal text, not HTML</script>');
        $this->assertNull($third->json('links.next'));
    }

    public function test_missing_assignment_and_ineligible_users_cannot_create_or_read(): void
    {
        $parent = $this->fixture();
        $conversation = $this->open($parent);
        $supervisor = $parent->companySupervisor->user;
        $supervisor->update(['is_active' => false]);
        $this->actingAs($supervisor)->getJson($this->path($conversation))->assertForbidden();
        $this->actingAs($parent->student->user)->postJson('/api/internships/'.$parent->id.'/conversations', ['type' => 'group'])->assertForbidden();
        $supervisor->update(['is_active' => true]);
        $parent->company->update(['is_active' => false]);
        $this->actingAs($supervisor)->getJson($this->path($conversation))->assertForbidden();
        $parent->company->update(['is_active' => true]);
        $supervisor->companySupervisorProfile()->update(['verification_status' => 'PENDING']);
        $this->actingAs($supervisor)->getJson($this->path($conversation))->assertForbidden();
        $parent->update(['coordinator_id' => null]);
        $this->actingAs($parent->student->user)->postJson('/api/internships/'.$parent->id.'/conversations', ['type' => 'student_coordinator'])->assertConflict();
    }

    public function test_reassignment_does_not_grant_new_assignee_legacy_membership_or_keep_foreign_access(): void
    {
        $parent = $this->fixture();
        $conversation = $this->open($parent);
        $old = $parent->coordinator->user;
        $replacement = $this->user('ACADEMIC_COORDINATOR');
        $parent->update(['coordinator_id' => $replacement->id]);
        foreach ([$old, $replacement] as $user) {
            $this->actingAs($user)->getJson($this->path($conversation))->assertNotFound();
        }
        $new = app(MessagingService::class)->open($replacement, $parent->id, 'group');
        $this->assertNotSame($conversation->id, $new->id);
        $this->assertSame(2, $parent->conversations()->count());
    }

    public function test_pagination_bounds_unknown_fields_and_bad_cursor_fail_safely(): void
    {
        $parent = $this->fixture();
        $conversation = $this->open($parent);
        $this->actingAs($parent->student->user);
        foreach (['per_page=51', 'per_page=0', 'page=-1', 'page=1000001', 'user_id=1'] as $query) {
            $this->getJson('/api/messaging/conversations?'.$query)->assertUnprocessable();
        }
        foreach (['cursor=bad', 'cursor=e30', 'page=1', 'per_page=51'] as $query) {
            $this->getJson($this->path($conversation, '/messages?'.$query))->assertUnprocessable();
        }
        $this->postJson('/api/internships/'.$parent->id.'/conversations', ['type' => 'arbitrary'])->assertUnprocessable();
        $this->getJson('/api/messaging/conversations/not-a-number')->assertNotFound();
    }

    public function test_outer_rollback_removes_conversation_members_and_messages(): void
    {
        $parent = $this->fixture();
        try {
            DB::transaction(function () use ($parent): void {
                $conversation = $this->open($parent);
                app(MessagingService::class)->send($parent->student->user, $conversation->id, 'Rolled back');
                throw new RuntimeException('Rollback');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('Rollback', $error->getMessage());
        }
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('conversation_participants', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_concurrent_open_requests_share_the_same_conversation(): void
    {
        $this->assertSame('internflow_testing', DB::selectOne('select current_database() as name')->name);
        $parent = $this->fixture();
        $userIds = [$parent->student_id, $parent->company_supervisor_id, $parent->coordinator_id];
        $companyId = $parent->company_id;
        $configuration = json_encode(config('database.connections.pgsql'), JSON_THROW_ON_ERROR);
        config(['database.connections.messaging_probe' => config('database.connections.pgsql')]);
        $existingRoleIds = DB::connection('messaging_probe')->table('roles')->pluck('id')->all();
        DB::purge('messaging_probe');
        $worker = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql' => json_decode(stream_get_contents(STDIN), true)]);
Illuminate\Support\Facades\DB::purge('pgsql');
if (Illuminate\Support\Facades\DB::selectOne('select current_database() as name')->name !== 'internflow_testing') { exit(2); }
Illuminate\Support\Facades\DB::transaction(function () use ($argv) {
    if ($argv[4] === 'hold') {
        App\Models\Internship::whereKey($argv[1])->lockForUpdate()->firstOrFail();
        echo "LOCKED\n";
        flush();
        usleep(500000);
    }
    $conversation = app(App\Modules\Messaging\Services\MessagingService::class)->open(App\Models\User::findOrFail($argv[2]), (int) $argv[1], $argv[3]);
    if (($argv[5] ?? null) === 'send') {
        $message = app(App\Modules\Messaging\Services\MessagingService::class)->send(App\Models\User::findOrFail($argv[2]), $conversation->id, 'Concurrent message');
        echo $message->id."\n";
        return;
    }
    if (($argv[5] ?? null) === 'read') {
        $record = app(App\Modules\Messaging\Services\MessagingService::class)->markRead(App\Models\User::findOrFail($argv[2]), $conversation->id, (int) $argv[6]);
        echo $record->participantRecords->first()->last_read_message_id."\n";
        return;
    }
    echo $conversation->id."\n";
});
PHP;
        DB::commit();
        $processes = [];
        try {
            foreach (['student_supervisor', 'student_coordinator', 'group'] as $type) {
                $counterpart = $type === 'student_coordinator' ? $parent->coordinator_id : $parent->company_supervisor_id;
                $first = new Process([PHP_BINARY, '-r', $worker, (string) $parent->id, (string) $parent->student_id, $type, 'hold'], base_path(), ['APP_ENV' => 'testing']);
                $first->setInput($configuration)->setTimeout(15)->start();
                $processes[] = $first;
                $deadline = microtime(true) + 5;
                while (! str_contains($first->getOutput(), 'LOCKED') && $first->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('LOCKED', $first->getOutput());
                $second = new Process([PHP_BINARY, '-r', $worker, (string) $parent->id, (string) $counterpart, $type, 'open'], base_path(), ['APP_ENV' => 'testing']);
                $second->setInput($configuration)->setTimeout(15)->start();
                $processes[] = $second;
                $first->wait();
                $second->wait();
                $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
                $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
                $this->assertSame(trim(str_replace('LOCKED', '', $first->getOutput())), trim($second->getOutput()));
            }
            $this->assertSame(3, $parent->conversations()->count());
            $this->assertSame(7, DB::table('conversation_participants')->count());
            $race = function (array $firstArguments, array $secondArguments) use ($worker, $configuration, &$processes): array {
                $first = new Process([PHP_BINARY, '-r', $worker, ...$firstArguments], base_path(), ['APP_ENV' => 'testing']);
                $first->setInput($configuration)->setTimeout(15)->start();
                $processes[] = $first;
                $deadline = microtime(true) + 5;
                while (! str_contains($first->getOutput(), 'LOCKED') && $first->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('LOCKED', $first->getOutput());
                $second = new Process([PHP_BINARY, '-r', $worker, ...$secondArguments], base_path(), ['APP_ENV' => 'testing']);
                $second->setInput($configuration)->setTimeout(15)->start();
                $processes[] = $second;
                $first->wait();
                $second->wait();
                $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
                $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());

                return [trim(str_replace('LOCKED', '', $first->getOutput())), trim($second->getOutput())];
            };
            $messageIds = $race([(string) $parent->id, (string) $parent->student_id, 'group', 'hold', 'send'],
                [(string) $parent->id, (string) $parent->company_supervisor_id, 'group', 'open', 'send']);
            $this->assertNotSame($messageIds[0], $messageIds[1]);
            $this->assertSame(2, DB::table('messages')->count());
            $this->assertSame(4, DB::table('notifications')->count());
            $group = $parent->conversations()->has('participants', '=', 3)->sole();
            $low = (string) min($messageIds);
            $high = (string) max($messageIds);
            foreach ([[$high, $low], [$low, $high]] as [$firstId, $secondId]) {
                DB::table('conversation_participants')->where('conversation_id', $group->id)->where('user_id', $parent->coordinator_id)->update(['last_read_message_id' => null, 'last_read_at' => null]);
                $results = $race([(string) $parent->id, (string) $parent->coordinator_id, 'group', 'hold', 'read', $firstId],
                    [(string) $parent->id, (string) $parent->coordinator_id, 'group', 'open', 'read', $secondId]);
                $this->assertSame($high, $results[1]);
                $this->assertSame((int) $high, DB::table('conversation_participants')->where('conversation_id', $group->id)->where('user_id', $parent->coordinator_id)->value('last_read_message_id'));
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            $ids = $parent->conversations()->pluck('id');
            DB::table('conversation_participants')->whereIn('conversation_id', $ids)->delete();
            DB::table('messages')->whereIn('conversation_id', $ids)->delete();
            DB::table('notifications')->whereIn('notifiable_id', $userIds)->where('notifiable_type', User::class)->delete();
            DB::table('conversations')->whereIn('id', $ids)->delete();
            $parent->delete();
            DB::table('student_profiles')->whereIn('user_id', $userIds)->delete();
            DB::table('company_supervisor_profiles')->whereIn('user_id', $userIds)->delete();
            DB::table('academic_coordinator_profiles')->whereIn('user_id', $userIds)->delete();
            User::whereIn('id', $userIds)->delete();
            Company::whereKey($companyId)->delete();
            Role::whereNotIn('id', $existingRoleIds)->delete();
            DB::beginTransaction();
        }
    }
}
