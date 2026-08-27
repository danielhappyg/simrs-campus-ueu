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
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('teaching_access_epoch')->default(0);
            $table->unsignedBigInteger('teaching_access_mutex')->default(0);
            $table->string('teaching_access_roster_key', 64)->nullable()->unique('users_teaching_access_roster_key_uq');
            $table->string('teaching_access_lease_public_id', 26)->nullable();
            $table->unsignedBigInteger('teaching_access_expires_at_epoch')->nullable();

            $table->index('teaching_access_expires_at_epoch', 'users_teaching_access_expires_idx');
        });

        foreach ([
            'registrar.demo@example.invalid' => 'registrar',
            'nurse.demo@example.invalid' => 'nurse',
            'physician.demo@example.invalid' => 'physician',
            'rmik.demo@example.invalid' => 'rmik',
        ] as $email => $rosterKey) {
            DB::table('users')->where('email', $email)->update(['teaching_access_roster_key' => $rosterKey]);
        }

        Schema::create('teaching_role_access_leases', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('user_id')
                ->constrained('users', indexName: 'tral_user_fk')
                ->restrictOnDelete();
            $table->string('expected_role', 64);
            $table->string('credential_commitment', 64);
            $table->string('password_state_commitment', 64);
            $table->string('environment', 64);
            $table->string('release_sha', 40);
            $table->string('deployment_url', 255);
            $table->string('canonical_host', 255);
            $table->string('status', 16);
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->unsignedBigInteger('activated_at_epoch');
            $table->unsignedBigInteger('expires_at_epoch');
            $table->unsignedBigInteger('ended_at_epoch')->nullable();
            $table->string('end_reason', 240)->nullable();
            $table->string('operator', 120);
            $table->string('reason', 240);
            $table->timestamps(precision: 6);

            $table->unique('public_id', 'tral_public_id_uq');
            $table->unique('credential_commitment', 'tral_credential_commitment_uq');
            $table->unique(['user_id', 'active_slot'], 'tral_one_active_slot_uq');
            $table->index(['user_id', 'status', 'expires_at_epoch'], 'tral_user_status_expires_idx');
            $table->index(['status', 'expires_at_epoch'], 'tral_status_expires_idx');
        });

        $this->qualifyPostgresSequence();
        $this->enforcePostgresRosterIdentityAndActiveLease();
    }

    public function down(): void
    {
        $roster = [
            'registrar.demo@example.invalid',
            'nurse.demo@example.invalid',
            'physician.demo@example.invalid',
            'rmik.demo@example.invalid',
        ];
        $unsafeAccount = DB::table('users')
            ->where(function ($query) use ($roster): void {
                $query->whereIn('email', $roster)
                    ->orWhereIn('teaching_access_roster_key', ['registrar', 'nurse', 'physician', 'rmik']);
            })
            ->where(function ($query): void {
                $query->where('status', '!=', 'DISABLED')
                    ->orWhere('teaching_access_epoch', '!=', 0)
                    ->orWhere('teaching_access_mutex', '!=', 0)
                    ->orWhereNotNull('teaching_access_lease_public_id')
                    ->orWhereNotNull('teaching_access_expires_at_epoch');
            })
            ->exists();
        $leaseEvidence = Schema::hasTable('teaching_role_access_leases')
            && DB::table('teaching_role_access_leases')->exists();

        if ($unsafeAccount || $leaseEvidence) {
            throw new RuntimeException(
                'Teaching-role access migration rollback refused: preserve every access-window record and fencing column.',
            );
        }

        $this->dropPostgresRosterIdentityEnforcement();

        Schema::dropIfExists('teaching_role_access_leases');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_teaching_access_expires_idx');
            $table->dropUnique('users_teaching_access_roster_key_uq');
            $table->dropColumn([
                'teaching_access_epoch',
                'teaching_access_mutex',
                'teaching_access_roster_key',
                'teaching_access_lease_public_id',
                'teaching_access_expires_at_epoch',
            ]);
        });
    }

    private function qualifyPostgresSequence(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = SchemaQualifier::primarySchema() ?? 'public';
        $quotedSchema = '"'.str_replace('"', '""', $schema).'"';
        DB::statement(sprintf(
            'ALTER TABLE %s."teaching_role_access_leases" ALTER COLUMN id SET DEFAULT nextval(%s::regclass)',
            $quotedSchema,
            DB::getPdo()->quote($quotedSchema.'."teaching_role_access_leases_id_seq"'),
        ));
    }

    private function enforcePostgresRosterIdentityAndActiveLease(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = SchemaQualifier::primarySchema() ?? 'public';
        $quotedSchema = '"'.str_replace('"', '""', $schema).'"';
        $users = $quotedSchema.'."users"';
        $leases = $quotedSchema.'."teaching_role_access_leases"';

        DB::statement(
            "ALTER TABLE {$users} ADD CONSTRAINT users_teaching_access_roster_mapping_ck CHECK (
                (teaching_access_roster_key IS NULL AND email NOT IN (
                    'registrar.demo@example.invalid', 'nurse.demo@example.invalid',
                    'physician.demo@example.invalid', 'rmik.demo@example.invalid'
                )) OR
                (email = 'registrar.demo@example.invalid' AND teaching_access_roster_key = 'registrar') OR
                (email = 'nurse.demo@example.invalid' AND teaching_access_roster_key = 'nurse') OR
                (email = 'physician.demo@example.invalid' AND teaching_access_roster_key = 'physician') OR
                (email = 'rmik.demo@example.invalid' AND teaching_access_roster_key = 'rmik')
            )",
        );
        DB::statement(
            "CREATE OR REPLACE FUNCTION {$quotedSchema}.enforce_teaching_roster_identity_immutability()
             RETURNS trigger LANGUAGE plpgsql AS \$\$
             BEGIN
                 IF OLD.teaching_access_roster_key IS NOT NULL AND (
                     NEW.teaching_access_roster_key IS DISTINCT FROM OLD.teaching_access_roster_key OR
                     NEW.email IS DISTINCT FROM OLD.email
                 ) THEN
                     RAISE EXCEPTION 'Teaching roster identity is immutable';
                 END IF;
                 RETURN NEW;
             END;
             \$\$",
        );
        DB::statement(
            "CREATE TRIGGER users_teaching_roster_identity_immutable_trg
             BEFORE UPDATE OF email, teaching_access_roster_key ON {$users}
             FOR EACH ROW EXECUTE FUNCTION {$quotedSchema}.enforce_teaching_roster_identity_immutability()",
        );
        DB::statement(
            "CREATE UNIQUE INDEX tral_one_active_per_user_uq
             ON {$leases} (user_id) WHERE status = 'ACTIVE'",
        );
    }

    private function dropPostgresRosterIdentityEnforcement(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = SchemaQualifier::primarySchema() ?? 'public';
        $quotedSchema = '"'.str_replace('"', '""', $schema).'"';
        DB::statement(
            "DROP TRIGGER IF EXISTS users_teaching_roster_identity_immutable_trg ON {$quotedSchema}.\"users\"",
        );
        DB::statement(
            "DROP FUNCTION IF EXISTS {$quotedSchema}.enforce_teaching_roster_identity_immutability()",
        );
        DB::statement(
            "ALTER TABLE {$quotedSchema}.\"users\" DROP CONSTRAINT IF EXISTS users_teaching_access_roster_mapping_ck",
        );
    }
};
