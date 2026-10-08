<?php

namespace App\Modules\Company\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResubmitVerificationApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = ['targets' => ['required', 'array', 'list', 'min:1', 'max:2'], 'targets.*' => ['required', 'string', 'distinct', 'in:company,supervisor']];
        foreach (array_diff(array_keys($this->all()), ['targets']) as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }
}
