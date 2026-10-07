<?php

namespace Tests\Feature\User;

use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\UserRole;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminSeedingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        config([
            'admin.email' => 'admin-foundation@example.test',
            'admin.password' => 'TestOnlyPassword123',
            'admin.first_name' => 'Foundation',
            'admin.last_name' => 'Administrator',
        ]);
    }

    public function test_configured_admin_is_created_once_without_resetting_existing_credentials(): void
    {
        $this->seed(AdminUserSeeder::class);
        $admin = User::query()->sole();
        $originalHash = $admin->password;

        $this->assertTrue($admin->hasRole(UserRole::ADMIN));
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check('TestOnlyPassword123', $originalHash));
        $this->assertSame('admin-foundation@example.test', $admin->email);

        config(['admin.password' => 'DifferentTestPassword123']);
        $this->seed(AdminUserSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($originalHash, $admin->fresh()->password);
        $this->assertDatabaseCount('student_profiles', 0);
        $this->assertDatabaseCount('company_supervisor_profiles', 0);
        $this->assertDatabaseCount('academic_coordinator_profiles', 0);
    }

    #[DataProvider('missingConfigurationProvider')]
    public function test_admin_is_not_created_when_required_configuration_is_missing(string $key): void
    {
        config([$key => null]);
        $this->seed(AdminUserSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }

    public static function missingConfigurationProvider(): array
    {
        return [['admin.email'], ['admin.password']];
    }

    public function test_existing_non_admin_email_is_not_promoted_or_overwritten(): void
    {
        $user = User::factory()->create(['email' => config('admin.email')]);
        $originalAttributes = $user->fresh()->getAttributes();

        $this->seed(AdminUserSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($originalAttributes, $user->fresh()->getAttributes());
        $this->assertTrue($user->fresh()->hasRole(UserRole::STUDENT));
    }

    public function test_role_seeding_is_idempotent_and_preserves_canonical_roles(): void
    {
        $originalIds = Role::query()->pluck('id', 'name')->all();
        $this->seed(RoleSeeder::class);

        $this->assertDatabaseCount('roles', 4);
        $this->assertSame($originalIds, Role::query()->pluck('id', 'name')->all());
        $this->assertEqualsCanonicalizing(array_column(UserRole::cases(), 'value'), array_keys($originalIds));
    }
}
