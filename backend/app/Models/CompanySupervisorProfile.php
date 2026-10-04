<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanySupervisorProfile extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'user_id';

    protected $keyType = 'int';

    protected $fillable = [
        'user_id', 'company_id', 'job_title', 'verification_status',
        'reviewed_by', 'reviewed_at', 'review_comment',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function internships(): HasMany
    {
        return $this->hasMany(Internship::class, 'company_supervisor_id', 'user_id');
    }

    public function finalEvaluations(): HasMany
    {
        return $this->hasMany(FinalEvaluation::class, 'evaluator_id', 'user_id');
    }
}
