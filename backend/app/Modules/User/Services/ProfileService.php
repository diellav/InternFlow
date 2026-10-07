<?php

namespace App\Modules\User\Services;

use App\Models\User;
use App\Modules\Auth\Services\AuthService;
use App\Shared\Enums\UserRole;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProfileService
{
    public function __construct(private readonly AuthService $auth) {}

    public function update(User $authenticatedUser, array $data): User
    {
        return DB::transaction(function () use ($authenticatedUser, $data): User {
            $user = User::query()->lockForUpdate()->findOrFail($authenticatedUser->id);

            if (! $user->is_active) {
                throw new HttpException(403, 'Account is inactive.');
            }

            if (array_key_exists('job_title', $data)) {
                $profile = $user->companySupervisorProfile()->lockForUpdate()->first();

                if (! $user->hasRole(UserRole::COMPANY_SUPERVISOR) || $profile === null) {
                    throw ValidationException::withMessages([
                        'job_title' => ['A supervisor profile is required to update the job title.'],
                    ]);
                }

                $profile->job_title = $data['job_title'];
                if ($profile->isDirty()) {
                    $profile->save();
                }
            }

            $user->fill(Arr::only($data, ['first_name', 'last_name', 'phone']));
            if ($user->isDirty()) {
                $user->save();
            }

            return $this->auth->currentUser($user);
        });
    }
}
