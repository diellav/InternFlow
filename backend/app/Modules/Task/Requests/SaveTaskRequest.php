<?php

namespace App\Modules\Task\Requests;

use App\Modules\Internship\Services\SupervisorInternshipService;
use App\Modules\Task\Services\TaskService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->isMethod('PATCH')) {
            app(TaskService::class)->details($this->user(), (int) $this->route('task'));
        } else {
            app(SupervisorInternshipService::class)->details($this->user(), (int) $this->route('internship'));
        }

        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];
        $rules = ['title' => [...$required, 'string', 'max:255'], 'description' => [...$required, 'string', 'max:10000'],
            'priority' => [...$required, Rule::in(['LOW', 'MEDIUM', 'HIGH'])], 'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d']];

        return [...$rules, ...array_fill_keys(array_diff(array_keys($this->all()), array_keys($rules)), ['missing'])];
    }
}
