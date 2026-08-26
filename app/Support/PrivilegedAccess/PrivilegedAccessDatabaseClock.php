<?php

namespace App\Support\PrivilegedAccess;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PrivilegedAccessDatabaseClock
{
    public function now(): CarbonImmutable
    {
        $connection = DB::connection();
        $sql = match ($connection->getDriverName()) {
            'pgsql' => "SELECT (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') AS database_now",
            'mysql' => 'SELECT UTC_TIMESTAMP(6) AS database_now',
            'sqlite' => "SELECT STRFTIME('%Y-%m-%d %H:%M:%f', 'now') AS database_now",
            default => throw new RuntimeException('The database clock driver is unsupported.'),
        };
        $row = $connection->selectOne($sql);
        $value = data_get($row, 'database_now');

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('Database time is unavailable.');
        }

        return CarbonImmutable::parse($value, 'UTC')->utc();
    }
}
