<?php

namespace App\Modules\Internship\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Internship\Requests\ListStudentInternshipsRequest;
use App\Modules\Internship\Requests\SaveInternshipDraftRequest;
use App\Modules\Internship\Requests\SubmitInternshipRequest;
use App\Modules\Internship\Resources\StudentInternshipResource;
use App\Modules\Internship\Services\StudentInternshipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StudentInternshipController extends Controller
{
    public function __construct(private readonly StudentInternshipService $internships) {}

    public function index(ListStudentInternshipsRequest $request): AnonymousResourceCollection
    {
        return StudentInternshipResource::collection($this->internships->paginate($request->user(), $request->validated()));
    }

    public function store(SaveInternshipDraftRequest $request): StudentInternshipResource
    {
        return new StudentInternshipResource($this->internships->save($request->user(), $request->validated()));
    }

    public function show(Request $request, int $internship): StudentInternshipResource
    {
        return new StudentInternshipResource($this->internships->details($request->user(), $internship));
    }

    public function update(SaveInternshipDraftRequest $request, int $internship): StudentInternshipResource
    {
        return new StudentInternshipResource($this->internships->save($request->user(), $request->validated(), $internship));
    }

    public function companies(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->internships->companies($request->user())]);
    }

    public function submit(SubmitInternshipRequest $request, int $internship): StudentInternshipResource
    {
        return new StudentInternshipResource($this->internships->submit($request->user(), $internship));
    }

    public function resubmit(SubmitInternshipRequest $request, int $internship): StudentInternshipResource
    {
        return new StudentInternshipResource($this->internships->resubmit($request->user(), $internship));
    }

    public function supervisors(Request $request, int $company): JsonResponse
    {
        return response()->json(['data' => $this->internships->supervisors($request->user(), $company)]);
    }
}
