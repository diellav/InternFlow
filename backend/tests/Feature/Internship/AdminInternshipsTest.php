<?php

namespace Tests\Feature\Internship;

use App\Models\Company;
use App\Models\Internship;
use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\InternshipStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminInternshipsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function user(string $role = 'ADMIN', array $attributes = []): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->sole()->id, ...$attributes]);
    }

    private function internship(string $status = 'DRAFT', bool $assigned = false): Internship
    {
        $student = $this->user('STUDENT', ['first_name' => 'Arta', 'last_name' => 'Student']);
        $student->studentProfile()->create(['student_number' => fake()->uuid(), 'study_program' => 'CS', 'study_year' => 2]);
        $company = Company::create(['name' => 'Example Company', 'is_active' => true, 'verification_status' => 'APPROVED']);
        $coordinator = $supervisor = null;
        if ($assigned) {
            $coordinator = $this->user('ACADEMIC_COORDINATOR');
            $coordinator->academicCoordinatorProfile()->create(['academic_unit' => 'Computing']);
            $supervisor = $this->user('COMPANY_SUPERVISOR');
            $supervisor->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => 'APPROVED']);
        }

        return Internship::create(['student_id' => $student->id, 'company_id' => $company->id, 'coordinator_id' => $coordinator?->id,
            'company_supervisor_id' => $supervisor?->id, 'position_title' => 'Developer', 'description' => 'Public application description',
            'decision_comment' => 'PRIVATE INTERNAL REVIEW', 'status' => $status, 'start_date' => '2026-10-01', 'end_date' => '2026-11-01',
            'submitted_at' => $status === 'DRAFT' ? null : now()]);
    }

    private function paths(int $id): array
    {
        return ['/api/admin/internships', '/api/admin/internships/statistics', '/api/admin/internships/'.$id];
    }

    public function test_guests_are_denied(): void
    {
        foreach ($this->paths($this->internship()->id) as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
    }

    public static function nonAdmins(): array
    {
        return [['STUDENT'], ['COMPANY_SUPERVISOR'], ['ACADEMIC_COORDINATOR']];
    }

    #[DataProvider('nonAdmins')]
    public function test_non_admins_are_denied(string $role): void
    {
        $record = $this->internship();
        $this->actingAs($this->user($role));
        foreach ($this->paths($record->id) as $path) {
            $this->getJson($path)->assertForbidden();
        }
    }

    public function test_inactive_admin_is_denied(): void
    {
        $record = $this->internship();
        $this->actingAs($this->user(attributes: ['is_active' => false]));
        foreach ($this->paths($record->id) as $path) {
            $this->getJson($path)->assertForbidden();
        }
    }

    public function test_statistics_are_actual_counts_with_one_aggregate_query(): void
    {
        $this->actingAs($this->user());
        $this->getJson('/api/admin/internships/statistics')->assertExactJson(['data' => ['total' => 0, 'pending_review' => 0, 'active' => 0, 'completed' => 0]]);
        foreach (InternshipStatus::cases() as $status) {
            $this->internship($status->value);
        }
        DB::enableQueryLog();
        try {
            $this->getJson('/api/admin/internships/statistics')->assertOk()
                ->assertExactJson(['data' => ['total' => 8, 'pending_review' => 2, 'active' => 1, 'completed' => 1]]);
            $queries = array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], '"internships"'));
            $this->assertCount(1, $queries);
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_list_search_filters_pagination_and_optional_assignments(): void
    {
        $draft = $this->internship();
        $active = $this->internship('ACTIVE', true);
        $active->company->update(['name' => 'Percent % Studio']);
        $this->actingAs($this->user());
        $this->getJson('/api/admin/internships')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.1.coordinator', null)->assertJsonPath('data.1.supervisor', null)->assertJsonPath('data.1.submitted_at', null);
        $this->getJson('/api/admin/internships?status=ACTIVE')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
        $this->getJson('/api/admin/internships?search=arta%20student')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/admin/internships?search=percent')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/internships?search=%25')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
        $this->getJson('/api/admin/internships?search=missing')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/internships?search=0')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/internships?per_page=1&page=2')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $draft->id);
    }

    public function test_details_are_allowlisted_and_have_no_private_data_or_mutations(): void
    {
        $record = $this->internship('COMPLETED', true);
        $record->update(['approved_at' => now(), 'completed_at' => now()]);
        $this->actingAs($this->user());
        $response = $this->getJson('/api/admin/internships/'.$record->id)->assertOk()->assertJsonPath('data.position_title', 'Developer')
            ->assertJsonPath('data.description', 'Public application description')->assertJsonPath('data.student.first_name', 'Arta')
            ->assertJsonPath('data.coordinator.id', $record->coordinator_id)->assertJsonPath('data.supervisor.id', $record->company_supervisor_id)
            ->assertJsonPath('data.start_date', '2026-10-01');
        $this->assertSame(['id', 'student', 'company', 'coordinator', 'supervisor', 'position_title', 'status', 'created_at', 'submitted_at',
            'start_date', 'end_date', 'approved_at', 'completed_at', 'description'], array_keys($response->json('data')));
        foreach (['student', 'supervisor', 'coordinator'] as $person) {
            $this->assertSame(['id', 'first_name', 'last_name'], array_keys($response->json('data.'.$person)));
        }
        $this->assertStringNotContainsString('PRIVATE INTERNAL REVIEW', $response->getContent());
        $this->getJson('/api/admin/internships')->assertJsonMissingPath('data.0.description')->assertJsonMissingPath('data.0.decision_comment');
        $this->getJson('/api/admin/internships/999999999')->assertNotFound();
        $this->postJson('/api/admin/internships/'.$record->id, ['status' => 'ACTIVE'])->assertStatus(405);
        $this->patchJson('/api/admin/internships/'.$record->id, ['status' => 'ACTIVE'])->assertStatus(405);
        $this->assertSame('COMPLETED', $record->fresh()->status);
    }

    public function test_listing_query_count_does_not_grow_per_internship(): void
    {
        $record = $this->internship('ACTIVE', true);
        $this->actingAs($this->user()->load('role'));
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $this->getJson('/api/admin/internships')->assertOk();

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        };
        $before = $count();
        for ($i = 0; $i < 5; $i++) {
            $record->replicate()->save();
        }
        $this->assertSame($before, $count());
    }

    #[DataProvider('invalidFilters')]
    public function test_filters_are_validated(string $query): void
    {
        $this->actingAs($this->user());
        $this->getJson('/api/admin/internships?'.$query)->assertUnprocessable();
    }

    public static function invalidFilters(): array
    {
        return [['status=INVALID'], ['per_page=101'], ['per_page=0'], ['page=0'], ['search[]=invalid'], ['search='.str_repeat('a', 256)]];
    }
}
