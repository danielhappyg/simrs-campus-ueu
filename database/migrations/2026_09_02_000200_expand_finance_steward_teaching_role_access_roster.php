<?php

use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Database\SchemaQualifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private const EXISTING_ROSTER = [
        'registrar.demo@example.invalid' => RoleCapabilityMatrix::ROLE_REGISTRAR,
        'nurse.demo@example.invalid' => RoleCapabilityMatrix::ROLE_NURSE,
        'physician.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PHYSICIAN,
        'rmik.demo@example.invalid' => RoleCapabilityMatrix::ROLE_RMIK,
        'admin.demo@example.invalid' => RoleCapabilityMatrix::ROLE_ADMIN,
        'radiology.technologist.demo@example.invalid' => RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST,
        'radiologist.demo@example.invalid' => RoleCapabilityMatrix::ROLE_RADIOLOGIST,
        'laboratory.technologist.demo@example.invalid' => RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST,
        'laboratory.verifier.demo@example.invalid' => RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER,
        'pharmacist.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PHARMACIST,
        'pharmacy.technician.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PHARMACY_TECHNICIAN,
        'pharmacy.inventory.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER,
        'cashier.demo@example.invalid' => RoleCapabilityMatrix::ROLE_CASHIER,
    ];

    /** @var array<string, string> */
    private const ADDED_ROSTER = [
        'finance.steward.demo@example.invalid' => RoleCapabilityMatrix::ROLE_FINANCE_STEWARD,
    ];

    public function up(): void
    {
        $this->dropPostgresMappingConstraint();

        foreach (self::ADDED_ROSTER as $email => $rosterKey) {
            DB::table(SchemaQualifier::table('users'))
                ->where('email', $email)
                ->update(['teaching_access_roster_key' => $rosterKey]);
        }

        $this->addPostgresMappingConstraint(self::EXISTING_ROSTER + self::ADDED_ROSTER);
    }

    public function down(): void
    {
        $users = SchemaQualifier::table('users');
        $addedAccountIds = DB::table($users)
            ->whereIn('email', array_keys(self::ADDED_ROSTER))
            ->orWhereIn('teaching_access_roster_key', array_values(self::ADDED_ROSTER))
            ->pluck('id');

        if ($addedAccountIds->isNotEmpty()
            || DB::table(SchemaQualifier::table('teaching_role_access_leases'))
                ->whereIn('expected_role', array_values(self::ADDED_ROSTER))
                ->exists()) {
            throw new RuntimeException(
                'Teaching-role roster rollback refused: preserve the finance-steward identity and its access-window evidence.',
            );
        }

        $this->dropPostgresMappingConstraint();
        $this->addPostgresMappingConstraint(self::EXISTING_ROSTER);
    }

    /** @param array<string, string> $roster */
    private function addPostgresMappingConstraint(array $roster): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $users = $this->quotedPostgresUsersTable();
        $emails = implode(', ', array_map(
            fn (string $email): string => DB::getPdo()->quote($email),
            array_keys($roster),
        ));
        $exactMappings = implode(' OR ', array_map(
            fn (string $email, string $role): string => sprintf(
                '(email = %s AND teaching_access_roster_key = %s)',
                DB::getPdo()->quote($email),
                DB::getPdo()->quote($role),
            ),
            array_keys($roster),
            array_values($roster),
        ));

        DB::statement(
            "ALTER TABLE {$users} ADD CONSTRAINT users_teaching_access_roster_mapping_ck CHECK (
                (teaching_access_roster_key IS NULL AND email NOT IN ({$emails})) OR {$exactMappings}
            )",
        );
    }

    private function dropPostgresMappingConstraint(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE '.$this->quotedPostgresUsersTable()
            .' DROP CONSTRAINT IF EXISTS users_teaching_access_roster_mapping_ck',
        );
    }

    private function quotedPostgresUsersTable(): string
    {
        $schema = SchemaQualifier::primarySchema() ?? 'public';

        return '"'.str_replace('"', '""', $schema).'"."users"';
    }
};
