<?php

namespace App\Modules\Notification\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class NotificationPayload
{
    private const RELATED_TYPES = ['internship', 'task', 'activity', 'company', 'user', 'final_evaluation'];

    public static function make(array $data): array
    {
        $plainText = function ($attribute, $value, $fail): void {
            if (is_string($value) && ($value !== strip_tags($value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value))) {
                $fail('The field must contain plain text.');
            }
        };
        $internalRoute = function ($attribute, $value, $fail): void {
            if (! self::isInternalRoute($value)) {
                $fail('The action URL must be an internal application route.');
            }
        };
        $validated = Validator::make($data, [
            'title' => ['required', 'string', 'max:200', $plainText],
            'message' => ['required', 'string', 'max:1000', $plainText],
            'category' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/D'],
            'action_url' => ['present', 'nullable', 'string', 'max:255', $internalRoute],
            'related_type' => ['present', 'nullable', Rule::in(self::RELATED_TYPES)],
            'related_id' => ['present', 'nullable', 'integer', 'min:1', function ($attribute, $value, $fail): void {
                if (is_bool($value)) {
                    $fail('The related ID must be an integer.');
                }
            }],
            ...array_fill_keys(array_diff(array_keys($data), ['title', 'message', 'category', 'action_url', 'related_type', 'related_id']), ['missing']),
        ])->validate();

        if ($validated['related_id'] !== null) {
            $validated['related_id'] = (int) $validated['related_id'];
        }

        return $validated;
    }

    public static function safe(mixed $data): array
    {
        $data = is_array($data) ? $data : [];
        $text = fn ($value, $limit) => is_string($value) ? mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', strip_tags($value)), 0, $limit) : '';
        $category = $data['category'] ?? null;
        $relatedId = $data['related_id'] ?? null;

        return [
            'title' => $text($data['title'] ?? null, 200),
            'message' => $text($data['message'] ?? null, 1000),
            'category' => is_string($category) && strlen($category) <= 80 && preg_match('/^[a-z][a-z0-9_]*$/D', $category) ? $category : null,
            'action_url' => self::isInternalRoute($data['action_url'] ?? null) ? $data['action_url'] : null,
            'related_type' => in_array($data['related_type'] ?? null, self::RELATED_TYPES, true) ? $data['related_type'] : null,
            'related_id' => is_int($relatedId) && $relatedId > 0 ? $relatedId : null,
        ];
    }

    private static function isInternalRoute(mixed $url): bool
    {
        return is_string($url) && strlen($url) <= 255
            && preg_match('~^/(?:student|supervisor|coordinator|admin)/(?:internships|tasks|activities|companies|users|supervisors|monitoring/internships|monitoring/tasks|monitoring/activities)(?:/[1-9][0-9]*)?(?:/(?:activities|final-evaluation))?$~D', $url) === 1;
    }
}
