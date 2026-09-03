<?php

use App\Support\Database\SchemaQualifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CLAIM_CHECK = 'encounters_active_inpatient_patient_ck';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }
        if (! in_array($driver, ['pgsql', 'mysql'], true)) {
            throw new RuntimeException("Unsupported database driver [{$driver}] for the inpatient patient claim correction.");
        }

        $table = SchemaQualifier::table('encounters');
        $drop = $driver === 'pgsql'
            ? 'DROP CONSTRAINT IF EXISTS '.self::CLAIM_CHECK
            : 'DROP CHECK '.self::CLAIM_CHECK;
        DB::statement('ALTER TABLE '.$table.' '.$drop.', ADD CONSTRAINT '.self::CLAIM_CHECK." CHECK (
            (
                care_setting = 'INPATIENT'
                AND status IN ('REGISTERED', 'IN_EXAMINATION')
                AND active_inpatient_patient_id IS NOT NULL
                AND active_inpatient_patient_id = patient_id
            )
            OR
            (
                (care_setting <> 'INPATIENT' OR status NOT IN ('REGISTERED', 'IN_EXAMINATION'))
                AND active_inpatient_patient_id IS NULL
            )
        )");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        throw new RuntimeException(
            'The inpatient patient claim correction is forward-only; refusing to restore the nullable PostgreSQL/MySQL check gap.',
        );
    }
};
