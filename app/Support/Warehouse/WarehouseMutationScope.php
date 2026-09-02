<?php

namespace App\Support\Warehouse;

use App\Support\Pharmacy\PharmacyMutationScope;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class WarehouseMutationScope
{
    public const DEFAULT_MIGRATOR_CONNECTION = 'warehouse_migrator';

    public const DEFAULT_RUNTIME_CONNECTION = 'warehouse_runtime';

    public const DEFAULT_WRITER_CONNECTION = 'warehouse_writer';

    public const DEFAULT_RESET_EXECUTOR_CONNECTION = 'warehouse_reset_executor';

    private static int $depth = 0;

    public static function run(callable $callback): mixed
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            self::$depth++;
            try {
                return PharmacyMutationScope::run($callback);
            } finally {
                self::$depth--;
            }
        }
        if (! in_array($driver, ['pgsql', 'mysql'], true)) {
            throw new LogicException('Warehouse mutations are unsupported for this database driver.');
        }

        $writer = self::assertWriterConnection();
        if ($writer->transactionLevel() < 1) {
            throw new LogicException('Exact-engine warehouse mutations require a transaction on the named warehouse writer connection.');
        }

        self::$depth++;
        try {
            return $callback($writer);
        } finally {
            self::$depth--;
        }
    }

    public static function assertMigratorConnection(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'mysql'], true)) {
            return;
        }

        self::assertNamedAuthenticatedConnection(
            self::connectionName('migrator', self::DEFAULT_MIGRATOR_CONNECTION),
            self::configuredIdentity('migrator'),
            'migrator',
        );
    }

    public static function assertWriterConnection(): Connection
    {
        $runtimeName = self::connectionName('runtime', self::DEFAULT_RUNTIME_CONNECTION);
        if (DB::getDefaultConnection() !== $runtimeName) {
            throw new LogicException("Exact-engine default warehouse access must use the named {$runtimeName} runtime connection.");
        }

        $runtime = DB::connection();
        $runtimeIdentity = self::configuredIdentity('runtime');
        $writerIdentity = self::configuredIdentity('writer');
        if (mb_strtolower($runtimeIdentity) === mb_strtolower($writerIdentity)) {
            throw new LogicException('Warehouse runtime and writer identities must be distinct.');
        }
        self::assertAuthenticatedIdentity($runtime, $runtimeIdentity, 'runtime');

        $writerName = self::connectionName('writer', self::DEFAULT_WRITER_CONNECTION);
        $writer = DB::connection($writerName);
        if ($writer->getName() !== $writerName) {
            throw new LogicException("Exact-engine warehouse mutations require the named {$writerName} connection.");
        }
        if ($writer->getDriverName() !== $runtime->getDriverName() || (string) $writer->getDatabaseName() !== (string) $runtime->getDatabaseName()) {
            throw new LogicException('Warehouse runtime and writer identities must use the same exact engine and database.');
        }
        self::assertSameDatabaseTargetFingerprints([
            'runtime' => self::databaseTargetFingerprint($runtime, $runtime->getDriverName()),
            'writer' => self::databaseTargetFingerprint($writer, $writer->getDriverName()),
        ]);
        self::assertAuthenticatedIdentity(
            $writer,
            $writerIdentity,
            'writer',
        );

        return $writer;
    }

    public static function assertExecutingScopeConnection(Connection $connection, bool $schemaScope): void
    {
        if (! in_array($connection->getDriverName(), ['pgsql', 'mysql'], true)) {
            return;
        }

        $purpose = $schemaScope ? 'migrator' : 'writer';
        $default = $schemaScope ? self::DEFAULT_MIGRATOR_CONNECTION : self::DEFAULT_WRITER_CONNECTION;
        $expected = self::connectionName($purpose, $default);
        if ($connection->getName() !== $expected) {
            throw new LogicException("Warehouse {$purpose} scope cannot authorize SQL on the {$connection->getName()} connection.");
        }
    }

    /**
     * Resolve and verify the identities that an exact-engine installation will
     * embed in its trigger contract. Every role must be a separate credential.
     *
     * @return array{driver: string, migrator: string, runtime: string, writer: string, reset_executor: string, reset_owner: string, database: string, target_fingerprint: string}
     */
    public static function installationIdentityContract(): array
    {
        self::assertMigratorConnection();

        $connections = [
            'migrator' => DB::connection(),
            'runtime' => DB::connection(self::connectionName('runtime', self::DEFAULT_RUNTIME_CONNECTION)),
            'writer' => DB::connection(self::connectionName('writer', self::DEFAULT_WRITER_CONNECTION)),
            'reset_executor' => DB::connection(self::connectionName('reset_executor', self::DEFAULT_RESET_EXECUTOR_CONNECTION)),
        ];
        $driver = $connections['migrator']->getDriverName();
        $database = (string) $connections['migrator']->getDatabaseName();
        $identities = [];
        $targetFingerprints = [];

        foreach ($connections as $purpose => $connection) {
            if ($connection->getDriverName() !== $driver || (string) $connection->getDatabaseName() !== $database) {
                throw new LogicException('Warehouse database identities must use the same exact engine and database.');
            }

            $actual = self::authenticatedIdentity($connection, $driver);
            $expected = self::configuredIdentity($purpose);
            if (! hash_equals($expected, $actual)) {
                throw new LogicException("Authenticated warehouse {$purpose} identity does not match its pinned configuration.");
            }
            $identities[$purpose] = $actual;
            $targetFingerprints[$purpose] = self::databaseTargetFingerprint($connection, $driver);
        }

        $identities['reset_owner'] = self::configuredIdentity('reset_owner');
        self::assertDistinctDatabaseIdentities($identities);
        self::assertSameDatabaseTargetFingerprints($targetFingerprints);

        return [
            'driver' => $driver,
            'migrator' => $identities['migrator'],
            'runtime' => $identities['runtime'],
            'writer' => $identities['writer'],
            'reset_executor' => $identities['reset_executor'],
            'reset_owner' => $identities['reset_owner'],
            'database' => $database,
            'target_fingerprint' => $targetFingerprints['migrator'],
        ];
    }

    /** @param array<string, string> $identities */
    public static function assertDistinctDatabaseIdentities(array $identities): void
    {
        $required = ['migrator', 'runtime', 'writer', 'reset_executor', 'reset_owner'];
        $normalized = [];
        foreach ($required as $purpose) {
            $identity = $identities[$purpose] ?? null;
            if (! is_string($identity) || $identity === '' || strlen($identity) > 255 || preg_match('/[\x00-\x1F\x7F]/', $identity) === 1) {
                throw new LogicException("Warehouse {$purpose} identity must be a non-empty pinned database identity.");
            }
            $normalized[$purpose] = mb_strtolower($identity);
        }
        if (count(array_unique($normalized, SORT_STRING)) !== count($required)) {
            throw new LogicException('Warehouse migrator, runtime, writer, reset executor, and reset owner identities must be pairwise distinct.');
        }
    }

    /** @param array<string, string> $fingerprints */
    public static function assertSameDatabaseTargetFingerprints(array $fingerprints): void
    {
        if ($fingerprints === [] || in_array('', $fingerprints, true)) {
            throw new LogicException('Every warehouse database identity requires a server target fingerprint.');
        }
        if (count(array_unique($fingerprints, SORT_STRING)) !== 1) {
            throw new LogicException('Warehouse database identities must resolve to the same database server target.');
        }
    }

    /**
     * Machine-readable deployment contract. Application checks cannot replace
     * these database grants on PostgreSQL/MySQL.
     *
     * @return array<string, list<string>>
     */
    public static function requiredPrivilegeContract(): array
    {
        return [
            'runtime_allow' => ['SELECT'],
            'runtime_deny' => ['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'CREATE', 'ALTER', 'DROP', 'TRIGGER', 'EXECUTE_RESET', 'SET_ROLE', 'ROLE_MEMBERSHIP', 'SCHEMA_CREATE', 'GRANT_OPTION', 'SUPERUSER', 'BYPASSRLS', 'REPLICATION'],
            'writer_mutable_heads_allow' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
            'writer_append_only_allow' => ['SELECT', 'INSERT'],
            'writer_deny' => ['UPDATE_APPEND_ONLY', 'DELETE_APPEND_ONLY', 'TRUNCATE', 'CREATE', 'ALTER', 'DROP', 'TRIGGER', 'SET_ROLE', 'ROLE_MEMBERSHIP', 'SCHEMA_CREATE', 'CREATE_ROUTINE', 'ALTER_ROUTINE', 'GRANT_OPTION', 'SUPERUSER', 'BYPASSRLS', 'REPLICATION'],
            'migrator_allow' => ['SCHEMA_OWNER'],
            'migrator_deny' => ['RUNTIME_CREDENTIAL_AVAILABILITY'],
            'reset_executor_allow' => ['EXECUTE_BOUNDED_SECURITY_DEFINER_RESET'],
            'reset_executor_deny' => ['TABLE_SELECT', 'TABLE_INSERT', 'TABLE_UPDATE', 'TABLE_DELETE', 'TRUNCATE', 'CREATE', 'ALTER', 'DROP', 'TRIGGER', 'CREATE_ROUTINE', 'ALTER_ROUTINE', 'GRANT_OPTION', 'ROLE_MEMBERSHIP'],
            'reset_owner_allow' => ['OWN_BOUNDED_SECURITY_DEFINER_RESET'],
            'reset_owner_deny' => ['LOGIN', 'INTERACTIVE_USE'],
        ];
    }

    public static function assertActive(): void
    {
        if (! self::isActive()) {
            throw new LogicException('Warehouse mutations must use the governed warehouse service.');
        }
    }

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }

    private static function assertNamedAuthenticatedConnection(string $expectedConnection, string $expectedIdentity, string $purpose): void
    {
        if (DB::getDefaultConnection() !== $expectedConnection) {
            throw new LogicException("Exact-engine warehouse {$purpose} operations require the named {$expectedConnection} connection.");
        }

        self::assertAuthenticatedIdentity(DB::connection(), $expectedIdentity, $purpose);
    }

    private static function assertAuthenticatedIdentity(Connection $connection, string $expectedIdentity, string $purpose): void
    {
        $actualIdentity = self::authenticatedIdentity($connection, $connection->getDriverName());
        if (! hash_equals($expectedIdentity, $actualIdentity)) {
            throw new LogicException("Authenticated warehouse {$purpose} identity does not match its pinned configuration.");
        }
    }

    private static function authenticatedIdentity(Connection $connection, string $driver): string
    {
        $row = match ($driver) {
            'pgsql' => $connection->selectOne('SELECT session_user AS authenticated_identity'),
            'mysql' => $connection->selectOne('SELECT USER() AS authenticated_identity'),
            default => throw new LogicException('Warehouse identity checks require PostgreSQL or MySQL.'),
        };

        return (string) ($row->authenticated_identity ?? '');
    }

    private static function databaseTargetFingerprint(Connection $connection, string $driver): string
    {
        $server = match ($driver) {
            'pgsql' => $connection->selectOne("SELECT COALESCE(inet_server_addr()::text, 'unix') AS server_identity, COALESCE(inet_server_port(), 0) AS server_port"),
            'mysql' => $connection->selectOne('SELECT @@server_uuid AS server_identity, 0 AS server_port'),
            default => throw new LogicException('Warehouse server target checks require PostgreSQL or MySQL.'),
        };
        $target = json_encode([
            'driver' => $driver,
            'host' => $connection->getConfig('host'),
            'port' => $connection->getConfig('port'),
            'unix_socket' => $connection->getConfig('unix_socket'),
            'database' => $connection->getDatabaseName(),
            'server_identity' => $server->server_identity ?? null,
            'server_port' => $server->server_port ?? null,
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $target);
    }

    private static function connectionName(string $purpose, string $default): string
    {
        $name = config("database.warehouse_connections.{$purpose}", $default);
        if (! is_string($name) || $name === '') {
            throw new LogicException("Warehouse {$purpose} connection name must be configured.");
        }

        return $name;
    }

    private static function configuredIdentity(string $purpose): string
    {
        $identity = config("database.warehouse_identities.{$purpose}");
        if (! is_string($identity) || $identity === '') {
            throw new LogicException("Warehouse {$purpose} database identity must be pinned in configuration.");
        }

        return $identity;
    }
}
