<?php

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->is_active) {
            return new JsonResponse(['message' => 'Account is inactive.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
