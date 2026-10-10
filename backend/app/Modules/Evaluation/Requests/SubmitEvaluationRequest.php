<?php

namespace App\Modules\Evaluation\Requests;

use App\Modules\Evaluation\Services\SupervisorEvaluationService;
use Illuminate\Foundation\Http\FormRequest;

class SubmitEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $parent = app(SupervisorEvaluationService::class)->owned($this->user(), (int) $this->route('internship'));
        abort_unless($parent->status === 'ACTIVE' && $parent->finalEvaluation !== null && $parent->finalEvaluation->submitted_at === null, 409);

        return true;
    }

    public function rules(): array
    {
        return ['draft_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/D'],
            ...array_fill_keys(array_diff(array_keys($this->all()), ['draft_token']), ['missing'])];
    }
}
