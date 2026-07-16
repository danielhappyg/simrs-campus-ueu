<?php

namespace App\Modules\Clinical\Enums;

enum PharmacyResponseAction: string
{
    case Explanation = 'EXPLANATION';
    case Replace = 'REPLACE';
    case Cancel = 'CANCEL';

    public function label(): string
    {
        return match ($this) {
            self::Explanation => 'Berikan penjelasan',
            self::Replace => 'Ganti permintaan obat',
            self::Cancel => 'Batalkan permintaan obat',
        };
    }
}
