<?php

namespace App\Modules\Internship\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Internship\Requests\ClaimInternshipRequest;
use App\Modules\Internship\Requests\InternshipDecisionRequest;
use App\Modules\Internship\Requests\ListCoordinatorInternshipsRequest;
use App\Modules\Internship\Requests\StartInternshipReviewRequest;
use App\Modules\Internship\Resources\CoordinatorInternshipResource;
use App\Modules\Internship\Services\CoordinatorInternshipService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CoordinatorInternshipController extends Controller
{
    public function __construct(private readonly CoordinatorInternshipService $internships) {}

    public function index(ListCoordinatorInternshipsRequest $request): AnonymousResourceCollection
    {
        return CoordinatorInternshipResource::collection($this->internships->paginate($request->user(), $request->validated()));
    }

    public function show(Request $request, int $internship): CoordinatorInternshipResource
    {
        return new CoordinatorInternshipResource($this->internships->details($request->user(), $internship));
    }

    public function claim(ClaimInternshipRequest $request, int $internship): CoordinatorInternshipResource
    {
        return new CoordinatorInternshipResource($this->internships->claim($request->user(), $internship));
    }

    public function startReview(StartInternshipReviewRequest $request, int $internship): CoordinatorInternshipResource
    {
        return new CoordinatorInternshipResource($this->internships->startReview($request->user(), $internship));
    }

    public function decision(InternshipDecisionRequest $request, int $internship): CoordinatorInternshipResource
    {
        return new CoordinatorInternshipResource($this->internships->decide($request->user(), $internship, $request->validated()));
    }
}
