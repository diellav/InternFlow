<?php

namespace App\Modules\Messaging\Requests;

use App\Modules\Messaging\Services\MessagingService;
use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(MessagingService::class)->conversation($this->user(), (int) $this->route('conversation'));

        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('content'))) {
            $this->merge(['content' => preg_replace('/^\s+|\s+$/u', '', $this->input('content'))]);
        }
    }

    public function rules(): array
    {
        return ['content' => ['required', 'string', 'max:2000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'],
            ...array_fill_keys(array_diff(array_keys($this->all()), ['content']), ['missing'])];
    }
}
