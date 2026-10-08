<?php

namespace Tests\Feature\Internship;

use App\Models\Company;
use App\Models\Internship;
use App\Models\Role;
use App\Models\User;
use App\Modules\Internship\Services\StudentInternshipService;
use App\Shared\Enums\InternshipStatus;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StudentInternshipsTest extends TestCase
{
    use RefreshDatabase;

    private string $path = '/api/student/internships';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_student_creates_a_minimal_owned_draft_without_other_workflow_records(): void
    {
        $student = $this->student();
        $company = $this->company();
        $before = $company->fresh()->getAttributes();
        $response = $this->actingAs($student)->postJson($this->path, $this->payload($company))
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.description', null)
            ->assertJsonPath('data.supervisor', null)->assertJsonPath('data.coordinator', null)
            ->assertJsonPath('data.submitted_at', null)->assertJsonPath('data.company.name', $company->name)
            ->assertJsonMissingPath('data.student_id')->assertJsonMissingPath('data.company.verification_note');
        $this->assertDatabaseHas('internships', ['id' => $response->json('data.id'), 'student_id' => $student->id, 'company_id' => $company->id, 'status' => 'DRAFT', 'company_supervisor_id' => null]);
        foreach (['tasks', 'activity_logs', 'final_evaluations', 'messages', 'notifications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($before, $company->fresh()->getAttributes());
        $this->assertTrue($student->fresh()->is_active);
    }

    public function test_listing_and_details_are_owned_safe_paginated_filtered_and_ordered(): void
    {
        $student = $this->student();
        $company = $this->company();
        $first = $this->draft($student, $company, ['created_at' => '2026-01-01 00:00:00']);
        $second = $this->draft($student, $company, ['created_at' => '2026-01-01 00:00:00', 'status' => 'SUBMITTED']);
        $this->draft($this->student(), $company);
        $this->actingAs($student)->getJson($this->path)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->id)->assertJsonPath('meta.per_page', 15)->assertJsonStructure(['data', 'links', 'meta']);
        $this->getJson($this->path.'?per_page=1&page=2')->assertJsonPath('data.0.id', $first->id)->assertJsonPath('meta.last_page', 2);
        $this->getJson($this->path.'?status=DRAFT')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
        $this->getJson($this->path.'/'.$first->id)->assertOk()->assertJsonPath('data.start_date', '2026-11-01');
        $this->getJson($this->path.'?status=invalid&per_page=101&page=0')->assertUnprocessable();
    }

    public function test_partial_draft_updates_preserve_fields_and_validate_the_merged_date_range(): void
    {
        $student = $this->student();
        $draft = $this->draft($student, $this->company(), ['description' => 'Original']);
        $this->actingAs($student)->patchJson($this->path.'/'.$draft->id, ['position_title' => '  Updated position  '])->assertOk()
            ->assertJsonPath('data.position_title', 'Updated position')->assertJsonPath('data.description', 'Original')->assertJsonPath('data.status', 'DRAFT');
        $before = $draft->fresh()->getAttributes();
        $this->patchJson($this->path.'/'.$draft->id, ['description' => 'Must not persist', 'start_date' => '2027-01-01'])->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->assertSame($before, $draft->fresh()->getAttributes());
        $this->patchJson($this->path.'/'.$draft->id, ['end_date' => '2026-10-01'])->assertUnprocessable();
        $this->patchJson($this->path.'/'.$draft->id, ['description' => null])->assertOk()->assertJsonPath('data.description', null);
    }

    public function test_other_students_ids_are_not_disclosed_or_updated_even_with_invalid_payloads(): void
    {
        $draft = $this->draft($this->student(), $this->company());
        $before = $draft->fresh()->getAttributes();
        $this->actingAs($this->student())->getJson($this->path.'/'.$draft->id)->assertNotFound();
        $this->patchJson($this->path.'/'.$draft->id, ['position_title' => 'Unsafe'])->assertNotFound();
        $this->patchJson($this->path.'/'.$draft->id, ['status' => 'APPROVED'])->assertNotFound();
        $this->deleteJson($this->path.'/'.$draft->id)->assertStatus(405);
        $this->getJson($this->path.'/999999')->assertNotFound();
        $this->assertSame($before, $draft->fresh()->getAttributes());
    }

    public function test_noneditable_statuses_are_read_only_and_stale_updates_recheck_state(): void
    {
        $student = $this->student();
        $company = $this->company();
        $this->actingAs($student);
        foreach (InternshipStatus::cases() as $status) {
            if (in_array($status, [InternshipStatus::DRAFT, InternshipStatus::REVISION_REQUIRED], true)) {
                continue;
            }
            $draft = $this->draft($student, $company, ['status' => $status->value]);
            $this->getJson($this->path.'/'.$draft->id)->assertOk();
            $this->patchJson($this->path.'/'.$draft->id, ['position_title' => 'Unsafe'])->assertConflict();
            $this->assertSame($status->value, $draft->fresh()->status);
        }
        $draft = $this->draft($student, $company);
        $draft->update(['status' => 'SUBMITTED']);
        try {
            app(StudentInternshipService::class)->save($student, ['position_title' => 'Stale'], $draft->id);
            $this->fail('Changed status must be rechecked.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertNotSame('Stale', $draft->fresh()->position_title);
    }

    public function test_protected_attributes_and_invalid_fields_never_partially_persist(): void
    {
        $student = $this->student();
        $company = $this->company();
        $draft = $this->draft($student, $company);
        $this->actingAs($student);
        foreach (['student_id', 'user_id', 'status', 'coordinator_id', 'submitted_at', 'approved_at', 'decision_comment', 'completed_at', 'id'] as $field) {
            $this->postJson($this->path, [...$this->payload($company), $field => null])->assertUnprocessable();
            $this->patchJson($this->path.'/'.$draft->id, [$field => null])->assertUnprocessable();
        }
        foreach ([['position_title' => str_repeat('x', 256)], ['description' => str_repeat('x', 10001)], ['start_date' => '2026-02-30'], ['end_date' => '2026-10-01'], ['company_id' => null], ['position_title' => '   ']] as $invalid) {
            $this->postJson($this->path, [...$this->payload($company), ...$invalid])->assertUnprocessable();
        }
        $this->postJson($this->path, [])->assertUnprocessable()->assertJsonValidationErrors(['company_id', 'position_title', 'start_date', 'end_date']);
        $this->assertDatabaseCount('internships', 1);
    }

    public function test_company_and_supervisor_selection_is_verified_active_associated_and_safe(): void
    {
        $student = $this->student();
        $company = $this->company();
        $supervisor = $this->supervisor($company);
        $unrelated = $this->supervisor($this->company());
        $this->actingAs($student);
        $this->getJson('/api/student/internship-options/companies')->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.verification_note');
        $this->getJson('/api/student/internship-options/companies/'.$company->id.'/supervisors')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $supervisor->id)->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.password');
        $this->postJson($this->path, [...$this->payload($company), 'company_supervisor_id' => $unrelated->id])->assertUnprocessable()->assertJsonValidationErrors('company_supervisor_id');
        $this->postJson($this->path, [...$this->payload($company), 'company_supervisor_id' => $supervisor->id])->assertCreated()
            ->assertJsonPath('data.supervisor.first_name', $supervisor->first_name)->assertJsonMissingPath('data.supervisor.review_comment');
        foreach (['PENDING', 'REJECTED'] as $status) {
            $company->update(['verification_status' => $status]);
            $this->postJson($this->path, $this->payload($company))->assertUnprocessable()->assertJsonValidationErrors('company_id');
            $this->getJson('/api/student/internship-options/companies/'.$company->id.'/supervisors')->assertNotFound();
        }
        $company->update(['verification_status' => 'APPROVED', 'is_active' => false]);
        $this->postJson($this->path, $this->payload($company))->assertUnprocessable();
        $company->update(['is_active' => true]);
        foreach (['PENDING', 'REJECTED'] as $status) {
            $supervisor->companySupervisorProfile()->update(['verification_status' => $status]);
            $this->postJson($this->path, [...$this->payload($company), 'company_supervisor_id' => $supervisor->id])->assertUnprocessable();
        }
        $supervisor->companySupervisorProfile()->update(['verification_status' => 'APPROVED']);
        $supervisor->update(['is_active' => false]);
        $this->postJson($this->path, [...$this->payload($company), 'company_supervisor_id' => $supervisor->id])->assertUnprocessable();
        $this->getJson('/api/student/internship-options/companies/'.$company->id.'/supervisors')->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('internships', 1);
    }

    public function test_changing_company_requires_clearing_or_replacing_the_previous_supervisor(): void
    {
        $student = $this->student();
        $company = $this->company();
        $supervisor = $this->supervisor($company);
        $otherCompany = $this->company();
        $draft = $this->draft($student, $company, ['company_supervisor_id' => $supervisor->id]);
        $this->actingAs($student)->patchJson($this->path.'/'.$draft->id, ['company_id' => $otherCompany->id])->assertUnprocessable();
        $this->assertSame($company->id, $draft->fresh()->company_id);
        $this->patchJson($this->path.'/'.$draft->id, ['company_id' => $otherCompany->id, 'company_supervisor_id' => null])->assertOk()->assertJsonPath('data.supervisor', null);
    }

    public function test_guests_non_students_inactive_and_missing_student_profiles_are_denied(): void
    {
        $company = $this->company();
        $draft = $this->draft($this->student(), $company);
        $check = function (int $status) use ($company, $draft): void {
            $this->getJson($this->path)->assertStatus($status);
            $this->getJson($this->path.'/'.$draft->id)->assertStatus($status);
            $this->postJson($this->path, $this->payload($company))->assertStatus($status);
            $this->patchJson($this->path.'/'.$draft->id, ['position_title' => 'Unsafe'])->assertStatus($status);
            $this->getJson('/api/student/internship-options/companies')->assertStatus($status);
            $this->getJson('/api/student/internship-options/companies/'.$company->id.'/supervisors')->assertStatus($status);
        };
        $check(401);
        foreach ([UserRole::ADMIN, UserRole::ACADEMIC_COORDINATOR, UserRole::COMPANY_SUPERVISOR] as $role) {
            $this->actingAs($this->user($role));
            $check(403);
        }
        $inactive = $this->student();
        $inactive->update(['is_active' => false]);
        $this->actingAs($inactive);
        $check(403);
        $this->actingAs($this->user(UserRole::STUDENT));
        $check(403);
    }

    public function test_listing_query_count_is_constant_with_more_records(): void
    {
        $student = $this->student();
        $company = $this->company();
        $supervisor = $this->supervisor($company);
        $this->draft($student, $company, ['company_supervisor_id' => $supervisor->id]);
        $this->actingAs($student->load('role'));
        $count = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->getJson($this->path)->assertOk();

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        };
        $small = $count();
        for ($i = 0; $i < 4; $i++) {
            $this->draft($student, $company, ['company_supervisor_id' => $supervisor->id]);
        }
        $this->assertSame($small, $count());
    }

    private function student(): User
    {
        $user = $this->user(UserRole::STUDENT);
        $user->studentProfile()->create(['student_number' => fake()->uuid(), 'study_program' => 'Computer Science', 'study_year' => 2]);

        return $user;
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('name', $role->value)->sole()->id]);
    }

    private function company(): Company
    {
        return Company::query()->create(['name' => fake()->company(), 'verification_status' => VerificationStatus::APPROVED]);
    }

    private function supervisor(Company $company): User
    {
        $user = $this->user(UserRole::COMPANY_SUPERVISOR);
        $user->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => VerificationStatus::APPROVED, 'job_title' => 'Mentor']);

        return $user;
    }

    private function payload(Company $company): array
    {
        return ['company_id' => $company->id, 'position_title' => 'Software Intern', 'start_date' => '2026-11-01', 'end_date' => '2026-12-01'];
    }

    private function draft(User $student, Company $company, array $data = []): Internship
    {
        return Internship::query()->create(['student_id' => $student->id, ...$this->payload($company), ...$data]);
    }
}
