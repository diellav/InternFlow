<?php

namespace App\Modules\Monitoring\Requests;

use App\Modules\Monitoring\Services\CoordinatorMonitoringService;
use Illuminate\Foundation\Http\FormRequest;

class MonitoringListRequest extends FormRequest
{
    public function authorize(): bool
    {
        $service = app(CoordinatorMonitoringService::class);
        if ($this->route('internship') !== null) {
            $service->internship($this->user(), (int) $this->route('internship'));
        } elseif ($this->route('task') !== null) {
            $service->task($this->user(), (int) $this->route('task'));
        } else {
            $service->internships($this->user());
        }

        return true;
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'required', 'integer', 'min:1'], 'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100']];
    }
}
