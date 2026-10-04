<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->withHeaders($this->spaHeaders());
    }

    public function test_active_student_can_login_and_receives_safe_profile(): void
    {
        $user = $this->user(UserRole::STUDENT);
        $user->studentProfile()->create([
            'student_number' => 'STU-LOGIN',
            'study_program' => 'Computer Science',
            'study_year' => 3,
        ]);

        $this->postJson('/api/auth/login', $this->credentials($user))
            ->assertOk()
            ->assertJsonPath('data.role', UserRole::STUDENT->value)
            ->assertJsonPath('data.profile.student_number', 'STU-LOGIN')
            ->assertJsonMissingPath('data.password');

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_returns_generic_failure_and_does_not_authenticate(): void
    {
        $user = $this->user(UserRole::STUDENT);

        $this->postJson('/api/auth/login', $this->credentials($user, 'wrong-password'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');

        $this->assertGuest();
    }

    public function test_unknown_email_returns_the_same_generic_failure(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');

        $this->assertGuest();
    }

    public function test_inactive_user_is_denied_without_leaving_an_authenticated_session(): void
    {
        $user = $this->user(UserRole::STUDENT, false);

        $this->postJson('/api/auth/login', $this->credentials($user))
            ->assertForbidden()
            ->assertJsonPath('message', 'Account is inactive.');

        $this->assertGuest();
    }

    public function test_pending_supervisor_can_login(): void
    {
        $user = $this->supervisor(VerificationStatus::PENDING);

        $this->postJson('/api/auth/login', $this->credentials($user))
            ->assertOk()
            ->assertJsonPath('data.profile.verification_status', VerificationStatus::PENDING->value);

        $this->assertAuthenticatedAs($user);
    }

    public function test_approved_supervisor_can_login(): void
    {
        $user = $this->supervisor(VerificationStatus::APPROVED);

        $this->postJson('/api/auth/login', $this->credentials($user))->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_rejected_supervisor_with_an_active_account_can_login(): void
    {
        $user = $this->supervisor(VerificationStatus::REJECTED);

        $this->postJson('/api/auth/login', $this->credentials($user))
            ->assertOk()
            ->assertJsonPath('data.profile.verification_status', VerificationStatus::REJECTED->value);

        $this->assertAuthenticatedAs($user);
    }

    public function test_active_admin_can_login(): void
    {
        $user = $this->user(UserRole::ADMIN);

        $this->postJson('/api/auth/login', $this->credentials($user))
            ->assertOk()
            ->assertJsonPath('data.role', UserRole::ADMIN->value)
            ->assertJsonMissingPath('data.profile');
    }

    public function test_active_coordinator_can_login(): void
    {
        $user = $this->user(UserRole::ACADEMIC_COORDINATOR);
        $user->academicCoordinatorProfile()->create(['academic_unit' => 'Computer Science']);

        $this->postJson('/api/auth/login', $this->credentials($user))
            ->assertOk()
            ->assertJsonPath('data.role', UserRole::ACADEMIC_COORDINATOR->value)
            ->assertJsonPath('data.profile.academic_unit', 'Computer Science');
    }

    public function test_successful_login_regenerates_the_session_identifier(): void
    {
        $user = $this->user(UserRole::STUDENT);
        $this->app['session']->start();
        $oldSessionId = $this->app['session']->getId();

        $this->postJson('/api/auth/login', $this->credentials($user))->assertOk();

        $this->assertNotSame($oldSessionId, $this->app['session']->getId());
    }

    public function test_login_response_never_contains_password_data(): void
    {
        $user = $this->user(UserRole::ADMIN);

        $response = $this->postJson('/api/auth/login', $this->credentials($user))->assertOk();

        $this->assertStringNotContainsString($user->password, $response->getContent());
        $response->assertJsonMissingPath('data.password');
    }

    public function test_login_rate_limit_activates_after_repeated_failures(): void
    {
        $email = 'rate-limit@example.com';
        $key = Str::transliterate($email).'|127.0.0.1';
        RateLimiter::clear($key);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong'])
            ->assertTooManyRequests();

        RateLimiter::clear($key);
    }

    private function user(UserRole $role, bool $active = true): User
    {
        $roleModel = Role::query()->where('name', $role->value)->firstOrFail();

        return User::factory()->create([
            'role_id' => $roleModel->id,
            'email' => fake()->unique()->safeEmail(),
            'password' => 'Password123',
            'is_active' => $active,
        ]);
    }

    private function supervisor(VerificationStatus $status): User
    {
        $user = $this->user(UserRole::COMPANY_SUPERVISOR);
        $company = Company::query()->create([
            'name' => fake()->unique()->company(),
            'verification_status' => VerificationStatus::APPROVED,
        ]);
        $user->companySupervisorProfile()->create([
            'company_id' => $company->id,
            'job_title' => 'Manager',
            'verification_status' => $status,
        ]);

        return $user;
    }

    /** @return array{email: string, password: string} */
    private function credentials(User $user, string $password = 'Password123'): array
    {
        return ['email' => $user->email, 'password' => $password];
    }

    /** @return array<string, string> */
    private function spaHeaders(): array
    {
        return [
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ];
    }
}
