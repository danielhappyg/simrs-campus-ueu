<?php

use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Database\SchemaQualifier;
use App\Support\Warehouse\WarehouseMutationScope;
use App\Support\Warehouse\WarehouseSchemaMutationScope;
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
        'cashier.supervisor.demo@example.invalid' => RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR,
        'finance.steward.demo@example.invalid' => RoleCapabilityMatrix::ROLE_FINANCE_STEWARD,
    ];

    /** @var array<string, string> */
    private const ADDED_ROSTER = [
        'procurement.officer.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER,
        'procurement.approver.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PROCUREMENT_APPROVER,
        'warehouse.receiver.demo@example.invalid' => RoleCapabilityMatrix::ROLE_WAREHOUSE_RECEIVER,
        'warehouse.inventory.controller.demo@example.invalid' => RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_CONTROLLER,
        'warehouse.inventory.supervisor.demo@example.invalid' => RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_SUPERVISOR,
    ];

    public function shouldRun(): bool
    {
        $defaultConnection = config('database.default');
        if (! is_string($defaultConnection) || $defaultConnection === '') {
            throw new RuntimeException('Warehouse roster migration requires an explicit default database connection.');
        }

        $driver = config("database.connections.{$defaultConnection}.driver");
        if ($driver === 'sqlite') {
            return true;
        }

        if (config('database.warehouse_schema_migration_enabled') !== true) {
            return false;
        }

        if ($defaultConnection !== WarehouseMutationScope::DEFAULT_MIGRATOR_CONNECTION) {
            throw new RuntimeException(
                'WAREHOUSE_SCHEMA_MIGRATION_ENABLED requires the warehouse_migrator default connection.',
            );
        }

        if (! in_array($driver, ['pgsql', 'mysql'], true)) {
            throw new RuntimeException(
                'The governed warehouse roster cutover supports only PostgreSQL or MySQL.',
            );
        }

        return true;
    }

    public function up(): void
    {
        WarehouseSchemaMutationScope::run(fn () => $this->migrateUp());
    }

    private function migrateUp(): void
    {
        $this->assertSafeSimulationMode();
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
        WarehouseSchemaMutationScope::run(fn () => $this->migrateDown());
    }

    private function migrateDown(): void
    {
        $this->assertSafeSimulationMode();
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
                'Teaching-role roster rollback refused: preserve warehouse identities and their access-window evidence.',
            );
        }

        $this->dropPostgresMappingConstraint();
        $this->addPostgresMappingConstraint(self::EXISTING_ROSTER);
    }

    private function assertSafeSimulationMode(): void
    {
        if (config('simulation.mode') !== 'SIMULATION'
            || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException(
                'Warehouse roster migration requires synthetic-only SIMULATION mode.',
            );
        }
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
