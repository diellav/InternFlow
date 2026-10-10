<?php

namespace App\Modules\Notification\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReadNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->route('notification') !== null) {
            $this->user()->notifications()->whereKey($this->route('notification'))->firstOrFail();
        }

        return true;
    }

    public function rules(): array
    {
        return array_fill_keys(array_keys($this->all()), ['missing']);
    }
}
