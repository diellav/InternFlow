<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinalEvaluation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'internship_id', 'evaluator_id', 'technical_skills', 'communication',
        'teamwork', 'responsibility', 'overall_score', 'comments', 'submitted_at',
    ];

    protected function casts(): array
    {
        return ['overall_score' => 'decimal:2', 'submitted_at' => 'datetime'];
    }

    public function internship(): BelongsTo
    {
        return $this->belongsTo(Internship::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(CompanySupervisorProfile::class, 'evaluator_id', 'user_id');
    }
}
