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
        'break_glass_requests',
        'break_glass_decisions',
        'break_glass_activations',
        'break_glass_revocations',
        'break_glass_session_bindings',
        'break_glass_subject_leases',
    ];

    public function up(): void
    {
        Schema::create('break_glass_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('subject_user_id')->constrained('users', indexName: 'bgr_subject_user_fk')->restrictOnDelete();
            $table->foreignId('requester_user_id')->constrained('users', indexName: 'bgr_requester_user_fk')->restrictOnDelete();
            $table->json('subject_snapshot');
            $table->json('requester_snapshot');
            $table->string('scope_key', 64);
            $table->json('capability_snapshot');
            $table->text('reason');
            $table->string('change_reference', 255);
            $table->unsignedSmallInteger('requested_ttl_minutes');
            $table->timestamp('requested_at', precision: 6);
            $table->timestamp('approval_deadline_at', precision: 6);
            $table->string('environment', 32);
            $table->string('release_sha', 64);
            $table->string('canonical_digest', 64);
            $table->timestamp('created_at', precision: 6);

            $table->unique('public_id', 'bgr_public_id_uq');
            $table->unique('canonical_digest', 'bgr_canonical_digest_uq');
            $table->index(['subject_user_id', 'requested_at'], 'bgr_subject_requested_idx');
            $table->index(['requester_user_id', 'requested_at'], 'bgr_requester_requested_idx');
            $table->index(['scope_key', 'requested_at'], 'bgr_scope_requested_idx');
        });

        Schema::create('break_glass_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('break_glass_request_id')
                ->constrained('break_glass_requests', indexName: 'bgd_request_fk')
                ->restrictOnDelete();
            $table->foreignId('approver_user_id')->constrained('users', indexName: 'bgd_approver_user_fk')->restrictOnDelete();
            $table->json('approver_snapshot');
            $table->string('decision', 16);
            $table->text('rationale');
            $table->string('assurance_method', 64);
            $table->timestamp('assured_at', precision: 6);
            $table->timestamp('decided_at', precision: 6);
            $table->string('request_digest', 64);
            $table->string('environment', 32);
            $table->string('release_sha', 64);
            $table->string('canonical_digest', 64);
            $table->timestamp('created_at', precision: 6);

            $table->unique('public_id', 'bgd_public_id_uq');
            $table->unique('break_glass_request_id', 'bgd_request_uq');
            $table->unique('canonical_digest', 'bgd_canonical_digest_uq');
            $table->index(['approver_user_id', 'decided_at'], 'bgd_approver_decided_idx');
        });

        Schema::create('break_glass_activations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('break_glass_request_id')
                ->constrained('break_glass_requests', indexName: 'bga_request_fk')
                ->restrictOnDelete();
            $table->foreignId('break_glass_decision_id')
                ->constrained('break_glass_decisions', indexName: 'bga_decision_fk')
                ->restrictOnDelete();
            $table->foreignId('subject_user_id')->constrained('users', indexName: 'bga_subject_user_fk')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->constrained('users', indexName: 'bga_approved_by_user_fk')->restrictOnDelete();
            $table->json('subject_snapshot');
            $table->json('approver_snapshot');
            $table->string('scope_key', 64);
            $table->json('capability_snapshot');
            $table->timestamp('starts_at', precision: 6);
            $table->timestamp('expires_at', precision: 6);
            $table->unsignedSmallInteger('nonce_version');
            $table->string('nonce_digest', 64);
            $table->string('request_digest', 64);
            $table->string('decision_digest', 64);
            $table->string('environment', 32);
            $table->string('release_sha', 64);
            $table->string('canonical_digest', 64);
            $table->timestamp('created_at', precision: 6);

            $table->unique('public_id', 'bga_public_id_uq');
            $table->unique('break_glass_request_id', 'bga_request_uq');
            $table->unique('break_glass_decision_id', 'bga_decision_uq');
            $table->unique('nonce_digest', 'bga_nonce_digest_uq');
            $table->unique('canonical_digest', 'bga_canonical_digest_uq');
            $table->index(['subject_user_id', 'expires_at'], 'bga_subject_expires_idx');
        });

        Schema::create('break_glass_revocations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('break_glass_activation_id')
                ->constrained('break_glass_activations', indexName: 'bgrv_activation_fk')
                ->restrictOnDelete();
            $table->foreignId('revoker_user_id')->nullable()->constrained('users', indexName: 'bgrv_revoker_user_fk')->restrictOnDelete();
            $table->string('revoker_type', 16);
            $table->string('revoker_reference', 255);
            $table->json('revoker_snapshot');
            $table->text('reason');
            $table->string('change_reference', 255);
            $table->timestamp('revoked_at', precision: 6);
            $table->string('activation_digest', 64);
            $table->string('environment', 32);
            $table->string('release_sha', 64);
            $table->string('canonical_digest', 64);
            $table->timestamp('created_at', precision: 6);

            $table->unique('public_id', 'bgrv_public_id_uq');
            $table->unique('break_glass_activation_id', 'bgrv_activation_uq');
            $table->unique('canonical_digest', 'bgrv_canonical_digest_uq');
            $table->index(['revoker_user_id', 'revoked_at'], 'bgrv_revoker_revoked_idx');
        });

        Schema::create('break_glass_session_bindings', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('break_glass_activation_id')
                ->constrained('break_glass_activations', indexName: 'bgsb_activation_fk')
                ->restrictOnDelete();
            $table->foreignId('subject_user_id')->constrained('users', indexName: 'bgsb_subject_user_fk')->restrictOnDelete();
            $table->string('session_reference_hmac', 64);
            $table->string('assurance_method', 64);
            $table->timestamp('assured_at', precision: 6);
            $table->timestamp('bound_at', precision: 6);
            $table->string('activation_digest', 64);
            $table->string('environment', 32);
            $table->string('release_sha', 64);
            $table->string('canonical_digest', 64);
            $table->timestamp('created_at', precision: 6);

            $table->unique('public_id', 'bgsb_public_id_uq');
            $table->unique('break_glass_activation_id', 'bgsb_activation_uq');
            $table->unique('session_reference_hmac', 'bgsb_session_hmac_uq');
            $table->unique('canonical_digest', 'bgsb_canonical_digest_uq');
            $table->index(['subject_user_id', 'bound_at'], 'bgsb_subject_bound_idx');
        });

        Schema::create('break_glass_subject_leases', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('subject_user_id')->constrained('users', indexName: 'bgsl_subject_user_fk')->restrictOnDelete();
            $table->foreignId('break_glass_activation_id')
                ->constrained('break_glass_activations', indexName: 'bgsl_activation_fk')
                ->restrictOnDelete();
            $table->timestamp('expires_at', precision: 6);
            $table->timestamps(precision: 6);

            $table->unique('public_id', 'bgsl_public_id_uq');
            $table->unique('subject_user_id', 'bgsl_subject_uq');
            $table->unique('break_glass_activation_id', 'bgsl_activation_uq');
            $table->index('expires_at', 'bgsl_expires_idx');
        });

        $this->qualifyPostgresSequences();
    }

    public function down(): void
    {
        Schema::dropIfExists('break_glass_subject_leases');
        Schema::dropIfExists('break_glass_session_bindings');
        Schema::dropIfExists('break_glass_revocations');
        Schema::dropIfExists('break_glass_activations');
        Schema::dropIfExists('break_glass_decisions');
        Schema::dropIfExists('break_glass_requests');
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
