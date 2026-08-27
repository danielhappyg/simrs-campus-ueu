# T1 Production promotion readiness — 2026-08-27

**Status:** `NO-GO` for public Production promotion; current predecessor schema is understood
**Candidate state:** `LOCAL_UNCOMMITTED_NOT_DEPLOYABLE`; an exact candidate SHA will be assigned only after the complete local release gate passes
**Base application SHA:** `b3f9eb48d195fa1b2be02170b2dbd33eee20ae76`
**Current public Production SHA:** `42ab482de577fe38cef539a74f0b749d64485b19`
**Boundary:** Synthetic teaching environment only. This record authorizes no migration, Vercel promotion, role activation, or production integration.

## Why GitHub main is not yet the public Vercel deployment

GitHub `main` and the public Vercel Production alias are separate release controls. The latest pushed base is already on `main` and has an exact-SHA Vercel Preview, but the current nine-migration release candidate also contains uncommitted teaching-role access work and therefore has no deployable SHA. Vercel does not run the Laravel migrations against Supabase. Promoting a Preview while the shared database remains on its older schema could expose code paths whose required tables, columns, indexes, foreign keys, queue allocator, and teaching-access fencing do not exist.

The public alias was therefore deliberately left on the last aligned Production release. A fresh HTTP check on 27 August returned `200` for `/login` and served the older `app-CWDOgPA4.js` asset. The exact candidate Preview previously served `app-BWTMoIGP.js`. Asset identity is only a deployment-difference observation; the exact source-SHA binding comes from the Vercel deployment metadata recorded for each candidate.

## Read-only live database result

The Supabase project was queried without DDL, DML, account changes, or row-level clinical output. The [known-predecessor query](T1_PRODUCTION_PROMOTION_READINESS_PREFLIGHT_2026-08-27.sql) validates only the exact pre-migration state reviewed on 27 August:

- `PRE_MIGRATION_CONTRACT_MATCH` means the known predecessor is safe to take through the separately controlled migration procedure;
- `NO_GO_SCHEMA_DRIFT` means stop and investigate without attempting migration or promotion.

The query deliberately has no post-migration success state. It cannot be reused after migration as proof of the resulting schema. Any SQL error, missing single JSON result, unknown role/schema/table, timeout, or ambiguous execution is also `NO-GO`, not a fourth status and not permission to retry.

The sanitized [current result](T1_PRODUCTION_PROMOTION_READINESS_RESULT_2026-08-27.json) is bound to Supabase project ref `xbmsfvstcpngizcplqyg`, capture time `2026-08-27T05:26:52.049385Z`, query SHA-256 `a70ff74908a6eac5bf04f5313f38f5abf0f8985b9cd56d91cfdfdae77a1a6d4c`, and result-file SHA-256 `03ca32eb06132f56145a4f4e3d24452be4b47c44bb90fecb0fa2a2e052703039`. Its status is `PRE_MIGRATION_CONTRACT_MATCH`, while `candidate_state` is `LOCAL_UNCOMMITTED_NOT_DEPLOYABLE`, `candidate_application_sha` is `null`, and `promotion_authorized` remains `false`.

The earlier eight-migration result for base SHA `b3f9eb48d195fa1b2be02170b2dbd33eee20ae76` is preserved unchanged as [superseded evidence](T1_PRODUCTION_PROMOTION_READINESS_RESULT_B3F9EB48_2026-08-27.json), SHA-256 `b13b63dad8ae782546ee963adbe73fc8ff83fa457cd4c7e3ba6ea4b1a760038a`. Its exact [historical SQL bytes](T1_PRODUCTION_PROMOTION_READINESS_PREFLIGHT_B3F9EB48_2026-08-27.sql) reproduce the stored query SHA-256 `dd353b49b50e4e1325843a8cf70c68373d2bd36f7aad4f26e005b674bb0300a5`. The [immutable companion manifest](T1_PRODUCTION_PROMOTION_READINESS_EVIDENCE_MANIFEST_B3F9EB48_2026-08-27.json) explicitly maps those two preserved artifacts because the historical JSON predates the SHA-specific SQL filename. It is historical evidence only and cannot authorize the current nine-migration candidate.

The 27 August live observations are:

