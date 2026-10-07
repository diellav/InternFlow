<?php

namespace Tests\Feature\User;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Modules\User\Services\UserActivationService;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_deactivate_and_reactivate_without_changing_other_account_or_profile_data(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $user = $this->user();
        $profile = $user->studentProfile()->create(['student_number' => 'ACTIVATION', 'study_program' => 'Computing']);
        $original = $user->only(['role_id', 'first_name', 'last_name', 'email', 'phone', 'password']);
        $this->actingAs($admin);

        foreach ([false, true] as $state) {
            $this->patchJson($this->url($user), ['is_active' => $state, 'role_id' => $admin->role_id])
                ->assertOk()->assertJsonPath('data.is_active', $state)
                ->assertJsonPath('data.role', 'STUDENT')->assertJsonMissingPath('data.password');
            $this->assertSame($state, $user->fresh()->is_active);
            $this->assertSame($original, $user->fresh()->only(array_keys($original)));
            $this->assertTrue($user->fresh()->studentProfile->is($profile));
        }
    }

    public function test_guest_cannot_change_activation(): void
    {
        $user = $this->user();
        $this->patchJson($this->url($user), ['is_active' => false])->assertUnauthorized();
        $this->assertTrue($user->fresh()->is_active);
    }

    #[DataProvider('nonAdminProvider')]
    public function test_non_admin_cannot_change_activation(UserRole $role): void
    {
        $user = $this->user($role);
        $this->actingAs($user)->patchJson($this->url($user), ['is_active' => false])->assertForbidden();
        $this->assertTrue($user->fresh()->is_active);
    }

    public static function nonAdminProvider(): array
    {
        return [[UserRole::STUDENT], [UserRole::COMPANY_SUPERVISOR], [UserRole::ACADEMIC_COORDINATOR]];
    }

    public function test_inactive_admin_cannot_change_activation(): void
    {
        $admin = $this->user(UserRole::ADMIN, false);
        $user = $this->user();
        $this->actingAs($admin)->patchJson($this->url($user), ['is_active' => false])->assertForbidden();
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_admin_cannot_deactivate_self_even_when_another_admin_exists(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $this->user(UserRole::ADMIN);
        $this->actingAs($admin)->patchJson($this->url($admin), ['is_active' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('is_active')
            ->assertJsonPath('errors.is_active.0', 'You cannot deactivate your own account.');
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_last_active_admin_cannot_be_deactivated(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $this->user(UserRole::ADMIN, false);
        $this->actingAs($admin)->patchJson($this->url($admin), ['is_active' => false])
            ->assertUnprocessable()->assertJsonPath('errors.is_active.0', 'The last active Admin cannot be deactivated.');
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_admin_can_deactivate_and_reactivate_another_admin(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $other = $this->user(UserRole::ADMIN);
        $this->actingAs($admin);
        foreach ([false, true] as $state) {
            $this->patchJson($this->url($other), ['is_active' => $state])->assertOk();
            $this->assertSame($state, $other->fresh()->is_active);
            $this->assertTrue($admin->fresh()->is_active);
        }
    }

    public function test_only_actual_json_booleans_are_accepted(): void
    {
        $this->actingAs($this->user(UserRole::ADMIN));
        $user = $this->user();
        foreach ([[], ['is_active' => null], ['is_active' => 'false'], ['is_active' => 0], ['is_active' => 'yes']] as $payload) {
            $this->patchJson($this->url($user), $payload)->assertUnprocessable()->assertJsonValidationErrors('is_active');
        }
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_unknown_user_returns_not_found(): void
    {
        $this->actingAs($this->user(UserRole::ADMIN));
        $this->patchJson('/api/admin/users/999999/activation', ['is_active' => false])->assertNotFound();
    }

    public function test_same_state_does_not_issue_an_update(): void
    {
        $this->actingAs($this->user(UserRole::ADMIN));
        $user = $this->user();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->patchJson($this->url($user), ['is_active' => true])->assertOk();
            $updates = array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with(strtolower($query['query']), 'update'));
            $this->assertSame([], $updates);
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_deactivated_authenticated_user_is_blocked_by_existing_active_middleware(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $user = $this->user();
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->getJson('/api/auth/me')->assertOk();

        app(UserActivationService::class)->update($admin, $user, false);
        Auth::guard('web')->forgetUser();
        Auth::guard('sanctum')->forgetUser();

        $this->getJson('/api/auth/me')->assertForbidden()
            ->assertJsonPath('message', 'Account is inactive.');
    }

    public function test_verification_states_and_review_metadata_are_unchanged(): void
    {
        $this->actingAs($this->user(UserRole::ADMIN));
        $user = $this->user(UserRole::COMPANY_SUPERVISOR);
        $company = Company::query()->create(['name' => 'Activation Company', 'verification_status' => VerificationStatus::PENDING]);
        $profile = $user->companySupervisorProfile()->create([
            'company_id' => $company->id, 'verification_status' => VerificationStatus::REJECTED, 'review_comment' => 'Unchanged',
        ]);
        $originalProfile = $profile->fresh()->getAttributes();
        $originalCompany = $company->fresh()->getAttributes();
        foreach ([false, true] as $state) {
            $this->patchJson($this->url($user), ['is_active' => $state])->assertOk();
            $this->assertSame($originalProfile, $profile->fresh()->getAttributes());
            $this->assertSame($originalCompany, $company->fresh()->getAttributes());
        }
    }

    public function test_admin_state_is_rechecked_under_the_transaction_lock(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $this->actingAs($admin);
        User::query()->whereKey($admin->id)->update(['is_active' => false]);
        $user = $this->user();

        $this->patchJson($this->url($user), ['is_active' => false])->assertForbidden();
        $this->assertTrue($user->fresh()->is_active);
    }

    private function user(UserRole $role = UserRole::STUDENT, bool $active = true): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role->value)->sole()->id,
            'is_active' => $active,
        ]);
    }

    private function url(User $user): string
    {
        return '/api/admin/users/'.$user->id.'/activation';
    }
}
