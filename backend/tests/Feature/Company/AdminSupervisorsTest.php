<?php

namespace Tests\Feature\Company;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Modules\Company\Services\SupervisorVerificationService;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use App\Shared\Http\Middleware\EnsureAccountIsActive;
use App\Shared\Http\Middleware\EnsureSupervisorIsApproved;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminSupervisorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_lists_and_filters_only_supervisors_with_safe_paginated_data(): void
    {
        $this->actingAs($this->user(UserRole::ADMIN)->load('role'));
        $company = $this->company();
        $match = $this->supervisor($company, ['first_name' => 'Ada', 'last_name' => 'Mentor']);
        $other = $this->supervisor($this->company(), ['first_name' => 'Ada']);
        $other->companySupervisorProfile->update(['verification_status' => VerificationStatus::REJECTED]);
        $this->user(UserRole::STUDENT);
        $this->getJson('/api/admin/supervisors')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 15)->assertJsonPath('data.0.id', $other->id)
            ->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.0.profile.review_comment');
        $query = http_build_query(['search' => 'ada mentor', 'verification_status' => 'PENDING', 'company_id' => $company->id]);
        $this->getJson('/api/admin/supervisors?'.$query)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
        $this->getJson('/api/admin/supervisors?search='.urlencode($match->email))->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/supervisors?per_page=1&page=2')->assertJsonPath('data.0.id', $match->id)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/admin/supervisors?verification_status=unknown&per_page=101')->assertUnprocessable();
    }

    public function test_details_include_review_metadata_and_reject_missing_or_other_role_targets(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $this->actingAs($admin);
        $company = $this->company(['verification_status' => VerificationStatus::REJECTED, 'verification_note' => 'Unrelated company note']);
        $user = $this->supervisor($company);
        $user->companySupervisorProfile->update(['reviewed_by' => $admin->id, 'review_comment' => 'Supervisor note']);
        $response = $this->getJson($this->endpoint($user))->assertOk()->assertJsonPath('data.profile.company.verification_status', 'REJECTED')
            ->assertJsonPath('data.profile.review_comment', 'Supervisor note')->assertJsonPath('data.profile.reviewed_by', $admin->id)
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');
        $this->assertStringNotContainsString('Unrelated company note', $response->getContent());
        $this->getJson('/api/admin/supervisors/999999')->assertNotFound();
        $student = $this->user(UserRole::STUDENT);
        $this->getJson($this->endpoint($student))->assertNotFound();
        $this->patchJson($this->endpoint($student).'/verification', ['decision' => 'APPROVED'])->assertNotFound();
        $missingProfile = $this->user(UserRole::COMPANY_SUPERVISOR);
        $this->getJson($this->endpoint($missingProfile))->assertJsonPath('data.profile', null);
        $this->patchJson($this->endpoint($missingProfile).'/verification', ['decision' => 'APPROVED'])->assertConflict();
    }

    public function test_guests_non_admins_and_inactive_admins_cannot_access_endpoints(): void
    {
        $user = $this->supervisor($this->company());
        $this->getJson('/api/admin/supervisors')->assertUnauthorized();
        $this->getJson($this->endpoint($user))->assertUnauthorized();
        $this->patchJson($this->endpoint($user).'/verification', ['decision' => 'APPROVED'])->assertUnauthorized();
        foreach ([UserRole::STUDENT, UserRole::COMPANY_SUPERVISOR, UserRole::ACADEMIC_COORDINATOR, UserRole::ADMIN] as $role) {
            $actor = $this->user($role);
            if ($role === UserRole::ADMIN) {
                $actor->update(['is_active' => false]);
            }
            $this->actingAs($actor);
            $this->getJson('/api/admin/supervisors')->assertForbidden();
            $this->getJson($this->endpoint($user))->assertForbidden();
            $this->patchJson($this->endpoint($user).'/verification', ['decision' => 'APPROVED'])->assertForbidden();
        }
    }

    public function test_approval_is_independent_of_company_status_and_preserves_account(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $this->actingAs($admin);
        foreach ([VerificationStatus::PENDING, VerificationStatus::REJECTED] as $status) {
            $company = $this->company(['verification_status' => $status]);
            $user = $this->supervisor($company);
            $beforeCompany = $company->fresh()->getAttributes();
            $beforeUser = $user->fresh()->getAttributes();
            $this->patchJson($this->endpoint($user).'/verification', ['decision' => 'APPROVED'])->assertOk()
                ->assertJsonPath('data.profile.verification_status', 'APPROVED')->assertJsonPath('data.profile.reviewed_by', $admin->id)
                ->assertJsonPath('data.profile.review_comment', null);
            $this->assertNotNull($user->companySupervisorProfile()->first()->reviewed_at);
            $this->assertSame($beforeCompany, $company->fresh()->getAttributes());
            $this->assertSame($beforeUser, $user->fresh()->getAttributes());
            $this->assertSame(403, $this->operationalStatus($user));
        }
    }

    public function test_rejection_requires_reason_persists_metadata_and_never_deactivates(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $this->actingAs($admin);
        $company = $this->company(['verification_status' => VerificationStatus::APPROVED]);
        $user = $this->supervisor($company);
        $beforeCompany = $company->fresh()->getAttributes();
        $beforeUser = $user->fresh()->getAttributes();
        foreach ([[], ['reason' => '   '], ['reason' => str_repeat('x', 2001)]] as $fields) {
            $this->patchJson($this->endpoint($user).'/verification', ['decision' => 'REJECTED', ...$fields])->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        $this->patchJson($this->endpoint($user).'/verification', ['decision' => 'APPROVED', 'is_active' => false])->assertUnprocessable();
        $this->patchJson($this->endpoint($user).'/verification', ['decision' => 'REJECTED', 'reason' => '  Cannot verify.  '])->assertOk()
            ->assertJsonPath('data.profile.review_comment', 'Cannot verify.')->assertJsonPath('data.profile.reviewed_by', $admin->id);
        $this->assertNotNull($user->companySupervisorProfile()->first()->reviewed_at);
        $this->assertSame($beforeCompany, $company->fresh()->getAttributes());
        $this->assertSame($beforeUser, $user->fresh()->getAttributes());
        $this->assertSame(403, $this->operationalStatus($user));
    }

    public function test_completed_decisions_and_stale_snapshots_cannot_be_overwritten(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $this->actingAs($admin);
        foreach (['APPROVED', 'REJECTED'] as $initial) {
            $user = $this->supervisor($this->company());
            $stale = $user->fresh();
            $this->patchJson($this->endpoint($user).'/verification', ['decision' => $initial, ...($initial === 'REJECTED' ? ['reason' => 'First decision'] : [])])->assertOk();
            $before = $user->companySupervisorProfile()->first()->getAttributes();
            foreach (['APPROVED', 'REJECTED'] as $next) {
                $this->patchJson($this->endpoint($user).'/verification', ['decision' => $next, ...($next === 'REJECTED' ? ['reason' => 'Another decision'] : [])])->assertConflict();
                $this->assertSame($before, $user->companySupervisorProfile()->first()->getAttributes());
            }
            try {
                app(SupervisorVerificationService::class)->verify($stale, $admin, VerificationStatus::APPROVED, null);
                $this->fail('A stale supervisor must not be reviewed again.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
        }
    }

    public function test_operational_access_requires_active_account_and_both_approvals(): void
    {
        $company = $this->company(['verification_status' => VerificationStatus::APPROVED]);
        $user = $this->supervisor($company);
        $this->assertSame(403, $this->operationalStatus($user));
        $user->companySupervisorProfile->update(['verification_status' => VerificationStatus::APPROVED]);
        $this->assertSame(200, $this->operationalStatus($user));
        $user->update(['is_active' => false]);
        $this->assertSame(403, $this->operationalStatus($user));
    }

    public function test_listing_eager_loading_has_constant_query_count(): void
    {
        $this->actingAs($this->user(UserRole::ADMIN)->load('role'));
        $company = $this->company();
        $this->supervisor($company);
        $small = $this->listingQueries();
        for ($i = 0; $i < 4; $i++) {
            $this->supervisor($company);
        }
        $this->assertSame($small, $this->listingQueries());
    }

    private function listingQueries(): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->getJson('/api/admin/supervisors')->assertOk();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    private function operationalStatus(User $user): int
    {
        $request = Request::create('/supervisor-operation');
        $request->setUserResolver(fn () => $user->fresh());

        return app(EnsureAccountIsActive::class)->handle($request, fn ($request) => app(EnsureSupervisorIsApproved::class)
            ->handle($request, fn () => response()->json(['ok' => true])))->getStatusCode();
    }

    private function endpoint(User $user): string
    {
        return '/api/admin/supervisors/'.$user->id;
    }

    private function company(array $attributes = []): Company
    {
        return Company::query()->create(['name' => 'Company', ...$attributes]);
    }

    private function supervisor(Company $company, array $attributes = []): User
    {
        $user = $this->user(UserRole::COMPANY_SUPERVISOR, $attributes);
        $user->companySupervisorProfile()->create(['company_id' => $company->id, 'job_title' => 'Mentor']);

        return $user;
    }

    private function user(UserRole $role, array $attributes = []): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('name', $role->value)->sole()->id, ...$attributes]);
    }
}
