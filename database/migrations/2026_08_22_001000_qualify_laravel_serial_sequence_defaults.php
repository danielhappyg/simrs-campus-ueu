<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PgBouncer / pooled connections can drop search_path mid-request.
 * Unqualified nextval('*_id_seq') then fails even when Eloquent writes
 * schema-qualified tables (laravel.patients). Pin defaults to laravel.*.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'patients',
        'encounters',
        'clinical_entries',
        'clinics',
        'doctors',
        'clinic_schedules',
        'users',
        'roles',
        'permissions',
        'jobs',
        'failed_jobs',
        'passkeys',
        'migrations',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tables as $table) {
            $qualifiedTable = 'laravel.'.$table;
            $qualifiedSeq = 'laravel.'.$table.'_id_seq';

            DB::statement(sprintf(
                'ALTER TABLE %s ALTER COLUMN id SET DEFAULT nextval(%s::regclass)',
                $qualifiedTable,
                "'".$qualifiedSeq."'",
            ));
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tables as $table) {
            $qualifiedTable = 'laravel.'.$table;
            $unqualifiedSeq = $table.'_id_seq';

            DB::statement(sprintf(
                'ALTER TABLE %s ALTER COLUMN id SET DEFAULT nextval(%s::regclass)',
                $qualifiedTable,
                "'".$unqualifiedSeq."'",
            ));
        }
    }
};
