<?php

namespace App\Modules\User\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Resources\AuthUserResource;
use App\Modules\Auth\Services\AuthService;
use App\Modules\User\Requests\UpdateProfileRequest;
use App\Modules\User\Services\ProfileService;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(private readonly AuthService $auth, private readonly ProfileService $profiles) {}

    public function show(Request $request): AuthUserResource
    {
        return new AuthUserResource($this->auth->currentUser($request->user()));
    }

    public function update(UpdateProfileRequest $request): AuthUserResource
    {
        return new AuthUserResource($this->profiles->update($request->user(), $request->validated()));
    }
}
