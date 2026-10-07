<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ]);

        Route::get('/_test/authorization/student', fn () => response()->json(['allowed' => true]))
            ->middleware(['auth:sanctum', 'active', 'role:STUDENT']);
        Route::get('/_test/authorization/admin', fn () => response()->json(['allowed' => true]))
            ->middleware(['auth:sanctum', 'active', 'role:ADMIN']);
        Route::get('/_test/authorization/coordinator', fn () => response()->json(['allowed' => true]))
            ->middleware(['auth:sanctum', 'active', 'role:ACADEMIC_COORDINATOR']);
        Route::get('/_test/authorization/supervisor-role', fn () => response()->json(['allowed' => true]))
            ->middleware(['auth:sanctum', 'active', 'role:COMPANY_SUPERVISOR']);
        Route::get('/_test/authorization/supervisor-approved', fn () => response()->json(['allowed' => true]))
            ->middleware([
                'auth:sanctum',
                'active',
                'role:COMPANY_SUPERVISOR',
                'supervisor.approved',
            ]);
        Route::get('/_test/authorization/supervisor-approval-only', fn () => response()->json(['allowed' => true]))
            ->middleware(['auth:sanctum', 'active', 'supervisor.approved']);
    }

    public function test_unauthenticated_role_protected_request_returns_unauthorized(): void
    {
        $this->getJson('/_test/authorization/student')->assertUnauthorized();
    }

    public function test_student_is_allowed_on_student_route(): void
    {
        $this->actingAs($this->user(UserRole::STUDENT))
            ->getJson('/_test/authorization/student')
            ->assertOk();
    }

    public function test_student_is_denied_on_admin_route(): void
    {
        $this->actingAs($this->user(UserRole::STUDENT))
            ->getJson('/_test/authorization/admin')
            ->assertForbidden();
    }

    public function test_guest_is_denied_on_admin_route(): void
    {
        $this->getJson('/_test/authorization/admin')->assertUnauthorized();
    }

    #[DataProvider('nonAdminRoleProvider')]
    public function test_other_non_admin_roles_are_denied_on_admin_route(UserRole $role): void
    {
        $this->actingAs($this->user($role))
            ->getJson('/_test/authorization/admin')
            ->assertForbidden()
            ->assertJsonPath('message', 'This action is forbidden.');
    }

    public static function nonAdminRoleProvider(): array
    {
        return [
            'supervisor' => [UserRole::COMPANY_SUPERVISOR],
            'coordinator' => [UserRole::ACADEMIC_COORDINATOR],
        ];
    }

    public function test_inactive_admin_is_denied_on_admin_route(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $admin->update(['is_active' => false]);

        $this->actingAs($admin)
            ->getJson('/_test/authorization/admin')
            ->assertForbidden()
            ->assertJsonPath('message', 'Account is inactive.');
    }

    public function test_admin_is_allowed_on_admin_route(): void
    {
        $this->actingAs($this->user(UserRole::ADMIN))
            ->getJson('/_test/authorization/admin')
            ->assertOk();
    }

    public function test_coordinator_is_allowed_on_coordinator_route(): void
    {
        $this->actingAs($this->user(UserRole::ACADEMIC_COORDINATOR))
            ->getJson('/_test/authorization/coordinator')
            ->assertOk();
    }

    public function test_pending_supervisor_passes_role_level_check(): void
    {
        $supervisor = $this->supervisor(VerificationStatus::PENDING, VerificationStatus::APPROVED);

        $this->actingAs($supervisor)
            ->getJson('/_test/authorization/supervisor-role')
            ->assertOk();
    }

    public function test_approved_supervisor_with_approved_active_company_is_allowed(): void
    {
        $supervisor = $this->supervisor(VerificationStatus::APPROVED, VerificationStatus::APPROVED);

        $this->actingAs($supervisor)
            ->getJson('/_test/authorization/supervisor-approved')
            ->assertOk();
    }

    #[DataProvider('deniedSupervisorStateProvider')]
    public function test_unapproved_supervisor_or_company_state_is_denied(
        VerificationStatus $supervisorStatus,
        VerificationStatus $companyStatus,
    ): void {
        $supervisor = $this->supervisor($supervisorStatus, $companyStatus);

        $this->actingAs($supervisor)
            ->getJson('/_test/authorization/supervisor-approved')
            ->assertForbidden()
            ->assertJsonPath('message', 'This account is not authorized for supervisor operations.');
    }

    /**
     * @return array<string, array{VerificationStatus, VerificationStatus}>
     */
    public static function deniedSupervisorStateProvider(): array
    {
        return [
            'pending supervisor' => [VerificationStatus::PENDING, VerificationStatus::APPROVED],
            'rejected supervisor' => [VerificationStatus::REJECTED, VerificationStatus::APPROVED],
            'pending company' => [VerificationStatus::APPROVED, VerificationStatus::PENDING],
            'rejected company' => [VerificationStatus::APPROVED, VerificationStatus::REJECTED],
        ];
    }

    public function test_supervisor_with_inactive_company_is_denied(): void
    {
        $supervisor = $this->supervisor(
            VerificationStatus::APPROVED,
            VerificationStatus::APPROVED,
            companyIsActive: false,
        );

        $this->actingAs($supervisor)
            ->getJson('/_test/authorization/supervisor-approved')
            ->assertForbidden();
    }

    public function test_supervisor_without_profile_is_denied(): void
    {
        $this->actingAs($this->user(UserRole::COMPANY_SUPERVISOR))
            ->getJson('/_test/authorization/supervisor-approved')
            ->assertForbidden();
    }

    public function test_non_supervisor_is_denied_by_supervisor_approval_middleware_itself(): void
    {
        $this->actingAs($this->user(UserRole::STUDENT))
            ->getJson('/_test/authorization/supervisor-approval-only')
            ->assertForbidden();
    }

    public function test_unauthenticated_full_supervisor_stack_returns_unauthorized(): void
    {
        $this->getJson('/_test/authorization/supervisor-approved')->assertUnauthorized();
    }

    public function test_inactive_approved_supervisor_is_denied_by_active_middleware(): void
    {
        $supervisor = $this->supervisor(VerificationStatus::APPROVED, VerificationStatus::APPROVED);
        $supervisor->update(['is_active' => false]);

        $this->actingAs($supervisor)
            ->getJson('/_test/authorization/supervisor-approved')
            ->assertForbidden()
            ->assertJsonPath('message', 'Account is inactive.');
    }

    public function test_pending_supervisor_can_login_and_view_me_but_not_enter_operations(): void
    {
        $supervisor = $this->supervisor(VerificationStatus::PENDING, VerificationStatus::APPROVED);

        $this->postJson('/api/auth/login', [
            'email' => $supervisor->email,
            'password' => 'Password123',
        ])->assertOk();

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.profile.verification_status', VerificationStatus::PENDING->value);

        $this->getJson('/_test/authorization/supervisor-approved')->assertForbidden();
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role->value)->firstOrFail()->id,
            'password' => 'Password123',
            'is_active' => true,
        ]);
    }

    private function supervisor(
        VerificationStatus $supervisorStatus,
        VerificationStatus $companyStatus,
        bool $companyIsActive = true,
    ): User {
        $supervisor = $this->user(UserRole::COMPANY_SUPERVISOR);
        $company = Company::query()->create([
            'name' => fake()->unique()->company(),
            'is_active' => $companyIsActive,
            'verification_status' => $companyStatus,
        ]);
        $supervisor->companySupervisorProfile()->create([
            'company_id' => $company->id,
            'job_title' => 'Supervisor',
            'verification_status' => $supervisorStatus,
        ]);

        return $supervisor;
    }
}
