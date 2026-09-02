<?php

namespace App\Support\Clinical;

use RuntimeException;

final class CancelledClinicalEntryDenied extends RuntimeException
{
    public function __construct(
        public readonly string $encounterPublicId,
        public readonly string $careSetting,
    ) {
        parent::__construct('Kunjungan tidak dapat menerima catatan klinis.');
    }
}
