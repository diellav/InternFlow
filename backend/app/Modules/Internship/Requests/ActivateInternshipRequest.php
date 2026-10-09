<?php

namespace App\Modules\Internship\Requests;

use App\Modules\Internship\Services\SupervisorInternshipService;
use Illuminate\Foundation\Http\FormRequest;

class ActivateInternshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(SupervisorInternshipService::class)->details($this->user(), (int) $this->route('internship'));

        return true;
    }

    public function rules(): array
    {
        return array_fill_keys(array_keys($this->all()), ['missing']);
    }
}
