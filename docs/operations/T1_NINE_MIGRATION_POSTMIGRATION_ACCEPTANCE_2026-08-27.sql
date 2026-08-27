-- SIMRS Campus UEU: fail-closed acceptance for the exact nine-migration candidate.
-- PostgreSQL 17, schema laravel, read-only, synthetic teaching environment only.
-- Run immediately after the one authorized migration attempt with psql
-- --no-psqlrc --set=ON_ERROR_STOP=1 --quiet --tuples-only --no-align.
--
-- EXTERNAL TARGET/PRESERVATION BINDING (mandatory): SQL cannot prove a Supabase
-- project ref. Independently verify the authorized project ref/host and record
-- its DNS/TLS/connection receipt. Hash these exact SQL bytes externally and pass
-- sql_sha256; SQL validates/echoes but cannot prove its own bytes. Also pass the
-- exact pre-receipt stdout SHA-256, target_context values, compact unchanged
-- preservation_contract JSON and preservation_contract_sha256 emitted by
-- T1_NINE_MIGRATION_PREMIGRATION_PRESERVATION_2026-08-27.sql. Undefined psql
-- variables are syntax errors and therefore NO-GO. Required variables:
-- sql_sha256, expected_pre_receipt_sha256, expected_database,
-- expected_current_user, expected_server_version_num, expected_ssl,
-- expected_preservation_contract, expected_preservation_contract_sha256.
-- Any SQL error, exception, timeout, missing single JSON result, or status other
-- than POST_MIGRATION_CONTRACT_MATCH is NO-GO. This artifact does not authorize
-- migration, traffic enablement, Vercel promotion, real-patient use, or retry.

BEGIN;
SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '120s';
SET LOCAL search_path = pg_catalog, public;
SET LOCAL simrs.sql_sha256 TO :'sql_sha256';
SET LOCAL simrs.expected_pre_receipt_sha256 TO :'expected_pre_receipt_sha256';
SET LOCAL simrs.expected_database TO :'expected_database';
SET LOCAL simrs.expected_current_user TO :'expected_current_user';
SET LOCAL simrs.expected_server_version_num TO :'expected_server_version_num';
SET LOCAL simrs.expected_ssl TO :'expected_ssl';
SET LOCAL simrs.expected_preservation_contract TO :'expected_preservation_contract';
SET LOCAL simrs.expected_preservation_contract_sha256 TO :'expected_preservation_contract_sha256';

DO $acceptance$
DECLARE
    contract_ok boolean;
    observed_count bigint;
    observed_ssl boolean;
    expected_preservation jsonb;
    actual_preservation jsonb;
    table_contracts jsonb := '{}'::jsonb;
    table_row record;
    row_count bigint;
    content_sha256 text;
    row_expression text;
