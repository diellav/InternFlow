<?php

namespace Tests\Feature\Company;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Modules\Company\Services\VerificationApplicationService;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use App\Shared\Http\Middleware\EnsureAccountIsActive;
use App\Shared\Http\Middleware\EnsureSupervisorIsApproved;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VerificationApplicationTest extends TestCase
{
    use RefreshDatabase;

    private string $path = '/api/supervisor/verification-application';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_combined_correction_and_resubmission_clear_metadata_without_duplicates_or_operational_access(): void
    {
        [$user, $company] = $this->application();
        $this->actingAs($user);
        $this->getJson($this->path)->assertOk()->assertJsonPath('data.company.rejection_reason', 'Company reason')
            ->assertJsonPath('data.supervisor.rejection_reason', 'Supervisor reason')->assertJsonPath('data.can_resubmit_company', true)
            ->assertJsonMissingPath('data.supervisor.password')->assertJsonMissingPath('data.company.verified_by');
        $this->patchJson($this->path, ['supervisor' => ['first_name' => 'Corrected', 'job_title' => 'Mentor'], 'company' => ['name' => 'Corrected Company', 'email' => 'company@example.test']])
            ->assertOk()->assertJsonPath('data.company.verification_status', 'REJECTED')->assertJsonPath('data.supervisor.verification_status', 'REJECTED');
        $this->assertSame('Corrected', $user->fresh()->first_name);
        $this->postJson($this->path.'/resubmit', ['targets' => ['company', 'supervisor']])->assertOk()
            ->assertJsonPath('data.company.verification_status', 'PENDING')->assertJsonPath('data.supervisor.verification_status', 'PENDING')
            ->assertJsonPath('data.company.rejection_reason', null)->assertJsonPath('data.supervisor.rejection_reason', null);
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'verified_by' => null, 'verified_at' => null, 'verification_note' => null]);
        $this->assertDatabaseHas('company_supervisor_profiles', ['user_id' => $user->id, 'reviewed_by' => null, 'reviewed_at' => null, 'review_comment' => null]);
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('company_supervisor_profiles', 1);
        $this->assertTrue($user->fresh()->is_active);
        $request = Request::create('/operation');
        $request->setUserResolver(fn () => $user->fresh());
        $this->assertSame(403, app(EnsureSupervisorIsApproved::class)->handle($request, fn () => response()->json([]))->getStatusCode());
        $this->postJson($this->path.'/resubmit', ['targets' => ['company', 'supervisor']])->assertConflict();
        $admin = $this->user(UserRole::ADMIN);
        $this->actingAs($admin);
        $this->getJson('/api/admin/companies')->assertJsonPath('data.0.verification_status', 'PENDING');
        $this->getJson('/api/admin/supervisors')->assertJsonPath('data.0.profile.verification_status', 'PENDING');
        $this->patchJson('/api/admin/companies/'.$company->id.'/verification', ['decision' => 'APPROVED'])->assertOk();
        $this->patchJson('/api/admin/supervisors/'.$user->id.'/verification', ['decision' => 'APPROVED'])->assertOk();
    }

    public function test_independent_resubmission_preserves_other_application_for_cases_b_c_and_d(): void
    {
        foreach ([['APPROVED', 'REJECTED', 'supervisor'], ['REJECTED', 'APPROVED', 'company'], ['PENDING', 'REJECTED', 'supervisor']] as [$companyStatus, $supervisorStatus, $target]) {
            [$user, $company] = $this->application($companyStatus, $supervisorStatus);
            $other = $target === 'company' ? $user->companySupervisorProfile->fresh()->getAttributes() : $company->fresh()->getAttributes();
            $this->actingAs($user);
            $this->postJson($this->path.'/resubmit', ['targets' => [$target]])->assertOk()->assertJsonPath('data.'.$target.'.verification_status', 'PENDING');
            $this->assertSame($other, $target === 'company' ? $user->companySupervisorProfile->fresh()->getAttributes() : $company->fresh()->getAttributes());
        }
    }

    public function test_pending_and_approved_applications_cannot_be_reset_or_edited(): void
    {
        foreach (['PENDING', 'APPROVED'] as $status) {
            [$user] = $this->application($status, $status);
            $this->actingAs($user);
            $this->getJson($this->path)->assertJsonPath('data.can_resubmit_company', false)->assertJsonPath('data.can_resubmit_supervisor', false);
            foreach (['company', 'supervisor'] as $target) {
                $this->postJson($this->path.'/resubmit', ['targets' => [$target]])->assertConflict();
                $this->patchJson($this->path, [$target => [$target === 'company' ? 'name' : 'first_name' => 'Unsafe']])->assertConflict();
            }
        }
    }

    public function test_shared_company_is_read_only_but_supervisor_can_resubmit_independently(): void
    {
        [$user, $company] = $this->application();
        $other = $this->user(UserRole::COMPANY_SUPERVISOR);
        $other->companySupervisorProfile()->create(['company_id' => $company->id]);
        $this->actingAs($user);
        $this->getJson($this->path)->assertJsonPath('data.can_resubmit_company', false)->assertJsonPath('data.editable_company_fields', []);
        $this->patchJson($this->path, ['supervisor' => ['first_name' => 'Unsafe'], 'company' => ['name' => 'Unsafe']])->assertForbidden();
        $this->assertNotSame('Unsafe', $user->fresh()->first_name);
        $this->postJson($this->path.'/resubmit', ['targets' => ['supervisor', 'company']])->assertForbidden();
        $this->assertSame(VerificationStatus::REJECTED, $user->companySupervisorProfile->fresh()->verification_status);
        $this->postJson($this->path.'/resubmit', ['targets' => ['supervisor']])->assertOk();
        $this->assertSame(VerificationStatus::REJECTED, $company->fresh()->verification_status);
    }

    public function test_strict_payloads_and_duplicate_company_validation_cannot_redirect_identity(): void
    {
        [$user, $company] = $this->application();
        $other = Company::query()->create(['name' => 'Another', 'website' => 'https://www.another.test']);
        $this->actingAs($user);
        foreach ([['company_id' => $other->id], ['supervisor' => ['is_active' => false]], ['company' => ['verification_status' => 'APPROVED']], ['supervisor' => ['first_name' => '']], ['company' => ['website' => 'ftp://invalid.test']]] as $payload) {
            $this->patchJson($this->path, $payload)->assertUnprocessable();
        }
        $this->patchJson($this->path, ['company' => ['name' => ' another ']])->assertUnprocessable()->assertJsonValidationErrors('company.name');
        $this->patchJson($this->path, ['company' => ['website' => 'https://another.test/path']])->assertUnprocessable()->assertJsonValidationErrors('company.website');
        $this->postJson($this->path.'/resubmit', ['targets' => ['supervisor'], 'user_id' => $user->id])->assertUnprocessable();
        $this->postJson($this->path.'/resubmit', ['targets' => ['supervisor', 'supervisor']])->assertUnprocessable();
        $this->assertSame('Another', $other->fresh()->name);
        $this->assertNotSame('Another', $company->fresh()->name);
    }

    public function test_combined_conflict_or_invalid_persisted_data_does_not_partially_resubmit(): void
    {
        [$user, $company] = $this->application('APPROVED');
        $this->actingAs($user);
        $this->postJson($this->path.'/resubmit', ['targets' => ['supervisor', 'company']])->assertConflict();
        $this->assertSame(VerificationStatus::REJECTED, $user->companySupervisorProfile->fresh()->verification_status);
        $company->update(['verification_status' => VerificationStatus::REJECTED, 'email' => 'bad-email']);
        $this->postJson($this->path.'/resubmit', ['targets' => ['supervisor', 'company']])->assertUnprocessable();
        $this->assertSame(VerificationStatus::REJECTED, $user->companySupervisorProfile->fresh()->verification_status);
        $this->assertSame('Supervisor reason', $user->companySupervisorProfile->fresh()->review_comment);
    }

    public function test_guests_other_roles_inactive_accounts_and_missing_profiles_are_handled(): void
    {
        $this->getJson($this->path)->assertUnauthorized();
        $this->patchJson($this->path, [])->assertUnauthorized();
        $this->postJson($this->path.'/resubmit', ['targets' => ['company']])->assertUnauthorized();
        foreach ([UserRole::ADMIN, UserRole::STUDENT, UserRole::ACADEMIC_COORDINATOR] as $role) {
            $this->actingAs($this->user($role));
            $this->getJson($this->path)->assertForbidden();
            $this->patchJson($this->path, [])->assertForbidden();
            $this->postJson($this->path.'/resubmit', ['targets' => ['company']])->assertForbidden();
        }
        [$user] = $this->application();
        $user->update(['is_active' => false]);
        $this->actingAs($user);
        $this->getJson($this->path)->assertForbidden();
        $this->patchJson($this->path, [])->assertForbidden();
        $this->postJson($this->path.'/resubmit', ['targets' => ['company']])->assertForbidden();
        $this->actingAs($this->user(UserRole::COMPANY_SUPERVISOR));
        $this->getJson($this->path)->assertOk()->assertJsonPath('data.company', null)->assertJsonPath('data.can_resubmit_supervisor', false);
        $this->postJson($this->path.'/resubmit', ['targets' => ['supervisor']])->assertConflict();
    }

    public function test_write_failure_rolls_back_both_application_statuses_and_metadata(): void
    {
        [$user, $company] = $this->application();
        $beforeCompany = $company->fresh()->getAttributes();
        $beforeProfile = $user->companySupervisorProfile->fresh()->getAttributes();
        $dispatcher = Company::getEventDispatcher();
        Company::setEventDispatcher(clone $dispatcher);
        Company::saving(function (Company $target) use ($company): void {
            if ($target->id === $company->id) {
                throw new RuntimeException('Simulated company persistence failure');
            }
        });
        try {
            app(VerificationApplicationService::class)->resubmit($user, ['supervisor', 'company']);
            $this->fail('Persistence failure must not be swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated company persistence failure', $exception->getMessage());
        } finally {
            Company::setEventDispatcher($dispatcher);
        }
        $this->assertSame($beforeCompany, $company->fresh()->getAttributes());
        $this->assertSame($beforeProfile, $user->companySupervisorProfile->fresh()->getAttributes());
    }

    public function test_stale_loaded_user_cannot_reset_a_changed_application(): void
    {
        [$user, $company] = $this->application();
        $user->load('companySupervisorProfile.company');
        $company->update(['verification_status' => VerificationStatus::APPROVED]);
        try {
            app(VerificationApplicationService::class)->resubmit($user, ['company', 'supervisor']);
            $this->fail('The fresh company status must be checked.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(VerificationStatus::APPROVED, $company->fresh()->verification_status);
        $this->assertSame(VerificationStatus::REJECTED, $user->companySupervisorProfile->fresh()->verification_status);
    }

    public function test_registration_login_rejection_correction_resubmission_and_rereview_work_together(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']);
        $admin = $this->user(UserRole::ADMIN);
        $admin->update(['password' => 'Admin12345']);
        $response = $this->postJson('/api/auth/register/supervisor', [
            'first_name' => 'New', 'last_name' => 'Supervisor', 'email' => 'journey@example.test',
            'password' => 'Test12345', 'password_confirmation' => 'Test12345',
            'company_mode' => 'new', 'company_name' => 'Journey Company',
        ])->assertCreated()->assertJsonPath('data.profile.verification_status', 'PENDING')
            ->assertJsonPath('data.profile.company.verification_status', 'PENDING');
        $user = User::query()->findOrFail($response->json('data.id'));
        $companyId = $response->json('data.profile.company.id');
        $this->postJson('/api/auth/login', ['email' => 'journey@example.test', 'password' => 'Test12345'])->assertOk();
        $this->getJson($this->path)->assertOk()->assertJsonPath('data.can_resubmit_supervisor', false);
        $this->assertSame(403, $this->operationalStatus($user));
        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'Admin12345'])->assertOk();
        $this->patchJson('/api/admin/companies/'.$companyId.'/verification', ['decision' => 'REJECTED', 'reason' => 'Correct company contact'])->assertOk();
        $this->patchJson('/api/admin/supervisors/'.$user->id.'/verification', ['decision' => 'REJECTED', 'reason' => 'Correct supervisor name'])->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', ['email' => 'journey@example.test', 'password' => 'Test12345'])->assertOk();
        $this->getJson($this->path)->assertJsonPath('data.company.rejection_reason', 'Correct company contact')
            ->assertJsonPath('data.supervisor.rejection_reason', 'Correct supervisor name');
        $this->patchJson($this->path, ['supervisor' => ['first_name' => 'Corrected'], 'company' => ['email' => 'contact@example.test']])->assertOk()
            ->assertJsonPath('data.supervisor.verification_status', 'REJECTED')->assertJsonPath('data.company.verification_status', 'REJECTED');
        $this->postJson($this->path.'/resubmit', ['targets' => ['company', 'supervisor']])->assertOk()
            ->assertJsonPath('data.company.rejection_reason', null)->assertJsonPath('data.supervisor.rejection_reason', null);
        $this->assertSame(403, $this->operationalStatus($user));
        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'Admin12345'])->assertOk();
        $this->patchJson('/api/admin/companies/'.$companyId.'/verification', ['decision' => 'APPROVED'])->assertOk();
        $this->assertSame(403, $this->operationalStatus($user));
        $this->patchJson('/api/admin/supervisors/'.$user->id.'/verification', ['decision' => 'APPROVED'])->assertOk();
        $this->assertSame(200, $this->operationalStatus($user));
        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', ['email' => 'journey@example.test', 'password' => 'Test12345'])->assertOk();
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.first_name', 'Corrected')
            ->assertJsonPath('data.profile.verification_status', 'APPROVED')->assertJsonPath('data.profile.company.verification_status', 'APPROVED');
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('company_supervisor_profiles', 1);
        $this->assertTrue($user->fresh()->is_active);
    }

    private function operationalStatus(User $user): int
    {
        $request = Request::create('/operation');
        $request->setUserResolver(fn () => $user->fresh());

        return app(EnsureAccountIsActive::class)->handle($request, fn ($request) => app(EnsureSupervisorIsApproved::class)
            ->handle($request, fn () => response()->json([])))->getStatusCode();
    }

    private function application(string $companyStatus = 'REJECTED', string $supervisorStatus = 'REJECTED'): array
    {
        $admin = $this->user(UserRole::ADMIN);
        $company = Company::query()->create(['name' => 'Company '.fake()->uuid(), 'verification_status' => $companyStatus, 'verified_by' => $admin->id, 'verified_at' => now(), 'verification_note' => 'Company reason']);
        $user = $this->user(UserRole::COMPANY_SUPERVISOR);
        $user->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => $supervisorStatus, 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_comment' => 'Supervisor reason']);

        return [$user, $company];
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('name', $role->value)->sole()->id]);
    }
}
