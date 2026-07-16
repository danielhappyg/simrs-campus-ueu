<?php

namespace App\Modules\Clinical\Enums;

enum ObservationStatus: string
{
    case Preliminary = 'PRELIMINARY';
    case Final = 'FINAL';
    case Amended = 'AMENDED';
    case EnteredInError = 'ENTERED_IN_ERROR';
}
