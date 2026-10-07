<?php

namespace Tests\Feature\User;

use App\Models\AcademicCoordinatorProfile;
use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\UserRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CoordinatorManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_creates_active_coordinator_with_profile_and_hashed_password(): void
    {
        $this->actingAs($this->user(UserRole::ADMIN));
        $response = $this->postJson('/api/admin/academic-coordinators', $this->payload())
            ->assertCreated()->assertJsonPath('data.role', 'ACADEMIC_COORDINATOR')
            ->assertJsonPath('data.is_active', true)->assertJsonPath('data.profile.academic_unit', 'Engineering')
            ->assertJsonPath('data.email', 'coordinator@example.test')->assertJsonMissingPath('data.password');
        $user = User::query()->findOrFail($response->json('data.id'));
        $this->assertTrue(Hash::check('Coordinator123', $user->password));
        $this->assertTrue($user->academicCoordinatorProfile->user->is($user));
        $this->assertStringNotContainsString($user->password, $response->getContent());
    }

    public function test_admin_updates_allowed_fields_without_changing_password_role_or_activation(): void
    {
        $this->actingAs($this->user(UserRole::ADMIN));
        $user = $this->user(UserRole::ACADEMIC_COORDINATOR);
        $user->academicCoordinatorProfile()->create(['academic_unit' => 'Original']);
        $before = $user->only(['password', 'role_id', 'is_active']);
        $this->patchJson($this->url($user), ['first_name' => 'Updated', 'email' => $user->email, 'phone' => '123', 'academic_unit' => 'Updated unit'])
            ->assertOk()->assertJsonPath('data.first_name', 'Updated')->assertJsonPath('data.profile.academic_unit', 'Updated unit');
        $this->assertSame($before, $user->fresh()->only(array_keys($before)));
        $this->patchJson($this->url($user), ['academic_unit' => null])->assertOk()->assertJsonPath('data.profile.academic_unit', null);
    }

    public function test_duplicate_email_is_rejected_on_creation_and_update(): void
    {
        $this->actingAs($this->user(UserRole::ADMIN));
        $other = $this->user(UserRole::STUDENT);
        $coordinator = $this->user(UserRole::ACADEMIC_COORDINATOR);
        $before = User::query()->count();
        $this->postJson('/api/admin/academic-coordinators', [...$this->payload(), 'email' => $other->email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patchJson($this->url($coordinator), ['email' => $other->email])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', $before);
        $this->assertSame($coordinator->email, $coordinator->fresh()->email);
    }

    public function test_guest_and_inactive_admin_cannot_manage_coordinators(): void
    {
        $coordinator = $this->user(UserRole::ACADEMIC_COORDINATOR);
        $this->postJson('/api/admin/academic-coordinators', $this->payload())->assertUnauthorized();
        $this->patchJson($this->url($coordinator), ['first_name' => 'Blocked'])->assertUnauthorized();
        $admin = $this->user(UserRole::ADMIN);
        $admin->update(['is_active' => false]);
        $this->actingAs($admin);
        $this->postJson('/api/admin/academic-coordinators', $this->payload())->assertForbidden();
        $this->patchJson($this->url($coordinator), ['first_name' => 'Blocked'])->assertForbidden();
    }

    #[DataProvider('nonAdminProvider')]
    public function test_non_admin_cannot_create_or_update(UserRole $role): void
    {
        $this->actingAs($this->user($role));
        $coordinator = $this->user(UserRole::ACADEMIC_COORDINATOR);
        $this->postJson('/api/admin/academic-coordinators', $this->payload())->assertForbidden();
        $this->patchJson($this->url($coordinator), ['first_name' => 'Blocked'])->assertForbidden();
    }

    public static function nonAdminProvider(): array
    {
        return [[UserRole::STUDENT], [UserRole::COMPANY_SUPERVISOR], [UserRole::ACADEMIC_COORDINATOR]];
    }

    public function test_non_coordinator_and_missing_targets_are_rejected(): void
    {
        $this->actingAs($this->user(UserRole::ADMIN));
        foreach ([UserRole::STUDENT, UserRole::COMPANY_SUPERVISOR, UserRole::ADMIN] as $role) {
            $user = $this->user($role);
            $this->patchJson($this->url($user), ['first_name' => 'Blocked'])->assertUnprocessable()->assertJsonValidationErrors('user');
            $this->assertSame($user->first_name, $user->fresh()->first_name);
        }
        $this->patchJson('/api/admin/academic-coordinators/999999', ['first_name' => 'Missing'])->assertNotFound();
    }

    public function test_invalid_and_privileged_fields_are_rejected(): void
    {
        $this->actingAs($this->user(UserRole::ADMIN));
        $this->postJson('/api/admin/academic-coordinators', ['email' => 'bad', 'password' => 'weak'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password', 'first_name', 'last_name']);
        $this->postJson('/api/admin/academic-coordinators', [...$this->payload(), 'role' => 'ADMIN', 'is_active' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['role', 'is_active']);
        $user = $this->user(UserRole::ACADEMIC_COORDINATOR);
        $this->patchJson($this->url($user), ['role_id' => 1, 'is_active' => false, 'password' => 'NewPassword123'])
            ->assertUnprocessable()->assertJsonValidationErrors(['role_id', 'is_active', 'password']);
    }

    public function test_profile_failure_rolls_back_account_creation(): void
    {
        $this->actingAs($this->user(UserRole::ADMIN));
        $before = User::query()->count();
        AcademicCoordinatorProfile::creating(fn () => throw new RuntimeException('Profile failure'));
        try {
            $this->postJson('/api/admin/academic-coordinators', $this->payload())->assertServerError();
            $this->assertDatabaseCount('users', $before);
            $this->assertDatabaseCount('academic_coordinator_profiles', 0);
        } finally {
            AcademicCoordinatorProfile::flushEventListeners();
        }
    }

    private function payload(): array
    {
        return ['first_name' => 'Academic', 'last_name' => 'Coordinator', 'email' => ' Coordinator@Example.Test ', 'password' => 'Coordinator123', 'academic_unit' => 'Engineering'];
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('name', $role->value)->sole()->id]);
    }

    private function url(User $user): string
    {
        return '/api/admin/academic-coordinators/'.$user->id;
    }
}
