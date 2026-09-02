<?php

namespace App\Support\Finance;

use App\Support\Database\SchemaQualifier;
use Illuminate\Support\Facades\DB;

final class FinanceAccommodationTariffMutableHeadGuard
{
    public static function install(): void
    {
        $table = 'finance_accommodation_tariff_bindings';
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION finance_accommodation_tariff_head_guard() RETURNS trigger AS $$ BEGIN IF current_setting('simrs.finance_accommodation_tariff_mutation', true) = '1' OR current_setting('simrs.synthetic_reset', true) = '1' THEN IF TG_OP = 'DELETE' THEN RETURN OLD; END IF; RETURN NEW; END IF; RAISE EXCEPTION 'accommodation tariff binding head write requires governed scope'; END; $$ LANGUAGE plpgsql");
            DB::statement("CREATE TRIGGER {$table}_governed BEFORE INSERT OR UPDATE OR DELETE ON ".SchemaQualifier::table($table).' FOR EACH ROW EXECUTE FUNCTION finance_accommodation_tariff_head_guard()');
        } elseif ($driver === 'mysql') {
            foreach (['insert', 'update', 'delete'] as $operation) {
                DB::statement("CREATE TRIGGER {$table}_governed_{$operation} BEFORE ".strtoupper($operation).' ON '.SchemaQualifier::table($table)." FOR EACH ROW BEGIN IF COALESCE(@simrs_finance_accommodation_tariff_mutation, 0) <> 1 AND COALESCE(@simrs_synthetic_reset, 0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='accommodation tariff binding head write requires governed scope'; END IF; END");
            }
        }
    }

    public static function remove(): void
    {
        $table = 'finance_accommodation_tariff_bindings';
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_governed ON ".SchemaQualifier::table($table));
        } elseif ($driver === 'mysql') {
            foreach (['insert', 'update', 'delete'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_governed_{$operation}");
            }
        }
    }
}
