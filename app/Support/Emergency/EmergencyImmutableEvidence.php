<?php

namespace App\Support\Emergency;

trait EmergencyImmutableEvidence
{
    public static function bootEmergencyImmutableEvidence(): void
    {
        static::creating(static fn () => EmergencyMutationScope::assertActive());
        static::updating(static function (): never {
            throw new \LogicException('Emergency evidence is append-only.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Emergency evidence is append-only.');
        });
    }
}
