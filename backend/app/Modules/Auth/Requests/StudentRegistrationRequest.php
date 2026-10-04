<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class StudentRegistrationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => Str::lower(trim($this->email))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
            'password_confirmation' => ['required', 'string'],
            'phone' => ['nullable', 'string', 'max:255'],
            'student_number' => ['required', 'string', 'max:255', 'unique:student_profiles,student_number'],
            'study_program' => ['required', 'string', 'max:255'],
            'study_year' => ['nullable', 'integer', 'min:1'],
            'role' => ['prohibited'],
            'role_id' => ['prohibited'],
        ];
    }
}
