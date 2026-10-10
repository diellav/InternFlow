<?php

namespace Tests\Feature\Notification;

use App\Models\Company;
use App\Models\FinalEvaluation;
use App\Models\Internship;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Modules\Evaluation\Services\CoordinatorCompletionService;
use App\Modules\Evaluation\Services\SupervisorEvaluationService;
use App\Modules\Internship\Services\CoordinatorInternshipService;
use App\Modules\Internship\Services\StudentInternshipService;
use App\Modules\Internship\Services\SupervisorInternshipService;
use App\Modules\Notification\Services\NotificationPayload;
use App\Modules\Task\Services\StudentTaskService;
use App\Modules\Task\Services\TaskReviewService;
use App\Modules\Task\Services\TaskService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NotificationEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'UTC'));
        Storage::fake('task_attachments');
    }

    public function test_initial_submission_is_unassigned_and_does_not_broadcast_or_notify_on_claim(): void
    {
        $parent = $this->fixture('DRAFT');
        $this->actingAs($parent->student->user)->postJson('/api/student/internships/'.$parent->id.'/submit')->assertOk();
        $this->assertNull($parent->fresh()->coordinator_id);
        $this->assertDatabaseCount('notifications', 0);
        $this->postJson('/api/student/internships/'.$parent->id.'/submit')->assertConflict();
        $coordinator = $this->coordinator();
        $this->actingAs($coordinator)->postJson('/api/coordinator/internships/'.$parent->id.'/claim')->assertOk();
        $this->postJson('/api/coordinator/internships/'.$parent->id.'/start-review')->assertOk();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_application_resubmission_notifies_only_retained_coordinator_once(): void
    {
        $parent = $this->fixture('REVISION_REQUIRED');
        $this->coordinator();
        $student = $parent->student->user;
        $this->actingAs($student)->postJson('/api/student/internships/'.$parent->id.'/resubmit')->assertOk();
        $this->assertEvent($parent->coordinator->user, 'application_submitted', '/coordinator/internships/'.$parent->id, 'internship', $parent->id);
        $this->postJson('/api/student/internships/'.$parent->id.'/resubmit')->assertConflict();
        $this->assertDatabaseCount('notifications', 1);
    }

    public static function decisions(): array
    {
        return [['APPROVED', 'application_approved'], ['REJECTED', 'application_rejected'], ['REVISION_REQUIRED', 'application_revision_required']];
    }

    #[DataProvider('decisions')]
    public function test_each_application_decision_notifies_only_applicant_with_generic_content(string $decision, string $category): void
    {
        $parent = $this->fixture('UNDER_REVIEW');
        $payload = ['decision' => $decision];
        if ($decision !== 'APPROVED') {
            $payload['decision_comment'] = 'Private explanation <b>must never enter notification payload</b>.';
        }
        $this->actingAs($parent->coordinator->user)->postJson('/api/coordinator/internships/'.$parent->id.'/decision', $payload)->assertOk();
        $this->assertEvent($parent->student->user, $category, '/student/internships/'.$parent->id, 'internship', $parent->id);
        $this->postJson('/api/coordinator/internships/'.$parent->id.'/decision', $payload)->assertConflict();
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_activation_notifies_student_once(): void
    {
        $parent = $this->fixture('APPROVED');
        $this->actingAs($parent->companySupervisor->user)->postJson('/api/supervisor/internships/'.$parent->id.'/activate')->assertOk();
        $this->assertEvent($parent->student->user, 'internship_activated', '/student/internships/'.$parent->id, 'internship', $parent->id);
        $this->postJson('/api/supervisor/internships/'.$parent->id.'/activate')->assertConflict();
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_task_creation_notifies_student_but_edit_and_start_do_not(): void
    {
        $parent = $this->fixture();
        $supervisor = $parent->companySupervisor->user;
        $task = app(TaskService::class)->save($supervisor, ['title' => 'Private task title', 'description' => '<b>private work</b>', 'priority' => 'MEDIUM'], $parent->id);
        $this->assertEvent($parent->student->user, 'task_assigned', '/student/tasks/'.$task->id, 'task', $task->id);
        app(TaskService::class)->save($supervisor, ['title' => 'Edited task'], null, $task->id);
        app(StudentTaskService::class)->transition($parent->student->user, $task->id);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_submission_review_revision_and_new_version_each_have_one_correct_notification(): void
    {
        $parent = $this->fixture();
        $task = $this->task($parent, 'IN_PROGRESS');
        $student = $parent->student->user;
        $supervisor = $parent->companySupervisor->user;
        $this->actingAs($student)->postJson('/api/student/tasks/'.$task->id.'/submissions', ['submission_text' => 'Private submission'])->assertOk();
        $this->assertEvent($supervisor, 'task_submitted', '/supervisor/tasks/'.$task->id, 'task', $task->id);
        $this->postJson('/api/student/tasks/'.$task->id.'/submissions', ['submission_text' => 'Duplicate'])->assertConflict();
        $first = $task->submissions()->sole();
        $this->actingAs($supervisor)->postJson('/api/supervisor/tasks/'.$task->id.'/review', ['expected_submission_id' => $first->id, 'decision' => 'REVISION_REQUIRED', 'comment' => 'Private correction request'])->assertOk();
        $this->assertEvent($student, 'task_revision_required', '/student/tasks/'.$task->id, 'task', $task->id);
        $this->postJson('/api/supervisor/tasks/'.$task->id.'/review', ['expected_submission_id' => $first->id, 'decision' => 'REVISION_REQUIRED', 'comment' => 'Duplicate'])->assertConflict();
        $this->actingAs($student)->postJson('/api/student/tasks/'.$task->id.'/resubmit', ['expected_submission_id' => $first->id, 'submission_text' => 'Private corrected submission'])->assertOk();
        $second = $task->submissions()->orderByDesc('version_no')->first();
        $this->assertSame(2, $second->version_no);
        $this->postJson('/api/student/tasks/'.$task->id.'/resubmit', ['expected_submission_id' => $first->id, 'submission_text' => 'Duplicate'])->assertConflict();
        $this->actingAs($supervisor)->postJson('/api/supervisor/tasks/'.$task->id.'/review', ['expected_submission_id' => $first->id, 'decision' => 'APPROVED'])->assertConflict();
        $this->postJson('/api/supervisor/tasks/'.$task->id.'/review', ['expected_submission_id' => $second->id, 'decision' => 'APPROVED'])->assertOk();
        $this->assertEvent($student, 'task_approved', '/student/tasks/'.$task->id, 'task', $task->id);
        $this->assertSame(2, $supervisor->notifications()->where('type', 'task_submitted')->count());
        $this->assertDatabaseCount('notifications', 4);
    }

    public function test_evaluation_is_private_until_completion_and_notifications_work_with_read_api(): void
    {
        $parent = $this->fixture();
        $supervisor = $parent->companySupervisor->user;
        $coordinator = $parent->coordinator->user;
        $student = $parent->student->user;
        $draft = app(SupervisorEvaluationService::class)->save($supervisor, $parent->id, $this->ratings());
        $this->assertDatabaseCount('notifications', 0);
        $token = $draft->finalEvaluation->draftToken();
        $this->actingAs($supervisor)->postJson('/api/supervisor/internships/'.$parent->id.'/final-evaluation/submit', ['draft_token' => $token])->assertOk();
        $this->assertEvent($coordinator, 'final_evaluation_submitted', '/coordinator/monitoring/internships/'.$parent->id, 'internship', $parent->id);
        $this->assertSame(0, $student->notifications()->count());
        $this->assertSame('ACTIVE', $parent->fresh()->status);
        $this->postJson('/api/supervisor/internships/'.$parent->id.'/final-evaluation/submit', ['draft_token' => $token])->assertConflict();
        $this->actingAs($student)->getJson('/api/student/internships/'.$parent->id.'/final-evaluation')->assertNotFound();
        $evaluation = $parent->finalEvaluation()->sole();
        $evaluationBefore = $evaluation->getAttributes();
        $this->actingAs($coordinator)->postJson('/api/coordinator/internships/'.$parent->id.'/complete', ['expected_evaluation_id' => $evaluation->id])->assertOk();
        $this->assertEvent($student, 'internship_completed', '/student/internships/'.$parent->id, 'internship', $parent->id);
        $this->postJson('/api/coordinator/internships/'.$parent->id.'/complete', ['expected_evaluation_id' => $evaluation->id])->assertConflict();
        $this->assertSame($evaluationBefore, $evaluation->fresh()->getAttributes());
        $this->assertDatabaseCount('notifications', 2);
        $notification = $student->notifications()->sole();
        $this->actingAs($student)->getJson('/api/notifications')->assertOk()->assertJsonPath('data.0.payload.category', 'internship_completed');
        $this->getJson('/api/notifications/unread-count')->assertExactJson(['data' => ['unread_count' => 1]]);
        $this->patchJson('/api/notifications/'.$notification->id.'/read')->assertOk();
        $this->getJson('/api/notifications/unread-count')->assertExactJson(['data' => ['unread_count' => 0]]);
        $this->getJson('/api/student/internships/'.$parent->id.'/final-evaluation')->assertOk();
    }

    public function test_validation_authorization_and_stale_failures_never_notify(): void
    {
        $parent = $this->fixture('UNDER_REVIEW');
        $path = '/api/coordinator/internships/'.$parent->id.'/decision';
        $this->actingAs($parent->student->user)->postJson($path, ['decision' => 'APPROVED'])->assertForbidden();
        $this->actingAs($this->coordinator())->postJson($path, ['decision' => 'APPROVED'])->assertNotFound();
        $this->actingAs($parent->coordinator->user)->postJson($path, ['decision' => 'REJECTED'])->assertUnprocessable();
        $this->postJson($path, ['decision' => 'APPROVED', 'recipient_id' => $parent->coordinator_id])->assertUnprocessable();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame('UNDER_REVIEW', $parent->fresh()->status);
        $parent->update(['status' => 'ACTIVE']);
        $this->postJson('/api/coordinator/internships/'.$parent->id.'/complete', ['expected_evaluation_id' => 1])->assertConflict();
        $draft = app(SupervisorEvaluationService::class)->save($parent->companySupervisor->user, $parent->id, $this->ratings());
        $this->task($parent, 'SUBMITTED');
        $this->actingAs($parent->companySupervisor->user)->postJson('/api/supervisor/internships/'.$parent->id.'/final-evaluation/submit', ['draft_token' => $draft->finalEvaluation->draftToken()])->assertConflict();
        $this->assertDatabaseCount('notifications', 0);
    }

    public static function rollbackEvents(): array
    {
        return array_map(fn ($event) => [$event], ['resubmit', 'decision', 'activate', 'assign', 'submit', 'review', 'evaluation', 'complete']);
    }

    #[DataProvider('rollbackEvents')]
    public function test_outer_rollback_removes_notification_and_primary_change(string $event): void
    {
        $parent = $this->fixture(match ($event) {
            'resubmit' => 'REVISION_REQUIRED', 'decision' => 'UNDER_REVIEW', 'activate' => 'APPROVED', default => 'ACTIVE'
        });
        $task = $this->task($parent, $event === 'review' ? 'SUBMITTED' : 'IN_PROGRESS');
        if ($event === 'review') {
            $task->submissions()->create(['version_no' => 1, 'submission_text' => 'Evidence', 'submitted_at' => now()]);
        }
        if (in_array($event, ['evaluation', 'complete'], true)) {
            $task->update(['status' => 'ASSIGNED']);
            app(SupervisorEvaluationService::class)->save($parent->companySupervisor->user, $parent->id, $this->ratings());
            if ($event === 'complete') {
                $parent->finalEvaluation()->update(['submitted_at' => now()]);
            }
        }
        $before = $parent->fresh()->getAttributes();
        $taskBefore = $task->fresh()->getAttributes();
        try {
            DB::transaction(function () use ($event, $parent, $task): void {
                $this->perform($event, $parent, $task);
                $this->assertDatabaseCount('notifications', 1);
                throw new RuntimeException('Isolated outer rollback');
            });
        } catch (RuntimeException $failure) {
            $this->assertSame('Isolated outer rollback', $failure->getMessage());
        }
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame($before, $parent->fresh()->getAttributes());
        $this->assertSame($taskBefore, $task->fresh()->getAttributes());
    }

    public function test_database_delivery_failure_uses_savepoint_and_does_not_abort_primary_operation(): void
    {
        $parent = $this->fixture('APPROVED');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT module8b_failure CHECK (type <> 'internship_activated')");
        try {
            app(SupervisorInternshipService::class)->activate($parent->companySupervisor->user, $parent->id);
            $this->assertSame('ACTIVE', $parent->fresh()->status);
            $this->assertDatabaseCount('notifications', 0);
            $this->assertSame(1, User::whereKey($parent->student_id)->count());
        } finally {
            DB::statement('ALTER TABLE notifications DROP CONSTRAINT module8b_failure');
        }
    }

    public static function invalidRecipients(): array
    {
        return array_map(fn ($case) => [$case], ['student_inactive', 'student_role', 'coordinator_inactive', 'coordinator_role', 'coordinator_missing', 'supervisor_inactive', 'supervisor_role', 'supervisor_pending', 'supervisor_company', 'company_inactive', 'company_pending']);
    }

    #[DataProvider('invalidRecipients')]
    public function test_ineligible_recipient_is_skipped_without_notifying_other_users(string $case): void
    {
        $parent = $this->fixture();
        $this->coordinator();
        $task = $this->task($parent, 'IN_PROGRESS');
        [$target] = explode('_', $case);
        $user = match ($target) {
            'student' => $parent->student->user, 'coordinator' => $parent->coordinator->user, default => $parent->companySupervisor->user
        };
        if (str_ends_with($case, '_inactive') && $target !== 'company') {
            $user->update(['is_active' => false]);
        } elseif (str_ends_with($case, '_role')) {
            $user->update(['role_id' => Role::where('name', 'ADMIN')->sole()->id]);
        } elseif ($case === 'coordinator_missing') {
            $parent->update(['coordinator_id' => null]);
        } elseif ($case === 'supervisor_pending') {
            $user->companySupervisorProfile()->update(['verification_status' => 'PENDING']);
        } elseif ($case === 'supervisor_company') {
            $other = Company::create(['name' => 'Other company', 'is_active' => true, 'verification_status' => 'APPROVED']);
            $user->companySupervisorProfile()->update(['company_id' => $other->id]);
        } elseif ($case === 'company_inactive') {
            $parent->company()->update(['is_active' => false]);
        } elseif ($case === 'company_pending') {
            $parent->company()->update(['verification_status' => 'PENDING']);
        }
        if ($target === 'student') {
            app(TaskService::class)->save($parent->companySupervisor->user, ['title' => 'New task', 'description' => 'Work', 'priority' => 'MEDIUM'], $parent->id);
        } elseif ($target === 'coordinator') {
            $draft = app(SupervisorEvaluationService::class)->save($parent->companySupervisor->user, $parent->id, $this->ratings());
            $parent->update(['status' => 'ACTIVE']);
            app(SupervisorEvaluationService::class)->submit($parent->companySupervisor->user, $parent->id, $draft->finalEvaluation->draftToken());
        } else {
            app(StudentTaskService::class)->transition($parent->student->user, $task->id, ['submission_text' => 'Evidence']);
        }
        $this->assertDatabaseCount('notifications', 0);
    }

    private function perform(string $event, Internship $parent, Task $task): void
    {
        $supervisor = $parent->companySupervisor->user;
        $student = $parent->student->user;
        $coordinator = $parent->coordinator->user;
        match ($event) {
            'resubmit' => app(StudentInternshipService::class)->resubmit($student, $parent->id),
            'decision' => app(CoordinatorInternshipService::class)->decide($coordinator, $parent->id, ['decision' => 'APPROVED']),
            'activate' => app(SupervisorInternshipService::class)->activate($supervisor, $parent->id),
            'assign' => app(TaskService::class)->save($supervisor, ['title' => 'New task', 'description' => 'Work', 'priority' => 'MEDIUM'], $parent->id),
            'submit' => app(StudentTaskService::class)->transition($student, $task->id, ['submission_text' => 'Evidence']),
            'review' => app(TaskReviewService::class)->review($supervisor, $task->id, ['expected_submission_id' => $task->submissions()->sole()->id, 'decision' => 'APPROVED']),
            'evaluation' => app(SupervisorEvaluationService::class)->submit($supervisor, $parent->id, $parent->finalEvaluation()->sole()->draftToken()),
            'complete' => app(CoordinatorCompletionService::class)->complete($coordinator, $parent->id, $parent->finalEvaluation()->sole()->id),
        };
    }

    private function assertEvent(User $recipient, string $category, string $url, string $relatedType, int $relatedId): void
    {
        $notification = $recipient->notifications()->where('type', $category)->sole();
        $payload = $notification->data;
        $this->assertNull($notification->read_at);
        $this->assertSame($category, $payload['category']);
        $this->assertSame($url, $payload['action_url']);
        $this->assertSame($relatedType, $payload['related_type']);
        $this->assertSame($relatedId, $payload['related_id']);
        $this->assertEquals($payload, NotificationPayload::make($payload));
        $this->assertSame(now()->getTimestamp(), $notification->created_at->getTimestamp());
        $this->assertStringNotContainsString('Private', json_encode($payload));
        $this->assertSame($recipient->id, $notification->notifiable_id);
        $this->assertSame(User::class, $notification->notifiable_type);
    }

    private function ratings(): array
    {
        return [...array_fill_keys(FinalEvaluation::RATINGS, 4), 'comments' => 'Private final assessment must never be included in a notification.'];
    }

    private function coordinator(): User
    {
        $user = User::factory()->create(['role_id' => Role::where('name', 'ACADEMIC_COORDINATOR')->sole()->id, 'is_active' => true]);
        $user->academicCoordinatorProfile()->create(['academic_unit' => 'Engineering']);

        return $user;
    }

    private function fixture(string $status = 'ACTIVE'): Internship
    {
        $student = User::factory()->create(['role_id' => Role::where('name', 'STUDENT')->sole()->id, 'is_active' => true]);
        $student->studentProfile()->create(['student_number' => fake()->uuid(), 'study_program' => 'CS', 'study_year' => 2]);
        $company = Company::create(['name' => 'Isolated notification company', 'is_active' => true, 'verification_status' => 'APPROVED']);
        $supervisor = User::factory()->create(['role_id' => Role::where('name', 'COMPANY_SUPERVISOR')->sole()->id, 'is_active' => true]);
        $supervisor->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => 'APPROVED']);
        $coordinator = $this->coordinator();

        return Internship::create(['student_id' => $student->id, 'company_id' => $company->id, 'company_supervisor_id' => $supervisor->id,
            'coordinator_id' => $coordinator->id, 'position_title' => 'Private role title', 'description' => 'Private description', 'status' => $status,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'approved_at' => $status === 'APPROVED' ? now() : null]);
    }

    private function task(Internship $parent, string $status): Task
    {
        return $parent->tasks()->create(['assigned_by' => $parent->company_supervisor_id, 'title' => 'Private task title', 'description' => 'Private work', 'priority' => 'MEDIUM', 'status' => $status]);
    }
}
