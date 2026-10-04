<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentProfile extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'user_id';

    protected $keyType = 'int';

    protected $fillable = ['user_id', 'student_number', 'study_program', 'study_year'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function internships(): HasMany
    {
        return $this->hasMany(Internship::class, 'student_id', 'user_id');
    }
}
