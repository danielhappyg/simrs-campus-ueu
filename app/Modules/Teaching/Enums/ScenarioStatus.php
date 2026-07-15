<?php

namespace App\Modules\Teaching\Enums;

enum ScenarioStatus: string
{
    case Draft = 'DRAFT';
    case Published = 'PUBLISHED';
    case Retired = 'RETIRED';
}
