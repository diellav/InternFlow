<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TaskSubmission extends Model
{
    public $timestamps = false;

    protected $fillable = ['task_id', 'version_no', 'submission_text', 'resource_url', 'submitted_at'];

    protected function casts(): array
    {
        return ['version_no' => 'integer', 'submitted_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function feedback(): HasOne
    {
        return $this->hasOne(TaskFeedback::class, 'submission_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(TaskSubmissionFile::class)->orderBy('id');
    }
}
