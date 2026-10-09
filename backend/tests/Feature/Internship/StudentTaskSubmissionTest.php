<?php

namespace Tests\Feature\Internship;

use App\Models\Company;
use App\Models\Internship;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Modules\Task\Services\StudentTaskService;
use App\Shared\Enums\TaskStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StudentTaskSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_start_is_explicit_and_preserves_task_fields_and_progress(): void
    {
        $task = $this->task(['progress_percent' => 25]);
        $before = $task->fresh()->getAttributes();
        $this->actingAs($task->internship->student->user);
        $this->getJson($this->path($task))->assertOk()->assertJsonPath('data.can_start', true)->assertJsonPath('data.can_submit', false);
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->postJson($this->path($task, 'start'))->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS')->assertJsonPath('data.can_start', false)->assertJsonPath('data.can_submit', true);
        foreach (array_diff(array_keys($before), ['status', 'updated_at']) as $field) {
            $this->assertSame($before[$field], $task->fresh()->getAttributes()[$field]);
        }
        $this->postJson($this->path($task, 'start'))->assertConflict();
        $this->assertDatabaseCount('task_submissions', 0);
    }

    public function test_submission_sets_server_fields_and_is_readable_only_by_owner_and_assigned_supervisor(): void
    {
        $task = $this->task(['status' => 'IN_PROGRESS']);
        $this->actingAs($task->internship->student->user);
        $this->postJson($this->path($task, 'submissions'), $this->payload())->assertOk()->assertJsonPath('data.status', 'SUBMITTED')->assertJsonPath('data.can_submit', false)->assertJsonPath('data.has_submissions', true);
        $submission = TaskSubmission::sole();
        $this->assertSame($task->id, $submission->task_id);
        $this->assertSame(1, $submission->version_no);
        $this->assertNotNull($submission->submitted_at);
        $this->getJson($this->path($task, 'submissions'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.submission_text', $this->payload()['submission_text'])->assertJsonPath('data.0.resource_url', $this->payload()['resource_url'])->assertJsonMissingPath('data.0.task_id')->assertJsonPath('data.0.feedback', null);
        $this->postJson($this->path($task, 'submissions'), $this->payload())->assertConflict();
        $this->assertDatabaseCount('task_submissions', 1);
        $this->actingAs($task->internship->companySupervisor->user);
        $this->getJson('/api/supervisor/tasks/'.$task->id.'/submissions')->assertOk()->assertJsonPath('data.0.id', $submission->id);
        $this->getJson('/api/supervisor/tasks/'.$task->id)->assertJsonPath('data.can_edit', false);
        $this->patchJson('/api/supervisor/tasks/'.$task->id, ['title' => 'No'])->assertConflict();
        $this->assertDatabaseCount('task_feedback', 0);
    }

    public function test_text_only_submission_and_paginated_empty_reads(): void
    {
        $task = $this->task(['status' => 'IN_PROGRESS']);
        $this->actingAs($task->internship->student->user);
        $this->getJson($this->path($task, 'submissions'))->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($this->path($task, 'submissions'), ['submission_text' => 'Completed work'])->assertOk();
        $this->assertNull(TaskSubmission::sole()->resource_url);
    }

    public function test_invalid_and_protected_submission_fields_never_mutate_records(): void
    {
        $task = $this->task(['status' => 'IN_PROGRESS']);
        $this->actingAs($task->internship->student->user);
        $before = $task->fresh()->getAttributes();
        foreach ([[], ['submission_text' => '  '], ['submission_text' => str_repeat('x', 10001)], ['resource_url' => 'http://example.com'], ['resource_url' => 'javascript:alert(1)'], ['resource_url' => 'not-a-url'], ['resource_url' => 'https://example.com/'.str_repeat('x', 240)]] as $invalid) {
            $this->postJson($this->path($task, 'submissions'), [...$this->payload(), ...$invalid, ...($invalid === [] ? ['submission_text' => null] : [])])->assertUnprocessable();
        }
        foreach (['task_id', 'student_id', 'reviewer_id', 'status', 'version_no', 'submitted_at', 'approved_at', 'feedback', 'progress_percent', 'title', 'priority', 'due_date', 'unknown'] as $field) {
            $this->postJson($this->path($task, 'submissions'), [...$this->payload(), $field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->postJson($this->path($task, 'start'), [$field => null])->assertUnprocessable();
        }
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertDatabaseCount('task_submissions', 0);
    }

    public function test_all_invalid_status_transitions_and_existing_submissions_are_rejected(): void
    {
        $task = $this->task();
        $this->actingAs($task->internship->student->user);
        foreach (TaskStatus::cases() as $status) {
            $task->update(['status' => $status->value]);
            if ($status->value !== 'ASSIGNED') {
                $this->postJson($this->path($task, 'start'))->assertConflict();
            }
            if ($status->value !== 'IN_PROGRESS') {
                $this->postJson($this->path($task, 'submissions'), $this->payload())->assertConflict();
            }
        }
        $task->submissions()->create(['version_no' => 1, 'submission_text' => 'Existing', 'submitted_at' => now()]);
        foreach (['ASSIGNED' => 'start', 'IN_PROGRESS' => 'submissions'] as $status => $action) {
            $task->update(['status' => $status]);
            $this->postJson($this->path($task, $action), $action === 'start' ? [] : $this->payload())->assertConflict();
        }
        $this->assertDatabaseCount('task_submissions', 1);
    }

    public function test_foreign_and_non_active_internships_cannot_be_managed(): void
    {
        $task = $this->task();
        $other = $this->task();
        $this->actingAs($other->internship->student->user);
        foreach (['start', 'submissions'] as $action) {
            $this->postJson($this->path($task, $action), $this->payload())->assertNotFound();
        }
        $this->getJson($this->path($task, 'submissions'))->assertNotFound();
        $this->actingAs($task->internship->student->user);
        $task->internship->update(['status' => 'APPROVED']);
        $this->postJson($this->path($task, 'start'))->assertConflict();
        $task->update(['status' => 'IN_PROGRESS']);
        $this->postJson($this->path($task, 'submissions'), $this->payload())->assertConflict();
        $this->getJson($this->path($task, 'submissions'))->assertNotFound();
    }

    public function test_guests_wrong_roles_inactive_and_missing_profiles_are_denied(): void
    {
        $task = $this->task();
        $calls = function (int $status) use ($task): void {
            $this->postJson($this->path($task, 'start'))->assertStatus($status);
            $this->postJson($this->path($task, 'submissions'), $this->payload())->assertStatus($status);
            $this->getJson($this->path($task, 'submissions'))->assertStatus($status);
        };
        $calls(401);
        foreach (['ADMIN', 'ACADEMIC_COORDINATOR', 'COMPANY_SUPERVISOR'] as $role) {
            $this->actingAs($this->user($role));
            $calls(403);
        }
        $student = $task->internship->student->user;
        $student->update(['is_active' => false]);
        $this->actingAs($student);
        $calls(403);
        $student->update(['is_active' => true]);
        $this->actingAs($this->user('STUDENT'));
        $calls(403);
    }

    public function test_supervisor_reads_remain_operationally_verified_and_directly_assigned(): void
    {
        $task = $this->task(['status' => 'IN_PROGRESS']);
        $this->actingAs($task->internship->student->user)->postJson($this->path($task, 'submissions'), $this->payload())->assertOk();
        $supervisor = $task->internship->companySupervisor->user;
        $foreign = $this->user('COMPANY_SUPERVISOR');
        $foreign->companySupervisorProfile()->create(['company_id' => $task->internship->company_id, 'verification_status' => 'APPROVED']);
        $path = '/api/supervisor/tasks/'.$task->id.'/submissions';
        $this->actingAs($foreign)->getJson($path)->assertNotFound();
        foreach (['PENDING', 'REJECTED'] as $status) {
            $supervisor->companySupervisorProfile()->update(['verification_status' => $status]);
            $this->actingAs($supervisor->fresh())->getJson($path)->assertForbidden();
        }
        $supervisor->companySupervisorProfile()->update(['verification_status' => 'APPROVED']);
        $task->internship->company->update(['is_active' => false]);
        $this->actingAs($supervisor->fresh())->getJson($path)->assertForbidden();
    }

    public function test_transaction_rolls_back_submission_if_task_update_fails(): void
    {
        $task = $this->task(['status' => 'IN_PROGRESS']);
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT module5b_test_failure CHECK (status <> 'SUBMITTED')");
        try {
            app(StudentTaskService::class)->transition($task->internship->student->user, $task->id, $this->payload());
            $this->fail('Expected database constraint failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('module5b_test_failure', $exception->getMessage());
        } finally {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT module5b_test_failure');
        }
        $this->assertSame('IN_PROGRESS', $task->fresh()->status);
        $this->assertDatabaseCount('task_submissions', 0);
    }

    public function test_service_rechecks_fresh_actor_and_student_cannot_approve_or_edit(): void
    {
        $task = $this->task();
        $student = $task->internship->student->user;
        $this->actingAs($student);
        $this->patchJson('/api/supervisor/tasks/'.$task->id, ['title' => 'No'])->assertForbidden();
        $this->postJson($this->path($task, 'approve'))->assertNotFound();
        $this->postJson($this->path($task, 'feedback'))->assertNotFound();
        User::whereKey($student->id)->update(['is_active' => false]);
        try {
            app(StudentTaskService::class)->transition($student, $task->id);
            $this->fail('Expected denial.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('ASSIGNED', $task->fresh()->status);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->sole()->id]);
    }

    private function task(array $attributes = []): Task
    {
        $student = $this->user('STUDENT');
        $student->studentProfile()->create(['student_number' => fake()->uuid(), 'study_program' => 'CS', 'study_year' => 2]);
        $company = Company::create(['name' => fake()->company(), 'verification_status' => 'APPROVED']);
        $supervisor = $this->user('COMPANY_SUPERVISOR');
        $supervisor->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => 'APPROVED']);
        $internship = Internship::create(['student_id' => $student->id, 'company_id' => $company->id, 'company_supervisor_id' => $supervisor->id, 'position_title' => 'Web internship', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'ACTIVE']);

        return Task::create(['internship_id' => $internship->id, 'assigned_by' => $supervisor->id, 'title' => 'Build form', 'description' => 'Test inputs', 'priority' => 'MEDIUM', ...$attributes]);
    }

    private function path(Task $task, ?string $action = null): string
    {
        return '/api/student/tasks/'.$task->id.($action ? '/'.$action : '');
    }

    private function payload(): array
    {
        return ['submission_text' => 'Implemented and tested the form.', 'resource_url' => 'https://github.com/example/work'];
    }
}
