<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected bool $recreateExactEngineDatabaseBeforeApplicationBoot = false;

    public function createApplication()
    {
        if ($this->recreateExactEngineDatabaseBeforeApplicationBoot) {
            $this->recreateDisposableExactEngineDatabase();
            if (in_array($this->environmentValueForTestDatabase('DB_CONNECTION', 'sqlite'), ['mysql', 'pgsql'], true)) {
                RefreshDatabaseState::$migrated = false;
            }
        }

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $recoverImplicitlyCommittedMySqlDatabase = $this->shouldRecoverImplicitlyCommittedMySqlDatabase();

        try {
            parent::tearDown();
        } finally {
            if ($this->recreateExactEngineDatabaseBeforeApplicationBoot || $recoverImplicitlyCommittedMySqlDatabase) {
                $this->recreateDisposableExactEngineDatabase();
            }
            if ($recoverImplicitlyCommittedMySqlDatabase) {
                RefreshDatabaseState::$migrated = false;
            }
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    private function recreateDisposableExactEngineDatabase(): void
    {
        $driver = $this->environmentValueForTestDatabase('DB_CONNECTION', 'sqlite');
        if (! in_array($driver, ['mysql', 'pgsql'], true)) {
            return;
        }

        if ($this->environmentValueForTestDatabase('APP_ENV', '') !== 'testing'
            || $this->environmentValueForTestDatabase('APP_MODE', '') !== 'SIMULATION'
            || $this->environmentValueForTestDatabase('APP_SYNTHETIC_ONLY', '') !== 'true') {
            throw new \RuntimeException('Exact-engine test database recreation requires the synthetic testing environment.');
        }

        $host = $this->environmentValueForTestDatabase('DB_HOST', '127.0.0.1');
        $port = $this->environmentValueForTestDatabase('DB_PORT', $driver === 'pgsql' ? '5432' : '3306');
        $database = $this->validatedTestDatabaseIdentifier($this->environmentValueForTestDatabase('DB_DATABASE', ''));
        if (! str_ends_with($database, '_test') && ! str_starts_with($database, 'simrs_codex_ci_')) {
            throw new \RuntimeException('Exact-engine test database recreation requires an explicitly disposable database name.');
        }
        $username = $this->environmentValueForTestDatabase('DB_USERNAME', '');
        $password = $this->environmentValueForTestDatabase('DB_PASSWORD', '');

        if ($driver === 'mysql') {
            $pdo = new \PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
            $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            return;
        }

        $schema = $this->validatedTestDatabaseIdentifier($this->environmentValueForTestDatabase('DB_SCHEMA', 'laravel'));
        if ($schema !== 'laravel') {
            throw new \RuntimeException('Exact-engine PostgreSQL tests may recreate only the private laravel schema.');
        }
        $pdo = new \PDO("pgsql:host={$host};port={$port};dbname={$database}", $username, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec("DROP SCHEMA IF EXISTS \"{$schema}\" CASCADE");
        $pdo->exec("CREATE SCHEMA \"{$schema}\"");
    }

    private function shouldRecoverImplicitlyCommittedMySqlDatabase(): bool
    {
        if (! in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)
            || ! isset($this->app)) {
            return false;
        }

        $connection = $this->app->make('db')->connection(config('database.default'));

        return $connection->getDriverName() === 'mysql'
            && $connection->transactionLevel() > 0
            && ! $connection->getPdo()->inTransaction();
    }

    private function environmentValueForTestDatabase(string $key, string $default): string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function validatedTestDatabaseIdentifier(string $identifier): string
    {
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $identifier) !== 1) {
            throw new \RuntimeException('Exact-engine test database identifiers must be simple SQL identifiers.');
        }

        return $identifier;
    }
}
