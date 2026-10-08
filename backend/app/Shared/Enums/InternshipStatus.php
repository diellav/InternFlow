<?php

namespace App\Shared\Enums;

enum InternshipStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case REVISION_REQUIRED = 'REVISION_REQUIRED';
    case APPROVED = 'APPROVED';
    case ACTIVE = 'ACTIVE';
    case COMPLETED = 'COMPLETED';
    case REJECTED = 'REJECTED';
}
