<?php

namespace Tests\Feature\Internship;

use App\Models\Company;
use App\Models\Internship;
use App\Models\Role;
use App\Models\User;
use App\Modules\Internship\Services\CoordinatorInternshipService;
use App\Shared\Enums\UserRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InternshipSubmissionAndInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_submission_preserves_the_record_and_sets_server_time_without_assignment_or_side_effects(): void
    {
        $draft = $this->draft();
        $before = $draft->fresh()->getAttributes();
        $this->freezeTime();
        $this->actingAs($draft->student->user)->postJson($this->submitPath($draft))->assertOk()
            ->assertJsonPath('data.id', $draft->id)->assertJsonPath('data.status', 'SUBMITTED')
            ->assertJsonPath('data.submitted_at', now()->toIso8601String())->assertJsonPath('data.coordinator', null)
            ->assertJsonMissingPath('data.student_id');
        $updated = $draft->fresh();
        foreach (['student_id', 'company_id', 'company_supervisor_id', 'position_title', 'description', 'start_date', 'end_date'] as $field) {
            $this->assertSame($before[$field], $updated->getAttributes()[$field]);
        }
        $this->assertNull($updated->coordinator_id);
        $this->postJson($this->submitPath($draft))->assertConflict();
        $this->patchJson('/api/student/internships/'.$draft->id, ['description' => 'Unsafe'])->assertConflict();
        foreach (['tasks', 'activity_logs', 'final_evaluations', 'messages', 'notifications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_all_non_draft_statuses_cannot_be_submitted(): void
    {
        $draft = $this->draft();
        $this->actingAs($draft->student->user);
        foreach (['SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE', 'COMPLETED', 'REJECTED', 'REVISION_REQUIRED'] as $status) {
            $draft->update(['status' => $status]);
            $before = $draft->fresh()->getAttributes();
            $this->postJson($this->submitPath($draft))->assertConflict();
            $this->assertSame($before, $draft->fresh()->getAttributes());
        }
    }

    public function test_draft_submission_shared_inbox_claim_and_student_details_form_one_workflow(): void
    {
        $draft = $this->draft();
        $student = $draft->student->user;
        $first = $this->coordinator();
        $second = $this->coordinator();
        $this->actingAs($first)->getJson('/api/coordinator/internships')->assertJsonCount(0, 'data');
        $this->actingAs($student)->postJson($this->submitPath($draft))->assertOk();
        foreach ([$first, $second] as $coordinator) {
            $this->actingAs($coordinator)->getJson('/api/coordinator/internships')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $draft->id);
        }
        $this->actingAs($first)->postJson($this->claimPath($draft))->assertOk()->assertJsonPath('data.coordinator_id', $first->id);
        $this->actingAs($second)->getJson('/api/coordinator/internships')->assertJsonCount(0, 'data');
        $this->getJson('/api/coordinator/internships/'.$draft->id)->assertNotFound();
        $this->actingAs($student)->getJson('/api/student/internships/'.$draft->id)->assertOk()
            ->assertJsonPath('data.status', 'SUBMITTED')->assertJsonPath('data.coordinator.first_name', $first->first_name);
        $this->postJson($this->submitPath($draft))->assertConflict();
        $this->assertSame($first->id, $draft->fresh()->coordinator_id);
    }

    public function test_submission_requires_complete_verified_active_and_associated_information_atomically(): void
    {
        $draft = $this->draft();
        $supervisor = $draft->companySupervisor->user;
        $this->actingAs($draft->student->user);
        $check = function (string $field) use ($draft): void {
            $before = $draft->fresh()->getAttributes();
            $this->postJson($this->submitPath($draft))->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertSame($before, $draft->fresh()->getAttributes());
            $this->assertSame('DRAFT', $draft->fresh()->status);
        };
        $draft->update(['company_supervisor_id' => null]);
        $check('company_supervisor_id');
        $draft->update(['company_supervisor_id' => $supervisor->id, 'position_title' => '']);
        $check('position_title');
        $draft->update(['position_title' => 'Software Intern']);
        foreach (['PENDING', 'REJECTED'] as $status) {
            $draft->company()->update(['verification_status' => $status]);
            $check('company_id');
        }
        $draft->company()->update(['verification_status' => 'APPROVED', 'is_active' => false]);
        $check('company_id');
        $draft->company()->update(['is_active' => true]);
        foreach (['PENDING', 'REJECTED'] as $status) {
            $supervisor->companySupervisorProfile()->update(['verification_status' => $status]);
            $check('company_supervisor_id');
        }
        $supervisor->companySupervisorProfile()->update(['verification_status' => 'APPROVED']);
        $supervisor->update(['is_active' => false]);
        $check('company_supervisor_id');
        $supervisor->update(['is_active' => true, 'role_id' => Role::where('name', 'STUDENT')->sole()->id]);
        $check('company_supervisor_id');
        $supervisor->update(['role_id' => Role::where('name', 'COMPANY_SUPERVISOR')->sole()->id]);
        $otherCompany = Company::create(['name' => 'Other']);
        $supervisor->companySupervisorProfile()->update(['company_id' => $otherCompany->id]);
        $check('company_supervisor_id');
        $supervisor->companySupervisorProfile()->update(['company_id' => $draft->company_id]);
        $this->postJson($this->submitPath($draft))->assertOk();
    }

    public function test_submission_denies_guests_other_roles_inactive_missing_profiles_and_foreign_records(): void
    {
        $draft = $this->draft();
        $this->postJson($this->submitPath($draft))->assertUnauthorized();
        foreach ([UserRole::ADMIN, UserRole::ACADEMIC_COORDINATOR, UserRole::COMPANY_SUPERVISOR] as $role) {
            $this->actingAs($this->user($role))->postJson($this->submitPath($draft))->assertForbidden();
        }
        $this->actingAs($this->user(UserRole::STUDENT))->postJson($this->submitPath($draft))->assertForbidden();
        $owner = $draft->student->user;
        $owner->update(['is_active' => false]);
        $this->actingAs($owner)->postJson($this->submitPath($draft))->assertForbidden();
        $other = $this->draft()->student->user;
        $this->actingAs($other)->postJson($this->submitPath($draft))->assertNotFound();
        $this->postJson($this->submitPath($draft), ['status' => 'APPROVED'])->assertNotFound();
        $this->assertSame('DRAFT', $draft->fresh()->status);
    }

    public function test_submission_and_claim_reject_every_client_field_including_null_protected_fields(): void
    {
        $draft = $this->draft();
        $this->actingAs($draft->student->user);
        foreach (['student_id', 'status', 'submitted_at', 'coordinator_id', 'company_id', 'position_title', 'approved_at', 'unknown'] as $field) {
            $this->postJson($this->submitPath($draft), [$field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $draft->update(['status' => 'SUBMITTED']);
        $this->actingAs($this->coordinator());
        foreach (['coordinator_id', 'status', 'student_id', 'submitted_at', 'unknown'] as $field) {
            $this->postJson($this->claimPath($draft), [$field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertNull($draft->fresh()->coordinator_id);
    }

    public function test_shared_inbox_and_assigned_visibility_exclude_drafts_and_unrelated_records(): void
    {
        $first = $this->coordinator();
        $second = $this->coordinator();
        $shared = $this->draft(['status' => 'SUBMITTED']);
        $owned = $this->draft(['status' => 'UNDER_REVIEW', 'coordinator_id' => $first->id]);
        $foreign = $this->draft(['status' => 'SUBMITTED', 'coordinator_id' => $second->id]);
        $draft = $this->draft(['coordinator_id' => $first->id]);
        $unassignedOtherStatus = $this->draft(['status' => 'REJECTED']);
        $this->actingAs($first)->getJson('/api/coordinator/internships')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $owned->id)->assertJsonPath('data.1.id', $shared->id);
        $this->getJson('/api/coordinator/internships/'.$shared->id)->assertOk()->assertJsonPath('data.coordinator_id', null);
        $this->getJson('/api/coordinator/internships/'.$owned->id)->assertOk();
        foreach ([$foreign, $draft, $unassignedOtherStatus] as $hidden) {
            $this->getJson('/api/coordinator/internships/'.$hidden->id)->assertNotFound();
        }
        $this->actingAs($second)->getJson('/api/coordinator/internships/'.$shared->id)->assertOk();
        $this->getJson('/api/coordinator/internships/'.$owned->id)->assertNotFound();
    }

    public function test_claim_assigns_only_the_actor_preserves_status_and_excludes_other_coordinators(): void
    {
        $first = $this->coordinator();
        $second = $this->coordinator();
        $application = $this->draft(['status' => 'SUBMITTED', 'submitted_at' => now()]);
        $before = $application->fresh()->getAttributes();
        $this->actingAs($first)->postJson($this->claimPath($application))->assertOk()
            ->assertJsonPath('data.coordinator_id', $first->id)->assertJsonPath('data.status', 'SUBMITTED')
            ->assertJsonPath('data.student.study_program', 'Computer Science')->assertJsonMissingPath('data.student.email')
            ->assertJsonMissingPath('data.student.password')->assertJsonMissingPath('data.company.verification_note')
            ->assertJsonMissingPath('data.supervisor.review_comment')->assertJsonMissingPath('data.supervisor.email');
        foreach (['student_id', 'company_id', 'company_supervisor_id', 'status', 'submitted_at'] as $field) {
            $this->assertSame($before[$field], $application->fresh()->getAttributes()[$field]);
        }
        $this->postJson($this->claimPath($application))->assertConflict();
        $this->actingAs($second)->postJson($this->claimPath($application))->assertConflict();
        $this->getJson('/api/coordinator/internships/'.$application->id)->assertNotFound();
        $this->getJson('/api/coordinator/internships')->assertJsonCount(0, 'data');
        try {
            app(CoordinatorInternshipService::class)->claim($second, $application->id);
            $this->fail('A stale claim must recheck assignment.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame($first->id, $application->fresh()->coordinator_id);
        $this->actingAs($first)->getJson('/api/coordinator/internships/'.$application->id)->assertOk();
    }

    public function test_only_unassigned_submitted_records_can_be_claimed(): void
    {
        $this->actingAs($this->coordinator());
        foreach (['DRAFT', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE', 'COMPLETED', 'REJECTED', 'REVISION_REQUIRED'] as $status) {
            $application = $this->draft(['status' => $status]);
            $this->postJson($this->claimPath($application))->assertStatus($status === 'DRAFT' ? 404 : 409);
            $this->assertNull($application->fresh()->coordinator_id);
        }
        $this->postJson('/api/coordinator/internships/999999/claim')->assertNotFound();
    }

    public function test_coordinator_routes_deny_guests_other_roles_inactive_and_missing_profiles(): void
    {
        $application = $this->draft(['status' => 'SUBMITTED']);
        $check = function (int $status) use ($application): void {
            $this->getJson('/api/coordinator/internships')->assertStatus($status);
            $this->getJson('/api/coordinator/internships/'.$application->id)->assertStatus($status);
            $this->postJson($this->claimPath($application))->assertStatus($status);
        };
        $check(401);
        foreach ([UserRole::ADMIN, UserRole::STUDENT, UserRole::COMPANY_SUPERVISOR] as $role) {
            $this->actingAs($this->user($role));
            $check(403);
        }
        $this->actingAs($this->user(UserRole::ACADEMIC_COORDINATOR));
        $check(403);
        $inactive = $this->coordinator();
        $inactive->update(['is_active' => false]);
        $this->actingAs($inactive);
        $check(403);
        $this->assertNull($application->fresh()->coordinator_id);
    }

    public function test_inbox_search_filters_pagination_and_ordering_are_scoped_and_literal(): void
    {
        $coordinator = $this->coordinator();
        $first = $this->draft(['status' => 'SUBMITTED', 'position_title' => 'Developer 100%', 'created_at' => '2026-01-01']);
        $second = $this->draft(['status' => 'APPROVED', 'coordinator_id' => $coordinator->id, 'created_at' => '2026-01-01']);
        $this->draft(['status' => 'SUBMITTED', 'coordinator_id' => $this->coordinator()->id]);
        $first->student->user->update(['first_name' => 'Unique', 'last_name' => 'Student']);
        $first->company->update(['name' => 'Acme Testing']);
        $this->actingAs($coordinator)->getJson('/api/coordinator/internships?per_page=1')->assertOk()
            ->assertJsonPath('data.0.id', $second->id)->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/coordinator/internships?per_page=1&page=2')->assertJsonPath('data.0.id', $first->id);
        foreach (['unique student', 'acme testing', 'developer', '100%', '0'] as $search) {
            $this->getJson('/api/coordinator/internships?search='.urlencode($search))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
        }
        $this->getJson('/api/coordinator/internships?search=_')->assertJsonCount(0, 'data');
        $this->getJson('/api/coordinator/internships?status=APPROVED')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->id);
        $this->getJson('/api/coordinator/internships?status=DRAFT')->assertJsonCount(0, 'data');
        $this->getJson('/api/coordinator/internships?status=invalid&per_page=101&page=0')->assertUnprocessable();
        $this->getJson('/api/coordinator/internships?search='.str_repeat('x', 256))->assertUnprocessable();
    }

    public function test_inbox_eager_loading_has_constant_query_count(): void
    {
        $coordinator = $this->coordinator();
        $this->draft(['status' => 'SUBMITTED']);
        $this->actingAs($coordinator->load('role'));
        $count = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->getJson('/api/coordinator/internships')->assertOk();

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        };
        $small = $count();
        for ($i = 0; $i < 3; $i++) {
            $this->draft(['status' => 'SUBMITTED']);
        }
        $this->assertSame($small, $count());
    }

    public function test_created_and_edited_draft_reaches_approval_or_terminal_rejection_through_explicit_endpoints(): void
    {
        foreach (['APPROVED', 'REJECTED'] as $decision) {
            $fixture = $this->draft();
            $student = $fixture->student->user;
            $coordinator = $this->coordinator();
            $count = Internship::count();
            $payload = $fixture->only(['company_id', 'company_supervisor_id', 'position_title']);
            $payload += ['start_date' => '2026-11-01', 'end_date' => '2026-12-01'];
            $created = $this->actingAs($student)->postJson('/api/student/internships', $payload)->assertCreated()
                ->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.approved_at', null);
            $id = $created->json('data.id');
            $studentPath = '/api/student/internships/'.$id;
            $coordinatorPath = '/api/coordinator/internships/'.$id;
            $this->patchJson($studentPath, ['description' => 'Edited draft responsibilities'])->assertOk()->assertJsonPath('data.status', 'DRAFT');
            $this->postJson($studentPath.'/submit')->assertOk()->assertJsonPath('data.status', 'SUBMITTED')->assertJsonPath('data.coordinator', null);
            $this->actingAs($coordinator)->postJson($coordinatorPath.'/claim')->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
            $this->postJson($coordinatorPath.'/start-review')->assertOk()->assertJsonPath('data.status', 'UNDER_REVIEW');
            $data = ['decision' => $decision];
            if ($decision === 'REJECTED') {
                $data['decision_comment'] = 'Academic requirements are not met.';
            }
            $this->postJson($coordinatorPath.'/decision', $data)->assertOk()->assertJsonPath('data.status', $decision);
            $this->actingAs($student)->getJson($studentPath)->assertOk()->assertJsonPath('data.id', $id)
                ->assertJsonPath('data.status', $decision)->assertJsonPath('data.description', 'Edited draft responsibilities')
                ->assertJsonPath('data.decision_comment', $data['decision_comment'] ?? null)->assertJsonPath('data.completed_at', null);
            $this->patchJson($studentPath, ['description' => 'Cannot edit'])->assertConflict();
            $this->postJson($studentPath.'/submit')->assertConflict();
            $this->postJson($studentPath.'/resubmit')->assertConflict();
            $this->assertSame($count + 1, Internship::count());
            $application = Internship::findOrFail($id);
            $this->assertSame($student->id, $application->student_id);
            $this->assertSame($coordinator->id, $application->coordinator_id);
            $this->assertSame($decision === 'APPROVED', $application->approved_at !== null);
        }
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role->value)->sole()->id]);
    }

    private function coordinator(): User
    {
        $user = $this->user(UserRole::ACADEMIC_COORDINATOR);
        $user->academicCoordinatorProfile()->create(['academic_unit' => 'Engineering']);

        return $user;
    }

    private function draft(array $attributes = []): Internship
    {
        $student = $this->user(UserRole::STUDENT);
        $student->studentProfile()->create(['student_number' => fake()->uuid(), 'study_program' => 'Computer Science', 'study_year' => 2]);
        $company = Company::create(['name' => fake()->company(), 'verification_status' => 'APPROVED']);
        $supervisor = $this->user(UserRole::COMPANY_SUPERVISOR);
        $supervisor->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => 'APPROVED']);

        return Internship::create(['student_id' => $student->id, 'company_id' => $company->id, 'company_supervisor_id' => $supervisor->id, 'position_title' => 'Software Intern', 'start_date' => '2026-11-01', 'end_date' => '2026-12-01', ...$attributes]);
    }

    private function submitPath(Internship $internship): string
    {
        return '/api/student/internships/'.$internship->id.'/submit';
    }

    private function claimPath(Internship $internship): string
    {
        return '/api/coordinator/internships/'.$internship->id.'/claim';
    }
}
