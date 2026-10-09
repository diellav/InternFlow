<?php

namespace App\Modules\Task\Requests;

use App\Modules\Task\Rules\TaskAttachment;
use App\Modules\Task\Services\StudentTaskService;
use Illuminate\Foundation\Http\FormRequest;

class StudentTaskActionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['submission_text', 'resource_url'] as $field) {
            if (is_string($this->input($field))) {
                $value = preg_replace('/^\s+|\s+$/u', '', $this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
    }

    public function authorize(): bool
    {
        app(StudentTaskService::class)->owned($this->user(), (int) $this->route('task'));

        return true;
    }

    public function rules(): array
    {
        $rules = $this->routeIs('student.tasks.start') ? [] : [
            'submission_text' => ['required', 'string', 'max:10000'],
            'resource_url' => ['sometimes', 'nullable', 'string', 'max:255', 'url:https'],
            'files' => ['sometimes', 'array', 'max:5'],
            'files.*' => ['bail', 'required', 'file', 'max:10240', new TaskAttachment],
        ];
        if ($this->routeIs('student.tasks.resubmit')) {
            $rules['expected_submission_id'] = ['required', 'integer', 'min:1'];
        }

        return [...$rules, ...array_fill_keys(array_diff(array_keys($this->all()), array_keys($rules)), ['missing'])];
    }
}
