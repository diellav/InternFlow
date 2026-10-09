<?php

namespace App\Modules\Internship\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Internship\Requests\ActivateInternshipRequest;
use App\Modules\Internship\Requests\ListCoordinatorInternshipsRequest;
use App\Modules\Internship\Resources\SupervisorInternshipResource;
use App\Modules\Internship\Services\SupervisorInternshipService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupervisorInternshipController extends Controller
{
    public function __construct(private readonly SupervisorInternshipService $internships) {}

    public function index(ListCoordinatorInternshipsRequest $request): AnonymousResourceCollection
    {
        return SupervisorInternshipResource::collection($this->internships->paginate($request->user(), $request->validated()));
    }

    public function show(Request $request, int $internship): SupervisorInternshipResource
    {
        return new SupervisorInternshipResource($this->internships->details($request->user(), $internship));
    }

    public function activate(ActivateInternshipRequest $request, int $internship): SupervisorInternshipResource
    {
        return new SupervisorInternshipResource($this->internships->activate($request->user(), $internship));
    }
}
