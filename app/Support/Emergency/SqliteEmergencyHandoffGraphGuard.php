<?php

namespace App\Support\Emergency;

use App\Support\Database\SchemaQualifier;
use App\Support\Inpatient\InpatientLocationSchemaMutationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owns the SQLite-only handoff graph trigger lifecycle. SQLite table rebuilds
 * cannot rename encounters while another table's trigger references it, so
 * migrations must suspend and restore the trigger as one bounded operation.
 */
final class SqliteEmergencyHandoffGraphGuard
{
    public const TRIGGER = 'emergency_handoff_graph_insert';

    public static function aroundEncounterTableRebuild(callable $callback): mixed
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return $callback();
        }

        self::dropIfPresent();

        try {
            return $callback();
        } finally {
            self::createIfSupported();
        }
    }

    public static function createIfSupported(): void
    {
        if (! self::schemaSupportsGuard()) {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $handoffs = $grammar->wrapTable(SchemaQualifier::table('emergency_inpatient_handoffs'));
        $encounters = $grammar->wrapTable(SchemaQualifier::table('encounters'));
        $dispositions = $grammar->wrapTable(SchemaQualifier::table('emergency_dispositions'));
        $locations = $grammar->wrapTable(SchemaQualifier::table('inpatient_location_events'));
        $beds = $grammar->wrapTable(SchemaQualifier::table('inpatient_beds'));

        InpatientLocationSchemaMutationScope::run(function () use ($handoffs, $encounters, $dispositions, $locations, $beds): void {
            DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER);
            DB::statement('CREATE TRIGGER '.self::TRIGGER." BEFORE INSERT ON {$handoffs}
                FOR EACH ROW WHEN NOT EXISTS (
                    SELECT 1 FROM {$encounters} source
                    JOIN {$dispositions} disposition ON disposition.id=NEW.disposition_id
                    JOIN {$encounters} target ON target.id=NEW.target_encounter_id
                    JOIN {$locations} location ON location.id=NEW.inpatient_location_event_id
                    JOIN {$beds} bed ON bed.id=NEW.inpatient_bed_id
                    WHERE source.id=NEW.source_encounter_id
                      AND source.care_setting='EMERGENCY'
                      AND disposition.encounter_id=source.id
                      AND disposition.disposition_type='RAWAT_INAP'
                      AND target.care_setting='INPATIENT'
                      AND target.patient_id=source.patient_id
                      AND target.active_inpatient_patient_id=source.patient_id
                      AND target.inpatient_bed_id=bed.id
                      AND location.encounter_id=target.id
                      AND location.event_type='ADMISSION_LOCATION'
                      AND location.sequence=1
                      AND location.to_bed_public_id=bed.public_id
                      AND bed.state='ACTIVE'
                      AND NEW.inpatient_bed_version=bed.version
                      AND json_extract(NEW.bed_snapshot, '$.bed_public_id')=bed.public_id
                      AND json_extract(NEW.bed_snapshot, '$.bed_code')=bed.code
                ) BEGIN SELECT RAISE(ABORT, 'invalid emergency inpatient handoff graph'); END");
        });
    }

    public static function dropIfPresent(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        InpatientLocationSchemaMutationScope::run(
            fn (): bool => DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER),
        );
    }

    private static function schemaSupportsGuard(): bool
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return false;
        }

        foreach (['emergency_inpatient_handoffs', 'encounters', 'emergency_dispositions', 'inpatient_location_events', 'inpatient_beds'] as $table) {
            if (! Schema::hasTable(SchemaQualifier::table($table))) {
                return false;
            }
        }

        return Schema::hasColumn(SchemaQualifier::table('encounters'), 'active_inpatient_patient_id');
    }
}
