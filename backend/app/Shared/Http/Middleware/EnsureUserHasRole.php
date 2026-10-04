<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Enums\UserRole;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $allowedRoles = array_map(
            static fn (string $role): UserRole => UserRole::tryFrom($role)
                ?? throw new InvalidArgumentException("Unsupported user role [{$role}]."),
            $roles,
        );

        if (! $user->hasRole(...$allowedRoles)) {
            return new JsonResponse(['message' => 'This action is forbidden.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
