<?php

namespace App\Modules\Teaching\Enums;

enum WorkTaskStatus: string
{
    case Ready = 'READY';
    case Waiting = 'WAITING';
    case Blocked = 'BLOCKED';
    case InProgress = 'IN_PROGRESS';
    case Submitted = 'SUBMITTED';
    case ChangesRequested = 'CHANGES_REQUESTED';
    case Complete = 'COMPLETE';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Siap',
            self::Waiting => 'Menunggu',
            self::Blocked => 'Diblokir',
            self::InProgress => 'Sedang dikerjakan',
            self::Submitted => 'Diajukan',
            self::ChangesRequested => 'Perlu perbaikan',
            self::Complete => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
