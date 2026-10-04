<?php

namespace App\Modules\Auth\DTOs;

use App\Modules\Auth\Enums\CompanyRegistrationMode;

readonly class SupervisorRegistrationDTO
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $password,
        public ?string $phone,
        public ?string $jobTitle,
        public CompanyRegistrationMode $companyMode,
        public ?int $companyId,
        public ?string $companyName,
        public ?string $companyIndustry,
        public ?string $companyAddress,
        public ?string $companyEmail,
        public ?string $companyPhone,
        public ?string $companyWebsite,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            firstName: $validated['first_name'],
            lastName: $validated['last_name'],
            email: $validated['email'],
            password: $validated['password'],
            phone: $validated['phone'] ?? null,
            jobTitle: $validated['job_title'] ?? null,
            companyMode: CompanyRegistrationMode::from($validated['company_mode']),
            companyId: isset($validated['company_id']) ? (int) $validated['company_id'] : null,
            companyName: $validated['company_name'] ?? null,
            companyIndustry: $validated['company_industry'] ?? null,
            companyAddress: $validated['company_address'] ?? null,
            companyEmail: $validated['company_email'] ?? null,
            companyPhone: $validated['company_phone'] ?? null,
            companyWebsite: $validated['company_website'] ?? null,
        );
    }
}
