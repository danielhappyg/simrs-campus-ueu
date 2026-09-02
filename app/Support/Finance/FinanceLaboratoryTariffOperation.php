<?php

namespace App\Support\Finance;

final class FinanceLaboratoryTariffOperation
{
    public const CREATE = 'FINANCE_LABORATORY_TARIFF_BINDING_CREATE';

    public const APPEND_VERSION = 'FINANCE_LABORATORY_TARIFF_BINDING_APPEND_VERSION';

    public const RETIRE = 'FINANCE_LABORATORY_TARIFF_BINDING_RETIRE';

    public const RESULT_BINDING = 'BINDING';

    private function __construct() {}
}
