<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\FinalEvaluation;
use App\Models\Internship;
use App\Models\Role;
use App\Models\User;
use App\Modules\Messaging\Services\MessagingService;
use Database\Seeders\InternFlowDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class InternFlowDemoSeederTest extends TestCase
{
    use DatabaseTransactions;

    private const TABLES = ['users', 'companies', 'student_profiles', 'academic_coordinator_profiles', 'company_supervisor_profiles',
        'internships', 'tasks', 'task_submissions', 'task_feedback', 'activity_logs', 'final_evaluations', 'conversations', 'conversation_participants', 'messages', 'notifications', 'audit_logs'];

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'internflow_testing') {
            throw new RuntimeException('These tests require the isolated internflow_testing database.');
        }
        foreach (['STUDENT', 'ACADEMIC_COORDINATOR', 'COMPANY_SUPERVISOR', 'ADMIN'] as $role) {
            Role::firstOrCreate(['name' => $role], ['description' => $role]);
        }
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (self::TABLES as $table) {
            $snapshot[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    public function test_dataset_relationships_and_existing_records_are_preserved(): void
    {
        $existing = User::create(['first_name' => 'Existing', 'last_name' => 'Admin', 'email' => 'existing-demo-fixture@example.test',
            'role_id' => Role::where('name', 'ADMIN')->value('id'), 'password' => 'Unchanged123', 'is_active' => true]);
        Company::create(['name' => 'Existing fixture company', 'is_active' => false, 'verification_status' => 'PENDING']);
        $before = $this->snapshot();
        $this->seed(InternFlowDemoSeeder::class);
        $after = $this->snapshot();
        foreach ($before as $table => $rows) {
            foreach ($rows as $row) {
                $this->assertContains($row, $after[$table], $table);
            }
        }
        $expected = ['users' => 10, 'companies' => 3, 'student_profiles' => 5, 'academic_coordinator_profiles' => 2,
            'company_supervisor_profiles' => 3, 'internships' => 7, 'tasks' => 10, 'task_submissions' => 6, 'task_feedback' => 4,
            'activity_logs' => 9, 'final_evaluations' => 1, 'conversations' => 2, 'conversation_participants' => 6,
            'messages' => 18, 'notifications' => 0, 'audit_logs' => 1];
        foreach ($expected as $table => $count) {
            $this->assertCount(count($before[$table]) + $count, $after[$table], $table);
        }
        $marker = AuditLog::where('action', InternFlowDemoSeeder::MARKER)->firstOrFail()->new_values;
        foreach (User::whereIn('id', $marker['users'])->with(['role', 'studentProfile', 'academicCoordinatorProfile', 'companySupervisorProfile.company'])->get() as $user) {
            $this->assertTrue(Hash::check('Test12345', $user->password));
            $this->assertTrue($user->is_active);
            $this->assertNotSame('ADMIN', $user->role->name);
            $profile = match ($user->role->name) {
                'STUDENT' => $user->studentProfile, 'ACADEMIC_COORDINATOR' => $user->academicCoordinatorProfile,
                'COMPANY_SUPERVISOR' => $user->companySupervisorProfile,
            };
            $this->assertSame($user->id, $profile->user_id);
            if ($user->role->name === 'COMPANY_SUPERVISOR') {
                $this->assertSame('APPROVED', $profile->verification_status->value);
                $this->assertTrue($profile->company->is_active);
                $this->assertSame('APPROVED', $profile->company->verification_status->value);
            }
        }
        $internships = Internship::whereIn('id', $marker['internships'])->with(['student.user', 'companySupervisor', 'coordinator', 'tasks.submissions.feedback', 'activityLogs'])->get();
        $this->assertEquals(['SUBMITTED' => 1, 'REJECTED' => 1, 'APPROVED' => 2, 'ACTIVE' => 2, 'COMPLETED' => 1], $internships->countBy('status')->all());
        foreach ($internships as $internship) {
            $this->assertSame($internship->company_id, $internship->companySupervisor->company_id);
            $this->assertNotNull($internship->student);
            $this->assertTrue($internship->created_at->lte($internship->submitted_at));
            if ($internship->status === 'SUBMITTED') {
                $this->assertNull($internship->coordinator_id);
            } else {
                $this->assertNotNull($internship->coordinator);
            }
            foreach ($internship->activityLogs as $activity) {
                $this->assertTrue($activity->activity_date->betweenIncluded($internship->start_date, $internship->end_date));
                $this->assertGreaterThan(0, (float) $activity->hours);
                $this->assertLessThanOrEqual(24, (float) $activity->hours);
            }
            $this->assertSame(in_array($internship->status, ['ACTIVE', 'COMPLETED'], true) ? 12.0 : 0.0, (float) $internship->activityLogs->sum('hours'));
            foreach ($internship->tasks as $task) {
                $this->assertSame($internship->company_supervisor_id, $task->assigned_by);
                foreach ($task->submissions as $submission) {
                    $this->assertSame(1, $submission->version_no);
                    if ($task->status === 'APPROVED') {
                        $this->assertSame('APPROVED', $submission->feedback->decision);
                        $this->assertSame($internship->company_supervisor_id, $submission->feedback->supervisor_id);
                    } else {
                        $this->assertSame('SUBMITTED', $task->status);
                        $this->assertNull($submission->feedback);
                    }
                }
            }
            if ($internship->status === 'COMPLETED') {
                $evaluation = FinalEvaluation::where('internship_id', $internship->id)->firstOrFail();
                $this->assertSame($internship->company_supervisor_id, $evaluation->evaluator_id);
                $this->assertTrue($evaluation->submitted_at->lt($internship->completed_at));
                $this->assertTrue($internship->end_date->lte($internship->completed_at));
                $this->assertSame(['APPROVED'], $internship->tasks->pluck('status')->unique()->values()->all());
            }
        }
        foreach (Conversation::whereIn('internship_id', $marker['internships'])->with(['internship', 'participants', 'messages'])->get() as $conversation) {
            $this->assertSame('ACTIVE', $conversation->internship->status);
            $this->assertEqualsCanonicalizing([$conversation->internship->student_id, $conversation->internship->company_supervisor_id, $conversation->internship->coordinator_id], $conversation->participants->modelKeys());
            foreach ($conversation->participants as $participant) {
                $this->assertSame($conversation->id, app(MessagingService::class)->conversation($participant, $conversation->id)->id);
            }
            $this->assertFalse(app(MessagingService::class)->conversations(User::where('email', 'era.gashi@test.com')->firstOrFail())->whereKey($conversation->id)->exists());
            foreach ($conversation->messages as $message) {
                $this->assertContains($message->sender_id, $conversation->participants->modelKeys());
                $this->assertTrue($message->sent_at->gte($conversation->created_at));
            }
        }
        $this->assertSame($existing->password, $existing->fresh()->password);
    }

    public function test_second_run_is_a_no_op_even_after_demo_record_is_edited(): void
    {
        $this->seed(InternFlowDemoSeeder::class);
        User::where('email', 'arta.krasniqi@test.com')->update(['first_name' => 'Edited']);
        $before = $this->snapshot();
        $this->seed(InternFlowDemoSeeder::class);
        $this->assertEquals($before, $this->snapshot());
    }

    public function test_existing_email_is_not_adopted_or_overwritten(): void
    {
        User::create(['first_name' => 'Existing', 'last_name' => 'Owner', 'email' => 'ARTA.KRASNIQI@test.com',
            'role_id' => Role::where('name', 'ADMIN')->value('id'), 'password' => 'Unchanged123', 'is_active' => false]);
        $this->assertCollisionLeavesEverythingUnchanged();
    }

    public function test_existing_company_is_not_adopted_or_overwritten(): void
    {
        Company::create(['name' => 'NovaTech Solutions', 'is_active' => false, 'verification_status' => 'PENDING']);
        $this->assertCollisionLeavesEverythingUnchanged();
    }

    private function assertCollisionLeavesEverythingUnchanged(): void
    {
        $before = $this->snapshot();
        try {
            $this->seed(InternFlowDemoSeeder::class);
            $this->fail('Expected an identifier collision.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('collision', $exception->getMessage());
        }
        $this->assertEquals($before, $this->snapshot());
    }

    public function test_late_failure_rolls_back_the_entire_dataset(): void
    {
        $before = $this->snapshot();
        $event = 'eloquent.creating: '.FinalEvaluation::class;
        Event::listen($event, fn () => throw new RuntimeException('Injected late failure'));
        try {
            $this->seed(InternFlowDemoSeeder::class);
            $this->fail('Expected failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected late failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertEquals($before, $this->snapshot());
    }
}
