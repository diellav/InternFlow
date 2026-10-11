<?php

namespace App\Modules\Messaging\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['type' => ['required', Rule::in(['student_supervisor', 'student_coordinator', 'group'])],
            ...array_fill_keys(array_diff(array_keys($this->all()), ['type']), ['missing'])];
    }
}
