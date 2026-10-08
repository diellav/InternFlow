<?php

namespace App\Modules\Internship\Requests;

use App\Modules\Internship\Services\CoordinatorInternshipService;
use Illuminate\Foundation\Http\FormRequest;

class StartInternshipReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(CoordinatorInternshipService::class)->assigned($this->user(), (int) $this->route('internship'));

        return true;
    }

    public function rules(): array
    {
        return array_fill_keys(array_keys($this->all()), ['missing']);
    }
}
