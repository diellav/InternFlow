<?php

namespace App\Modules\User\Requests;

use Illuminate\Validation\Rule;

class UpdateCoordinatorRequest extends CreateCoordinatorRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['email'] = ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user')->id)];

        foreach (['first_name', 'last_name', 'email', 'phone', 'academic_unit'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        $rules['password'] = ['prohibited'];

        return $rules;
    }
}
