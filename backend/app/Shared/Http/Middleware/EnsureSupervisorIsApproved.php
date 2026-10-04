<?php

namespace App\Shared\Http\Middleware;

use App\Models\User;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSupervisorIsApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return new JsonResponse(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $user->loadMissing(['role', 'companySupervisorProfile.company']);

        $profile = $user->companySupervisorProfile;
        $company = $profile?->company;

        if (
            ! $user->hasRole(UserRole::COMPANY_SUPERVISOR)
            || $profile === null
            || $profile->verification_status !== VerificationStatus::APPROVED
            || $company === null
            || $company->verification_status !== VerificationStatus::APPROVED
            || ! $company->is_active
        ) {
            return new JsonResponse(
                ['message' => 'This account is not authorized for supervisor operations.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
