<?php

namespace App\Modules\User\Requests;

use App\Shared\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = [
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];

        if ($this->user()->hasRole(UserRole::COMPANY_SUPERVISOR)) {
            $rules['job_title'] = ['sometimes', 'nullable', 'string', 'max:255'];
        }

        foreach (array_diff(array_keys($this->all()), array_keys($rules)) as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }
}
