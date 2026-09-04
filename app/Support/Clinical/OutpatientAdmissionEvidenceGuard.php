<?php

namespace App\Support\Clinical;

use App\Support\Database\SchemaQualifier;
use Illuminate\Support\Facades\DB;
use LogicException;

final class OutpatientAdmissionEvidenceGuard
{
    public const TABLES = [
        'outpatient_dispositions',
        'outpatient_inpatient_handoffs',
        'outpatient_admission_operation_receipts',
    ];

    public static function install(): void
    {
        $driver = DB::connection()->getDriverName();
        $grammar = DB::connection()->getQueryGrammar();
        $function = $grammar->wrapTable(SchemaQualifier::table('outpatient_admission_immutable'));
        if ($driver === 'pgsql') {
            DB::statement("CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP='DELETE' AND current_setting('simrs.synthetic_reset', true)='1' THEN RETURN OLD; END IF; RAISE EXCEPTION 'outpatient admission evidence is immutable'; END $$");
        }
        foreach (self::TABLES as $index => $table) {
            $qualified = $grammar->wrapTable(SchemaQualifier::table($table));
            if ($driver === 'pgsql') {
                DB::statement("CREATE TRIGGER oadm_{$index}_immutable BEFORE UPDATE OR DELETE ON {$qualified} FOR EACH ROW EXECUTE FUNCTION {$function}()");
                DB::statement("CREATE TRIGGER oadm_{$index}_truncate BEFORE TRUNCATE ON {$qualified} FOR EACH STATEMENT EXECUTE FUNCTION {$function}()");
            } else {
                foreach (['update', 'delete'] as $verb) {
                    $body = $driver === 'sqlite'
                        ? "BEGIN SELECT RAISE(ABORT, 'outpatient admission evidence is immutable'); END"
                        : ($verb === 'delete'
                            ? "FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='outpatient admission evidence is immutable'; END IF; END"
                            : "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='outpatient admission evidence is immutable'");
                    DB::statement("CREATE TRIGGER oadm_{$index}_{$verb} BEFORE {$verb} ON {$qualified} {$body}");
                }
            }
        }
    }

    public static function remove(): void
    {
        $driver = DB::connection()->getDriverName();
        $grammar = DB::connection()->getQueryGrammar();
        foreach (self::TABLES as $index => $table) {
            $qualified = $grammar->wrapTable(SchemaQualifier::table($table));
            if ($driver === 'pgsql') {
                DB::statement("DROP TRIGGER IF EXISTS oadm_{$index}_immutable ON {$qualified}");
                DB::statement("DROP TRIGGER IF EXISTS oadm_{$index}_truncate ON {$qualified}");
            } else {
                DB::statement("DROP TRIGGER IF EXISTS oadm_{$index}_update");
                DB::statement("DROP TRIGGER IF EXISTS oadm_{$index}_delete");
            }
        }
        if ($driver === 'pgsql') {
            $function = $grammar->wrapTable(SchemaQualifier::table('outpatient_admission_immutable'));
            DB::statement("DROP FUNCTION IF EXISTS {$function}()");
        }
    }

    /** @param callable(): void $callback */
    public static function runSyntheticReset(callable $callback): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true
            || DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Outpatient evidence reset requires the synthetic reset transaction.');
        }
        $driver = DB::connection()->getDriverName();
        $prior = null;
        if ($driver === 'sqlite') {
            self::remove();
        } elseif ($driver === 'pgsql') {
            $prior = DB::scalar("SELECT current_setting('simrs.synthetic_reset', true)");
            DB::statement("SET LOCAL simrs.synthetic_reset = '1'");
        } elseif ($driver === 'mysql') {
            $prior = DB::scalar('SELECT @simrs_synthetic_reset');
            DB::statement('SET @simrs_synthetic_reset = 1');
        }
        try {
            $callback();
        } finally {
            if ($driver === 'sqlite') {
                self::install();
            } elseif ($driver === 'pgsql') {
                DB::select("SELECT set_config('simrs.synthetic_reset', ?, true)", [(string) $prior]);
            } elseif ($driver === 'mysql') {
                DB::statement('SET @simrs_synthetic_reset = ?', [$prior]);
            }
        }
    }
}
