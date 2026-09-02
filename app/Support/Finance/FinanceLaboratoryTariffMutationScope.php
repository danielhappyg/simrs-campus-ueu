<?php

namespace App\Support\Finance;

use Illuminate\Support\Facades\DB;
use LogicException;

final class FinanceLaboratoryTariffMutationScope
{
    private static int $depth = 0;

    public static function run(callable $callback): mixed
    {
        self::$depth++;
        $driver = DB::connection()->getDriverName();

        try {
            if (self::$depth === 1) {
                if ($driver === 'pgsql') {
                    if (DB::connection()->transactionLevel() < 1) {
                        throw new LogicException('Laboratory tariff binding mutations require a transaction.');
                    }
                    DB::statement("SET LOCAL simrs.finance_laboratory_tariff_mutation = '1'");
                } elseif ($driver === 'mysql') {
                    DB::statement('SET @simrs_finance_laboratory_tariff_mutation = 1');
                }
            }

            return $callback();
        } finally {
            if (self::$depth === 1 && $driver === 'mysql') {
                DB::statement('SET @simrs_finance_laboratory_tariff_mutation = 0');
            }
            self::$depth--;
        }
    }

    public static function assertActive(): void
    {
        if (! self::isActive()) {
            throw new LogicException('Laboratory tariff binding mutations must use FinanceLaboratoryTariffBindingService.');
        }
    }

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }
}
