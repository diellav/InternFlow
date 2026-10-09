<?php

namespace App\Modules\Activity\Requests;

use App\Modules\Activity\Services\StudentActivityService;
use Illuminate\Foundation\Http\FormRequest;

class SaveActivityRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['title', 'description'] as $field) {
            if (is_string($this->input($field))) {
                $value = preg_replace('/^\s+|\s+$/u', '', $this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
    }

    public function authorize(): bool
    {
        $service = app(StudentActivityService::class);
        if ($this->isMethod('PATCH')) {
            $service->owned($this->user(), (int) $this->route('activity'));
        } else {
            $service->parent($this->user(), (int) $this->route('internship'));
        }

        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];
        $rules = [
            'activity_date' => [...$required, 'date_format:Y-m-d'],
            'title' => [...$required, 'string', 'max:255'], 'description' => [...$required, 'string', 'max:10000'],
            'hours' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:24'],
        ];

        return [...$rules, ...array_fill_keys(array_diff(array_keys($this->all()), array_keys($rules)), ['missing'])];
    }
}
