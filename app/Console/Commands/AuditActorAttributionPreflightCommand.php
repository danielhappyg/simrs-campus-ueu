<?php

namespace App\Console\Commands;

use App\Support\Audit\AuditActorAttributionPreflight;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

class AuditActorAttributionPreflightCommand extends Command
{
    protected $signature = 'audit:attribution:preflight
        {--json : Emit one compact machine-readable report}
        {--require-current : Fail when any reviewed backfill remains}';

    protected $description = 'Read-only audit actor-attribution readiness preflight';

    public function handle(AuditActorAttributionPreflight $preflight): int
    {
        try {
            $report = $preflight->scan();
        } catch (Throwable $exception) {
            $code = $exception instanceof RuntimeException
                && in_array($exception->getMessage(), AuditActorAttributionPreflight::OPERATIONAL_CODES, true)
                    ? $exception->getMessage()
                    : 'DATABASE_SCAN_FAILED';

            return $this->emitOperationalFailure($code);
        }

        $exitCode = match (true) {
            $report['result'] === AuditActorAttributionPreflight::BLOCKING => 3,
            (bool) $this->option('require-current') && ! $report['contract_ready'] => 2,
            default => self::SUCCESS,
        };

        if ((bool) $this->option('json')) {
            try {
                $this->line(json_encode(
                    [...$report, 'exit_code' => $exitCode],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ));
            } catch (JsonException) {
                return $this->emitOperationalFailure('DATABASE_SCAN_FAILED');
            }
        } else {
            $this->line('Mode: READ_ONLY');
            $this->line('Result: '.$report['result']);
            $this->line('Rows scanned: '.$report['rows_scanned']);
            $this->line('Root digest: '.$report['root_digest']);
            $this->table(
                ['Classification', 'Count'],
                collect($report['counts'])
                    ->map(fn (int $count, string $classification): array => [$classification, $count])
                    ->values()
                    ->all(),
            );
        }

        return $exitCode;
    }

    private function emitOperationalFailure(string $code): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'schema_version' => 1,
                'command' => 'audit:attribution:preflight',
                'mode' => 'READ_ONLY',
                'result' => 'OPERATIONAL_FAILURE',
                'operational_code' => $code,
                'exit_code' => self::FAILURE,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error('Audit attribution preflight failed: '.$code);
        }

        return self::FAILURE;
    }
}
