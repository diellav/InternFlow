<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Internship extends Model
{
    protected $fillable = [
        'student_id', 'company_id', 'company_supervisor_id', 'coordinator_id',
        'position_title', 'description', 'start_date', 'end_date', 'status',
        'submitted_at', 'approved_at', 'decision_comment', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date', 'end_date' => 'date', 'submitted_at' => 'datetime',
            'approved_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_id', 'user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companySupervisor(): BelongsTo
    {
        return $this->belongsTo(CompanySupervisorProfile::class, 'company_supervisor_id', 'user_id');
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(AcademicCoordinatorProfile::class, 'coordinator_id', 'user_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function finalEvaluation(): HasOne
    {
        return $this->hasOne(FinalEvaluation::class);
    }
}
