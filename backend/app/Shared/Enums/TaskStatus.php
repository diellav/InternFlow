<?php

namespace App\Shared\Enums;

enum TaskStatus: string
{
    case ASSIGNED = 'ASSIGNED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case SUBMITTED = 'SUBMITTED';
    case REVISION_REQUIRED = 'REVISION_REQUIRED';
    case APPROVED = 'APPROVED';
    case CANCELLED = 'CANCELLED';
}
