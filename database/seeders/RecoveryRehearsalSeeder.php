<?php

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Database\SchemaQualifier;
use App\Support\Operations\SyntheticRecoverySnapshot;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RecoveryRehearsalSeeder extends Seeder
{
    public function run(): void
    {
        $this->assertSafeBoundary();

        if (Patient::query()->where('is_synthetic', false)->exists()) {
            throw new RuntimeException('Recovery rehearsal refuses a database containing non-synthetic patients.');
        }

        $actor = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->first();
        if (! $actor instanceof User || Patient::query()->syntheticOnly()->doesntExist()) {
            throw new RuntimeException('Recovery rehearsal requires the bounded synthetic teaching census.');
        }

        $existing = DB::table(SchemaQualifier::table('audit_events'))
            ->where('action', 'authorization.denied')
            ->where('resource_type', 'http_route')
            ->where('resource_id', 'recovery.rehearsal.synthetic')
            ->get();

        if ($existing->count() > 1) {
            throw new RuntimeException('Recovery rehearsal audit sentinel is duplicated.');
        }

        if ($existing->isEmpty()) {
            $event = app(AuditRecorder::class)->record(
                action: 'authorization.denied',
                resourceType: 'http_route',
                resourceId: 'recovery.rehearsal.synthetic',
                actor: $actor,
                outcome: 'DENIED',
                reason: 'authorization_check_failed',
                metadata: ['http_method' => 'GET', 'http_status' => 403],
                includeRequestFingerprint: false,
            );

            if ($event === null) {
                throw new RuntimeException('Recovery rehearsal could not persist its validated audit sentinel.');
            }
        }

        if ($this->command !== null) {
            $this->command->info('Recovery rehearsal sentinel ready.');
        }
    }

    private function assertSafeBoundary(): void
    {
        if (getenv('SIMRS_RECOVERY_REHEARSAL_CONFIRM') !== 'YES_DISPOSABLE_LOCAL_POSTGRES17') {
            throw new RuntimeException('Recovery rehearsal seeding requires the explicit disposable-local confirmation.');
        }

        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Recovery rehearsal requires SIMULATION mode with synthetic-only enforcement.');
        }

        if (config('database.default') !== 'pgsql' || DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Recovery rehearsal seeding requires PostgreSQL.');
        }

        $version = (int) DB::scalar('SHOW server_version_num');
        if (intdiv($version, 10_000) !== 17) {
            throw new RuntimeException('Recovery rehearsal seeding requires PostgreSQL major version 17.');
        }

        $database = (string) DB::scalar('SELECT current_database()');
        if (preg_match(SyntheticRecoverySnapshot::DATABASE_NAME_PATTERN, $database) !== 1) {
            throw new RuntimeException('Recovery rehearsal seeding refuses a database outside the generated disposable namespace.');
        }

        if (SchemaQualifier::primarySchema() !== 'laravel') {
            throw new RuntimeException('Recovery rehearsal seeding requires the private laravel schema.');
        }

        $serverIsLocal = DB::scalar(<<<'SQL'
            SELECT inet_server_addr() IS NULL
                OR inet_server_addr() <<= inet '127.0.0.0/8'
                OR inet_server_addr() = inet '::1'
            SQL);
        if (filter_var($serverIsLocal, FILTER_VALIDATE_BOOLEAN) !== true) {
            throw new RuntimeException('Recovery rehearsal seeding refuses a non-local PostgreSQL server.');
        }
    }
}
