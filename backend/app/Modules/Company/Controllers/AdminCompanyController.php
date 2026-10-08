<?php

namespace App\Modules\Company\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Company\Requests\ListCompaniesRequest;
use App\Modules\Company\Requests\VerifyCompanyRequest;
use App\Modules\Company\Resources\CompanyDetailResource;
use App\Modules\Company\Resources\CompanyResource;
use App\Modules\Company\Services\CompanyReadService;
use App\Modules\Company\Services\CompanyVerificationService;
use App\Shared\Enums\VerificationStatus;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminCompanyController extends Controller
{
    public function __construct(private readonly CompanyReadService $companies) {}

    public function index(ListCompaniesRequest $request): AnonymousResourceCollection
    {
        return CompanyResource::collection($this->companies->paginate($request->validated()));
    }

    public function show(Company $company): CompanyDetailResource
    {
        return new CompanyDetailResource($this->companies->details($company));
    }

    public function verify(VerifyCompanyRequest $request, Company $company, CompanyVerificationService $verification): CompanyDetailResource
    {
        $updated = $verification->verify(
            $company,
            $request->user(),
            VerificationStatus::from($request->validated('decision')),
            $request->validated('reason'),
        );

        return new CompanyDetailResource($this->companies->details($updated));
    }
}
