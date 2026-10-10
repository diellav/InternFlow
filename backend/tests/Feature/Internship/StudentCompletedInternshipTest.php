<?php

namespace Tests\Feature\Internship;

use App\Models\Company;
use App\Models\FinalEvaluation;
use App\Models\Internship;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Modules\Evaluation\Services\CoordinatorCompletionService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudentCompletedInternshipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-09 12:00:00', 'UTC'));
        Storage::fake('task_attachments');
    }

    public function test_owner_reads_safe_unchanged_completed_evaluation_including_decimal_score_and_teamwork(): void
    {
        $parent = $this->fixture();
        $evaluation = $this->evaluation($parent);
        $before = $evaluation->fresh()->getAttributes();
        $parentBefore = $parent->fresh()->getAttributes();
        $this->actingAs($parent->student->user);
        $response = $this->getJson($this->evaluationPath($parent))->assertOk()->assertJsonPath('data.internship.status', 'COMPLETED')
            ->assertJsonPath('data.internship.company.name', 'Completed company')->assertJsonPath('data.evaluation.teamwork', 3)
            ->assertJsonPath('data.evaluation.overall_score', '4.50')->assertJsonPath('data.evaluation.comments', 'Immutable final assessment for the student.')
            ->assertJsonMissingPath('data.evaluation.draft_token')->assertJsonMissingPath('data.evaluation.evaluator.email')
            ->assertJsonMissingPath('data.evaluation.evaluator.password')->assertJsonMissingPath('data.evaluation.evaluator_id');
        foreach (FinalEvaluation::RATINGS as $field) {
            $this->assertArrayHasKey($field, $response->json('data.evaluation'));
        }
        $this->assertNotNull($response->json('data.internship.completed_at'));
        $this->assertNotNull($response->json('data.evaluation.submitted_at'));
        $this->getJson($this->evaluationPath($parent).'?status=ACTIVE&overall_score=1')->assertOk();
        $this->assertSame($before, $evaluation->fresh()->getAttributes());
        $this->assertSame($parentBefore, $parent->fresh()->getAttributes());
    }

    public static function hiddenStatuses(): array
    {
        return array_map(fn ($status) => [$status], ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'REVISION_REQUIRED', 'REJECTED', 'APPROVED', 'ACTIVE']);
    }

    #[DataProvider('hiddenStatuses')]
    public function test_evaluation_is_not_visible_before_completion(string $status): void
    {
        $parent = $this->fixture();
        $this->evaluation($parent);
        $parent->update(['status' => $status]);
        $this->actingAs($parent->student->user)->getJson($this->evaluationPath($parent))->assertNotFound()->assertJsonMissing(['Immutable final assessment for the student.']);
    }

    public function test_missing_draft_or_unconfirmed_completion_is_unavailable_without_leaking_draft(): void
    {
        $parent = $this->fixture();
        $this->actingAs($parent->student->user);
        $this->getJson($this->evaluationPath($parent))->assertNotFound();
        $evaluation = $this->evaluation($parent);
        $evaluation->update(['submitted_at' => null, 'comments' => 'Private supervisor draft']);
        $this->getJson($this->evaluationPath($parent))->assertNotFound()->assertJsonMissing(['Private supervisor draft']);
        $evaluation->update(['submitted_at' => now()]);
        $parent->update(['completed_at' => null]);
        $this->getJson($this->evaluationPath($parent))->assertNotFound();
        $parent->update(['completed_at' => now(), 'coordinator_id' => null]);
        $this->getJson($this->evaluationPath($parent))->assertNotFound();
    }

    public function test_guests_roles_inactive_and_missing_profiles_are_denied(): void
    {
        $parent = $this->fixture();
        $this->evaluation($parent);
        $task = $this->task($parent);
        $activity = $this->activity($parent);
        $paths = [$this->evaluationPath($parent), $this->activitiesPath($parent), '/api/student/activities/'.$activity->id,
            '/api/student/internships/'.$parent->id.'/tasks', '/api/student/tasks/'.$task->id, '/api/student/tasks/'.$task->id.'/submissions'];
        foreach ($paths as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        foreach (['ADMIN', 'COMPANY_SUPERVISOR', 'ACADEMIC_COORDINATOR', 'STUDENT'] as $role) {
            $this->actingAs($this->user($role));
            foreach ($paths as $path) {
                $this->getJson($path)->assertForbidden();
            }
        }
        $student = $parent->student->user;
        $student->update(['is_active' => false]);
        $this->actingAs($student->fresh());
        foreach ($paths as $path) {
            $this->getJson($path)->assertForbidden();
        }
    }

    public function test_foreign_and_nonexistent_resources_never_leak_evaluation_or_completed_evidence(): void
    {
        $parent = $this->fixture();
        $this->evaluation($parent);
        $task = $this->task($parent);
        $activity = $this->activity($parent);
        $foreign = $this->fixture();
        $this->actingAs($foreign->student->user);
        foreach ([$this->evaluationPath($parent), $this->activitiesPath($parent).'?page=0', '/api/student/activities/'.$activity->id,
            '/api/student/internships/'.$parent->id.'/tasks', '/api/student/tasks/'.$task->id, '/api/student/tasks/'.$task->id.'/submissions'] as $path) {
            $this->getJson($path)->assertNotFound();
        }
        $this->actingAs($parent->student->user);
        foreach (['missing', '-1', '999999999'] as $id) {
            $this->getJson('/api/student/internships/'.$id.'/final-evaluation')->assertNotFound();
        }
    }

    public function test_completed_activities_keep_filters_pagination_null_hours_and_decimal_safe_summaries(): void
    {
        $parent = $this->fixture();
        for ($i = 0; $i < 17; $i++) {
            $this->activity($parent, ['title' => 'Work%_ '.$i, 'activity_date' => '2026-10-08', 'hours' => '0.10']);
        }
        $this->activity($parent, ['activity_date' => '2026-10-09', 'hours' => '0.20']);
        $this->activity($parent, ['activity_date' => '2026-10-01', 'hours' => '0.10']);
        $null = $this->activity($parent, ['activity_date' => '2026-10-07', 'hours' => null]);
        $before = $parent->activityLogs()->orderBy('id')->get()->map->getAttributes()->all();
        $this->actingAs($parent->student->user);
        $this->getJson($this->activitiesPath($parent))->assertOk()->assertJsonPath('total_recorded_hours', '2.00')->assertJsonPath('meta.total', 20);
        $filter = '?date_from=2026-10-08&date_to=2026-10-08&search='.urlencode('wORK%_').'&per_page=15';
        $first = $this->getJson($this->activitiesPath($parent).$filter)->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('meta.total', 17)
            ->assertJsonPath('total_recorded_hours', '2.00')->assertJsonPath('filtered_recorded_hours', '1.70');
        $second = $this->getJson($this->activitiesPath($parent).$filter.'&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('filtered_recorded_hours', '1.70');
        $this->assertCount(17, array_unique([...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id')]));
        $this->getJson($this->activitiesPath($parent).'?date_from=2026-10-09')->assertOk()->assertJsonPath('filtered_recorded_hours', '0.20');
        $this->getJson($this->activitiesPath($parent).'?date_to=2026-10-01')->assertOk()->assertJsonPath('filtered_recorded_hours', '0.10');
        $this->getJson($this->activitiesPath($parent).'?search=absent')->assertOk()->assertJsonPath('filtered_recorded_hours', '0.00')->assertJsonCount(0, 'data');
        $this->getJson('/api/student/activities/'.$null->id)->assertOk()->assertJsonPath('data.hours', null)->assertJsonPath('data.can_edit', false);
        $this->assertSame($before, $parent->activityLogs()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_completed_task_versions_feedback_and_private_attachments_remain_readable_only_by_owner(): void
    {
        $parent = $this->fixture();
        $task = $this->task($parent);
        $files = [];
        foreach ([1 => 'REVISION_REQUIRED', 2 => 'APPROVED'] as $version => $decision) {
            $submission = $task->submissions()->create(['version_no' => $version, 'submission_text' => 'Version '.$version, 'submitted_at' => now()]);
            $submission->feedback()->create(['supervisor_id' => $parent->company_supervisor_id, 'decision' => $decision, 'comment' => 'Feedback '.$version]);
            $path = Str::uuid().'.bin';
            Storage::disk('task_attachments')->put($path, 'Private version '.$version);
            $files[$version] = $submission->files()->create(['storage_path' => $path, 'original_name' => 'Report_v'.$version.'.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 17]);
        }
        $this->actingAs($parent->student->user);
        $this->getJson('/api/student/internships/'.$parent->id.'/tasks')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/student/tasks/'.$task->id)->assertOk()->assertJsonPath('data.can_start', false)->assertJsonPath('data.can_submit', false)
            ->assertJsonPath('data.can_resubmit', false)->assertJsonPath('data.can_edit', false)->assertJsonPath('data.can_review', false);
        $history = $this->getJson('/api/student/tasks/'.$task->id.'/submissions')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.version_no', 2)->assertJsonPath('data.0.feedback.comment', 'Feedback 2')->assertJsonPath('data.1.feedback.decision', 'REVISION_REQUIRED')
            ->assertJsonMissingPath('data.0.files.0.storage_path');
        $this->assertSame($files[2]->id, $history->json('data.0.files.0.id'));
        foreach ($files as $version => $file) {
            $path = '/api/task-submission-files/'.$file->id.'/download';
            $this->get($path)->assertOk()->assertStreamedContent('Private version '.$version)->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->actingAs($parent->companySupervisor->user)->get($path)->assertOk();
            $this->actingAs($parent->coordinator->user)->get($path)->assertOk();
            $foreign = $this->fixture();
            $this->actingAs($foreign->student->user)->getJson($path)->assertNotFound();
            $this->actingAs($parent->student->user);
        }
    }

    public function test_completion_does_not_enable_any_student_writes_or_status_mass_assignment(): void
    {
        $parent = $this->fixture();
        $evaluation = $this->evaluation($parent);
        $activity = $this->activity($parent);
        $task = $this->task($parent);
        $before = $parent->fresh()->getAttributes();
        $evaluationBefore = $evaluation->fresh()->getAttributes();
        $this->actingAs($parent->student->user);
        $this->postJson($this->activitiesPath($parent), ['title' => 'New', 'description' => 'No changes', 'activity_date' => '2026-10-09'])->assertConflict();
        $this->patchJson('/api/student/activities/'.$activity->id, ['title' => 'Unsafe'])->assertConflict();
        $this->deleteJson('/api/student/activities/'.$activity->id)->assertMethodNotAllowed();
        foreach (['ASSIGNED' => 'start', 'IN_PROGRESS' => 'submissions', 'REVISION_REQUIRED' => 'resubmit'] as $status => $action) {
            $task->update(['status' => $status]);
            $payload = $action === 'start' ? [] : ['submission_text' => 'Unsafe'];
            if ($action === 'resubmit') {
                $payload['expected_submission_id'] = 1;
            }
            $this->postJson('/api/student/tasks/'.$task->id.'/'.$action, $payload)->assertConflict();
        }
        $this->patchJson('/api/student/internships/'.$parent->id, ['status' => 'ACTIVE', 'completed_at' => null])->assertUnprocessable();
        $this->patchJson('/api/student/internships/'.$parent->id, ['position_title' => 'Unsafe'])->assertConflict();
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->json($method, $this->evaluationPath($parent), ['overall_score' => 1])->assertMethodNotAllowed();
        }
        $this->assertSame($before, $parent->fresh()->getAttributes());
        $this->assertSame($evaluationBefore, $evaluation->fresh()->getAttributes());
        $this->assertSame('Recorded work', $activity->fresh()->title);
        $this->assertDatabaseCount('task_submissions', 0);
    }

    public function test_actual_supervisor_submission_then_coordinator_completion_unlocks_student_view_without_changing_evaluation(): void
    {
        $parent = $this->fixture();
        $parent->update(['status' => 'ACTIVE', 'completed_at' => null]);
        $supervisorPath = '/api/supervisor/internships/'.$parent->id.'/final-evaluation';
        $this->actingAs($parent->companySupervisor->user);
        $token = $this->putJson($supervisorPath, [...array_fill_keys(FinalEvaluation::RATINGS, 4), 'comments' => 'Supervisor evidence is immutable after submission and completion.'])->assertOk()->json('data.evaluation.draft_token');
        $this->postJson($supervisorPath.'/submit', ['draft_token' => $token])->assertOk();
        $evaluation = $parent->finalEvaluation()->sole();
        $before = $evaluation->getAttributes();
        $this->actingAs($parent->student->user)->getJson($this->evaluationPath($parent))->assertNotFound();
        app(CoordinatorCompletionService::class)->complete($parent->coordinator->user, $parent->id, $evaluation->id);
        $this->getJson($this->evaluationPath($parent))->assertOk();
        $this->assertSame($before, $evaluation->fresh()->getAttributes());
        $this->actingAs($parent->coordinator->user)->getJson('/api/coordinator/monitoring/internships/'.$parent->id)->assertOk();
    }

    private function evaluationPath(Internship $parent): string
    {
        return '/api/student/internships/'.$parent->id.'/final-evaluation';
    }

    private function activitiesPath(Internship $parent): string
    {
        return '/api/student/internships/'.$parent->id.'/activities';
    }

    private function evaluation(Internship $parent): FinalEvaluation
    {
        return $parent->finalEvaluation()->create(['evaluator_id' => $parent->company_supervisor_id, ...array_fill_keys(FinalEvaluation::RATINGS, 4), 'teamwork' => 3,
            'overall_score' => '4.50', 'comments' => 'Immutable final assessment for the student.', 'submitted_at' => now()]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->sole()->id, 'is_active' => true]);
    }

    private function fixture(): Internship
    {
        $student = $this->user('STUDENT');
        $student->studentProfile()->create(['student_number' => fake()->uuid(), 'study_program' => 'CS', 'study_year' => 2]);
        $company = Company::create(['name' => 'Completed company', 'verification_status' => 'APPROVED', 'is_active' => true]);
        $supervisor = $this->user('COMPANY_SUPERVISOR');
        $supervisor->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => 'APPROVED']);
        $coordinator = $this->user('ACADEMIC_COORDINATOR');
        $coordinator->academicCoordinatorProfile()->create(['academic_unit' => 'Engineering']);

        return Internship::create(['student_id' => $student->id, 'company_id' => $company->id, 'company_supervisor_id' => $supervisor->id, 'coordinator_id' => $coordinator->id,
            'position_title' => 'Completed internship', 'status' => 'COMPLETED', 'completed_at' => now(), 'start_date' => '2026-10-01', 'end_date' => '2026-10-09']);
    }

    private function task(Internship $parent): Task
    {
        return $parent->tasks()->create(['title' => 'Preserved task', 'description' => 'Reviewed work', 'assigned_by' => $parent->company_supervisor_id, 'priority' => 'MEDIUM', 'status' => 'APPROVED']);
    }

    private function activity(Internship $parent, array $data = [])
    {
        return $parent->activityLogs()->create([...['title' => 'Recorded work', 'description' => 'Actual work', 'hours' => '0.50', 'activity_date' => '2026-10-09'], ...$data]);
    }
}
