<?php

namespace Tests\Feature\Company;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCompaniesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_guests_cannot_access_either_endpoint(): void
    {
        $company = $this->company();
        $this->getJson('/api/admin/companies')->assertUnauthorized();
        $this->getJson('/api/admin/companies/'.$company->id)->assertUnauthorized();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_cannot_access_either_endpoint(UserRole $role): void
    {
        $company = $this->company();
        $this->actingAs($this->user($role));
        $this->getJson('/api/admin/companies')->assertForbidden();
        $this->getJson('/api/admin/companies/'.$company->id)->assertForbidden();
    }

    public static function nonAdminRoles(): array
    {
        return [[UserRole::STUDENT], [UserRole::COMPANY_SUPERVISOR], [UserRole::ACADEMIC_COORDINATOR]];
    }

    public function test_inactive_admin_cannot_access_either_endpoint(): void
    {
        $company = $this->company();
        $this->actingAs($this->user(UserRole::ADMIN, ['is_active' => false]));
        $this->getJson('/api/admin/companies')->assertForbidden();
        $this->getJson('/api/admin/companies/'.$company->id)->assertForbidden();
    }

    public function test_admin_listing_is_paginated_safe_and_deterministically_ordered(): void
    {
        $this->admin();
        $this->getJson('/api/admin/companies')->assertOk()->assertJsonPath('data', [])
            ->assertJsonPath('meta.per_page', 15)->assertJsonStructure(['data', 'links', 'meta']);
        $old = $this->company(['created_at' => '2025-01-01 00:00:00']);
        $first = $this->company(['created_at' => '2026-01-01 00:00:00']);
        $second = $this->company(['created_at' => '2026-01-01 00:00:00', 'verification_note' => 'Admin-only note']);
        $response = $this->getJson('/api/admin/companies')->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertSame([$second->id, $first->id, $old->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.verification_status', 'PENDING')->assertJsonPath('data.0.is_active', true)
            ->assertJsonMissingPath('data.0.verification_note')->assertJsonMissingPath('data.0.supervisors');
        $this->getJson('/api/admin/companies?per_page=1&page=2')->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 3)->assertJsonPath('data.0.id', $first->id);
    }

    public function test_search_is_literal_case_insensitive_and_combines_with_verification_status(): void
    {
        $this->admin();
        $match = $this->company(['name' => 'Acme 100%_Tools', 'verification_status' => VerificationStatus::APPROVED]);
        $this->company(['name' => 'Acme 100%_Tools', 'verification_status' => VerificationStatus::PENDING]);
        $this->company(['name' => 'Acme 100ABTools', 'verification_status' => VerificationStatus::APPROVED]);
        $this->company(['name' => 'Other', 'email' => 'acme@example.test', 'verification_status' => VerificationStatus::REJECTED]);
        $query = http_build_query(['search' => '  acme 100%_  ', 'verification_status' => 'APPROVED']);
        $this->getJson('/api/admin/companies?'.$query)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
        $this->getJson('/api/admin/companies?verification_status=REJECTED')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/companies?search=unknown')->assertJsonPath('meta.total', 0);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->admin();
        foreach ([
            ['verification_status', 'approved'], ['verification_status', 'UNKNOWN'],
            ['per_page', 101], ['per_page', 0], ['page', 0], ['page', '1.5'],
            ['search', str_repeat('x', 256)], ['search', ['invalid']],
        ] as [$field, $value]) {
            $this->getJson('/api/admin/companies?'.http_build_query([$field => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_details_include_safe_supervisors_and_metadata_without_mutating_any_states(): void
    {
        $admin = $this->admin();
        $company = $this->company([
            'name' => 'Details Company', 'industry' => 'Technology', 'address' => 'Main Street',
            'email' => 'company@example.test', 'phone' => '123', 'website' => 'https://example.test',
            'is_active' => false, 'verification_status' => VerificationStatus::APPROVED,
            'verified_by' => $admin->id, 'verified_at' => now(), 'verification_note' => 'Existing decision',
        ]);
        $supervisor = $this->user(UserRole::COMPANY_SUPERVISOR, ['is_active' => false]);
        $profile = $supervisor->companySupervisorProfile()->create([
            'company_id' => $company->id, 'job_title' => 'Mentor',
            'verification_status' => VerificationStatus::REJECTED, 'review_comment' => 'Unrelated private review',
        ]);
        $other = $this->user(UserRole::COMPANY_SUPERVISOR);
        $other->companySupervisorProfile()->create(['company_id' => $this->company()->id]);
        $beforeCompany = $company->fresh()->getAttributes();
        $beforeProfile = $profile->fresh()->getAttributes();
        $beforeUser = $supervisor->fresh()->getAttributes();

        $response = $this->getJson('/api/admin/companies/'.$company->id)->assertOk()
            ->assertJsonPath('data.name', 'Details Company')->assertJsonPath('data.industry', 'Technology')
            ->assertJsonPath('data.verification_note', 'Existing decision')->assertJsonPath('data.verified_by', $admin->id)
            ->assertJsonPath('data.verification_status', 'APPROVED')->assertJsonPath('data.is_active', false)
            ->assertJsonCount(1, 'data.supervisors')->assertJsonPath('data.supervisors.0.user_id', $supervisor->id)
            ->assertJsonPath('data.supervisors.0.job_title', 'Mentor')->assertJsonPath('data.supervisors.0.verification_status', 'REJECTED')
            ->assertJsonPath('data.supervisors.0.is_active', false)->assertJsonMissingPath('data.supervisors.0.password')
            ->assertJsonMissingPath('data.supervisors.0.remember_token')->assertJsonMissingPath('data.supervisors.0.review_comment');
        $this->assertStringNotContainsString($supervisor->password, $response->getContent());
        $this->assertStringNotContainsString('Unrelated private review', $response->getContent());
        $this->assertSame($beforeCompany, $company->fresh()->getAttributes());
        $this->assertSame($beforeProfile, $profile->fresh()->getAttributes());
        $this->assertSame($beforeUser, $supervisor->fresh()->getAttributes());
    }

    public function test_company_without_supervisors_and_missing_company(): void
    {
        $this->admin();
        $this->getJson('/api/admin/companies/'.$this->company()->id)->assertOk()->assertJsonPath('data.supervisors', []);
        $this->getJson('/api/admin/companies/999999')->assertNotFound();
    }

    public function test_details_eager_loading_has_constant_query_count(): void
    {
        $this->admin();
        $company = $this->company();
        $this->user(UserRole::COMPANY_SUPERVISOR)->companySupervisorProfile()->create(['company_id' => $company->id]);
        $small = $this->detailQueryCount($company);
        for ($i = 0; $i < 4; $i++) {
            $this->user(UserRole::COMPANY_SUPERVISOR)->companySupervisorProfile()->create(['company_id' => $company->id]);
        }
        $this->assertSame($small, $this->detailQueryCount($company));
    }

    private function detailQueryCount(Company $company): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->getJson('/api/admin/companies/'.$company->id)->assertOk();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    private function company(array $attributes = []): Company
    {
        return Company::query()->create(['name' => 'Test Company', ...$attributes]);
    }

    private function user(UserRole $role, array $attributes = []): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role->value)->sole()->id,
            ...$attributes,
        ]);
    }

    private function admin(): User
    {
        $admin = $this->user(UserRole::ADMIN)->load('role');
        $this->actingAs($admin);

        return $admin;
    }
}
