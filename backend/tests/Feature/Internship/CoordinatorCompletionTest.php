<?php

namespace Tests\Feature\Internship;

use App\Models\Company;
use App\Models\FinalEvaluation;
use App\Models\Internship;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskFeedback;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Modules\Evaluation\Services\CoordinatorCompletionService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CoordinatorCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-09 12:00:00', 'UTC'));
    }

    public function test_assigned_coordinator_views_safe_submitted_evaluation_without_accepting_or_completing_it(): void
    {
        $parent = $this->fixture();
        $evaluation = $this->evaluation($parent);
        $before = $this->snapshot($parent);
        $this->actingAs($parent->coordinator->user);
        $this->getJson($this->viewPath($parent))->assertOk()->assertJsonPath('data.evaluation.id', $evaluation->id)
            ->assertJsonPath('data.evaluation.overall_score', '4.50')->assertJsonPath('data.evaluation.teamwork', 3)
            ->assertJsonPath('data.evaluation.comments', 'Supervisor final assessment remains immutable.')
            ->assertJsonPath('data.internship.student.first_name', 'Student')->assertJsonPath('data.internship.company.name', 'Completion company')
            ->assertJsonPath('data.evaluation.evaluator.first_name', 'Supervisor')->assertJsonPath('data.can_complete', true)
            ->assertJsonMissingPath('data.evaluation.draft_token')->assertJsonMissingPath('data.evaluation.evaluator.password');
        $this->assertSame($before, $this->snapshot($parent));
    }

    public function test_missing_and_draft_evaluations_are_indistinguishable_and_cannot_complete(): void
    {
        $parent = $this->fixture();
        $this->actingAs($parent->coordinator->user);
        foreach ([false, true] as $draft) {
            if ($draft) {
                $parent->finalEvaluation()->create(['evaluator_id' => $parent->company_supervisor_id, 'technical_skills' => 4, 'comments' => 'Private supervisor draft']);
            }
            $this->getJson($this->viewPath($parent))->assertOk()->assertJsonPath('data.evaluation', null)
                ->assertJsonPath('data.can_complete', false)->assertJsonPath('data.completion_blocker', 'MISSING_SUBMITTED_EVALUATION')
                ->assertJsonMissing(['Private supervisor draft']);
            $this->postJson($this->completePath($parent), ['expected_evaluation_id' => 1])->assertConflict();
        }
        $this->assertSame('ACTIVE', $parent->fresh()->status);
        $this->assertNull($parent->fresh()->completed_at);
    }

    public function test_completion_preserves_evaluation_and_all_evidence_and_retains_completed_monitoring(): void
    {
        $parent = $this->fixture();
        $evaluation = $this->evaluation($parent);
        $task = $this->task($parent, 'APPROVED');
        $submission = $task->submissions()->create(['version_no' => 1, 'submission_text' => 'Completed work', 'submitted_at' => now()]);
        $submission->feedback()->create(['supervisor_id' => $parent->company_supervisor_id, 'decision' => 'APPROVED', 'comment' => 'Verified']);
        $activity = $parent->activityLogs()->create(['activity_date' => '2026-10-08', 'title' => 'Work diary', 'description' => 'Actual work', 'hours' => '0.50']);
        $before = $this->snapshot($parent);
        $this->actingAs($parent->coordinator->user);
        $response = $this->postJson($this->completePath($parent), ['expected_evaluation_id' => $evaluation->id])->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')->assertJsonPath('data.coordinator_id', $parent->coordinator_id)
            ->assertJsonPath('data.tasks_count', 1)->assertJsonPath('data.approved_tasks_count', 1);
        $this->assertNotNull($response->json('data.completed_at'));
        $fresh = $parent->fresh();
        $this->assertTrue($fresh->completed_at->equalTo(now()));
        $after = $this->snapshot($parent);
        foreach (['evaluation', 'activities', 'tasks', 'submissions', 'feedback'] as $part) {
            $this->assertSame($before[$part], $after[$part]);
        }
        $this->assertSame($before['parent']['student_id'], $after['parent']['student_id']);
        $this->assertSame($before['parent']['company_supervisor_id'], $after['parent']['company_supervisor_id']);
        $this->assertDatabaseCount('notifications', 0);
        foreach ([$this->viewPath($parent), '/api/coordinator/monitoring/internships/'.$parent->id,
            '/api/coordinator/monitoring/internships/'.$parent->id.'/activities', '/api/coordinator/monitoring/activities/'.$activity->id,
            '/api/coordinator/monitoring/internships/'.$parent->id.'/tasks', '/api/coordinator/monitoring/tasks/'.$task->id,
            '/api/coordinator/monitoring/tasks/'.$task->id.'/submissions'] as $path) {
            $this->getJson($path)->assertOk();
        }
        $this->getJson($this->viewPath($parent))->assertJsonPath('data.can_complete', false)->assertJsonPath('data.completion_blocker', 'COMPLETED');
    }

    public function test_duplicate_completion_does_not_overwrite_server_timestamp(): void
    {
        $parent = $this->fixture();
        $evaluation = $this->evaluation($parent);
        $this->actingAs($parent->coordinator->user);
        $payload = ['expected_evaluation_id' => $evaluation->id];
        $this->postJson($this->completePath($parent), $payload)->assertOk();
        $before = $this->snapshot($parent);
        $this->travelTo(now()->addDays(3));
        $this->postJson($this->completePath($parent), $payload)->assertConflict();
        $this->assertSame($before, $this->snapshot($parent));
    }

    public function test_end_date_is_inclusive_and_uses_configured_calendar_timezone(): void
    {
        config(['app.timezone' => 'Europe/Warsaw']);
        $parent = $this->fixture();
        $evaluation = $this->evaluation($parent);
        $this->actingAs($parent->coordinator->user);
        $this->travelTo(Carbon::parse('2026-10-08 21:59:00', 'UTC'));
        $this->getJson($this->viewPath($parent))->assertJsonPath('data.completion_blocker', 'END_DATE_NOT_REACHED');
        $this->postJson($this->completePath($parent), ['expected_evaluation_id' => $evaluation->id])->assertConflict();
        $this->travelTo(Carbon::parse('2026-10-08 22:00:00', 'UTC'));
        $this->postJson($this->completePath($parent), ['expected_evaluation_id' => $evaluation->id])->assertOk();
    }

    public static function nonActiveStatuses(): array
    {
        return array_map(fn ($status) => [$status], ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REVISION_REQUIRED', 'REJECTED', 'COMPLETED']);
    }

    #[DataProvider('nonActiveStatuses')]
    public function test_only_active_internship_can_complete(string $status): void
    {
        $parent = $this->fixture();
        $evaluation = $this->evaluation($parent);
        $parent->update(['status' => $status]);
        $before = $this->snapshot($parent);
        $this->actingAs($parent->coordinator->user)->postJson($this->completePath($parent), ['expected_evaluation_id' => $evaluation->id])->assertConflict();
        $this->assertSame($before, $this->snapshot($parent));
    }

    public function test_task_review_readiness_is_rechecked_without_inventing_task_approval_or_hours_minimum(): void
    {
        $parent = $this->fixture();
        $evaluation = $this->evaluation($parent);
        $this->actingAs($parent->coordinator->user);
        $task = $this->task($parent, 'SUBMITTED');
        foreach (['SUBMITTED', 'REVISION_REQUIRED'] as $status) {
            $task->update(['status' => $status]);
            $this->getJson($this->viewPath($parent))->assertJsonPath('data.completion_blocker', 'UNRESOLVED_TASKS');
            $this->postJson($this->completePath($parent), ['expected_evaluation_id' => $evaluation->id])->assertConflict();
        }
        $task->update(['status' => 'APPROVED']);
        $submission = $task->submissions()->create(['version_no' => 1, 'submission_text' => 'Review missing', 'submitted_at' => now()]);
        $this->postJson($this->completePath($parent), ['expected_evaluation_id' => $evaluation->id])->assertConflict();
        $submission->feedback()->create(['supervisor_id' => $parent->company_supervisor_id, 'decision' => 'APPROVED']);
        foreach (['ASSIGNED', 'IN_PROGRESS', 'CANCELLED'] as $status) {
            $this->task($parent, $status);
        }
        $this->postJson($this->completePath($parent), ['expected_evaluation_id' => $evaluation->id])->assertOk();
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_foreign_and_unassigned_internships_are_hidden_before_validation(): void
    {
        $parent = $this->fixture();
        $this->evaluation($parent);
        $foreign = $this->fixture();
        $this->actingAs($foreign->coordinator->user);
        $this->getJson($this->viewPath($parent))->assertNotFound();
        $this->postJson($this->completePath($parent), ['status' => 'COMPLETED'])->assertNotFound();
        $parent->update(['coordinator_id' => null]);
        $this->getJson($this->viewPath($parent))->assertNotFound();
        $this->postJson($this->completePath($parent), [])->assertNotFound();
    }

    public function test_guest_wrong_roles_inactive_and_missing_profile_coordinators_are_denied(): void
    {
        $parent = $this->fixture();
        $evaluation = $this->evaluation($parent);
        $payload = ['expected_evaluation_id' => $evaluation->id];
        $this->getJson($this->viewPath($parent))->assertUnauthorized();
        $this->postJson($this->completePath($parent), $payload)->assertUnauthorized();
        foreach (['ADMIN', 'STUDENT', 'COMPANY_SUPERVISOR', 'ACADEMIC_COORDINATOR'] as $role) {
            $this->actingAs($this->user($role));
            $this->getJson($this->viewPath($parent))->assertForbidden();
            $this->postJson($this->completePath($parent), $payload)->assertForbidden();
        }
        $actor = $parent->coordinator->user;
        $actor->update(['is_active' => false]);
        $this->actingAs($actor->fresh())->getJson($this->viewPath($parent))->assertForbidden();
        $this->postJson($this->completePath($parent), $payload)->assertForbidden();
        $this->assertSame('ACTIVE', $parent->fresh()->status);
    }

    public function test_mass_assignment_invalid_ids_and_wrong_evaluation_cannot_manipulate_completion(): void
    {
        $parent = $this->fixture();
        $evaluation = $this->evaluation($parent);
        $foreign = $this->fixture();
        $otherEvaluation = $this->evaluation($foreign);
        $this->actingAs($parent->coordinator->user);
        foreach (['id', 'coordinator_id', 'student_id', 'company_id', 'company_supervisor_id', 'status', 'completed_at', 'comments', 'overall_score', 'unknown'] as $field) {
            $this->postJson($this->completePath($parent), ['expected_evaluation_id' => $evaluation->id, $field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach ([null, 0, -1, [], true, 'invalid'] as $value) {
            $this->postJson($this->completePath($parent), ['expected_evaluation_id' => $value])->assertUnprocessable();
        }
        $this->postJson($this->completePath($parent), ['expected_evaluation_id' => $otherEvaluation->id])->assertConflict();
        foreach (['missing', '-1', '999999999'] as $id) {
            $this->getJson('/api/coordinator/monitoring/internships/'.$id.'/final-evaluation')->assertNotFound();
            $this->postJson('/api/coordinator/internships/'.$id.'/complete', [])->assertNotFound();
        }
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->json($method, $this->viewPath($parent), [])->assertMethodNotAllowed();
        }
        $this->assertNull($parent->fresh()->completed_at);
    }

    public function test_stale_service_operation_rechecks_authorization_and_database_state(): void
    {
        $parent = $this->fixture();
        $evaluation = $this->evaluation($parent);
        $actor = $parent->coordinator->user;
        $service = app(CoordinatorCompletionService::class);
        $service->complete($actor, $parent->id, $evaluation->id);
        $before = $this->snapshot($parent);
        try {
            $service->complete($actor, $parent->id, $evaluation->id);
            $this->fail('Stale completion must fail.');
        } catch (HttpException $failure) {
            $this->assertSame(409, $failure->getStatusCode());
        }
        $actor->update(['is_active' => false]);
        try {
            $service->complete($actor, $parent->id, $evaluation->id);
            $this->fail('Inactive stale actor must fail.');
        } catch (HttpException $failure) {
            $this->assertSame(403, $failure->getStatusCode());
        }
        $this->assertSame($before, $this->snapshot($parent));
    }

    public function test_completed_timestamp_already_set_is_never_overwritten_even_if_status_is_inconsistent(): void
    {
        $parent = $this->fixture();
        $evaluation = $this->evaluation($parent);
        $parent->update(['completed_at' => now()->subDay()]);
        $before = $parent->fresh()->getAttributes();
        $this->actingAs($parent->coordinator->user)->postJson($this->completePath($parent), ['expected_evaluation_id' => $evaluation->id])->assertConflict();
        $this->assertSame($before, $parent->fresh()->getAttributes());
    }

    public function test_completion_clock_is_not_shifted_by_postgresql_session_timezone(): void
    {
        foreach (['UTC', 'Europe/Warsaw', 'America/New_York'] as $timezone) {
            DB::selectOne("SELECT set_config('TimeZone', ?, true)", [$timezone]);
            $parent = $this->fixture();
            $evaluation = $this->evaluation($parent);
            $this->actingAs($parent->coordinator->user)->postJson($this->completePath($parent), ['expected_evaluation_id' => $evaluation->id])->assertOk();
            $this->assertTrue($parent->fresh()->completed_at->equalTo(now()));
        }
    }

    public function test_readiness_and_assignment_changes_after_view_are_rechecked_on_completion(): void
    {
        $parent = $this->fixture();
        $evaluation = $this->evaluation($parent);
        $actor = $parent->coordinator->user;
        $this->actingAs($actor)->getJson($this->viewPath($parent))->assertJsonPath('data.can_complete', true);
        $this->task($parent, 'SUBMITTED');
        $this->postJson($this->completePath($parent), ['expected_evaluation_id' => $evaluation->id])->assertConflict();
        $other = $this->fixture();
        $parent->update(['coordinator_id' => $other->coordinator_id]);
        $this->postJson($this->completePath($parent), ['expected_evaluation_id' => $evaluation->id])->assertNotFound();
        $this->assertSame('ACTIVE', $parent->fresh()->status);
        $this->assertNull($parent->fresh()->completed_at);
    }

    public function test_supervisor_submission_clock_remains_identical_in_response_storage_and_coordinator_review(): void
    {
        foreach (['UTC', 'Europe/Warsaw', 'America/New_York'] as $timezone) {
            DB::selectOne("SELECT set_config('TimeZone', ?, true)", [$timezone]);
            $parent = $this->fixture();
            $path = '/api/supervisor/internships/'.$parent->id.'/final-evaluation';
            $this->actingAs($parent->companySupervisor->user);
            $token = $this->putJson($path, [...array_fill_keys(FinalEvaluation::RATINGS, 4), 'comments' => 'Consistent assessment timestamp across the final review integration.'])->assertOk()->json('data.evaluation.draft_token');
            $response = $this->postJson($path.'/submit', ['draft_token' => $token])->assertOk();
            $this->assertTrue(Carbon::parse($response->json('data.evaluation.submitted_at'))->equalTo(now()));
            $this->assertTrue($parent->finalEvaluation()->sole()->submitted_at->equalTo(now()));
            $this->assertSame('ACTIVE', $parent->fresh()->status);
            $this->actingAs($parent->coordinator->user);
            $review = $this->getJson($this->viewPath($parent))->assertOk();
            $this->assertSame($response->json('data.evaluation.submitted_at'), $review->json('data.evaluation.submitted_at'));
            $this->postJson($this->completePath($parent), ['expected_evaluation_id' => $review->json('data.evaluation.id')])->assertOk();
            $this->assertTrue($parent->fresh()->completed_at->equalTo(now()));
        }
    }

    private function snapshot(Internship $parent): array
    {
        return ['parent' => $parent->fresh()->getAttributes(), 'evaluation' => $parent->finalEvaluation()->first()?->getAttributes(),
            'activities' => $parent->activityLogs()->get()->map->getAttributes()->all(),
            'tasks' => $parent->tasks()->get()->map->getAttributes()->all(),
            'submissions' => TaskSubmission::whereHas('task', fn ($query) => $query->where('internship_id', $parent->id))->get()->map->getAttributes()->all(),
            'feedback' => TaskFeedback::whereHas('submission.task', fn ($query) => $query->where('internship_id', $parent->id))->get()->map->getAttributes()->all()];
    }

    private function viewPath(Internship $parent): string
    {
        return '/api/coordinator/monitoring/internships/'.$parent->id.'/final-evaluation';
    }

    private function completePath(Internship $parent): string
    {
        return '/api/coordinator/internships/'.$parent->id.'/complete';
    }

    private function evaluation(Internship $parent): FinalEvaluation
    {
        return $parent->finalEvaluation()->create(['evaluator_id' => $parent->company_supervisor_id, ...array_fill_keys(FinalEvaluation::RATINGS, 4),
            'teamwork' => 3, 'overall_score' => '4.50', 'comments' => 'Supervisor final assessment remains immutable.', 'submitted_at' => now()]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->sole()->id, 'is_active' => true,
            'first_name' => match ($role) {
                'STUDENT' => 'Student', 'COMPANY_SUPERVISOR' => 'Supervisor', default => 'Coordinator'
            }]);
    }

    private function fixture(): Internship
    {
        $student = $this->user('STUDENT');
        $student->studentProfile()->create(['student_number' => fake()->uuid(), 'study_program' => 'CS', 'study_year' => 2]);
        $company = Company::create(['name' => 'Completion company', 'is_active' => true, 'verification_status' => 'APPROVED']);
        $supervisor = $this->user('COMPANY_SUPERVISOR');
        $supervisor->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => 'APPROVED']);
        $coordinator = $this->user('ACADEMIC_COORDINATOR');
        $coordinator->academicCoordinatorProfile()->create(['academic_unit' => 'Engineering']);

        return Internship::create(['student_id' => $student->id, 'company_id' => $company->id, 'company_supervisor_id' => $supervisor->id,
            'coordinator_id' => $coordinator->id, 'position_title' => 'Final review internship', 'status' => 'ACTIVE', 'start_date' => '2026-10-01', 'end_date' => '2026-10-09']);
    }

    private function task(Internship $parent, string $status): Task
    {
        return Task::create(['internship_id' => $parent->id, 'assigned_by' => $parent->company_supervisor_id, 'title' => 'Completion evidence',
            'description' => 'Work', 'priority' => 'MEDIUM', 'status' => $status]);
    }
}
