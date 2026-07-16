<?php

namespace App\Modules\Coding\Enums;

enum TerminologyProvenanceStatus: string
{
    case VerifiedUserSupplied = 'VERIFIED_USER_SUPPLIED';
    case SyntheticFixture = 'SYNTHETIC_FIXTURE';

    public function label(): string
    {
        return match ($this) {
            self::VerifiedUserSupplied => 'Berkas pengguna terverifikasi',
            self::SyntheticFixture => 'Fixture sintetis',
        };
    }
}
