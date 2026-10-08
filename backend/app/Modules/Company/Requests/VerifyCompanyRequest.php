<?php

namespace App\Modules\Company\Requests;

use App\Shared\Enums\VerificationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        $rules = [
            'decision' => ['required', Rule::in([VerificationStatus::APPROVED->value, VerificationStatus::REJECTED->value])],
            'reason' => $this->input('decision') === VerificationStatus::REJECTED->value
                ? ['required', 'string', 'max:2000']
                : ['missing'],
        ];

        foreach (array_diff(array_keys($this->all()), array_keys($rules)) as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }
}
