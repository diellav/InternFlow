<?php

namespace App\Modules\Auth\Enums;

enum CompanyRegistrationMode: string
{
    case EXISTING = 'existing';
    case NEW = 'new';
}
