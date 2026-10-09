<?php

namespace Tests\Feature\Internship;

use App\Models\Company;
use App\Models\Internship;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Modules\Internship\Services\SupervisorInternshipService;
use App\Modules\Task\Services\TaskService;
use App\Shared\Enums\InternshipStatus;
use App\Shared\Enums\TaskStatus;
use App\Shared\Enums\UserRole;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SupervisorOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
    }

    public function test_assigned_supervisor_explicitly_activates_and_preserves_all_metadata(): void
    {
        $application = $this->application();
        $before = $application->fresh()->getAttributes();
        $this->actingAs($application->companySupervisor->user)->getJson($this->path($application))->assertOk()->assertJsonPath('data.can_activate', true)->assertJsonPath('data.server_date', '2026-10-08');
        $this->assertSame($before, $application->fresh()->getAttributes());
        $this->postJson($this->path($application, 'activate'))->assertOk()->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.can_activate', false)->assertJsonPath('data.can_create_tasks', true);
        foreach (array_diff(array_keys($before), ['status', 'updated_at']) as $field) {
            $this->assertSame($before[$field], $application->fresh()->getAttributes()[$field], $field);
        }
        $this->postJson($this->path($application, 'activate'))->assertConflict();
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('internships', 1);
    }

    public function test_activation_date_window_is_inclusive_and_uses_configured_timezone(): void
    {
        $application = $this->application();
        $this->actingAs($application->companySupervisor->user);
        foreach ([['2026-10-09', '2026-10-10'], ['2026-10-01', '2026-10-07']] as [$start, $end]) {
            $application->update(['start_date' => $start, 'end_date' => $end]);
            $before = $application->fresh()->getAttributes();
            $this->getJson($this->path($application))->assertJsonPath('data.can_activate', false);
            $this->postJson($this->path($application, 'activate'))->assertUnprocessable()->assertJsonValidationErrors('internship');
            $this->assertSame($before, $application->fresh()->getAttributes());
        }
        foreach ([['2026-10-08', '2026-10-10'], ['2026-10-01', '2026-10-08']] as [$start, $end]) {
            $application->refresh()->update(['status' => 'APPROVED', 'start_date' => $start, 'end_date' => $end]);
            $this->postJson($this->path($application, 'activate'))->assertOk();
        }
        config(['app.timezone' => 'Pacific/Auckland']);
        $this->travelTo(Carbon::parse('2026-10-08 23:30:00', 'UTC'));
        $application->refresh()->update(['status' => 'APPROVED', 'start_date' => '2026-10-09', 'end_date' => '2026-10-09']);
        $this->getJson($this->path($application))->assertJsonPath('data.server_date', '2026-10-09')->assertJsonPath('data.can_activate', true);
        $this->postJson($this->path($application, 'activate'))->assertOk();
    }

    public function test_activation_rejects_all_other_statuses_missing_approval_and_protected_fields(): void
    {
        $application = $this->application();
        $this->actingAs($application->companySupervisor->user);
        foreach (InternshipStatus::cases() as $status) {
            if ($status->value === 'APPROVED') {
                continue;
            }
            $application->update(['status' => $status->value]);
            $this->postJson($this->path($application, 'activate'))->assertConflict();
        }
        $application->update(['status' => 'APPROVED', 'approved_at' => null]);
        $this->postJson($this->path($application, 'activate'))->assertUnprocessable();
        $application->update(['approved_at' => now()]);
        $before = $application->fresh()->getAttributes();
        foreach (['status', 'activated_at', 'student_id', 'coordinator_id', 'approved_at', 'start_date', 'company_supervisor_id', 'unknown'] as $field) {
            $this->postJson($this->path($application, 'activate'), [$field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame($before, $application->fresh()->getAttributes());
    }

    public function test_supervisor_scope_uses_profile_user_id_even_for_another_supervisor_in_the_same_company(): void
    {
        $application = $this->application(['status' => 'ACTIVE']);
        $other = $this->supervisor($application->company);
        $task = $this->task($application);
        $this->actingAs($other)->getJson('/api/supervisor/internships')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->path($application))->assertNotFound();
        $this->postJson($this->path($application, 'activate'), ['status' => 'ACTIVE'])->assertNotFound();
        $this->getJson($this->path($application, 'tasks'))->assertNotFound();
        $this->postJson($this->path($application, 'tasks'), ['status' => 'ASSIGNED'])->assertNotFound();
        $this->getJson('/api/supervisor/tasks/'.$task->id)->assertNotFound();
        $this->patchJson('/api/supervisor/tasks/'.$task->id, ['status' => 'ASSIGNED'])->assertNotFound();
        $this->assertSame('Original task', $task->fresh()->title);
    }

    public function test_supervisor_listing_search_filters_pagination_and_safe_resources(): void
    {
        $first = $this->application(['position_title' => 'Developer 100%', 'created_at' => '2026-01-01']);
        $second = $this->application(['company_id' => $first->company_id, 'company_supervisor_id' => $first->company_supervisor_id, 'status' => 'ACTIVE', 'created_at' => '2026-01-01']);
        $this->application();
        $first->student->user->update(['first_name' => 'Unique', 'last_name' => 'Student']);
        $this->actingAs($first->companySupervisor->user)->getJson('/api/supervisor/internships?per_page=1')->assertOk()->assertJsonPath('data.0.id', $second->id)->assertJsonPath('meta.total', 2);
        $this->getJson('/api/supervisor/internships?per_page=1&page=2')->assertJsonPath('data.0.id', $first->id);
        foreach (['unique student', 'developer', '100%', '0'] as $search) {
            $this->getJson('/api/supervisor/internships?search='.urlencode($search))->assertOk()->assertJsonCount(1, 'data');
        }
        $this->getJson('/api/supervisor/internships?search=_')->assertJsonCount(0, 'data');
        $this->getJson('/api/supervisor/internships?status=ACTIVE')->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.student.email')->assertJsonMissingPath('data.0.company.verification_note');
        $this->getJson('/api/supervisor/internships?status=invalid&per_page=101&page=0')->assertUnprocessable();
    }

    public function test_all_operational_routes_deny_guests_other_roles_inactive_and_missing_profiles(): void
    {
        $application = $this->application(['status' => 'ACTIVE']);
        $task = $this->task($application);
        $check = function (int $status) use ($application, $task): void {
            $this->getJson('/api/supervisor/internships')->assertStatus($status);
            $this->getJson($this->path($application))->assertStatus($status);
            $this->postJson($this->path($application, 'activate'))->assertStatus($status);
            $this->getJson($this->path($application, 'tasks'))->assertStatus($status);
            $this->postJson($this->path($application, 'tasks'), $this->payload())->assertStatus($status);
            $this->getJson('/api/supervisor/tasks/'.$task->id)->assertStatus($status);
            $this->patchJson('/api/supervisor/tasks/'.$task->id, ['title' => 'Unsafe'])->assertStatus($status);
        };
        $check(401);
        foreach ([UserRole::ADMIN, UserRole::STUDENT, UserRole::ACADEMIC_COORDINATOR, UserRole::COMPANY_SUPERVISOR] as $role) {
            $this->actingAs($this->user($role));
            $check(403);
        }
        $supervisor = $application->companySupervisor->user;
        $supervisor->update(['is_active' => false]);
        $this->actingAs($supervisor->fresh());
        $check(403);
    }

    public function test_pending_rejected_or_inactive_company_and_supervisor_are_denied_and_fresh_service_checks_enforce_it(): void
    {
        $application = $this->application();
        $supervisor = $application->companySupervisor->user;
        foreach (['PENDING', 'REJECTED'] as $status) {
            $supervisor->companySupervisorProfile()->update(['verification_status' => $status]);
            $this->actingAs($supervisor->fresh())->getJson($this->path($application))->assertForbidden();
            $this->postJson($this->path($application, 'activate'))->assertForbidden();
        }
        $supervisor->companySupervisorProfile()->update(['verification_status' => 'APPROVED']);
        foreach (['PENDING', 'REJECTED'] as $status) {
            $application->company()->update(['verification_status' => $status]);
            $this->actingAs($supervisor->fresh())->getJson('/api/supervisor/internships')->assertForbidden();
        }
        $application->company()->update(['verification_status' => 'APPROVED', 'is_active' => false]);
        $this->actingAs($supervisor->fresh())->postJson($this->path($application, 'activate'))->assertForbidden();
        try {
            app(SupervisorInternshipService::class)->activate($supervisor, $application->id);
            $this->fail('Fresh company state must be checked.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('APPROVED', $application->fresh()->status);
    }

    public function test_active_internship_task_creation_sets_relationships_and_initial_status_server_side(): void
    {
        $application = $this->application(['status' => 'ACTIVE']);
        $supervisor = $application->companySupervisor->user;
        $response = $this->actingAs($supervisor)->postJson($this->path($application, 'tasks'), $this->payload())->assertCreated()
            ->assertJsonPath('data.status', 'ASSIGNED')->assertJsonPath('data.can_edit', true)->assertJsonPath('data.internship.id', $application->id)
            ->assertJsonMissingPath('data.internship.student.email')->assertJsonMissingPath('data.submissions');
        $this->assertDatabaseHas('tasks', ['id' => $response->json('data.id'), 'assigned_by' => $supervisor->id, 'internship_id' => $application->id, 'progress_percent' => null]);
        $this->assertDatabaseCount('task_submissions', 0);
        $this->assertDatabaseCount('task_feedback', 0);
    }

    public function test_nonactive_statuses_and_invalid_or_protected_task_fields_are_rejected(): void
    {
        $application = $this->application();
        $this->actingAs($application->companySupervisor->user);
        foreach (InternshipStatus::cases() as $status) {
            if ($status->value !== 'ACTIVE') {
                $application->update(['status' => $status->value]);
                $this->postJson($this->path($application, 'tasks'), $this->payload())->assertConflict();
            }
        }
        $application->update(['status' => 'ACTIVE']);
        foreach (['internship_id', 'assigned_by', 'student_id', 'supervisor_id', 'creator_id', 'status', 'progress_percent', 'submission_text', 'feedback', 'id', 'created_at'] as $field) {
            $this->postJson($this->path($application, 'tasks'), [...$this->payload(), $field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach ([[], ['title' => '  '], ['description' => null], ['title' => str_repeat('x', 256)], ['description' => str_repeat('x', 10001)], ['priority' => 'URGENT'], ['due_date' => '2026-02-30']] as $invalid) {
            $this->postJson($this->path($application, 'tasks'), $invalid === [] ? [] : [...$this->payload(), ...$invalid])->assertUnprocessable();
        }
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_task_editing_is_partial_preserves_metadata_and_cannot_manipulate_status(): void
    {
        $application = $this->application(['status' => 'ACTIVE']);
        $task = $this->task($application);
        $before = $task->fresh()->getAttributes();
        $this->actingAs($application->companySupervisor->user)->patchJson('/api/supervisor/tasks/'.$task->id, ['title' => 'Updated task', 'priority' => 'HIGH', 'due_date' => null])->assertOk()
            ->assertJsonPath('data.title', 'Updated task')->assertJsonPath('data.description', 'Task responsibilities')->assertJsonPath('data.status', 'ASSIGNED');
        foreach (['id', 'internship_id', 'assigned_by', 'status', 'progress_percent', 'created_at'] as $field) {
            $this->assertSame($before[$field], $task->fresh()->getAttributes()[$field]);
        }
        foreach (['status', 'internship_id', 'assigned_by', 'progress_percent', 'submission_text', 'feedback'] as $field) {
            $this->patchJson('/api/supervisor/tasks/'.$task->id, [$field => null])->assertUnprocessable();
        }
        $this->deleteJson('/api/supervisor/tasks/'.$task->id)->assertStatus(405);
    }

    public function test_task_editing_rechecks_parent_status_task_status_and_existing_submissions(): void
    {
        $application = $this->application(['status' => 'ACTIVE']);
        $task = $this->task($application);
        $supervisor = $application->companySupervisor->user;
        $this->actingAs($supervisor);
        foreach (TaskStatus::cases() as $status) {
            if ($status->value !== 'ASSIGNED') {
                $task->update(['status' => $status->value]);
                $this->patchJson('/api/supervisor/tasks/'.$task->id, ['title' => 'Unsafe'])->assertConflict();
            }
        }
        $task->update(['status' => 'ASSIGNED']);
        $application->update(['status' => 'COMPLETED']);
        $this->patchJson('/api/supervisor/tasks/'.$task->id, ['title' => 'Unsafe'])->assertConflict();
        $application->update(['status' => 'ACTIVE']);
        $submission = $task->submissions()->create(['version_no' => 1, 'submission_text' => 'Existing submission', 'submitted_at' => now()]);
        $this->getJson('/api/supervisor/tasks/'.$task->id)->assertJsonPath('data.can_edit', false);
        $this->patchJson('/api/supervisor/tasks/'.$task->id, ['title' => 'Unsafe'])->assertConflict();
        try {
            app(TaskService::class)->save($supervisor, ['title' => 'Unsafe'], id: $task->id);
            $this->fail('Existing submissions must be rechecked.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame('Original task', $task->fresh()->title);
        $this->assertSame('Existing submission', $submission->fresh()->submission_text);
    }

    public function test_task_writes_recheck_changed_supervisor_company_association_and_eligibility(): void
    {
        $application = $this->application(['status' => 'ACTIVE']);
        $supervisor = $application->companySupervisor->user;
        $task = $this->task($application);
        $this->actingAs($supervisor);
        $supervisor->companySupervisorProfile()->update(['company_id' => Company::create(['name' => 'Another approved company', 'verification_status' => 'APPROVED'])->id]);
        $this->getJson($this->path($application))->assertOk()->assertJsonPath('data.can_create_tasks', false);
        $this->getJson('/api/supervisor/tasks/'.$task->id)->assertOk()->assertJsonPath('data.can_edit', false);
        $this->postJson($this->path($application, 'tasks'), $this->payload())->assertUnprocessable();
        $this->patchJson('/api/supervisor/tasks/'.$task->id, ['title' => 'Unsafe'])->assertUnprocessable();
        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame('Original task', $task->fresh()->title);
    }

    public function test_student_task_access_is_owned_active_read_only_and_safe(): void
    {
        $application = $this->application(['status' => 'ACTIVE']);
        $task = $this->task($application);
        $student = $application->student->user;
        $this->actingAs($student)->getJson('/api/student/internships/'.$application->id.'/tasks')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_edit', false);
        $this->getJson('/api/student/tasks/'.$task->id)->assertOk()->assertJsonPath('data.title', 'Original task')->assertJsonMissingPath('data.internship.student.email')->assertJsonMissingPath('data.assigned_by');
        $this->postJson($this->path($application, 'tasks'), $this->payload())->assertForbidden();
        $this->patchJson('/api/supervisor/tasks/'.$task->id, ['title' => 'Unsafe'])->assertForbidden();
        $this->patchJson('/api/student/tasks/'.$task->id, ['title' => 'Unsafe'])->assertStatus(405);
        $other = $this->application()->student->user;
        $this->actingAs($other)->getJson('/api/student/tasks/'.$task->id)->assertNotFound();
        $this->getJson('/api/student/internships/'.$application->id.'/tasks')->assertNotFound();
        $application->update(['status' => 'APPROVED']);
        $this->actingAs($student)->getJson('/api/student/tasks/'.$task->id)->assertNotFound();
        $this->getJson('/api/student/internships/'.$application->id.'/tasks')->assertNotFound();
        $this->actingAs($this->user(UserRole::STUDENT))->getJson('/api/student/tasks/'.$task->id)->assertForbidden();
    }

    public function test_student_task_routes_deny_guests_other_roles_inactive_and_missing_profiles(): void
    {
        $application = $this->application(['status' => 'ACTIVE']);
        $task = $this->task($application);
        $check = function (int $status) use ($application, $task): void {
            $this->getJson('/api/student/tasks/'.$task->id)->assertStatus($status);
            $this->getJson('/api/student/internships/'.$application->id.'/tasks')->assertStatus($status);
        };
        $check(401);
        foreach ([UserRole::ADMIN, UserRole::COMPANY_SUPERVISOR, UserRole::ACADEMIC_COORDINATOR, UserRole::STUDENT] as $role) {
            $this->actingAs($this->user($role));
            $check(403);
        }
        $student = $application->student->user;
        $student->update(['is_active' => false]);
        $this->actingAs($student);
        $check(403);
    }

    public function test_task_lists_are_paginated_filtered_literal_and_eager_loaded(): void
    {
        $application = $this->application(['status' => 'ACTIVE']);
        $first = $this->task($application, ['title' => 'Task 100%', 'created_at' => '2026-01-01']);
        $second = $this->task($application, ['priority' => 'HIGH', 'created_at' => '2026-01-01']);
        $this->task($this->application(['status' => 'ACTIVE']));
        $this->actingAs($application->companySupervisor->user->load('role'));
        $this->getJson($this->path($application, 'tasks').'?per_page=1')->assertOk()->assertJsonPath('data.0.id', $second->id)->assertJsonPath('meta.total', 2);
        $this->getJson($this->path($application, 'tasks').'?search=100%25')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
        $this->getJson($this->path($application, 'tasks').'?priority=HIGH&status=ASSIGNED')->assertJsonCount(1, 'data');
        $this->getJson($this->path($application, 'tasks').'?status=invalid&per_page=101')->assertUnprocessable();
        $count = function () use ($application): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->getJson($this->path($application, 'tasks'))->assertOk();

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        };
        $small = $count();
        $this->task($application);
        $this->task($application);
        $this->assertSame($small, $count());
    }

    public function test_activation_to_task_approval_with_and_without_revision_is_integrated(): void
    {
        foreach ([false, true] as $revision) {
            $application = $this->application();
            $supervisor = $application->companySupervisor->user;
            $student = $application->student->user;
            $this->actingAs($supervisor)->postJson($this->path($application, 'activate'))->assertOk();
            $parent = $application->fresh()->getAttributes();
            $id = $this->postJson($this->path($application, 'tasks'), $this->payload())->assertCreated()->json('data.id');
            $task = Task::findOrFail($id);
            $this->patchJson('/api/supervisor/tasks/'.$id, ['title' => 'Reviewed definition'])->assertOk();
            $metadata = $task->fresh()->getAttributes();
            $this->actingAs($student)->getJson('/api/student/tasks/'.$id)->assertOk()->assertJsonPath('data.status', 'ASSIGNED');
            $this->postJson('/api/student/tasks/'.$id.'/start')->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
            $this->postJson('/api/student/tasks/'.$id.'/submissions', ['submission_text' => 'Initial work'])->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
            $first = $task->submissions()->sole();
            $this->assertSame(1, $first->version_no);
            $original = $first->getAttributes();
            $this->postJson('/api/student/tasks/'.$id.'/submissions', ['submission_text' => 'Duplicate'])->assertConflict();
            if ($revision) {
                $this->actingAs($supervisor)->postJson('/api/supervisor/tasks/'.$id.'/review', ['decision' => 'REVISION_REQUIRED', 'comment' => 'Add tests', 'expected_submission_id' => $first->id])->assertOk();
                $feedback = $first->fresh()->feedback->getAttributes();
                $this->actingAs($student)->postJson('/api/student/tasks/'.$id.'/resubmit', ['submission_text' => 'Corrected work', 'expected_submission_id' => $first->id])->assertOk();
                $this->postJson('/api/student/tasks/'.$id.'/resubmit', ['submission_text' => 'Duplicate correction', 'expected_submission_id' => $first->id])->assertConflict();
                $this->assertSame($feedback, $first->fresh()->feedback->getAttributes());
            }
            $latest = $task->submissions()->orderByDesc('version_no')->first();
            $this->assertSame($revision ? 2 : 1, $latest->version_no);
            $review = ['decision' => 'APPROVED', 'expected_submission_id' => $latest->id];
            $this->actingAs($supervisor)->postJson('/api/supervisor/tasks/'.$id.'/review', $review)->assertOk()->assertJsonPath('data.status', 'APPROVED');
            $this->postJson('/api/supervisor/tasks/'.$id.'/review', $review)->assertConflict();
            $this->assertSame($original, $first->fresh()->getAttributes());
            $this->assertSame($parent, $application->fresh()->getAttributes());
            foreach (array_diff(array_keys($metadata), ['status', 'updated_at']) as $field) {
                $this->assertSame($metadata[$field], $task->fresh()->getAttributes()[$field]);
            }
            foreach ([$student, $supervisor] as $actor) {
                $portal = $actor->id === $student->id ? 'student' : 'supervisor';
                $this->actingAs($actor)->getJson('/api/'.$portal.'/tasks/'.$id.'/submissions')->assertOk()->assertJsonCount($revision ? 2 : 1, 'data')->assertJsonPath('data.0.feedback.decision', 'APPROVED');
            }
            $this->assertSame($revision ? 2 : 1, $task->submissions()->count());
            $this->assertSame(1, Task::where('internship_id', $application->id)->count());
        }
    }

    public function test_due_dates_follow_existing_date_only_rules_without_new_window_restrictions(): void
    {
        $application = $this->application(['status' => 'ACTIVE']);
        $this->actingAs($application->companySupervisor->user);
        foreach ([null, '2026-09-30', '2026-10-31', '2026-11-01'] as $date) {
            $id = $this->postJson($this->path($application, 'tasks'), [...$this->payload(), 'due_date' => $date])->assertCreated()->assertJsonPath('data.due_date', $date)->json('data.id');
            $this->patchJson('/api/supervisor/tasks/'.$id, ['due_date' => '2026-02-30'])->assertUnprocessable();
            $this->assertSame($date, Task::findOrFail($id)->due_date?->format('Y-m-d'));
        }
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role->value)->sole()->id]);
    }

    private function supervisor(Company $company): User
    {
        $user = $this->user(UserRole::COMPANY_SUPERVISOR);
        $user->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => 'APPROVED']);

        return $user;
    }

    private function application(array $attributes = []): Internship
    {
        $student = $this->user(UserRole::STUDENT);
        $student->studentProfile()->create(['student_number' => fake()->uuid(), 'study_program' => 'Computer Science', 'study_year' => 2]);
        $company = Company::create(['name' => fake()->company(), 'verification_status' => 'APPROVED']);
        $coordinator = $this->user(UserRole::ACADEMIC_COORDINATOR);
        $coordinator->academicCoordinatorProfile()->create(['academic_unit' => 'Engineering']);

        return Internship::create(['student_id' => $student->id, 'company_id' => $company->id, 'company_supervisor_id' => $this->supervisor($company)->id, 'coordinator_id' => $coordinator->id,
            'position_title' => 'Software Intern', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'APPROVED', 'submitted_at' => now()->subDays(3), 'approved_at' => now()->subDay(), ...$attributes]);
    }

    private function payload(): array
    {
        return ['title' => 'Original task', 'description' => 'Task responsibilities', 'priority' => 'MEDIUM', 'due_date' => '2026-10-20'];
    }

    private function task(Internship $internship, array $attributes = []): Task
    {
        return Task::create(['internship_id' => $internship->id, 'assigned_by' => $internship->company_supervisor_id, ...$this->payload(), ...$attributes]);
    }

    private function path(Internship $internship, ?string $action = null): string
    {
        return '/api/supervisor/internships/'.$internship->id.($action ? '/'.$action : '');
    }
}
