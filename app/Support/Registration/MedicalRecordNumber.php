<?php

namespace App\Support\Registration;

use InvalidArgumentException;

final readonly class MedicalRecordNumber
{
    public const WIDTH = 6;

    public const MAX = 999_999;

    public function __construct(public string $value)
    {
        if (preg_match('/^[0-9]{6}$/', $value) !== 1) {
            throw new InvalidArgumentException('Medical record number must be exactly 6 digit characters.');
        }
    }

    public static function fromSequence(int $number): self
    {
        if ($number < 1 || $number > self::MAX) {
            throw new InvalidArgumentException('Medical record number sequence is outside the supported range.');
        }

        return new self(sprintf('%06d', $number));
    }

    public function sequence(): int
    {
        return (int) $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
