<?php

namespace App\Modules\Task\Requests;

use App\Modules\Task\Services\TaskService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(TaskService::class)->details($this->user(), (int) $this->route('task'));

        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('comment'))) {
            $comment = preg_replace('/^\s+|\s+$/u', '', $this->input('comment'));
            $this->merge(['comment' => $comment === '' ? null : $comment]);
        }
    }

    public function rules(): array
    {
        $rules = [
            'decision' => ['required', Rule::in(['APPROVED', 'REVISION_REQUIRED'])],
            'comment' => ['required_if:decision,REVISION_REQUIRED', 'nullable', 'string', 'max:10000'],
            'expected_submission_id' => ['required', 'integer', 'min:1'],
        ];

        return [...$rules, ...array_fill_keys(array_diff(array_keys($this->all()), array_keys($rules)), ['missing'])];
    }
}
