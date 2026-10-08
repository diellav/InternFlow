<?php

namespace App\Modules\Company\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Company\Requests\ListSupervisorsRequest;
use App\Modules\Company\Requests\VerifySupervisorRequest;
use App\Modules\Company\Resources\SupervisorDetailResource;
use App\Modules\Company\Resources\SupervisorResource;
use App\Modules\Company\Services\SupervisorReadService;
use App\Modules\Company\Services\SupervisorVerificationService;
use App\Shared\Enums\VerificationStatus;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminSupervisorController extends Controller
{
    public function __construct(private readonly SupervisorReadService $supervisors) {}

    public function index(ListSupervisorsRequest $request): AnonymousResourceCollection
    {
        return SupervisorResource::collection($this->supervisors->paginate($request->validated()));
    }

    public function show(User $user): SupervisorDetailResource
    {
        return new SupervisorDetailResource($this->supervisors->details($user));
    }

    public function verify(VerifySupervisorRequest $request, User $user, SupervisorVerificationService $verification): SupervisorDetailResource
    {
        $updated = $verification->verify($user, $request->user(), VerificationStatus::from($request->validated('decision')), $request->validated('reason'));

        return new SupervisorDetailResource($this->supervisors->details($updated));
    }
}
