<?php

namespace App\Modules\Clinical\Enums;

enum ServiceRequestStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
}
