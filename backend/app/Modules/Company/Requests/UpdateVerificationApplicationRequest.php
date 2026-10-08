<?php

namespace App\Modules\Company\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVerificationApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = [
            'supervisor' => ['sometimes', 'required', 'array:first_name,last_name,phone,job_title', 'min:1'],
            'supervisor.first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'supervisor.last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'supervisor.phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'supervisor.job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company' => ['sometimes', 'required', 'array:name,industry,address,email,phone,website', 'min:1'],
            'company.name' => ['sometimes', 'required', 'string', 'max:255'],
            'company.industry' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.address' => ['sometimes', 'nullable', 'string'],
            'company.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'company.phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.website' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
        ];
        foreach (array_diff(array_keys($this->all()), ['supervisor', 'company']) as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }
}
