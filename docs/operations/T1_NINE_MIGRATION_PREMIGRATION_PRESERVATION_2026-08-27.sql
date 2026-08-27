-- SIMRS Campus UEU: pre-migration preservation and target-context receipt.
-- PostgreSQL 17, schema laravel, read-only, synthetic teaching environment only.
--
-- EXTERNAL TARGET BINDING (mandatory; SQL cannot prove a Supabase project ref):
-- 1. Independently verify the authorized project ref/host, DNS/TLS endpoint and
--    connection source. Record that external evidence outside database output.
-- 2. Hash these exact SQL bytes outside PostgreSQL. Pass that 64-hex value as
--    sql_sha256; SQL can echo/validate it but cannot prove its own file bytes.
-- 3. Pass the independently authorized expected database, login role, exact
--    server_version_num, and connection SSL boolean. Undefined psql variables
--    are syntax errors and therefore NO-GO.
-- 4. Run with psql --no-psqlrc --set=ON_ERROR_STOP=1 --quiet --tuples-only
--    --no-align and capture the single JSON line byte-for-byte. Hash complete
--    stdout externally; that is expected_pre_receipt_sha256 for the post-check.
-- 5. Extract preservation_contract as compact JSON and pass it unchanged to
--    the post-check as expected_preservation_contract, together with its
--    preservation_contract_sha256. Never hand-edit either value.
--
-- Required psql variables: sql_sha256, expected_database, expected_current_user,
-- expected_server_version_num, expected_ssl (literal true or false).
-- Any error, timeout, missing single JSON result or non-match is NO-GO.

BEGIN;
SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '120s';
SET LOCAL search_path = pg_catalog, public;
SET LOCAL simrs.sql_sha256 TO :'sql_sha256';
SET LOCAL simrs.expected_database TO :'expected_database';
SET LOCAL simrs.expected_current_user TO :'expected_current_user';
SET LOCAL simrs.expected_server_version_num TO :'expected_server_version_num';
SET LOCAL simrs.expected_ssl TO :'expected_ssl';

DO $preservation$
DECLARE
    contract_ok boolean;
    observed_ssl boolean;
    table_row record;
    row_count bigint;
    content_sha256 text;
    row_expression text;
    table_contracts jsonb := '{}'::jsonb;
    preservation_contract jsonb;
