<?php

namespace App\Modules\Teaching\Enums;

enum ApplicationRole: string
{
    case Learner = 'LEARNER';
    case Supervisor = 'SUPERVISOR';
    case Facilitator = 'FACILITATOR';
    case Registrar = 'REGISTRAR';
    case Coder = 'CODER';
    case Pharmacist = 'PHARMACIST';
    case SystemAdministrator = 'SYSTEM_ADMINISTRATOR';

    public function label(): string
    {
        return match ($this) {
            self::Learner => 'Mahasiswa',
            self::Supervisor => 'Supervisor',
            self::Facilitator => 'Fasilitator',
            self::Registrar => 'Petugas Registrasi Simulasi',
            self::Coder => 'Koder RMIK',
            self::Pharmacist => 'Farmasis Simulasi',
            self::SystemAdministrator => 'Administrator Sistem',
        };
    }
}
