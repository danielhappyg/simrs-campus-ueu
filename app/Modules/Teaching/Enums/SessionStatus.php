<?php

namespace App\Modules\Teaching\Enums;

enum SessionStatus: string
{
    case Scheduled = 'SCHEDULED';
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    public function permitsLearnerWork(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Terjadwal',
            self::Active => 'Aktif',
            self::Paused => 'Dijeda',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
