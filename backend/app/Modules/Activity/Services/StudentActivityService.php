<?php

namespace App\Modules\Activity\Services;

use App\Models\ActivityLog;
use App\Models\Internship;
use App\Models\StudentProfile;
use App\Models\User;
use App\Shared\Enums\UserRole;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StudentActivityService
{
    public function __construct(private readonly ActivityQueryService $queries) {}

    private function requireProfile(User $user): void
    {
        abort_unless(StudentProfile::whereKey($user->id)->exists(), 403, 'A student profile is required.');
    }

    public function parent(User $user, int $id): Internship
    {
        $this->requireProfile($user);

        return Internship::where('student_id', $user->id)->findOrFail($id);
    }

    public function owned(User $user, int $id): ActivityLog
    {
        $this->requireProfile($user);

        return ActivityLog::whereHas('internship', fn ($query) => $query->where('student_id', $user->id))->with('internship')->findOrFail($id);
    }

    public function details(User $user, int $id): ActivityLog
    {
        $activity = $this->owned($user, $id);
        abort_unless(in_array($activity->internship->status, ['ACTIVE', 'COMPLETED'], true), 404);

        return $activity;
    }

    public function listing(User $user, int $id, array $filters): array
    {
        $internship = $this->parent($user, $id);
        abort_unless($internship->student_id === $user->id && in_array($internship->status, ['ACTIVE', 'COMPLETED'], true), 404);

        return $this->queries->listing($internship, $filters);
    }

    public function save(User $actor, array $data, ?int $internshipId = null, ?int $activityId = null): ActivityLog
    {
        return DB::transaction(function () use ($actor, $data, $internshipId, $activityId): ActivityLog {
            $user = User::with('role')->lockForUpdate()->findOrFail($actor->id);
            abort_unless($user->is_active && $user->hasRole(UserRole::STUDENT), 403);
            abort_unless(StudentProfile::whereKey($user->id)->sharedLock()->first(), 403);
            if ($activityId !== null) {
                $internshipId = $this->owned($user, $activityId)->internship_id;
            }
            $internship = Internship::where('student_id', $user->id)->lockForUpdate()->findOrFail($internshipId);
            abort_unless($internship->status === 'ACTIVE', 409, 'Only active internship activities can be edited.');
            $activity = $activityId === null ? new ActivityLog(['internship_id' => $internship->id])
                : ActivityLog::where('internship_id', $internship->id)->lockForUpdate()->findOrFail($activityId);
            $activity->fill(Arr::only($data, ['activity_date', 'title', 'description', 'hours']));
            Validator::make([
                'activity_date' => $activity->activity_date?->format('Y-m-d'),
                'title' => $activity->title, 'description' => $activity->description, 'hours' => $activity->hours,
            ], [
                'activity_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$internship->start_date?->format('Y-m-d'),
                    'before_or_equal:'.$internship->end_date?->format('Y-m-d'), 'before_or_equal:'.today(config('app.timezone'))->format('Y-m-d')],
                'title' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:10000'],
                'hours' => ['nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:24'],
            ])->validate();
            if ($activity->hours !== null) {
                $existing = ActivityLog::whereHas('internship', fn ($query) => $query->where('student_id', $user->id))
                    ->where('activity_date', $activity->activity_date->format('Y-m-d'))
                    ->when($activityId !== null, fn ($query) => $query->whereKeyNot($activityId))->sum('hours');
                if ((int) round((float) $existing * 100) + (int) round((float) $activity->hours * 100) > 2400) {
                    throw ValidationException::withMessages(['hours' => ['Recorded work must not exceed 24 hours per day across your internships.']]);
                }
            }
            $activity->save();

            return $activity->setRelation('internship', $internship);
        });
    }
}
