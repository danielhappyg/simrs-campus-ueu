<?php

namespace App\Support\Warehouse;

use App\Support\Pharmacy\PharmacySchemaMutationScope;
use Illuminate\Support\Facades\DB;

final class WarehouseSchemaMutationScope
{
    private static int $depth = 0;

    public static function run(callable $callback): mixed
    {
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['pgsql', 'mysql'], true)) {
            WarehouseMutationScope::assertMigratorConnection();
        }

        self::$depth++;
        try {
            if ($driver === 'sqlite') {
                return PharmacySchemaMutationScope::run($callback);
            }

            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }
}
