<?php

namespace Tests\Feature\Internship;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Internship;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Modules\Activity\Services\StudentActivityService;
use App\Shared\Enums\InternshipStatus;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StudentActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-09 12:00:00', 'UTC'));
    }

    public function test_student_creates_lists_views_and_partially_edits_without_mutating_parent_or_task(): void
    {
        $internship = $this->internship();
        $task = Task::create(['internship_id' => $internship->id, 'assigned_by' => $internship->company_supervisor_id, 'title' => 'Task', 'description' => 'Work', 'priority' => 'MEDIUM']);
        $parent = $internship->fresh()->getAttributes();
        $originalTask = $task->fresh()->getAttributes();
        $this->actingAs($internship->student->user);
        $id = $this->postJson($this->path($internship), [...$this->payload(), 'title' => '  Work diary  ', 'hours' => '2.50'])->assertCreated()->assertJsonPath('data.title', 'Work diary')->assertJsonPath('data.hours', '2.50')->assertJsonPath('data.can_edit', true)->assertJsonMissingPath('data.student_id')->json('data.id');
        $before = ActivityLog::findOrFail($id)->getAttributes();
        $this->getJson($this->path($internship))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('internship.server_date', '2026-10-09');
        $this->getJson('/api/student/activities/'.$id)->assertOk()->assertJsonPath('data.title', 'Work diary')->assertJsonMissingPath('data.internship.student_id');
        $this->patchJson('/api/student/activities/'.$id, ['description' => 'Updated description'])->assertOk()->assertJsonPath('data.hours', '2.50')->assertJsonPath('data.description', 'Updated description');
        $updated = ActivityLog::findOrFail($id)->getAttributes();
        foreach (['id', 'internship_id', 'created_at', 'title', 'activity_date', 'hours'] as $field) {
            $this->assertSame($before[$field], $updated[$field]);
        }
        $this->patchJson('/api/student/activities/'.$id, ['hours' => null])->assertOk()->assertJsonPath('data.hours', null);
        $this->assertSame($parent, $internship->fresh()->getAttributes());
        $this->assertSame($originalTask, $task->fresh()->getAttributes());
        $this->assertDatabaseCount('activity_logs', 1);
        $this->deleteJson('/api/student/activities/'.$id)->assertMethodNotAllowed();
    }

    public function test_optional_hours_and_inclusive_date_bounds_with_server_timezone(): void
    {
        $internship = $this->internship(['end_date' => '2026-10-09']);
        $this->actingAs($internship->student->user);
        foreach (['2026-10-01', '2026-10-09'] as $date) {
            $this->postJson($this->path($internship), [...$this->payload(), 'activity_date' => $date])->assertCreated()->assertJsonPath('data.hours', null);
        }
        config(['app.timezone' => 'Pacific/Auckland']);
        $this->travelTo(Carbon::parse('2026-10-08 23:30:00', 'UTC'));
        $this->postJson($this->path($internship), [...$this->payload(), 'activity_date' => '2026-10-09', 'hours' => '0.01'])->assertCreated()->assertJsonPath('data.internship.server_date', '2026-10-09');
    }

    public function test_required_fields_dates_lengths_hours_and_protected_fields_are_rejected(): void
    {
        $internship = $this->internship();
        $this->actingAs($internship->student->user);
        foreach ([['title' => "\u{00a0}"], ['description' => null], ['title' => str_repeat('x', 256)], ['description' => str_repeat('x', 10001)], ['activity_date' => '2026-02-30'], ['activity_date' => '2026-09-30'], ['activity_date' => '2026-11-01'], ['activity_date' => '2026-10-10'], ['hours' => 0], ['hours' => -1], ['hours' => '24.01'], ['hours' => '1.234'], ['hours' => 'bad'], ['hours' => true]] as $invalid) {
            $this->postJson($this->path($internship), [...$this->payload(), ...$invalid])->assertUnprocessable();
        }
        $this->postJson($this->path($internship), [])->assertUnprocessable();
        foreach (['id', 'student_id', 'internship_id', 'supervisor_id', 'created_at', 'updated_at', 'status', 'feedback', 'task_id', 'unknown'] as $field) {
            $this->postJson($this->path($internship), [...$this->payload(), $field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_foreign_student_cannot_list_create_view_or_edit_even_with_invalid_payload(): void
    {
        $internship = $this->internship();
        $activity = $this->activity($internship);
        $foreign = $this->internship();
        $this->actingAs($foreign->student->user)->getJson($this->path($internship).'?page=0')->assertNotFound();
        $this->postJson($this->path($internship), ['student_id' => null])->assertNotFound();
        $this->getJson('/api/student/activities/'.$activity->id)->assertNotFound();
        $this->patchJson('/api/student/activities/'.$activity->id, ['internship_id' => null])->assertNotFound();
        $this->assertSame('Diary entry', $activity->fresh()->title);
    }

    public function test_guests_wrong_roles_inactive_and_missing_profiles_are_denied(): void
    {
        $internship = $this->internship();
        $activity = $this->activity($internship);
        $check = function (int $status) use ($internship, $activity): void {
            $this->getJson($this->path($internship))->assertStatus($status);
            $this->postJson($this->path($internship), $this->payload())->assertStatus($status);
            $this->getJson('/api/student/activities/'.$activity->id)->assertStatus($status);
            $this->patchJson('/api/student/activities/'.$activity->id, ['title' => 'Unsafe'])->assertStatus($status);
        };
        $check(401);
        foreach (['ADMIN', 'ACADEMIC_COORDINATOR', 'COMPANY_SUPERVISOR', 'STUDENT'] as $role) {
            $this->actingAs($this->user($role));
            $check(403);
        }
        $student = $internship->student->user;
        $student->update(['is_active' => false]);
        $this->actingAs($student);
        $check(403);
        $this->assertSame('Diary entry', $activity->fresh()->title);
    }

    public function test_nonactive_states_deny_writes_and_only_completed_evidence_remains_readable(): void
    {
        $internship = $this->internship();
        $activity = $this->activity($internship);
        $this->actingAs($internship->student->user);
        foreach (InternshipStatus::cases() as $status) {
            if ($status->value === 'ACTIVE') {
                continue;
            }
            $internship->update(['status' => $status->value]);
            $this->postJson($this->path($internship), $this->payload())->assertConflict();
            $this->patchJson('/api/student/activities/'.$activity->id, ['title' => 'Unsafe'])->assertConflict();
            $expected = $status->value === 'COMPLETED' ? 200 : 404;
            $this->getJson($this->path($internship))->assertStatus($expected);
            $this->getJson('/api/student/activities/'.$activity->id)->assertStatus($expected);
        }
        $this->assertSame('Diary entry', $activity->fresh()->title);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    public function test_daily_hours_are_capped_across_internships_and_edit_excludes_its_own_hours(): void
    {
        $first = $this->internship();
        $second = $this->internship(['student_id' => $first->student_id]);
        $activity = $this->activity($first, ['hours' => '20.00']);
        $this->actingAs($first->student->user);
        $id = $this->postJson($this->path($second), [...$this->payload(), 'hours' => '4'])->assertCreated()->json('data.id');
        $this->postJson($this->path($first), [...$this->payload(), 'hours' => '0.01'])->assertUnprocessable()->assertJsonValidationErrors('hours');
        $this->patchJson('/api/student/activities/'.$activity->id, ['hours' => '20'])->assertOk();
        $this->patchJson('/api/student/activities/'.$id, ['hours' => '4.01'])->assertUnprocessable();
        $this->assertSame('4.00', ActivityLog::findOrFail($id)->hours);
        $this->patchJson('/api/student/activities/'.$id, ['activity_date' => '2026-10-08', 'hours' => '24'])->assertOk();
    }

    public function test_partial_edits_validate_dates_and_reject_protected_fields(): void
    {
        $internship = $this->internship();
        $activity = $this->activity($internship);
        $before = $activity->fresh()->getAttributes();
        $this->actingAs($internship->student->user);
        foreach (['student_id', 'internship_id', 'task_id', 'supervisor_id', 'created_at', 'updated_at', 'status', 'feedback'] as $field) {
            $this->patchJson('/api/student/activities/'.$activity->id, [$field => null])->assertUnprocessable();
        }
        $this->patchJson('/api/student/activities/'.$activity->id, ['activity_date' => '2026-10-10'])->assertUnprocessable();
        $this->patchJson('/api/student/activities/'.$activity->id, ['description' => '  '])->assertUnprocessable();
        $this->assertSame($before, $activity->fresh()->getAttributes());
    }

    public function test_pagination_newest_date_then_id_order_and_constant_queries(): void
    {
        $internship = $this->internship();
        $old = $this->activity($internship, ['activity_date' => '2026-10-01']);
        $first = $this->activity($internship);
        $last = $this->activity($internship);
        $this->activity($this->internship());
        $this->actingAs($internship->student->user->load('role'))->getJson($this->path($internship).'?per_page=1')->assertJsonPath('data.0.id', $last->id)->assertJsonPath('meta.total', 3);
        $this->getJson($this->path($internship).'?per_page=1&page=2')->assertJsonPath('data.0.id', $first->id);
        $this->getJson($this->path($internship).'?per_page=1&page=3')->assertJsonPath('data.0.id', $old->id);
        $this->getJson($this->path($internship).'?page=0&per_page=101')->assertUnprocessable();
        $count = function () use ($internship): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->getJson($this->path($internship))->assertOk();

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        };
        $small = $count();
        $this->activity($internship);
        $this->assertSame($small, $count());
    }

    public function test_service_rechecks_fresh_actor_and_parent_state_under_transaction(): void
    {
        $internship = $this->internship();
        $student = $internship->student->user;
        User::whereKey($student->id)->update(['is_active' => false]);
        try {
            app(StudentActivityService::class)->save($student, $this->payload(), internshipId: $internship->id);
            $this->fail('Fresh actor state must be checked.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        User::whereKey($student->id)->update(['is_active' => true]);
        $internship->update(['status' => 'COMPLETED']);
        try {
            app(StudentActivityService::class)->save($student, $this->payload(), internshipId: $internship->id);
            $this->fail('Fresh parent state must be checked.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_invalid_ids_are_hidden_without_activity_mutation(): void
    {
        $internship = $this->internship();
        $activity = $this->activity($internship);
        $before = $activity->fresh()->getAttributes();
        foreach (['999999999', '0', '-1', 'missing'] as $id) {
            $this->actingAs($internship->student->user);
            $this->getJson('/api/student/internships/'.$id.'/activities?search=Diary')->assertNotFound();
            $this->postJson('/api/student/internships/'.$id.'/activities', $this->payload())->assertNotFound();
            $this->getJson('/api/student/activities/'.$id)->assertNotFound();
            $this->patchJson('/api/student/activities/'.$id, ['title' => 'Unsafe'])->assertNotFound();
            $this->actingAs($internship->companySupervisor->user);
            $this->getJson('/api/supervisor/internships/'.$id.'/activities?search=Diary')->assertNotFound();
            $this->getJson('/api/supervisor/activities/'.$id)->assertNotFound();
        }
        $this->assertSame($before, $activity->fresh()->getAttributes());
        $this->assertDatabaseCount('activity_logs', 1);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->sole()->id]);
    }

    private function internship(array $attributes = []): Internship
    {
        $student = isset($attributes['student_id']) ? User::findOrFail($attributes['student_id']) : $this->user('STUDENT');
        $student->studentProfile()->firstOrCreate(['user_id' => $student->id], ['student_number' => fake()->uuid(), 'study_program' => 'CS', 'study_year' => 2]);
        $company = Company::create(['name' => fake()->company(), 'verification_status' => 'APPROVED']);
        $supervisor = $this->user('COMPANY_SUPERVISOR');
        $supervisor->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => 'APPROVED']);

        return Internship::create(['student_id' => $student->id, 'company_id' => $company->id, 'company_supervisor_id' => $supervisor->id, 'position_title' => 'Web internship', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'ACTIVE', ...$attributes]);
    }

    private function activity(Internship $internship, array $attributes = []): ActivityLog
    {
        return $internship->activityLogs()->create([...$this->payload(), ...$attributes]);
    }

    private function payload(): array
    {
        return ['title' => 'Diary entry', 'description' => 'Implemented and tested the form.', 'activity_date' => '2026-10-09'];
    }

    private function path(Internship $internship): string
    {
        return '/api/student/internships/'.$internship->id.'/activities';
    }
}
