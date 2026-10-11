<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Modules\Audit\Services\AuditLogReadService;
use App\Modules\User\Services\UserActivationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminAuditLogsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'internflow_testing') {
            throw new RuntimeException('Audit read tests require internflow_testing.');
        }
        foreach (['ADMIN', 'STUDENT', 'ACADEMIC_COORDINATOR', 'COMPANY_SUPERVISOR'] as $role) {
            Role::firstOrCreate(['name' => $role], ['description' => $role]);
        }
        $this->admin = $this->actor('ADMIN');
    }

    private function actor(string $role): User
    {
        return User::create(['first_name' => 'AuditFixture', 'last_name' => 'Reader', 'email' => Str::uuid().'@example.test',
            'password' => 'NeverExpose123', 'role_id' => Role::where('name', $role)->value('id'), 'is_active' => true]);
    }

    private function log(array $values = []): AuditLog
    {
        $log = AuditLog::forceCreate([...['user_id' => $this->admin->id, 'action' => 'internship.review_started', 'entity_type' => 'internship',
            'entity_id' => 700001, 'created_at' => '2026-10-10T12:00:00Z', 'old_values' => ['status' => 'SUBMITTED'], 'new_values' => ['status' => 'UNDER_REVIEW']], ...$values]);
        DB::table('audit_logs')->where('id', $log->id)->update(['created_at' => $values['created_at'] ?? '2026-10-10T12:00:00Z']);

        return $log->refresh();
    }

    private function getLogs(array $filters = [])
    {
        return $this->getJson('/api/admin/audit-logs?'.http_build_query($filters));
    }

    public function test_admin_can_read_safe_logs_without_generating_audit_records(): void
    {
        $log = $this->log();
        $before = AuditLog::orderBy('id')->get()->toArray();
        $this->actingAs($this->admin);
        $this->getLogs(['search' => $this->admin->email])->assertOk()->assertJsonPath('data.0.id', $log->id)
            ->assertJsonPath('data.0.actor.role', 'ADMIN')->assertJsonPath('data.0.actor.email', $this->admin->email)
            ->assertJsonPath('data.0.details.before.status', 'SUBMITTED')->assertJsonPath('data.0.details.after.status', 'UNDER_REVIEW')
            ->assertJsonMissingPath('data.0.old_values')->assertJsonMissingPath('data.0.new_values')
            ->assertJsonMissingPath('data.0.actor.password')->assertJsonMissingPath('data.0.actor.remember_token');
        $this->assertSame($before, AuditLog::orderBy('id')->get()->toArray());
        $this->postJson('/api/admin/audit-logs', [])->assertMethodNotAllowed();
    }

    public function test_successful_account_mutation_is_audited_once_and_returned_by_history(): void
    {
        $target = $this->actor('STUDENT');
        $before = AuditLog::count();
        $started = now();
        app(UserActivationService::class)->update($this->admin, $target, false);
        $this->assertSame($before + 1, AuditLog::count());
        $log = AuditLog::where('user_id', $this->admin->id)->sole();
        $this->assertSame('user.deactivated', $log->action);
        $this->assertSame('user', $log->entity_type);
        $this->assertSame($target->id, $log->entity_id);
        $writtenAt = DB::table('audit_logs')->where('id', $log->id)->value(DB::raw("to_char(created_at, 'YYYY-MM-DD HH24:MI:SS')"));
        $this->assertGreaterThanOrEqual($started->format('Y-m-d H:i:s'), $writtenAt);
        $this->assertLessThanOrEqual(now()->format('Y-m-d H:i:s'), $writtenAt);
        $this->actingAs($this->admin);
        $this->getLogs(['search' => $this->admin->email])->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $log->id)
            ->assertJsonPath('data.0.actor.id', $this->admin->id)
            ->assertJsonPath('data.0.action', 'user.deactivated')
            ->assertJsonPath('data.0.entity_type', 'user')->assertJsonPath('data.0.entity_id', $target->id)
            ->assertJsonPath('data.0.created_at', $log->created_at->toIso8601String())
            ->assertJsonPath('data.0.details.before.is_active', true)
            ->assertJsonPath('data.0.details.after.is_active', false)
            ->assertJsonMissingPath('data.0.old_values')->assertJsonMissingPath('data.0.new_values');
        $this->assertSame($before + 1, AuditLog::count());
    }

    public static function otherRoles(): array
    {
        return [['STUDENT'], ['COMPANY_SUPERVISOR'], ['ACADEMIC_COORDINATOR']];
    }

    #[DataProvider('otherRoles')]
    public function test_non_admins_cannot_read_logs(string $role): void
    {
        $this->actingAs($this->actor($role));
        $this->getLogs()->assertForbidden();
    }

    public function test_guests_and_inactive_admins_are_denied(): void
    {
        $this->getLogs()->assertUnauthorized();
        $this->admin->update(['is_active' => false]);
        $this->actingAs($this->admin);
        $this->getLogs()->assertForbidden();
    }

    public function test_pagination_defaults_and_deterministic_newest_first_order(): void
    {
        $ids = [];
        for ($index = 0; $index < 17; $index++) {
            $ids[] = $this->log()->id;
        }
        $this->actingAs($this->admin);
        $filters = ['search' => $this->admin->email];
        $this->getLogs($filters)->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 17)->assertJsonPath('data.0.id', $ids[16])->assertJsonPath('data.14.id', $ids[2]);
        $this->getLogs([...$filters, 'page' => 2])->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $ids[1]);
        $newer = $this->log(['created_at' => '2026-10-11T00:00:00Z']);
        $this->getLogs($filters)->assertJsonPath('data.0.id', $newer->id);
    }

    public function test_search_action_entity_and_inclusive_dates_are_applied_server_side(): void
    {
        $first = $this->log(['created_at' => '2026-10-10T00:00:00Z']);
        $last = $this->log(['created_at' => '2026-10-10T23:59:59Z']);
        $this->log(['created_at' => '2026-10-11T00:00:00Z', 'action' => 'task.created', 'entity_type' => 'task']);
        $this->actingAs($this->admin);
        config(['app.timezone' => 'UTC']);
        $response = $this->getLogs(['search' => $this->admin->email, 'action' => 'internship.review_started', 'entity_type' => 'internship', 'date_from' => '2026-10-10', 'date_to' => '2026-10-10'])->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$last->id, $first->id], array_column($response->json('data'), 'id'));
        foreach (['AuditFixture Reader', 'internship.review_started', 'internship', '700001'] as $search) {
            $this->getLogs(['search' => $search])->assertOk();
        }
        $this->getLogs(['search' => '%'])->assertJsonCount(0, 'data');
        $this->getLogs(['search' => $this->admin->email, 'entity_type' => 'task'])->assertJsonCount(1, 'data');
        $this->getLogs(['search' => $this->admin->email, 'date_to' => '2026-10-09'])->assertJsonCount(0, 'data');
    }

    public function test_filters_and_pagination_are_validated(): void
    {
        $this->actingAs($this->admin);
        foreach ([['page' => 0], ['per_page' => 101], ['search' => str_repeat('x', 256)], ['search' => ['bad']], ['action' => 'bad'], ['entity_type' => 'bad'], ['date_from' => 'not-date'], ['date_to' => '2026-02-30'], ['date_from' => '2026-10-11', 'date_to' => '2026-10-10']] as $filters) {
            $this->getLogs($filters)->assertUnprocessable();
        }
    }

    public function test_date_filters_use_application_timezone_with_exclusive_next_day_boundary(): void
    {
        config(['app.timezone' => 'Europe/Warsaw']);
        $first = $this->log(['created_at' => '2026-10-09T22:00:00Z']);
        $last = $this->log(['created_at' => '2026-10-10T21:59:59Z']);
        $this->log(['created_at' => '2026-10-10T22:00:00Z']);
        $this->actingAs($this->admin);
        $response = $this->getLogs(['search' => $this->admin->email, 'date_from' => '2026-10-10', 'date_to' => '2026-10-10'])->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$last->id, $first->id], array_column($response->json('data'), 'id'));
    }

    public function test_legacy_confidential_json_and_invalid_allowlisted_values_never_escape(): void
    {
        $this->log(['old_values' => ['status' => 'SUBMITTED', 'password' => 'CONFIDENTIAL'],
            'new_values' => ['status' => 'CONFIDENTIAL', 'comment' => 'CONFIDENTIAL', 'hours' => ['CONFIDENTIAL'], 'coordinator_id' => 'CONFIDENTIAL', 'submitted' => 'CONFIDENTIAL', 'version_no' => 'CONFIDENTIAL', 'ratings' => ['CONFIDENTIAL'], 'storage_path' => 'CONFIDENTIAL']]);
        $this->log(['action' => 'internflow.demo.seed.v1', 'entity_type' => 'demo_dataset', 'new_values' => ['users' => [1, 2], 'password' => 'CONFIDENTIAL']]);
        $this->log(['action' => 'CONFIDENTIAL', 'entity_type' => 'CONFIDENTIAL', 'new_values' => ['status' => 'APPROVED']]);
        $this->actingAs($this->admin);
        $response = $this->getLogs(['search' => $this->admin->email])->assertOk()->assertJsonPath('data.0.action', 'unknown')
            ->assertJsonPath('data.0.entity_type', 'unknown')->assertJsonPath('data.0.details.after', [])
            ->assertJsonPath('data.1.details.after', [])->assertJsonPath('data.2.details.after', []);
        $this->assertStringNotContainsString('CONFIDENTIAL', $response->getContent());
    }

    public function test_missing_actor_is_null_and_eager_loading_avoids_n_plus_one(): void
    {
        $log = $this->log(['user_id' => null]);
        $this->actingAs($this->admin);
        $this->getLogs(['search' => '700001'])->assertOk()->assertJsonPath('data.0.actor', null);
        $this->assertNull($log->fresh()->user_id);
        for ($i = 0; $i < 16; $i++) {
            $this->log(['user_id' => $this->actor('STUDENT')->id]);
        }
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            app(AuditLogReadService::class)->paginate(['per_page' => 1]);
            $single = count(DB::getQueryLog());
            DB::flushQueryLog();
            app(AuditLogReadService::class)->paginate(['per_page' => 100]);
            $this->assertLessThanOrEqual($single + 2, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
        }
    }
}
