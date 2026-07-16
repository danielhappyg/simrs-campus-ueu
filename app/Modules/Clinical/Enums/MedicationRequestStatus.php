<?php

namespace App\Modules\Clinical\Enums;

enum MedicationRequestStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case OnHold = 'ON_HOLD';
    case Accepted = 'ACCEPTED';
    case CancellationRecommended = 'CANCELLATION_RECOMMENDED';
    case Completed = 'COMPLETED';
    case Partial = 'PARTIAL';
    case NotDispensed = 'NOT_DISPENSED';
    case Cancelled = 'CANCELLED';
}
