<?php

namespace App\Modules\Evaluation\Requests;

use App\Models\FinalEvaluation;
use App\Modules\Evaluation\Services\SupervisorEvaluationService;
use Illuminate\Foundation\Http\FormRequest;

class SaveEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $parent = app(SupervisorEvaluationService::class)->owned($this->user(), (int) $this->route('internship'));
        abort_unless($parent->status === 'ACTIVE' && $parent->finalEvaluation?->submitted_at === null, 409);

        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('comments'))) {
            $this->merge(['comments' => preg_replace('/^\s+|\s+$/u', '', $this->input('comments'))]);
        }
    }

    public function rules(): array
    {
        $ratingRules = ['sometimes', 'nullable', 'integer', 'between:1,5', function ($attribute, $value, $fail): void {
            if (is_bool($value)) {
                $fail('The rating must be an integer from 1 to 5.');
            }
        }];
        $rules = [...array_fill_keys(FinalEvaluation::RATINGS, $ratingRules),
            'comments' => ['sometimes', 'nullable', 'string', 'max:10000']];

        return [...$rules, ...array_fill_keys(array_diff(array_keys($this->all()), array_keys($rules)), ['missing'])];
    }
}
