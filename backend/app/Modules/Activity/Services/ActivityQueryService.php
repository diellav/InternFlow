<?php

namespace App\Modules\Activity\Services;

use App\Models\ActivityLog;
use App\Models\Internship;
use Illuminate\Database\Eloquent\Builder;

class ActivityQueryService
{
    private function filter(Builder $query, array $filters): Builder
    {
        if (isset($filters['date_from'])) {
            $query->where('activity_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->where('activity_date', '<=', $filters['date_to']);
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';
            $query->where(fn (Builder $scope) => $scope->where('title', 'ilike', $search)->orWhere('description', 'ilike', $search));
        }

        return $query;
    }

    private function hours(Builder $query): string
    {
        $sum = (string) $query->selectRaw('COALESCE(SUM(hours), 0)::text AS recorded_hours')->value('recorded_hours');
        [$whole, $fraction] = array_pad(explode('.', $sum, 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }

    public function listing(Internship $internship, array $filters): array
    {
        $base = ActivityLog::where('internship_id', $internship->id);
        $filtered = $this->filter(clone $base, $filters);
        $total = $this->hours(clone $base);
        $hasFilters = isset($filters['date_from']) || isset($filters['date_to']) || (isset($filters['search']) && $filters['search'] !== '');

        return [
            'internship' => $internship,
            'total_recorded_hours' => $total,
            'filtered_recorded_hours' => $hasFilters ? $this->hours(clone $filtered) : $total,
            'activities' => $filtered->with('internship')->orderByDesc('activity_date')->orderByDesc('id')
                ->paginate($filters['per_page'] ?? 15, ['*'], 'page', $filters['page'] ?? 1)->withQueryString(),
        ];
    }
}
