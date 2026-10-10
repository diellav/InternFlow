<?php

namespace App\Modules\Internship\Services;

use App\Models\AcademicCoordinatorProfile;
use App\Models\Internship;
use App\Models\User;
use App\Modules\Notification\Services\NotificationDispatchService;
use App\Shared\Enums\InternshipStatus;
use App\Shared\Enums\UserRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CoordinatorInternshipService
{
    public function __construct(private readonly InternshipEligibilityService $eligibility) {}

    private array $relations = ['student.user:id,first_name,last_name', 'company:id,name,industry,is_active,verification_status', 'companySupervisor.user:id,first_name,last_name', 'coordinator.user:id,first_name,last_name'];

    private function profile(User $user): void
    {
        abort_unless(AcademicCoordinatorProfile::query()->whereKey($user->id)->exists(), 403, 'A coordinator profile is required.');
    }

    private function visible(User $user): Builder
    {
        $this->profile($user);

        return Internship::query()->where('status', '!=', 'DRAFT')->where(function (Builder $query) use ($user): void {
            $query->where('coordinator_id', $user->id)->orWhere(fn (Builder $shared) => $shared->whereNull('coordinator_id')->where('status', 'SUBMITTED'));
        })->with($this->relations);
    }

    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->visible($user);
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->where('position_title', 'ilike', $search)
                    ->orWhereHas('company', fn (Builder $company) => $company->where('name', 'ilike', $search))
                    ->orWhereHas('student.user', fn (Builder $student) => $student->whereRaw("concat_ws(' ', first_name, last_name) ILIKE ?", [$search]));
            });
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 15, ['*'], 'page', $filters['page'] ?? 1)->withQueryString();
    }

    public function details(User $user, int $id): Internship
    {
        return $this->visible($user)->findOrFail($id);
    }

    public function claim(User $actor, int $id): Internship
    {
        return DB::transaction(function () use ($actor, $id): Internship {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($user->is_active && $user->hasRole(UserRole::ACADEMIC_COORDINATOR), 403);
            abort_unless(AcademicCoordinatorProfile::query()->sharedLock()->find($user->id) !== null, 403);
            $internship = Internship::query()->where('status', '!=', 'DRAFT')->lockForUpdate()->findOrFail($id);
            abort_unless($internship->status === 'SUBMITTED' && $internship->coordinator_id === null, 409, 'This application is no longer available to claim.');
            $internship->forceFill(['coordinator_id' => $user->id])->save();

            return $internship->load($this->relations);
        });
    }

    public function assigned(User $user, int $id): Internship
    {
        $this->profile($user);

        return Internship::query()->where('coordinator_id', $user->id)->findOrFail($id);
    }

    private function lockedAssigned(User $actor, int $id): Internship
    {
        $user = User::query()->lockForUpdate()->findOrFail($actor->id);
        abort_unless($user->is_active && $user->hasRole(UserRole::ACADEMIC_COORDINATOR), 403);
        abort_unless(AcademicCoordinatorProfile::query()->sharedLock()->find($user->id) !== null, 403);

        return Internship::query()->where('coordinator_id', $user->id)->lockForUpdate()->findOrFail($id);
    }

    public function startReview(User $actor, int $id): Internship
    {
        return DB::transaction(function () use ($actor, $id): Internship {
            $internship = $this->lockedAssigned($actor, $id);
            abort_unless($internship->status === InternshipStatus::SUBMITTED->value, 409, 'Only an assigned submitted application can enter review.');
            $internship->forceFill(['status' => InternshipStatus::UNDER_REVIEW->value])->save();

            return $internship->load($this->relations);
        });
    }

    public function decide(User $actor, int $id, array $data): Internship
    {
        return DB::transaction(function () use ($actor, $id, $data): Internship {
            $internship = $this->lockedAssigned($actor, $id);
            abort_unless($internship->status === InternshipStatus::UNDER_REVIEW->value, 409, 'Only an application under review can receive a decision.');
            $decision = $data['decision'] ?? null;
            $comment = is_string($data['decision_comment'] ?? null) ? trim($data['decision_comment']) : ($data['decision_comment'] ?? null);
            Validator::make(['decision' => $decision, 'decision_comment' => $comment], [
                'decision' => ['required', Rule::in([InternshipStatus::APPROVED->value, InternshipStatus::REJECTED->value, InternshipStatus::REVISION_REQUIRED->value])],
                'decision_comment' => $decision === InternshipStatus::APPROVED->value ? ['prohibited'] : ['required', 'string', 'max:10000'],
            ])->validate();
            if ($decision === InternshipStatus::APPROVED->value) {
                $this->eligibility->validateCompleted($internship);
            }
            $internship->forceFill([
                'status' => $decision,
                'approved_at' => $decision === InternshipStatus::APPROVED->value ? now() : null,
                'decision_comment' => $decision === InternshipStatus::APPROVED->value ? null : $comment,
            ])->save();
            app(NotificationDispatchService::class)->applicationDecided($internship);

            return $internship->load($this->relations);
        });
    }
}
