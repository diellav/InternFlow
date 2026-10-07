<?php

namespace Tests\Feature\User;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_guests_are_denied_on_both_endpoints(): void
    {
        $user = $this->user();
        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->getJson('/api/admin/users/'.$user->id)->assertUnauthorized();
    }

    #[DataProvider('nonAdminProvider')]
    public function test_non_admins_are_denied_on_both_endpoints(UserRole $role): void
    {
        $user = $this->user($role);
        $this->actingAs($user);
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->getJson('/api/admin/users/'.$user->id)->assertForbidden();
    }

    public static function nonAdminProvider(): array
    {
        return [[UserRole::STUDENT], [UserRole::COMPANY_SUPERVISOR], [UserRole::ACADEMIC_COORDINATOR]];
    }

    public function test_inactive_admin_is_denied_on_both_endpoints(): void
    {
        $user = $this->user(UserRole::ADMIN, ['is_active' => false]);
        $this->actingAs($user);
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->getJson('/api/admin/users/'.$user->id)->assertForbidden();
    }

    public function test_empty_list_is_paginated(): void
    {
        $this->admin();
        $this->getJson('/api/admin/users')->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_list_has_safe_fields_and_stable_newest_first_ordering(): void
    {
        $this->admin();
        $old = $this->user(attributes: ['created_at' => '2025-01-01 00:00:00']);
        $first = $this->user(attributes: ['created_at' => '2026-01-01 00:00:00']);
        $second = $this->user(attributes: ['created_at' => '2026-01-01 00:00:00']);
        $response = $this->getJson('/api/admin/users')->assertOk();

        $this->assertSame([$second->id, $first->id, $old->id], array_column($response->json('data'), 'id'));
        $this->assertSame(['id', 'first_name', 'last_name', 'email', 'phone', 'is_active', 'role'], array_keys($response->json('data.0')));
        $response->assertJsonPath('data.0.role', 'STUDENT');
        $this->assertStringNotContainsString($second->password, $response->getContent());
    }

    public function test_pagination_defaults_later_pages_and_maximum(): void
    {
        $this->admin();
        User::factory()->count(21)->create();
        $this->getJson('/api/admin/users')->assertJsonCount(15, 'data')->assertJsonPath('meta.total', 21);
        $this->getJson('/api/admin/users?per_page=10&page=2')->assertJsonCount(10, 'data')->assertJsonPath('meta.current_page', 2);
        $this->getJson('/api/admin/users?per_page=100')->assertOk()->assertJsonCount(21, 'data')->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/admin/users?per_page=1')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/users?page=3')->assertJsonCount(0, 'data');
    }

    #[DataProvider('searchProvider')]
    public function test_case_insensitive_names_email_and_literal_search(string $search, array $attributes): void
    {
        $this->admin();
        $match = $this->user(attributes: $attributes);
        $this->user(attributes: ['first_name' => 'Unrelated', 'last_name' => 'Person', 'email' => 'other@example.test']);

        $this->listing(['search' => $search])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
    }

    public static function searchProvider(): array
    {
        return [
            'first name' => ['dIeLlA', ['first_name' => 'Diella']],
            'last name' => ['hOXha', ['last_name' => 'Hoxha']],
            'email' => ['UNIQUE@EXAMPLE', ['email' => 'unique@example.test']],
            'full name' => ['  diella hoxha  ', ['first_name' => 'Diella', 'last_name' => 'Hoxha']],
            'percent' => ['%', ['first_name' => 'Literal%Name']],
            'underscore' => ['_', ['first_name' => 'Literal_Name']],
            'backslash' => ['\\', ['first_name' => 'Literal\\Name']],
            'combined wildcards' => ['\\%_', ['first_name' => 'Literal\\%_Name']],
            'zero' => ['0', ['first_name' => 'Name0']],
        ];
    }

    public function test_blank_search_is_ignored(): void
    {
        $this->admin();
        $this->user();
        $this->listing(['search' => '   '])->assertJsonCount(1, 'data');
    }

    #[DataProvider('roleProvider')]
    public function test_exact_role_filter(UserRole $role): void
    {
        $this->admin();
        foreach (UserRole::cases() as $candidate) {
            $this->user($candidate);
        }
        $this->listing(['role' => $role->value])->assertJsonCount(1, 'data')->assertJsonPath('data.0.role', $role->value);
    }

    public static function roleProvider(): array
    {
        return array_map(fn (UserRole $role): array => [$role], UserRole::cases());
    }

    #[DataProvider('booleanProvider')]
    public function test_strict_boolean_filters(string $input, bool $expected): void
    {
        $this->admin();
        $this->user(attributes: ['is_active' => true]);
        $this->user(attributes: ['is_active' => false]);
        $this->listing(['is_active' => $input])->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_active', $expected);
    }

    public static function booleanProvider(): array
    {
        return [['true', true], ['false', false], ['1', true], ['0', false]];
    }

    public function test_filters_combine_with_and(): void
    {
        $this->admin();
        $match = $this->user(attributes: ['first_name' => 'Match', 'is_active' => false]);
        $this->user(attributes: ['first_name' => 'Match', 'is_active' => true]);
        $this->user(UserRole::ADMIN, ['first_name' => 'Match', 'is_active' => false]);
        $this->listing(['search' => 'Match', 'role' => 'STUDENT', 'is_active' => 'false'])
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
    }

    #[DataProvider('invalidFilterProvider')]
    public function test_invalid_filters_return_validation_errors(string $key, mixed $value): void
    {
        $this->admin();
        $this->listing([$key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
    }

    public static function invalidFilterProvider(): array
    {
        return [
            ['role', 'student'], ['role', 'UNKNOWN'], ['role', ''],
            ['is_active', 'yes'], ['is_active', '2'], ['is_active', ''],
            ['page', '0'], ['page', '-1'], ['page', '1.5'], ['page', 'abc'],
            ['per_page', '0'], ['per_page', '101'], ['per_page', '1.5'],
            ['search', str_repeat('x', 256)], ['search', ['bad']], ['sort', 'email'],
        ];
    }

    #[DataProvider('roleProvider')]
    public function test_details_only_include_the_relevant_safe_profile(UserRole $role): void
    {
        $this->admin();
        $user = $this->user($role);
        $user->studentProfile()->create(['student_number' => 'DETAIL', 'study_program' => 'Computing']);
        $user->academicCoordinatorProfile()->create(['academic_unit' => 'Engineering']);
        $company = Company::query()->create(['name' => 'Details Company', 'verification_note' => 'PrivateCompanyNote']);
        $user->companySupervisorProfile()->create([
            'company_id' => $company->id, 'job_title' => 'Mentor',
            'verification_status' => VerificationStatus::REJECTED, 'review_comment' => 'PrivateSupervisorNote',
        ]);
        $before = $user->companySupervisorProfile->getAttributes();
        $response = $this->getJson('/api/admin/users/'.$user->id)->assertOk()->assertJsonPath('data.role', $role->value);

        match ($role) {
            UserRole::STUDENT => $response->assertJsonPath('data.profile.student_number', 'DETAIL')->assertJsonMissingPath('data.profile.company'),
            UserRole::ACADEMIC_COORDINATOR => $response->assertJsonPath('data.profile.academic_unit', 'Engineering')->assertJsonMissingPath('data.profile.student_number'),
            UserRole::COMPANY_SUPERVISOR => $response->assertJsonPath('data.profile.job_title', 'Mentor')
                ->assertJsonPath('data.profile.verification_status', 'REJECTED')->assertJsonPath('data.profile.company.name', 'Details Company'),
            UserRole::ADMIN => $response->assertJsonMissingPath('data.profile'),
        };

        foreach ([$user->password, 'PrivateCompanyNote', 'PrivateSupervisorNote'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $response->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token')
            ->assertJsonMissingPath('data.profile.review_comment')->assertJsonMissingPath('data.profile.company.verification_note');
        $this->assertSame($before, $user->companySupervisorProfile->fresh()->getAttributes());
    }

    #[DataProvider('nonAdminProvider')]
    public function test_missing_optional_profile_returns_null(UserRole $role): void
    {
        $this->admin();
        $user = $this->user($role);
        $this->getJson('/api/admin/users/'.$user->id)->assertOk()->assertJsonPath('data.profile', null);
    }

    public function test_unknown_user_returns_not_found(): void
    {
        $this->admin();
        $this->getJson('/api/admin/users/999999')->assertNotFound();
    }

    #[DataProvider('roleProvider')]
    public function test_details_query_only_the_relevant_profile_and_company(UserRole $role): void
    {
        $this->admin();
        $user = $this->user($role);
        if ($role === UserRole::COMPANY_SUPERVISOR) {
            $company = Company::query()->create(['name' => 'Query Company']);
            $user->companySupervisorProfile()->create(['company_id' => $company->id]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->getJson('/api/admin/users/'.$user->id)->assertOk();
            $sql = implode(' ', array_column(DB::getQueryLog(), 'query'));
            foreach ([
                'student_profiles' => UserRole::STUDENT,
                'academic_coordinator_profiles' => UserRole::ACADEMIC_COORDINATOR,
                'company_supervisor_profiles' => UserRole::COMPANY_SUPERVISOR,
                'companies' => UserRole::COMPANY_SUPERVISOR,
            ] as $table => $expectedRole) {
                $this->assertSame($role === $expectedRole, str_contains($sql, '"'.$table.'"'));
            }
        } finally {
            DB::disableQueryLog();
        }
    }

    #[DataProvider('verificationProvider')]
    public function test_listing_includes_unverified_supervisors_without_mutating_verification(VerificationStatus $status): void
    {
        $this->admin();
        $supervisor = $this->user(UserRole::COMPANY_SUPERVISOR);
        $company = Company::query()->create(['name' => 'Unverified Company', 'verification_status' => $status]);
        $profile = $supervisor->companySupervisorProfile()->create([
            'company_id' => $company->id, 'verification_status' => $status,
        ]);

        $this->listing(['role' => 'COMPANY_SUPERVISOR'])->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $supervisor->id);
        $this->assertSame($status, $profile->fresh()->verification_status);
        $this->assertSame($status, $company->fresh()->verification_status);
    }

    public static function verificationProvider(): array
    {
        return [[VerificationStatus::PENDING], [VerificationStatus::REJECTED]];
    }

    public function test_listing_query_count_is_constant_without_profile_queries(): void
    {
        $this->admin();
        $this->user();
        $small = $this->listingQueries();
        User::factory()->count(10)->create();
        $large = $this->listingQueries();
        $this->assertCount(count($small), $large);
        $this->assertCount(3, $large);
        foreach ($large as $query) {
            $this->assertDoesNotMatchRegularExpression('/profiles|companies/', $query['query']);
        }
    }

    private function listingQueries(): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->getJson('/api/admin/users')->assertOk();

            return DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
    }

    private function listing(array $query): TestResponse
    {
        return $this->getJson('/api/admin/users?'.http_build_query($query));
    }

    private function admin(): void
    {
        $admin = User::factory()->make(['role_id' => Role::query()->where('name', 'ADMIN')->sole()->id]);
        $admin->setRelation('role', Role::query()->where('name', 'ADMIN')->sole());
        $this->actingAs($admin);
    }

    private function user(UserRole $role = UserRole::STUDENT, array $attributes = []): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role->value)->sole()->id,
            ...$attributes,
        ]);
    }
}
