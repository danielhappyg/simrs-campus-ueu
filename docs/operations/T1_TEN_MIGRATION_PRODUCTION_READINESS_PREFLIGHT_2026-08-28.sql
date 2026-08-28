-- SIMRS Campus UEU: exact known-predecessor Production readiness preflight.
-- PostgreSQL 17, schema laravel, read-only. This query validates only the
-- pre-migration state observed and reviewed on 2026-08-27 for the exact
-- ten-migration candidate frozen on 2026-08-28. It does not validate
-- a post-migration schema and never authorizes migration or Vercel promotion.

WITH expected_ledger(id, migration, batch) AS (
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
), actual_ledger AS (
    SELECT id, migration, batch
    FROM laravel.migrations
), ledger_contract AS (
    SELECT (SELECT count(*) FROM actual_ledger) = (SELECT count(*) FROM expected_ledger)
       AND NOT EXISTS (
           SELECT 1
           FROM expected_ledger expected
           FULL OUTER JOIN actual_ledger actual
             ON actual.id = expected.id
            AND actual.migration = expected.migration
            AND actual.batch = expected.batch
           WHERE expected.id IS NULL OR actual.id IS NULL
       ) AS exact
), candidate_migrations(name) AS (
    VALUES
        ('2026_08_21_000100_create_rebuild_foundation_tables'),
        ('2026_08_22_000800_add_patient_marital_status'),
        ('2026_08_22_001000_qualify_laravel_serial_sequence_defaults'),
        ('2026_08_25_000100_create_break_glass_record_tables'),
        ('2026_08_25_000200_create_security_ledger_tables'),
        ('2026_08_25_000300_expand_audit_actor_attribution'),
        ('2026_08_26_000100_add_operational_worklist_indexes'),
        ('2026_08_26_000200_create_daily_queue_allocator'),
        ('2026_08_27_000100_create_teaching_role_access_leases'),
        ('2026_08_28_000100_create_inpatient_bed_claim_mutexes')
), expected_audit_columns(attnum, column_name, formatted_type, not_null, default_expression) AS (
    VALUES
        (1, 'id', 'character(26)', true, NULL::text),
        (2, 'recorded_at', 'timestamp(6) without time zone', true, NULL::text),
        (3, 'actor_user_id', 'bigint', false, NULL::text),
        (4, 'assignment_id', 'bigint', false, NULL::text),
        (5, 'session_id', 'bigint', false, NULL::text),
        (6, 'action', 'character varying(255)', true, NULL::text),
        (7, 'resource_type', 'character varying(255)', true, NULL::text),
        (8, 'resource_id', 'character varying(255)', false, NULL::text),
        (9, 'resource_version', 'character varying(255)', false, NULL::text),
        (10, 'outcome', 'character varying(255)', true, '''SUCCESS''::character varying'),
        (11, 'reason', 'character varying(255)', false, NULL::text),
        (12, 'request_correlation_id', 'character(26)', false, NULL::text),
        (13, 'ip_hash', 'character varying(64)', false, NULL::text),
        (14, 'user_agent', 'character varying(255)', false, NULL::text),
        (15, 'metadata', 'json', false, NULL::text),
        (16, 'patient_id', 'bigint', false, NULL::text),
        (17, 'encounter_id', 'bigint', false, NULL::text)
), actual_audit_columns AS (
    SELECT attribute.attnum,
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
      AND table_row.relname = 'audit_events'
      AND table_row.relkind = 'r'
      AND attribute.attnum > 0
      AND NOT attribute.attisdropped
), audit_column_contract AS (
    SELECT (SELECT count(*) FROM actual_audit_columns) = (SELECT count(*) FROM expected_audit_columns)
       AND NOT EXISTS (
           SELECT 1
           FROM expected_audit_columns expected
           FULL OUTER JOIN actual_audit_columns actual
             ON actual.attnum = expected.attnum
            AND actual.column_name = expected.column_name
            AND actual.formatted_type = expected.formatted_type
            AND actual.not_null = expected.not_null
            AND actual.default_expression IS NOT DISTINCT FROM expected.default_expression
            AND actual.attgenerated = ''
           WHERE expected.attnum IS NULL OR actual.attnum IS NULL
       ) AS exact
), expected_audit_indexes(
    index_name,
    columns,
    opclasses,
    collations,
    index_options,
    is_primary,
    is_unique
) AS (
    VALUES
        ('audit_case_context', ARRAY['session_id', 'patient_id', 'encounter_id']::text[], ARRAY['int8_ops', 'int8_ops', 'int8_ops']::text[], ARRAY['0', '0', '0']::text[], '0 0 0', false, false),
        ('audit_events_action_index', ARRAY['action']::text[], ARRAY['text_ops']::text[], ARRAY['default']::text[], '0', false, false),
        ('audit_events_outcome_index', ARRAY['outcome']::text[], ARRAY['text_ops']::text[], ARRAY['default']::text[], '0', false, false),
        ('audit_events_pkey', ARRAY['id']::text[], ARRAY['bpchar_ops']::text[], ARRAY['default']::text[], '0', true, true),
        ('audit_events_recorded_at_index', ARRAY['recorded_at']::text[], ARRAY['timestamp_ops']::text[], ARRAY['0']::text[], '0', false, false),
        ('audit_events_request_correlation_id_index', ARRAY['request_correlation_id']::text[], ARRAY['bpchar_ops']::text[], ARRAY['default']::text[], '0', false, false),
        ('audit_resource_lookup', ARRAY['resource_type', 'resource_id']::text[], ARRAY['text_ops', 'text_ops']::text[], ARRAY['default', 'default']::text[], '0 0', false, false)
), actual_audit_indexes AS (
    SELECT index_row.relname::text AS index_name,
           (
               SELECT array_agg(attribute.attname::text ORDER BY key_row.ordinality)
               FROM unnest(index_meta.indkey) WITH ORDINALITY key_row(attnum, ordinality)
               JOIN pg_attribute attribute
                 ON attribute.attrelid = table_row.oid
                AND attribute.attnum = key_row.attnum
           ) AS columns,
           (
               SELECT array_agg(opclass.opcname::text ORDER BY class_row.ordinality)
               FROM unnest(index_meta.indclass) WITH ORDINALITY class_row(opclass_oid, ordinality)
               JOIN pg_opclass opclass ON opclass.oid = class_row.opclass_oid
           ) AS opclasses,
           (
               SELECT array_agg(COALESCE(collation_definition.collname::text, '0') ORDER BY collation_row.ordinality)
               FROM unnest(index_meta.indcollation) WITH ORDINALITY collation_row(collation_oid, ordinality)
               LEFT JOIN pg_collation collation_definition
                 ON collation_definition.oid = collation_row.collation_oid
           ) AS collations,
           index_meta.indoption::text AS index_options,
           access_method.amname::text AS access_method,
           index_meta.indnkeyatts,
           index_meta.indnatts,
           index_meta.indisprimary AS is_primary,
           index_meta.indisunique AS is_unique,
           index_meta.indisvalid AS is_valid,
           index_meta.indisready AS is_ready,
           index_meta.indislive AS is_live,
           index_meta.indimmediate AS is_immediate,
           index_meta.indpred IS NULL AS has_no_predicate,
           index_meta.indexprs IS NULL AS has_no_expressions
    FROM pg_index index_meta
    JOIN pg_class table_row ON table_row.oid = index_meta.indrelid
    JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
    JOIN pg_class index_row ON index_row.oid = index_meta.indexrelid
    JOIN pg_am access_method ON access_method.oid = index_row.relam
    WHERE schema_row.nspname = 'laravel'
      AND table_row.relname = 'audit_events'
), audit_index_contract AS (
    SELECT (SELECT count(*) FROM actual_audit_indexes) = (SELECT count(*) FROM expected_audit_indexes)
       AND NOT EXISTS (
           SELECT 1
           FROM expected_audit_indexes expected
           FULL OUTER JOIN actual_audit_indexes actual
            ON actual.index_name = expected.index_name
            AND actual.columns = expected.columns
            AND actual.opclasses = expected.opclasses
            AND actual.collations = expected.collations
            AND actual.index_options = expected.index_options
            AND actual.access_method = 'btree'
            AND actual.indnkeyatts = cardinality(expected.columns)
            AND actual.indnatts = cardinality(expected.columns)
            AND actual.is_primary = expected.is_primary
            AND actual.is_unique = expected.is_unique
            AND actual.is_valid
            AND actual.is_ready
            AND actual.is_live
            AND actual.is_immediate
            AND actual.has_no_predicate
            AND actual.has_no_expressions
           WHERE expected.index_name IS NULL OR actual.index_name IS NULL
       ) AS exact
), audit_foreign_key_contract AS (
    SELECT count(*) = 1
       AND bool_and(constraint_row.conname = 'audit_events_actor_user_id_foreign')
       AND bool_and(constraint_row.contype = 'f')
       AND bool_and(constraint_row.confupdtype = 'a')
       AND bool_and(constraint_row.confdeltype = 'n')
       AND bool_and(constraint_row.confmatchtype = 's')
       AND bool_and(NOT constraint_row.condeferrable)
       AND bool_and(NOT constraint_row.condeferred)
       AND bool_and(constraint_row.convalidated)
       AND bool_and(source_columns.columns = ARRAY['actor_user_id']::text[])
       AND bool_and(reference_schema.nspname = 'laravel')
       AND bool_and(reference_table.relname = 'users')
       AND bool_and(reference_columns.columns = ARRAY['id']::text[]) AS exact
    FROM pg_constraint constraint_row
    JOIN pg_class source_table ON source_table.oid = constraint_row.conrelid
    JOIN pg_namespace source_schema ON source_schema.oid = source_table.relnamespace
    JOIN pg_class reference_table ON reference_table.oid = constraint_row.confrelid
    JOIN pg_namespace reference_schema ON reference_schema.oid = reference_table.relnamespace
    CROSS JOIN LATERAL (
        SELECT array_agg(attribute.attname::text ORDER BY key_row.ordinality) AS columns
        FROM unnest(constraint_row.conkey) WITH ORDINALITY key_row(attnum, ordinality)
        JOIN pg_attribute attribute
          ON attribute.attrelid = source_table.oid
         AND attribute.attnum = key_row.attnum
    ) source_columns
    CROSS JOIN LATERAL (
        SELECT array_agg(attribute.attname::text ORDER BY key_row.ordinality) AS columns
        FROM unnest(constraint_row.confkey) WITH ORDINALITY key_row(attnum, ordinality)
        JOIN pg_attribute attribute
          ON attribute.attrelid = reference_table.oid
         AND attribute.attnum = key_row.attnum
    ) reference_columns
    WHERE source_schema.nspname = 'laravel'
      AND source_table.relname = 'audit_events'
      AND constraint_row.contype = 'f'
), expected_sequences(table_name, sequence_name) AS (
    VALUES
        ('patients', 'patients_id_seq'),
        ('encounters', 'encounters_id_seq'),
        ('clinical_entries', 'clinical_entries_id_seq'),
        ('clinics', 'clinics_id_seq'),
        ('doctors', 'doctors_id_seq'),
        ('clinic_schedules', 'clinic_schedules_id_seq'),
        ('users', 'users_id_seq'),
        ('roles', 'roles_id_seq'),
        ('permissions', 'permissions_id_seq'),
        ('jobs', 'jobs_id_seq'),
        ('failed_jobs', 'failed_jobs_id_seq'),
        ('passkeys', 'passkeys_id_seq'),
        ('migrations', 'migrations_id_seq')
), sequence_contracts AS (
    SELECT expected.table_name,
           table_row.relkind = 'r'
             AND format_type(attribute.atttypid, attribute.atttypmod) IN ('integer', 'bigint')
             AND attribute.attnotnull
             AND sequence_row.relkind = 'S'
             AND table_row.relowner = sequence_row.relowner
             AND EXISTS (
                 SELECT 1
                 FROM pg_depend owned
                 WHERE owned.classid = 'pg_class'::regclass
                   AND owned.objid = sequence_row.oid
                   AND owned.refclassid = 'pg_class'::regclass
                   AND owned.refobjid = table_row.oid
                   AND owned.refobjsubid = attribute.attnum
                   AND owned.deptype IN ('a', 'i')
             )
             AND EXISTS (
                 SELECT 1
                 FROM pg_depend dependency
                 WHERE dependency.classid = 'pg_attrdef'::regclass
                   AND dependency.objid = default_row.oid
                   AND dependency.refclassid = 'pg_class'::regclass
                   AND dependency.refobjid = sequence_row.oid
                   AND dependency.deptype = 'n'
             ) AS safe_to_qualify,
           pg_get_expr(default_row.adbin, default_row.adrelid) IN (
               format('nextval(''%s''::regclass)', expected.sequence_name),
               format('nextval(''laravel.%s''::regclass)', expected.sequence_name)
           ) AS canonical_default
    FROM expected_sequences expected
    LEFT JOIN pg_namespace schema_row ON schema_row.nspname = 'laravel'
    LEFT JOIN pg_class table_row
      ON table_row.relnamespace = schema_row.oid
     AND table_row.relname = expected.table_name
     AND table_row.relkind IN ('r', 'p')
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
     AND sequence_row.relkind = 'S'
), expected_teaching_access_relations(name) AS (
    VALUES
        ('teaching_role_access_leases'),
        ('teaching_role_access_leases_id_seq'),
        ('teaching_role_access_leases_pkey'),
        ('users_teaching_access_roster_key_uq'),
        ('users_teaching_access_expires_idx'),
        ('tral_public_id_uq'),
        ('tral_credential_commitment_uq'),
        ('tral_one_active_slot_uq'),
        ('tral_one_active_per_user_uq'),
        ('tral_user_status_expires_idx'),
        ('tral_status_expires_idx')
), expected_teaching_access_user_columns(name) AS (
    VALUES
        ('teaching_access_epoch'),
        ('teaching_access_mutex'),
        ('teaching_access_roster_key'),
        ('teaching_access_lease_public_id'),
        ('teaching_access_expires_at_epoch')
), teaching_role_access_predecessor AS (
    SELECT NOT EXISTS (
               SELECT 1
               FROM expected_teaching_access_relations expected
               WHERE to_regclass(format('%I.%I', 'laravel', expected.name)) IS NOT NULL
           )
       AND NOT EXISTS (
               SELECT 1
               FROM information_schema.columns column_row
               JOIN expected_teaching_access_user_columns expected
                 ON expected.name = column_row.column_name
               WHERE column_row.table_schema = 'laravel'
                 AND column_row.table_name = 'users'
           )
       AND NOT EXISTS (
               SELECT 1
               FROM pg_constraint constraint_row
               JOIN pg_class table_row ON table_row.oid = constraint_row.conrelid
               JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
               WHERE schema_row.nspname = 'laravel'
                 AND constraint_row.conname = 'tral_user_fk'
           ) AS exact
), inpatient_bed_claim_predecessor AS (
    SELECT to_regclass('laravel.inpatient_bed_claim_mutexes') IS NULL
       AND to_regclass('laravel.inpatient_bed_claim_mutexes_pkey') IS NULL
       AND to_regclass('laravel.encounters_care_bed_status_idx') IS NULL AS exact
), candidate_schema_objects_absent AS (
    SELECT NOT EXISTS (
               SELECT 1
               FROM pg_class relation_row
               JOIN pg_namespace schema_row ON schema_row.oid = relation_row.relnamespace
               WHERE schema_row.nspname = 'laravel'
                 AND (
                     relation_row.relname ~ '^(break_glass_|security_ledger_|daily_queue_counters|teaching_role_access_leases|inpatient_bed_claim_mutexes)'
                     OR relation_row.relname ~ '^(bgr_|bgd_|bga_|bgrv_|bgsb_|bgsl_|sle_|slo_|tral_)'
                     OR relation_row.relname IN (
                         'audit_actor_type_reference_idx',
                         'encounters_care_registered_id_idx',
                         'lab_requests_status_requested_id_idx',
                         'encounters_queue_date_number_unique',
                         'encounters_care_bed_status_idx',
                         'users_teaching_access_roster_key_uq',
                         'users_teaching_access_expires_idx'
                     )
                 )
           )
       AND NOT EXISTS (
               SELECT 1
               FROM pg_proc function_row
               JOIN pg_namespace schema_row ON schema_row.oid = function_row.pronamespace
               WHERE schema_row.nspname = 'laravel'
                 AND function_row.proname IN (
                     'reject_protected_fact_mutation',
                     'protect_security_ledger_outbox_mutation',
                     'enforce_teaching_roster_identity_immutability'
                 )
           )
       AND NOT EXISTS (
               SELECT 1
               FROM pg_trigger trigger_row
               JOIN pg_class table_row ON table_row.oid = trigger_row.tgrelid
               JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
               WHERE schema_row.nspname = 'laravel'
                 AND NOT trigger_row.tgisinternal
                 AND (
                     trigger_row.tgname ~ '^(break_glass_|security_ledger_)'
                     OR trigger_row.tgname IN (
                         'security_ledger_outboxes_validate_insert',
                         'security_ledger_outboxes_protect_update',
                         'security_ledger_outboxes_no_delete',
                         'users_teaching_roster_identity_immutable_trg'
                     )
                 )
           )
       AND NOT EXISTS (
               SELECT 1
               FROM pg_constraint constraint_row
               JOIN pg_namespace schema_row ON schema_row.oid = constraint_row.connamespace
               WHERE schema_row.nspname = 'laravel'
                 AND (
                     constraint_row.conname ~ '^(bgr_|bgd_|bga_|bgrv_|bgsb_|bgsl_|sle_|slo_|tral_)'
                     OR constraint_row.conname ~ '^(break_glass_|security_ledger_|daily_queue_counters_|teaching_role_access_leases_|inpatient_bed_claim_mutexes_)'
                     OR constraint_row.conname = 'users_teaching_access_roster_mapping_ck'
                 )
           ) AS exact
), api_roles(role_name) AS (
    VALUES ('anon'), ('authenticated'), ('authenticator'), ('service_role')
), role_privileges AS (
    SELECT role_name,
           has_schema_privilege(role_name, 'laravel', 'USAGE') AS schema_usage,
           has_schema_privilege(role_name, 'laravel', 'CREATE') AS schema_create,
           has_table_privilege(role_name, 'laravel.patients', 'SELECT') AS patient_select,
           has_table_privilege(role_name, 'laravel.patients', 'INSERT') AS patient_insert,
           has_table_privilege(role_name, 'laravel.users', 'SELECT') AS users_select,
           has_table_privilege(role_name, 'laravel.audit_events', 'SELECT') AS audit_select
    FROM api_roles
), facts AS (
    SELECT timezone('UTC', statement_timestamp()) AS captured_at_utc,
           current_setting('server_version_num')::integer / 10000 = 17 AS postgres_17,
           (SELECT exact FROM ledger_contract) AS exact_predecessor_ledger,
           NOT EXISTS (
               SELECT 1
               FROM laravel.migrations recorded
               JOIN candidate_migrations candidate ON candidate.name = recorded.migration
           ) AS all_candidate_migrations_pending,
           (SELECT exact FROM audit_column_contract) AS exact_audit_columns,
           (SELECT exact FROM audit_index_contract) AS exact_audit_indexes,
           (SELECT exact FROM audit_foreign_key_contract) AS exact_audit_foreign_key,
           EXISTS (
               SELECT 1
               FROM pg_attribute attribute
               JOIN pg_class table_row ON table_row.oid = attribute.attrelid
               JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
               LEFT JOIN pg_attrdef default_row
                 ON default_row.adrelid = table_row.oid
                AND default_row.adnum = attribute.attnum
               WHERE schema_row.nspname = 'laravel'
                 AND table_row.relname = 'patients'
                 AND table_row.relkind = 'r'
                 AND attribute.attname = 'marital_status'
                 AND format_type(attribute.atttypid, attribute.atttypmod) = 'character varying'
                 AND NOT attribute.attnotnull
                 AND default_row.oid IS NULL
                 AND attribute.attgenerated = ''
           ) AS exact_marital_status,
           NOT EXISTS (
               SELECT 1
               FROM sequence_contracts
               WHERE safe_to_qualify IS NOT TRUE
                  OR canonical_default IS NOT TRUE
           ) AS exact_sequence_predecessor,
           (SELECT exact FROM teaching_role_access_predecessor)
               AS exact_teaching_role_access_predecessor,
           (SELECT exact FROM inpatient_bed_claim_predecessor)
               AS exact_inpatient_bed_claim_predecessor,
           (SELECT exact FROM candidate_schema_objects_absent) AND NOT EXISTS (
               SELECT 1
               FROM information_schema.columns
               WHERE table_schema = 'laravel'
                 AND table_name = 'encounters'
                 AND column_name = 'queue_date'
           ) AS candidate_physical_state_absent,
           NOT EXISTS (
               SELECT 1
               FROM laravel.patients
               WHERE is_synthetic IS NOT TRUE
           ) AS synthetic_only,
           NOT EXISTS (
               SELECT 1
               FROM laravel.encounters
               WHERE registered_at IS NULL
           ) AS all_encounters_have_registration_time,
           NOT EXISTS (
               SELECT 1
               FROM role_privileges
               WHERE schema_usage
                  OR schema_create
                  OR patient_select
                  OR patient_insert
                  OR users_select
                  OR audit_select
           ) AS data_api_roles_denied
)
SELECT jsonb_build_object(
    'status', CASE
        WHEN postgres_17
         AND exact_predecessor_ledger
         AND all_candidate_migrations_pending
         AND exact_audit_columns
         AND exact_audit_indexes
         AND exact_audit_foreign_key
         AND exact_marital_status
         AND exact_sequence_predecessor
         AND exact_teaching_role_access_predecessor
         AND exact_inpatient_bed_claim_predecessor
         AND candidate_physical_state_absent
         AND synthetic_only
         AND all_encounters_have_registration_time
         AND data_api_roles_denied
            THEN 'PRE_MIGRATION_CONTRACT_MATCH'
        ELSE 'NO_GO_SCHEMA_DRIFT'
    END,
    'promotion_authorized', false,
    'captured_at_utc', captured_at_utc,
    'facts', jsonb_build_object(
        'postgres_17', postgres_17,
        'exact_predecessor_ledger', exact_predecessor_ledger,
        'all_candidate_migrations_pending', all_candidate_migrations_pending,
        'exact_audit_columns', exact_audit_columns,
        'exact_audit_indexes', exact_audit_indexes,
        'exact_audit_foreign_key', exact_audit_foreign_key,
        'exact_marital_status', exact_marital_status,
        'exact_sequence_predecessor', exact_sequence_predecessor,
        'exact_teaching_role_access_predecessor', exact_teaching_role_access_predecessor,
        'exact_inpatient_bed_claim_predecessor', exact_inpatient_bed_claim_predecessor,
        'candidate_physical_state_absent', candidate_physical_state_absent,
        'synthetic_only', synthetic_only,
        'all_encounters_have_registration_time', all_encounters_have_registration_time,
        'data_api_roles_denied', data_api_roles_denied
    ),
    'candidate_migrations', (
        SELECT jsonb_agg(name ORDER BY name)
        FROM candidate_migrations
    ),
    'data_api_role_privileges', (
        SELECT jsonb_agg(to_jsonb(role_privileges) ORDER BY role_name)
        FROM role_privileges
    )
) AS ten_migration_production_readiness
FROM facts;
