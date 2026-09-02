<?php

namespace App\Support\Finance;

use App\Models\FinanceAccommodationTariffBinding;

final readonly class FinanceAccommodationTariffMutationResult
{
    public function __construct(public FinanceAccommodationTariffBinding $record, public bool $replayed) {}
}
