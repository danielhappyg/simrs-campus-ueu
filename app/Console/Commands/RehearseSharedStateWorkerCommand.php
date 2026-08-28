<?php

namespace App\Console\Commands;

use App\Models\Patient;
use App\Support\Database\SchemaQualifier;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Session\EncryptedStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class RehearseSharedStateWorkerCommand extends Command
{
    public const CONFIRMATION = 'YES_DISPOSABLE_OWNED_POSTGRES17_SHARED_STATE';

    public const DATABASE_PATTERN = '/\Asimrs_shared_[0-9a-f]{12}\z/';

    protected $signature = 'ops:rehearse-shared-state-worker
        {--run-token= : Generated 12-hex token bound to the disposable database}
        {--phase= : boundary, maintenance-read, counter-init, counter-increment, counter-read, lock-holder, lock-attempt, session-write, or session-read}
        {--worker= : A, B, or C}
        {--expect= : Closed expected outcome for read phases}
        {--hold-ms=0 : Lock-holder duration, at most 5000 ms}
        {--confirm-owned-local-synthetic : Confirm the harness-owned local synthetic PostgreSQL boundary}';

    protected $description = 'Internal worker for the disposable PostgreSQL shared-state rehearsal';

    public function handle(): int
    {
        $started = hrtime(true);

        try {
            [$runToken, $phase, $worker, $expect, $holdMs] = $this->validatedOptions();
            $backendPid = $this->assertSafeBoundary($runToken, $phase, $worker);
            $result = $this->runPhase($runToken, $phase, $expect, $holdMs);
            $result = [
                'schema_version' => 1,
                'status' => 'PASS',
                'phase' => $phase,
                'worker' => $worker,
                'backend_pid' => $backendPid,
                ...$result,
                'elapsed_ms' => $this->elapsedMilliseconds($started),
            ];

            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->line(json_encode([
                'schema_version' => 1,
                'status' => 'BLOCKED',
                'error_code' => 'SHARED_STATE_REHEARSAL_WORKER_FAILED',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    /** @return array{string, string, string, string, int} */
    private function validatedOptions(): array
    {
        if ($this->option('confirm-owned-local-synthetic') !== true
            || getenv('SIMRS_SHARED_STATE_REHEARSAL_CONFIRM') !== self::CONFIRMATION) {
            throw new RuntimeException('Shared-state rehearsal requires both explicit local confirmations.');
        }

        $runToken = trim((string) $this->option('run-token'));
        if (preg_match('/\A[0-9a-f]{12}\z/', $runToken) !== 1) {
            throw new RuntimeException('Shared-state rehearsal run token is invalid.');
        }

        $phase = trim((string) $this->option('phase'));
        if (! in_array($phase, [
            'boundary',
            'maintenance-read',
            'counter-init',
            'counter-increment',
            'counter-read',
            'lock-holder',
            'lock-attempt',
            'session-write',
            'session-read',
        ], true)) {
            throw new RuntimeException('Shared-state rehearsal phase is invalid.');
        }

        $worker = strtoupper(trim((string) $this->option('worker')));
        if (! in_array($worker, ['A', 'B', 'C'], true)) {
            throw new RuntimeException('Shared-state rehearsal worker is invalid.');
        }

        $expect = trim((string) $this->option('expect'));
        $allowedExpectations = [
            'boundary' => ['ready'],
            'maintenance-read' => ['active', 'inactive'],
            'counter-init' => ['zero'],
            'counter-increment' => ['incremented'],
            'counter-read' => ['two'],
            'lock-holder' => ['held'],
            'lock-attempt' => ['rejected'],
            'session-write' => ['written'],
            'session-read' => ['present', 'absent'],
        ];
        if (! in_array($expect, $allowedExpectations[$phase], true)) {
            throw new RuntimeException('Shared-state rehearsal expectation is invalid for its phase.');
        }

        $holdValue = trim((string) $this->option('hold-ms'));
        if (preg_match('/\A[0-9]{1,4}\z/', $holdValue) !== 1 || (int) $holdValue > 5000) {
            throw new RuntimeException('Shared-state rehearsal hold duration is invalid.');
        }
        $holdMs = (int) $holdValue;
        if (($phase === 'lock-holder') !== ($holdMs > 0)) {
            throw new RuntimeException('Only the lock holder requires a positive hold duration.');
        }

        return [$runToken, $phase, $worker, $expect, $holdMs];
    }

    private function assertSafeBoundary(string $runToken, string $phase, string $worker): int
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Shared-state rehearsal requires SIMULATION synthetic-only mode.');
        }
        if (config('database.default') !== 'pgsql' || DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Shared-state rehearsal requires PostgreSQL.');
        }
        if ((int) DB::scalar('SHOW server_version_num') !== 170_010) {
            throw new RuntimeException('Shared-state rehearsal requires PostgreSQL 17.10 exactly.');
        }

        $database = (string) DB::scalar('SELECT current_database()');
        if (preg_match(self::DATABASE_PATTERN, $database) !== 1 || $database !== 'simrs_shared_'.$runToken) {
            throw new RuntimeException('Shared-state rehearsal database is outside its generated namespace.');
        }
        if (SchemaQualifier::primarySchema() !== 'laravel') {
            throw new RuntimeException('Shared-state rehearsal requires the private laravel schema.');
        }
        if (DB::scalar('SELECT inet_server_addr() IS NULL') !== true
            || (string) DB::scalar('SHOW listen_addresses') !== '') {
            throw new RuntimeException('Shared-state rehearsal requires its non-listening Unix-socket cluster.');
        }

        $expectedPrefixes = [
            sprintf('simrs_shared_%s_', $runToken),
            sprintf('simrs_shared_%s_divergent_', $runToken),
        ];
        if (config('cache.default') !== 'database'
            || config('cache.stores.database.driver') !== 'database'
            || ! in_array(config('cache.prefix'), $expectedPrefixes, true)) {
            throw new RuntimeException('Shared-state rehearsal requires its closed database-cache namespace.');
        }
        if (config('session.driver') !== 'database'
            || config('session.encrypt') !== true
            || config('app.maintenance.driver') !== 'cache'
            || config('app.maintenance.store') !== 'database') {
            throw new RuntimeException('Shared-state rehearsal requires encrypted database sessions and database-cache maintenance.');
        }
        if (config('mail.default') !== 'array' || config('queue.default') !== 'sync') {
            throw new RuntimeException('Shared-state rehearsal requires disabled external delivery.');
        }
        if (Patient::query()->where('is_synthetic', false)->exists()) {
            throw new RuntimeException('Shared-state rehearsal refuses non-synthetic patient data.');
        }

        DB::selectOne("SELECT set_config('application_name', ?, false)", [
            sprintf('simrs_shared_%s_%s_%s', $runToken, str_replace('-', '_', $phase), strtolower($worker)),
        ]);

        return (int) DB::scalar('SELECT pg_backend_pid()');
    }

    /** @return array<string, bool|int|string> */
    private function runPhase(string $runToken, string $phase, string $expect, int $holdMs): array
    {
        return match ($phase) {
            'boundary' => $this->boundaryResult($expect),
            'maintenance-read' => $this->maintenanceResult($expect),
            'counter-init' => $this->counterInitResult($runToken),
            'counter-increment' => $this->counterIncrementResult($runToken),
            'counter-read' => $this->counterReadResult($runToken),
            'lock-holder' => $this->lockHolderResult($runToken, $holdMs),
            'lock-attempt' => $this->lockAttemptResult($runToken),
            'session-write' => $this->sessionWriteResult($runToken),
            'session-read' => $this->sessionReadResult($runToken, $expect),
            default => throw new RuntimeException('Shared-state rehearsal phase was not implemented.'),
        };
    }

    /** @return array<string, bool|int|string> */
    private function boundaryResult(string $expect): array
    {
        $tables = DB::selectOne(<<<'SQL'
SELECT
    to_regclass('cache') = to_regclass('laravel.cache') AS cache_table_private,
    to_regclass('cache_locks') = to_regclass('laravel.cache_locks') AS lock_table_private,
    to_regclass('sessions') = to_regclass('laravel.sessions') AS session_table_private,
    current_schema() AS current_schema
SQL);

        if ($expect !== 'ready'
            || $tables->cache_table_private !== true
            || $tables->lock_table_private !== true
            || $tables->session_table_private !== true
            || $tables->current_schema !== 'laravel') {
            throw new RuntimeException('Shared-state framework tables did not resolve in the private schema.');
        }

        return [
            'outcome' => 'BOUNDARY_READY',
            'framework_tables_private' => true,
        ];
    }

    /** @return array<string, bool|int|string> */
    private function maintenanceResult(string $expect): array
    {
        $active = app()->maintenanceMode()->active();
        if (($expect === 'active') !== $active) {
            throw new RuntimeException('Shared maintenance state did not match the expected cache namespace.');
        }

        return [
            'outcome' => $active ? 'MAINTENANCE_ACTIVE' : 'MAINTENANCE_INACTIVE',
            'maintenance_active' => $active,
        ];
    }

    /** @return array<string, bool|int|string> */
    private function counterInitResult(string $runToken): array
    {
        if (! Cache::store('database')->put($this->counterKey($runToken), 0, 300)) {
            throw new RuntimeException('Shared cache counter initialization failed.');
        }

        return ['outcome' => 'COUNTER_INITIALIZED', 'counter_value' => 0];
    }

    /** @return array<string, bool|int|string> */
    private function counterIncrementResult(string $runToken): array
    {
        $value = Cache::store('database')->increment($this->counterKey($runToken));
        if (! is_int($value) || ! in_array($value, [1, 2], true)) {
            throw new RuntimeException('Shared cache counter increment failed.');
        }

        return ['outcome' => 'COUNTER_INCREMENTED', 'counter_value' => $value];
    }

    /** @return array<string, bool|int|string> */
    private function counterReadResult(string $runToken): array
    {
        $value = Cache::store('database')->get($this->counterKey($runToken));
        if ($value !== 2) {
            throw new RuntimeException('Shared cache counter did not retain both increments.');
        }

        return ['outcome' => 'COUNTER_TWO', 'counter_value' => 2];
    }

    /** @return array<string, bool|int|string> */
    private function lockHolderResult(string $runToken, int $holdMs): array
    {
        $lock = Cache::lock($this->lockKey($runToken), 10);
        if (! $lock->get()) {
            throw new RuntimeException('Shared cache lock holder could not acquire its lock.');
        }

        try {
            DB::selectOne('SELECT pg_sleep(?)', [$holdMs / 1000]);
        } finally {
            $this->releaseRequired($lock);
        }

        return ['outcome' => 'LOCK_HELD_RELEASED', 'lock_released' => true];
    }

    /** @return array<string, bool|int|string> */
    private function lockAttemptResult(string $runToken): array
    {
        $lock = Cache::lock($this->lockKey($runToken), 10);
        $acquired = $lock->get();
        if ($acquired) {
            $this->releaseRequired($lock);
            throw new RuntimeException('Shared cache lock contender acquired a concurrently held lock.');
        }

        return ['outcome' => 'LOCK_REJECTED', 'lock_acquired' => false];
    }

    /** @return array<string, bool|int|string> */
    private function sessionWriteResult(string $runToken): array
    {
        $session = app('session')->driver();
        if (! $session instanceof EncryptedStore) {
            throw new RuntimeException('Shared-state rehearsal requires Laravel EncryptedStore.');
        }
        $session->setId($this->sessionId($runToken));
        $session->start();
        $session->put('shared_state_marker', $this->sessionMarker($runToken));
        $session->save();

        return ['outcome' => 'SESSION_WRITTEN', 'session_encrypted' => true];
    }

    /** @return array<string, bool|int|string> */
    private function sessionReadResult(string $runToken, string $expect): array
    {
        $session = app('session')->driver();
        if (! $session instanceof EncryptedStore) {
            throw new RuntimeException('Shared-state rehearsal requires Laravel EncryptedStore.');
        }
        $session->setId($this->sessionId($runToken));
        $session->start();
        $present = hash_equals($this->sessionMarker($runToken), (string) $session->get('shared_state_marker', ''));
        if (($expect === 'present') !== $present) {
            throw new RuntimeException('Shared encrypted session visibility did not match the expected application key.');
        }

        return [
            'outcome' => $present ? 'SESSION_PRESENT' : 'SESSION_ABSENT',
            'session_marker_present' => $present,
        ];
    }

    private function releaseRequired(Lock $lock): void
    {
        if (! $lock->release()) {
            throw new RuntimeException('Shared cache lock release failed.');
        }
    }

    private function counterKey(string $runToken): string
    {
        return 'shared-counter-'.$runToken;
    }

    private function lockKey(string $runToken): string
    {
        return 'shared-lock-'.$runToken;
    }

    private function sessionId(string $runToken): string
    {
        return $runToken.str_repeat('0', 28);
    }

    private function sessionMarker(string $runToken): string
    {
        return 'SIMRS_SHARED_SESSION_'.$runToken;
    }

    private function elapsedMilliseconds(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