BEGIN
    IF current_setting('simrs.sql_sha256') !~ '^[0-9a-f]{64}$' THEN
        RAISE EXCEPTION 'PRE_MIGRATION_NO_GO: sql_sha256 is absent or malformed';
    END IF;

    SELECT COALESCE((
        SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()
    ), false) INTO observed_ssl;

    IF current_database() <> current_setting('simrs.expected_database')
       OR current_user <> current_setting('simrs.expected_current_user')
       OR current_setting('server_version_num') <> current_setting('simrs.expected_server_version_num')
       OR observed_ssl <> current_setting('simrs.expected_ssl')::boolean THEN
        RAISE EXCEPTION 'PRE_MIGRATION_NO_GO: authorized database/user/version/SSL context mismatch';
    END IF;

    IF current_setting('server_version_num')::integer / 10000 <> 17 THEN
        RAISE EXCEPTION 'PRE_MIGRATION_NO_GO: PostgreSQL major version is not 17';
    END IF;
    IF to_regnamespace('laravel') IS NULL THEN
        RAISE EXCEPTION 'PRE_MIGRATION_NO_GO: laravel schema is missing';
    END IF;

    SELECT count(*) = 31 INTO contract_ok
    FROM information_schema.tables
    WHERE table_schema = 'laravel' AND table_type = 'BASE TABLE';
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'PRE_MIGRATION_NO_GO: exact 31-table predecessor is absent';
    END IF;

    WITH expected(id, migration, batch) AS (
        VALUES
            (1, '0001_01_01_000000_create_users_table', 1),
            (2, '0001_01_01_000001_create_cache_table', 1),
            (3, '0001_01_01_000002_create_jobs_table', 1),
            (4, '2024_01_01_000000_create_passkeys_table', 1),
            (5, '2025_08_14_170933_add_two_factor_columns_to_users_table', 1),
            (6, '2026_07_15_000100_create_teaching_foundation_tables', 1),
            (7, '2026_07_15_000200_create_audit_events_table', 1),
            (8, '2026_07_15_000300_create_outpatient_registration_tables', 1),
            (9, '2026_07_15_000400_create_clinical_documentation_tables', 1),
            (10, '2026_07_15_000500_create_order_result_and_medication_request_tables', 1),
            (11, '2026_07_15_000600_create_pharmacy_workflow_tables', 1),
            (12, '2026_07_15_000700_create_encounter_closure_tables', 1),
            (13, '2026_07_15_000800_create_record_quality_review_tables', 1),
            (14, '2026_07_15_000900_create_computer_assisted_coding_tables', 1),
            (15, '2026_07_15_001000_create_coding_documentation_corrections_table', 1),
            (16, '2026_07_15_001100_create_clinical_procedures_table', 1),
            (17, '2026_07_16_001200_generalize_coding_sources_for_procedures', 1),
            (18, '2026_07_16_001300_create_procedure_documentation_corrections_table', 1),
            (19, '2026_07_16_001400_create_debrief_teaching_evidence_tables', 1),
            (20, '2026_07_17_000100_create_outpatient_safety_dispositions_table', 1),
            (21, '2026_07_18_000100_create_outpatient_early_departures_table', 1),
            (22, '2026_07_21_000100_create_eclaim_simulation_tables', 1),
            (23, '2026_07_24_000100_create_medication_dispense_preparation_tables', 1),
            (24, '2026_08_21_000200_create_rbac_tables', 2),
            (25, '2026_08_21_000300_create_outpatient_core_tables', 2),
            (26, '2026_08_21_000400_expand_pendaftaran_sahabat_desk', 3),
            (27, '2026_08_21_000600_add_inpatient_encounter_fields', 4),
            (28, '2026_08_21_000500_add_emergency_encounter_fields', 4),
            (29, '2026_08_22_000700_create_wilayah_tables_and_patient_codes', 5),
            (30, '2026_08_22_001100_create_lab_service_tables', 6),
            (31, '2026_08_24_000100_create_outpatient_documentation_tables', 7)
    ), actual AS (
        SELECT id, migration::text, batch FROM laravel.migrations
    )
    SELECT count(*) = 31
       AND NOT EXISTS (
           SELECT 1 FROM expected
           FULL OUTER JOIN actual USING (id, migration, batch)
           WHERE expected.id IS NULL OR actual.id IS NULL
       )
    INTO contract_ok FROM actual;
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'PRE_MIGRATION_NO_GO: exact predecessor ledger drifted';
    END IF;

    -- Preserve every nonvolatile preexisting base table. migrations is expected
    -- to gain nine rows. cache/cache_locks/sessions and queue-runtime tables are
    -- explicitly excluded because maintenance/session/worker control can change
    -- them independently; their schema remains covered by the post acceptance.
    -- users/audit_events/encounters exclude only columns intentionally added or
    -- rewritten by the nine migrations; every other preexisting column is hashed.
    FOR table_row IN
        SELECT table_class.relname::text AS table_name
        FROM pg_class table_class
        JOIN pg_namespace schema_row ON schema_row.oid = table_class.relnamespace
        WHERE schema_row.nspname = 'laravel'
          AND table_class.relkind = 'r'
          AND table_class.relname NOT IN (
              'migrations','cache','cache_locks','sessions','jobs','job_batches','failed_jobs'
          )
        ORDER BY table_class.relname
    LOOP
        row_expression := CASE table_row.table_name
            WHEN 'users' THEN
                'to_jsonb(t) - ARRAY[''teaching_access_epoch'',''teaching_access_mutex'',''teaching_access_roster_key'',''teaching_access_lease_public_id'',''teaching_access_expires_at_epoch'']::text[]'
            WHEN 'audit_events' THEN
                'to_jsonb(t) - ARRAY[''actor_type'',''actor_reference'']::text[]'
            WHEN 'encounters' THEN
                'to_jsonb(t) - ARRAY[''queue_date'',''queue_number'']::text[]'
            ELSE 'to_jsonb(t)'
        END;

        EXECUTE format(
            'SELECT count(*), encode(sha256(convert_to(COALESCE(string_agg(row_sha, E''\n'' ORDER BY row_sha), ''''), ''UTF8'')), ''hex'')
             FROM (
                 SELECT encode(sha256(convert_to((%s)::text, ''UTF8'')), ''hex'') AS row_sha
                 FROM laravel.%I AS t
             ) digested_rows',
            row_expression,
            table_row.table_name
        ) INTO row_count, content_sha256;

        table_contracts := table_contracts || jsonb_build_object(
            table_row.table_name,
            jsonb_build_object('rows', row_count, 'sha256', content_sha256)
        );
    END LOOP;

    preservation_contract := jsonb_build_object(
        'schema_version', 1,
        'algorithm', 'sha256(sorted sha256(to_jsonb(row) minus intentional candidate columns))',
        'excluded_volatile_tables', ARRAY[
            'cache','cache_locks','failed_jobs','job_batches','jobs','migrations','sessions'
        ],
        'intentional_column_exclusions', jsonb_build_object(
            'users', ARRAY['teaching_access_epoch','teaching_access_mutex','teaching_access_roster_key','teaching_access_lease_public_id','teaching_access_expires_at_epoch'],
            'audit_events', ARRAY['actor_type','actor_reference'],
            'encounters', ARRAY['queue_date','queue_number']
        ),
        'ordinary_audit_attribution', (
            SELECT jsonb_build_object(
                'rows', count(*),
                'with_actor_user_id', count(actor_user_id),
                'without_actor_user_id', count(*) FILTER (WHERE actor_user_id IS NULL)
            ) FROM laravel.audit_events
        ),
        'users_public_id', (
            SELECT jsonb_build_object(
                'rows', count(*),
                'non_null_public_ids', count(public_id),
                'distinct_public_ids', count(DISTINCT public_id)
            ) FROM laravel.users
        ),
        'tables', table_contracts
    );

    IF (SELECT count(*) FROM jsonb_object_keys(table_contracts)) <> 24 THEN
        RAISE EXCEPTION 'PRE_MIGRATION_NO_GO: expected 24 preserved nonvolatile tables, found %',
            (SELECT count(*) FROM jsonb_object_keys(table_contracts));
    END IF;

    PERFORM set_config('simrs.preservation_contract', preservation_contract::text, true);
    PERFORM set_config(
        'simrs.preservation_contract_sha256',
        encode(sha256(convert_to(preservation_contract::text, 'UTF8')), 'hex'),
        true
    );
END;
$preservation$ LANGUAGE plpgsql;

WITH ssl_state AS (
    SELECT COALESCE(ssl, false) AS ssl,
           version AS ssl_version,
           cipher AS ssl_cipher
    FROM pg_stat_ssl
    WHERE pid = pg_backend_pid()
), context AS (
    SELECT current_database() AS database_name,
           current_user::text AS current_user_name,
           current_setting('server_version') AS server_version,
           current_setting('server_version_num')::integer AS server_version_num,
           COALESCE((SELECT ssl FROM ssl_state), false) AS ssl,
           (SELECT ssl_version FROM ssl_state) AS ssl_version,
           (SELECT ssl_cipher FROM ssl_state) AS ssl_cipher
)
SELECT jsonb_build_object(
    'status', 'PRE_MIGRATION_PRESERVATION_CAPTURED',
    'promotion_authorized', false,
    'boundary', 'SYNTHETIC_TEACHING_ONLY',
    'captured_at_utc', timezone('UTC', statement_timestamp()),
    'sql_sha256_supplied_for_external_binding', current_setting('simrs.sql_sha256'),
    'target_context', to_jsonb(context),
    'target_binding_limit', 'Database context only; Supabase project identity requires the external receipt described in this SQL header.',
    'preservation_contract_sha256', current_setting('simrs.preservation_contract_sha256'),
    'preservation_contract', current_setting('simrs.preservation_contract')::jsonb
) AS nine_migration_premigration_preservation
FROM context;

COMMIT;
