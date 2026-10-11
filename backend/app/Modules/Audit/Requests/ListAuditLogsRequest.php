<?php

namespace App\Modules\Audit\Requests;

use App\Shared\Enums\UserRole;
use App\Shared\Services\AuditService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAuditLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->hasRole(UserRole::ADMIN);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('search'))) {
            $this->merge(['search' => trim($this->input('search')) ?: null]);
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string', Rule::in([...array_keys(AuditService::events()), 'internflow.demo.seed.v1'])],
            'entity_type' => ['nullable', 'string', Rule::in([...array_unique(array_values(AuditService::events())), 'demo_dataset'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'page' => ['sometimes', 'required', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
