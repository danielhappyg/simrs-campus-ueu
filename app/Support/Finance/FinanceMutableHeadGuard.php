<?php

namespace App\Support\Finance;

use App\Support\Database\SchemaQualifier;
use Illuminate\Support\Facades\DB;

final class FinanceMutableHeadGuard
{
    public const TABLES = ['finance_bills'];

    public static function install(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION finance_mutable_head_guard() RETURNS trigger AS $$ BEGIN IF current_setting('simrs.finance_mutation', true) = '1' OR current_setting('simrs.synthetic_reset', true) = '1' THEN IF TG_OP = 'DELETE' THEN RETURN OLD; END IF; RETURN NEW; END IF; RAISE EXCEPTION 'finance mutable head write requires governed scope'; END; $$ LANGUAGE plpgsql");
            foreach (self::TABLES as $table) {
                DB::statement("CREATE TRIGGER {$table}_governed BEFORE UPDATE OR DELETE ON ".SchemaQualifier::table($table).' FOR EACH ROW EXECUTE FUNCTION finance_mutable_head_guard()');
            }
        } elseif ($driver === 'mysql') {
            foreach (self::TABLES as $table) {
                DB::statement("CREATE TRIGGER {$table}_governed_update BEFORE UPDATE ON ".SchemaQualifier::table($table)." FOR EACH ROW BEGIN IF COALESCE(@simrs_finance_mutation, 0) <> 1 AND COALESCE(@simrs_synthetic_reset, 0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='finance mutable head write requires governed scope'; END IF; END");
                DB::statement("CREATE TRIGGER {$table}_governed_delete BEFORE DELETE ON ".SchemaQualifier::table($table)." FOR EACH ROW BEGIN IF COALESCE(@simrs_finance_mutation, 0) <> 1 AND COALESCE(@simrs_synthetic_reset, 0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='finance mutable head write requires governed scope'; END IF; END");
            }
        }
    }

    public static function remove(): void
    {
        $driver = DB::connection()->getDriverName();
        foreach (self::TABLES as $table) {
            if ($driver === 'pgsql') {
                DB::statement("DROP TRIGGER IF EXISTS {$table}_governed ON ".SchemaQualifier::table($table));
            } elseif ($driver === 'mysql') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_governed_update");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_governed_delete");
            }
        }
    }
}
