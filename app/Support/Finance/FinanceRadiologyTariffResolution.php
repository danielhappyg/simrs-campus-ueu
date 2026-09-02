<?php

namespace App\Support\Finance;

use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceCostComponentVersion;
use App\Models\FinanceRadiologyTariffBinding;
use App\Models\FinanceRadiologyTariffBindingVersion;
use App\Models\FinanceTariffItem;
use App\Models\FinanceTariffItemVersion;

final readonly class FinanceRadiologyTariffResolution
{
    public function __construct(
        public FinanceRadiologyTariffBinding $binding,
        public FinanceRadiologyTariffBindingVersion $bindingVersion,
        public FinanceTariffItem $tariffItem,
        public FinanceTariffItemVersion $tariffVersion,
        public FinanceCostComponent $component,
        public FinanceCostComponentVersion $componentVersion,
        public FinanceCostComponentGroup $componentGroup,
        public string $serviceDate,
        public int $amountRupiah,
    ) {}
}
