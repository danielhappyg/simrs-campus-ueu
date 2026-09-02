<?php

namespace App\Support\Finance;

final class FinanceRadiologyTariffOperation
{
    public const CREATE = 'FINANCE_RADIOLOGY_TARIFF_BINDING_CREATE';

    public const APPEND_VERSION = 'FINANCE_RADIOLOGY_TARIFF_BINDING_APPEND_VERSION';

    public const RETIRE = 'FINANCE_RADIOLOGY_TARIFF_BINDING_RETIRE';

    public const RESULT_BINDING = 'BINDING';

    private function __construct() {}
}
