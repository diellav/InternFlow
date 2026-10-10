<?php

namespace Tests\Feature\Internship;

use App\Models\Company;
use App\Models\Internship;
use App\Models\Role;
use App\Models\User;
use App\Modules\Internship\Services\CoordinatorInternshipService;
use App\Shared\Enums\InternshipStatus;
use App\Shared\Enums\UserRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InternshipReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_assigned_submitted_application_enters_review_only_after_explicit_action(): void
    {
        $coordinator = $this->coordinator();
        $application = $this->application($coordinator, ['status' => 'SUBMITTED']);
        $before = $application->fresh()->getAttributes();
        $this->actingAs($coordinator)->getJson($this->path($application))->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
        $this->assertSame($before, $application->fresh()->getAttributes());
        $this->postJson($this->path($application, 'start-review'))->assertOk()->assertJsonPath('data.status', 'UNDER_REVIEW')->assertJsonPath('data.coordinator_id', $coordinator->id);
        foreach (array_diff(array_keys($before), ['status', 'updated_at']) as $field) {
            $this->assertSame($before[$field], $application->fresh()->getAttributes()[$field]);
        }
        $this->postJson($this->path($application, 'start-review'))->assertConflict();
    }

    public function test_approval_uses_server_timestamp_clears_comment_and_does_not_activate_or_create_workflow_records(): void
    {
        $coordinator = $this->coordinator();
        $application = $this->application($coordinator, ['decision_comment' => 'Old comment']);
        $this->freezeTime();
        $this->actingAs($coordinator)->postJson($this->path($application, 'decision'), ['decision' => 'APPROVED'])->assertOk()
            ->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.approved_at', now()->toIso8601String())
            ->assertJsonPath('data.decision_comment', null)->assertJsonPath('data.completed_at', null);
        $this->assertSame($coordinator->id, $application->fresh()->coordinator_id);
        $this->postJson($this->path($application, 'decision'), ['decision' => 'APPROVED'])->assertConflict();
        foreach (['tasks', 'activity_logs', 'messages', 'final_evaluations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame('application_approved', $application->student->user->notifications()->sole()->data['category']);
    }

    public function test_rejection_and_revision_store_trimmed_reasons_clear_approval_and_are_visible_to_the_student(): void
    {
        $coordinator = $this->coordinator();
        foreach (['REJECTED', 'REVISION_REQUIRED'] as $decision) {
            $application = $this->application($coordinator, ['approved_at' => now()]);
            $this->actingAs($coordinator)->postJson($this->path($application, 'decision'), ['decision' => $decision, 'decision_comment' => "  Explain the required corrections. \n"])->assertOk()
                ->assertJsonPath('data.status', $decision)->assertJsonPath('data.decision_comment', 'Explain the required corrections.')->assertJsonPath('data.approved_at', null);
            $this->actingAs($application->student->user)->getJson('/api/student/internships/'.$application->id)->assertOk()
                ->assertJsonPath('data.status', $decision)->assertJsonPath('data.decision_comment', 'Explain the required corrections.')
                ->assertJsonPath('data.coordinator.first_name', $coordinator->first_name)->assertJsonMissingPath('data.student.password');
            $this->patchJson('/api/student/internships/'.$application->id, ['description' => 'Corrections'])->assertStatus($decision === 'REVISION_REQUIRED' ? 200 : 409);
            $this->postJson('/api/student/internships/'.$application->id.'/submit')->assertConflict();
            $this->actingAs($coordinator)->postJson($this->path($application, 'decision'), ['decision' => $decision, 'decision_comment' => 'Changed'])->assertConflict();
            $this->assertSame('Explain the required corrections.', $application->fresh()->decision_comment);
        }
    }

    public function test_reasons_are_required_strings_with_an_application_limit_and_failures_are_atomic(): void
    {
        $coordinator = $this->coordinator();
        $application = $this->application($coordinator);
        $this->actingAs($coordinator);
        $before = $application->fresh()->getAttributes();
        foreach (['REJECTED', 'REVISION_REQUIRED'] as $decision) {
            foreach ([null, '', " \t\n", ['not a string'], str_repeat('x', 10001)] as $reason) {
                $this->postJson($this->path($application, 'decision'), ['decision' => $decision, 'decision_comment' => $reason])->assertUnprocessable()->assertJsonValidationErrors('decision_comment');
                $this->assertSame($before, $application->fresh()->getAttributes());
            }
            $this->postJson($this->path($application, 'decision'), ['decision' => $decision])->assertUnprocessable();
        }
        $this->postJson($this->path($application, 'decision'), ['decision' => 'REVISION_REQUIRED', 'decision_comment' => '  '.str_repeat('x', 10000).'  '])->assertOk();
        $this->assertSame(10000, strlen($application->fresh()->decision_comment));
    }

    public function test_invalid_decisions_and_protected_fields_cannot_manipulate_records(): void
    {
        $coordinator = $this->coordinator();
        $application = $this->application($coordinator);
        $before = $application->fresh()->getAttributes();
        $this->actingAs($coordinator);
        foreach ([null, 'DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'ACTIVE', 'COMPLETED', 'approved', ['APPROVED']] as $decision) {
            $this->postJson($this->path($application, 'decision'), ['decision' => $decision])->assertUnprocessable()->assertJsonValidationErrors('decision');
        }
        foreach (['student_id', 'coordinator_id', 'reviewer_id', 'status', 'approved_at', 'submitted_at', 'completed_at', 'company_id', 'company_supervisor_id', 'description', 'unknown'] as $field) {
            $this->postJson($this->path($application, 'decision'), ['decision' => 'APPROVED', $field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->postJson($this->path($application, 'start-review'), [$field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson($this->path($application, 'decision'), ['decision' => 'APPROVED', 'decision_comment' => null])->assertUnprocessable();
        $this->assertSame($before, $application->fresh()->getAttributes());
    }

    public function test_transition_matrix_and_stale_service_calls_recheck_database_status(): void
    {
        $coordinator = $this->coordinator();
        $application = $this->application($coordinator);
        $this->actingAs($coordinator);
        foreach (InternshipStatus::cases() as $status) {
            $application->update(['status' => $status->value]);
            if ($status !== InternshipStatus::SUBMITTED) {
                $this->postJson($this->path($application, 'start-review'))->assertConflict();
            }
            if ($status !== InternshipStatus::UNDER_REVIEW) {
                $this->postJson($this->path($application, 'decision'), ['decision' => 'APPROVED'])->assertConflict();
            }
        }
        $application->update(['status' => 'APPROVED']);
        try {
            app(CoordinatorInternshipService::class)->decide($coordinator, $application->id, ['decision' => 'REJECTED', 'decision_comment' => 'Stale']);
            $this->fail('A stale decision must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame('APPROVED', $application->fresh()->status);
    }

    public function test_unassigned_and_other_coordinator_records_are_out_of_scope_even_with_invalid_payloads(): void
    {
        $first = $this->coordinator();
        $second = $this->coordinator();
        foreach ([$this->application(null), $this->application($second)] as $application) {
            $before = $application->fresh()->getAttributes();
            $this->actingAs($first)->postJson($this->path($application, 'start-review'))->assertNotFound();
            $this->postJson($this->path($application, 'decision'), ['decision' => 'APPROVED'])->assertNotFound();
            $this->postJson($this->path($application, 'decision'), ['status' => 'ACTIVE'])->assertNotFound();
            $this->assertSame($before, $application->fresh()->getAttributes());
        }
        $this->postJson('/api/coordinator/internships/999999/start-review')->assertNotFound();
    }

    public function test_review_routes_deny_guests_other_roles_inactive_and_missing_coordinator_profiles(): void
    {
        $coordinator = $this->coordinator();
        $application = $this->application($coordinator);
        $check = function (int $status) use ($application): void {
            $this->postJson($this->path($application, 'start-review'))->assertStatus($status);
            $this->postJson($this->path($application, 'decision'), ['decision' => 'APPROVED'])->assertStatus($status);
        };
        $check(401);
        foreach ([UserRole::ADMIN, UserRole::STUDENT, UserRole::COMPANY_SUPERVISOR] as $role) {
            $this->actingAs($this->user($role));
            $check(403);
        }
        $this->actingAs($this->user(UserRole::ACADEMIC_COORDINATOR));
        $check(403);
        $coordinator->update(['is_active' => false]);
        $this->actingAs($coordinator);
        $check(403);
        $this->assertSame('UNDER_REVIEW', $application->fresh()->status);
    }

    public function test_approval_rechecks_current_company_supervisor_eligibility_and_association_without_partial_writes(): void
    {
        $coordinator = $this->coordinator();
        $application = $this->application($coordinator);
        $this->actingAs($coordinator);
        $check = function (string $field) use ($application): void {
            $before = $application->fresh()->getAttributes();
            $this->postJson($this->path($application, 'decision'), ['decision' => 'APPROVED'])->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertSame($before, $application->fresh()->getAttributes());
        };
        foreach (['PENDING', 'REJECTED'] as $status) {
            $application->company()->update(['verification_status' => $status]);
            $check('company_id');
        }
        $application->company()->update(['verification_status' => 'APPROVED', 'is_active' => false]);
        $check('company_id');
        $application->company()->update(['is_active' => true]);
        $supervisor = $application->companySupervisor->user;
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
        $supervisor->companySupervisorProfile()->update(['company_id' => Company::create(['name' => 'Unrelated'])->id]);
        $check('company_supervisor_id');
        $supervisor->companySupervisorProfile()->update(['company_id' => $application->company_id]);
        $application->update(['company_supervisor_id' => null]);
        $check('company_supervisor_id');
        $application->update(['company_supervisor_id' => $supervisor->id, 'position_title' => '']);
        $check('position_title');
        $application->update(['position_title' => 'Software Intern']);
        $this->postJson($this->path($application, 'decision'), ['decision' => 'APPROVED'])->assertOk();
    }

    public function test_ineligible_company_can_still_be_rejected_or_returned_for_correction(): void
    {
        $coordinator = $this->coordinator();
        foreach (['REJECTED', 'REVISION_REQUIRED'] as $decision) {
            $application = $this->application($coordinator);
            $application->company()->update(['is_active' => false]);
            $this->actingAs($coordinator)->postJson($this->path($application, 'decision'), ['decision' => $decision, 'decision_comment' => 'The company is no longer eligible.'])->assertOk();
        }
    }

    public function test_claim_start_review_decision_and_student_visibility_share_the_same_record(): void
    {
        $coordinator = $this->coordinator();
        $application = $this->application(null, ['status' => 'SUBMITTED']);
        $student = $application->student->user;
        $this->actingAs($coordinator)->postJson($this->path($application, 'claim'))->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
        $this->postJson($this->path($application, 'start-review'))->assertOk()->assertJsonPath('data.status', 'UNDER_REVIEW');
        $this->postJson($this->path($application, 'decision'), ['decision' => 'APPROVED'])->assertOk()->assertJsonPath('data.id', $application->id);
        $this->getJson('/api/coordinator/internships?status=APPROVED')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $application->id);
        $this->actingAs($this->coordinator())->getJson($this->path($application))->assertNotFound();
        $this->getJson('/api/coordinator/internships')->assertJsonCount(0, 'data');
        $this->actingAs($student)->getJson('/api/student/internships/'.$application->id)->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.approved_at', $application->fresh()->approved_at->toIso8601String());
    }

    public function test_inbox_retains_all_assigned_review_outcomes_and_filters_them(): void
    {
        $coordinator = $this->coordinator();
        $other = $this->coordinator();
        foreach (['SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'REVISION_REQUIRED'] as $status) {
            $application = $this->application($coordinator, ['status' => $status]);
            $this->application($other, ['status' => $status]);
            $this->actingAs($coordinator)->getJson('/api/coordinator/internships?status='.$status)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $application->id);
        }
        $this->getJson('/api/coordinator/internships')->assertJsonCount(5, 'data');
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

    private function application(?User $coordinator, array $attributes = []): Internship
    {
        $student = $this->user(UserRole::STUDENT);
        $student->studentProfile()->create(['student_number' => fake()->uuid(), 'study_program' => 'Computer Science', 'study_year' => 2]);
        $company = Company::create(['name' => fake()->company(), 'verification_status' => 'APPROVED']);
        $supervisor = $this->user(UserRole::COMPANY_SUPERVISOR);
        $supervisor->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => 'APPROVED']);

        return Internship::create(['student_id' => $student->id, 'company_id' => $company->id, 'company_supervisor_id' => $supervisor->id, 'coordinator_id' => $coordinator?->id, 'position_title' => 'Software Intern', 'start_date' => '2026-11-01', 'end_date' => '2026-12-01', 'submitted_at' => now(), 'status' => 'UNDER_REVIEW', ...$attributes]);
    }

    private function path(Internship $internship, ?string $action = null): string
    {
        return '/api/coordinator/internships/'.$internship->id.($action ? '/'.$action : '');
    }
}
