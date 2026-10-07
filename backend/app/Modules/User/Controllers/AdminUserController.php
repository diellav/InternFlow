<?php

namespace App\Modules\User\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\User\Requests\ListUsersRequest;
use App\Modules\User\Requests\UpdateUserActivationRequest;
use App\Modules\User\Resources\UserDetailResource;
use App\Modules\User\Resources\UserResource;
use App\Modules\User\Services\UserActivationService;
use App\Modules\User\Services\UserReadService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminUserController extends Controller
{
    public function __construct(private readonly UserReadService $users) {}

    public function index(ListUsersRequest $request): AnonymousResourceCollection
    {
        return UserResource::collection($this->users->paginate($request->validated()));
    }

    public function show(User $user): UserDetailResource
    {
        return new UserDetailResource($this->users->details($user));
    }

    public function updateActivation(
        UpdateUserActivationRequest $request,
        User $user,
        UserActivationService $activation,
    ): UserResource {
        return new UserResource($activation->update($request->user(), $user, $request->validated('is_active')));
    }
}
