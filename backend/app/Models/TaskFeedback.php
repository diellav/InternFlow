<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskFeedback extends Model
{
    protected $table = 'task_feedback';

    public const UPDATED_AT = null;

    protected $fillable = ['submission_id', 'supervisor_id', 'decision', 'comment'];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(TaskSubmission::class, 'submission_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(CompanySupervisorProfile::class, 'supervisor_id', 'user_id');
    }
}
