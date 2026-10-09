<?php

namespace Tests\Feature\Internship;

use App\Models\Company;
use App\Models\Internship;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskFeedback;
use App\Models\User;
use App\Modules\Task\Services\TaskReviewService;
use App\Shared\Enums\TaskStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TaskReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_approval_records_correct_reviewer_and_submission_without_completing_internship(): void
    {
        $task = $this->task();
        $parent = $task->internship->getAttributes();
        $before = $task->fresh()->getAttributes();
        $this->actingAs($task->internship->companySupervisor->user);
        $this->postJson($this->path($task), $this->payload($task))->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.can_review', false)->assertJsonPath('data.can_edit', false);
        $feedback = TaskFeedback::sole();
        $this->assertSame($task->submissions()->sole()->id, $feedback->submission_id);
        $this->assertSame($task->internship->company_supervisor_id, $feedback->supervisor_id);
        $this->assertSame('APPROVED', $feedback->decision);
        $this->assertNull($feedback->comment);
        $this->assertNotNull($feedback->created_at);
        $this->assertSame($parent, $task->internship->fresh()->getAttributes());
        foreach (array_diff(array_keys($before), ['status', 'updated_at']) as $field) {
            $this->assertSame($before[$field], $task->fresh()->getAttributes()[$field]);
        }
        $this->postJson($this->path($task), $this->payload($task))->assertConflict();
        $this->assertDatabaseCount('task_feedback', 1);
    }

    public function test_revision_trims_feedback_and_is_readable_without_leaking_ownership(): void
    {
        $task = $this->task();
        $this->actingAs($task->internship->companySupervisor->user)->postJson($this->path($task), $this->payload($task, ['decision' => 'REVISION_REQUIRED', 'comment' => "  Add tests.\nExplain edge cases.  "]))->assertOk()->assertJsonPath('data.status', 'REVISION_REQUIRED');
        $this->getJson('/api/supervisor/tasks/'.$task->id.'/submissions')->assertOk()->assertJsonPath('data.0.feedback.comment', "Add tests.\nExplain edge cases.")->assertJsonPath('data.0.feedback.decision', 'REVISION_REQUIRED')->assertJsonMissingPath('data.0.feedback.supervisor_id')->assertJsonMissingPath('data.0.feedback.submission_id');
        $this->actingAs($task->internship->student->user)->getJson('/api/student/tasks/'.$task->id.'/submissions')->assertOk()->assertJsonPath('data.0.feedback.decision', 'REVISION_REQUIRED');
        $this->postJson('/api/student/tasks/'.$task->id.'/start')->assertConflict();
        $this->postJson('/api/student/tasks/'.$task->id.'/submissions', ['submission_text' => 'Not yet'])->assertConflict();
        $this->actingAs($task->internship->companySupervisor->user)->patchJson('/api/supervisor/tasks/'.$task->id, ['title' => 'No'])->assertConflict();
    }

    public function test_invalid_payloads_and_protected_fields_never_create_feedback(): void
    {
        $task = $this->task();
        $this->actingAs($task->internship->companySupervisor->user);
        foreach ([['decision' => 'REJECTED'], ['decision' => 'ASSIGNED'], ['decision' => 'REVISION_REQUIRED'], ['decision' => 'REVISION_REQUIRED', 'comment' => " \t\n"], ['decision' => 'REVISION_REQUIRED', 'comment' => "\u{00a0}"], ['comment' => str_repeat('x', 10001)], ['comment' => []], ['expected_submission_id' => null], ['expected_submission_id' => 0], ['expected_submission_id' => 'bad']] as $invalid) {
            $this->postJson($this->path($task), $this->payload($task, $invalid))->assertUnprocessable();
        }
        foreach (['task_id', 'internship_id', 'supervisor_id', 'student_id', 'reviewer_id', 'status', 'created_at', 'updated_at', 'submission_id', 'version_no', 'approved_at', 'unknown'] as $field) {
            $this->postJson($this->path($task), $this->payload($task, [$field => null]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame('SUBMITTED', $task->fresh()->status);
        $this->assertDatabaseCount('task_feedback', 0);
    }

    public function test_only_latest_submission_and_one_final_decision_can_be_reviewed(): void
    {
        $task = $this->task();
        $stale = $this->payload($task);
        $latest = $task->submissions()->create(['version_no' => 2, 'submission_text' => 'New version fixture', 'submitted_at' => now()]);
        $this->actingAs($task->internship->companySupervisor->user);
        $this->postJson($this->path($task), $stale)->assertConflict();
        $foreign = $this->task();
        $this->postJson($this->path($task), $this->payload($foreign))->assertConflict();
        $this->postJson($this->path($task), $this->payload($task))->assertOk();
        $this->assertSame($latest->id, TaskFeedback::sole()->submission_id);
        $task->update(['status' => 'SUBMITTED']);
        $this->postJson($this->path($task), $this->payload($task))->assertConflict();
        $this->assertDatabaseCount('task_feedback', 1);
    }

    public function test_all_other_task_statuses_and_nonactive_internships_are_denied(): void
    {
        $task = $this->task();
        $this->actingAs($task->internship->companySupervisor->user);
        foreach (TaskStatus::cases() as $status) {
            if ($status->value === 'SUBMITTED') {
                continue;
            }
            $task->update(['status' => $status->value]);
            $this->postJson($this->path($task), $this->payload($task))->assertConflict();
        }
        $task->update(['status' => 'SUBMITTED']);
        $task->internship->update(['status' => 'COMPLETED']);
        $this->postJson($this->path($task), $this->payload($task))->assertConflict();
        $task->internship->update(['status' => 'ACTIVE']);
        $task->submissions()->delete();
        $this->postJson($this->path($task), ['decision' => 'APPROVED', 'expected_submission_id' => 999])->assertConflict();
        $this->assertDatabaseCount('task_feedback', 0);
    }

    public function test_guest_wrong_roles_and_foreign_supervisors_are_denied(): void
    {
        $task = $this->task();
        $this->postJson($this->path($task), $this->payload($task))->assertUnauthorized();
        foreach (['STUDENT', 'ADMIN', 'ACADEMIC_COORDINATOR'] as $role) {
            $this->actingAs($this->user($role))->postJson($this->path($task), $this->payload($task))->assertForbidden();
        }
        $foreign = $this->user('COMPANY_SUPERVISOR');
        $foreign->companySupervisorProfile()->create(['company_id' => $task->internship->company_id, 'verification_status' => 'APPROVED']);
        $this->actingAs($foreign)->postJson($this->path($task), $this->payload($task))->assertNotFound();
        $this->getJson('/api/supervisor/tasks/'.$task->id.'/submissions')->assertNotFound();
        $other = $this->task();
        $this->actingAs($other->internship->student->user)->getJson('/api/student/tasks/'.$task->id.'/submissions')->assertNotFound();
    }

    public function test_operational_verification_and_fresh_eligibility_are_required(): void
    {
        $task = $this->task();
        $supervisor = $task->internship->companySupervisor->user;
        foreach (['PENDING', 'REJECTED'] as $status) {
            $supervisor->companySupervisorProfile()->update(['verification_status' => $status]);
            $this->actingAs($supervisor->fresh())->postJson($this->path($task), $this->payload($task))->assertForbidden();
        }
        $supervisor->companySupervisorProfile()->update(['verification_status' => 'APPROVED']);
        foreach (['PENDING', 'REJECTED'] as $status) {
            $task->internship->company->update(['verification_status' => $status]);
            $this->actingAs($supervisor->fresh())->postJson($this->path($task), $this->payload($task))->assertForbidden();
        }
        $task->internship->company->update(['verification_status' => 'APPROVED', 'is_active' => false]);
        $this->actingAs($supervisor->fresh())->postJson($this->path($task), $this->payload($task))->assertForbidden();
        $task->internship->company->update(['is_active' => true]);
        $supervisor->update(['is_active' => false]);
        $this->actingAs($supervisor)->postJson($this->path($task), $this->payload($task))->assertForbidden();
        $supervisor->update(['is_active' => true]);
        User::whereKey($supervisor->id)->update(['is_active' => false]);
        try {
            app(TaskReviewService::class)->review($supervisor, $task->id, $this->payload($task));
            $this->fail('Expected denial.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('task_feedback', 0);
    }

    public function test_failed_status_update_rolls_back_feedback_and_preserves_submission(): void
    {
        $task = $this->task();
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT module5c_test_failure CHECK (status <> 'APPROVED')");
        try {
            app(TaskReviewService::class)->review($task->internship->companySupervisor->user, $task->id, $this->payload($task));
            $this->fail('Expected failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('module5c_test_failure', $exception->getMessage());
        } finally {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT module5c_test_failure');
        }
        $this->assertSame('SUBMITTED', $task->fresh()->status);
        $this->assertDatabaseCount('task_feedback', 0);
        $this->assertDatabaseCount('task_submissions', 1);
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
        $task = Task::create(['internship_id' => $internship->id, 'assigned_by' => $supervisor->id, 'title' => 'Build form', 'description' => 'Test inputs', 'priority' => 'MEDIUM', 'status' => 'SUBMITTED']);
        $task->submissions()->create(['version_no' => 1, 'submission_text' => 'Completed work', 'submitted_at' => now()]);

        return $task;
    }

    private function path(Task $task): string
    {
        return '/api/supervisor/tasks/'.$task->id.'/review';
    }

    private function payload(Task $task, array $changes = []): array
    {
        return ['decision' => 'APPROVED', 'expected_submission_id' => $task->submissions()->orderByDesc('version_no')->first()->id, ...$changes];
    }
}
