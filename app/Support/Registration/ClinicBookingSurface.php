<?php

namespace App\Support\Registration;

use InvalidArgumentException;

final class ClinicBookingSurface
{
    public const OUTPATIENT = 'outpatient';

    public const EMERGENCY = 'emergency';

    public const SUPPORTING = 'supporting';

    /**
     * @var list<string>
     */
    public const VALUES = [
        self::OUTPATIENT,
        self::EMERGENCY,
        self::SUPPORTING,
    ];

    public static function assertKnown(string $value): string
    {
        if (! in_array($value, self::VALUES, true)) {
            throw new InvalidArgumentException('Unknown clinic booking surface.');
        }

        return $value;
    }

    public static function isOutpatient(string $value): bool
    {
        return $value === self::OUTPATIENT;
    }
}
