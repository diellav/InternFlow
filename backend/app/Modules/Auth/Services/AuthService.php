<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Shared\Enums\UserRole;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthService
{
    public function login(string $email, string $password, Request $request): User
    {
        $guard = Auth::guard('web');
        $provider = $guard->getProvider();
        $credentials = ['email' => $email, 'password' => $password];
        $user = $provider->retrieveByCredentials($credentials);

        if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new HttpException(403, 'Account is inactive.');
        }

        $guard->login($user);
        $request->session()->regenerate();

        return $this->loadAuthContext($user);
    }

    public function currentUser(Authenticatable $authenticatedUser): User
    {
        if (! $authenticatedUser instanceof User) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        return $this->loadAuthContext($authenticatedUser);
    }

    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();
        Auth::guard('sanctum')->forgetUser();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function loadAuthContext(User $user): User
    {
        $user->load('role');

        match (UserRole::tryFrom((string) $user->role?->name)) {
            UserRole::STUDENT => $user->load('studentProfile'),
            UserRole::COMPANY_SUPERVISOR => $user->load('companySupervisorProfile.company'),
            UserRole::ACADEMIC_COORDINATOR => $user->load('academicCoordinatorProfile'),
            default => null,
        };

        return $user;
    }
}
