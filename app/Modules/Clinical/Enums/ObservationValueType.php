<?php

namespace App\Modules\Clinical\Enums;

enum ObservationValueType: string
{
    case Quantity = 'QUANTITY';
    case Text = 'TEXT';
    case Coded = 'CODED';
    case Boolean = 'BOOLEAN';
}
