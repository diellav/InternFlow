<?php

namespace App\Modules\Company\Requests;

use App\Shared\Enums\VerificationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCompaniesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('search'))) {
            $search = trim($this->input('search'));
            $this->merge(['search' => $search === '' ? null : $search]);
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'verification_status' => ['sometimes', 'required', Rule::enum(VerificationStatus::class)],
            'page' => ['sometimes', 'required', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'sort' => ['prohibited'],
        ];
    }
}
