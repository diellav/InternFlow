<?php

namespace App\Modules\Evaluation\Requests;

use App\Modules\Evaluation\Services\CoordinatorCompletionService;
use Illuminate\Foundation\Http\FormRequest;

class CompleteInternshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        $parent = app(CoordinatorCompletionService::class)->owned($this->user(), (int) $this->route('internship'));
        abort_unless($parent->status === 'ACTIVE' && $parent->completed_at === null && $parent->finalEvaluation !== null, 409);

        return true;
    }

    public function rules(): array
    {
        return ['expected_evaluation_id' => ['required', 'integer', 'min:1', function ($attribute, $value, $fail): void {
            if (is_bool($value)) {
                $fail('The evaluation ID must be an integer.');
            }
        }], ...array_fill_keys(array_diff(array_keys($this->all()), ['expected_evaluation_id']), ['missing'])];
    }
}
