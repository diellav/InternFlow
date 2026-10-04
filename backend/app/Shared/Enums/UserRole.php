<?php

namespace App\Shared\Enums;

enum UserRole: string
{
    case STUDENT = 'STUDENT';
    case COMPANY_SUPERVISOR = 'COMPANY_SUPERVISOR';
    case ACADEMIC_COORDINATOR = 'ACADEMIC_COORDINATOR';
    case ADMIN = 'ADMIN';
}
