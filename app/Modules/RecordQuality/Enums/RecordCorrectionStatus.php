<?php

namespace App\Modules\RecordQuality\Enums;

enum RecordCorrectionStatus: string
{
    case Open = 'OPEN';
    case ResponseSubmitted = 'RESPONSE_SUBMITTED';
    case CorrectedPendingVerification = 'CORRECTED_PENDING_VERIFICATION';
    case Resolved = 'RESOLVED';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Menunggu koreksi',
            self::ResponseSubmitted => 'Koreksi diajukan',
            self::CorrectedPendingVerification => 'Menunggu verifikasi RMIK',
            self::Resolved => 'Selesai',
        };
    }
}
