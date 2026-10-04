<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Resources\AuthUserResource;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function login(LoginRequest $request): AuthUserResource
    {
        $user = $this->authService->login(
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            request: $request,
        );

        return new AuthUserResource($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request);

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): AuthUserResource
    {
        return new AuthUserResource($this->authService->currentUser($request->user()));
    }
}
