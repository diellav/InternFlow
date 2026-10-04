<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['internship_id', 'activity_date', 'title', 'description', 'hours'];

    protected function casts(): array
    {
        return ['activity_date' => 'date', 'hours' => 'decimal:2'];
    }

    public function internship(): BelongsTo
    {
        return $this->belongsTo(Internship::class);
    }
}
