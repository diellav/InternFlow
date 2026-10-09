<?php

namespace App\Modules\Activity\Services;

use App\Models\ActivityLog;
use App\Models\CompanySupervisorProfile;
use App\Models\Internship;
use App\Models\User;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Builder;

class SupervisorActivityService
{
    public function __construct(private readonly ActivityQueryService $queries) {}

    private function internships(User $actor): Builder
    {
        $profile = CompanySupervisorProfile::with(['company', 'user.role'])->find($actor->id);
        abort_unless($profile !== null && $profile->user?->is_active && $profile->user->hasRole(UserRole::COMPANY_SUPERVISOR)
            && $profile->verification_status === VerificationStatus::APPROVED && $profile->company?->is_active
            && $profile->company->verification_status === VerificationStatus::APPROVED, 403);

        return Internship::where('company_supervisor_id', $profile->getKey())->where('company_id', $profile->company_id)->where('status', 'ACTIVE');
    }

    public function parent(User $user, int $id): Internship
    {
        return $this->internships($user)->findOrFail($id);
    }

    public function details(User $user, int $id): ActivityLog
    {
        $parents = $this->internships($user)->select('id');

        return ActivityLog::whereIn('internship_id', $parents)->with('internship')->findOrFail($id);
    }

    public function listing(User $user, int $id, array $filters): array
    {
        $parent = $this->parent($user, $id);

        return $this->queries->listing($parent, $filters);
    }
}
