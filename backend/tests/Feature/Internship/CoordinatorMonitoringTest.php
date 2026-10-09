<?php

namespace Tests\Feature\Internship;

use App\Models\Company;
use App\Models\Internship;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CoordinatorMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('task_attachments');
    }

    public function test_assigned_eligible_internships_are_paginated_with_safe_information_and_progress(): void
    {
        [$parent, $task] = $this->fixture();
        $actor = $parent->coordinator->user;
        $this->fixture();
        foreach (['APPROVED', 'COMPLETED', 'DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'REVISION_REQUIRED', 'REJECTED'] as $status) {
            $this->fixture($actor, $status);
        }
        $this->actingAs($actor);
        $this->getJson($this->base().'/internships?per_page=1')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonCount(1, 'data');
        $this->getJson($this->base().'/internships/'.$parent->id)->assertOk()->assertJsonPath('data.company.name', 'Monitoring company')
            ->assertJsonPath('data.student.first_name', 'Test')->assertJsonPath('data.tasks_count', 1)->assertJsonPath('data.approved_tasks_count', 1)
            ->assertJsonMissingPath('data.student.password')->assertJsonMissingPath('data.supervisor.email');
        foreach (['APPROVED', 'ACTIVE', 'COMPLETED'] as $status) {
            $parent->update(['status' => $status]);
            foreach ($this->paths($parent, $task) as $path) {
                $this->getJson($path)->assertOk();
            }
        }
    }

    public function test_activities_tasks_and_versions_have_safe_read_only_resources(): void
    {
        [$parent, $task, $activity, $file] = $this->fixture();
        $second = $task->submissions()->create(['version_no' => 2, 'submission_text' => 'Corrected version', 'submitted_at' => now()]);
        $second->feedback()->create(['supervisor_id' => $parent->company_supervisor_id, 'decision' => 'APPROVED', 'comment' => 'Verified work']);
        $this->actingAs($parent->coordinator->user);
        $this->getJson($this->base().'/internships/'.$parent->id.'/activities')->assertOk()->assertJsonPath('data.0.can_edit', false)
            ->assertJsonPath('total_recorded_hours', '0.10')->assertJsonPath('filtered_recorded_hours', '0.10');
        $this->getJson($this->base().'/activities/'.$activity->id)->assertOk()->assertJsonPath('data.can_edit', false);
        $response = $this->getJson($this->base().'/tasks/'.$task->id)->assertOk()->assertJsonPath('data.assigned_by.first_name', 'Test');
        foreach (['can_start', 'can_submit', 'can_resubmit', 'can_review', 'can_edit'] as $field) {
            $response->assertJsonPath('data.'.$field, false);
        }
        $this->getJson($this->base().'/tasks/'.$task->id.'/submissions?per_page=1')->assertOk()->assertJsonPath('data.0.version_no', 2)
            ->assertJsonPath('data.0.feedback.comment', 'Verified work')->assertJsonPath('meta.total', 2);
        $this->getJson($this->base().'/tasks/'.$task->id.'/submissions?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.version_no', 1)
            ->assertJsonPath('data.0.feedback.decision', 'REVISION_REQUIRED')->assertJsonPath('data.0.files.0.id', $file->id)
            ->assertJsonPath('data.0.resource_url', 'https://example.com/work')->assertJsonMissingPath('data.0.files.0.storage_path');
    }

    public function test_foreign_unassigned_and_ineligible_status_resources_are_hidden_before_validation(): void
    {
        [$parent, $task] = $this->fixture();
        [$foreign] = $this->fixture();
        $this->actingAs($foreign->coordinator->user);
        foreach ($this->paths($parent, $task) as $path) {
            $this->getJson($path.'?page=0&date_from=bad')->assertNotFound();
        }
        $this->actingAs($parent->coordinator->user);
        foreach (['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'REVISION_REQUIRED', 'REJECTED'] as $status) {
            $parent->update(['status' => $status]);
            foreach ($this->paths($parent, $task) as $path) {
                $this->getJson($path)->assertNotFound();
            }
        }
        $parent->update(['status' => 'ACTIVE', 'coordinator_id' => null]);
        foreach ($this->paths($parent, $task) as $path) {
            $this->getJson($path)->assertNotFound();
        }
    }

    public function test_guests_wrong_roles_inactive_accounts_and_missing_profiles_are_denied(): void
    {
        [$parent, $task] = $this->fixture();
        $paths = [$this->base().'/internships', ...$this->paths($parent, $task)];
        foreach ($paths as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        foreach (['ADMIN', 'STUDENT', 'COMPANY_SUPERVISOR', 'ACADEMIC_COORDINATOR'] as $role) {
            $this->actingAs($this->user($role));
            foreach ($paths as $path) {
                $this->getJson($path)->assertForbidden();
            }
        }
        $actor = $parent->coordinator->user;
        $actor->update(['is_active' => false]);
        $this->actingAs($actor);
        foreach ($paths as $path) {
            $this->getJson($path)->assertForbidden();
        }
    }

    public function test_nonexistent_ids_and_invalid_pagination_are_handled(): void
    {
        [$parent] = $this->fixture();
        $this->actingAs($parent->coordinator->user);
        foreach (['999999999', '-1', 'missing'] as $id) {
            foreach (['/internships/'.$id, '/internships/'.$id.'/activities', '/internships/'.$id.'/tasks', '/activities/'.$id, '/tasks/'.$id, '/tasks/'.$id.'/submissions'] as $path) {
                $this->getJson($this->base().$path)->assertNotFound();
            }
        }
        $this->getJson($this->base().'/internships?page=0&per_page=101')->assertUnprocessable();
        $this->getJson($this->base().'/internships/'.$parent->id.'/tasks?page=0')->assertUnprocessable();
    }

    public function test_monitoring_is_read_only_and_existing_mutation_routes_remain_forbidden(): void
    {
        [$parent, $task, $activity] = $this->fixture();
        $before = [$parent->fresh()->getAttributes(), $task->fresh()->getAttributes(), $activity->fresh()->getAttributes()];
        $this->actingAs($parent->coordinator->user);
        foreach ([$this->base().'/internships', ...$this->paths($parent, $task)] as $path) {
            foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
                $this->json($method, $path, ['status' => 'COMPLETED'])->assertMethodNotAllowed();
            }
        }
        foreach ([['POST', '/api/student/internships/'.$parent->id.'/activities'], ['PATCH', '/api/student/activities/'.$activity->id],
            ['POST', '/api/supervisor/internships/'.$parent->id.'/tasks'], ['PATCH', '/api/supervisor/tasks/'.$task->id],
            ['POST', '/api/student/tasks/'.$task->id.'/submissions'], ['POST', '/api/student/tasks/'.$task->id.'/start'],
            ['POST', '/api/student/tasks/'.$task->id.'/resubmit'], ['POST', '/api/supervisor/tasks/'.$task->id.'/review'],
            ['POST', '/api/supervisor/internships/'.$parent->id.'/activate']] as [$method, $path]) {
            $this->json($method, $path, [])->assertForbidden();
        }
        $this->postJson('/api/coordinator/internships/'.$parent->id.'/decision', ['decision' => 'APPROVED'])->assertConflict();
        $this->assertSame($before, [$parent->fresh()->getAttributes(), $task->fresh()->getAttributes(), $activity->fresh()->getAttributes()]);
        $this->assertDatabaseCount('task_feedback', 1);
    }

    public function test_private_downloads_are_assignment_and_status_scoped_with_existing_permissions_preserved(): void
    {
        [$parent, $task, $activity, $file] = $this->fixture();
        $url = '/api/task-submission-files/'.$file->id.'/download';
        foreach (['APPROVED', 'ACTIVE', 'COMPLETED'] as $status) {
            $parent->update(['status' => $status]);
            $response = $this->actingAs($parent->coordinator->user)->getJson($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertSame('Isolated private attachment', $response->streamedContent());
        }
        [$other] = $this->fixture();
        $this->actingAs($other->coordinator->user)->getJson($url)->assertNotFound();
        $this->actingAs($this->user('ADMIN'))->getJson($url)->assertNotFound();
        foreach ([$parent->student->user, $parent->companySupervisor->user] as $actor) {
            $this->actingAs($actor)->getJson($url)->assertOk();
        }
        $this->actingAs($parent->coordinator->user);
        foreach (['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'REVISION_REQUIRED', 'REJECTED'] as $status) {
            $parent->update(['status' => $status]);
            $this->getJson($url)->assertNotFound();
        }
        $parent->update(['status' => 'ACTIVE', 'coordinator_id' => null]);
        $this->getJson($url)->assertNotFound();
        $this->actingAs($this->user('ACADEMIC_COORDINATOR'))->getJson($url)->assertForbidden();
        $actor = $other->coordinator->user;
        $actor->update(['is_active' => false]);
        $this->actingAs($actor)->getJson($url)->assertForbidden();
    }

    public function test_empty_monitoring_sections_and_protected_query_parameters_do_not_leak_data(): void
    {
        [$parent, $task] = $this->fixture();
        [$foreign] = $this->fixture();
        $this->actingAs($parent->coordinator->user);
        $this->getJson($this->base().'/internships?coordinator_id='.$foreign->coordinator_id)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $parent->id);
        $empty = $parent->replicate();
        $empty->position_title = 'Empty monitoring internship';
        $empty->save();
        $this->getJson($this->base().'/internships/'.$empty->id.'/activities')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('total_recorded_hours', '0.00')->assertJsonPath('filtered_recorded_hours', '0.00');
        $this->getJson($this->base().'/internships/'.$empty->id.'/tasks')->assertOk()->assertJsonCount(0, 'data');
    }

    private function paths(Internship $parent, Task $task): array
    {
        return array_map(fn ($path) => $this->base().$path, ['/internships/'.$parent->id, '/internships/'.$parent->id.'/activities',
            '/activities/'.$parent->activityLogs()->firstOrFail()->id, '/internships/'.$parent->id.'/tasks', '/tasks/'.$task->id, '/tasks/'.$task->id.'/submissions']);
    }

    private function base(): string
    {
        return '/api/coordinator/monitoring';
    }

    private function user(string $role): User
    {
        return User::factory()->create(['first_name' => 'Test', 'role_id' => Role::where('name', $role)->sole()->id, 'is_active' => true]);
    }

    private function fixture(?User $coordinator = null, string $status = 'ACTIVE'): array
    {
        $coordinator ??= $this->user('ACADEMIC_COORDINATOR');
        $coordinator->academicCoordinatorProfile()->firstOrCreate(['user_id' => $coordinator->id], ['academic_unit' => 'Engineering']);
        $student = $this->user('STUDENT');
        $student->studentProfile()->create(['student_number' => fake()->uuid(), 'study_program' => 'CS', 'study_year' => 2]);
        $company = Company::create(['name' => 'Monitoring company', 'is_active' => true, 'verification_status' => 'APPROVED']);
        $supervisor = $this->user('COMPANY_SUPERVISOR');
        $supervisor->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => 'APPROVED']);
        $parent = Internship::create(['student_id' => $student->id, 'company_id' => $company->id, 'company_supervisor_id' => $supervisor->id,
            'coordinator_id' => $coordinator->id, 'position_title' => 'Monitoring internship', 'status' => $status, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
        $activity = $parent->activityLogs()->create(['title' => 'Diary', 'description' => 'Work', 'activity_date' => '2026-10-02', 'hours' => '0.10']);
        $task = Task::create(['internship_id' => $parent->id, 'assigned_by' => $supervisor->id, 'title' => 'Reviewed task', 'description' => 'Work', 'priority' => 'MEDIUM', 'status' => 'APPROVED']);
        $submission = $task->submissions()->create(['version_no' => 1, 'submission_text' => 'First version', 'resource_url' => 'https://example.com/work', 'submitted_at' => now()]);
        $submission->feedback()->create(['supervisor_id' => $supervisor->id, 'decision' => 'REVISION_REQUIRED', 'comment' => 'Add tests']);
        $file = $submission->files()->create(['original_name' => 'Report.txt', 'storage_path' => Str::uuid().'.bin', 'mime_type' => 'text/plain', 'size_bytes' => 27]);
        Storage::disk('task_attachments')->put($file->storage_path, 'Isolated private attachment');

        return [$parent, $task, $activity, $file];
    }
}
