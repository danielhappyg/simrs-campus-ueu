<?php

namespace App\Support\Warehouse;

use App\Support\Database\SchemaQualifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class WarehouseMutableHeadGuard
{
    public const TABLES = [
        'warehouse_suppliers', 'warehouse_purchase_orders', 'warehouse_transfers',
        'warehouse_supplier_returns', 'warehouse_unit_returns',
    ];

    public static function install(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            $contract = WarehouseMutationScope::installationIdentityContract();
            $writer = DB::connection()->getPdo()->quote($contract['writer']);
            $resetOwner = DB::connection()->getPdo()->quote($contract['reset_owner']);
            $resetExecutor = DB::connection()->getPdo()->quote($contract['reset_executor']);
            DB::statement("CREATE OR REPLACE FUNCTION warehouse_mutable_head_guard() RETURNS trigger AS $$ BEGIN IF current_user = {$writer} AND session_user = {$writer} THEN IF TG_OP = 'DELETE' THEN RETURN OLD; END IF; RETURN NEW; END IF; IF TG_OP = 'DELETE' AND current_user = {$resetOwner} AND session_user = {$resetExecutor} THEN RETURN OLD; END IF; RAISE EXCEPTION 'warehouse mutable head write requires governed scope'; END; $$ LANGUAGE plpgsql");
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable(SchemaQualifier::table($table))) {
                    continue;
                }
                $trigger = self::triggerName($table, 'governed');
                DB::statement("CREATE TRIGGER {$trigger} BEFORE INSERT OR UPDATE OR DELETE ON ".SchemaQualifier::table($table).' FOR EACH ROW EXECUTE FUNCTION warehouse_mutable_head_guard()');
                DB::statement('ALTER TABLE '.SchemaQualifier::table($table)." ENABLE ALWAYS TRIGGER {$trigger}");
            }
        } elseif ($driver === 'mysql') {
            $contract = WarehouseMutationScope::installationIdentityContract();
            $writer = DB::connection()->getPdo()->quote($contract['writer']);
            $resetExecutor = DB::connection()->getPdo()->quote($contract['reset_executor']);
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable(SchemaQualifier::table($table))) {
                    continue;
                }
                foreach (['insert', 'update', 'delete'] as $operation) {
                    $identityCheck = $operation === 'delete'
                        ? "USER() <> {$writer} AND USER() <> {$resetExecutor}"
                        : "USER() <> {$writer}";
                    DB::statement('CREATE TRIGGER '.self::triggerName($table, "governed_{$operation}").' BEFORE '.strtoupper($operation).' ON '.SchemaQualifier::table($table)." FOR EACH ROW BEGIN IF {$identityCheck} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='warehouse mutable head write requires governed scope'; END IF; END");
                }
            }
        }
    }

    public static function remove(): void
    {
        $driver = DB::connection()->getDriverName();
        foreach (self::TABLES as $table) {
            if ($driver === 'pgsql') {
                if (! Schema::hasTable(SchemaQualifier::table($table))) {
                    continue;
                }
                DB::statement('DROP TRIGGER IF EXISTS '.self::triggerName($table, 'governed').' ON '.SchemaQualifier::table($table));
            } elseif ($driver === 'mysql') {
                foreach (['insert', 'update', 'delete'] as $operation) {
                    DB::statement('DROP TRIGGER IF EXISTS '.self::triggerName($table, "governed_{$operation}"));
                }
            }
        }
    }

    private static function triggerName(string $table, string $purpose): string
    {
        return 'wh_'.substr(hash('sha256', "{$table}:{$purpose}"), 0, 24);
    }
}
