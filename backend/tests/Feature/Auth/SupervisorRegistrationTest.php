<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\CompanySupervisorProfile;
use App\Models\Role;
use App\Models\User;
use App\Modules\Auth\DTOs\SupervisorRegistrationDTO;
use App\Modules\Auth\Services\RegistrationService;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupervisorRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_supervisor_can_register_with_an_approved_existing_company(): void
    {
        $company = $this->company(VerificationStatus::APPROVED);

        $response = $this->postJson('/api/auth/register/supervisor', $this->existingCompanyPayload($company));

        $response
            ->assertCreated()
            ->assertJsonPath('data.role', UserRole::COMPANY_SUPERVISOR->value)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.profile.verification_status', VerificationStatus::PENDING->value)
            ->assertJsonPath('data.profile.company.id', $company->id)
            ->assertJsonPath('data.profile.company.verification_status', VerificationStatus::APPROVED->value)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.role_id')
            ->assertJsonMissingPath('data.profile.review_comment')
            ->assertJsonMissingPath('data.profile.company.verification_note');

        $user = User::query()->where('email', 'supervisor@example.com')->firstOrFail();
        $profile = CompanySupervisorProfile::query()->findOrFail($user->id);

        $this->assertTrue($user->hasRole(UserRole::COMPANY_SUPERVISOR));
        $this->assertTrue($user->is_active);
        $this->assertSame(VerificationStatus::PENDING, $profile->verification_status);
        $this->assertSame($company->id, $profile->company_id);
        $this->assertSame(VerificationStatus::APPROVED, $company->fresh()->verification_status);
    }

    public function test_supervisor_can_register_with_a_pending_existing_company(): void
    {
        $company = $this->company(VerificationStatus::PENDING);

        $this->postJson('/api/auth/register/supervisor', $this->existingCompanyPayload($company))
            ->assertCreated()
            ->assertJsonPath('data.profile.verification_status', VerificationStatus::PENDING->value)
            ->assertJsonPath('data.profile.company.verification_status', VerificationStatus::PENDING->value);

        $this->assertSame(VerificationStatus::PENDING, $company->fresh()->verification_status);
    }

    public function test_rejected_existing_company_cannot_be_selected(): void
    {
        $company = $this->company(VerificationStatus::REJECTED);

        $this->postJson('/api/auth/register/supervisor', $this->existingCompanyPayload($company))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('company_supervisor_profiles', 0);
        $this->assertSame(VerificationStatus::REJECTED, $company->fresh()->verification_status);
    }

    public function test_supervisor_can_register_with_a_new_pending_company(): void
    {
        $response = $this->postJson('/api/auth/register/supervisor', $this->newCompanyPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('data.role', UserRole::COMPANY_SUPERVISOR->value)
            ->assertJsonPath('data.profile.verification_status', VerificationStatus::PENDING->value)
            ->assertJsonPath('data.profile.company.name', 'New Company')
            ->assertJsonPath('data.profile.company.verification_status', VerificationStatus::PENDING->value);

        $company = Company::query()->where('name', 'New Company')->firstOrFail();
        $user = User::query()->where('email', 'supervisor@example.com')->firstOrFail();

        $this->assertSame(VerificationStatus::PENDING, $company->verification_status);
        $this->assertDatabaseHas('company_supervisor_profiles', [
            'user_id' => $user->id,
            'company_id' => $company->id,
            'verification_status' => VerificationStatus::PENDING->value,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_comment' => null,
        ]);
    }

    public function test_new_company_registration_rolls_back_when_user_creation_fails(): void
    {
        User::factory()->create(['email' => 'supervisor@example.com']);
        $dto = SupervisorRegistrationDTO::fromValidated($this->newCompanyPayload());

        try {
            app(RegistrationService::class)->registerSupervisor($dto);
            $this->fail('Expected duplicate email validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }

        $this->assertDatabaseMissing('companies', ['name' => 'New Company']);
        $this->assertDatabaseCount('company_supervisor_profiles', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_company_mode_must_be_valid(): void
    {
        $payload = $this->newCompanyPayload(['company_mode' => 'unknown']);

        $this->postJson('/api/auth/register/supervisor', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_mode');
    }

    public function test_existing_mode_requires_company_id(): void
    {
        $payload = $this->basePayload(['company_mode' => 'existing']);

        $this->postJson('/api/auth/register/supervisor', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');
    }

    public function test_new_mode_prohibits_company_id(): void
    {
        $company = $this->company(VerificationStatus::APPROVED);
        $payload = $this->newCompanyPayload(['company_id' => $company->id]);

        $this->postJson('/api/auth/register/supervisor', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');
    }

    public function test_existing_mode_prohibits_new_company_fields(): void
    {
        $company = $this->company(VerificationStatus::APPROVED);
        $payload = $this->existingCompanyPayload($company, ['company_name' => 'Injected Company']);

        $this->postJson('/api/auth/register/supervisor', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_name');
    }

    public function test_new_mode_requires_company_name(): void
    {
        $payload = $this->newCompanyPayload();
        unset($payload['company_name']);

        $this->postJson('/api/auth/register/supervisor', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_name');
    }

    public function test_duplicate_user_email_is_rejected_without_partial_records(): void
    {
        User::factory()->create(['email' => 'supervisor@example.com']);

        $this->postJson('/api/auth/register/supervisor', $this->newCompanyPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('company_supervisor_profiles', 0);
    }

    public function test_privileged_role_fields_are_rejected(): void
    {
        $adminRole = Role::query()->where('name', UserRole::ADMIN->value)->firstOrFail();
        $payload = $this->newCompanyPayload([
            'role' => UserRole::ADMIN->value,
            'role_id' => $adminRole->id,
        ]);

        $this->postJson('/api/auth/register/supervisor', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role', 'role_id']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_administrative_verification_fields_are_rejected(): void
    {
        $payload = $this->newCompanyPayload([
            'verification_status' => VerificationStatus::APPROVED->value,
            'reviewed_by' => 1,
            'reviewed_at' => now()->toISOString(),
            'review_comment' => 'Approved',
            'verified_by' => 1,
            'verified_at' => now()->toISOString(),
            'verification_note' => 'Approved',
            'is_active' => false,
        ]);

        $this->postJson('/api/auth/register/supervisor', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'verification_status', 'reviewed_by', 'reviewed_at', 'review_comment',
                'verified_by', 'verified_at', 'verification_note', 'is_active',
            ]);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_obvious_duplicate_new_company_is_rejected_by_website_domain(): void
    {
        Company::query()->create([
            'name' => 'Existing Legal Name',
            'website' => 'https://example.com/about',
            'verification_status' => VerificationStatus::APPROVED,
        ]);
        $payload = $this->newCompanyPayload([
            'company_name' => 'Different Entered Name',
            'company_website' => 'https://www.example.com/contact',
        ]);

        $this->postJson('/api/auth/register/supervisor', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_website');

        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_supervisor_registration_does_not_authenticate_the_user(): void
    {
        $this->postJson('/api/auth/register/supervisor', $this->newCompanyPayload())->assertCreated();

        $this->assertGuest();
    }

    public function test_supervisor_password_is_hashed(): void
    {
        $this->postJson('/api/auth/register/supervisor', $this->newCompanyPayload())->assertCreated();

        $user = User::query()->where('email', 'supervisor@example.com')->firstOrFail();

        $this->assertNotSame('Supervisor123', $user->password);
        $this->assertTrue(Hash::check('Supervisor123', $user->password));
    }

    private function company(VerificationStatus $status): Company
    {
        return Company::query()->create([
            'name' => "{$status->value} Company",
            'verification_status' => $status,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function basePayload(array $overrides = []): array
    {
        return array_replace([
            'first_name' => 'Company',
            'last_name' => 'Supervisor',
            'email' => 'supervisor@example.com',
            'password' => 'Supervisor123',
            'password_confirmation' => 'Supervisor123',
            'phone' => '+48 987 654 321',
            'job_title' => 'Engineering Manager',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function existingCompanyPayload(Company $company, array $overrides = []): array
    {
        return $this->basePayload(array_replace([
            'company_mode' => 'existing',
            'company_id' => $company->id,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newCompanyPayload(array $overrides = []): array
    {
        return $this->basePayload(array_replace([
            'company_mode' => 'new',
            'company_name' => 'New Company',
            'company_industry' => 'Software',
            'company_address' => '1 Example Street',
            'company_email' => 'contact@new-company.example',
            'company_phone' => '+48 111 222 333',
            'company_website' => 'https://new-company.example',
        ], $overrides));
    }
}
