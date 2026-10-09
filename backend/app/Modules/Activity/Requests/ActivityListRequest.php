<?php

namespace App\Modules\Activity\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class ActivityListRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('search'))) {
            $search = preg_replace('/^\s+|\s+$/u', '', $this->input('search'));
            $this->merge(['search' => $search === '' ? null : $search]);
        }
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'required', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'date_from' => ['sometimes', 'nullable', 'date', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date', 'date_format:Y-m-d', ...($this->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
