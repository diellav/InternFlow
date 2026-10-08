<?php

namespace App\Modules\Company\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Requests\ResubmitVerificationApplicationRequest;
use App\Modules\Company\Requests\UpdateVerificationApplicationRequest;
use App\Modules\Company\Services\VerificationApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationApplicationController extends Controller
{
    public function __construct(private readonly VerificationApplicationService $applications) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->applications->show($request->user())]);
    }

    public function update(UpdateVerificationApplicationRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->applications->update($request->user(), $request->validated())]);
    }

    public function resubmit(ResubmitVerificationApplicationRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->applications->resubmit($request->user(), $request->validated('targets'))]);
    }
}
