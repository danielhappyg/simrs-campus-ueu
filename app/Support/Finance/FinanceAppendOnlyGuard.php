<?php

namespace App\Support\Finance;

use App\Support\Database\SchemaQualifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FinanceAppendOnlyGuard
{
    public const TABLES = [
        'finance_charge_events', 'finance_bill_versions', 'finance_bill_lines',
        'finance_operation_receipts', 'finance_cash_settlements',
        'finance_settlement_operation_receipts',
        'finance_settlement_correction_cases', 'finance_settlement_correction_events',
        'finance_settlement_correction_operation_receipts',
        'finance_cashier_collection_batches', 'finance_cashier_collection_members',
        'finance_cashier_collection_events', 'finance_cash_deposit_handoffs',
        'finance_cashier_collection_operation_receipts',
    ];

    public static function install(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable(SchemaQualifier::table($table))) {
                    continue;
                }
                $trigger = self::triggerBase($table);
                foreach (['UPDATE' => 'update', 'DELETE' => 'delete'] as $verb => $suffix) {
                    DB::statement("CREATE TRIGGER {$trigger}_immutable_{$suffix} BEFORE {$verb} ON ".SchemaQualifier::table($table)." BEGIN SELECT RAISE(ABORT, 'finance append-only evidence is immutable'); END");
                }
            }

            return;
        }
        if ($driver === 'pgsql') {
            $installer = DB::selectOne('SELECT current_user AS authenticated_identity');
            $installerIdentity = DB::connection()->getPdo()->quote((string) $installer->authenticated_identity);
            DB::statement("CREATE OR REPLACE FUNCTION finance_append_only_guard() RETURNS trigger AS $$ BEGIN IF current_user = {$installerIdentity} AND current_setting('simrs.synthetic_reset', true) = '1' THEN IF TG_OP = 'DELETE' THEN RETURN OLD; END IF; RETURN NEW; END IF; RAISE EXCEPTION 'finance append-only evidence is immutable'; END; $$ LANGUAGE plpgsql");
            DB::statement("CREATE OR REPLACE FUNCTION finance_append_only_truncate_guard() RETURNS trigger AS $$ BEGIN IF current_user = {$installerIdentity} AND current_setting('simrs.synthetic_reset', true) = '1' THEN RETURN NULL; END IF; RAISE EXCEPTION 'finance append-only evidence is immutable'; END; $$ LANGUAGE plpgsql");
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable(SchemaQualifier::table($table))) {
                    continue;
                }
                $trigger = self::triggerBase($table);
                DB::statement("CREATE TRIGGER {$trigger}_immutable BEFORE UPDATE OR DELETE ON ".SchemaQualifier::table($table).' FOR EACH ROW EXECUTE FUNCTION finance_append_only_guard()');
                DB::statement("CREATE TRIGGER {$trigger}_truncate_guard BEFORE TRUNCATE ON ".SchemaQualifier::table($table).' FOR EACH STATEMENT EXECUTE FUNCTION finance_append_only_truncate_guard()');
            }

            return;
        }
        if ($driver === 'mysql') {
            $installer = DB::selectOne('SELECT USER() AS authenticated_identity');
            $installerIdentity = DB::connection()->getPdo()->quote((string) $installer->authenticated_identity);
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable(SchemaQualifier::table($table))) {
                    continue;
                }
                $trigger = self::triggerBase($table);
                DB::statement("CREATE TRIGGER {$trigger}_immutable_update BEFORE UPDATE ON ".SchemaQualifier::table($table)." FOR EACH ROW BEGIN IF NOT (USER() = {$installerIdentity} AND COALESCE(@simrs_synthetic_reset, 0) = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='finance append-only evidence is immutable'; END IF; END");
                DB::statement("CREATE TRIGGER {$trigger}_immutable_delete BEFORE DELETE ON ".SchemaQualifier::table($table)." FOR EACH ROW BEGIN IF NOT (USER() = {$installerIdentity} AND COALESCE(@simrs_synthetic_reset, 0) = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='finance append-only evidence is immutable'; END IF; END");
            }
        }
    }

    public static function remove(): void
    {
        $driver = DB::connection()->getDriverName();
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable(SchemaQualifier::table($table))) {
                continue;
            }
            $trigger = self::triggerBase($table);
            if ($driver === 'pgsql') {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}_immutable ON ".SchemaQualifier::table($table));
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}_truncate_guard ON ".SchemaQualifier::table($table));
            } else {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}_immutable_update");
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}_immutable_delete");
            }
        }
    }

    public static function runSyntheticReset(callable $callback): mixed
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            FinanceSchemaMutationScope::run(fn () => self::remove());
            try {
                return $callback();
            } finally {
                FinanceSchemaMutationScope::run(fn () => self::install());
            }
        }
        if ($driver === 'pgsql') {
            DB::statement("SET LOCAL simrs.synthetic_reset = '1'");
        } elseif ($driver === 'mysql') {
            DB::statement('SET @simrs_synthetic_reset = 1');
        }
        try {
            return $callback();
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET @simrs_synthetic_reset = 0');
            }
        }
    }

    private static function triggerBase(string $table): string
    {
        return match ($table) {
            'finance_settlement_correction_operation_receipts' => 'fscor',
            'finance_cashier_collection_batches' => 'fccb',
            'finance_cashier_collection_members' => 'fccm',
            'finance_cashier_collection_events' => 'fcce',
            'finance_cash_deposit_handoffs' => 'fcdh',
            'finance_cashier_collection_operation_receipts' => 'fccor',
            default => $table,
        };
    }
}
