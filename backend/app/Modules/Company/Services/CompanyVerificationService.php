<?php

namespace App\Modules\Company\Services;

use App\Models\Company;
use App\Models\User;
use App\Shared\Enums\VerificationStatus;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CompanyVerificationService
{
    public function verify(Company $company, User $admin, VerificationStatus $decision, ?string $reason): Company
    {
        return DB::transaction(function () use ($company, $admin, $decision, $reason): Company {
            $current = Company::query()->lockForUpdate()->findOrFail($company->id);

            if ($current->verification_status !== VerificationStatus::PENDING) {
                throw new HttpException(409, 'This company has already been reviewed. The decision is final.');
            }

            $current->verification_status = $decision;
            $current->verified_by = $admin->id;
            $current->verified_at = now();
            $current->verification_note = $decision === VerificationStatus::REJECTED ? $reason : null;
            $current->save();

            return $current;
        });
    }
}
