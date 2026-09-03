<?php

namespace Tests\Unit\Registration;

use App\Support\Registration\MedicalRecordNumber;
use InvalidArgumentException;
use Tests\TestCase;

class MedicalRecordNumberTest extends TestCase
{
    public function test_from_sequence_keeps_leading_zeros_as_characters(): void
    {
        $mrn = MedicalRecordNumber::fromSequence(212);

        $this->assertSame('000212', $mrn->value);
        $this->assertSame('000212', (string) $mrn);
        $this->assertIsString($mrn->value);
        $this->assertNotSame(212, $mrn->value);
        $this->assertSame('"000212"', json_encode($mrn->value));
    }

    public function test_constructor_accepts_padded_digit_string(): void
    {
        $mrn = new MedicalRecordNumber('000212');

        $this->assertSame('000212', $mrn->value);
        $this->assertSame(212, $mrn->sequence());
    }

    public function test_numeric_looking_integer_cast_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly 6 digit characters');

        new MedicalRecordNumber((string) 212);
    }

    public function test_constructor_rejects_non_digit_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MedicalRecordNumber('RM-0001');
    }

    public function test_from_sequence_rejects_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MedicalRecordNumber::fromSequence(0);
    }

    public function test_from_sequence_rejects_overflow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MedicalRecordNumber::fromSequence(MedicalRecordNumber::MAX + 1);
    }
}
