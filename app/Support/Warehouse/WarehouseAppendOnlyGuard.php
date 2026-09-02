<?php

namespace App\Support\Warehouse;

use App\Support\Database\SchemaQualifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

final class WarehouseAppendOnlyGuard
{
    private static int $syntheticResetDepth = 0;

    public const TABLES = [
        'warehouse_supplier_versions', 'warehouse_purchase_order_versions',
        'warehouse_purchase_order_lines', 'warehouse_purchase_order_decisions',
        'warehouse_receipts', 'warehouse_receipt_lines', 'warehouse_custody_lots',
        'warehouse_custody_receipt_allocations', 'warehouse_custody_movement_sets',
        'warehouse_custody_movement_pairs',
        'warehouse_transfer_items', 'warehouse_transfer_decisions',
        'warehouse_supplier_return_items', 'warehouse_supplier_return_decisions',
        'warehouse_unit_return_items', 'warehouse_unit_return_decisions',
        'warehouse_correction_requests', 'warehouse_correction_decisions',
        'warehouse_correction_compensations', 'warehouse_operation_receipts',
    ];

    public static function install(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable(SchemaQualifier::table($table))) {
                    continue;
                }
                foreach (['UPDATE' => 'update', 'DELETE' => 'delete'] as $verb => $suffix) {
                    $trigger = self::triggerName($table, "immutable_{$suffix}");
                    DB::statement("CREATE TRIGGER {$trigger} BEFORE {$verb} ON ".SchemaQualifier::table($table)." BEGIN SELECT RAISE(ABORT, 'warehouse append-only evidence is immutable'); END");
                }
            }

            return;
        }
        if ($driver === 'pgsql') {
            $contract = WarehouseMutationScope::installationIdentityContract();
            $resetOwner = DB::connection()->getPdo()->quote($contract['reset_owner']);
            $resetExecutor = DB::connection()->getPdo()->quote($contract['reset_executor']);
            DB::statement("CREATE OR REPLACE FUNCTION warehouse_append_only_guard() RETURNS trigger AS $$ BEGIN IF TG_OP = 'DELETE' AND current_user = {$resetOwner} AND session_user = {$resetExecutor} THEN RETURN OLD; END IF; RAISE EXCEPTION 'warehouse append-only evidence is immutable'; END; $$ LANGUAGE plpgsql");
            DB::statement("CREATE OR REPLACE FUNCTION warehouse_append_only_truncate_guard() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'warehouse append-only evidence is immutable'; END; $$ LANGUAGE plpgsql");
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable(SchemaQualifier::table($table))) {
                    continue;
                }
                $immutable = self::triggerName($table, 'immutable');
                $truncate = self::triggerName($table, 'truncate');
                DB::statement("CREATE TRIGGER {$immutable} BEFORE UPDATE OR DELETE ON ".SchemaQualifier::table($table).' FOR EACH ROW EXECUTE FUNCTION warehouse_append_only_guard()');
                DB::statement("CREATE TRIGGER {$truncate} BEFORE TRUNCATE ON ".SchemaQualifier::table($table).' FOR EACH STATEMENT EXECUTE FUNCTION warehouse_append_only_truncate_guard()');
                DB::statement('ALTER TABLE '.SchemaQualifier::table($table)." ENABLE ALWAYS TRIGGER {$immutable}");
                DB::statement('ALTER TABLE '.SchemaQualifier::table($table)." ENABLE ALWAYS TRIGGER {$truncate}");
            }

            return;
        }
        if ($driver === 'mysql') {
            $contract = WarehouseMutationScope::installationIdentityContract();
            $resetExecutor = DB::connection()->getPdo()->quote($contract['reset_executor']);
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable(SchemaQualifier::table($table))) {
                    continue;
                }
                $update = self::triggerName($table, 'immutable_update');
                $delete = self::triggerName($table, 'immutable_delete');
                DB::statement("CREATE TRIGGER {$update} BEFORE UPDATE ON ".SchemaQualifier::table($table)." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='warehouse append-only evidence is immutable'");
                DB::statement("CREATE TRIGGER {$delete} BEFORE DELETE ON ".SchemaQualifier::table($table)." FOR EACH ROW BEGIN IF USER() <> {$resetExecutor} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='warehouse append-only evidence is immutable'; END IF; END");
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
                DB::statement('DROP TRIGGER IF EXISTS '.self::triggerName($table, 'immutable').' ON '.SchemaQualifier::table($table));
                DB::statement('DROP TRIGGER IF EXISTS '.self::triggerName($table, 'truncate').' ON '.SchemaQualifier::table($table));
            } else {
                DB::statement('DROP TRIGGER IF EXISTS '.self::triggerName($table, 'immutable_update'));
                DB::statement('DROP TRIGGER IF EXISTS '.self::triggerName($table, 'immutable_delete'));
            }
        }
    }

    public static function runSyntheticReset(callable $callback): mixed
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'pgsql', 'mysql'], true)) {
            throw new LogicException('Warehouse synthetic reset is unsupported for this database driver.');
        }
        if (in_array($driver, ['pgsql', 'mysql'], true)) {
            throw new LogicException('Exact-engine warehouse reset requires the bounded security-definer reset routine; generic callback reset is prohibited.');
        }
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Warehouse synthetic reset requires an active transaction.');
        }

        WarehouseSchemaMutationScope::run(fn () => self::remove());
        self::$syntheticResetDepth++;
        try {
            return $callback();
        } finally {
            try {
                WarehouseSchemaMutationScope::run(fn () => self::install());
            } finally {
                self::$syntheticResetDepth--;
            }
        }
    }

    public static function isSyntheticResetActive(): bool
    {
        return self::$syntheticResetDepth > 0;
    }

    private static function triggerName(string $table, string $purpose): string
    {
        return 'wh_'.substr(hash('sha256', "{$table}:{$purpose}"), 0, 24);
    }
}
