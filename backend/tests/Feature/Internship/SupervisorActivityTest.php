<?php

namespace Tests\Feature\Internship;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Internship;
use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\InternshipStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupervisorActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_assigned_supervisor_reads_safe_records_without_modifying_any_data(): void
    {
        $parent = $this->internship();
        $activity = $this->activity($parent, ['hours' => '2.50']);
        $originalParent = $parent->fresh()->getAttributes();
        $originalActivity = $activity->fresh()->getAttributes();
        $this->actingAs($parent->companySupervisor->user);
        $this->getJson($this->path($parent))->assertOk()->assertJsonPath('data.0.id', $activity->id)
            ->assertJsonPath('data.0.can_edit', false)->assertJsonPath('total_recorded_hours', '2.50')
            ->assertJsonMissingPath('data.0.internship.student_id')->assertJsonMissingPath('internship.company_supervisor_id');
        $this->getJson('/api/supervisor/activities/'.$activity->id)->assertOk()->assertJsonPath('data.hours', '2.50')
            ->assertJsonPath('data.description', 'Work performed')->assertJsonPath('data.can_edit', false)
            ->assertJsonMissingPath('data.updated_at')->assertJsonMissingPath('data.review_status');
        $this->assertSame($originalParent, $parent->fresh()->getAttributes());
        $this->assertSame($originalActivity, $activity->fresh()->getAttributes());
    }

    public function test_total_is_exact_ignores_nulls_and_is_independent_of_pagination_and_other_internships(): void
    {
        $parent = $this->internship();
        $old = $this->activity($parent, ['activity_date' => '2026-10-01', 'hours' => '0.10']);
        $middle = $this->activity($parent, ['hours' => '0.20']);
        $new = $this->activity($parent, ['hours' => null]);
        $other = $this->internship(['company_supervisor_id' => $parent->company_supervisor_id, 'company_id' => $parent->company_id]);
        $this->activity($other, ['hours' => '24.00']);
        $this->actingAs($parent->companySupervisor->user);
        foreach ([$new, $middle, $old] as $index => $activity) {
            $this->getJson($this->path($parent).'?per_page=1&page='.($index + 1))->assertOk()
                ->assertJsonPath('data.0.id', $activity->id)->assertJsonPath('meta.total', 3)->assertJsonPath('total_recorded_hours', '0.30');
        }
        $this->getJson('/api/supervisor/activities/'.$new->id)->assertJsonPath('data.hours', null);
        $empty = $this->internship(['company_supervisor_id' => $parent->company_supervisor_id, 'company_id' => $parent->company_id]);
        $this->getJson($this->path($empty))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('total_recorded_hours', '0.00');
        $this->activity($empty);
        $this->getJson($this->path($empty))->assertOk()->assertJsonPath('total_recorded_hours', '0.00')->assertJsonPath('data.0.hours', null);
    }

    public function test_foreign_supervisors_in_same_or_different_company_cannot_access_records(): void
    {
        $parent = $this->internship();
        $activity = $this->activity($parent);
        foreach ([$parent->company_id, Company::create(['name' => 'Other', 'is_active' => true, 'verification_status' => 'APPROVED'])->id] as $companyId) {
            $foreign = $this->supervisor($companyId);
            $this->actingAs($foreign)->getJson($this->path($parent).'?per_page=bad')->assertNotFound();
            $this->getJson('/api/supervisor/activities/'.$activity->id)->assertNotFound();
        }
        $this->getJson('/api/supervisor/activities/999999')->assertNotFound();
        $this->getJson('/api/supervisor/internships/999999/activities')->assertNotFound();
    }

    public function test_guests_wrong_roles_and_missing_supervisor_profiles_are_denied(): void
    {
        $parent = $this->internship();
        $activity = $this->activity($parent);
        $this->reads($parent, $activity, 401);
        foreach (['STUDENT', 'ADMIN', 'ACADEMIC_COORDINATOR', 'COMPANY_SUPERVISOR'] as $role) {
            $this->actingAs($this->user($role));
            $this->reads($parent, $activity, 403);
        }
    }

    public function test_inactive_unapproved_supervisors_and_ineligible_companies_are_denied(): void
    {
        $parent = $this->internship();
        $activity = $this->activity($parent);
        $supervisor = $parent->companySupervisor->user;
        foreach (['PENDING', 'REJECTED'] as $status) {
            $supervisor->companySupervisorProfile()->update(['verification_status' => $status]);
            $this->actingAs($supervisor->fresh());
            $this->reads($parent, $activity, 403);
        }
        $supervisor->companySupervisorProfile()->update(['verification_status' => 'APPROVED']);
        $supervisor->update(['is_active' => false]);
        $this->actingAs($supervisor->fresh());
        $this->reads($parent, $activity, 403);
        $supervisor->update(['is_active' => true]);
        foreach ([['verification_status' => 'PENDING'], ['verification_status' => 'REJECTED'], ['verification_status' => 'APPROVED', 'is_active' => false]] as $attributes) {
            $parent->company()->update($attributes);
            $this->actingAs($supervisor->fresh());
            $this->reads($parent, $activity, 403);
        }
    }

    public function test_all_nonactive_states_and_mismatched_company_assignments_are_hidden(): void
    {
        $parent = $this->internship();
        $activity = $this->activity($parent);
        $this->actingAs($parent->companySupervisor->user);
        foreach (InternshipStatus::cases() as $status) {
            if ($status->value !== 'ACTIVE') {
                $parent->update(['status' => $status->value]);
                $this->reads($parent, $activity, 404);
            }
        }
        $otherCompany = Company::create(['name' => 'Wrong company', 'verification_status' => 'APPROVED']);
        $parent->update(['status' => 'ACTIVE', 'company_id' => $otherCompany->id]);
        $this->reads($parent, $activity, 404);
        $parent->update(['company_supervisor_id' => null]);
        $this->reads($parent, $activity, 404);
    }

    public function test_supervisor_has_no_activity_write_endpoints_or_student_write_access(): void
    {
        $parent = $this->internship();
        $activity = $this->activity($parent);
        $before = $activity->fresh()->getAttributes();
        $this->actingAs($parent->companySupervisor->user);
        foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
            $this->json($method, $this->path($parent), ['title' => 'Unsafe'])->assertMethodNotAllowed();
            $this->json($method, '/api/supervisor/activities/'.$activity->id, ['title' => 'Unsafe'])->assertMethodNotAllowed();
        }
        $this->postJson('/api/student/internships/'.$parent->id.'/activities', [])->assertForbidden();
        $this->patchJson('/api/student/activities/'.$activity->id, ['title' => 'Unsafe'])->assertForbidden();
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertSame($before, $activity->fresh()->getAttributes());
    }

    public function test_pagination_validation_and_query_count_are_constant(): void
    {
        $parent = $this->internship();
        $this->activity($parent);
        $this->actingAs($parent->companySupervisor->user->load(['role', 'companySupervisorProfile.company']));
        $this->getJson($this->path($parent).'?page=0&per_page=101')->assertUnprocessable();
        $count = function () use ($parent): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->getJson($this->path($parent))->assertOk();

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        };
        $small = $count();
        for ($i = 0; $i < 5; $i++) {
            $this->activity($parent);
        }
        $this->assertSame($small, $count());
    }

    private function reads(Internship $parent, ActivityLog $activity, int $status): void
    {
        $this->getJson($this->path($parent))->assertStatus($status);
        $this->getJson('/api/supervisor/activities/'.$activity->id)->assertStatus($status);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->sole()->id, 'is_active' => true]);
    }

    private function supervisor(int $companyId): User
    {
        $user = $this->user('COMPANY_SUPERVISOR');
        $user->companySupervisorProfile()->create(['company_id' => $companyId, 'verification_status' => 'APPROVED']);

        return $user;
    }

    private function internship(array $attributes = []): Internship
    {
        $student = $this->user('STUDENT');
        $student->studentProfile()->create(['student_number' => fake()->uuid(), 'study_program' => 'CS', 'study_year' => 2]);
        $company = Company::create(['name' => fake()->company(), 'is_active' => true, 'verification_status' => 'APPROVED']);
        $supervisor = $this->supervisor($company->id);

        return Internship::create(['student_id' => $student->id, 'company_id' => $company->id, 'company_supervisor_id' => $supervisor->companySupervisorProfile->getKey(),
            'position_title' => 'Activity internship', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'ACTIVE', ...$attributes]);
    }

    private function activity(Internship $parent, array $attributes = []): ActivityLog
    {
        return $parent->activityLogs()->create(['title' => 'Diary', 'description' => 'Work performed', 'activity_date' => '2026-10-09', ...$attributes]);
    }

    private function path(Internship $parent): string
    {
        return '/api/supervisor/internships/'.$parent->id.'/activities';
    }
}
