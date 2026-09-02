<?php

use App\Support\Database\SchemaQualifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'inpatient_wards',
        'inpatient_ward_versions',
        'inpatient_beds',
        'inpatient_bed_versions',
        'inpatient_master_operation_receipts',
        'inpatient_master_code_reservations',
    ];

    public function up(): void
    {
        $this->assertSyntheticMigrationBoundary();

        Schema::create(SchemaQualifier::table('inpatient_wards'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->string('code', 64);
            $table->string('display_name', 120);
            $table->string('state', 16);
            $table->unsignedInteger('version');
            $table->timestamps();

            $table->unique('public_id', 'inpatient_wards_public_id_uq');
            $table->unique('code', 'inpatient_wards_code_uq');
            $table->index(['state', 'display_name'], 'inpatient_wards_state_name_idx');
        });

        Schema::create(SchemaQualifier::table('inpatient_ward_versions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('ward_id')->constrained(SchemaQualifier::table('inpatient_wards'), indexName: 'iwv_ward_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'iwv_actor_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('display_name', 120);
            $table->string('state', 16);
            $table->string('reason_code', 32);
            $table->string('before_digest', 64)->nullable();
            $table->string('after_digest', 64);
            $table->string('request_correlation_id', 26)->nullable();
            $table->timestamp('created_at');

            $table->unique('public_id', 'iwv_public_id_uq');
            $table->unique(['ward_id', 'version'], 'iwv_ward_version_uq');
        });

        Schema::create(SchemaQualifier::table('inpatient_beds'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('ward_id')->constrained(SchemaQualifier::table('inpatient_wards'), indexName: 'ib_ward_fk')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('display_name', 120);
            $table->string('room_label', 120);
            $table->string('service_class', 120);
            $table->string('state', 16);
            $table->unsignedInteger('version');
            $table->timestamps();

            $table->unique('public_id', 'inpatient_beds_public_id_uq');
            $table->unique('code', 'inpatient_beds_code_uq');
            $table->index(['ward_id', 'state', 'service_class'], 'inpatient_beds_ward_state_class_idx');
        });

        Schema::create(SchemaQualifier::table('inpatient_bed_versions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('bed_id')->constrained(SchemaQualifier::table('inpatient_beds'), indexName: 'ibv_bed_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'ibv_actor_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('display_name', 120);
            $table->string('room_label', 120);
            $table->string('service_class', 120);
            $table->string('state', 16);
            $table->string('reason_code', 32);
            $table->string('before_digest', 64)->nullable();
            $table->string('after_digest', 64);
            $table->string('request_correlation_id', 26)->nullable();
            $table->timestamp('created_at');

            $table->unique('public_id', 'ibv_public_id_uq');
            $table->unique(['bed_id', 'version'], 'ibv_bed_version_uq');
        });

        Schema::create(SchemaQualifier::table('inpatient_master_operation_receipts'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'imor_actor_fk')->restrictOnDelete();
            $table->string('operation', 64);
            $table->string('idempotency_key', 255);
            $table->string('payload_digest', 64);
            $table->string('result_type', 16);
            $table->string('result_public_id', 26);
            $table->string('request_correlation_id', 26)->nullable();
            $table->timestamp('completed_at')->useCurrent();
            $table->timestamps();

            $table->unique('public_id', 'imor_public_id_uq');
            $table->unique(['actor_user_id', 'operation', 'idempotency_key'], 'imor_actor_operation_key_uq');
        });

        Schema::create(SchemaQualifier::table('inpatient_master_code_reservations'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'imcr_actor_fk')->restrictOnDelete();
            $table->string('master_type', 16);
            $table->string('normalized_code', 64);
            $table->timestamp('created_at');

            $table->unique('public_id', 'imcr_public_id_uq');
            $table->unique(['master_type', 'normalized_code'], 'imcr_type_code_uq');
        });

        Schema::table(SchemaQualifier::table('encounters'), function (Blueprint $table): void {
            $table->foreignId('inpatient_bed_id')
                ->nullable()
                ->constrained(SchemaQualifier::table('inpatient_beds'), indexName: 'encounters_inpatient_bed_fk')
                ->restrictOnDelete();
        });

        $this->addChecks();
        $this->qualifyPostgresSequences();
    }

    public function down(): void
    {
        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit)
            && DB::table($audit)->where('action', 'like', 'master.inpatient.%')->exists()) {
            throw new RuntimeException('Refusing to roll back inpatient masters because correlated audit evidence remains.');
        }

        foreach ($this->tables as $table) {
            $qualified = SchemaQualifier::table($table);
            if (Schema::hasTable($qualified) && DB::table($qualified)->exists()) {
                throw new RuntimeException('Refusing to roll back inpatient masters because retained master evidence exists.');
            }
        }

        $encounters = SchemaQualifier::table('encounters');
        if (Schema::hasColumn($encounters, 'inpatient_bed_id')) {
            if (DB::table($encounters)->whereNotNull('inpatient_bed_id')->exists()) {
                throw new RuntimeException('Refusing to roll back inpatient masters because encounter bed references remain.');
            }
            Schema::table($encounters, function (Blueprint $table): void {
                $table->dropForeign('encounters_inpatient_bed_fk');
                $table->dropColumn('inpatient_bed_id');
            });
        }

        foreach (array_reverse($this->tables) as $table) {
            Schema::dropIfExists(SchemaQualifier::table($table));
        }
    }

    private function assertSyntheticMigrationBoundary(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Inpatient master migration requires SIMULATION mode with synthetic-only data enforced.');
        }

        $patients = SchemaQualifier::table('patients');
        if (Schema::hasTable($patients) && DB::table($patients)->where('is_synthetic', false)->exists()) {
            throw new RuntimeException('Inpatient master migration refused because non-synthetic patient data exists.');
        }
    }

    private function addChecks(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        foreach (['inpatient_wards', 'inpatient_beds'] as $name) {
            $table = $grammar->wrapTable(SchemaQualifier::table($name));
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name}_state_ck CHECK (state IN ('ACTIVE', 'RETIRED'))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name}_version_ck CHECK (version >= 1)");
        }
        foreach (['inpatient_ward_versions', 'inpatient_bed_versions'] as $name) {
            $table = $grammar->wrapTable(SchemaQualifier::table($name));
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name}_state_ck CHECK (state IN ('ACTIVE', 'RETIRED'))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name}_reason_ck CHECK (reason_code IN ('INITIAL_SETUP', 'DATA_CORRECTION', 'OPERATIONAL_CHANGE', 'RETIREMENT'))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name}_version_ck CHECK (version >= 1)");
        }
        $receipts = $grammar->wrapTable(SchemaQualifier::table('inpatient_master_operation_receipts'));
        DB::statement("ALTER TABLE {$receipts} ADD CONSTRAINT imor_result_type_ck CHECK (result_type IN ('WARD', 'BED'))");
        $reservations = $grammar->wrapTable(SchemaQualifier::table('inpatient_master_code_reservations'));
        DB::statement("ALTER TABLE {$reservations} ADD CONSTRAINT imcr_master_type_ck CHECK (master_type IN ('WARD', 'BED'))");
    }

    private function qualifyPostgresSequences(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = SchemaQualifier::primarySchema() ?? 'public';
        foreach ($this->tables as $table) {
            $qualifiedTable = $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier($table);
            $qualifiedSequence = $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier($table.'_id_seq');
            DB::statement(sprintf(
                'ALTER TABLE %s ALTER COLUMN id SET DEFAULT nextval(%s::regclass)',
                $qualifiedTable,
                $this->quoteLiteral($qualifiedSequence),
            ));
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
};
