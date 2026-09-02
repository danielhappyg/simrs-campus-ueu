<?php

namespace App\Support\Finance;

use App\Models\FinanceAccommodationTariffBinding;
use App\Models\FinanceAccommodationTariffBindingVersion;
use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceCostComponentVersion;
use App\Models\FinanceTariffItem;
use App\Models\FinanceTariffItemVersion;

final readonly class FinanceAccommodationTariffResolution
{
    public function __construct(
        public FinanceAccommodationTariffBinding $binding,
        public FinanceAccommodationTariffBindingVersion $bindingVersion,
        public FinanceTariffItem $tariffItem,
        public FinanceTariffItemVersion $tariffVersion,
        public FinanceCostComponent $component,
        public FinanceCostComponentVersion $componentVersion,
        public FinanceCostComponentGroup $componentGroup,
        public string $serviceDate,
        public int $amountRupiah,
    ) {}
}
