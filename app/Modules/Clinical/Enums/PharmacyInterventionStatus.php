<?php

namespace App\Modules\Clinical\Enums;

enum PharmacyInterventionStatus: string
{
    case Open = 'OPEN';
    case Responded = 'RESPONDED';
    case Resolved = 'RESOLVED';
}
