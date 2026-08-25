-- BG-02c4b G1 value-minimized source/restore acceptance query set.
-- Run byte-for-byte unchanged with PostgreSQL 17 psql --no-psqlrc
-- --set=ON_ERROR_STOP=1 --csv. Hash the complete stdout artifact.

SELECT count(*) AS laravel_base_table_count
FROM information_schema.tables
WHERE table_schema = 'laravel'
  AND table_type = 'BASE TABLE';

SELECT count(*) AS migration_rows,
       max(batch) AS last_batch,
       (SELECT migration FROM laravel.migrations ORDER BY id DESC LIMIT 1) AS last_migration
FROM laravel.migrations;

SELECT id, migration, batch
FROM laravel.migrations
ORDER BY id;

SELECT count(*) AS audit_rows,
       count(actor_user_id) AS audit_rows_with_actor_fk,
       count(*) FILTER (WHERE actor_user_id IS NULL) AS audit_rows_without_actor_fk
FROM laravel.audit_events;

SELECT count(*) AS user_rows,
       count(public_id) AS users_with_public_id,
       count(DISTINCT public_id) AS distinct_public_ids
FROM laravel.users;

SELECT conname, confdeltype, convalidated
FROM pg_constraint
WHERE conrelid = 'laravel.audit_events'::regclass
  AND contype = 'f'
ORDER BY conname;

WITH expected(table_name, sequence_name) AS (
    VALUES ('patients', 'patients_id_seq'),
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
), contracts AS (
    SELECT expected.table_name,
           expected.sequence_name,
           c.relkind AS table_kind,
           format_type(a.atttypid, a.atttypmod) AS id_type,
           a.attnotnull AS id_not_null,
           pg_get_expr(ad.adbin, ad.adrelid) AS default_expression,
           s.oid IS NOT NULL AS sequence_exists,
           s.relkind AS sequence_kind,
           c.relowner = s.relowner AS owners_match,
           EXISTS (
               SELECT 1
               FROM pg_depend owned
               WHERE owned.classid = 'pg_class'::regclass
                 AND owned.objid = s.oid
                 AND owned.refclassid = 'pg_class'::regclass
                 AND owned.refobjid = c.oid
                 AND owned.refobjsubid = a.attnum
                 AND owned.deptype IN ('a', 'i')
           ) AS sequence_owned_by_id,
           EXISTS (
               SELECT 1
               FROM pg_depend default_dependency
               WHERE default_dependency.classid = 'pg_attrdef'::regclass
                 AND default_dependency.objid = ad.oid
                 AND default_dependency.refclassid = 'pg_class'::regclass
                 AND default_dependency.refobjid = s.oid
                 AND default_dependency.deptype = 'n'
           ) AS default_depends_on_sequence
    FROM expected
    LEFT JOIN pg_namespace n ON n.nspname = 'laravel'
    LEFT JOIN pg_class c
      ON c.relnamespace = n.oid
     AND c.relname = expected.table_name
     AND c.relkind IN ('r', 'p')
    LEFT JOIN pg_attribute a
      ON a.attrelid = c.oid
     AND a.attname = 'id'
     AND a.attnum > 0
     AND NOT a.attisdropped
    LEFT JOIN pg_attrdef ad ON ad.adrelid = c.oid AND ad.adnum = a.attnum
    LEFT JOIN pg_class s
      ON s.relnamespace = n.oid
     AND s.relname = expected.sequence_name
     AND s.relkind = 'S'
)
SELECT table_name,
       table_kind,
       id_type,
       id_not_null,
       sequence_exists,
       sequence_kind,
       owners_match,
       sequence_owned_by_id,
       default_depends_on_sequence,
       default_expression = format(
           'nextval(''laravel.%s''::regclass)',
           sequence_name
       ) AS qualified_default
FROM contracts
ORDER BY table_name;
