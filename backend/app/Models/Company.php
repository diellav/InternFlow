<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'name', 'industry', 'address', 'email', 'phone', 'website', 'is_active',
        'verification_status', 'verified_by', 'verified_at', 'verification_note',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'verified_at' => 'datetime'];
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function supervisors(): HasMany
    {
        return $this->hasMany(CompanySupervisorProfile::class);
    }

    public function internships(): HasMany
    {
        return $this->hasMany(Internship::class);
    }
}
