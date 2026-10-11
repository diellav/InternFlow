<?php

namespace App\Modules\Messaging\Requests;

use App\Modules\Messaging\Services\MessagingService;
use Illuminate\Foundation\Http\FormRequest;

class ReadConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(MessagingService::class)->conversation($this->user(), (int) $this->route('conversation'));

        return true;
    }

    public function rules(): array
    {
        return ['last_read_message_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX, function ($attribute, $value, $fail): void {
            if (is_bool($value)) {
                $fail('The message cursor must be an integer.');
            }
        }],
            ...array_fill_keys(array_diff(array_keys($this->all()), ['last_read_message_id']), ['missing'])];
    }
}
