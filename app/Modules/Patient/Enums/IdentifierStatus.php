<?php

namespace App\Modules\Patient\Enums;

enum IdentifierStatus: string
{
    case Active = 'ACTIVE';
    case Replaced = 'REPLACED';
    case EnteredInError = 'ENTERED_IN_ERROR';
}
