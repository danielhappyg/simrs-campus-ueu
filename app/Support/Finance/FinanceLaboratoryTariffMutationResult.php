<?php

namespace App\Support\Finance;

use App\Models\FinanceLaboratoryTariffBinding;

final readonly class FinanceLaboratoryTariffMutationResult
{
    public function __construct(
        public FinanceLaboratoryTariffBinding $record,
        public bool $replayed,
    ) {}
}
