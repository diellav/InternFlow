<?php

namespace App\Modules\Internship\Requests;

use App\Models\AcademicCoordinatorProfile;
use Illuminate\Foundation\Http\FormRequest;

class ClaimInternshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return AcademicCoordinatorProfile::query()->whereKey($this->user()->id)->exists();
    }

    public function rules(): array
    {
        return array_fill_keys(array_keys($this->all()), ['missing']);
    }
}
