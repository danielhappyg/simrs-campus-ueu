<?php

namespace App\Support\Finance;

use App\Models\FinanceRadiologyTariffBinding;

final readonly class FinanceRadiologyTariffMutationResult
{
    public function __construct(
        public FinanceRadiologyTariffBinding $record,
        public bool $replayed,
    ) {}
}
