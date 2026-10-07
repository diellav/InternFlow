<?php

namespace Tests\Feature\User;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    #[DataProvider('roleProvider')]
    public function test_each_role_can_view_own_safe_profile(UserRole $role): void
    {
        $user = $this->user($role);
        $this->user(UserRole::STUDENT);
        if ($role === UserRole::STUDENT) {
            $user->studentProfile()->create(['student_number' => 'SELF', 'study_program' => 'Computing', 'study_year' => 2]);
        } elseif ($role === UserRole::ACADEMIC_COORDINATOR) {
            $user->academicCoordinatorProfile()->create(['academic_unit' => 'Engineering']);
        } elseif ($role === UserRole::COMPANY_SUPERVISOR) {
            $company = Company::query()->create(['name' => 'Self Company', 'verification_note' => 'PrivateNote']);
            $user->companySupervisorProfile()->create(['company_id' => $company->id, 'job_title' => 'Mentor', 'review_comment' => 'PrivateReview']);
        }

        $response = $this->actingAs($user)->getJson('/api/profile')->assertOk()
            ->assertJsonPath('data.id', $user->id)->assertJsonPath('data.role', $role->value)
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');
        match ($role) {
            UserRole::STUDENT => $response->assertJsonPath('data.profile.student_number', 'SELF'),
            UserRole::ACADEMIC_COORDINATOR => $response->assertJsonPath('data.profile.academic_unit', 'Engineering'),
            UserRole::COMPANY_SUPERVISOR => $response->assertJsonPath('data.profile.job_title', 'Mentor'),
            UserRole::ADMIN => $response->assertJsonMissingPath('data.profile'),
        };
        foreach ([$user->password, 'PrivateNote', 'PrivateReview'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public static function roleProvider(): array
    {
        return array_map(fn (UserRole $role): array => [$role], UserRole::cases());
    }

    public function test_partial_update_preserves_unspecified_fields_and_other_accounts(): void
    {
        $user = $this->user(UserRole::STUDENT);
        $other = $this->user(UserRole::STUDENT);
        $otherBefore = $other->fresh()->getAttributes();
        $before = $user->only(['last_name', 'email', 'role_id', 'password', 'is_active']);
        $this->actingAs($user)->patchJson('/api/profile', ['first_name' => 'Updated', 'phone' => '12345'])
            ->assertOk()->assertJsonPath('data.first_name', 'Updated')->assertJsonPath('data.phone', '12345');
        $this->assertSame($before, $user->fresh()->only(array_keys($before)));
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
        $this->assertDatabaseCount('student_profiles', 0);
        $this->patchJson('/api/profile', ['phone' => null])->assertOk()->assertJsonPath('data.phone', null);
        $this->actingAs($user->fresh())->getJson('/api/auth/me')->assertJsonPath('data.first_name', 'Updated');
    }

    public function test_guest_and_inactive_users_are_denied(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
        $this->patchJson('/api/profile', ['first_name' => 'Denied'])->assertUnauthorized();
        $user = $this->user(UserRole::ADMIN);
        $user->update(['is_active' => false]);
        $this->actingAs($user)->getJson('/api/profile')->assertForbidden();
        $this->patchJson('/api/profile', ['first_name' => 'Denied'])->assertForbidden();
    }

    public function test_prohibited_and_unknown_fields_are_rejected_including_another_user_id(): void
    {
        $user = $this->user(UserRole::STUDENT);
        $other = $this->user(UserRole::STUDENT);
        $before = $user->fresh()->getAttributes();
        $otherBefore = $other->fresh()->getAttributes();
        $this->actingAs($user);
        foreach ([
            'user_id' => $other->id, 'id' => $other->id, 'role_id' => $other->role_id,
            'role' => 'ADMIN', 'email' => 'changed@example.test', 'password' => 'ChangedPassword123',
            'is_active' => false, 'company_id' => 1, 'verification_status' => 'APPROVED',
            'review_comment' => 'Changed', 'extra' => null,
        ] as $field => $value) {
            $this->patchJson('/api/profile', ['first_name' => 'Denied', $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
    }

    public function test_institutional_fields_are_read_only_and_job_title_is_role_specific(): void
    {
        $this->actingAs($this->user(UserRole::STUDENT));
        $this->patchJson('/api/profile', ['student_number' => 'Changed', 'study_program' => 'Changed', 'study_year' => 3, 'job_title' => 'Changed'])
            ->assertUnprocessable()->assertJsonValidationErrors(['student_number', 'study_program', 'study_year', 'job_title']);
        $this->actingAs($this->user(UserRole::ACADEMIC_COORDINATOR));
        $this->patchJson('/api/profile', ['academic_unit' => 'Changed'])->assertUnprocessable()->assertJsonValidationErrors('academic_unit');
    }

    #[DataProvider('supervisorStatusProvider')]
    public function test_unverified_supervisor_can_edit_job_title_without_changing_verification(VerificationStatus $status): void
    {
        $user = $this->user(UserRole::COMPANY_SUPERVISOR);
        $company = Company::query()->create(['name' => 'Pending Company', 'verification_status' => VerificationStatus::PENDING]);
        $profile = $user->companySupervisorProfile()->create([
            'company_id' => $company->id, 'job_title' => 'Original', 'verification_status' => $status, 'review_comment' => 'Unchanged',
        ]);
        $companyBefore = $company->fresh()->getAttributes();
        $profileBefore = $profile->fresh()->only(['company_id', 'verification_status', 'reviewed_by', 'reviewed_at', 'review_comment']);
        $this->actingAs($user)->getJson('/api/profile')->assertOk();
        $this->patchJson('/api/profile', ['first_name' => 'Updated', 'job_title' => 'New title'])->assertOk()
            ->assertJsonPath('data.profile.job_title', 'New title')->assertJsonPath('data.profile.verification_status', $status->value);
        $this->assertSame($companyBefore, $company->fresh()->getAttributes());
        $this->assertSame($profileBefore, $profile->fresh()->only(array_keys($profileBefore)));
    }

    public static function supervisorStatusProvider(): array
    {
        return [[VerificationStatus::PENDING], [VerificationStatus::REJECTED]];
    }

    public function test_missing_supervisor_profile_is_not_created_and_failed_update_is_atomic(): void
    {
        $user = $this->user(UserRole::COMPANY_SUPERVISOR);
        $this->actingAs($user)->getJson('/api/profile')->assertOk()->assertJsonPath('data.profile', null);
        $this->patchJson('/api/profile', ['first_name' => 'Denied', 'job_title' => 'Mentor'])
            ->assertUnprocessable()->assertJsonValidationErrors('job_title');
        $this->assertSame($user->first_name, $user->fresh()->first_name);
        $this->assertDatabaseCount('company_supervisor_profiles', 0);
        $this->patchJson('/api/profile', ['phone' => '123'])->assertOk();
    }

    public function test_invalid_personal_fields_are_rejected(): void
    {
        $user = $this->user(UserRole::ADMIN);
        $this->actingAs($user)->patchJson('/api/profile', ['first_name' => '', 'last_name' => str_repeat('x', 256), 'phone' => ['bad']])
            ->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'last_name', 'phone']);
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('name', $role->value)->sole()->id]);
    }
}