BEGIN
    IF current_setting('simrs.sql_sha256') !~ '^[0-9a-f]{64}$'
       OR current_setting('simrs.expected_pre_receipt_sha256') !~ '^[0-9a-f]{64}$'
       OR current_setting('simrs.expected_preservation_contract_sha256') !~ '^[0-9a-f]{64}$' THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: SQL/pre-receipt/preservation hash binding is absent or malformed';
    END IF;

    SELECT COALESCE((
        SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()
    ), false) INTO observed_ssl;

    IF current_database() <> current_setting('simrs.expected_database')
       OR current_user <> current_setting('simrs.expected_current_user')
       OR current_setting('server_version_num') <> current_setting('simrs.expected_server_version_num')
       OR observed_ssl <> current_setting('simrs.expected_ssl')::boolean THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: pre-receipt database/user/version/SSL context mismatch';
    END IF;

    IF current_setting('server_version_num')::integer / 10000 <> 17 THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: PostgreSQL major version is not 17';
    END IF;

    IF to_regnamespace('laravel') IS NULL THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: laravel schema is missing';
    END IF;

    SELECT count(*) INTO observed_count
    FROM information_schema.tables
    WHERE table_schema = 'laravel'
      AND table_type = 'BASE TABLE';
    IF observed_count <> 41 THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: expected 41 laravel base tables, found %', observed_count;
    END IF;

    -- Exact ordered ledger: the 31-row reviewed predecessor plus nine rows in
    -- one new batch. A manual ledger repair, split batch, extra row, or reorder fails.
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
            (31, '2026_08_24_000100_create_outpatient_documentation_tables', 7),
            (32, '2026_08_21_000100_create_rebuild_foundation_tables', 8),
            (33, '2026_08_22_000800_add_patient_marital_status', 8),
            (34, '2026_08_22_001000_qualify_laravel_serial_sequence_defaults', 8),
            (35, '2026_08_25_000100_create_break_glass_record_tables', 8),
            (36, '2026_08_25_000200_create_security_ledger_tables', 8),
            (37, '2026_08_25_000300_expand_audit_actor_attribution', 8),
            (38, '2026_08_26_000100_add_operational_worklist_indexes', 8),
            (39, '2026_08_26_000200_create_daily_queue_allocator', 8),
            (40, '2026_08_27_000100_create_teaching_role_access_leases', 8)
    ), actual AS (
        SELECT id, migration::text, batch FROM laravel.migrations
    )
    SELECT count(*) = 40
       AND NOT EXISTS (
           SELECT 1
           FROM expected
           FULL OUTER JOIN actual USING (id, migration, batch)
           WHERE expected.id IS NULL OR actual.id IS NULL
       )
    INTO contract_ok
    FROM actual;
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: exact 40-row migration ledger or batch-8 order drifted';
    END IF;

    -- Exact columns, order, PostgreSQL type, and nullability for every new table.
    WITH expected(table_name, column_names, formatted_types, not_nulls) AS (
        VALUES
        ('break_glass_requests',
         ARRAY['id','public_id','subject_user_id','requester_user_id','subject_snapshot','requester_snapshot','scope_key','capability_snapshot','reason','change_reference','requested_ttl_minutes','requested_at','approval_deadline_at','environment','release_sha','canonical_digest','created_at']::text[],
         ARRAY['bigint','character varying(26)','bigint','bigint','json','json','character varying(64)','json','text','character varying(255)','smallint','timestamp(6) without time zone','timestamp(6) without time zone','character varying(32)','character varying(64)','character varying(64)','timestamp(6) without time zone']::text[],
         ARRAY[true,true,true,true,true,true,true,true,true,true,true,true,true,true,true,true,true]::boolean[]),
        ('break_glass_decisions',
         ARRAY['id','public_id','break_glass_request_id','approver_user_id','approver_snapshot','decision','rationale','assurance_method','assured_at','decided_at','request_digest','environment','release_sha','canonical_digest','created_at']::text[],
         ARRAY['bigint','character varying(26)','bigint','bigint','json','character varying(16)','text','character varying(64)','timestamp(6) without time zone','timestamp(6) without time zone','character varying(64)','character varying(32)','character varying(64)','character varying(64)','timestamp(6) without time zone']::text[],
         ARRAY[true,true,true,true,true,true,true,true,true,true,true,true,true,true,true]::boolean[]),
        ('break_glass_activations',
         ARRAY['id','public_id','break_glass_request_id','break_glass_decision_id','subject_user_id','approved_by_user_id','subject_snapshot','approver_snapshot','scope_key','capability_snapshot','starts_at','expires_at','nonce_version','nonce_digest','request_digest','decision_digest','environment','release_sha','canonical_digest','created_at']::text[],
         ARRAY['bigint','character varying(26)','bigint','bigint','bigint','bigint','json','json','character varying(64)','json','timestamp(6) without time zone','timestamp(6) without time zone','smallint','character varying(64)','character varying(64)','character varying(64)','character varying(32)','character varying(64)','character varying(64)','timestamp(6) without time zone']::text[],
         ARRAY[true,true,true,true,true,true,true,true,true,true,true,true,true,true,true,true,true,true,true,true]::boolean[]),
        ('break_glass_revocations',
         ARRAY['id','public_id','break_glass_activation_id','revoker_user_id','revoker_type','revoker_reference','revoker_snapshot','reason','change_reference','revoked_at','activation_digest','environment','release_sha','canonical_digest','created_at']::text[],
         ARRAY['bigint','character varying(26)','bigint','bigint','character varying(16)','character varying(255)','json','text','character varying(255)','timestamp(6) without time zone','character varying(64)','character varying(32)','character varying(64)','character varying(64)','timestamp(6) without time zone']::text[],
         ARRAY[true,true,true,false,true,true,true,true,true,true,true,true,true,true,true]::boolean[]),
        ('break_glass_session_bindings',
         ARRAY['id','public_id','break_glass_activation_id','subject_user_id','session_reference_hmac','assurance_method','assured_at','bound_at','activation_digest','environment','release_sha','canonical_digest','created_at']::text[],
         ARRAY['bigint','character varying(26)','bigint','bigint','character varying(64)','character varying(64)','timestamp(6) without time zone','timestamp(6) without time zone','character varying(64)','character varying(32)','character varying(64)','character varying(64)','timestamp(6) without time zone']::text[],
         ARRAY[true,true,true,true,true,true,true,true,true,true,true,true,true]::boolean[]),
        ('break_glass_subject_leases',
         ARRAY['id','public_id','subject_user_id','break_glass_activation_id','expires_at','created_at','updated_at']::text[],
         ARRAY['bigint','character varying(26)','bigint','bigint','timestamp(6) without time zone','timestamp(6) without time zone','timestamp(6) without time zone']::text[],
         ARRAY[true,true,true,true,true,false,false]::boolean[]),
        ('security_ledger_entries',
         ARRAY['id','public_id','recorded_at','actor_user_id','actor_type','actor_reference','event_type','resource_type','resource_public_id','outcome','reason','environment','release_sha','request_correlation_id','schema_version','payload','payload_digest','semantic_key','integrity_digest','created_at']::text[],
         ARRAY['bigint','character varying(26)','timestamp(6) without time zone','bigint','character varying(16)','character varying(255)','character varying(128)','character varying(64)','character varying(255)','character varying(16)','text','character varying(32)','character varying(64)','character varying(26)','smallint','json','character varying(64)','character varying(64)','character varying(64)','timestamp(6) without time zone']::text[],
         ARRAY[true,true,true,false,true,true,true,true,true,true,true,true,true,false,true,true,true,true,true,true]::boolean[]),
        ('security_ledger_outboxes',
         ARRAY['id','public_id','security_ledger_entry_id','destination','delivery_state','attempts','available_at','last_attempted_at','delivered_at','last_error_digest','created_at','updated_at']::text[],
         ARRAY['bigint','character varying(26)','bigint','character varying(64)','character varying(16)','integer','timestamp(6) without time zone','timestamp(6) without time zone','timestamp(6) without time zone','character varying(64)','timestamp(6) without time zone','timestamp(6) without time zone']::text[],
         ARRAY[true,true,true,true,true,true,true,false,false,false,false,false]::boolean[]),
        ('daily_queue_counters',
         ARRAY['queue_date','last_number','created_at','updated_at']::text[],
         ARRAY['date','integer','timestamp(0) without time zone','timestamp(0) without time zone']::text[],
         ARRAY[true,true,false,false]::boolean[]),
        ('teaching_role_access_leases',
         ARRAY['id','public_id','user_id','expected_role','credential_commitment','password_state_commitment','environment','release_sha','deployment_url','canonical_host','status','active_slot','activated_at_epoch','expires_at_epoch','ended_at_epoch','end_reason','operator','reason','created_at','updated_at']::text[],
         ARRAY['bigint','character varying(26)','bigint','character varying(64)','character varying(64)','character varying(64)','character varying(64)','character varying(40)','character varying(255)','character varying(255)','character varying(16)','smallint','bigint','bigint','bigint','character varying(240)','character varying(120)','character varying(240)','timestamp(6) without time zone','timestamp(6) without time zone']::text[],
         ARRAY[true,true,true,true,true,true,true,true,true,true,true,false,true,true,false,false,true,true,false,false]::boolean[])
    ), actual AS (
        SELECT table_row.relname::text AS table_name,
               array_agg(attribute.attname::text ORDER BY attribute.attnum) AS column_names,
               array_agg(format_type(attribute.atttypid, attribute.atttypmod) ORDER BY attribute.attnum) AS formatted_types,
               array_agg(attribute.attnotnull ORDER BY attribute.attnum) AS not_nulls,
               bool_and(attribute.attgenerated = '') AS no_generated_columns
        FROM pg_class table_row
        JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
        JOIN pg_attribute attribute ON attribute.attrelid = table_row.oid
        WHERE schema_row.nspname = 'laravel'
          AND table_row.relkind = 'r'
          AND attribute.attnum > 0
          AND NOT attribute.attisdropped
          AND table_row.relname IN (SELECT expected.table_name FROM expected)
        GROUP BY table_row.relname
    )
    SELECT count(*) = 10
       AND NOT EXISTS (
           SELECT 1
           FROM expected
           FULL OUTER JOIN actual USING (table_name, column_names, formatted_types, not_nulls)
           WHERE expected.table_name IS NULL
              OR actual.table_name IS NULL
              OR actual.no_generated_columns IS NOT TRUE
       )
    INTO contract_ok
    FROM actual;
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: exact new-table column/type/nullability contract drifted';
    END IF;

    -- Existing-table additions and the adopted marital-status shape.
    WITH expected(table_name, column_name, formatted_type, not_null, default_expression) AS (
        VALUES
            ('patients','marital_status','character varying',false,NULL::text),
            ('audit_events','actor_type','character varying(16)',false,NULL::text),
            ('audit_events','actor_reference','character varying(255)',false,NULL::text),
            ('encounters','queue_date','date',true,NULL::text),
            ('users','teaching_access_epoch','bigint',true,'0'::text),
            ('users','teaching_access_mutex','bigint',true,'0'::text),
            ('users','teaching_access_roster_key','character varying(64)',false,NULL::text),
            ('users','teaching_access_lease_public_id','character varying(26)',false,NULL::text),
            ('users','teaching_access_expires_at_epoch','bigint',false,NULL::text)
    ), actual AS (
        SELECT table_row.relname::text AS table_name,
               attribute.attname::text AS column_name,
               format_type(attribute.atttypid, attribute.atttypmod) AS formatted_type,
               attribute.attnotnull AS not_null,
               pg_get_expr(default_row.adbin, default_row.adrelid) AS default_expression,
               attribute.attgenerated
        FROM pg_attribute attribute
        JOIN pg_class table_row ON table_row.oid = attribute.attrelid
        JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
        LEFT JOIN pg_attrdef default_row
          ON default_row.adrelid = table_row.oid
         AND default_row.adnum = attribute.attnum
        WHERE schema_row.nspname = 'laravel'
          AND (table_row.relname, attribute.attname) IN (
              SELECT expected.table_name, expected.column_name FROM expected
          )
          AND attribute.attnum > 0
          AND NOT attribute.attisdropped
    )
    SELECT count(*) = 9
       AND NOT EXISTS (
           SELECT 1 FROM expected
           FULL OUTER JOIN actual
             ON actual.table_name = expected.table_name
            AND actual.column_name = expected.column_name
            AND actual.formatted_type = expected.formatted_type
            AND actual.not_null = expected.not_null
            AND actual.default_expression IS NOT DISTINCT FROM expected.default_expression
            AND actual.attgenerated = ''
           WHERE expected.table_name IS NULL OR actual.table_name IS NULL
       )
    INTO contract_ok
    FROM actual;
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: adopted or added column contract drifted';
    END IF;

    -- Reassert the complete adopted audit shape, not only the two added fields.
    -- The 17-column predecessor was exact before the attempt; actor fields append.
    WITH actual AS (
        SELECT array_agg(attribute.attname::text ORDER BY attribute.attnum) AS column_names,
               array_agg(format_type(attribute.atttypid, attribute.atttypmod) ORDER BY attribute.attnum) AS formatted_types,
               array_agg(attribute.attnotnull ORDER BY attribute.attnum) AS not_nulls,
               count(default_row.oid) AS default_count,
               bool_and(attribute.attgenerated = '') AS no_generated_columns
        FROM pg_attribute attribute
        JOIN pg_class table_row ON table_row.oid = attribute.attrelid
        JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
        LEFT JOIN pg_attrdef default_row
          ON default_row.adrelid = table_row.oid
         AND default_row.adnum = attribute.attnum
        WHERE schema_row.nspname = 'laravel'
          AND table_row.relname = 'audit_events'
          AND table_row.relkind = 'r'
          AND attribute.attnum > 0
          AND NOT attribute.attisdropped
    )
    SELECT column_names = ARRAY[
               'id','recorded_at','actor_user_id','assignment_id','session_id',
               'action','resource_type','resource_id','resource_version','outcome',
               'reason','request_correlation_id','ip_hash','user_agent','metadata',
               'patient_id','encounter_id','actor_type','actor_reference'
           ]::text[]
       AND formatted_types = ARRAY[
               'character(26)','timestamp(6) without time zone','bigint','bigint','bigint',
               'character varying(255)','character varying(255)','character varying(255)',
               'character varying(255)','character varying(255)','character varying(255)',
               'character(26)','character varying(64)','character varying(255)','json',
               'bigint','bigint','character varying(16)','character varying(255)'
           ]::text[]
       AND not_nulls = ARRAY[
               true,true,false,false,false,true,true,false,false,true,false,false,false,false,
               false,false,false,false,false
           ]::boolean[]
       AND default_count = 1
       AND no_generated_columns
       AND (
           SELECT pg_get_expr(default_row.adbin, default_row.adrelid)
           FROM pg_attrdef default_row
           JOIN pg_attribute attribute
             ON attribute.attrelid = default_row.adrelid
            AND attribute.attnum = default_row.adnum
           WHERE default_row.adrelid = 'laravel.audit_events'::regclass
             AND attribute.attname = 'outcome'
       ) = '''SUCCESS''::character varying'
    INTO contract_ok
    FROM actual;
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: complete 19-column audit contract drifted';
    END IF;

    -- Only the reviewed defaults may exist on the ten new tables. Every serial
    -- default must be schema-qualified; pooled search_path cannot affect it.
    WITH expected(table_name, column_name, default_expression) AS (
        VALUES
            ('break_glass_requests','id','nextval(''laravel.break_glass_requests_id_seq''::regclass)'),
            ('break_glass_decisions','id','nextval(''laravel.break_glass_decisions_id_seq''::regclass)'),
            ('break_glass_activations','id','nextval(''laravel.break_glass_activations_id_seq''::regclass)'),
            ('break_glass_revocations','id','nextval(''laravel.break_glass_revocations_id_seq''::regclass)'),
            ('break_glass_session_bindings','id','nextval(''laravel.break_glass_session_bindings_id_seq''::regclass)'),
            ('break_glass_subject_leases','id','nextval(''laravel.break_glass_subject_leases_id_seq''::regclass)'),
            ('security_ledger_entries','id','nextval(''laravel.security_ledger_entries_id_seq''::regclass)'),
            ('security_ledger_outboxes','id','nextval(''laravel.security_ledger_outboxes_id_seq''::regclass)'),
            ('security_ledger_outboxes','attempts','0'),
            ('daily_queue_counters','last_number','0'),
            ('teaching_role_access_leases','id','nextval(''laravel.teaching_role_access_leases_id_seq''::regclass)')
    ), actual AS (
        SELECT table_row.relname::text AS table_name,
               attribute.attname::text AS column_name,
               pg_get_expr(default_row.adbin, default_row.adrelid) AS default_expression
        FROM pg_attrdef default_row
        JOIN pg_class table_row ON table_row.oid = default_row.adrelid
        JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
        JOIN pg_attribute attribute
          ON attribute.attrelid = table_row.oid
         AND attribute.attnum = default_row.adnum
        WHERE schema_row.nspname = 'laravel'
          AND table_row.relname IN (
              'break_glass_requests','break_glass_decisions','break_glass_activations',
              'break_glass_revocations','break_glass_session_bindings','break_glass_subject_leases',
              'security_ledger_entries','security_ledger_outboxes','daily_queue_counters',
              'teaching_role_access_leases'
          )
    )
    SELECT count(*) = 11
       AND NOT EXISTS (
           SELECT 1 FROM expected
           FULL OUTER JOIN actual USING (table_name, column_name, default_expression)
           WHERE expected.table_name IS NULL OR actual.table_name IS NULL
       )
    INTO contract_ok
    FROM actual;
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: new-table defaults drifted';
    END IF;

    -- All 13 adopted serials and all nine new serials must be real, same-owner,
    -- owned-by-id sequences with one exact qualified nextval dependency.
    WITH expected(table_name, sequence_name) AS (
        VALUES
            ('patients','patients_id_seq'),('encounters','encounters_id_seq'),
            ('clinical_entries','clinical_entries_id_seq'),('clinics','clinics_id_seq'),
            ('doctors','doctors_id_seq'),('clinic_schedules','clinic_schedules_id_seq'),
            ('users','users_id_seq'),('roles','roles_id_seq'),('permissions','permissions_id_seq'),
            ('jobs','jobs_id_seq'),('failed_jobs','failed_jobs_id_seq'),
            ('passkeys','passkeys_id_seq'),('migrations','migrations_id_seq'),
            ('break_glass_requests','break_glass_requests_id_seq'),
            ('break_glass_decisions','break_glass_decisions_id_seq'),
            ('break_glass_activations','break_glass_activations_id_seq'),
            ('break_glass_revocations','break_glass_revocations_id_seq'),
            ('break_glass_session_bindings','break_glass_session_bindings_id_seq'),
            ('break_glass_subject_leases','break_glass_subject_leases_id_seq'),
            ('security_ledger_entries','security_ledger_entries_id_seq'),
            ('security_ledger_outboxes','security_ledger_outboxes_id_seq'),
            ('teaching_role_access_leases','teaching_role_access_leases_id_seq')
    ), contracts AS (
        SELECT expected.table_name,
               table_row.relkind = 'r'
               AND format_type(attribute.atttypid, attribute.atttypmod) IN ('integer','bigint')
               AND attribute.attnotnull
               AND sequence_row.relkind = 'S'
               AND table_row.relowner = sequence_row.relowner
               AND pg_get_expr(default_row.adbin, default_row.adrelid)
                   = format('nextval(''laravel.%s''::regclass)', expected.sequence_name)
               AND EXISTS (
                   SELECT 1 FROM pg_depend dependency
                   WHERE dependency.classid = 'pg_class'::regclass
                     AND dependency.objid = sequence_row.oid
                     AND dependency.refclassid = 'pg_class'::regclass
                     AND dependency.refobjid = table_row.oid
                     AND dependency.refobjsubid = attribute.attnum
                     AND dependency.deptype IN ('a','i')
               )
               AND EXISTS (
                   SELECT 1 FROM pg_depend dependency
                   WHERE dependency.classid = 'pg_attrdef'::regclass
                     AND dependency.objid = default_row.oid
                     AND dependency.refclassid = 'pg_class'::regclass
                     AND dependency.refobjid = sequence_row.oid
                     AND dependency.deptype = 'n'
               ) AS exact
        FROM expected
        LEFT JOIN pg_namespace schema_row ON schema_row.nspname = 'laravel'
        LEFT JOIN pg_class table_row
          ON table_row.relnamespace = schema_row.oid
         AND table_row.relname = expected.table_name
        LEFT JOIN pg_attribute attribute
          ON attribute.attrelid = table_row.oid
         AND attribute.attname = 'id'
         AND attribute.attnum > 0
         AND NOT attribute.attisdropped
        LEFT JOIN pg_attrdef default_row
          ON default_row.adrelid = table_row.oid
         AND default_row.adnum = attribute.attnum
        LEFT JOIN pg_class sequence_row
          ON sequence_row.relnamespace = schema_row.oid
         AND sequence_row.relname = expected.sequence_name
    )
    SELECT count(*) = 22 AND bool_and(exact) INTO contract_ok FROM contracts;
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: one or more of 22 sequence contracts drifted';
    END IF;

    -- Exact indexes for the ten new tables plus the four reviewed additions.
    WITH expected(index_name, table_name, columns, is_unique, is_primary, predicate) AS (
        VALUES
            ('break_glass_requests_pkey','break_glass_requests',ARRAY['id']::text[],true,true,NULL::text),
            ('bgr_public_id_uq','break_glass_requests',ARRAY['public_id']::text[],true,false,NULL),
            ('bgr_canonical_digest_uq','break_glass_requests',ARRAY['canonical_digest']::text[],true,false,NULL),
            ('bgr_subject_requested_idx','break_glass_requests',ARRAY['subject_user_id','requested_at']::text[],false,false,NULL),
            ('bgr_requester_requested_idx','break_glass_requests',ARRAY['requester_user_id','requested_at']::text[],false,false,NULL),
            ('bgr_scope_requested_idx','break_glass_requests',ARRAY['scope_key','requested_at']::text[],false,false,NULL),
            ('break_glass_decisions_pkey','break_glass_decisions',ARRAY['id']::text[],true,true,NULL),
            ('bgd_public_id_uq','break_glass_decisions',ARRAY['public_id']::text[],true,false,NULL),
            ('bgd_request_uq','break_glass_decisions',ARRAY['break_glass_request_id']::text[],true,false,NULL),
            ('bgd_canonical_digest_uq','break_glass_decisions',ARRAY['canonical_digest']::text[],true,false,NULL),
            ('bgd_approver_decided_idx','break_glass_decisions',ARRAY['approver_user_id','decided_at']::text[],false,false,NULL),
            ('break_glass_activations_pkey','break_glass_activations',ARRAY['id']::text[],true,true,NULL),
            ('bga_public_id_uq','break_glass_activations',ARRAY['public_id']::text[],true,false,NULL),
            ('bga_request_uq','break_glass_activations',ARRAY['break_glass_request_id']::text[],true,false,NULL),
            ('bga_decision_uq','break_glass_activations',ARRAY['break_glass_decision_id']::text[],true,false,NULL),
            ('bga_nonce_digest_uq','break_glass_activations',ARRAY['nonce_digest']::text[],true,false,NULL),
            ('bga_canonical_digest_uq','break_glass_activations',ARRAY['canonical_digest']::text[],true,false,NULL),
            ('bga_subject_expires_idx','break_glass_activations',ARRAY['subject_user_id','expires_at']::text[],false,false,NULL),
            ('break_glass_revocations_pkey','break_glass_revocations',ARRAY['id']::text[],true,true,NULL),
            ('bgrv_public_id_uq','break_glass_revocations',ARRAY['public_id']::text[],true,false,NULL),
            ('bgrv_activation_uq','break_glass_revocations',ARRAY['break_glass_activation_id']::text[],true,false,NULL),
            ('bgrv_canonical_digest_uq','break_glass_revocations',ARRAY['canonical_digest']::text[],true,false,NULL),
            ('bgrv_revoker_revoked_idx','break_glass_revocations',ARRAY['revoker_user_id','revoked_at']::text[],false,false,NULL),
            ('break_glass_session_bindings_pkey','break_glass_session_bindings',ARRAY['id']::text[],true,true,NULL),
            ('bgsb_public_id_uq','break_glass_session_bindings',ARRAY['public_id']::text[],true,false,NULL),
            ('bgsb_activation_uq','break_glass_session_bindings',ARRAY['break_glass_activation_id']::text[],true,false,NULL),
            ('bgsb_session_hmac_uq','break_glass_session_bindings',ARRAY['session_reference_hmac']::text[],true,false,NULL),
            ('bgsb_canonical_digest_uq','break_glass_session_bindings',ARRAY['canonical_digest']::text[],true,false,NULL),
            ('bgsb_subject_bound_idx','break_glass_session_bindings',ARRAY['subject_user_id','bound_at']::text[],false,false,NULL),
            ('break_glass_subject_leases_pkey','break_glass_subject_leases',ARRAY['id']::text[],true,true,NULL),
            ('bgsl_public_id_uq','break_glass_subject_leases',ARRAY['public_id']::text[],true,false,NULL),
            ('bgsl_subject_uq','break_glass_subject_leases',ARRAY['subject_user_id']::text[],true,false,NULL),
            ('bgsl_activation_uq','break_glass_subject_leases',ARRAY['break_glass_activation_id']::text[],true,false,NULL),
            ('bgsl_expires_idx','break_glass_subject_leases',ARRAY['expires_at']::text[],false,false,NULL),
            ('security_ledger_entries_pkey','security_ledger_entries',ARRAY['id']::text[],true,true,NULL),
            ('sle_public_id_uq','security_ledger_entries',ARRAY['public_id']::text[],true,false,NULL),
            ('sle_semantic_key_uq','security_ledger_entries',ARRAY['semantic_key']::text[],true,false,NULL),
            ('sle_integrity_digest_uq','security_ledger_entries',ARRAY['integrity_digest']::text[],true,false,NULL),
            ('sle_event_recorded_idx','security_ledger_entries',ARRAY['event_type','recorded_at']::text[],false,false,NULL),
            ('sle_resource_idx','security_ledger_entries',ARRAY['resource_type','resource_public_id']::text[],false,false,NULL),
            ('sle_actor_recorded_idx','security_ledger_entries',ARRAY['actor_user_id','recorded_at']::text[],false,false,NULL),
            ('sle_request_correlation_idx','security_ledger_entries',ARRAY['request_correlation_id']::text[],false,false,NULL),
            ('security_ledger_outboxes_pkey','security_ledger_outboxes',ARRAY['id']::text[],true,true,NULL),
            ('slo_public_id_uq','security_ledger_outboxes',ARRAY['public_id']::text[],true,false,NULL),
            ('slo_entry_uq','security_ledger_outboxes',ARRAY['security_ledger_entry_id']::text[],true,false,NULL),
            ('slo_pending_idx','security_ledger_outboxes',ARRAY['delivery_state','available_at']::text[],false,false,NULL),
            ('daily_queue_counters_pkey','daily_queue_counters',ARRAY['queue_date']::text[],true,true,NULL),
            ('teaching_role_access_leases_pkey','teaching_role_access_leases',ARRAY['id']::text[],true,true,NULL),
            ('tral_public_id_uq','teaching_role_access_leases',ARRAY['public_id']::text[],true,false,NULL),
            ('tral_credential_commitment_uq','teaching_role_access_leases',ARRAY['credential_commitment']::text[],true,false,NULL),
            ('tral_one_active_slot_uq','teaching_role_access_leases',ARRAY['user_id','active_slot']::text[],true,false,NULL),
            ('tral_user_status_expires_idx','teaching_role_access_leases',ARRAY['user_id','status','expires_at_epoch']::text[],false,false,NULL),
            ('tral_status_expires_idx','teaching_role_access_leases',ARRAY['status','expires_at_epoch']::text[],false,false,NULL),
            ('tral_one_active_per_user_uq','teaching_role_access_leases',ARRAY['user_id']::text[],true,false,'((status)::text = ''ACTIVE''::text)'),
            ('audit_actor_type_reference_idx','audit_events',ARRAY['actor_type','actor_reference']::text[],false,false,NULL),
            ('encounters_care_registered_id_idx','encounters',ARRAY['care_setting','registered_at','id']::text[],false,false,NULL),
            ('lab_requests_status_requested_id_idx','lab_service_requests',ARRAY['status','requested_at','id']::text[],false,false,NULL),
            ('encounters_queue_date_number_unique','encounters',ARRAY['queue_date','queue_number']::text[],true,false,NULL),
            ('users_teaching_access_roster_key_uq','users',ARRAY['teaching_access_roster_key']::text[],true,false,NULL),
            ('users_teaching_access_expires_idx','users',ARRAY['teaching_access_expires_at_epoch']::text[],false,false,NULL)
    ), actual AS (
        SELECT index_row.relname::text AS index_name,
               table_row.relname::text AS table_name,
               array_agg(attribute.attname::text ORDER BY key_row.ordinality) AS columns,
               index_meta.indisunique AS is_unique,
               index_meta.indisprimary AS is_primary,
               pg_get_expr(index_meta.indpred, index_meta.indrelid) AS predicate,
               access_method.amname = 'btree'
                 AND index_meta.indisvalid
                 AND index_meta.indisready
                 AND index_meta.indislive
                 AND index_meta.indimmediate
                 AND index_meta.indexprs IS NULL
                 AND index_meta.indnkeyatts = index_meta.indnatts AS healthy
        FROM pg_index index_meta
        JOIN pg_class table_row ON table_row.oid = index_meta.indrelid
        JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
        JOIN pg_class index_row ON index_row.oid = index_meta.indexrelid
        JOIN pg_am access_method ON access_method.oid = index_row.relam
        CROSS JOIN LATERAL unnest(index_meta.indkey) WITH ORDINALITY key_row(attnum, ordinality)
        JOIN pg_attribute attribute
          ON attribute.attrelid = table_row.oid
         AND attribute.attnum = key_row.attnum
        WHERE schema_row.nspname = 'laravel'
          AND index_row.relname IN (SELECT expected.index_name FROM expected)
        GROUP BY index_row.relname, table_row.relname, index_meta.indisunique,
                 index_meta.indisprimary, index_meta.indpred, index_meta.indrelid,
                 access_method.amname, index_meta.indisvalid, index_meta.indisready,
                 index_meta.indislive, index_meta.indimmediate, index_meta.indexprs,
                 index_meta.indnkeyatts, index_meta.indnatts
    )
    SELECT count(*) = 60
       AND NOT EXISTS (
           SELECT 1 FROM expected
           FULL OUTER JOIN actual
             ON actual.index_name = expected.index_name
            AND actual.table_name = expected.table_name
            AND actual.columns = expected.columns
            AND actual.is_unique = expected.is_unique
            AND actual.is_primary = expected.is_primary
            AND actual.predicate IS NOT DISTINCT FROM expected.predicate
            AND actual.healthy
           WHERE expected.index_name IS NULL OR actual.index_name IS NULL
       )
    INTO contract_ok
    FROM actual;
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: required index contract drifted';
    END IF;

    SELECT count(*) = 54 INTO contract_ok
    FROM pg_index index_meta
    JOIN pg_class table_row ON table_row.oid = index_meta.indrelid
    JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
    WHERE schema_row.nspname = 'laravel'
      AND table_row.relname IN (
          'break_glass_requests','break_glass_decisions','break_glass_activations',
          'break_glass_revocations','break_glass_session_bindings','break_glass_subject_leases',
          'security_ledger_entries','security_ledger_outboxes','daily_queue_counters',
          'teaching_role_access_leases'
      );
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: candidate tables contain an extra or missing index';
    END IF;

    SELECT count(*) = 8
       AND array_agg(index_row.relname::text ORDER BY index_row.relname) = ARRAY[
           'audit_actor_type_reference_idx','audit_case_context','audit_events_action_index',
           'audit_events_outcome_index','audit_events_pkey','audit_events_recorded_at_index',
           'audit_events_request_correlation_id_index','audit_resource_lookup'
       ]::text[]
    INTO contract_ok
    FROM pg_index index_meta
    JOIN pg_class table_row ON table_row.oid = index_meta.indrelid
    JOIN pg_class index_row ON index_row.oid = index_meta.indexrelid
    WHERE table_row.oid = 'laravel.audit_events'::regclass;
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: exact eight-index audit contract drifted';
    END IF;

    -- Exact restrictive foreign keys created or replaced by the candidate.
    WITH expected(constraint_name, source_table, source_columns, target_table, target_columns) AS (
        VALUES
            ('bgr_subject_user_fk','break_glass_requests',ARRAY['subject_user_id']::text[],'users',ARRAY['id']::text[]),
            ('bgr_requester_user_fk','break_glass_requests',ARRAY['requester_user_id']::text[],'users',ARRAY['id']::text[]),
            ('bgd_request_fk','break_glass_decisions',ARRAY['break_glass_request_id']::text[],'break_glass_requests',ARRAY['id']::text[]),
            ('bgd_approver_user_fk','break_glass_decisions',ARRAY['approver_user_id']::text[],'users',ARRAY['id']::text[]),
            ('bga_request_fk','break_glass_activations',ARRAY['break_glass_request_id']::text[],'break_glass_requests',ARRAY['id']::text[]),
            ('bga_decision_fk','break_glass_activations',ARRAY['break_glass_decision_id']::text[],'break_glass_decisions',ARRAY['id']::text[]),
            ('bga_subject_user_fk','break_glass_activations',ARRAY['subject_user_id']::text[],'users',ARRAY['id']::text[]),
            ('bga_approved_by_user_fk','break_glass_activations',ARRAY['approved_by_user_id']::text[],'users',ARRAY['id']::text[]),
            ('bgrv_activation_fk','break_glass_revocations',ARRAY['break_glass_activation_id']::text[],'break_glass_activations',ARRAY['id']::text[]),
            ('bgrv_revoker_user_fk','break_glass_revocations',ARRAY['revoker_user_id']::text[],'users',ARRAY['id']::text[]),
            ('bgsb_activation_fk','break_glass_session_bindings',ARRAY['break_glass_activation_id']::text[],'break_glass_activations',ARRAY['id']::text[]),
            ('bgsb_subject_user_fk','break_glass_session_bindings',ARRAY['subject_user_id']::text[],'users',ARRAY['id']::text[]),
            ('bgsl_subject_user_fk','break_glass_subject_leases',ARRAY['subject_user_id']::text[],'users',ARRAY['id']::text[]),
            ('bgsl_activation_fk','break_glass_subject_leases',ARRAY['break_glass_activation_id']::text[],'break_glass_activations',ARRAY['id']::text[]),
            ('sle_actor_user_fk','security_ledger_entries',ARRAY['actor_user_id']::text[],'users',ARRAY['id']::text[]),
            ('slo_entry_fk','security_ledger_outboxes',ARRAY['security_ledger_entry_id']::text[],'security_ledger_entries',ARRAY['id']::text[]),
            ('audit_events_actor_user_fk','audit_events',ARRAY['actor_user_id']::text[],'users',ARRAY['id']::text[]),
            ('tral_user_fk','teaching_role_access_leases',ARRAY['user_id']::text[],'users',ARRAY['id']::text[])
    ), actual AS (
        SELECT constraint_row.conname::text AS constraint_name,
               source_table.relname::text AS source_table,
               source_columns.columns AS source_columns,
               target_table.relname::text AS target_table,
               target_columns.columns AS target_columns,
               constraint_row.confupdtype = 'a'
                 AND constraint_row.confdeltype = 'r'
                 AND constraint_row.confmatchtype = 's'
                 AND NOT constraint_row.condeferrable
                 AND NOT constraint_row.condeferred
                 AND constraint_row.convalidated AS healthy
        FROM pg_constraint constraint_row
        JOIN pg_class source_table ON source_table.oid = constraint_row.conrelid
        JOIN pg_namespace source_schema ON source_schema.oid = source_table.relnamespace
        JOIN pg_class target_table ON target_table.oid = constraint_row.confrelid
        JOIN pg_namespace target_schema ON target_schema.oid = target_table.relnamespace
        CROSS JOIN LATERAL (
            SELECT array_agg(attribute.attname::text ORDER BY key_row.ordinality) AS columns
            FROM unnest(constraint_row.conkey) WITH ORDINALITY key_row(attnum, ordinality)
            JOIN pg_attribute attribute
              ON attribute.attrelid = source_table.oid AND attribute.attnum = key_row.attnum
        ) source_columns
        CROSS JOIN LATERAL (
            SELECT array_agg(attribute.attname::text ORDER BY key_row.ordinality) AS columns
            FROM unnest(constraint_row.confkey) WITH ORDINALITY key_row(attnum, ordinality)
            JOIN pg_attribute attribute
              ON attribute.attrelid = target_table.oid AND attribute.attnum = key_row.attnum
        ) target_columns
        WHERE source_schema.nspname = 'laravel'
          AND target_schema.nspname = 'laravel'
          AND constraint_row.contype = 'f'
          AND constraint_row.conname IN (SELECT expected.constraint_name FROM expected)
    )
    SELECT count(*) = 18
       AND NOT EXISTS (
           SELECT 1 FROM expected
           FULL OUTER JOIN actual
             ON actual.constraint_name = expected.constraint_name
            AND actual.source_table = expected.source_table
            AND actual.source_columns = expected.source_columns
            AND actual.target_table = expected.target_table
            AND actual.target_columns = expected.target_columns
            AND actual.healthy
           WHERE expected.constraint_name IS NULL OR actual.constraint_name IS NULL
       )
    INTO contract_ok
    FROM actual;
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: restrictive foreign-key contract drifted';
    END IF;

    SELECT count(*) = 55 INTO contract_ok
    FROM pg_constraint constraint_row
    JOIN pg_class table_row ON table_row.oid = constraint_row.conrelid
    JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
    WHERE schema_row.nspname = 'laravel'
      AND table_row.relname IN (
          'break_glass_requests','break_glass_decisions','break_glass_activations',
          'break_glass_revocations','break_glass_session_bindings','break_glass_subject_leases',
          'security_ledger_entries','security_ledger_outboxes','daily_queue_counters',
          'teaching_role_access_leases'
      );
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: candidate tables contain an extra or missing constraint';
    END IF;

    SELECT count(*) = 1 INTO contract_ok
    FROM pg_constraint
    WHERE conrelid = 'laravel.audit_events'::regclass
      AND contype = 'f';
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: audit table contains an extra or missing foreign key';
    END IF;

    SELECT count(*) = 1
       AND bool_and(convalidated)
       AND bool_and(NOT condeferrable)
       AND bool_and(pg_get_constraintdef(oid, false) =
           'CHECK ((((teaching_access_roster_key IS NULL) AND ((email)::text <> ALL ((ARRAY[''registrar.demo@example.invalid''::character varying, ''nurse.demo@example.invalid''::character varying, ''physician.demo@example.invalid''::character varying, ''rmik.demo@example.invalid''::character varying])::text[]))) OR (((email)::text = ''registrar.demo@example.invalid''::text) AND ((teaching_access_roster_key)::text = ''registrar''::text)) OR (((email)::text = ''nurse.demo@example.invalid''::text) AND ((teaching_access_roster_key)::text = ''nurse''::text)) OR (((email)::text = ''physician.demo@example.invalid''::text) AND ((teaching_access_roster_key)::text = ''physician''::text)) OR (((email)::text = ''rmik.demo@example.invalid''::text) AND ((teaching_access_roster_key)::text = ''rmik''::text))))')
    INTO contract_ok
    FROM pg_constraint
    WHERE connamespace = 'laravel'::regnamespace
      AND conrelid = 'laravel.users'::regclass
      AND conname = 'users_teaching_access_roster_mapping_ck'
      AND contype = 'c';
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: teaching roster mapping check drifted';
    END IF;

    -- Exact PG17-normalized pg_get_functiondef digests were derived from a
    -- transaction-rolled-back fixture containing the migration definitions.
    -- Attribute checks prevent a matching body from weakening execution flags.
    WITH expected(function_name, normalized_definition_sha256) AS (
        VALUES
            ('enforce_teaching_roster_identity_immutability','0314fb16721a7c56b5bd0115ae81f7d5a4b355af81aed3f81870330e281c9512'),
            ('protect_security_ledger_outbox_mutation','68d23680d3ee5bc9d12d7725fe41cad2bd94d8cb81377247b44d4e49d79de22b'),
            ('reject_protected_fact_mutation','3b1235f22060c8b8dfc8290e34956ba7f994590868b53de294b7a15a294e6563')
    ), actual AS (
        SELECT function_row.proname::text AS function_name,
               encode(sha256(convert_to(
                   btrim(regexp_replace(pg_get_functiondef(function_row.oid), '[[:space:]]+', ' ', 'g')),
                   'UTF8'
               )), 'hex') AS normalized_definition_sha256,
               language_row.lanname = 'plpgsql'
                 AND function_row.pronargs = 0
                 AND function_row.pronargdefaults = 0
                 AND function_row.prorettype = 'trigger'::regtype
                 AND function_row.provolatile = 'v'
                 AND function_row.proparallel = 'u'
                 AND NOT function_row.prosecdef
                 AND NOT function_row.proleakproof
                 AND NOT function_row.proisstrict
                 AND function_row.proconfig IS NULL AS exact_attributes
        FROM pg_proc function_row
        JOIN pg_namespace schema_row ON schema_row.oid = function_row.pronamespace
        JOIN pg_language language_row ON language_row.oid = function_row.prolang
        WHERE schema_row.nspname = 'laravel'
          AND function_row.proname IN (
              'reject_protected_fact_mutation',
              'protect_security_ledger_outbox_mutation',
              'enforce_teaching_roster_identity_immutability'
          )
    )
    SELECT count(*) = 3
       AND NOT EXISTS (
           SELECT 1 FROM expected
           FULL OUTER JOIN actual USING (function_name, normalized_definition_sha256)
           WHERE expected.function_name IS NULL
              OR actual.function_name IS NULL
              OR actual.exact_attributes IS NOT TRUE
       )
    INTO contract_ok
    FROM actual;
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: mutation-guard function contract drifted';
    END IF;

    -- Twelve append-only fact triggers, three controlled-outbox triggers, and
    -- one roster-identity trigger. No trigger is disabled or misbound.
    WITH expected(trigger_name, table_name, function_identity, trigger_type, update_of_columns) AS (
        VALUES
            ('security_ledger_entries_no_update','security_ledger_entries','laravel.reject_protected_fact_mutation()',19,ARRAY[]::text[]),
            ('security_ledger_entries_no_delete','security_ledger_entries','laravel.reject_protected_fact_mutation()',11,ARRAY[]::text[]),
            ('break_glass_requests_no_update','break_glass_requests','laravel.reject_protected_fact_mutation()',19,ARRAY[]::text[]),
            ('break_glass_requests_no_delete','break_glass_requests','laravel.reject_protected_fact_mutation()',11,ARRAY[]::text[]),
            ('break_glass_decisions_no_update','break_glass_decisions','laravel.reject_protected_fact_mutation()',19,ARRAY[]::text[]),
            ('break_glass_decisions_no_delete','break_glass_decisions','laravel.reject_protected_fact_mutation()',11,ARRAY[]::text[]),
            ('break_glass_activations_no_update','break_glass_activations','laravel.reject_protected_fact_mutation()',19,ARRAY[]::text[]),
            ('break_glass_activations_no_delete','break_glass_activations','laravel.reject_protected_fact_mutation()',11,ARRAY[]::text[]),
            ('break_glass_revocations_no_update','break_glass_revocations','laravel.reject_protected_fact_mutation()',19,ARRAY[]::text[]),
            ('break_glass_revocations_no_delete','break_glass_revocations','laravel.reject_protected_fact_mutation()',11,ARRAY[]::text[]),
            ('break_glass_session_bindings_no_update','break_glass_session_bindings','laravel.reject_protected_fact_mutation()',19,ARRAY[]::text[]),
            ('break_glass_session_bindings_no_delete','break_glass_session_bindings','laravel.reject_protected_fact_mutation()',11,ARRAY[]::text[]),
            ('security_ledger_outboxes_validate_insert','security_ledger_outboxes','laravel.protect_security_ledger_outbox_mutation()',7,ARRAY[]::text[]),
            ('security_ledger_outboxes_protect_update','security_ledger_outboxes','laravel.protect_security_ledger_outbox_mutation()',19,ARRAY[]::text[]),
            ('security_ledger_outboxes_no_delete','security_ledger_outboxes','laravel.protect_security_ledger_outbox_mutation()',11,ARRAY[]::text[]),
            ('users_teaching_roster_identity_immutable_trg','users','laravel.enforce_teaching_roster_identity_immutability()',19,ARRAY['email','teaching_access_roster_key']::text[])
    ), actual AS (
        SELECT trigger_row.tgname::text AS trigger_name,
               table_row.relname::text AS table_name,
               trigger_row.tgfoid::regprocedure::text AS function_identity,
               trigger_row.tgtype::integer AS trigger_type,
               trigger_attributes.update_of_columns,
               trigger_row.tgenabled = 'O'
                 AND NOT trigger_row.tgisinternal
                 AND trigger_row.tgqual IS NULL
                 AND trigger_row.tgconstraint = 0
                 AND NOT trigger_row.tgdeferrable
                 AND NOT trigger_row.tginitdeferred
                 AND trigger_row.tgnargs = 0
                 AND trigger_row.tgoldtable IS NULL
                 AND trigger_row.tgnewtable IS NULL AS healthy
        FROM pg_trigger trigger_row
        JOIN pg_class table_row ON table_row.oid = trigger_row.tgrelid
        JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
        CROSS JOIN LATERAL (
            SELECT COALESCE(
                array_agg(attribute.attname::text ORDER BY key_row.ordinality),
                ARRAY[]::text[]
            ) AS update_of_columns
            FROM unnest(trigger_row.tgattr) WITH ORDINALITY key_row(attnum, ordinality)
            JOIN pg_attribute attribute
              ON attribute.attrelid = table_row.oid
             AND attribute.attnum = key_row.attnum
        ) trigger_attributes
        WHERE schema_row.nspname = 'laravel'
          AND trigger_row.tgname IN (SELECT expected.trigger_name FROM expected)
    )
    SELECT count(*) = 16
       AND NOT EXISTS (
           SELECT 1 FROM expected
           FULL OUTER JOIN actual USING (
               trigger_name, table_name, function_identity, trigger_type, update_of_columns
           )
           WHERE expected.trigger_name IS NULL
              OR actual.trigger_name IS NULL
              OR actual.healthy IS NOT TRUE
       )
    INTO contract_ok
    FROM actual;
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: exact 16-trigger contract drifted';
    END IF;

    -- Complete candidate-created table set: differently named extra triggers
    -- cannot hide behind the expected-name lookup above.
    SELECT count(*) = 15
       AND array_agg(trigger_row.tgname::text ORDER BY trigger_row.tgname) = (
           SELECT array_agg(expected_name ORDER BY expected_name)
           FROM unnest(ARRAY[
               'security_ledger_entries_no_update','security_ledger_entries_no_delete',
               'break_glass_requests_no_update','break_glass_requests_no_delete',
               'break_glass_decisions_no_update','break_glass_decisions_no_delete',
               'break_glass_activations_no_update','break_glass_activations_no_delete',
               'break_glass_revocations_no_update','break_glass_revocations_no_delete',
               'break_glass_session_bindings_no_update','break_glass_session_bindings_no_delete',
               'security_ledger_outboxes_validate_insert',
               'security_ledger_outboxes_protect_update','security_ledger_outboxes_no_delete'
           ]::text[]) expected_name
       )
    INTO contract_ok
    FROM pg_trigger trigger_row
    JOIN pg_class table_row ON table_row.oid = trigger_row.tgrelid
    JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
    WHERE schema_row.nspname = 'laravel'
      AND NOT trigger_row.tgisinternal
      AND table_row.relname IN (
          'break_glass_requests','break_glass_decisions','break_glass_activations',
          'break_glass_revocations','break_glass_session_bindings','break_glass_subject_leases',
          'security_ledger_entries','security_ledger_outboxes','daily_queue_counters',
          'teaching_role_access_leases'
      );
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: candidate tables contain an extra or missing non-internal trigger';
    END IF;

    -- The predecessor evidence did not inventory unrelated users triggers, so
    -- do not falsely require users to have only one trigger. Instead, bind the
    -- one migration-owned name/OID exactly and reject every additional users
    -- trigger that invokes any of the three candidate functions.
    SELECT count(*) = 1
       AND bool_and(
           trigger_row.tgname = 'users_teaching_roster_identity_immutable_trg'
           AND trigger_row.tgfoid = 'laravel.enforce_teaching_roster_identity_immutability()'::regprocedure
       )
    INTO contract_ok
    FROM pg_trigger trigger_row
    WHERE trigger_row.tgrelid = 'laravel.users'::regclass
      AND NOT trigger_row.tgisinternal
      AND (
          trigger_row.tgname = 'users_teaching_roster_identity_immutable_trg'
          OR trigger_row.tgfoid IN (
              'laravel.reject_protected_fact_mutation()'::regprocedure,
              'laravel.protect_security_ledger_outbox_mutation()'::regprocedure,
              'laravel.enforce_teaching_roster_identity_immutability()'::regprocedure
          )
      );
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: users candidate-trigger ownership/binding drifted';
    END IF;

    -- Recompute the exact pre-receipt preservation contract. Hardcoded hosted
    -- counts are prohibited: the captured contract and SHA-256 are authoritative.
    expected_preservation := current_setting('simrs.expected_preservation_contract')::jsonb;
    IF encode(sha256(convert_to(expected_preservation::text, 'UTF8')), 'hex')
           <> current_setting('simrs.expected_preservation_contract_sha256') THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: supplied preservation JSON does not match its pre-receipt digest';
    END IF;

    IF expected_preservation->>'schema_version' <> '1'
       OR expected_preservation->>'algorithm'
          <> 'sha256(sorted sha256(to_jsonb(row) minus intentional candidate columns))'
       OR expected_preservation->'excluded_volatile_tables' <> to_jsonb(ARRAY[
           'cache','cache_locks','failed_jobs','job_batches','jobs','migrations','sessions'
       ]::text[])
       OR expected_preservation->'intentional_column_exclusions' <> jsonb_build_object(
           'users', ARRAY['teaching_access_epoch','teaching_access_mutex','teaching_access_roster_key','teaching_access_lease_public_id','teaching_access_expires_at_epoch'],
           'audit_events', ARRAY['actor_type','actor_reference'],
           'encounters', ARRAY['queue_date','queue_number']
       )
       OR jsonb_typeof(expected_preservation->'tables') <> 'object'
       OR (SELECT count(*) FROM jsonb_object_keys(expected_preservation->'tables')) <> 24 THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: preservation contract scope/version was weakened or malformed';
    END IF;

    WITH expected_names AS (
        SELECT jsonb_object_keys(expected_preservation->'tables') AS table_name
    ), actual_names AS (
        SELECT table_class.relname::text AS table_name
        FROM pg_class table_class
        JOIN pg_namespace schema_row ON schema_row.oid = table_class.relnamespace
        WHERE schema_row.nspname = 'laravel'
          AND table_class.relkind = 'r'
          AND table_class.relname NOT IN (
              'migrations','cache','cache_locks','sessions','jobs','job_batches','failed_jobs',
              'break_glass_requests','break_glass_decisions','break_glass_activations',
              'break_glass_revocations','break_glass_session_bindings','break_glass_subject_leases',
              'security_ledger_entries','security_ledger_outboxes','daily_queue_counters',
              'teaching_role_access_leases'
          )
    )
    SELECT NOT EXISTS (
        SELECT 1 FROM expected_names
        FULL OUTER JOIN actual_names USING (table_name)
        WHERE expected_names.table_name IS NULL OR actual_names.table_name IS NULL
    ) INTO contract_ok;
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: preserved table-name set differs from the pre receipt';
    END IF;

    FOR table_row IN
        SELECT jsonb_object_keys(expected_preservation->'tables') AS table_name
        ORDER BY table_name
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

    actual_preservation := jsonb_build_object(
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

    IF actual_preservation <> expected_preservation THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: preexisting nonvolatile row count/content preservation failed';
    END IF;
    IF EXISTS (
        SELECT 1 FROM laravel.audit_events
        WHERE actor_type IS NOT NULL OR actor_reference IS NOT NULL
    ) THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: migration unexpectedly backfilled ordinary-audit attribution';
    END IF;

    PERFORM set_config('simrs.actual_preservation_contract', actual_preservation::text, true);
    PERFORM set_config(
        'simrs.actual_preservation_contract_sha256',
        encode(sha256(convert_to(actual_preservation::text, 'UTF8')), 'hex'),
        true
    );

    SELECT NOT EXISTS (
        SELECT 1 FROM laravel.patients WHERE is_synthetic IS NOT TRUE
    ) INTO contract_ok;
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: synthetic-only patient boundary failed';
    END IF;

    -- Migration-time security tables must still be empty before any smoke write.
    SELECT NOT EXISTS (
        SELECT 1
        FROM (VALUES
            ('break_glass_requests'),('break_glass_decisions'),('break_glass_activations'),
            ('break_glass_revocations'),('break_glass_session_bindings'),('break_glass_subject_leases'),
            ('security_ledger_entries'),('security_ledger_outboxes'),('teaching_role_access_leases')
        ) expected(table_name)
        WHERE CASE expected.table_name
            WHEN 'break_glass_requests' THEN EXISTS (SELECT 1 FROM laravel.break_glass_requests)
            WHEN 'break_glass_decisions' THEN EXISTS (SELECT 1 FROM laravel.break_glass_decisions)
            WHEN 'break_glass_activations' THEN EXISTS (SELECT 1 FROM laravel.break_glass_activations)
            WHEN 'break_glass_revocations' THEN EXISTS (SELECT 1 FROM laravel.break_glass_revocations)
            WHEN 'break_glass_session_bindings' THEN EXISTS (SELECT 1 FROM laravel.break_glass_session_bindings)
            WHEN 'break_glass_subject_leases' THEN EXISTS (SELECT 1 FROM laravel.break_glass_subject_leases)
            WHEN 'security_ledger_entries' THEN EXISTS (SELECT 1 FROM laravel.security_ledger_entries)
            WHEN 'security_ledger_outboxes' THEN EXISTS (SELECT 1 FROM laravel.security_ledger_outboxes)
            WHEN 'teaching_role_access_leases' THEN EXISTS (SELECT 1 FROM laravel.teaching_role_access_leases)
            ELSE true END
    ) INTO contract_ok;
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: pre-smoke protected/access tables are not empty';
    END IF;

    -- Transactional queue allocator: exact migration reassignment, no gaps or
    -- duplicates, and one counter high-water mark for every queue date.
    SELECT NOT EXISTS (
               SELECT 1 FROM laravel.encounters
               WHERE registered_at IS NULL OR queue_date IS NULL OR queue_number IS NULL
                  OR queue_date <> registered_at::date
           )
       AND NOT EXISTS (
               SELECT 1
               FROM (
                   SELECT queue_date, count(*) AS row_count,
                          count(DISTINCT queue_number) AS distinct_count,
                          min(queue_number) AS minimum_number,
                          max(queue_number) AS maximum_number
                   FROM laravel.encounters GROUP BY queue_date
               ) grouped
               WHERE distinct_count <> row_count
                  OR minimum_number <> 1
                  OR maximum_number <> row_count
           )
       AND NOT EXISTS (
               SELECT 1
               FROM (
                   SELECT id, queue_date, queue_number,
                          row_number() OVER (
                              PARTITION BY queue_date ORDER BY registered_at, id
                          ) AS expected_number
                   FROM laravel.encounters
               ) numbered
               WHERE queue_number <> expected_number
           )
       AND NOT EXISTS (
               SELECT 1
               FROM (
                   SELECT queue_date, max(queue_number)::integer AS last_number
                   FROM laravel.encounters GROUP BY queue_date
               ) expected
               FULL OUTER JOIN laravel.daily_queue_counters actual
                 ON actual.queue_date = expected.queue_date
                AND actual.last_number = expected.last_number
               WHERE expected.queue_date IS NULL OR actual.queue_date IS NULL
           )
    INTO contract_ok;
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: daily queue allocation/counter invariant drifted';
    END IF;

    -- Teaching-role access starts fail-closed: exact four synthetic roster
    -- mappings, all accounts disabled, zero fences, and no lease record.
    SELECT count(*) FILTER (WHERE teaching_access_roster_key IS NOT NULL) = 4
       AND count(*) FILTER (WHERE teaching_access_epoch <> 0 OR teaching_access_mutex <> 0) = 0
       AND count(*) FILTER (
           WHERE teaching_access_lease_public_id IS NOT NULL
              OR teaching_access_expires_at_epoch IS NOT NULL
       ) = 0
       AND count(*) FILTER (
           WHERE (email, teaching_access_roster_key) IN (
               ('registrar.demo@example.invalid','registrar'),
               ('nurse.demo@example.invalid','nurse'),
               ('physician.demo@example.invalid','physician'),
               ('rmik.demo@example.invalid','rmik')
           )
       ) = 4
       AND count(*) FILTER (
           WHERE teaching_access_roster_key IS NOT NULL AND status <> 'DISABLED'
       ) = 0
    INTO contract_ok
    FROM laravel.users;
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: teaching roster/fence/disabled-account invariant drifted';
    END IF;

    -- Supabase Data API roles must exist but have no schema, table, column,
    -- sequence, MAINTAIN, or routine EXECUTE privilege in laravel. PostgreSQL's
    -- usual PUBLIC EXECUTE default is not waived: if inherited here, this check
    -- intentionally returns NO-GO pending separately reviewed privilege hardening.
    -- This is grant-denial evidence; RLS is not relied upon.
    WITH api_roles(role_name) AS (
        VALUES ('anon'),('authenticated'),('authenticator'),('service_role')
    ), role_contracts AS (
        SELECT role_name,
               to_regrole(role_name) IS NOT NULL AS role_exists,
               has_schema_privilege(role_name, 'laravel', 'USAGE') AS schema_usage,
               has_schema_privilege(role_name, 'laravel', 'CREATE') AS schema_create,
               EXISTS (
                   SELECT 1 FROM pg_class relation_row
                   JOIN pg_namespace schema_row ON schema_row.oid = relation_row.relnamespace
                   WHERE schema_row.nspname = 'laravel'
                     AND relation_row.relkind IN ('r','p','v','m','f')
                     AND has_table_privilege(
                         role_name, relation_row.oid,
                         'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN'
                     )
               ) AS any_relation_privilege,
               EXISTS (
                   SELECT 1 FROM pg_class relation_row
                   JOIN pg_namespace schema_row ON schema_row.oid = relation_row.relnamespace
                   WHERE schema_row.nspname = 'laravel'
                     AND relation_row.relkind IN ('r','p','v','m','f')
                     AND has_any_column_privilege(
                         role_name, relation_row.oid, 'SELECT,INSERT,UPDATE,REFERENCES'
                     )
               ) AS any_column_privilege,
               EXISTS (
                   SELECT 1 FROM pg_class sequence_row
                   JOIN pg_namespace schema_row ON schema_row.oid = sequence_row.relnamespace
                   WHERE schema_row.nspname = 'laravel'
                     AND sequence_row.relkind = 'S'
                     AND has_sequence_privilege(role_name, sequence_row.oid, 'USAGE,SELECT,UPDATE')
               ) AS any_sequence_privilege,
               EXISTS (
                   SELECT 1 FROM pg_proc routine_row
                   JOIN pg_namespace schema_row ON schema_row.oid = routine_row.pronamespace
                   WHERE schema_row.nspname = 'laravel'
                     AND routine_row.prokind IN ('f','p')
                     AND has_function_privilege(role_name, routine_row.oid, 'EXECUTE')
               ) AS any_routine_execute_privilege
        FROM api_roles
    )
    SELECT count(*) = 4
       AND bool_and(role_exists)
       AND NOT bool_or(
           schema_usage OR schema_create OR any_relation_privilege OR any_column_privilege
           OR any_sequence_privilege OR any_routine_execute_privilege
       )
    INTO contract_ok
    FROM role_contracts;
    IF contract_ok IS NOT TRUE THEN
        RAISE EXCEPTION 'POST_MIGRATION_NO_GO: Data API role denial contract drifted';
    END IF;
END;
$acceptance$ LANGUAGE plpgsql;

-- One deterministic, value-minimized evidence object. It contains no patient
-- row diagnostics, identity values, audit payloads, reasons, network data, or secrets.
WITH api_roles(role_name) AS (
    VALUES ('anon'),('authenticated'),('authenticator'),('service_role')
), api_denials AS (
    SELECT role_name,
           NOT has_schema_privilege(role_name, 'laravel', 'USAGE') AS schema_usage_denied,
           NOT has_schema_privilege(role_name, 'laravel', 'CREATE') AS schema_create_denied,
           NOT EXISTS (
               SELECT 1 FROM pg_class relation_row
               JOIN pg_namespace schema_row ON schema_row.oid = relation_row.relnamespace
               WHERE schema_row.nspname = 'laravel'
                 AND relation_row.relkind IN ('r','p','v','m','f')
                 AND has_table_privilege(
                     role_name, relation_row.oid,
                     'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN'
                 )
           ) AS all_relation_privileges_denied,
           NOT EXISTS (
               SELECT 1 FROM pg_class relation_row
               JOIN pg_namespace schema_row ON schema_row.oid = relation_row.relnamespace
               WHERE schema_row.nspname = 'laravel'
                 AND relation_row.relkind IN ('r','p','v','m','f')
                 AND has_any_column_privilege(
                     role_name, relation_row.oid, 'SELECT,INSERT,UPDATE,REFERENCES'
                 )
           ) AS all_column_privileges_denied,
           NOT EXISTS (
               SELECT 1 FROM pg_class sequence_row
               JOIN pg_namespace schema_row ON schema_row.oid = sequence_row.relnamespace
               WHERE schema_row.nspname = 'laravel'
                 AND sequence_row.relkind = 'S'
                 AND has_sequence_privilege(role_name, sequence_row.oid, 'USAGE,SELECT,UPDATE')
           ) AS all_sequence_privileges_denied,
           EXISTS (
               SELECT 1 FROM pg_proc routine_row
               JOIN pg_namespace schema_row ON schema_row.oid = routine_row.pronamespace
               WHERE schema_row.nspname = 'laravel'
                 AND routine_row.prokind IN ('f','p')
                 AND has_function_privilege(role_name, routine_row.oid, 'EXECUTE')
           ) AS routine_execute_grant_present,
           NOT EXISTS (
                 SELECT 1 FROM pg_proc routine_row
                 JOIN pg_namespace schema_row ON schema_row.oid = routine_row.pronamespace
                 WHERE schema_row.nspname = 'laravel'
                   AND routine_row.prokind IN ('f','p')
                   AND has_function_privilege(role_name, routine_row.oid, 'EXECUTE')
           ) AS all_routine_execute_privileges_denied
    FROM api_roles
), ssl_state AS (
    SELECT COALESCE(ssl, false) AS ssl,
           version AS ssl_version,
           cipher AS ssl_cipher
    FROM pg_stat_ssl
    WHERE pid = pg_backend_pid()
), execution_context AS (
    SELECT current_database() AS database_name,
           current_user::text AS current_user_name,
           current_setting('server_version') AS server_version,
           current_setting('server_version_num')::integer AS server_version_num,
           COALESCE((SELECT ssl FROM ssl_state), false) AS ssl,
           (SELECT ssl_version FROM ssl_state) AS ssl_version,
           (SELECT ssl_cipher FROM ssl_state) AS ssl_cipher
), counts AS (
    SELECT
        (SELECT count(*) FROM laravel.users) AS users,
        (SELECT count(*) FROM laravel.audit_events) AS audit_events,
        (SELECT count(*) FROM laravel.patients) AS synthetic_patients,
        (SELECT count(*) FROM laravel.encounters) AS encounters,
        (SELECT count(*) FROM laravel.daily_queue_counters) AS queue_dates,
        (SELECT count(*) FROM laravel.teaching_role_access_leases) AS teaching_access_leases,
        (SELECT count(*) FROM laravel.security_ledger_entries) AS security_ledger_entries
)
SELECT jsonb_build_object(
    'status', 'POST_MIGRATION_CONTRACT_MATCH',
    'promotion_authorized', false,
    'boundary', 'SYNTHETIC_TEACHING_ONLY',
    'sql_sha256_supplied_for_external_binding', current_setting('simrs.sql_sha256'),
    'expected_pre_receipt_sha256', current_setting('simrs.expected_pre_receipt_sha256'),
    'target_context', to_jsonb(execution_context),
    'target_binding_limit', 'Database context only; Supabase project identity requires the external receipt described in this SQL header.',
    'postgres_major', 17,
    'schema', 'laravel',
    'base_table_count', 41,
    'migration_ledger', jsonb_build_object(
        'rows', 40,
        'last_batch', 8,
        'candidate_rows', 9,
        'first_candidate', '2026_08_21_000100_create_rebuild_foundation_tables',
        'last_candidate', '2026_08_27_000100_create_teaching_role_access_leases'
    ),
    'catalog_contracts', jsonb_build_object(
        'candidate_new_tables', 10,
        'qualified_sequences', 22,
        'required_indexes', 60,
        'restrictive_foreign_keys', 18,
        'mutation_guard_functions', 3,
        'candidate_triggers', 16,
        'roster_mapping_check_validated', true
    ),
    'preservation_contract_sha256', current_setting('simrs.actual_preservation_contract_sha256'),
    'preserved_counts', jsonb_build_object(
        'ordinary_audit_attribution', current_setting('simrs.actual_preservation_contract')::jsonb->'ordinary_audit_attribution',
        'users_public_id', current_setting('simrs.actual_preservation_contract')::jsonb->'users_public_id',
        'preserved_nonvolatile_tables', (
            SELECT count(*) FROM jsonb_object_keys(
                current_setting('simrs.actual_preservation_contract')::jsonb->'tables'
            )
        )
    ),
    'value_minimized_current_counts', jsonb_build_object(
        'synthetic_patients', synthetic_patients,
        'encounters', encounters,
        'queue_dates', queue_dates,
        'teaching_access_leases', teaching_access_leases,
        'security_ledger_entries', security_ledger_entries
    ),
    'invariants', jsonb_build_object(
        'synthetic_only', true,
        'ordinary_audit_attribution_preserved', true,
        'protected_security_tables_empty_before_smoke', true,
        'daily_queue_allocator_consistent', true,
        'teaching_role_access_fail_closed', true,
        'data_api_roles_denied', true
    ),
    'data_api_role_denials', (
        SELECT jsonb_agg(to_jsonb(api_denials) ORDER BY role_name) FROM api_denials
    )
) AS nine_migration_postmigration_acceptance
FROM counts
CROSS JOIN execution_context;

COMMIT;
