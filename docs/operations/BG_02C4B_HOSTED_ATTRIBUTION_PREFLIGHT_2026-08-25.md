# BG-02c4b — Hosted audit-attribution release preflight

**Status:** read-only hosted inventory complete; migration and promotion held
**Observed at:** 2026-08-25 (Asia/Jakarta)
**Boundary:** synthetic teaching demo only; no migration, backfill, deployment promotion, credential transmission, or account activation occurred

## Decision

Do not promote the current `main` preview and do not run a generic Laravel migration yet. The application release is ready for a controlled rollout only after the migration-ledger drift is handled by reviewed code and a restorable logical backup exists.

The hosted attribution manifest is also not eligible for generation yet. One legacy ordinary-audit row has no preserved actor foreign key and its action is not an allowlisted service-only event, so the row is `BLOCKING` under BG-02c3. Its actor must not be guessed.

Controlled execution is governed by [`BG_02C4B_G1_CUTOVER_EXECUTION_CONTROL_2026-08-25.md`](BG_02C4B_G1_CUTOVER_EXECUTION_CONTROL_2026-08-25.md). It freezes the application payload separately from the later procedure/evidence carrier and provides the still-unexecuted authority, drift, backup/restore, ambiguity and recovery record. The older copy of this preflight embedded in the payload checkout is historical and must not override that two-SHA boundary.

The current execution artifacts are frozen as follows:

| Execution artifact | Frozen identity |
| --- | --- |
| G1 execution-control companion | Git blob `4f1199d4f7eeb5e032adf97a166ae5c963a43995` |
| Source/restore acceptance SQL | Git blob `fada760479fe0a43972cc5bd5b40124312898125`; SHA-256 `832cfa0a145c5d92f32114675d1249300899e554497b65e2d275d7877639b3ba` |
| Exact-SHA Preview evidence | Git blob `0d4f5ae96732866bde98413c281d7e32228fd3e3` |
| Release-evidence index | Git blob `0e400d84eb8eb4601fecb2e7cee571bef547ad26` |
| Authoritative procedure/evidence carrier commit | `PENDING` |

Action-time authorization must name the exact carrier commit containing all four frozen blobs above and this current preflight. This preflight cannot embed its own Git blob without creating a self-referential hash; its exact bytes are therefore pinned by that authorized carrier commit. Until such a commit exists, is reviewed and is named in the authorization, the cutover remains `NO-GO`.

## Exact release boundary

| Layer | Read-only observation |
| --- | --- |
| Approved cutover candidate | Exact SHA `5129e31d1077dd340feb6af016151cef64f50b2b`; this contains the reviewed application merge `3a9ad49c67d1c6c56831eb39afa7e5df8501190a` plus the documentation-only final-backup gate correction from PR #67 |
| Public Vercel alias | `simrs-campus-ueu-demo.vercel.app` still targets production deployment `dpl_4uGZACFzKsHMnNUy6YshiJjeQN1Q` from `42ab482de577fe38cef539a74f0b749d64485b19` |
| Cutover-candidate Vercel build | Preview deployment `dpl_Bj1s8MgwZMjUxGkajt3B1f6whYAk` is `READY` from exact SHA `5129e31d1077dd340feb6af016151cef64f50b2b`; it is not the production target. Protected runtime proof is recorded in `BG_02C4B3_EXACT_SHA_PREVIEW_BOOT_EVIDENCE_2026-08-25.md` |
| Supabase project | `simrs-campus-ueu-demo`, `ACTIVE_HEALTHY`, PostgreSQL 17.6, Singapore region |
| Supabase plan | Free; the project has no database branches and does not receive the automatic daily backups documented for paid plans |
| Private schema | `laravel`; 31 tables were listed |

The preview has no approved isolated persistent database. It must remain limited to public boot/static checks and must not receive Production-demo database credentials.

## Migration-ledger drift

The hosted `laravel.migrations` table contains 31 records and ends with batch 7, `2026_08_24_000100_create_outpatient_documentation_tables`. Comparing that ledger with the migration files at the reviewed release shows six locally pending names:

1. `2026_08_21_000100_create_rebuild_foundation_tables`
2. `2026_08_22_000800_add_patient_marital_status`
3. `2026_08_22_001000_qualify_laravel_serial_sequence_defaults`
4. `2026_08_25_000100_create_break_glass_record_tables`
5. `2026_08_25_000200_create_security_ledger_tables`
6. `2026_08_25_000300_expand_audit_actor_attribution`

The first three are not all genuinely unapplied:

