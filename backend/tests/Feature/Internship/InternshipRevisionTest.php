<?php

namespace Tests\Feature\Internship;

use App\Models\Company;
use App\Models\Internship;
use App\Models\Role;
use App\Models\User;
use App\Modules\Internship\Services\StudentInternshipService;
use App\Shared\Enums\InternshipStatus;
use App\Shared\Enums\UserRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InternshipRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_owner_saves_all_corrections_without_changing_status_assignment_or_review_metadata(): void
    {
        $application = $this->application(['approved_at' => now()->subDays(2), 'completed_at' => now()->subDay()]);
        $before = $application->fresh()->getAttributes();
        $other = $this->application();
        $payload = ['position_title' => 'Corrected role', 'description' => 'Corrected responsibilities', 'company_id' => $other->company_id, 'company_supervisor_id' => $other->company_supervisor_id, 'start_date' => '2026-12-01', 'end_date' => '2027-02-01'];
        $this->actingAs($application->student->user)->patchJson($this->path($application), $payload)->assertOk()
            ->assertJsonPath('data.id', $application->id)->assertJsonPath('data.status', 'REVISION_REQUIRED')
            ->assertJsonPath('data.decision_comment', 'Clarify your responsibilities.')
            ->assertJsonPath('data.position_title', 'Corrected role')->assertJsonMissingPath('data.student_id');
        $after = $application->fresh()->getAttributes();
        foreach (array_diff(array_keys($before), [...array_keys($payload), 'updated_at']) as $field) {
            $this->assertSame($before[$field], $after[$field], $field);
        }
        $this->assertDatabaseCount('internships', 2);
    }

    public function test_explicit_resubmission_preserves_identity_data_and_coordinator_and_updates_only_current_review_metadata(): void
    {
        $application = $this->application(['description' => 'Saved corrections', 'approved_at' => now()->subDay()]);
        $before = $application->fresh()->getAttributes();
        $this->freezeTime();
        $this->actingAs($application->student->user)->postJson($this->path($application, 'resubmit'))->assertOk()
            ->assertJsonPath('data.id', $application->id)->assertJsonPath('data.status', 'SUBMITTED')
            ->assertJsonPath('data.description', 'Saved corrections')->assertJsonPath('data.decision_comment', null)
            ->assertJsonPath('data.approved_at', null)->assertJsonPath('data.submitted_at', now()->toIso8601String())
            ->assertJsonMissingPath('data.supervisor.password')->assertJsonMissingPath('data.coordinator.email');
        foreach (array_diff(array_keys($before), ['status', 'submitted_at', 'decision_comment', 'approved_at', 'updated_at']) as $field) {
            $this->assertSame($before[$field], $application->fresh()->getAttributes()[$field], $field);
        }
        $this->assertNotSame($before['submitted_at'], $application->fresh()->getAttributes()['submitted_at']);
        $this->postJson($this->path($application, 'resubmit'))->assertConflict();
        $this->patchJson($this->path($application), ['description' => 'Stale edit'])->assertConflict();
        $this->assertDatabaseCount('internships', 1);
        foreach (['tasks', 'activity_logs', 'messages', 'final_evaluations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame('application_submitted', $application->coordinator->user->notifications()->sole()->data['category']);
    }

    public function test_every_other_status_and_direct_stale_service_calls_cannot_resubmit(): void
    {
        $application = $this->application();
        $student = $application->student->user;
        $this->actingAs($student);
        foreach (InternshipStatus::cases() as $status) {
            if ($status === InternshipStatus::REVISION_REQUIRED) {
                continue;
            }
            $application->update(['status' => $status->value]);
            $before = $application->fresh()->getAttributes();
            $this->postJson($this->path($application, 'resubmit'))->assertConflict();
            $this->assertSame($before, $application->fresh()->getAttributes());
        }
        try {
            app(StudentInternshipService::class)->resubmit($student, $application->id);
            $this->fail('Stale state must be rechecked.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_foreign_records_are_hidden_even_when_protected_payloads_are_invalid(): void
    {
        $application = $this->application();
        $before = $application->fresh()->getAttributes();
        $this->actingAs($this->student());
        foreach ([[], ['status' => 'SUBMITTED']] as $payload) {
            $this->patchJson($this->path($application), $payload)->assertNotFound();
            $this->postJson($this->path($application, 'resubmit'), $payload)->assertNotFound();
        }
        $this->postJson('/api/student/internships/999999/resubmit')->assertNotFound();
        $this->assertSame($before, $application->fresh()->getAttributes());
    }

    public function test_guests_other_roles_inactive_and_missing_student_profiles_are_denied(): void
    {
        $application = $this->application();
        $check = function (int $status) use ($application): void {
            $this->patchJson($this->path($application), ['description' => 'Unsafe'])->assertStatus($status);
            $this->postJson($this->path($application, 'resubmit'))->assertStatus($status);
        };
        $check(401);
        foreach ([UserRole::ADMIN, UserRole::ACADEMIC_COORDINATOR, UserRole::COMPANY_SUPERVISOR, UserRole::STUDENT] as $role) {
            $this->actingAs($this->user($role));
            $check(403);
        }
        $student = $application->student->user;
        $student->update(['is_active' => false]);
        $this->actingAs($student);
        $check(403);
        $this->assertSame('REVISION_REQUIRED', $application->fresh()->status);
    }

    public function test_protected_fields_and_any_resubmission_payload_are_rejected_without_partial_writes(): void
    {
        $application = $this->application();
        $before = $application->fresh()->getAttributes();
        $this->actingAs($application->student->user);
        foreach (['id', 'student_id', 'coordinator_id', 'status', 'submitted_at', 'approved_at', 'decision_comment', 'completed_at', 'created_at', 'updated_at'] as $field) {
            $this->patchJson($this->path($application), ['description' => 'Unsafe', $field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->postJson($this->path($application, 'resubmit'), [$field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson($this->path($application, 'resubmit'), ['description' => 'Do not save here'])->assertUnprocessable();
        $this->assertSame($before, $application->fresh()->getAttributes());
    }

    public function test_resubmission_rechecks_all_company_and_supervisor_eligibility_and_is_atomic(): void
    {
        $application = $this->application(['description' => 'Saved corrections']);
        $this->actingAs($application->student->user);
        $check = function (string $field) use ($application): void {
            $before = $application->fresh()->getAttributes();
            $this->postJson($this->path($application, 'resubmit'))->assertUnprocessable()->assertJsonValidationErrors($field);
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
        $application->update(['position_title' => 'Corrected role', 'coordinator_id' => null]);
        $check('coordinator_id');
        $this->assertDatabaseCount('internships', 1);
    }

    public function test_revision_date_and_selection_errors_preserve_previous_corrections_and_instructions(): void
    {
        $application = $this->application(['description' => 'Saved corrections']);
        $before = $application->fresh()->getAttributes();
        $this->actingAs($application->student->user);
        foreach ([['start_date' => '2027-01-01'], ['end_date' => '2026-02-30'], ['position_title' => '  '], ['description' => str_repeat('x', 10001)], ['company_id' => 999999], ['company_supervisor_id' => 999999]] as $payload) {
            $this->patchJson($this->path($application), $payload)->assertUnprocessable();
            $this->assertSame($before, $application->fresh()->getAttributes());
        }
    }

    public function test_multiple_revision_cycles_keep_one_record_in_the_same_coordinators_inbox_until_approval(): void
    {
        $application = $this->application(['status' => 'DRAFT', 'coordinator_id' => null, 'submitted_at' => null, 'decision_comment' => null]);
        $student = $application->student->user;
        $coordinator = $this->coordinator();
        $other = $this->coordinator();
        $coordinatorPath = '/api/coordinator/internships/'.$application->id;
        $this->actingAs($student)->postJson($this->path($application, 'submit'))->assertOk();
        $this->actingAs($coordinator)->postJson($coordinatorPath.'/claim')->assertOk();
        for ($cycle = 1; $cycle <= 3; $cycle++) {
            $this->actingAs($coordinator)->postJson($coordinatorPath.'/start-review')->assertOk()->assertJsonPath('data.status', 'UNDER_REVIEW');
            $this->postJson($coordinatorPath.'/decision', ['decision' => 'REVISION_REQUIRED', 'decision_comment' => 'Correction '.$cycle])->assertOk();
            $this->actingAs($student)->patchJson($this->path($application), ['description' => 'Correction completed '.$cycle])->assertOk()->assertJsonPath('data.decision_comment', 'Correction '.$cycle)->assertJsonPath('data.status', 'REVISION_REQUIRED');
            $this->postJson($this->path($application, 'resubmit'))->assertOk()->assertJsonPath('data.id', $application->id)->assertJsonPath('data.status', 'SUBMITTED')->assertJsonPath('data.decision_comment', null);
            $this->actingAs($coordinator)->getJson('/api/coordinator/internships?status=SUBMITTED')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $application->id)->assertJsonPath('data.0.coordinator_id', $coordinator->id);
            $this->actingAs($other)->getJson('/api/coordinator/internships')->assertOk()->assertJsonCount(0, 'data');
            $this->getJson($coordinatorPath)->assertNotFound();
            $this->postJson($coordinatorPath.'/start-review')->assertNotFound();
            $this->postJson($coordinatorPath.'/decision', ['decision' => 'APPROVED'])->assertNotFound();
            $this->postJson($coordinatorPath.'/claim')->assertConflict();
            $this->assertDatabaseCount('internships', 1);
        }
        $this->actingAs($coordinator)->postJson($coordinatorPath.'/start-review')->assertOk();
        $this->postJson($coordinatorPath.'/decision', ['decision' => 'APPROVED'])->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->actingAs($student)->getJson($this->path($application))->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.description', 'Correction completed 3');
        $this->assertDatabaseCount('internships', 1);
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role->value)->sole()->id]);
    }

    private function student(): User
    {
        $user = $this->user(UserRole::STUDENT);
        $user->studentProfile()->create(['student_number' => fake()->uuid(), 'study_program' => 'Computer Science', 'study_year' => 2]);

        return $user;
    }

    private function coordinator(): User
    {
        $user = $this->user(UserRole::ACADEMIC_COORDINATOR);
        $user->academicCoordinatorProfile()->create(['academic_unit' => 'Engineering']);

        return $user;
    }

    private function application(array $attributes = []): Internship
    {
        $student = $this->student();
        $company = Company::create(['name' => fake()->company(), 'verification_status' => 'APPROVED']);
        $supervisor = $this->user(UserRole::COMPANY_SUPERVISOR);
        $supervisor->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => 'APPROVED']);

        return Internship::create(['student_id' => $student->id, 'company_id' => $company->id, 'company_supervisor_id' => $supervisor->id, 'coordinator_id' => $this->coordinator()->id, 'position_title' => 'Software Intern', 'description' => 'Original responsibilities', 'start_date' => '2026-11-01', 'end_date' => '2026-12-01', 'submitted_at' => now()->subDays(3), 'decision_comment' => 'Clarify your responsibilities.', 'status' => 'REVISION_REQUIRED', ...$attributes]);
    }

    private function path(Internship $application, ?string $action = null): string
    {
        return '/api/student/internships/'.$application->id.($action ? '/'.$action : '');
    }
}
