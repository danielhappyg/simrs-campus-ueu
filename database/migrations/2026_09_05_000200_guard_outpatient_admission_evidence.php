<?php

use App\Support\Clinical\OutpatientAdmissionEvidenceGuard;
use App\Support\Database\SchemaQualifier;
use App\Support\Inpatient\InpatientLocationSchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        OutpatientAdmissionEvidenceGuard::install();
        $encounters = $this->table('encounters');
        $patients = $this->table('patients');
        $documents = $this->table('outpatient_clinical_documents');
        $versions = $this->table('outpatient_clinical_document_versions');
        $dispositions = $this->table('outpatient_dispositions');
        $handoffs = $this->table('outpatient_inpatient_handoffs');
        $locations = $this->table('inpatient_location_events');
        $beds = $this->table('inpatient_beds');

        $validDisposition = "EXISTS (
            SELECT 1 FROM {$encounters} encounter
            JOIN {$patients} patient ON patient.id=encounter.patient_id
            JOIN {$versions} version ON version.id=NEW.medical_document_version_id
            JOIN {$documents} document ON document.id=version.outpatient_clinical_document_id
            WHERE encounter.id=NEW.encounter_id AND encounter.care_setting='OUTPATIENT'
            AND encounter.status IN ('REGISTERED','IN_EXAMINATION','READY_FOR_RM')
            AND patient.is_synthetic=TRUE AND document.encounter_id=encounter.id
            AND document.document_type='MEDICAL_ASSESSMENT' AND document.document_state='FINAL'
            AND version.document_state='FINAL' AND version.version=document.version
        ) AND NEW.disposition_type IN ('KONTROL_ULANG','SEMBUH','RAWAT_INAP')
        AND NOT EXISTS (SELECT 1 FROM {$handoffs} handoff WHERE handoff.source_encounter_id=NEW.encounter_id)
        AND ((NEW.version=1 AND NEW.prior_disposition_id IS NULL AND NEW.correction_reason IS NULL
              AND NOT EXISTS (SELECT 1 FROM {$dispositions} prior WHERE prior.encounter_id=NEW.encounter_id))
            OR (NEW.version>1 AND NEW.correction_reason IS NOT NULL AND EXISTS (
                SELECT 1 FROM {$dispositions} prior WHERE prior.id=NEW.prior_disposition_id
                AND prior.encounter_id=NEW.encounter_id AND prior.version=NEW.version-1
                AND prior.physician_user_id=NEW.physician_user_id
                AND prior.medical_document_version_id=NEW.medical_document_version_id
                AND prior.content_digest=NEW.prior_disposition_digest
                AND NOT EXISTS (SELECT 1 FROM {$dispositions} later WHERE later.encounter_id=prior.encounter_id AND later.version>prior.version)
            )))";
        $validHandoff = "EXISTS (
            SELECT 1 FROM {$encounters} source
            JOIN {$patients} patient ON patient.id=source.patient_id
            JOIN {$dispositions} disposition ON disposition.id=NEW.disposition_id
            JOIN {$encounters} target ON target.id=NEW.target_encounter_id
            JOIN {$locations} location ON location.id=NEW.inpatient_location_event_id
            JOIN {$beds} bed ON bed.id=target.inpatient_bed_id
            WHERE source.id=NEW.source_encounter_id AND source.care_setting='OUTPATIENT'
            AND source.status IN ('IN_EXAMINATION','READY_FOR_RM') AND patient.is_synthetic=TRUE
            AND disposition.encounter_id=source.id AND disposition.disposition_type='RAWAT_INAP'
            AND NOT EXISTS (SELECT 1 FROM {$dispositions} later WHERE later.encounter_id=source.id AND later.version>disposition.version)
            AND target.care_setting='INPATIENT' AND target.continue_from='DARI_RJ'
            AND target.patient_id=source.patient_id AND target.status='REGISTERED'
            AND location.encounter_id=target.id AND location.event_type='ADMISSION_LOCATION'
            AND location.to_bed_public_id=bed.public_id
        )";
        $this->installGraphGuard('oadm_disposition_graph', 'outpatient_dispositions', $validDisposition);
        $this->installGraphGuard('oadm_handoff_graph', 'outpatient_inpatient_handoffs', $validHandoff);
    }

    public function down(): void
    {
        foreach (OutpatientAdmissionEvidenceGuard::TABLES as $table) {
            if (DB::table(SchemaQualifier::table($table))->exists()) {
                throw new RuntimeException('Cannot remove outpatient guards while signed admission evidence exists.');
            }
        }
        foreach (['oadm_disposition_graph' => 'outpatient_dispositions', 'oadm_handoff_graph' => 'outpatient_inpatient_handoffs'] as $name => $table) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("DROP TRIGGER IF EXISTS {$name} ON ".$this->table($table));
                DB::statement('DROP FUNCTION IF EXISTS '.$this->table($name).'()');
            } else {
                DB::statement("DROP TRIGGER IF EXISTS {$name}");
            }
        }
        OutpatientAdmissionEvidenceGuard::remove();
    }

    private function table(string $table): string
    {
        return DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table($table));
    }

    private function installGraphGuard(string $name, string $table, string $validGraph): void
    {
        InpatientLocationSchemaMutationScope::run(fn () => $this->createGraphTrigger($name, $table, $validGraph));
    }

    private function createGraphTrigger(string $name, string $table, string $validGraph): void
    {
        $qualified = $this->table($table);
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            $function = $this->table($name);
            DB::statement("CREATE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NOT ({$validGraph}) THEN RAISE EXCEPTION 'invalid outpatient admission graph'; END IF; RETURN NEW; END $$");
            DB::statement("CREATE TRIGGER {$name} BEFORE INSERT ON {$qualified} FOR EACH ROW EXECUTE FUNCTION {$function}()");
        } elseif ($driver === 'mysql') {
            DB::statement("CREATE TRIGGER {$name} BEFORE INSERT ON {$qualified} FOR EACH ROW BEGIN IF NOT ({$validGraph}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='invalid outpatient admission graph'; END IF; END");
        } elseif ($driver === 'sqlite') {
            DB::statement("CREATE TRIGGER {$name} BEFORE INSERT ON {$qualified} WHEN NOT ({$validGraph}) BEGIN SELECT RAISE(ABORT, 'invalid outpatient admission graph'); END");
        }
    }
};
