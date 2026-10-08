<?php

namespace App\Modules\Company\Services;

use App\Models\CompanySupervisorProfile;
use App\Models\User;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupervisorVerificationService
{
    public function verify(User $user, User $admin, VerificationStatus $decision, ?string $reason): User
    {
        return DB::transaction(function () use ($user, $admin, $decision, $reason): User {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($current->hasRole(UserRole::COMPANY_SUPERVISOR), 404);
            $profile = CompanySupervisorProfile::query()->where('user_id', $current->id)->lockForUpdate()->first();

            if ($profile === null) {
                throw new HttpException(409, 'The supervisor profile is missing and cannot be reviewed.');
            }
            if ($profile->verification_status !== VerificationStatus::PENDING) {
                throw new HttpException(409, 'This supervisor has already been reviewed. The decision is final.');
            }

            $profile->verification_status = $decision;
            $profile->reviewed_by = $admin->id;
            $profile->reviewed_at = now();
            $profile->review_comment = $decision === VerificationStatus::REJECTED ? $reason : null;
            $profile->save();

            return $current;
        });
    }
}
