<?php

namespace App\Modules\RecordQuality\Enums;

enum RecordQualityFindingSeverity: string
{
    case Blocking = 'BLOCKING';
    case NonBlocking = 'NON_BLOCKING';
    case Informational = 'INFORMATIONAL';

    public function label(): string
    {
        return match ($this) {
            self::Blocking => 'Memblokir',
            self::NonBlocking => 'Tidak memblokir',
            self::Informational => 'Informasional',
        };
    }
}
