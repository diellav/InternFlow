<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Shared\Enums\VerificationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupervisorRegistrationCompanyOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_options_are_public_and_include_only_eligible_companies(): void
    {
        $approved = $this->company('Approved Company', VerificationStatus::APPROVED);
        $pending = $this->company('Pending Company', VerificationStatus::PENDING);
        $this->company('Rejected Company', VerificationStatus::REJECTED);

        $this->getJson('/api/auth/register/supervisor/companies')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    ['id' => $approved->id, 'name' => 'Approved Company'],
                    ['id' => $pending->id, 'name' => 'Pending Company'],
                ],
            ]);
    }

    public function test_company_options_expose_no_administrative_or_contact_metadata(): void
    {
        $this->company('Safe Company', VerificationStatus::APPROVED, [
            'industry' => 'Software',
            'address' => 'Private address',
            'email' => 'private@example.com',
            'phone' => '+48 000 000 000',
            'website' => 'https://example.com',
            'verification_note' => 'Internal note',
        ]);

        $response = $this->getJson('/api/auth/register/supervisor/companies')->assertOk();

        $this->assertSame(['id', 'name'], array_keys($response->json('data.0')));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function company(string $name, VerificationStatus $status, array $attributes = []): Company
    {
        return Company::query()->create(array_merge([
            'name' => $name,
            'verification_status' => $status,
        ], $attributes));
    }
}
