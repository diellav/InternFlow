<?php

namespace App\Modules\Internship\Requests;

use App\Modules\Internship\Services\StudentInternshipService;
use Illuminate\Foundation\Http\FormRequest;

class SubmitInternshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(StudentInternshipService::class)->details($this->user(), (int) $this->route('internship'));

        return true;
    }

    public function rules(): array
    {
        return array_fill_keys(array_keys($this->all()), ['missing']);
    }
}
