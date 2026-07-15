<?php

namespace App\Modules\Teaching\Enums;

enum EnvironmentMode: string
{
    case Simulation = 'SIMULATION';
    case Sandbox = 'SANDBOX';

    public function label(): string
    {
        return match ($this) {
            self::Simulation => 'Simulasi',
            self::Sandbox => 'Sandbox integrasi',
        };
    }
}
