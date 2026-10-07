<?php

namespace App\Modules\User\Requests;

use App\Shared\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListUsersRequest extends FormRequest
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

        if (in_array($this->input('is_active'), ['true', 'false'], true)) {
            $this->merge(['is_active' => $this->input('is_active') === 'true']);
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['sometimes', 'required', Rule::enum(UserRole::class)],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'page' => ['sometimes', 'required', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'sort' => ['prohibited'],
        ];
    }
}
