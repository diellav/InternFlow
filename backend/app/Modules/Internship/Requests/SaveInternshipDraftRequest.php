<?php

namespace App\Modules\Internship\Requests;

use App\Modules\Internship\Services\StudentInternshipService;
use Illuminate\Foundation\Http\FormRequest;

class SaveInternshipDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->isMethod('PATCH')) {
            app(StudentInternshipService::class)->details($this->user(), (int) $this->route('internship'));
        }

        return $this->user() !== null;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];
        $rules = [
            'company_id' => [...$required, 'integer', 'min:1'],
            'company_supervisor_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'position_title' => [...$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'start_date' => [...$required, 'date_format:Y-m-d'],
            'end_date' => [...$required, 'date_format:Y-m-d'],
        ];
        foreach (array_diff(array_keys($this->all()), array_keys($rules)) as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }
}
