<?php

use App\Models\Encounter;
use App\Support\Database\SchemaQualifier;
use App\Support\Emergency\SqliteEmergencyHandoffGraphGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_CLAIM = 'encounters_active_inpatient_patient_uq';

    private const CLAIM_CHECK = 'encounters_active_inpatient_patient_ck';

    private const CLAIM_INSERT_TRIGGER = 'encounters_active_inpatient_patient_insert_ck';

    private const CLAIM_UPDATE_TRIGGER = 'encounters_active_inpatient_patient_update_ck';

    public function up(): void
    {
        $this->assertSyntheticMigrationBoundary();

        Schema::create(SchemaQualifier::table('inpatient_patient_claim_mutexes'), function (Blueprint $table): void {
            $table->foreignId('patient_id')
                ->primary()
                ->constrained(SchemaQualifier::table('patients'), indexName: 'ipcm_patient_fk')
                ->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table(SchemaQualifier::table('encounters'), function (Blueprint $table): void {
            $table->foreignId('active_inpatient_patient_id')
                ->nullable()
                ->after('patient_id')
                ->constrained(SchemaQualifier::table('patients'), indexName: 'encounters_active_inpatient_patient_fk')
                ->cascadeOnDelete();
            $table->unique('active_inpatient_patient_id', self::UNIQUE_CLAIM);
        });

        $this->backfillAndVerifyClaims();
        $this->addClaimConsistencyCheck();
        SqliteEmergencyHandoffGraphGuard::createIfSupported();
    }

    public function down(): void
    {
        $encounters = SchemaQualifier::table('encounters');
        if (Schema::hasColumn($encounters, 'active_inpatient_patient_id')
            && DB::table($encounters)->whereNotNull('active_inpatient_patient_id')->exists()) {
            throw new RuntimeException('Refusing to remove the active inpatient patient claim guard while active admissions remain.');
        }

        SqliteEmergencyHandoffGraphGuard::dropIfPresent();
        $this->dropClaimConsistencyCheck();
        Schema::table($encounters, function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_CLAIM);
            $table->dropForeign('encounters_active_inpatient_patient_fk');
            $table->dropColumn('active_inpatient_patient_id');
        });
        Schema::dropIfExists(SchemaQualifier::table('inpatient_patient_claim_mutexes'));
    }

    private function assertSyntheticMigrationBoundary(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Inpatient patient admission claim migration requires SIMULATION mode with synthetic-only data enforced.');
        }

        $patients = SchemaQualifier::table('patients');
        if (Schema::hasTable($patients) && DB::table($patients)->where('is_synthetic', false)->exists()) {
            throw new RuntimeException('Inpatient patient admission claim migration refused because non-synthetic patient data exists.');
        }
    }

    private function backfillAndVerifyClaims(): void
    {
        $encounters = SchemaQualifier::table('encounters');
        $active = DB::table($encounters)
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)
            ->orderBy('id')
            ->get(['id', 'patient_id']);

        $duplicate = $active->groupBy('patient_id')->first(fn ($rows): bool => $rows->count() > 1);
        if ($duplicate !== null) {
            throw new RuntimeException('Inpatient patient admission claim migration refused because one patient has multiple active inpatient encounters.');
        }

        foreach ($active as $row) {
            DB::table($encounters)->where('id', $row->id)->update([
                'active_inpatient_patient_id' => $row->patient_id,
            ]);
        }
    }

    private function addClaimConsistencyCheck(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $table = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('encounters'));
            $violation = <<<'SQL'
                (
                    NEW.care_setting = 'INPATIENT'
                    AND NEW.status IN ('REGISTERED', 'IN_EXAMINATION')
                    AND NEW.active_inpatient_patient_id IS NOT NEW.patient_id
                )
                OR
                (
                    NOT (NEW.care_setting = 'INPATIENT' AND NEW.status IN ('REGISTERED', 'IN_EXAMINATION'))
                    AND NEW.active_inpatient_patient_id IS NOT NULL
                )
                SQL;
            DB::statement('CREATE TRIGGER '.self::CLAIM_INSERT_TRIGGER.' BEFORE INSERT ON '.$table
                .' FOR EACH ROW WHEN '.$violation
                ." BEGIN SELECT RAISE(ABORT, 'invalid active inpatient patient claim'); END");
            DB::statement('CREATE TRIGGER '.self::CLAIM_UPDATE_TRIGGER.' BEFORE UPDATE ON '.$table
                .' FOR EACH ROW WHEN '.$violation
                ." BEGIN SELECT RAISE(ABORT, 'invalid active inpatient patient claim'); END");

            return;
        }

        $table = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('encounters'));
        $constraint = self::CLAIM_CHECK;
        DB::statement(<<<SQL
            ALTER TABLE {$table}
            ADD CONSTRAINT {$constraint} CHECK (
                (
                    care_setting = 'INPATIENT'
                    AND status IN ('REGISTERED', 'IN_EXAMINATION')
                    AND active_inpatient_patient_id = patient_id
                )
                OR
                (
                    (care_setting <> 'INPATIENT' OR status NOT IN ('REGISTERED', 'IN_EXAMINATION'))
                    AND active_inpatient_patient_id IS NULL
                )
            )
            SQL);
    }

    private function dropClaimConsistencyCheck(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS '.self::CLAIM_INSERT_TRIGGER);
            DB::unprepared('DROP TRIGGER IF EXISTS '.self::CLAIM_UPDATE_TRIGGER);
        } elseif (DB::connection()->getDriverName() === 'pgsql') {
            $table = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('encounters'));
            DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT IF EXISTS '.self::CLAIM_CHECK);
        } elseif (DB::connection()->getDriverName() === 'mysql') {
            $table = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('encounters'));
            DB::statement('ALTER TABLE '.$table.' DROP CHECK '.self::CLAIM_CHECK);
        }
    }
};
