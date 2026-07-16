<?php

namespace App\Modules\Encounter\Enums;

enum QueueEventStatus: string
{
    case Waiting = 'WAITING';
    case Called = 'CALLED';
    case InService = 'IN_SERVICE';
    case Held = 'HELD';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Menunggu',
            self::Called => 'Dipanggil',
            self::InService => 'Dalam pelayanan simulasi',
            self::Held => 'Ditahan',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
