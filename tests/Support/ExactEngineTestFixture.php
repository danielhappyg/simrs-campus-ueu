<?php

namespace Tests\Support;

use App\Support\Database\SchemaQualifier;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Assert;
use RuntimeException;

final class ExactEngineTestFixture
{
    /**
     * Run an intentional corruption fixture with PostgreSQL user triggers
     * disabled only inside a nested transaction that is always rolled back.
     * MySQL cannot disable triggers transactionally, so its mandatory trigger
     * refusal is accepted as the stronger integrity outcome instead.
     *
     * @template T
     *
     * @param  list<string>  $tables
     * @param  callable(): T  $callback
     * @return T|null
     */
    public static function corruptWithPostgresTriggersDisabled(array $tables, callable $callback): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'mysql') {
            self::assertSafeExactEngineFixture($connection);

            try {
                return $callback();
            } catch (QueryException $exception) {
                if (self::isMysqlTriggerRefusal($exception)) {
                    Assert::assertTrue(true, 'MySQL refused the intentional trigger-protected corruption.');

                    return null;
                }

                throw $exception;
            }
        }

        if ($connection->getDriverName() !== 'pgsql') {
            return $callback();
        }

        self::assertSafeExactEngineFixture($connection);
        $connection->beginTransaction();

        try {
            foreach ($tables as $table) {
                self::assertSimpleIdentifier($table);
                $qualified = $connection->getQueryGrammar()->wrapTable(SchemaQualifier::table($table));
                $connection->getPdo()->exec("ALTER TABLE {$qualified} DISABLE TRIGGER USER");
            }

            return $callback();
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * Run an intentional PostgreSQL CHECK-constraint corruption fixture only
     * inside a nested transaction that restores both data and DDL on rollback.
     * MySQL cannot drop a CHECK transactionally, so a database refusal is the
     * expected safe result and is returned as null.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    public static function corruptWithoutPostgresCheck(string $table, string $constraint, callable $callback): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'mysql') {
            self::assertSafeExactEngineFixture($connection);

            try {
                return $callback();
            } catch (QueryException $exception) {
                if (self::isMysqlCheckRefusal($exception)) {
                    Assert::assertTrue(true, 'MySQL refused the intentional CHECK-protected corruption.');

                    return null;
                }

                throw $exception;
            }
        }

        if ($connection->getDriverName() !== 'pgsql') {
            return $callback();
        }

        self::assertSafeExactEngineFixture($connection);
        self::assertSimpleIdentifier($table);
        self::assertSimpleIdentifier($constraint);
        $qualified = $connection->getQueryGrammar()->wrapTable(SchemaQualifier::table($table));
        $wrappedConstraint = $connection->getQueryGrammar()->wrap($constraint);
        $connection->beginTransaction();

        try {
            $connection->getPdo()->exec("ALTER TABLE {$qualified} DROP CONSTRAINT {$wrappedConstraint}");

            return $callback();
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * Permit the exact-engine DELETE path used by a synthetic reset fixture,
     * then restore the connection-local flag before returning.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function runSyntheticResetDelete(callable $callback): mixed
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['pgsql', 'mysql'], true)) {
            return $callback();
        }

        self::assertSafeExactEngineFixture($connection);
        $connection->getPdo()->exec($driver === 'pgsql'
            ? "SET LOCAL simrs.synthetic_reset = '1'"
            : 'SET @simrs_synthetic_reset = 1');

        try {
            return $callback();
        } finally {
            $connection->getPdo()->exec($driver === 'pgsql'
                ? "SET LOCAL simrs.synthetic_reset = '0'"
                : 'SET @simrs_synthetic_reset = 0');
        }
    }

    private static function assertSafeExactEngineFixture(ConnectionInterface $connection): void
    {
        $database = (string) $connection->getDatabaseName();
        if (! app()->environment('testing')
            || strtoupper((string) config('simulation.mode')) !== 'SIMULATION'
            || ! filter_var(config('simulation.synthetic_only'), FILTER_VALIDATE_BOOLEAN)
            || (! str_ends_with($database, '_test') && ! str_starts_with($database, 'simrs_codex_ci_'))
            || $connection->transactionLevel() < 1) {
            throw new RuntimeException('Exact-engine corruption fixtures require a synthetic disposable test transaction.');
        }
    }

    private static function assertSimpleIdentifier(string $identifier): void
    {
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $identifier) !== 1) {
            throw new RuntimeException('Exact-engine corruption fixture identifiers must be simple SQL identifiers.');
        }
    }

    private static function isMysqlTriggerRefusal(QueryException $exception): bool
    {
        $previous = $exception->getPrevious();

        return $previous instanceof PDOException
            && ($previous->errorInfo[0] ?? null) === '45000'
            && (int) ($previous->errorInfo[1] ?? 0) === 1644;
    }

    private static function isMysqlCheckRefusal(QueryException $exception): bool
    {
        $previous = $exception->getPrevious();

        return $previous instanceof PDOException
            && (int) ($previous->errorInfo[1] ?? 0) === 3819;
    }
}
