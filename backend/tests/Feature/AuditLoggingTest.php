<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FinalEvaluation;
use App\Models\Internship;
use App\Models\Role;
use App\Models\User;
use App\Modules\Activity\Services\StudentActivityService;
use App\Modules\Company\Services\CompanyVerificationService;
use App\Modules\Company\Services\SupervisorVerificationService;
use App\Modules\Company\Services\VerificationApplicationService;
use App\Modules\Evaluation\Services\CoordinatorCompletionService;
use App\Modules\Evaluation\Services\SupervisorEvaluationService;
use App\Modules\Internship\Services\CoordinatorInternshipService;
use App\Modules\Internship\Services\StudentInternshipService;
use App\Modules\Internship\Services\SupervisorInternshipService;
use App\Modules\Task\Services\StudentTaskService;
use App\Modules\Task\Services\TaskReviewService;
use App\Modules\Task\Services\TaskService;
use App\Modules\User\Services\CoordinatorService;
use App\Modules\User\Services\UserActivationService;
use App\Shared\Enums\VerificationStatus;
use App\Shared\Services\AuditService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuditLoggingTest extends TestCase
{
    use DatabaseTransactions;

    private int $baseline;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'internflow_testing') {
            throw new RuntimeException('Audit tests require internflow_testing.');
        }
        $this->baseline = AuditLog::max('id') ?? 0;
        foreach (['ADMIN', 'STUDENT', 'ACADEMIC_COORDINATOR', 'COMPANY_SUPERVISOR'] as $role) {
            Role::firstOrCreate(['name' => $role], ['description' => $role]);
        }
    }

    private function user(string $role): User
    {
        $user = User::create(['first_name' => 'PrivateName', 'last_name' => 'PrivateSurname', 'email' => Str::uuid().'@example.test',
            'password' => 'PrivatePassword123', 'role_id' => Role::where('name', $role)->value('id'), 'is_active' => true]);
        if ($role === 'STUDENT') {
            $user->studentProfile()->create(['student_number' => Str::uuid(), 'study_program' => 'CS', 'study_year' => 3]);
        } elseif ($role === 'ACADEMIC_COORDINATOR') {
            $user->academicCoordinatorProfile()->create(['academic_unit' => 'Computing']);
        }

        return $user;
    }

    private function fixture(string $status = 'ACTIVE'): array
    {
        $student = $this->user('STUDENT');
        $coordinator = $this->user('ACADEMIC_COORDINATOR');
        $supervisor = $this->user('COMPANY_SUPERVISOR');
        $company = Company::create(['name' => 'PrivateCompany', 'is_active' => true, 'verification_status' => 'APPROVED']);
        $supervisor->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => 'APPROVED']);
        $internship = Internship::create(['student_id' => $student->id, 'company_id' => $company->id, 'company_supervisor_id' => $supervisor->id,
            'coordinator_id' => $status === 'DRAFT' ? null : $coordinator->id, 'status' => $status, 'position_title' => 'PrivateTitle',
            'description' => 'PrivateDescription', 'start_date' => today()->subDays(10), 'end_date' => today(),
            'approved_at' => in_array($status, ['APPROVED', 'ACTIVE'], true) ? now()->subDays(11) : null]);

        return compact('student', 'coordinator', 'supervisor', 'company', 'internship');
    }

    private function logs()
    {
        return AuditLog::where('id', '>', $this->baseline)->orderBy('id')->get();
    }

    private function assertAudit(string $event, User $actor, string $type, int $id): AuditLog
    {
        $log = $this->logs()->where('action', $event)->last();
        $this->assertNotNull($log, $event);
        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame($type, $log->entity_type);
        $this->assertSame($id, $log->entity_id);
        $this->assertNotNull($log->created_at);

        return $log;
    }

    private function assertPrivateDataExcluded(): void
    {
        foreach ($this->logs() as $log) {
            foreach ([$log->old_values, $log->new_values] as $metadata) {
                $this->assertEmpty(array_diff(array_keys($metadata ?? []), ['status', 'is_active', 'coordinator_id', 'hours', 'version_no', 'submitted']));
            }
            $this->assertStringNotContainsString('Private', json_encode($log->getAttributes()));
        }
    }

    public function test_admin_coordinator_and_activation_events_exclude_personal_data_and_no_ops(): void
    {
        $admin = $this->user('ADMIN');
        $data = ['first_name' => 'PrivateCoordinator', 'last_name' => 'PrivateSurname', 'email' => 'private-coordinator@example.test',
            'password' => 'PrivatePassword123', 'academic_unit' => 'PrivateUnit'];
        $this->actingAs($admin);
        $id = $this->postJson('/api/admin/academic-coordinators', $data)->assertCreated()->json('data.id');
        $target = User::findOrFail($id);
        $this->assertAudit('coordinator.created', $admin, 'user', $id);
        app(CoordinatorService::class)->update($target, ['first_name' => 'PrivateUpdated'], $admin);
        $this->assertAudit('coordinator.updated', $admin, 'user', $id);
        app(CoordinatorService::class)->update($target, ['first_name' => 'PrivateUpdated'], $admin);
        app(UserActivationService::class)->update($admin, $target, false);
        app(UserActivationService::class)->update($admin, $target, false);
        app(UserActivationService::class)->update($admin, $target, true);
        $this->assertAudit('user.deactivated', $admin, 'user', $id);
        $this->assertAudit('user.activated', $admin, 'user', $id);
        $this->assertCount(4, $this->logs());
        $this->assertPrivateDataExcluded();
    }

    public static function verificationDecisions(): array
    {
        return [['APPROVED'], ['REJECTED']];
    }

    #[DataProvider('verificationDecisions')]
    public function test_verification_records_decisions_without_private_reasons(string $decision): void
    {
        $f = $this->fixture();
        $admin = $this->user('ADMIN');
        $f['company']->update(['verification_status' => 'PENDING']);
        $f['supervisor']->companySupervisorProfile()->update(['verification_status' => 'PENDING']);
        app(CompanyVerificationService::class)->verify($f['company'], $admin, VerificationStatus::from($decision), 'PrivateReason');
        app(SupervisorVerificationService::class)->verify($f['supervisor'], $admin, VerificationStatus::from($decision), 'PrivateReason');
        $this->assertAudit('company.'.strtolower($decision), $admin, 'company', $f['company']->id);
        $this->assertAudit('supervisor.'.strtolower($decision), $admin, 'company_supervisor_profile', $f['supervisor']->id);
        $this->assertCount(2, $this->logs());
        $this->assertPrivateDataExcluded();
    }

    public function test_company_and_supervisor_corrections_and_resubmission_are_audited(): void
    {
        $f = $this->fixture();
        $f['company']->update(['verification_status' => 'REJECTED']);
        $f['supervisor']->companySupervisorProfile()->update(['verification_status' => 'REJECTED']);
        $service = app(VerificationApplicationService::class);
        $data = ['company' => ['name' => 'PrivateCorrection'], 'supervisor' => ['first_name' => 'PrivateCorrection']];
        $service->update($f['supervisor'], $data);
        $service->update($f['supervisor'], $data);
        $service->resubmit($f['supervisor'], ['company', 'supervisor']);
        foreach (['company.application_updated', 'company.resubmitted'] as $event) {
            $this->assertAudit($event, $f['supervisor'], 'company', $f['company']->id);
        }
        foreach (['supervisor.application_updated', 'supervisor.resubmitted'] as $event) {
            $this->assertAudit($event, $f['supervisor'], 'company_supervisor_profile', $f['supervisor']->id);
        }
        $this->assertCount(4, $this->logs());
        $this->assertPrivateDataExcluded();
    }

    public function test_submission_claim_review_approval_and_activation_have_correct_actors(): void
    {
        $f = $this->fixture('DRAFT');
        $id = $f['internship']->id;
        app(StudentInternshipService::class)->submit($f['student'], $id);
        app(CoordinatorInternshipService::class)->claim($f['coordinator'], $id);
        app(CoordinatorInternshipService::class)->startReview($f['coordinator'], $id);
        app(CoordinatorInternshipService::class)->decide($f['coordinator'], $id, ['decision' => 'APPROVED']);
        app(SupervisorInternshipService::class)->activate($f['supervisor'], $id);
        foreach (['submitted' => 'student', 'claimed' => 'coordinator', 'review_started' => 'coordinator', 'approved' => 'coordinator', 'activated' => 'supervisor'] as $event => $actor) {
            $this->assertAudit('internship.'.$event, $f[$actor], 'internship', $id);
        }
        $this->assertCount(5, $this->logs());
        $this->assertPrivateDataExcluded();
    }

    public static function applicationDecisions(): array
    {
        return [['REJECTED'], ['REVISION_REQUIRED']];
    }

    #[DataProvider('applicationDecisions')]
    public function test_negative_application_decisions_and_resubmission_do_not_log_comments(string $decision): void
    {
        $f = $this->fixture('UNDER_REVIEW');
        app(CoordinatorInternshipService::class)->decide($f['coordinator'], $f['internship']->id, ['decision' => $decision, 'decision_comment' => 'PrivateComment']);
        $this->assertAudit('internship.'.strtolower($decision), $f['coordinator'], 'internship', $f['internship']->id);
        if ($decision === 'REVISION_REQUIRED') {
            app(StudentInternshipService::class)->resubmit($f['student'], $f['internship']->id);
            $this->assertAudit('internship.resubmitted', $f['student'], 'internship', $f['internship']->id);
        }
        $this->assertPrivateDataExcluded();
    }

    public function test_task_versioned_submission_and_review_events_are_minimal_and_unique(): void
    {
        $f = $this->fixture();
        $task = app(TaskService::class)->save($f['supervisor'], ['title' => 'PrivateTask', 'description' => 'PrivateDescription', 'priority' => 'MEDIUM'], $f['internship']->id);
        app(TaskService::class)->save($f['supervisor'], ['title' => 'PrivateChanged'], null, $task->id);
        app(TaskService::class)->save($f['supervisor'], ['title' => 'PrivateChanged'], null, $task->id);
        $student = app(StudentTaskService::class);
        $student->transition($f['student'], $task->id);
        $student->transition($f['student'], $task->id, ['submission_text' => 'PrivateSubmission', 'resource_url' => 'https://private.example.test']);
        $first = $task->submissions()->sole();
        app(TaskReviewService::class)->review($f['supervisor'], $task->id, ['expected_submission_id' => $first->id, 'decision' => 'REVISION_REQUIRED', 'comment' => 'PrivateFeedback']);
        $student->transition($f['student'], $task->id, ['expected_submission_id' => $first->id, 'submission_text' => 'PrivateCorrection'], true);
        $latest = $task->submissions()->orderByDesc('version_no')->first();
        app(TaskReviewService::class)->review($f['supervisor'], $task->id, ['expected_submission_id' => $latest->id, 'decision' => 'APPROVED']);
        foreach (['created', 'updated', 'revision_required', 'approved'] as $event) {
            $this->assertAudit('task.'.$event, $f['supervisor'], 'task', $task->id);
        }
        $this->assertAudit('task.started', $f['student'], 'task', $task->id);
        $this->assertAudit('task.submitted', $f['student'], 'task', $task->id);
        $this->assertAudit('submission.created', $f['student'], 'task_submission', $latest->id);
        $this->assertCount(9, $this->logs());
        $this->assertEquals([1, 2], $this->logs()->where('action', 'submission.created')->pluck('new_values.version_no')->all());
        $this->assertPrivateDataExcluded();
    }

    public function test_activity_changes_log_only_hours_and_ignore_no_ops(): void
    {
        $f = $this->fixture();
        $service = app(StudentActivityService::class);
        $activity = $service->save($f['student'], ['title' => 'PrivateActivity', 'description' => 'PrivateDescription', 'activity_date' => today()->format('Y-m-d'), 'hours' => '2.50'], $f['internship']->id);
        $service->save($f['student'], ['hours' => '3.00'], null, $activity->id);
        $service->save($f['student'], ['hours' => '3.00'], null, $activity->id);
        $service->save($f['student'], ['description' => 'PrivateUpdated'], null, $activity->id);
        $this->assertAudit('activity.created', $f['student'], 'activity_log', $activity->id);
        $this->assertAudit('activity.updated', $f['student'], 'activity_log', $activity->id);
        $this->assertCount(3, $this->logs());
        $this->assertSame(['hours' => '2.50'], $this->logs()[1]->old_values);
        $this->assertSame(['hours' => '3.00'], $this->logs()[1]->new_values);
        $this->assertPrivateDataExcluded();
    }

    public function test_evaluation_submission_and_completion_have_no_ratings_comments_or_duplicate_confirmation(): void
    {
        $f = $this->fixture();
        $parent = app(SupervisorEvaluationService::class)->save($f['supervisor'], $f['internship']->id, [...array_fill_keys(FinalEvaluation::RATINGS, 4), 'comments' => 'Private evaluation comments with confidential evidence.']);
        $this->assertCount(0, $this->logs());
        app(SupervisorEvaluationService::class)->submit($f['supervisor'], $parent->id, $parent->finalEvaluation->draftToken());
        app(CoordinatorCompletionService::class)->complete($f['coordinator'], $parent->id, $parent->finalEvaluation->id);
        $this->assertAudit('evaluation.submitted', $f['supervisor'], 'final_evaluation', $parent->finalEvaluation->id);
        $this->assertAudit('internship.completed', $f['coordinator'], 'internship', $parent->id);
        try {
            app(CoordinatorCompletionService::class)->complete($f['coordinator'], $parent->id, $parent->finalEvaluation->id);
            $this->fail('Expected stale confirmation denial.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertCount(2, $this->logs());
        $this->assertPrivateDataExcluded();
    }

    public function test_failed_unauthorized_and_get_requests_create_no_audit_rows(): void
    {
        $f = $this->fixture('DRAFT');
        $this->actingAs($f['student'])->postJson('/api/admin/academic-coordinators', [])->assertForbidden();
        $this->getJson('/api/student/internships/'.$f['internship']->id)->assertOk();
        $this->postJson('/api/student/internships/'.$f['internship']->id.'/submit', ['status' => 'ACTIVE'])->assertUnprocessable();
        $this->actingAs($this->user('STUDENT'))->postJson('/api/student/internships/'.$f['internship']->id.'/submit')->assertNotFound();
        $this->assertCount(0, $this->logs());
    }

    public function test_stale_claim_and_review_attempts_do_not_add_duplicate_audits(): void
    {
        $f = $this->fixture('SUBMITTED');
        $f['internship']->update(['coordinator_id' => null]);
        $id = $f['internship']->id;
        $this->actingAs($f['coordinator'])->postJson('/api/coordinator/internships/'.$id.'/claim')->assertOk();
        $this->actingAs($this->user('ACADEMIC_COORDINATOR'))->postJson('/api/coordinator/internships/'.$id.'/claim')->assertConflict();
        $this->actingAs($f['coordinator'])->postJson('/api/coordinator/internships/'.$id.'/start-review')->assertOk();
        $this->postJson('/api/coordinator/internships/'.$id.'/start-review')->assertConflict();
        $this->assertCount(2, $this->logs());
        $this->assertAudit('internship.claimed', $f['coordinator'], 'internship', $id);
        $this->assertAudit('internship.review_started', $f['coordinator'], 'internship', $id);
    }

    public function test_audit_failure_rolls_back_existing_account_state(): void
    {
        $admin = $this->user('ADMIN');
        $target = $this->user('STUDENT');
        $event = 'eloquent.creating: '.AuditLog::class;
        Event::listen($event, fn () => throw new RuntimeException('Injected audit failure'));
        try {
            app(UserActivationService::class)->update($admin, $target, false);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected audit failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertTrue($target->fresh()->is_active);
        $this->assertCount(0, $this->logs());
    }

    public function test_audit_insert_failure_rolls_back_business_and_notification_writes(): void
    {
        $f = $this->fixture();
        $event = 'eloquent.creating: '.AuditLog::class;
        $notificationCount = DB::table('notifications')->count();
        Event::listen($event, fn () => throw new RuntimeException('Injected audit failure'));
        try {
            app(TaskService::class)->save($f['supervisor'], ['title' => 'PrivateTask', 'description' => 'PrivateDescription', 'priority' => 'MEDIUM'], $f['internship']->id);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected audit failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame(0, $f['internship']->tasks()->count());
        $this->assertSame($notificationCount, DB::table('notifications')->count());
        $this->assertCount(0, $this->logs());
    }

    public function test_outer_transaction_rollback_also_removes_audit_records(): void
    {
        $f = $this->fixture('DRAFT');
        try {
            DB::transaction(function () use ($f): void {
                app(StudentInternshipService::class)->submit($f['student'], $f['internship']->id);
                throw new RuntimeException('Outer rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Outer rollback', $exception->getMessage());
        }
        $this->assertSame('DRAFT', $f['internship']->fresh()->status);
        $this->assertCount(0, $this->logs());
    }

    public function test_writer_rejects_unrestricted_metadata_and_confidential_status_values(): void
    {
        $actor = $this->user('ADMIN');
        $forbidden = ['password', 'password_hash', 'token', 'email', 'phone', 'first_name', 'message', 'content', 'comment', 'submission_text', 'resource_url', 'storage_path', 'ratings', 'technical_skills', 'request'];
        $payloads = array_map(fn ($field) => [$field => 'PrivateSecret'], $forbidden);
        foreach ([...$payloads, ['status' => 'PrivatePayload'], ['hours' => ['PrivatePayload']], ['hours' => str_repeat('0', 1000)], ['coordinator_id' => 'PrivateEmail']] as $metadata) {
            try {
                app(AuditService::class)->record($actor, 'user.activated', $actor->id, [], $metadata);
                $this->fail('Unsafe metadata must not be accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('allowlisted', $exception->getMessage());
            }
        }
        $this->assertCount(0, $this->logs());
    }
}
