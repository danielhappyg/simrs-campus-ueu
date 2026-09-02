<?php

namespace App\Support\Warehouse;

use App\Support\Database\SchemaQualifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class WarehouseAuditEvidenceGuard
{
    public const ACTION = 'warehouse.workflow.mutate';

    public static function install(): void
    {
        $table = SchemaQualifier::table('audit_events');
        if (! Schema::hasTable($table)) {
            return;
        }
        if (! Schema::hasColumn($table, 'action')) {
            throw new RuntimeException('Warehouse audit evidence guard requires the audit action registry column.');
        }

        $driver = DB::connection()->getDriverName();
        $action = DB::connection()->getPdo()->quote(self::ACTION);
        if ($driver === 'sqlite') {
            DB::statement("CREATE TRIGGER wh_audit_immutable_update BEFORE UPDATE ON {$table} WHEN OLD.action = {$action} OR NEW.action = {$action} BEGIN SELECT RAISE(ABORT, 'warehouse audit evidence is immutable'); END");
            DB::statement("CREATE TRIGGER wh_audit_immutable_delete BEFORE DELETE ON {$table} WHEN OLD.action = {$action} BEGIN SELECT RAISE(ABORT, 'warehouse audit evidence is immutable'); END");

            return;
        }
        if ($driver === 'pgsql') {
            $contract = WarehouseMutationScope::installationIdentityContract();
            $resetOwner = DB::connection()->getPdo()->quote($contract['reset_owner']);
            $resetExecutor = DB::connection()->getPdo()->quote($contract['reset_executor']);
            DB::statement("CREATE OR REPLACE FUNCTION warehouse_audit_evidence_guard() RETURNS trigger AS $$ BEGIN IF TG_OP = 'UPDATE' THEN IF OLD.action = {$action} OR NEW.action = {$action} THEN RAISE EXCEPTION 'warehouse audit evidence is immutable'; END IF; RETURN NEW; END IF; IF OLD.action = {$action} AND NOT (current_user = {$resetOwner} AND session_user = {$resetExecutor}) THEN RAISE EXCEPTION 'warehouse audit evidence is immutable'; END IF; RETURN OLD; END; $$ LANGUAGE plpgsql");
            DB::statement("CREATE TRIGGER wh_audit_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION warehouse_audit_evidence_guard()");
            DB::statement("ALTER TABLE {$table} ENABLE ALWAYS TRIGGER wh_audit_immutable");

            return;
        }
        if ($driver === 'mysql') {
            $contract = WarehouseMutationScope::installationIdentityContract();
            $resetExecutor = DB::connection()->getPdo()->quote($contract['reset_executor']);
            DB::statement("CREATE TRIGGER wh_audit_immutable_update BEFORE UPDATE ON {$table} FOR EACH ROW BEGIN IF OLD.action = {$action} OR NEW.action = {$action} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='warehouse audit evidence is immutable'; END IF; END");
            DB::statement("CREATE TRIGGER wh_audit_immutable_delete BEFORE DELETE ON {$table} FOR EACH ROW BEGIN IF OLD.action = {$action} AND USER() <> {$resetExecutor} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='warehouse audit evidence is immutable'; END IF; END");

            return;
        }

        throw new RuntimeException('Warehouse audit evidence guard is unsupported for this database driver.');
    }

    public static function remove(): void
    {
        $driver = DB::connection()->getDriverName();
        $table = SchemaQualifier::table('audit_events');
        if ($driver === 'pgsql') {
            if (Schema::hasTable($table)) {
                DB::statement("DROP TRIGGER IF EXISTS wh_audit_immutable ON {$table}");
            }
            DB::unprepared('DROP FUNCTION IF EXISTS warehouse_audit_evidence_guard()');

            return;
        }
        if (in_array($driver, ['sqlite', 'mysql'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS wh_audit_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS wh_audit_immutable_delete');
        }
    }
}