- `laravel.audit_events` already exists and retains 70 rows. It contains the required foundation columns, indexes, and same-schema `users` foreign key, plus four retained legacy context columns and one context index.
- `laravel.patients.marital_status` already exists as nullable `character varying` with no default. It was recorded in Supabase's platform migration history, not Laravel's ledger.
- all 13 sequence defaults targeted by the qualifier migration resolve to same-owner sequences in the private `laravel` schema, with the expected ID ownership and default dependency. The qualifier was also recorded only in Supabase's platform migration history.

Therefore an unguarded `php artisan migrate --force` from a pre-adoption checkout is not a safe first write: it can attempt to create the existing `audit_events` table and add the existing `marital_status` column before reaching the three intended 25 August migrations. Exact cutover SHA `5129e31d1077dd340feb6af016151cef64f50b2b` contains the reviewed fail-closed adoption behavior from application merge `3a9ad49c67d1c6c56831eb39afa7e5df8501190a` with automated cross-engine coverage. The foundation and marital-status migrations accept only the observed compatible contracts, while the sequence qualifier proves all 13 ownership/dependency contracts before replaying the same qualified defaults. PostgreSQL rollback for the qualifier is explicitly forward-only. A manual ledger insert remains prohibited.

## Audit-attribution state

The hosted ordinary-audit inventory contains:

- 70 total rows;
- 69 rows with a preserved `actor_user_id`;
- one row with no actor foreign key, for the action `lab_access.enabled`;
- 15 users, all with non-null and pairwise-distinct public IDs; and
- the legacy actor foreign key is validated, same-schema, and currently uses `ON DELETE SET NULL`.

BG-02c2 has not been applied: `actor_type`, `actor_reference`, the restrictive actor foreign key, break-glass tables, and security-ledger tables are absent. Until the expansion is applied, the BG-02c3 command must refuse its schema precondition.

After expansion, the one null-actor `lab_access.enabled` row is expected to classify as `BLOCKING`. A separate value-minimized provenance investigation may determine whether immutable evidence identifies its actor. Action name, current users, or likely operator behavior are not sufficient evidence.

## Supabase RLS advisory

The Supabase table inventory emitted a critical generic advisory because RLS is disabled on all 31 private-schema tables. Direct privilege checks showed:

- `anon`, `authenticated`, and `service_role` have no `USAGE` or `CREATE` privilege on `laravel`; and
- those roles have no table grants in `laravel`.

The advisory must remain visible in release evidence, but the observed grants do not currently expose these tables through Supabase Data API roles. Do not enable RLS mechanically: policies must be designed and tested first or Laravel can be denied its own database access. Recheck schema exposure and grants after every hosting/security change.

## Mandatory forward rollout graph

