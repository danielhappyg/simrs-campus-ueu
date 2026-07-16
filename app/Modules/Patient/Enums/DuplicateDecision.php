<?php

namespace App\Modules\Patient\Enums;

enum DuplicateDecision: string
{
    case NoCandidate = 'NO_CANDIDATE';
    case UseExisting = 'USE_EXISTING';
    case CreateNew = 'CREATE_NEW';
}
