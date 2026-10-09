<?php

namespace App\Modules\Task\Requests;

use App\Shared\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['status' => ['sometimes', 'required', Rule::enum(TaskStatus::class)], 'priority' => ['sometimes', 'required', Rule::in(['LOW', 'MEDIUM', 'HIGH'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'], 'page' => ['sometimes', 'required', 'integer', 'min:1'], 'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100']];
    }
}
