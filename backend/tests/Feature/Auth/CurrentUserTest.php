<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_unauthenticated_me_returns_unauthorized(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_authenticated_student_receives_student_profile(): void
    {
        $user = $this->user(UserRole::STUDENT);
        $user->studentProfile()->create([
            'student_number' => 'STU-ME',
            'study_program' => 'Information Systems',
            'study_year' => 2,
        ]);

        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.profile.student_number', 'STU-ME');
    }

    public function test_authenticated_supervisor_receives_safe_company_and_verification_data(): void
    {
        $user = $this->user(UserRole::COMPANY_SUPERVISOR);
        $company = Company::query()->create([
            'name' => 'Safe Company',
            'verification_status' => VerificationStatus::APPROVED,
            'verification_note' => 'Private company note',
        ]);
        $user->companySupervisorProfile()->create([
            'company_id' => $company->id,
            'job_title' => 'Supervisor',
            'verification_status' => VerificationStatus::PENDING,
            'review_comment' => 'Private review note',
        ]);

        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.profile.job_title', 'Supervisor')
            ->assertJsonPath('data.profile.verification_status', VerificationStatus::PENDING->value)
            ->assertJsonPath('data.profile.company.name', 'Safe Company')
            ->assertJsonMissingPath('data.profile.review_comment')
            ->assertJsonMissingPath('data.profile.company.verification_note');
    }

    public function test_authenticated_coordinator_receives_coordinator_profile(): void
    {
        $user = $this->user(UserRole::ACADEMIC_COORDINATOR);
        $user->academicCoordinatorProfile()->create(['academic_unit' => 'Engineering']);

        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.profile.academic_unit', 'Engineering');
    }

    public function test_authenticated_admin_has_no_fabricated_profile(): void
    {
        $user = $this->user(UserRole::ADMIN);

        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', UserRole::ADMIN->value)
            ->assertJsonMissingPath('data.profile');
    }

    public function test_user_disabled_after_authentication_cannot_access_me(): void
    {
        $user = $this->user(UserRole::STUDENT);
        $this->actingAs($user);
        $user->update(['is_active' => false]);

        $this->getJson('/api/auth/me')->assertForbidden();
    }

    public function test_me_never_exposes_password_or_administrative_notes(): void
    {
        $user = $this->user(UserRole::ADMIN);

        $response = $this->actingAs($user)->getJson('/api/auth/me')->assertOk();

        $this->assertStringNotContainsString($user->password, $response->getContent());
        $response
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.role_id');
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role->value)->firstOrFail()->id,
            'password' => 'Password123',
            'is_active' => true,
        ]);
    }
}
