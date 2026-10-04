<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\DTOs\StudentRegistrationDTO;
use App\Modules\Auth\DTOs\SupervisorRegistrationDTO;
use App\Modules\Auth\Requests\StudentRegistrationRequest;
use App\Modules\Auth\Requests\SupervisorRegistrationRequest;
use App\Modules\Auth\Resources\AuthUserResource;
use App\Modules\Auth\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RegistrationController extends Controller
{
    public function __construct(private readonly RegistrationService $registrationService) {}

    public function student(StudentRegistrationRequest $request): JsonResponse
    {
        $user = $this->registrationService->registerStudent(
            StudentRegistrationDTO::fromValidated($request->validated()),
        );

        return (new AuthUserResource($user))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function supervisor(SupervisorRegistrationRequest $request): JsonResponse
    {
        $user = $this->registrationService->registerSupervisor(
            SupervisorRegistrationDTO::fromValidated($request->validated()),
        );

        return (new AuthUserResource($user))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
