<?php

namespace App\Console\Commands;

use App\Support\Operations\SyntheticRecoverySnapshot;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

class VerifySyntheticRecoverySnapshotCommand extends Command
{
    protected $signature = 'ops:verify-synthetic-recovery {--json : Emit the value-minimized snapshot as JSON}';

    protected $description = 'Verify and fingerprint an isolated synthetic PostgreSQL recovery database';

    public function handle(SyntheticRecoverySnapshot $snapshot): int
    {
        try {
            $result = $snapshot->capture();
        } catch (Throwable $exception) {
            $blocked = [
                'schema_version' => 1,
                'kind' => 'SIMRS_SYNTHETIC_RECOVERY_SNAPSHOT',
                'status' => 'BLOCKED',
                'error' => $exception->getMessage(),
            ];

            if ($this->option('json')) {
                try {
                    $this->line(json_encode($blocked, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                } catch (JsonException) {
                    $this->error('Recovery snapshot JSON encoding failed.');
                }
            } else {
                $this->error($blocked['error']);
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('PASS synthetic PostgreSQL recovery snapshot '.$result['snapshot_sha256']);
        }

        return self::SUCCESS;
    }
}