| Check | Result | Interpretation |
| --- | --- | --- |
| PostgreSQL | Major version 17 | Matches the tested hosted engine boundary |
| Laravel migration ledger | Exact ordered 31-row predecessor, including IDs and batches; last `2026_08_24_000100_create_outpatient_documentation_tables` | Nine candidate migration rows are not recorded; any replacement, addition, deletion, reordering, or batch change fails |
| Existing-shape adoption | Exact 17-column, seven-index and legacy `SET NULL` foreign-key predecessor checks pass for `audit_events`; nullable unbounded `patients.marital_status` matches | Foundation and marital-status migrations can use their fail-closed adoption paths |
| Sequence contracts | All 13 tables, sequences, ownership and dependencies are valid; every default is exactly one canonical `nextval` call to its expected sequence | The query accepts equivalent qualified or unqualified catalog rendering; the candidate migration still normalizes the reviewed defaults |
| New break-glass/security tables | Eight of eight absent | Expected before the controlled BG migration; incompatible with the current candidate runtime if promoted blindly |
| Candidate namespace objects | No candidate relations, sequences, primary/unique/index relations, named foreign-key constraints, PostgreSQL mutation-guard functions, or mutation-guard triggers exist | Detects partial or orphan candidate objects that could otherwise make a later migration fail after earlier migration commits |
| Teaching-role access predecessor | Lease table, sequence, five user fencing columns, eleven named relations/indexes, restrictive foreign key, roster-mapping constraint, roster-identity trigger/function, and partial one-active-lease index are all absent | Exact predecessor for the ninth migration; temporary teaching-role activation must not run before this migration is accepted |
| Queue allocator table | `daily_queue_counters` absent | The daily queue migration has not run |
| New audit columns | `actor_type` and `actor_reference` absent | Actor-attribution migration has not run |
| New audit foreign key | Legacy `SET NULL` constraint present | Restrictive actor foreign key has not been installed |
| New operational indexes | Four of four absent | Worklist, audit-attribution, and daily queue indexes have not run |
| Synthetic boundary | Zero non-synthetic patients; zero encounters missing registration time | The daily queue migration's two data preconditions currently pass |
| Data API roles | `anon`, `authenticated`, `authenticator`, and `service_role` have no tested schema/table privileges in `laravel` | The generic RLS-disabled advisory is not evidence of effective Data API access under current grants |

## Migration reconciliation

The nine Laravel migration rows that are absent from the hosted ledger are intentionally divided into two groups:

1. Three adoption migrations validate and record physical state already present or ready for qualification: rebuild foundation, patient marital status, and the 13 PostgreSQL sequence defaults.
2. Six later migrations add the break-glass/security ledger structures, audit attribution, operational indexes, the transactional daily queue allocator, and teaching-role access leases with session fencing.

A manual insert into `laravel.migrations` is prohibited. The reviewed candidate must run the migration code so each fail-closed adoption check and each structural migration executes under maintenance control.

## Remaining gates before one Production promotion

1. Complete the local gate, assign one exact candidate application SHA, then name the cutover operator and independent reviewer and authorize the exact application and procedure SHAs.
2. Capture an encrypted, checksummed PostgreSQL 17 backup and prove restoration into an isolated target.
3. Re-run the preflight from the same reviewed Production binding; require `PRE_MIGRATION_CONTRACT_MATCH`.
4. Activate the shared maintenance marker, drain writes, and confirm the marker from a second request path.
5. Run the exact candidate migrations once. An ambiguous exit is a stop condition, not permission to retry.
6. Run the separately reviewed post-migration structural acceptance from the controlled G1 record, exact Laravel migration status, attribution preflight, and exact-engine smoke checks. Do not reuse this predecessor-only query as post-migration evidence.
7. Verify the exact-SHA application against the migrated database with `/up`, `/login`, one permitted audited synthetic write, one denied-role request, and a role/session closeout.
8. Promote the exact verified deployment to the public Production alias once, then repeat health, asset, log, migration-ledger, privilege, and synthetic-boundary checks.
9. Keep Klaim, BPJS, Apotek, LIS, PACS, payment, and all other production integrations disabled.

Until these gates are complete, the correct state is: latest pushed base on `main`, current nine-migration candidate local and uncommitted, public Production intentionally retained at the last database-aligned release.

## Separate product-work boundary

No unbuilt clinical module is currently owner-approved. The strongest next clinical bridge is IGD disposition to a linked Rawat Inap admission with atomic bed allocation, but disposition states, required clinical facts, responsible roles, correction rules, and bed-lock authority remain owner decisions. Radiology runtime remains blocked by `PAR-CLN-007`; a requirements/owner pack may follow hosted UAT, but copying the minimal laboratory lifecycle is not an approved implementation shortcut.

## Governing references

- `docs/operations/BG_02C4B_G1_CUTOVER_EXECUTION_CONTROL_2026-08-25.md`
- `docs/operations/BG_02C4B_G1_SOURCE_RESTORE_ACCEPTANCE_2026-08-25.sql`
- `docs/operations/BG_02C4B3_EXACT_SHA_PREVIEW_BOOT_EVIDENCE_2026-08-25.md`
- `docs/operations/T1_PRODUCTION_PROMOTION_READINESS_RESULT_2026-08-27.json`
- `docs/operations/T1_PRODUCTION_PROMOTION_READINESS_RESULT_B3F9EB48_2026-08-27.json`
- `docs/operations/T1_PRODUCTION_PROMOTION_READINESS_PREFLIGHT_B3F9EB48_2026-08-27.sql`
- `docs/operations/T1_PRODUCTION_PROMOTION_READINESS_EVIDENCE_MANIFEST_B3F9EB48_2026-08-27.json`
- `docs/operations/VERCEL_SUPABASE_DEMO.md`
- `docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_2026-08-26.json`
- `docs/new-simrs-rebuild/phase-0/G0_BATCH_D_DECISION_REGISTER_2026-08-25.json`
