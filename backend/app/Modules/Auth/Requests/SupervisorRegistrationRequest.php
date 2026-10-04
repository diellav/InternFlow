<?php

namespace App\Modules\Auth\Requests;

use App\Modules\Auth\Enums\CompanyRegistrationMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SupervisorRegistrationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => Str::lower(trim($this->email))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $existingCompany = fn (): bool => $this->input('company_mode') === CompanyRegistrationMode::EXISTING->value;
        $newCompany = fn (): bool => $this->input('company_mode') === CompanyRegistrationMode::NEW->value;

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
            'password_confirmation' => ['required', 'string'],
            'phone' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'company_mode' => ['required', Rule::enum(CompanyRegistrationMode::class)],
            'company_id' => [
                Rule::requiredIf($existingCompany),
                Rule::prohibitedIf($newCompany),
                'integer',
                'exists:companies,id',
            ],
            'company_name' => [Rule::requiredIf($newCompany), Rule::prohibitedIf($existingCompany), 'string', 'max:255'],
            'company_industry' => [Rule::prohibitedIf($existingCompany), 'nullable', 'string', 'max:255'],
            'company_address' => [Rule::prohibitedIf($existingCompany), 'nullable', 'string'],
            'company_email' => [Rule::prohibitedIf($existingCompany), 'nullable', 'email', 'max:255'],
            'company_phone' => [Rule::prohibitedIf($existingCompany), 'nullable', 'string', 'max:255'],
            'company_website' => [Rule::prohibitedIf($existingCompany), 'nullable', 'url:http,https', 'max:255'],
            'role' => ['prohibited'],
            'role_id' => ['prohibited'],
            'is_active' => ['prohibited'],
            'verification_status' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'reviewed_at' => ['prohibited'],
            'review_comment' => ['prohibited'],
            'verified_by' => ['prohibited'],
            'verified_at' => ['prohibited'],
            'verification_note' => ['prohibited'],
        ];
    }
}
