<?php

namespace App\Modules\Internship\Requests;

use App\Shared\Enums\InternshipStatus;
use Illuminate\Validation\Rule;

class InternshipDecisionRequest extends StartInternshipReviewRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('decision_comment'))) {
            $this->merge(['decision_comment' => trim($this->input('decision_comment'))]);
        }
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in([InternshipStatus::APPROVED->value, InternshipStatus::REJECTED->value, InternshipStatus::REVISION_REQUIRED->value])],
            'decision_comment' => $this->input('decision') === InternshipStatus::APPROVED->value ? ['missing'] : ['required', 'string', 'max:10000'],
            ...array_fill_keys(array_diff(array_keys($this->all()), ['decision', 'decision_comment']), ['missing']),
        ];
    }
}
