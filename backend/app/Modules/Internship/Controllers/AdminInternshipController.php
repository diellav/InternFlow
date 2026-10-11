<?php

namespace App\Modules\Internship\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Internship\Requests\ListAdminInternshipsRequest;
use App\Modules\Internship\Resources\AdminInternshipResource;
use App\Modules\Internship\Services\AdminInternshipReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminInternshipController extends Controller
{
    public function __construct(private readonly AdminInternshipReadService $internships) {}

    public function index(ListAdminInternshipsRequest $request): AnonymousResourceCollection
    {
        return AdminInternshipResource::collection($this->internships->paginate($request->validated()));
    }

    public function show(int $internship): AdminInternshipResource
    {
        return new AdminInternshipResource($this->internships->details($internship));
    }

    public function statistics(): JsonResponse
    {
        return response()->json(['data' => $this->internships->statistics()]);
    }
}
