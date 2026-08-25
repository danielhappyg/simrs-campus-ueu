# BG-02c2 Audit Attribution Rollout

Status: implementation evidence only; not authorization to migrate or deploy.

## Safety boundary

- Run only in `APP_MODE=SIMULATION` with synthetic-only data enforced.
- Do not place credentials, patient data, or raw audit payloads in the evidence pack.
- Record the exact application and migration SHA before changing the hosted database.
- Vercel does not run this migration. The private Supabase `laravel` schema requires an explicit migration step.

## Forward rollout order

1. Drain old application writers and enter a documented maintenance window.
2. Confirm the target database, private schema, current application SHA, and backup/restore point.
3. Run the migration from the exact release artifact before routing traffic to the new code:

   ```bash
   php artisan migrate --force
   php artisan migrate:status
   ```

4. Verify `laravel.audit_events` contains nullable `actor_type` and `actor_reference`, the `audit_actor_type_reference_idx` index, and a restrictive `audit_events_actor_user_fk` to the same-schema `users` table.
5. Release the matching application SHA. New rows must contain either:
   - `USER` plus the persisted user's event-time public ULID; or
   - an allowlisted `SERVICE` reference derived by the recorder.
6. Reopen writers and monitor value-free `AUDIT_CONTRACT_REJECTED` / `AUDIT_PERSISTENCE_FAILED` signals.

The columns remain nullable only for legacy rollout compatibility. A separate preflight and reviewed backfill must reach zero unresolved rows before a later non-null contraction.

## Rollback contract

- If any ordinary audit row exists, migration rollback refuses before weakening attribution. Retained evidence is forward-only; use a reviewed corrective migration.
- A code rollback may leave the expanded nullable schema in place, but old writers must remain drained because they can create rows without the new attribution snapshot.
- PostgreSQL rollback obtains an `ACCESS EXCLUSIVE` lock on the exact private audit table before checking emptiness.
- SQLite obtains a write lock in its migration transaction before checking emptiness.
- MySQL DDL auto-commits. Even with the migration's table lock and second emptiness check, successful reverse migration is permitted only in a drained maintenance window on an empty audit table. Verify it in an isolated database, never inside a shared transactional test.

## Required evidence

- exact release SHA and migration manifest;
- database engine/version and resolved schema;
- migration status before and after;
- schema/FK/index verification;
- current, legacy-null, and blocking attribution counts without payload values;
- rollback/restore rehearsal reference;
- operator and reviewer sign-off.
