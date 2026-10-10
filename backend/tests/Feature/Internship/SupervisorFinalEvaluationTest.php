<?php

namespace Tests\Feature\Internship;

use App\Models\Company;
use App\Models\FinalEvaluation;
use App\Models\Internship;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Modules\Evaluation\Services\SupervisorEvaluationService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SupervisorFinalEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-09 12:00:00', 'UTC'));
    }

    public function test_empty_draft_creation_update_and_reload_preserve_partial_progress(): void
    {
        $parent = $this->fixture();
        $this->actingAs($parent->companySupervisor->user);
        $this->getJson($this->path($parent))->assertOk()->assertJsonPath('data.evaluation', null)->assertJsonPath('data.submission_blocker', 'NO_DRAFT');
        $response = $this->putJson($this->path($parent), ['technical_skills' => 4, 'comments' => '  In progress  '])->assertOk()
            ->assertJsonPath('data.evaluation.status', 'DRAFT')->assertJsonPath('data.evaluation.technical_skills', 4)
            ->assertJsonPath('data.evaluation.comments', 'In progress')->assertJsonPath('data.evaluation.submitted_at', null);
        $token = $response->json('data.evaluation.draft_token');
        $this->getJson($this->path($parent))->assertJsonPath('data.evaluation.draft_token', $token);
        $this->putJson($this->path($parent), ['quality_of_work' => 5])->assertOk()->assertJsonPath('data.evaluation.technical_skills', 4)
            ->assertJsonPath('data.evaluation.quality_of_work', 5);
        $this->assertDatabaseCount('final_evaluations', 1);
        $this->assertSame('ACTIVE', $parent->fresh()->status);
        $this->assertNull($parent->fresh()->completed_at);
    }

    public function test_submission_is_immutable_and_does_not_complete_or_create_other_workflow_records(): void
    {
        $parent = $this->fixture();
        $this->actingAs($parent->companySupervisor->user);
        $draft = $this->putJson($this->path($parent), $this->ratings())->assertOk();
        $token = $draft->json('data.evaluation.draft_token');
        $before = $parent->fresh()->getAttributes();
        $this->postJson($this->path($parent).'/submit', ['draft_token' => $token])->assertOk()
            ->assertJsonPath('data.evaluation.status', 'SUBMITTED')->assertJsonPath('data.evaluation.overall_score', 4)
            ->assertJsonPath('data.can_edit', false)->assertJsonPath('data.can_submit', false)->assertJsonPath('data.evaluation.draft_token', null);
        $evaluation = FinalEvaluation::sole()->getAttributes();
        $this->assertNotNull(FinalEvaluation::sole()->submitted_at);
        $this->putJson($this->path($parent), $this->ratings())->assertConflict();
        $this->postJson($this->path($parent).'/submit', ['draft_token' => $token])->assertConflict();
        $this->deleteJson($this->path($parent))->assertMethodNotAllowed();
        $this->assertSame($evaluation, FinalEvaluation::sole()->getAttributes());
        $this->assertSame($before, $parent->fresh()->getAttributes());
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('task_feedback', 0);
    }

    public static function ratingFields(): array
    {
        return array_map(fn ($field) => [$field], FinalEvaluation::RATINGS);
    }

    #[DataProvider('ratingFields')]
    public function test_rating_range_and_required_final_criteria(string $field): void
    {
        $parent = $this->fixture();
        $this->actingAs($parent->companySupervisor->user);
        foreach ([0, 6, -1, 2.5, 'bad', [], true] as $value) {
            $this->putJson($this->path($parent), [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $data = $this->ratings();
        unset($data[$field]);
        $token = $this->putJson($this->path($parent), $data)->assertOk()->json('data.evaluation.draft_token');
        $this->postJson($this->path($parent).'/submit', ['draft_token' => $token])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertNull(FinalEvaluation::sole()->submitted_at);
    }

    public function test_final_comments_are_required_meaningful_and_length_bounded(): void
    {
        $parent = $this->fixture();
        $this->actingAs($parent->companySupervisor->user);
        foreach ([null, '', '   ', 'Too short'] as $comments) {
            $token = $this->putJson($this->path($parent), [...$this->ratings(), 'comments' => $comments])->assertOk()->json('data.evaluation.draft_token');
            $this->postJson($this->path($parent).'/submit', ['draft_token' => $token])->assertUnprocessable()->assertJsonValidationErrors('comments');
        }
        foreach ([str_repeat('a', 10001), ['invalid'], 12] as $comments) {
            $this->putJson($this->path($parent), ['comments' => $comments])->assertUnprocessable();
        }
    }

    public function test_draft_is_allowed_before_end_date_but_submission_uses_local_calendar_date(): void
    {
        config(['app.timezone' => 'Europe/Warsaw']);
        $this->travelTo(Carbon::parse('2026-10-08 21:59:00', 'UTC'));
        $parent = $this->fixture();
        $this->actingAs($parent->companySupervisor->user);
        $draft = $this->putJson($this->path($parent), $this->ratings())->assertOk()->assertJsonPath('data.submission_blocker', 'END_DATE_NOT_REACHED');
        $token = $draft->json('data.evaluation.draft_token');
        $this->postJson($this->path($parent).'/submit', ['draft_token' => $token])->assertConflict();
        $this->travelTo(Carbon::parse('2026-10-08 22:00:00', 'UTC'));
        $this->postJson($this->path($parent).'/submit', ['draft_token' => $token])->assertOk()->assertJsonPath('data.server_date', '2026-10-09');
    }

    public function test_non_active_internships_block_writes_and_submission_without_hiding_eligibility(): void
    {
        $parent = $this->fixture();
        $this->actingAs($parent->companySupervisor->user);
        $token = $this->putJson($this->path($parent), $this->ratings())->json('data.evaluation.draft_token');
        foreach (['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REVISION_REQUIRED', 'REJECTED', 'COMPLETED'] as $status) {
            $parent->update(['status' => $status]);
            $this->getJson($this->path($parent))->assertOk()->assertJsonPath('data.submission_blocker', 'NOT_ACTIVE')->assertJsonPath('data.can_edit', false);
            $this->putJson($this->path($parent), $this->ratings())->assertConflict();
            $this->postJson($this->path($parent).'/submit', ['draft_token' => $token])->assertConflict();
        }
    }

    public function test_pending_reviews_and_revisions_block_but_no_invented_task_or_hours_minimum_applies(): void
    {
        $parent = $this->fixture();
        $this->actingAs($parent->companySupervisor->user);
        $token = $this->putJson($this->path($parent), $this->ratings())->json('data.evaluation.draft_token');
        $task = $this->task($parent, 'SUBMITTED');
        foreach (['SUBMITTED', 'REVISION_REQUIRED'] as $status) {
            $task->update(['status' => $status]);
            $this->getJson($this->path($parent))->assertJsonPath('data.submission_blocker', 'UNRESOLVED_TASKS');
            $this->postJson($this->path($parent).'/submit', ['draft_token' => $token])->assertConflict();
        }
        $task->update(['status' => 'APPROVED']);
        $submission = $task->submissions()->create(['version_no' => 1, 'submission_text' => 'Unreviewed work', 'submitted_at' => now()]);
        $this->postJson($this->path($parent).'/submit', ['draft_token' => $token])->assertConflict();
        $submission->feedback()->create(['supervisor_id' => $parent->company_supervisor_id, 'decision' => 'APPROVED', 'comment' => 'Reviewed']);
        foreach (['ASSIGNED', 'IN_PROGRESS', 'CANCELLED'] as $status) {
            $this->task($parent, $status);
        }
        $this->postJson($this->path($parent).'/submit', ['draft_token' => $token])->assertOk();
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_ownership_is_direct_and_company_scoped_before_payload_validation(): void
    {
        $parent = $this->fixture();
        $foreign = $this->fixture();
        $sameCompany = $this->user('COMPANY_SUPERVISOR');
        $sameCompany->companySupervisorProfile()->create(['company_id' => $parent->company_id, 'verification_status' => 'APPROVED']);
        foreach ([$foreign->companySupervisor->user, $sameCompany] as $actor) {
            $this->actingAs($actor);
            $this->getJson($this->path($parent))->assertNotFound();
            $this->putJson($this->path($parent), ['technical_skills' => 9])->assertNotFound();
            $this->postJson($this->path($parent).'/submit', [])->assertNotFound();
        }
        $this->actingAs($parent->companySupervisor->user);
        $parent->update(['company_id' => $foreign->company_id]);
        $this->getJson($this->path($parent))->assertNotFound();
        $this->putJson($this->path($parent), $this->ratings())->assertNotFound();
    }

    public function test_guests_wrong_roles_and_ineligible_accounts_or_companies_cannot_read_or_write(): void
    {
        $parent = $this->fixture();
        foreach (['GET', 'PUT', 'POST'] as $method) {
            $this->json($method, $this->path($parent).($method === 'POST' ? '/submit' : ''), [])->assertUnauthorized();
        }
        foreach (['STUDENT', 'ACADEMIC_COORDINATOR', 'ADMIN', 'COMPANY_SUPERVISOR'] as $role) {
            $this->actingAs($this->user($role));
            foreach (['GET', 'PUT', 'POST'] as $method) {
                $this->json($method, $this->path($parent).($method === 'POST' ? '/submit' : ''), [])->assertForbidden();
            }
        }
        $actor = $parent->companySupervisor->user;
        foreach ([['user', 'is_active', false], ['profile', 'verification_status', 'PENDING'], ['profile', 'verification_status', 'REJECTED'],
            ['company', 'is_active', false], ['company', 'verification_status', 'PENDING'], ['company', 'verification_status', 'REJECTED']] as [$target, $field, $value]) {
            $model = match ($target) {
                'user' => $actor, 'profile' => $actor->companySupervisorProfile, 'company' => $parent->company
            };
            $original = $model->$field;
            $model->update([$field => $value]);
            $this->actingAs($actor->fresh());
            foreach (['GET', 'PUT', 'POST'] as $method) {
                $this->json($method, $this->path($parent).($method === 'POST' ? '/submit' : ''), [])->assertForbidden();
            }
            $model->update([$field => $original]);
        }
        $this->assertDatabaseCount('final_evaluations', 0);
    }

    public function test_protected_fields_and_invalid_ids_are_rejected(): void
    {
        $parent = $this->fixture();
        $this->actingAs($parent->companySupervisor->user);
        foreach (['id', 'internship_id', 'evaluator_id', 'status', 'submitted_at', 'completed_at', 'unknown', 'draft_token'] as $field) {
            $this->putJson($this->path($parent), [...$this->ratings(), $field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $token = $this->putJson($this->path($parent), $this->ratings())->json('data.evaluation.draft_token');
        $this->postJson($this->path($parent).'/submit', ['draft_token' => $token, 'status' => 'SUBMITTED'])->assertUnprocessable();
        foreach (['missing', '-1', '999999999'] as $id) {
            $this->getJson('/api/supervisor/internships/'.$id.'/final-evaluation')->assertNotFound();
        }
        $this->assertNull(FinalEvaluation::sole()->submitted_at);
    }

    public function test_changed_draft_token_and_repeated_stale_service_calls_cannot_submit_twice(): void
    {
        $parent = $this->fixture();
        $actor = $parent->companySupervisor->user;
        $this->actingAs($actor);
        $old = $this->putJson($this->path($parent), $this->ratings())->json('data.evaluation.draft_token');
        $new = $this->putJson($this->path($parent), ['technical_skills' => 5])->json('data.evaluation.draft_token');
        $this->assertNotSame($old, $new);
        $this->postJson($this->path($parent).'/submit', ['draft_token' => $old])->assertConflict();
        $service = app(SupervisorEvaluationService::class);
        $service->submit($actor, $parent->id, $new);
        try {
            $service->submit($actor, $parent->id, $new);
            $this->fail('Stale submission must fail.');
        } catch (HttpException $failure) {
            $this->assertSame(409, $failure->getStatusCode());
        }
        $this->assertDatabaseCount('final_evaluations', 1);
    }

    public function test_database_unique_constraint_prevents_duplicate_evaluations(): void
    {
        $parent = $this->fixture();
        $this->actingAs($parent->companySupervisor->user)->putJson($this->path($parent), [])->assertOk();
        try {
            DB::transaction(fn () => FinalEvaluation::create(['internship_id' => $parent->id, 'evaluator_id' => $parent->company_supervisor_id]));
            $this->fail('Duplicate row must fail.');
        } catch (QueryException $failure) {
            $this->assertSame('23505', (string) $failure->getCode());
        }
        $this->assertDatabaseCount('final_evaluations', 1);
    }

    public function test_service_rechecks_current_actor_company_assignment_and_readiness(): void
    {
        $parent = $this->fixture();
        $actor = $parent->companySupervisor->user;
        $this->actingAs($actor);
        $token = $this->putJson($this->path($parent), $this->ratings())->json('data.evaluation.draft_token');
        $parent->company->update(['is_active' => false]);
        try {
            app(SupervisorEvaluationService::class)->submit($actor, $parent->id, $token);
            $this->fail('Current company must be checked under the transaction.');
        } catch (HttpException $failure) {
            $this->assertSame(403, $failure->getStatusCode());
        }
        $this->assertNull(FinalEvaluation::sole()->submitted_at);
    }

    public function test_legacy_submitted_ratings_are_preserved_and_remain_read_only(): void
    {
        $parent = $this->fixture();
        $legacy = FinalEvaluation::create(['internship_id' => $parent->id, 'evaluator_id' => $parent->company_supervisor_id,
            'technical_skills' => 5, 'communication' => 4, 'teamwork' => 3, 'responsibility' => 4, 'overall_score' => '4.50',
            'comments' => 'Existing evaluation remains unchanged.', 'submitted_at' => now()]);
        $before = $legacy->fresh()->getAttributes();
        $this->actingAs($parent->companySupervisor->user);
        $this->getJson($this->path($parent))->assertOk()->assertJsonPath('data.evaluation.status', 'SUBMITTED')
            ->assertJsonPath('data.evaluation.quality_of_work', null)->assertJsonPath('data.evaluation.initiative', null)->assertJsonPath('data.evaluation.overall_score', 4.5);
        $this->putJson($this->path($parent), $this->ratings())->assertConflict();
        $this->assertSame($before, $legacy->fresh()->getAttributes());
    }

    public function test_another_evaluators_draft_is_never_exposed_or_taken_over(): void
    {
        $parent = $this->fixture();
        $other = $this->user('COMPANY_SUPERVISOR');
        $other->companySupervisorProfile()->create(['company_id' => $parent->company_id, 'verification_status' => 'APPROVED']);
        FinalEvaluation::create(['internship_id' => $parent->id, 'evaluator_id' => $other->id, 'comments' => 'Private draft']);
        $this->actingAs($parent->companySupervisor->user);
        $this->getJson($this->path($parent))->assertNotFound();
        $this->putJson($this->path($parent), [])->assertNotFound();
        $this->postJson($this->path($parent).'/submit', [])->assertNotFound();
        $this->assertSame('Private draft', FinalEvaluation::sole()->comments);
    }

    public function test_submission_requires_a_valid_draft_token_and_current_task_readiness(): void
    {
        $parent = $this->fixture();
        $this->actingAs($parent->companySupervisor->user);
        $this->postJson($this->path($parent).'/submit', [])->assertConflict();
        $token = $this->putJson($this->path($parent), $this->ratings())->json('data.evaluation.draft_token');
        foreach ([null, 'invalid', str_repeat('z', 64), []] as $invalid) {
            $this->postJson($this->path($parent).'/submit', ['draft_token' => $invalid])->assertUnprocessable();
        }
        $this->task($parent, 'SUBMITTED');
        $this->postJson($this->path($parent).'/submit', ['draft_token' => $token])->assertConflict();
        $this->assertNull(FinalEvaluation::sole()->submitted_at);
    }

    private function ratings(): array
    {
        return [...array_fill_keys(FinalEvaluation::RATINGS, 4), 'comments' => 'The student demonstrated consistent professional work and technical progress.'];
    }

    private function path(Internship $parent): string
    {
        return '/api/supervisor/internships/'.$parent->id.'/final-evaluation';
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->sole()->id, 'is_active' => true]);
    }

    private function fixture(): Internship
    {
        $student = $this->user('STUDENT');
        $student->studentProfile()->create(['student_number' => fake()->uuid(), 'study_program' => 'CS', 'study_year' => 2]);
        $company = Company::create(['name' => 'Evaluation company', 'is_active' => true, 'verification_status' => 'APPROVED']);
        $supervisor = $this->user('COMPANY_SUPERVISOR');
        $supervisor->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => 'APPROVED']);

        return Internship::create(['student_id' => $student->id, 'company_id' => $company->id, 'company_supervisor_id' => $supervisor->id,
            'position_title' => 'Evaluation internship', 'status' => 'ACTIVE', 'start_date' => '2026-10-01', 'end_date' => '2026-10-09']);
    }

    private function task(Internship $parent, string $status): Task
    {
        return Task::create(['internship_id' => $parent->id, 'assigned_by' => $parent->company_supervisor_id, 'title' => 'Task evidence',
            'description' => 'Work', 'priority' => 'MEDIUM', 'status' => $status]);
    }
}
