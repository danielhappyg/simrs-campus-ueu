<?php

use App\Support\Database\SchemaQualifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertSyntheticMigrationBoundary();

        Schema::create(SchemaQualifier::table('encounter_cancellations'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('encounter_id')
                ->constrained('encounters', indexName: 'enc_cancel_encounter_fk')
                ->cascadeOnDelete();
            $table->foreignId('cancelled_by_user_id')
                ->constrained('users', indexName: 'enc_cancel_actor_fk')
                ->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->string('note', 500)->nullable();
            $table->string('idempotency_key', 255);
            $table->string('payload_digest', 64);
            $table->string('request_correlation_id', 26)->nullable();
            $table->timestamp('cancelled_at')->useCurrent();
            $table->timestamps();

            $table->unique('public_id', 'enc_cancel_public_id_uq');
            $table->unique('encounter_id', 'enc_cancel_encounter_uq');
            $table->unique(
                ['cancelled_by_user_id', 'idempotency_key'],
                'enc_cancel_actor_key_uq',
            );
            $table->index(['reason_code', 'cancelled_at'], 'enc_cancel_reason_time_idx');
        });

        $this->qualifyPostgresSequence();
    }

    public function down(): void
    {
        $table = SchemaQualifier::table('encounter_cancellations');

        if (Schema::hasTable($table) && DB::table($table)->exists()) {
            throw new RuntimeException(
                'Refusing to roll back encounter cancellations because retained cancellation evidence exists.',
            );
        }

        Schema::dropIfExists($table);
    }

    private function assertSyntheticMigrationBoundary(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException(
                'Encounter cancellation migration requires SIMULATION mode with synthetic-only data enforced.',
            );
        }

        $patients = SchemaQualifier::table('patients');
        if (Schema::hasTable($patients) && DB::table($patients)->where('is_synthetic', false)->exists()) {
            throw new RuntimeException(
                'Encounter cancellation migration refused: non-synthetic patient data requires a separately reviewed migration plan.',
            );
        }
    }

    private function qualifyPostgresSequence(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = SchemaQualifier::primarySchema() ?? 'public';
        $qualifiedTable = $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier('encounter_cancellations');
        $qualifiedSequence = $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier('encounter_cancellations_id_seq');

        DB::statement(sprintf(
            'ALTER TABLE %s ALTER COLUMN id SET DEFAULT nextval(%s::regclass)',
            $qualifiedTable,
            $this->quoteLiteral($qualifiedSequence),
        ));
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
