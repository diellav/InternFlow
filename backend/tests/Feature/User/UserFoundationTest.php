<?php

namespace Tests\Feature\User;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Modules\User\Resources\UserResource;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    #[DataProvider('roleProvider')]
    public function test_user_role_and_role_users_relationships_resolve(UserRole $role): void
    {
        $user = $this->user($role);

        $this->assertSame($role->value, $user->role->name);
        $this->assertTrue($user->hasRole($role));
        $this->assertTrue($user->role->users()->sole()->is($user));
        $this->assertFalse($user->hasRole(...array_filter(
            UserRole::cases(),
            fn (UserRole $other): bool => $other !== $role,
        )));
    }

    public function test_student_profile_resolves_in_both_directions_without_unrelated_profiles(): void
    {
        $user = $this->user(UserRole::STUDENT);
        $profile = $user->studentProfile()->create([
            'student_number' => 'FOUNDATION-STUDENT',
            'study_program' => 'Information Systems',
            'study_year' => 2,
        ]);

        $this->assertTrue($user->fresh()->studentProfile->is($profile));
        $this->assertTrue($profile->fresh()->user->is($user));
        $this->assertNull($user->companySupervisorProfile);
        $this->assertNull($user->academicCoordinatorProfile);
    }

    public function test_supervisor_profile_resolves_user_and_company_without_unrelated_profiles(): void
    {
        $user = $this->user(UserRole::COMPANY_SUPERVISOR);
        $company = Company::query()->create(['name' => 'Foundation Company']);
        $profile = $user->companySupervisorProfile()->create([
            'company_id' => $company->id,
            'verification_status' => VerificationStatus::PENDING,
        ]);

        $this->assertTrue($user->fresh()->companySupervisorProfile->is($profile));
        $this->assertTrue($profile->fresh()->user->is($user));
        $this->assertTrue($profile->company->is($company));
        $this->assertTrue($company->supervisors()->sole()->is($profile));
        $this->assertNull($user->studentProfile);
        $this->assertNull($user->academicCoordinatorProfile);
        $this->assertTrue($user->is_active);
        $this->assertSame(VerificationStatus::PENDING, $profile->verification_status);
    }

    public function test_coordinator_profile_resolves_in_both_directions_without_unrelated_profiles(): void
    {
        $user = $this->user(UserRole::ACADEMIC_COORDINATOR);
        $profile = $user->academicCoordinatorProfile()->create(['academic_unit' => 'Engineering']);

        $this->assertTrue($user->fresh()->academicCoordinatorProfile->is($profile));
        $this->assertTrue($profile->fresh()->user->is($user));
        $this->assertNull($user->studentProfile);
        $this->assertNull($user->companySupervisorProfile);
    }

    public function test_admin_has_a_role_without_a_role_specific_profile(): void
    {
        $user = $this->user(UserRole::ADMIN);

        $this->assertTrue($user->hasRole(UserRole::ADMIN));
        $this->assertNull($user->studentProfile);
        $this->assertNull($user->companySupervisorProfile);
        $this->assertNull($user->academicCoordinatorProfile);
    }

    #[DataProvider('roleProvider')]
    public function test_resource_exposes_only_safe_account_fields_without_queries(UserRole $role): void
    {
        $user = $this->user($role)->load('role');
        $user->setAttribute('remember_token', 'private-token');
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $data = (new UserResource($user))->response()->getData(true);

            $this->assertSame([
                'data' => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'is_active' => true,
                    'role' => $role->value,
                ],
            ], $data);
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_resource_does_not_lazy_load_role_or_profiles(): void
    {
        $user = $this->user(UserRole::STUDENT)->fresh();
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $data = (new UserResource($user))->resolve();

            $this->assertArrayNotHasKey('role', $data);
            $this->assertArrayNotHasKey('profile', $data);
            $this->assertSame([], DB::getQueryLog());
            $this->assertSame([], $user->getRelations());
        } finally {
            DB::disableQueryLog();
        }
    }

    public static function roleProvider(): array
    {
        return array_map(fn (UserRole $role): array => [$role], UserRole::cases());
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role->value)->sole()->id,
        ]);
    }
}
