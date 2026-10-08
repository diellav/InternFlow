<?php

namespace App\Modules\Internship\Requests;

class ListCoordinatorInternshipsRequest extends ListStudentInternshipsRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'search' => ['sometimes', 'nullable', 'string', 'max:255']];
    }
}
