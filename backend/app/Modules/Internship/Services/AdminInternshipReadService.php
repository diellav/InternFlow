<?php

namespace App\Modules\Internship\Services;

use App\Models\Internship;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class AdminInternshipReadService
{
    private function query(): Builder
    {
        return Internship::select(['id', 'student_id', 'company_id', 'coordinator_id', 'company_supervisor_id',
            'position_title', 'status', 'created_at', 'submitted_at', 'start_date', 'end_date', 'approved_at', 'completed_at'])
            ->with(['student:user_id', 'student.user:id,first_name,last_name', 'company:id,name',
                'coordinator:user_id', 'coordinator.user:id,first_name,last_name',
                'companySupervisor:user_id', 'companySupervisor.user:id,first_name,last_name']);
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->query();
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';
            $query->where(fn (Builder $query) => $query
                ->whereHas('student.user', fn (Builder $users) => $users->whereRaw("(first_name || ' ' || last_name) ILIKE ?", [$pattern]))
                ->orWhereHas('company', fn (Builder $companies) => $companies->where('name', 'ilike', $pattern)));
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15, ['*'], 'page', $filters['page'] ?? 1)->withQueryString();
    }

    public function details(int $id): Internship
    {
        return $this->query()->addSelect('description')->findOrFail($id);
    }

    public function statistics(): array
    {
        $counts = Internship::query()->selectRaw("COUNT(*) AS total, COUNT(*) FILTER (WHERE status IN ('SUBMITTED', 'UNDER_REVIEW')) AS pending_review, COUNT(*) FILTER (WHERE status = 'ACTIVE') AS active, COUNT(*) FILTER (WHERE status = 'COMPLETED') AS completed")->first();

        return array_map('intval', $counts->only(['total', 'pending_review', 'active', 'completed']));
    }
}
