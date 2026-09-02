<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Inpatient\InpatientLocationSchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'inpatient_location_events';

    private const INSERT_TRIGGER = 'ile_bed_version_insert_trg';

    private const POSTGRES_FUNCTION = 'guard_inpatient_location_bed_version';

    public function up(): void
    {
        $this->assertSyntheticMigrationBoundary();

        InpatientLocationSchemaMutationScope::run(function (): void {
            $this->addColumns();

            $this->addShapeCheck();
            $this->createInsertGuard();
        });
    }

    public function down(): void
    {
        $events = SchemaQualifier::table(self::TABLE);
        if (DB::table($events)
            ->whereNotNull('from_inpatient_bed_version_id')
            ->orWhereNotNull('to_inpatient_bed_version_id')
            ->exists()) {
            throw new RuntimeException('Refusing to remove inpatient bed-version provenance while prospective location evidence remains.');
        }

        InpatientLocationSchemaMutationScope::run(function () use ($events): void {
            $this->dropInsertGuard();
            $this->dropShapeCheck();
            $this->dropColumns($events);
        });
    }

    private function addColumns(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $grammar = DB::connection()->getQueryGrammar();
            $events = $grammar->wrapTable(SchemaQualifier::table(self::TABLE));
            $versions = $grammar->wrapTable(SchemaQualifier::table('inpatient_bed_versions'));
            foreach (['from', 'to'] as $prefix) {
                DB::statement("ALTER TABLE {$events} ADD COLUMN {$prefix}_inpatient_bed_version_id INTEGER NULL REFERENCES {$versions} (id) ON DELETE RESTRICT");
                DB::statement("ALTER TABLE {$events} ADD COLUMN {$prefix}_inpatient_bed_version_public_id VARCHAR(26) NULL");
                DB::statement("ALTER TABLE {$events} ADD COLUMN {$prefix}_inpatient_bed_version INTEGER NULL");
                DB::statement("ALTER TABLE {$events} ADD COLUMN {$prefix}_inpatient_bed_after_digest VARCHAR(64) NULL");
            }

            return;
        }

        Schema::table(SchemaQualifier::table(self::TABLE), function (Blueprint $table): void {
            $table->foreignId('from_inpatient_bed_version_id')->nullable()
                ->constrained(SchemaQualifier::table('inpatient_bed_versions'), indexName: 'ile_from_bed_version_fk')
                ->restrictOnDelete();
            $table->string('from_inpatient_bed_version_public_id', 26)->nullable();
            $table->unsignedInteger('from_inpatient_bed_version')->nullable();
            $table->string('from_inpatient_bed_after_digest', 64)->nullable();

            $table->foreignId('to_inpatient_bed_version_id')->nullable()
                ->constrained(SchemaQualifier::table('inpatient_bed_versions'), indexName: 'ile_to_bed_version_fk')
                ->restrictOnDelete();
            $table->string('to_inpatient_bed_version_public_id', 26)->nullable();
            $table->unsignedInteger('to_inpatient_bed_version')->nullable();
            $table->string('to_inpatient_bed_after_digest', 64)->nullable();
        });
    }

    private function dropColumns(string $events): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            foreach ([
                'from_inpatient_bed_version_id',
                'from_inpatient_bed_version_public_id',
                'from_inpatient_bed_version',
                'from_inpatient_bed_after_digest',
                'to_inpatient_bed_version_id',
                'to_inpatient_bed_version_public_id',
                'to_inpatient_bed_version',
                'to_inpatient_bed_after_digest',
            ] as $column) {
                DB::statement("ALTER TABLE {$events} DROP COLUMN {$column}");
            }

            return;
        }

        Schema::table($events, function (Blueprint $table): void {
            $table->dropForeign('ile_from_bed_version_fk');
            $table->dropForeign('ile_to_bed_version_fk');
            $table->dropColumn([
                'from_inpatient_bed_version_id',
                'from_inpatient_bed_version_public_id',
                'from_inpatient_bed_version',
                'from_inpatient_bed_after_digest',
                'to_inpatient_bed_version_id',
                'to_inpatient_bed_version_public_id',
                'to_inpatient_bed_version',
                'to_inpatient_bed_after_digest',
            ]);
        });
    }

    private function assertSyntheticMigrationBoundary(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Inpatient bed-version provenance migration requires synthetic-only SIMULATION mode.');
        }
    }

    private function addShapeCheck(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            return;
        }

        $events = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table(self::TABLE));
        $fromNull = $this->allNull('from');
        $fromPresent = $this->allPresent('from');
        $toNull = $this->allNull('to');
        $toPresent = $this->allPresent('to');
        DB::statement("ALTER TABLE {$events} ADD CONSTRAINT ile_bed_version_shape_ck CHECK ((({$fromNull}) OR ({$fromPresent})) AND (({$toNull}) OR ({$toPresent})))");
    }

    private function dropShapeCheck(): void
    {
        $events = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table(self::TABLE));
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$events} DROP CONSTRAINT IF EXISTS ile_bed_version_shape_ck");
        } elseif (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE {$events} DROP CHECK ile_bed_version_shape_ck");
        }
    }

    private function createInsertGuard(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->createPostgresInsertGuard(),
            'mysql' => $this->createMysqlInsertGuard(),
            'sqlite' => $this->createSqliteInsertGuard(),
            default => throw new RuntimeException('Unsupported database driver for inpatient bed-version provenance.'),
        };
    }

    private function dropInsertGuard(): void
    {
        $driver = DB::connection()->getDriverName();
        $events = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table(self::TABLE));
        if ($driver === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS '.$this->quotePostgresIdentifier(self::INSERT_TRIGGER)." ON {$events}");
            $schema = SchemaQualifier::primarySchema() ?? 'public';
            DB::statement('DROP FUNCTION IF EXISTS '.$this->quotePostgresIdentifier($schema).'.'.$this->quotePostgresIdentifier(self::POSTGRES_FUNCTION).'()');
        } elseif ($driver === 'mysql') {
            DB::statement('DROP TRIGGER IF EXISTS '.$this->quoteMysqlIdentifier(self::INSERT_TRIGGER));
        } elseif ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS '.$this->quoteSqliteIdentifier(self::INSERT_TRIGGER));
        }
    }

    private function createPostgresInsertGuard(): void
    {
        $schema = SchemaQualifier::primarySchema() ?? 'public';
        $function = $this->quotePostgresIdentifier($schema).'.'.$this->quotePostgresIdentifier(self::POSTGRES_FUNCTION);
        $events = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table(self::TABLE));
        $versions = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('inpatient_bed_versions'));
        $beds = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('inpatient_beds'));
        $wards = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('inpatient_wards'));

        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION {$function}()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$function\$
            BEGIN
                IF NEW.to_inpatient_bed_version_id IS NULL
                    OR NEW.to_inpatient_bed_version_public_id IS NULL
                    OR NEW.to_inpatient_bed_version IS NULL
                    OR NEW.to_inpatient_bed_after_digest IS NULL THEN
                    RAISE EXCEPTION 'new location events require exact destination bed-version provenance' USING ERRCODE = '23514';
                END IF;
                IF (NEW.from_inpatient_bed_version_id IS NULL) <> (NEW.from_inpatient_bed_version_public_id IS NULL)
                    OR (NEW.from_inpatient_bed_version_id IS NULL) <> (NEW.from_inpatient_bed_version IS NULL)
                    OR (NEW.from_inpatient_bed_version_id IS NULL) <> (NEW.from_inpatient_bed_after_digest IS NULL) THEN
                    RAISE EXCEPTION 'source bed-version provenance must be wholly null or wholly present' USING ERRCODE = '23514';
                END IF;
                IF NEW.event_type = 'ADMISSION_LOCATION' AND NEW.from_inpatient_bed_version_id IS NOT NULL THEN
                    RAISE EXCEPTION 'admission source bed-version provenance must be null' USING ERRCODE = '23514';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM {$versions} version_row
                    JOIN {$beds} bed ON bed.id = version_row.bed_id
                    JOIN {$wards} ward ON ward.id = bed.ward_id
                    WHERE version_row.id = NEW.to_inpatient_bed_version_id
                      AND version_row.public_id = NEW.to_inpatient_bed_version_public_id
                      AND version_row.version = NEW.to_inpatient_bed_version
                      AND version_row.after_digest = NEW.to_inpatient_bed_after_digest
                      AND version_row.display_name = NEW.to_bed_display_name
                      AND version_row.room_label = NEW.to_room_label
                      AND version_row.service_class = NEW.to_service_class
                      AND bed.public_id = NEW.to_bed_public_id
                      AND bed.code = NEW.to_bed_code
                      AND ward.public_id = NEW.to_ward_public_id
                      AND ward.code = NEW.to_ward_code
                ) THEN
                    RAISE EXCEPTION 'destination bed-version provenance does not resolve exactly' USING ERRCODE = '23514';
                END IF;
                IF NEW.from_inpatient_bed_version_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM {$versions} version_row
                    JOIN {$beds} bed ON bed.id = version_row.bed_id
                    JOIN {$wards} ward ON ward.id = bed.ward_id
                    WHERE version_row.id = NEW.from_inpatient_bed_version_id
                      AND version_row.public_id = NEW.from_inpatient_bed_version_public_id
                      AND version_row.version = NEW.from_inpatient_bed_version
                      AND version_row.after_digest = NEW.from_inpatient_bed_after_digest
                      AND version_row.display_name = NEW.from_bed_display_name
                      AND version_row.room_label = NEW.from_room_label
                      AND version_row.service_class = NEW.from_service_class
                      AND bed.public_id = NEW.from_bed_public_id
                      AND bed.code = NEW.from_bed_code
                      AND ward.public_id = NEW.from_ward_public_id
                      AND ward.code = NEW.from_ward_code
                ) THEN
                    RAISE EXCEPTION 'source bed-version provenance does not resolve exactly' USING ERRCODE = '23514';
                END IF;
                IF EXISTS (SELECT 1 FROM {$events} prior WHERE prior.encounter_id = NEW.encounter_id AND prior.sequence = NEW.sequence - 1)
                    AND NOT EXISTS (
                        SELECT 1 FROM {$events} prior
                        WHERE prior.encounter_id = NEW.encounter_id AND prior.sequence = NEW.sequence - 1
                          AND prior.to_ward_public_id IS NOT DISTINCT FROM NEW.from_ward_public_id
                          AND prior.to_ward_code IS NOT DISTINCT FROM NEW.from_ward_code
                          AND prior.to_ward_display_name IS NOT DISTINCT FROM NEW.from_ward_display_name
                          AND prior.to_bed_public_id IS NOT DISTINCT FROM NEW.from_bed_public_id
                          AND prior.to_bed_code IS NOT DISTINCT FROM NEW.from_bed_code
                          AND prior.to_bed_display_name IS NOT DISTINCT FROM NEW.from_bed_display_name
                          AND prior.to_room_label IS NOT DISTINCT FROM NEW.from_room_label
                          AND prior.to_service_class IS NOT DISTINCT FROM NEW.from_service_class
                          AND prior.to_inpatient_bed_version_id IS NOT DISTINCT FROM NEW.from_inpatient_bed_version_id
                          AND prior.to_inpatient_bed_version_public_id IS NOT DISTINCT FROM NEW.from_inpatient_bed_version_public_id
                          AND prior.to_inpatient_bed_version IS NOT DISTINCT FROM NEW.from_inpatient_bed_version
                          AND prior.to_inpatient_bed_after_digest IS NOT DISTINCT FROM NEW.from_inpatient_bed_after_digest
                    ) THEN
                    RAISE EXCEPTION 'location source snapshot must equal the prior destination snapshot' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$function\$;
            SQL);

        DB::statement('CREATE TRIGGER '.$this->quotePostgresIdentifier(self::INSERT_TRIGGER)." BEFORE INSERT ON {$events} FOR EACH ROW EXECUTE FUNCTION {$function}()");
    }

    private function createMysqlInsertGuard(): void
    {
        $events = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table(self::TABLE));
        $versions = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('inpatient_bed_versions'));
        $beds = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('inpatient_beds'));
        $wards = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('inpatient_wards'));
        $trigger = $this->quoteMysqlIdentifier(self::INSERT_TRIGGER);

        DB::statement(<<<SQL
            CREATE TRIGGER {$trigger}
            BEFORE INSERT ON {$events}
            FOR EACH ROW
            BEGIN
                IF NEW.to_inpatient_bed_version_id IS NULL
                    OR NEW.to_inpatient_bed_version_public_id IS NULL
                    OR NEW.to_inpatient_bed_version IS NULL
                    OR NEW.to_inpatient_bed_after_digest IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'new location events require exact destination bed-version provenance';
                END IF;
                IF (NEW.from_inpatient_bed_version_id IS NULL) <> (NEW.from_inpatient_bed_version_public_id IS NULL)
                    OR (NEW.from_inpatient_bed_version_id IS NULL) <> (NEW.from_inpatient_bed_version IS NULL)
                    OR (NEW.from_inpatient_bed_version_id IS NULL) <> (NEW.from_inpatient_bed_after_digest IS NULL) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'source bed-version provenance must be wholly null or wholly present';
                END IF;
                IF NEW.event_type = 'ADMISSION_LOCATION' AND NEW.from_inpatient_bed_version_id IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'admission source bed-version provenance must be null';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM {$versions} version_row
                    JOIN {$beds} bed ON bed.id = version_row.bed_id
                    JOIN {$wards} ward ON ward.id = bed.ward_id
                    WHERE version_row.id = NEW.to_inpatient_bed_version_id
                      AND version_row.public_id = NEW.to_inpatient_bed_version_public_id
                      AND version_row.version = NEW.to_inpatient_bed_version
                      AND version_row.after_digest = NEW.to_inpatient_bed_after_digest
                      AND version_row.display_name = NEW.to_bed_display_name
                      AND version_row.room_label = NEW.to_room_label
                      AND version_row.service_class = NEW.to_service_class
                      AND bed.public_id = NEW.to_bed_public_id
                      AND bed.code = NEW.to_bed_code
                      AND ward.public_id = NEW.to_ward_public_id
                      AND ward.code = NEW.to_ward_code
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'destination bed-version provenance does not resolve exactly';
                END IF;
                IF NEW.from_inpatient_bed_version_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM {$versions} version_row
                    JOIN {$beds} bed ON bed.id = version_row.bed_id
                    JOIN {$wards} ward ON ward.id = bed.ward_id
                    WHERE version_row.id = NEW.from_inpatient_bed_version_id
                      AND version_row.public_id = NEW.from_inpatient_bed_version_public_id
                      AND version_row.version = NEW.from_inpatient_bed_version
                      AND version_row.after_digest = NEW.from_inpatient_bed_after_digest
                      AND version_row.display_name = NEW.from_bed_display_name
                      AND version_row.room_label = NEW.from_room_label
                      AND version_row.service_class = NEW.from_service_class
                      AND bed.public_id = NEW.from_bed_public_id
                      AND bed.code = NEW.from_bed_code
                      AND ward.public_id = NEW.from_ward_public_id
                      AND ward.code = NEW.from_ward_code
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'source bed-version provenance does not resolve exactly';
                END IF;
                IF EXISTS (SELECT 1 FROM {$events} prior WHERE prior.encounter_id = NEW.encounter_id AND prior.sequence = NEW.sequence - 1)
                    AND NOT EXISTS (
                        SELECT 1 FROM {$events} prior
                        WHERE prior.encounter_id = NEW.encounter_id AND prior.sequence = NEW.sequence - 1
                          AND prior.to_ward_public_id <=> NEW.from_ward_public_id
                          AND prior.to_ward_code <=> NEW.from_ward_code
                          AND prior.to_ward_display_name <=> NEW.from_ward_display_name
                          AND prior.to_bed_public_id <=> NEW.from_bed_public_id
                          AND prior.to_bed_code <=> NEW.from_bed_code
                          AND prior.to_bed_display_name <=> NEW.from_bed_display_name
                          AND prior.to_room_label <=> NEW.from_room_label
                          AND prior.to_service_class <=> NEW.from_service_class
                          AND prior.to_inpatient_bed_version_id <=> NEW.from_inpatient_bed_version_id
                          AND prior.to_inpatient_bed_version_public_id <=> NEW.from_inpatient_bed_version_public_id
                          AND prior.to_inpatient_bed_version <=> NEW.from_inpatient_bed_version
                          AND prior.to_inpatient_bed_after_digest <=> NEW.from_inpatient_bed_after_digest
                    ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'location source snapshot must equal the prior destination snapshot';
                END IF;
            END
            SQL);
    }

    private function createSqliteInsertGuard(): void
    {
        $events = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table(self::TABLE));
        $versions = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('inpatient_bed_versions'));
        $beds = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('inpatient_beds'));
        $wards = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('inpatient_wards'));
        $trigger = $this->quoteSqliteIdentifier(self::INSERT_TRIGGER);

        DB::statement(<<<SQL
            CREATE TRIGGER {$trigger}
            BEFORE INSERT ON {$events}
            FOR EACH ROW
            BEGIN
                SELECT CASE WHEN NEW.to_inpatient_bed_version_id IS NULL
                    OR NEW.to_inpatient_bed_version_public_id IS NULL
                    OR NEW.to_inpatient_bed_version IS NULL
                    OR NEW.to_inpatient_bed_after_digest IS NULL
                    THEN RAISE(ABORT, 'new location events require exact destination bed-version provenance') END;
                SELECT CASE WHEN (NEW.from_inpatient_bed_version_id IS NULL) <> (NEW.from_inpatient_bed_version_public_id IS NULL)
                    OR (NEW.from_inpatient_bed_version_id IS NULL) <> (NEW.from_inpatient_bed_version IS NULL)
                    OR (NEW.from_inpatient_bed_version_id IS NULL) <> (NEW.from_inpatient_bed_after_digest IS NULL)
                    THEN RAISE(ABORT, 'source bed-version provenance must be wholly null or wholly present') END;
                SELECT CASE WHEN NEW.event_type = 'ADMISSION_LOCATION' AND NEW.from_inpatient_bed_version_id IS NOT NULL
                    THEN RAISE(ABORT, 'admission source bed-version provenance must be null') END;
                SELECT CASE WHEN NOT EXISTS (
                    SELECT 1 FROM {$versions} version_row
                    JOIN {$beds} bed ON bed.id = version_row.bed_id
                    JOIN {$wards} ward ON ward.id = bed.ward_id
                    WHERE version_row.id = NEW.to_inpatient_bed_version_id
                      AND version_row.public_id = NEW.to_inpatient_bed_version_public_id
                      AND version_row.version = NEW.to_inpatient_bed_version
                      AND version_row.after_digest = NEW.to_inpatient_bed_after_digest
                      AND version_row.display_name = NEW.to_bed_display_name
                      AND version_row.room_label = NEW.to_room_label
                      AND version_row.service_class = NEW.to_service_class
                      AND bed.public_id = NEW.to_bed_public_id
                      AND bed.code = NEW.to_bed_code
                      AND ward.public_id = NEW.to_ward_public_id
                      AND ward.code = NEW.to_ward_code
                ) THEN RAISE(ABORT, 'destination bed-version provenance does not resolve exactly') END;
                SELECT CASE WHEN NEW.from_inpatient_bed_version_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM {$versions} version_row
                    JOIN {$beds} bed ON bed.id = version_row.bed_id
                    JOIN {$wards} ward ON ward.id = bed.ward_id
                    WHERE version_row.id = NEW.from_inpatient_bed_version_id
                      AND version_row.public_id = NEW.from_inpatient_bed_version_public_id
                      AND version_row.version = NEW.from_inpatient_bed_version
                      AND version_row.after_digest = NEW.from_inpatient_bed_after_digest
                      AND version_row.display_name = NEW.from_bed_display_name
                      AND version_row.room_label = NEW.from_room_label
                      AND version_row.service_class = NEW.from_service_class
                      AND bed.public_id = NEW.from_bed_public_id
                      AND bed.code = NEW.from_bed_code
                      AND ward.public_id = NEW.from_ward_public_id
                      AND ward.code = NEW.from_ward_code
                ) THEN RAISE(ABORT, 'source bed-version provenance does not resolve exactly') END;
                SELECT CASE WHEN EXISTS (SELECT 1 FROM {$events} prior WHERE prior.encounter_id = NEW.encounter_id AND prior.sequence = NEW.sequence - 1)
                    AND NOT EXISTS (
                        SELECT 1 FROM {$events} prior
                        WHERE prior.encounter_id = NEW.encounter_id AND prior.sequence = NEW.sequence - 1
                          AND prior.to_ward_public_id IS NEW.from_ward_public_id
                          AND prior.to_ward_code IS NEW.from_ward_code
                          AND prior.to_ward_display_name IS NEW.from_ward_display_name
                          AND prior.to_bed_public_id IS NEW.from_bed_public_id
                          AND prior.to_bed_code IS NEW.from_bed_code
                          AND prior.to_bed_display_name IS NEW.from_bed_display_name
                          AND prior.to_room_label IS NEW.from_room_label
                          AND prior.to_service_class IS NEW.from_service_class
                          AND prior.to_inpatient_bed_version_id IS NEW.from_inpatient_bed_version_id
                          AND prior.to_inpatient_bed_version_public_id IS NEW.from_inpatient_bed_version_public_id
                          AND prior.to_inpatient_bed_version IS NEW.from_inpatient_bed_version
                          AND prior.to_inpatient_bed_after_digest IS NEW.from_inpatient_bed_after_digest
                    ) THEN RAISE(ABORT, 'location source snapshot must equal the prior destination snapshot') END;
            END
            SQL);
    }

    private function allNull(string $prefix): string
    {
        return implode(' AND ', array_map(
            static fn (string $suffix): string => $prefix.'_inpatient_bed_'.$suffix.' IS NULL',
            ['version_id', 'version_public_id', 'version', 'after_digest'],
        ));
    }

    private function allPresent(string $prefix): string
    {
        return implode(' AND ', [
            $prefix.'_inpatient_bed_version_id IS NOT NULL',
            $prefix.'_inpatient_bed_version_public_id IS NOT NULL',
            $prefix.'_inpatient_bed_version >= 1',
            'CHAR_LENGTH('.$prefix.'_inpatient_bed_version_public_id) = 26',
            'CHAR_LENGTH('.$prefix.'_inpatient_bed_after_digest) = 64',
        ]);
    }

    private function quotePostgresIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function quoteMysqlIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function quoteSqliteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
