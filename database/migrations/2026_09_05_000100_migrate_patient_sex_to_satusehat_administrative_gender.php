<?php

use App\Support\Database\SchemaQualifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const LEGACY_CODES = [
        'LAKI_LAKI' => 'male',
        'PEREMPUAN' => 'female',
        'TIDAK_DIKETAHUI' => 'unknown',
    ];

    /** @var list<string> */
    private const CANONICAL_CODES = ['male', 'female', 'other', 'unknown'];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'pgsql', 'mysql'], true)) {
            throw new RuntimeException('Unsupported database driver for SATUSEHAT administrative-gender migration.');
        }

        $patients = SchemaQualifier::table('patients');
        if (! Schema::hasTable($patients) || ! Schema::hasColumn($patients, 'sex')) {
            throw new RuntimeException('Cannot migrate patient sex because patients.sex is missing.');
        }

        // This application owns a plain string patients.sex column. Do not
        // rewrite a host enum or CHECK: that can silently remove unrelated
        // constraints. A constrained host must be reconciled explicitly.
        $this->assertPlainStringColumn($driver, $patients);

        DB::transaction(function () use ($patients): void {
            $supported = array_merge(array_keys(self::LEGACY_CODES), self::CANONICAL_CODES);
            $unexpected = DB::table($patients)
                ->whereNull('sex')
                ->orWhereNotIn('sex', $supported)
                ->count();

            if ($unexpected > 0) {
                throw new RuntimeException(
                    "Refusing to migrate patient sex: {$unexpected} record(s) use null or unrecognised values.",
                );
            }

            foreach (self::LEGACY_CODES as $legacy => $canonical) {
                DB::table($patients)->where('sex', $legacy)->update(['sex' => $canonical]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'SATUSEHAT administrative-gender migration is forward-only; restoring legacy codes would discard the canonical other value.',
        );
    }

    private function assertPlainStringColumn(string $driver, string $patients): void
    {
        if ($driver === 'pgsql') {
            $schema = SchemaQualifier::primarySchema() ?? 'public';
            $dataType = DB::scalar(
                'SELECT data_type FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
                [$schema, 'patients', 'sex'],
            );
            $hasSexCheck = DB::selectOne(
                'SELECT 1 FROM pg_constraint WHERE conrelid = ?::regclass AND contype = ? AND pg_get_constraintdef(oid) ILIKE ? LIMIT 1',
                [$patients, 'c', '%sex%'],
            ) !== null;

            if (! in_array($dataType, ['character varying', 'text'], true) || $hasSexCheck) {
                throw new RuntimeException('Refusing SATUSEHAT administrative-gender migration: patients.sex is enum- or CHECK-constrained.');
            }

            return;
        }

        if ($driver === 'mysql') {
            $database = DB::connection()->getDatabaseName();
            $columnType = DB::scalar(
                'SELECT column_type FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
                [$database, 'patients', 'sex'],
            );
            $hasSexCheck = DB::selectOne(
                "SELECT 1 FROM information_schema.table_constraints tc JOIN information_schema.check_constraints cc ON cc.constraint_schema = tc.constraint_schema AND cc.constraint_name = tc.constraint_name WHERE tc.constraint_schema = ? AND tc.table_name = ? AND tc.constraint_type = 'CHECK' AND LOWER(cc.check_clause) LIKE ? LIMIT 1",
                [$database, 'patients', '%sex%'],
            ) !== null;

            if (! is_string($columnType) || str_starts_with(strtolower($columnType), 'enum(') || $hasSexCheck) {
                throw new RuntimeException('Refusing SATUSEHAT administrative-gender migration: patients.sex is enum- or CHECK-constrained.');
            }

            return;
        }

        $schema = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'patients')->value('sql');
        if (str_contains(strtolower($schema), 'check') && str_contains(strtolower($schema), 'sex')) {
            throw new RuntimeException('Refusing SATUSEHAT administrative-gender migration: patients.sex is CHECK-constrained.');
        }
    }
};
