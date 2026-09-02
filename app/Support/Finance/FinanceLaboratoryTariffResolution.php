<?php

namespace App\Support\Finance;

use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceCostComponentVersion;
use App\Models\FinanceLaboratoryTariffBinding;
use App\Models\FinanceLaboratoryTariffBindingVersion;
use App\Models\FinanceTariffItem;
use App\Models\FinanceTariffItemVersion;

final readonly class FinanceLaboratoryTariffResolution
{
    public function __construct(
        public FinanceLaboratoryTariffBinding $binding,
        public FinanceLaboratoryTariffBindingVersion $bindingVersion,
        public FinanceTariffItem $tariffItem,
        public FinanceTariffItemVersion $tariffVersion,
        public FinanceCostComponent $component,
        public FinanceCostComponentVersion $componentVersion,
        public FinanceCostComponentGroup $componentGroup,
        public string $serviceDate,
        public int $amountRupiah,
    ) {}
}
