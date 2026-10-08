<?php

namespace App\Modules\Company\Requests;

class ListSupervisorsRequest extends ListCompaniesRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'company_id' => ['sometimes', 'required', 'integer', 'min:1', 'exists:companies,id']];
    }
}
