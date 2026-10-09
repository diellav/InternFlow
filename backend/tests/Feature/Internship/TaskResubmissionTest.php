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
use Tests\TestCase;

class TaskResubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_resubmission_preserves_task_metadata_and_original_submission_and_feedback(): void
    {
        $task = $this->task();
        $before = $task->fresh()->getAttributes();
        $submission = $task->submissions()->sole();
        $original = $submission->getAttributes();
        $feedback = $submission->feedback->getAttributes();
        $this->actingAs($task->internship->student->user);
        $this->getJson('/api/student/tasks/'.$task->id)->assertJsonPath('data.can_resubmit', true);
        $this->postJson($this->path($task), $this->payload($task, ['submission_text' => '  Corrected work  ']))->assertOk()->assertJsonPath('data.status', 'SUBMITTED')->assertJsonPath('data.can_resubmit', false)->assertJsonPath('data.can_submit', false);
        $latest = $task->submissions()->orderByDesc('version_no')->first();
        $this->assertSame(2, $latest->version_no);
        $this->assertSame($task->id, $latest->task_id);
        $this->assertSame('Corrected work', $latest->submission_text);
        $this->assertNotNull($latest->submitted_at);
        $this->assertNull($latest->feedback);
        $this->assertSame($original, $submission->fresh()->getAttributes());
        $this->assertSame($feedback, $submission->feedback->fresh()->getAttributes());
        foreach (array_diff(array_keys($before), ['status', 'updated_at']) as $field) {
            $this->assertSame($before[$field], $task->fresh()->getAttributes()[$field]);
        }
        $this->postJson($this->path($task), $this->payload($task))->assertConflict();
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('task_submissions', 2);
    }

    public function test_three_revision_cycles_and_latest_review_preserve_complete_history(): void
    {
        $task = $this->task();
        $student = $task->internship->student->user;
        $supervisor = $task->internship->companySupervisor->user;
        $firstId = $task->submissions()->sole()->id;
        $snapshots = [];
        for ($cycle = 1; $cycle <= 3; $cycle++) {
            $latest = $task->submissions()->orderByDesc('version_no')->with('feedback')->first();
            $snapshots[$latest->id] = [$latest->getAttributes(), $latest->feedback->getAttributes()];
            $this->actingAs($student)->postJson($this->path($task), $this->payload($task, ['submission_text' => 'Correction '.$cycle]))->assertOk();
            $current = $task->submissions()->orderByDesc('version_no')->first();
            $this->assertSame($cycle + 1, $current->version_no);
            $this->actingAs($supervisor)->postJson($this->reviewPath($task), ['decision' => 'APPROVED', 'expected_submission_id' => $latest->id])->assertConflict();
            $decision = $cycle === 3 ? 'APPROVED' : 'REVISION_REQUIRED';
            $this->postJson($this->reviewPath($task), ['decision' => $decision, 'comment' => 'Review '.$cycle, 'expected_submission_id' => $current->id])->assertOk();
            if ($cycle < 3) {
                $this->actingAs($student)->postJson($this->path($task), $this->payload($task, ['expected_submission_id' => $firstId]))->assertConflict();
            }
        }
        foreach ($snapshots as $id => [$submission, $feedback]) {
            $record = TaskSubmission::findOrFail($id);
            $this->assertSame($submission, $record->getAttributes());
            $this->assertSame($feedback, $record->feedback->getAttributes());
        }
        $this->assertSame('APPROVED', $task->fresh()->status);
        $this->assertSame('ACTIVE', $task->internship->fresh()->status);
        $this->assertDatabaseCount('task_submissions', 4);
        $this->assertDatabaseCount('task_feedback', 4);
        $this->actingAs($student)->getJson('/api/student/tasks/'.$task->id.'/submissions')->assertJsonCount(4, 'data')->assertJsonPath('data.0.version_no', 4)->assertJsonPath('data.0.feedback.decision', 'APPROVED')->assertJsonPath('data.3.version_no', 1)->assertJsonPath('data.3.feedback.decision', 'REVISION_REQUIRED')->assertJsonMissingPath('data.0.task_id')->assertJsonMissingPath('data.0.feedback.supervisor_id');
        $this->actingAs($supervisor)->getJson('/api/supervisor/tasks/'.$task->id.'/submissions')->assertJsonCount(4, 'data')->assertJsonPath('data.0.version_no', 4);
        $this->actingAs($student)->postJson($this->path($task), $this->payload($task))->assertConflict();
    }

    public function test_validation_and_protected_fields_leave_history_unchanged(): void
    {
        $task = $this->task();
        $this->actingAs($task->internship->student->user);
        foreach ([['submission_text' => null], ['submission_text' => "\u{00a0} \n"], ['submission_text' => str_repeat('x', 10001)], ['resource_url' => 'http://example.com'], ['resource_url' => 'javascript:alert(1)'], ['resource_url' => 'https://example.com/'.str_repeat('x', 240)], ['expected_submission_id' => null], ['expected_submission_id' => 0]] as $invalid) {
            $this->postJson($this->path($task), $this->payload($task, $invalid))->assertUnprocessable();
        }
        foreach (['task_id', 'version_no', 'submitted_at', 'status', 'supervisor_id', 'student_id', 'feedback', 'reviewer_id', 'internship_id', 'assigned_by', 'progress_percent', 'title', 'unknown'] as $field) {
            $this->postJson($this->path($task), $this->payload($task, [$field => null]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame('REVISION_REQUIRED', $task->fresh()->status);
        $this->assertDatabaseCount('task_submissions', 1);
        $this->assertDatabaseCount('task_feedback', 1);
        $this->postJson($this->path($task), $this->payload($task, ['resource_url' => null]))->assertOk();
        $this->assertNull($task->submissions()->orderByDesc('version_no')->first()->resource_url);
    }

    public function test_only_revision_required_active_tasks_with_latest_revision_feedback_can_resubmit(): void
    {
        $task = $this->task();
        $this->actingAs($task->internship->student->user);
        foreach (TaskStatus::cases() as $status) {
            if ($status->value === 'REVISION_REQUIRED') {
                continue;
            }
            $task->update(['status' => $status->value]);
            $this->postJson($this->path($task), $this->payload($task))->assertConflict();
        }
        $task->update(['status' => 'REVISION_REQUIRED']);
        $task->internship->update(['status' => 'APPROVED']);
        $this->postJson($this->path($task), $this->payload($task))->assertConflict();
        $task->internship->update(['status' => 'ACTIVE']);
        $latest = $task->submissions()->sole();
        $latest->feedback()->update(['decision' => 'APPROVED']);
        $this->postJson($this->path($task), $this->payload($task))->assertConflict();
        $latest->feedback()->delete();
        $this->postJson($this->path($task), $this->payload($task))->assertConflict();
        $task->submissions()->delete();
        $this->postJson($this->path($task), ['submission_text' => 'Work', 'expected_submission_id' => $latest->id])->assertConflict();
        $this->assertDatabaseCount('task_submissions', 0);
    }

    public function test_guests_wrong_roles_inactive_missing_profiles_and_foreign_students_are_denied(): void
    {
        $task = $this->task();
        $this->postJson($this->path($task), $this->payload($task))->assertUnauthorized();
        foreach (['ADMIN', 'ACADEMIC_COORDINATOR', 'COMPANY_SUPERVISOR'] as $role) {
            $this->actingAs($this->user($role))->postJson($this->path($task), $this->payload($task))->assertForbidden();
        }
        $student = $task->internship->student->user;
        $student->update(['is_active' => false]);
        $this->actingAs($student)->postJson($this->path($task), $this->payload($task))->assertForbidden();
        $this->actingAs($this->user('STUDENT'))->postJson($this->path($task), $this->payload($task))->assertForbidden();
        $other = $this->task();
        $this->actingAs($other->internship->student->user)->postJson($this->path($task), $this->payload($task))->assertNotFound();
        $this->getJson('/api/student/tasks/'.$task->id.'/submissions')->assertNotFound();
        $this->actingAs($other->internship->companySupervisor->user)->getJson('/api/supervisor/tasks/'.$task->id.'/submissions')->assertNotFound();
        $supervisor = $task->internship->companySupervisor->user;
        foreach (['PENDING', 'REJECTED'] as $status) {
            $supervisor->companySupervisorProfile()->update(['verification_status' => $status]);
            $this->actingAs($supervisor->fresh())->getJson('/api/supervisor/tasks/'.$task->id.'/submissions')->assertForbidden();
        }
    }

    public function test_failed_task_update_rolls_back_new_version(): void
    {
        $task = $this->task();
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT module5d_test_failure CHECK (status <> 'SUBMITTED')");
        try {
            app(StudentTaskService::class)->transition($task->internship->student->user, $task->id, $this->payload($task), true);
            $this->fail('Expected failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('module5d_test_failure', $exception->getMessage());
        } finally {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT module5d_test_failure');
        }
        $this->assertSame('REVISION_REQUIRED', $task->fresh()->status);
        $this->assertDatabaseCount('task_submissions', 1);
        $this->assertDatabaseCount('task_feedback', 1);
    }

    public function test_database_version_uniqueness_conflict_is_409_and_atomic(): void
    {
        $task = $this->task();
        $dispatcher = TaskSubmission::getEventDispatcher();
        TaskSubmission::setEventDispatcher(clone $dispatcher);
        TaskSubmission::creating(function (TaskSubmission $submission): void {
            $submission->version_no = 1;
        });
        try {
            $this->actingAs($task->internship->student->user)->postJson($this->path($task), $this->payload($task))->assertConflict();
        } finally {
            TaskSubmission::setEventDispatcher($dispatcher);
        }
        $this->assertSame('REVISION_REQUIRED', $task->fresh()->status);
        $this->assertDatabaseCount('task_submissions', 1);
    }

    public function test_history_is_paginated_newest_first_and_prior_records_cannot_be_edited(): void
    {
        $task = $this->task();
        for ($version = 2; $version <= 17; $version++) {
            $task->submissions()->create(['version_no' => $version, 'submission_text' => 'Version '.$version, 'submitted_at' => now()]);
        }
        $this->actingAs($task->internship->student->user)->getJson('/api/student/tasks/'.$task->id.'/submissions')->assertJsonCount(15, 'data')->assertJsonPath('data.0.version_no', 17)->assertJsonPath('meta.total', 17);
        $this->getJson('/api/student/tasks/'.$task->id.'/submissions?page=2')->assertJsonCount(2, 'data')->assertJsonPath('data.0.version_no', 2)->assertJsonPath('data.1.version_no', 1);
        $this->patchJson('/api/student/tasks/'.$task->id.'/submissions', ['submission_text' => 'No'])->assertMethodNotAllowed();
        $this->patchJson('/api/student/tasks/'.$task->id.'/feedback', ['comment' => 'No'])->assertNotFound();
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->sole()->id]);
    }

    private function task(): Task
    {
        $student = $this->user('STUDENT');
        $student->studentProfile()->create(['student_number' => fake()->uuid(), 'study_program' => 'CS', 'study_year' => 2]);
        $company = Company::create(['name' => fake()->company(), 'verification_status' => 'APPROVED']);
        $supervisor = $this->user('COMPANY_SUPERVISOR');
        $supervisor->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => 'APPROVED']);
        $internship = Internship::create(['student_id' => $student->id, 'company_id' => $company->id, 'company_supervisor_id' => $supervisor->id, 'position_title' => 'Web internship', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'ACTIVE']);
        $task = Task::create(['internship_id' => $internship->id, 'assigned_by' => $supervisor->id, 'title' => 'Build form', 'description' => 'Test inputs', 'priority' => 'MEDIUM', 'status' => 'REVISION_REQUIRED']);
        $submission = $task->submissions()->create(['version_no' => 1, 'submission_text' => 'Original work', 'resource_url' => 'https://example.com/v1', 'submitted_at' => now()]);
        $submission->feedback()->create(['supervisor_id' => $supervisor->id, 'decision' => 'REVISION_REQUIRED', 'comment' => 'Add tests']);

        return $task;
    }

    private function path(Task $task): string
    {
        return '/api/student/tasks/'.$task->id.'/resubmit';
    }

    private function reviewPath(Task $task): string
    {
        return '/api/supervisor/tasks/'.$task->id.'/review';
    }

    private function payload(Task $task, array $changes = []): array
    {
        return ['submission_text' => 'Corrected work', 'resource_url' => 'https://example.com/v2', 'expected_submission_id' => $task->submissions()->orderByDesc('version_no')->first()->id, ...$changes];
    }
}