1. Use a separate clean deployment checkout pinned to the single approved cutover SHA `5129e31d1077dd340feb6af016151cef64f50b2b`, and a separate clean procedure/evidence checkout pinned to the exact action-time-authorized carrier commit containing the frozen blobs above; record the payload's green multi-engine CI and READY Preview deployment evidence without staging user-owned untracked paths. Do not substitute a later payload or procedure commit without the corresponding review and evidence.
2. Treat the historical authenticated Preview runtime proof for `/up` and application boot as satisfied only for the recorded exact deployment and SHA. At action time, refresh its Vercel deployment ID, state, target and full source SHA; obtain new runtime proof if any metadata or artifact has drifted. Never attach Production database credentials to Preview.
3. Refresh the Production environment-key review and read-only hosted inventory; require the same database identity, schema/audit counts, structural contracts, and exactly the six reviewed pending migration names recorded above.
4. Record action-time authorization, named operator/reviewer, short-lived direct database access, encrypted backup destination, restore target, approved Production source service name/config hash, Linux/platform custody procedure, kernel mount fingerprint, trusted-parent and libpq-file custody fingerprints, secret custodian UID:GID, expected non-root operator UID/GID/groups and zero-effective-capability evidence, approved platform evidence preventing mount shadowing/remount or privilege escalation, plus the non-secret ID/commitment/expiry for a dedicated short-lived target-binding HMAC key. The authorization must provide expected Production target HMACs for source snapshots A and B. Never retain the HMAC key or raw host, DSN, database, role, password, mount source or service-file contents in chat, Git or receipts.
5. Require the secret-manager-provisioned `PGSERVICEFILE` and `PGPASSFILE` mount to be kernel `ro`, canonical/non-symlinked and non-replaceable by the ordinary operator. The Linux gate requires a stable `findmnt` target/source/fstype/major:minor/options fingerprint, custodian-owned parent/mountpoint at `0550`, custodian-owned `PGSERVICEFILE` at `0440`, approved read-only group membership, a non-root operator and `CapEff=0000000000000000`. Because libpq rejects group-readable password files, the only ownership exception is a secret-manager-presented operator-UID-owned `PGPASSFILE` at `0400` on the same kernel-`ro` mount; its complete custody fingerprint remains pinned and the operator still cannot write, replace or `chmod` it. The local checks require separate approved platform evidence that mount shadowing/remount and privilege escalation are unavailable; another platform needs a separately reviewed equivalent or the result is `NO-GO`. From the same imported-snapshot query session and `G1_SOURCE_SERVICE` bytes used for acceptance and `pg_dump --snapshot`, derive the value-minimized Production target HMAC and require it to equal the approved A value. Repeat the complete execution/mount/custody gate and exact service hash immediately before Q, immediately before `pg_dump`, and after publication. Any mismatch aborts and quarantines the archive. Then checksum the archive and prove it can be restored and queried in an isolated PostgreSQL 17 target. Supabase Free has no automatic daily restore point to substitute for this step.
6. Activate the database-backed Laravel maintenance marker, promote the exact release in drained mode, record the resulting Production deployment ID and source SHA, verify `/up` is 200 and `/login` is 503, then wait longer than Vercel's configured 60-second function maximum plus an observation margin and prove no old writer remains in flight.
7. While the shared marker remains active, repeat the same three-checkpoint execution-identity, kernel-`ro` mount, libpq custody/service-hash and imported-snapshot target binding and require the approved B HMAC, then take a final encrypted logical backup after the completed writer drain, record its checksum, and independently restore/query that final backup in an isolated PostgreSQL 17 target. Restore identities are separate and need not equal the Production target HMAC. Do not migrate if this post-drain restore proof fails, changes custody or is ambiguous.
8. From the exact reviewed checkout, run `migrate:status`; require exactly the six reviewed pending names recorded above: two safe historical adoptions, one verified idempotent qualifier replay, and the three 25 August migrations.
9. Run `migrate --force` once. The six names should be recorded together in the next batch. Do not retry an ambiguous result automatically.
10. Verify the new tables, triggers, nullable attribution columns, index, restrictive same-schema foreign key, all 13 sequence contracts, preserved audit evidence, Laravel ledger, and application/Supabase migration fingerprints.
11. Run `audit:attribution:preflight --json` once and retain only the value-free summary. The expected blocker means manifest generation remains prohibited pending provenance review.
12. Confirm that the promoted drained deployment's full source SHA exactly matches the migrated release, then remove the shared maintenance marker and perform public, permitted-role, denied-role, and synthetic-only smoke checks, including one successful audited write and one denied write.
13. Keep writers open only after release SHA, schema, audit persistence, temporary-access revocation, and rollback/forward-recovery evidence reconcile; reactivate the shared marker on any failed gate.

If a pre-commit migration step fails, preserve the database and investigate. If completion is ambiguous after a connection failure, do not rerun; inspect the ledger and schema first. Once new ordinary-audit rows are written, a code rollback to the old writer is not an acceptable normal recovery path because it can create rows without the new actor snapshot. Prefer a reviewed forward correction.

## Deferred authorization boundary

This artifact does not authorize:

- use of persistent production credentials;
- creation of a paid Supabase branch or backup feature;
- database migration or direct ledger edits;
- Vercel production promotion;
- mutation or deletion of the blocking audit row;
- attribution manifest generation or application;
- non-null contraction; or
- live BPJS/VClaim/SATUSEHAT or real-patient operation.

## References

- [BG-02c2 rollout](BG_02C2_AUDIT_ATTRIBUTION_ROLLOUT_2026-08-25.md)
- [BG-02c3 preflight](BG_02C3_AUDIT_ATTRIBUTION_PREFLIGHT_2026-08-25.md)
- [BG-02c4a private manifest](BG_02C4A_AUDIT_ATTRIBUTION_MANIFEST_2026-08-25.md)
- [BG-02c4b3 exact-SHA Preview boot evidence](BG_02C4B3_EXACT_SHA_PREVIEW_BOOT_EVIDENCE_2026-08-25.md)
- [BG-02c4b G1 cutover execution control](BG_02C4B_G1_CUTOVER_EXECUTION_CONTROL_2026-08-25.md)
- [BG-02c4b G1 source/restore acceptance SQL](BG_02C4B_G1_SOURCE_RESTORE_ACCEPTANCE_2026-08-25.sql)
- [Vercel + Supabase synthetic demo](VERCEL_SUPABASE_DEMO.md)
- [Supabase database backups](https://supabase.com/docs/guides/platform/backups)
