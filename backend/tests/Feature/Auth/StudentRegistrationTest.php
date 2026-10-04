<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use App\Modules\Auth\DTOs\StudentRegistrationDTO;
use App\Modules\Auth\Services\RegistrationService;
use App\Shared\Enums\UserRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StudentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_student_can_register_with_a_profile(): void
    {
        $response = $this->postJson('/api/auth/register/student', $this->validPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('data.email', 'student@example.com')
            ->assertJsonPath('data.role', UserRole::STUDENT->value)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.profile.student_number', 'STU-001')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.role_id');

        $studentRole = Role::query()->where('name', UserRole::STUDENT->value)->firstOrFail();
        $user = User::query()->where('email', 'student@example.com')->firstOrFail();

        $this->assertSame($studentRole->id, $user->role_id);
        $this->assertTrue($user->is_active);
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $user->id,
            'student_number' => 'STU-001',
            'study_program' => 'Computer Science',
            'study_year' => 3,
        ]);
    }

    public function test_registered_password_is_hashed(): void
    {
        $this->postJson('/api/auth/register/student', $this->validPayload())->assertCreated();

        $user = User::query()->where('email', 'student@example.com')->firstOrFail();

        $this->assertNotSame('Student123', $user->password);
        $this->assertTrue(Hash::check('Student123', $user->password));
    }

    public function test_duplicate_email_is_rejected_without_creating_an_orphan_profile(): void
    {
        $this->postJson('/api/auth/register/student', $this->validPayload())->assertCreated();

        $duplicate = $this->validPayload([
            'student_number' => 'STU-002',
        ]);

        $this->postJson('/api/auth/register/student', $duplicate)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('student_profiles', 1);
    }

    public function test_duplicate_student_number_is_rejected_without_creating_a_user(): void
    {
        $this->postJson('/api/auth/register/student', $this->validPayload())->assertCreated();

        $duplicate = $this->validPayload([
            'email' => 'second.student@example.com',
        ]);

        $this->postJson('/api/auth/register/student', $duplicate)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_number');

        $this->assertDatabaseMissing('users', ['email' => 'second.student@example.com']);
        $this->assertDatabaseCount('student_profiles', 1);
    }

    public function test_password_confirmation_must_match(): void
    {
        $payload = $this->validPayload(['password_confirmation' => 'Different123']);

        $this->postJson('/api/auth/register/student', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('student_profiles', 0);
    }

    public function test_required_fields_are_validated(): void
    {
        $this->postJson('/api/auth/register/student')
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'first_name',
                'last_name',
                'email',
                'password',
                'password_confirmation',
                'student_number',
                'study_program',
            ]);
    }

    public function test_privileged_role_fields_are_rejected(): void
    {
        $adminRole = Role::query()->where('name', UserRole::ADMIN->value)->firstOrFail();
        $payload = $this->validPayload([
            'role' => UserRole::ADMIN->value,
            'role_id' => $adminRole->id,
        ]);

        $this->postJson('/api/auth/register/student', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role', 'role_id']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_does_not_authenticate_the_student(): void
    {
        $this->postJson('/api/auth/register/student', $this->validPayload())->assertCreated();

        $this->assertGuest();
    }

    public function test_profile_failure_rolls_back_user_creation(): void
    {
        $this->postJson('/api/auth/register/student', $this->validPayload())->assertCreated();

        $dto = StudentRegistrationDTO::fromValidated($this->validPayload([
            'email' => 'rollback@example.com',
        ]));

        try {
            app(RegistrationService::class)->registerStudent($dto);
            $this->fail('Expected duplicate student number validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('student_number', $exception->errors());
        }

        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.com']);
        $this->assertDatabaseCount('student_profiles', 1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'first_name' => 'Student',
            'last_name' => 'Example',
            'email' => 'student@example.com',
            'password' => 'Student123',
            'password_confirmation' => 'Student123',
            'phone' => '+48 123 456 789',
            'student_number' => 'STU-001',
            'study_program' => 'Computer Science',
            'study_year' => 3,
        ], $overrides);
    }
}
