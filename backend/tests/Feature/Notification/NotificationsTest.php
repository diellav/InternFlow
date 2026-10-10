<?php

namespace Tests\Feature\Notification;

use App\Models\Role;
use App\Models\User;
use App\Modules\Notification\Services\NotificationPayload;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'UTC'));
    }

    public static function roles(): array
    {
        return array_map(fn ($role) => [$role], ['STUDENT', 'COMPANY_SUPERVISOR', 'ACADEMIC_COORDINATOR', 'ADMIN']);
    }

    public function test_guest_requests_require_authentication(): void
    {
        $id = (string) Str::uuid();
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->getJson('/api/notifications/unread-count')->assertUnauthorized();
        $this->patchJson('/api/notifications/'.$id.'/read')->assertUnauthorized();
        $this->patchJson('/api/notifications/read-all')->assertUnauthorized();
    }

    #[DataProvider('roles')]
    public function test_each_role_has_only_its_own_notifications_and_cannot_bypass_ownership(string $role): void
    {
        $actor = $this->user($role);
        $owned = $this->notification($actor);
        $foreign = [];
        foreach (['STUDENT', 'COMPANY_SUPERVISOR', 'ACADEMIC_COORDINATOR', 'ADMIN'] as $otherRole) {
            $foreign[] = $this->notification($this->user($otherRole));
        }
        $morph = DatabaseNotification::create(['id' => (string) Str::uuid(), 'type' => 'fixture', 'notifiable_id' => $actor->id,
            'notifiable_type' => 'App\\Models\\Company', 'data' => $this->payload()]);
        $this->actingAs($actor)->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $owned->id);
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertExactJson(['data' => ['unread_count' => 1]]);
        foreach ([...$foreign, $morph] as $notification) {
            $this->patchJson('/api/notifications/'.$notification->id.'/read')->assertNotFound();
            $this->patchJson('/api/notifications/'.$notification->id.'/read', ['notifiable_id' => $actor->id])->assertNotFound();
            $this->assertNull($notification->fresh()->read_at);
        }
        $this->patchJson('/api/notifications/'.$owned->id.'/read')->assertOk()->assertJsonPath('data.id', $owned->id);
        $this->patchJson('/api/notifications/read-all')->assertOk()->assertExactJson(['data' => ['updated_count' => 0]]);
        foreach ($foreign as $notification) {
            $this->assertNull($notification->fresh()->read_at);
        }
    }

    #[DataProvider('roles')]
    public function test_inactive_accounts_cannot_access_any_endpoint(string $role): void
    {
        $actor = $this->user($role);
        $notification = $this->notification($actor);
        $actor->update(['is_active' => false]);
        $this->actingAs($actor)->getJson('/api/notifications')->assertForbidden();
        $this->getJson('/api/notifications/unread-count')->assertForbidden();
        $this->patchJson('/api/notifications/'.$notification->id.'/read')->assertForbidden();
        $this->patchJson('/api/notifications/read-all')->assertForbidden();
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_pagination_has_newest_first_and_stable_uuid_tie_order(): void
    {
        $user = $this->user();
        $old = $this->notification($user, ['created_at' => now()->subDay()]);
        $low = $this->notification($user, ['id' => '00000000-0000-4000-8000-000000000001']);
        $high = $this->notification($user, ['id' => '00000000-0000-4000-8000-000000000002']);
        $new = $this->notification($user, ['created_at' => now()->addMinute()]);
        $this->notification($this->user(), ['created_at' => now()->addHour()]);
        $this->actingAs($user)->getJson('/api/notifications?per_page=2')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 4)->assertJsonPath('meta.per_page', 2)->assertJsonPath('data.0.id', $new->id)->assertJsonPath('data.1.id', $high->id);
        $this->getJson('/api/notifications?per_page=2&page=2')->assertOk()->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.0.id', $low->id)->assertJsonPath('data.1.id', $old->id);
        $this->getJson('/api/notifications?per_page=2&page=3')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_filters_and_count_are_recipient_scoped_and_empty_results_are_safe(): void
    {
        $user = $this->user();
        $unread = $this->notification($user);
        $read = $this->notification($user, ['read_at' => now()->subDay()]);
        $this->notification($this->user());
        $this->actingAs($user)->getJson('/api/notifications?status=all')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/notifications?status=unread')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $unread->id);
        $this->getJson('/api/notifications?status=read')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $read->id);
        $this->getJson('/api/notifications/unread-count')->assertExactJson(['data' => ['unread_count' => 1]]);
        $this->actingAs($this->user())->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/notifications/unread-count')->assertExactJson(['data' => ['unread_count' => 0]]);
        $this->patchJson('/api/notifications/read-all')->assertExactJson(['data' => ['updated_count' => 0]]);
    }

    public function test_mark_read_preserves_recipient_payload_and_first_timestamp(): void
    {
        $user = $this->user();
        $notification = $this->notification($user);
        $before = $notification->getAttributes();
        $expected = now()->getTimestamp();
        $this->actingAs($user)->patchJson('/api/notifications/'.$notification->id.'/read')->assertOk()
            ->assertJsonPath('data.payload.title', 'Internship update')->assertJsonMissingPath('data.notifiable_id')->assertJsonMissingPath('data.type');
        $after = $notification->fresh()->getAttributes();
        $this->assertSame($expected, $notification->fresh()->read_at->getTimestamp());
        foreach (['id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'created_at'] as $field) {
            $this->assertSame($before[$field], $after[$field]);
        }
        $this->travel(2)->hours();
        $this->patchJson('/api/notifications/'.$notification->id.'/read')->assertOk();
        $this->assertSame($after, $notification->fresh()->getAttributes());
        $this->getJson('/api/notifications/unread-count')->assertExactJson(['data' => ['unread_count' => 0]]);
    }

    public function test_read_all_is_bulk_idempotent_and_preserves_already_read_and_foreign_records(): void
    {
        $user = $this->user();
        $first = $this->notification($user);
        $second = $this->notification($user);
        $read = $this->notification($user, ['read_at' => now()->subDay()]);
        $foreign = $this->notification($this->user());
        $beforeRead = $read->getAttributes();
        $beforeForeign = $foreign->getAttributes();
        $beforeFirst = $first->data;
        DB::enableQueryLog();
        $this->actingAs($user)->patchJson('/api/notifications/read-all')->assertExactJson(['data' => ['updated_count' => 2]]);
        $updates = array_filter(DB::getQueryLog(), fn ($query) => str_starts_with(strtolower($query['query']), 'update "notifications"'));
        DB::disableQueryLog();
        $this->assertCount(1, $updates);
        $this->assertSame(now()->getTimestamp(), $first->fresh()->read_at->getTimestamp());
        $this->assertSame($first->fresh()->read_at->getTimestamp(), $second->fresh()->read_at->getTimestamp());
        $this->assertSame($beforeFirst, $first->fresh()->data);
        $this->assertSame($beforeRead, $read->fresh()->getAttributes());
        $this->assertSame($beforeForeign, $foreign->fresh()->getAttributes());
        $after = $first->fresh()->getAttributes();
        $this->travel(1)->hours();
        $this->patchJson('/api/notifications/read-all')->assertExactJson(['data' => ['updated_count' => 0]]);
        $this->assertSame($after, $first->fresh()->getAttributes());
    }

    public static function invalidFilters(): array
    {
        return array_map(fn ($query) => [$query], ['status=other', 'status=', 'status[]=all', 'page=0', 'page=-1', 'page=1.5', 'per_page=0', 'per_page=101', 'per_page=abc', 'sort=notifiable_id', 'notifiable_id=1']);
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_or_arbitrary_filters_are_rejected(string $query): void
    {
        $this->actingAs($this->user())->getJson('/api/notifications?'.$query)->assertUnprocessable();
    }

    public function test_invalid_ids_and_unavailable_mutation_routes_are_safe(): void
    {
        $user = $this->user();
        $notification = $this->notification($user);
        $before = $notification->getAttributes();
        $this->actingAs($user);
        foreach (['not-a-uuid', '123', '00000000-0000-4000-8000-000000000099'] as $id) {
            $this->patchJson('/api/notifications/'.$id.'/read')->assertNotFound();
        }
        foreach (['notifiable_id', 'notifiable_type', 'id', 'data', 'read_at', 'type'] as $field) {
            $this->patchJson('/api/notifications/'.$notification->id.'/read', [$field => 'forged'])->assertUnprocessable();
            $this->patchJson('/api/notifications/read-all', [$field => 'forged'])->assertUnprocessable();
        }
        $this->postJson('/api/notifications', ['data' => $this->payload()])->assertStatus(405);
        $this->patchJson('/api/notifications/'.$notification->id, ['data' => ['title' => 'forged']])->assertNotFound();
        $this->deleteJson('/api/notifications/'.$notification->id)->assertNotFound();
        $this->assertSame($before, $notification->fresh()->getAttributes());
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_payload_contract_and_safe_resource_do_not_expose_unknown_or_unsafe_fields(): void
    {
        $payload = $this->payload();
        $this->assertSame($payload, NotificationPayload::make($payload));
        $this->assertSame(1, NotificationPayload::make([...$payload, 'related_id' => '1'])['related_id']);
        $this->assertNull(NotificationPayload::make([...$payload, 'action_url' => null])['action_url']);
        $user = $this->user();
        $notification = $this->notification($user, ['data' => [...$payload, 'title' => '<script>bad</script>Update', 'message' => '<b>Safe text</b>',
            'action_url' => 'javascript:alert(1)', 'related_type' => 'App\\Models\\User', 'password' => 'secret', 'token' => 'secret', 'storage_path' => 'private/file.bin', 'evaluation_comments' => 'private assessment']]);
        $this->actingAs($user)->getJson('/api/notifications')->assertOk()->assertJsonPath('data.0.payload.title', 'badUpdate')
            ->assertJsonPath('data.0.payload.message', 'Safe text')->assertJsonPath('data.0.payload.action_url', null)->assertJsonPath('data.0.payload.related_type', null)
            ->assertJsonMissingPath('data.0.payload.password')->assertJsonMissingPath('data.0.payload.token')->assertJsonMissingPath('data.0.payload.storage_path')
            ->assertJsonMissingPath('data.0.payload.evaluation_comments')->assertJsonMissingPath('data.0.notifiable_type');
        $this->assertSame('javascript:alert(1)', $notification->fresh()->data['action_url']);
    }

    public static function unsafeUrls(): array
    {
        return array_map(fn ($url) => [$url], ['https://example.com', '//example.com', 'javascript:alert(1)', 'data:text/html,bad', '/storage/file.pdf', '/api/task-submission-files/1/download', '/student/../storage/file', '/student/internships/1?redirect=evil', '/student/internships/1#evil', '/student/internships/%31', '/student\\internships\\1', '/student/internships/1%0a']);
    }

    #[DataProvider('unsafeUrls')]
    public function test_backend_payload_contract_rejects_unsafe_urls(string $url): void
    {
        $this->expectException(ValidationException::class);
        NotificationPayload::make([...$this->payload(), 'action_url' => $url]);
    }

    public static function invalidPayloads(): array
    {
        return array_map(fn ($data) => [$data], [['title' => '<b>HTML</b>'], ['message' => "bad\x00text"], ['category' => 'bad category'], ['related_id' => true], ['related_id' => -1], ['related_type' => 'attachment'], ['password' => 'secret'], ['evaluation_comments' => 'private']]);
    }

    #[DataProvider('invalidPayloads')]
    public function test_backend_payload_contract_rejects_invalid_or_extra_data(array $data): void
    {
        $this->expectException(ValidationException::class);
        NotificationPayload::make([...$this->payload(), ...$data]);
    }

    public function test_malformed_legacy_payloads_have_a_safe_predictable_shape(): void
    {
        $user = $this->user();
        $this->notification($user, ['data' => ['title' => [], 'message' => ['password' => 'secret'], 'category' => [], 'action_url' => [], 'related_type' => [], 'related_id' => []]]);
        $this->actingAs($user)->getJson('/api/notifications')->assertOk()->assertJsonPath('data.0.payload', [
            'title' => '', 'message' => '', 'category' => null, 'action_url' => null, 'related_type' => null, 'related_id' => null,
        ]);
    }

    private function user(string $role = 'STUDENT'): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->sole()->id, 'is_active' => true]);
    }

    private function notification(User $user, array $attributes = []): DatabaseNotification
    {
        return $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'fixture', 'data' => $this->payload(), ...$attributes])->fresh();
    }

    private function payload(): array
    {
        return ['title' => 'Internship update', 'message' => 'An internship update is available.', 'category' => 'internship_update',
            'action_url' => '/student/internships/1', 'related_type' => 'internship', 'related_id' => 1];
    }
}
