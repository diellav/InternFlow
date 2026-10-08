<?php

namespace Tests\Feature\Company;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Modules\Company\Services\CompanyVerificationService;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use App\Shared\Http\Middleware\EnsureSupervisorIsApproved;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CompanyVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_approval_records_admin_metadata_without_approving_supervisors(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $company = Company::query()->create(['name' => 'Pending', 'verification_note' => 'Old note']);
        $supervisor = $this->user(UserRole::COMPANY_SUPERVISOR);
        $profile = $supervisor->companySupervisorProfile()->create(['company_id' => $company->id]);
        $beforeUser = $supervisor->fresh()->getAttributes();
        $beforeProfile = $profile->fresh()->getAttributes();

        $this->actingAs($admin)->patchJson($this->endpoint($company), ['decision' => 'APPROVED'])->assertOk()
            ->assertJsonPath('data.verification_status', 'APPROVED')->assertJsonPath('data.verified_by', $admin->id)
            ->assertJsonPath('data.verification_note', null)->assertJsonPath('data.supervisors.0.verification_status', 'PENDING')
            ->assertJsonMissingPath('data.supervisors.0.password');
        $this->assertNotNull($company->fresh()->verified_at);
        $this->assertTrue($company->fresh()->is_active);
        $this->assertSame($beforeUser, $supervisor->fresh()->getAttributes());
        $this->assertSame($beforeProfile, $profile->fresh()->getAttributes());
        $request = Request::create('/supervisor-operation');
        $request->setUserResolver(fn () => $supervisor->fresh());
        $response = app(EnsureSupervisorIsApproved::class)->handle($request, fn () => response()->json(['ok' => true]));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_rejection_records_trimmed_reason_without_deactivating_accounts_or_company(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $company = Company::query()->create(['name' => 'Pending']);
        $supervisor = $this->user(UserRole::COMPANY_SUPERVISOR);
        $profile = $supervisor->companySupervisorProfile()->create([
            'company_id' => $company->id, 'verification_status' => VerificationStatus::APPROVED,
        ]);
        $beforeUser = $supervisor->fresh()->getAttributes();
        $beforeProfile = $profile->fresh()->getAttributes();
        $this->actingAs($admin)->patchJson($this->endpoint($company), ['decision' => 'REJECTED', 'reason' => '  Cannot verify.  '])
            ->assertOk()->assertJsonPath('data.verification_status', 'REJECTED')
            ->assertJsonPath('data.verification_note', 'Cannot verify.')->assertJsonPath('data.verified_by', $admin->id);
        $this->assertNotNull($company->fresh()->verified_at);
        $this->assertTrue($company->fresh()->is_active);
        $this->assertSame($beforeUser, $supervisor->fresh()->getAttributes());
        $this->assertSame($beforeProfile, $profile->fresh()->getAttributes());
    }

    public function test_invalid_requests_do_not_change_company(): void
    {
        $this->actingAs($this->user(UserRole::ADMIN));
        $company = Company::query()->create(['name' => 'Pending']);
        $before = $company->fresh()->getAttributes();
        foreach ([
            [['decision' => 'REJECTED'], 'reason'],
            [['decision' => 'REJECTED', 'reason' => '   '], 'reason'],
            [['decision' => 'REJECTED', 'reason' => str_repeat('x', 2001)], 'reason'],
            [['decision' => 'PENDING'], 'decision'],
            [['decision' => 'approved'], 'decision'],
            [['decision' => 'APPROVED', 'verified_by' => 42], 'verified_by'],
            [['decision' => 'APPROVED', 'is_active' => false], 'is_active'],
            [['decision' => 'APPROVED', 'reason' => 'Unrelated reason'], 'reason'],
        ] as [$payload, $field]) {
            $this->patchJson($this->endpoint($company), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertSame($before, $company->fresh()->getAttributes());
        }
    }

    public function test_guests_and_inactive_admins_are_denied_and_missing_company_is_not_found(): void
    {
        $company = Company::query()->create(['name' => 'Pending']);
        $this->patchJson($this->endpoint($company), ['decision' => 'APPROVED'])->assertUnauthorized();
        $inactive = $this->user(UserRole::ADMIN);
        $inactive->update(['is_active' => false]);
        $this->actingAs($inactive)->patchJson($this->endpoint($company), ['decision' => 'APPROVED'])->assertForbidden();
        $this->actingAs($this->user(UserRole::ADMIN))->patchJson('/api/admin/companies/999999/verification', ['decision' => 'APPROVED'])->assertNotFound();
        $this->assertSame(VerificationStatus::PENDING, $company->fresh()->verification_status);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_cannot_review(UserRole $role): void
    {
        $company = Company::query()->create(['name' => 'Pending']);
        $this->actingAs($this->user($role))->patchJson($this->endpoint($company), ['decision' => 'APPROVED'])->assertForbidden();
        $this->assertSame(VerificationStatus::PENDING, $company->fresh()->verification_status);
    }

    public static function nonAdminRoles(): array
    {
        return [[UserRole::STUDENT], [UserRole::COMPANY_SUPERVISOR], [UserRole::ACADEMIC_COORDINATOR]];
    }

    public function test_all_further_decisions_conflict_and_preserve_original_metadata(): void
    {
        $this->actingAs($this->user(UserRole::ADMIN));
        foreach (['APPROVED', 'REJECTED'] as $initial) {
            $company = Company::query()->create(['name' => 'Pending']);
            $this->patchJson($this->endpoint($company), ['decision' => $initial, ...($initial === 'REJECTED' ? ['reason' => 'First decision'] : [])])->assertOk();
            $before = $company->fresh()->getAttributes();
            foreach (['APPROVED', 'REJECTED'] as $next) {
                $this->patchJson($this->endpoint($company), ['decision' => $next, ...($next === 'REJECTED' ? ['reason' => 'New decision'] : [])])->assertConflict();
                $this->assertSame($before, $company->fresh()->getAttributes());
            }
        }
    }

    public function test_stale_review_snapshots_recheck_state_under_a_row_lock(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $company = Company::query()->create(['name' => 'Pending']);
        $stale = $company->fresh();
        $service = app(CompanyVerificationService::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $service->verify($company, $admin, VerificationStatus::APPROVED, null);
            $this->assertStringContainsString('for update', strtolower(implode(' ', array_column(DB::getQueryLog(), 'query'))));
        } finally {
            DB::disableQueryLog();
        }
        try {
            $service->verify($stale, $admin, VerificationStatus::REJECTED, 'Stale decision');
            $this->fail('A stale review must not overwrite the committed decision.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(VerificationStatus::APPROVED, $company->fresh()->verification_status);
        $this->assertNull($company->fresh()->verification_note);
    }

    private function endpoint(Company $company): string
    {
        return '/api/admin/companies/'.$company->id.'/verification';
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('name', $role->value)->sole()->id]);
    }
}
