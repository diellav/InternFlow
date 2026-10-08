<?php

namespace App\Modules\Company\Services;

use App\Models\Company;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyReadService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Company::query();

        if (isset($filters['search']) && $filters['search'] !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';
            $query->where('name', 'ilike', $pattern);
        }

        if (isset($filters['verification_status'])) {
            $query->where('verification_status', $filters['verification_status']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15, [
                'id', 'name', 'industry', 'address', 'email', 'phone', 'website',
                'is_active', 'verification_status', 'created_at',
            ], 'page', $filters['page'] ?? 1)
            ->withQueryString();
    }

    public function details(Company $company): Company
    {
        return $company->load([
            'supervisors' => fn (HasMany $query) => $query->orderBy('user_id')
                ->select(['user_id', 'company_id', 'job_title', 'verification_status']),
            'supervisors.user:id,first_name,last_name,email,phone,is_active',
        ]);
    }
}
