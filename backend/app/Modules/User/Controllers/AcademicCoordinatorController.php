<?php

namespace App\Modules\User\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\User\Requests\CreateCoordinatorRequest;
use App\Modules\User\Requests\UpdateCoordinatorRequest;
use App\Modules\User\Resources\UserDetailResource;
use App\Modules\User\Services\CoordinatorService;
use Illuminate\Http\JsonResponse;

class AcademicCoordinatorController extends Controller
{
    public function __construct(private readonly CoordinatorService $coordinators) {}

    public function store(CreateCoordinatorRequest $request): JsonResponse
    {
        return (new UserDetailResource($this->coordinators->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(UpdateCoordinatorRequest $request, User $user): UserDetailResource
    {
        return new UserDetailResource($this->coordinators->update($user, $request->validated()));
    }
}
