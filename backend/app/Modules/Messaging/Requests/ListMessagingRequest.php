<?php

namespace App\Modules\Messaging\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

class ListMessagingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $messages = $this->route('conversation') !== null;
        $rules = ['per_page' => ['sometimes', 'required', 'integer', 'between:1,50']];
        if ($messages) {
            $rules['latest'] = ['sometimes', 'required', 'in:true,false'];
            $rules['cursor'] = ['sometimes', 'required', 'string', 'max:2048', function ($attribute, $value, $fail): void {
                $decoded = is_string($value) ? json_decode(base64_decode(strtr($value, '-_', '+/'), true) ?: '', true) : null;
                if (! is_array($decoded) || Validator::make($decoded, ['sent_at' => ['required', 'string', 'date'], 'id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX], '_pointsToNextItems' => ['required', 'boolean']])->fails()) {
                    $fail('Invalid message cursor.');
                }
            }];
        } else {
            $rules['search'] = ['sometimes', 'nullable', 'string', 'max:100'];
            $rules['filter'] = ['sometimes', 'required', 'in:all,unread,read'];
            $rules['page'] = ['sometimes', 'required', 'integer', 'between:1,1000000'];
        }

        return [...$rules, ...array_fill_keys(array_diff(array_keys($this->all()), array_keys($rules)), ['missing'])];
    }
}
