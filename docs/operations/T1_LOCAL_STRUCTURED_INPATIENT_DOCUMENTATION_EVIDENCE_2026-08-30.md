# Local structured inpatient documentation evidence — 2026-08-30

## Evidence boundary

**Classification: `LOCAL / NOT DEPLOYED`.** This record covers the current uncommitted Structured Inpatient Longitudinal Documentation v1 and its role-aware multi-setting operational-home integration. It records no commit, push, pull request, GitHub Actions run, Vercel deployment, hosted Supabase migration, hosted UAT, Nursing/Clinical acceptance, product-owner release acceptance, or Sahabat parity acceptance.

The implementation remains inside the established simulation boundary: synthetic records only, no real patient data, no secrets, and no live BPJS, VClaim, E-Klaim, SATUSEHAT, LIS, PACS, payment, pharmacy, device, or other external integration.

## Implemented workflow

The inpatient examination desk now provides a bounded longitudinal documentation workflow on managed inpatient episodes:

1. each nurse has a separate `NURSING_DAILY` head for the server-assigned Asia/Jakarta service day;
2. each physician has a separate `MEDICAL_DAILY` head for that service day;
3. Draft saves and Finalization append immutable attributable versions with exact ward/bed placement snapshots;
4. only the original author with the exact role and capability may edit or finalize their current-day Draft;
5. the first successful Final may move `REGISTERED` to `IN_EXAMINATION`, but this slice never marks ready-for-RM, closes/discharges the encounter, or releases a bed; and
6. older free-text inpatient entries remain visible as read-only history and are never silently converted.

Pre-existing inpatient records without a managed bed remain readable, but structured write permissions and action URLs are absent. This prevents legacy data loss while keeping new writes fail-closed.

## Integrity and authorization controls

- `NURSING_DAILY` requires both the nurse role and `clinical.nursing.write`; `MEDICAL_DAILY` requires both the physician role and `clinical.medical.write`. Administrator and system-administrator identity alone grants no clinical write authority.
- Role and capability checks occur before manual encounter or document lookup, and the service repeats the policy boundary.
- Document identity is `(encounter, document type, server service day, author)`, so multiple care actors never share a Draft or version sequence.
- Every write reloads and locks the synthetic inpatient encounter, active managed ward, active managed bed, current head and receipt before mutation.
- Immutable versions preserve encounter state plus ward/bed public IDs, immutable codes, display names, room label and service class at write time; later master renames do not rewrite history.
- Optimistic expected versions, canonical lowercase idempotency keys and immutable receipts provide stable replay and audited conflict denial. An old exact retry resolves the receipt's original immutable result version even after the head advances, the service day changes, or the encounter becomes ineligible.
- MySQL replay uses locking current reads for the winning receipt, head and immutable version so `REPEATABLE READ` cannot hide a just-committed race result.
- Head update, immutable version append, receipt, permitted encounter-status transition and success audit commit atomically. Finalization audit failure restores the prior Draft, version, receipt and encounter state.
- Ordinary Eloquent and raw SQL writes to the three documentation evidence tables are guarded. Protected create/alter/drop/rename operations require the dedicated schema-mutation scope used by migration up/down.
- Audit metadata contains identifiers, states, versions, placement codes and digests only; it excludes patient identifiers, clinical text, secrets and connection data.
- The migration explicitly qualifies PostgreSQL `laravel` schema foreign keys and sequences, includes engine-native state/type/definition checks, performs no backfill, and refuses down while document or correlated audit evidence remains.
- Synthetic reset removes the patient/document chain through the governed scope while preserving reset and audit evidence.

## Operational-home integration

The local home page now reports today's active synthetic encounters separately for Rawat Jalan, IGD and Rawat Inap. Actors with `inpatient.occupancy.view` receive aggregate active/occupied/available managed-bed totals without patient details. Registration, examination, RM and occupancy actions are built from the signed-in actor's capabilities, so the page and header do not link users to routes they cannot open. Read failures are presented as explicit unavailable states instead of trustworthy-looking zeroes.

## Local quality gates

| Gate | Result |
| --- | --- |
| Full PHPUnit (`php artisan test`) | PASS — 640 tests; 636 passed; 4 skipped; 9,481 assertions |
| Pint | PASS |
| PHPStan | PASS — 0 errors |
| Full frontend unit suite | PASS — 17 files; 84 tests |
| TypeScript, ESLint, Prettier | PASS |
| Inpatient + audit focused gate | PASS — 50 tests; 5,299 assertions |
| Inpatient portability harness contract | PASS — 9 tests; 169 assertions |
| Working-tree diff check and supplied-secret scan | PASS |

One combined `composer test` wrapper invocation reached its 300-second process timeout after Pint and PHPStan passed. The same current tree then passed direct full PHPUnit in 17.394 seconds, and the other gates above were rerun separately. This record therefore cites the direct commands and does not mislabel the timed-out wrapper as PASS.

## Exact-engine portability and concurrency evidence

`scripts/rehearse-local-inpatient-documentation-portability.rb` owns isolated disposable PostgreSQL/MySQL engines, applies a fresh migration, runs independent application workers for race scenarios, verifies durable targets from a fresh third connection, retains sanitized logical command/result catalogues, binds a closed 20-file behavior-critical source set, writes mode-`0600` evidence in a mode-`0700` directory, and proves strict database/server/user cleanup before returning PASS.

Both engines passed the same ten-scenario catalogue:

| Scenario | Verified result |
| --- | --- |
| Concurrent same-author/current-day create | one head/version/receipt/success audit; competing create denied and audited |
| Same expected-version update | one next version applies; stale competitor denied and audited |
| Identical idempotency replay | one mutation and success audit; the second worker returns the same immutable result |
| Conflicting idempotency replay | the changed payload is denied and audited without changing the original result |
| Multi-author same day | exact two nursing and one medical author/type/day tuples, each with its own head/version/receipt/audit |
| Placement rename snapshot | old and new versions preserve the correct ward/bed IDs, codes, display names, room and service class |
| Final audit failure | prior Draft v1, one version/receipt and `REGISTERED` status remain; no Final success audit survives |
| Reset retention | synthetic document chains are removed through the declared path while audit/reset evidence remains |
| Empty down/reapply | unused structures roll down and reapply successfully |
| Populated/correlated-audit down refusal | retained document or audit evidence prevents destructive rollback |

The first four are observed database races. Each artifact retains two worker results and a seven-step protocol: worker A starts and holds; worker B starts; an engine-native wait is observed; A and B commit; a fresh third connection verifies the target-specific head, version, receipt and success/denial-audit counts. The other six are explicitly sequential durable proofs, not mislabeled races.

## Final matched records

The final PostgreSQL and MySQL records share source aggregate SHA-256 `094a9bbe4b5f1ea46625e8a972dd4e1bb22299b373c9ead799ca73ffcbab0d3e`, worker SHA-256 `186711fccb610bb4f72b470d1133d706bf8e214ecbc2a85335cc85cad5fdebde`, scenario-catalogue SHA-256 `87fcb39bfbafbf623918afa40224d6e19f1a7e2bf4653ce3b900b44b8d261681`, and command-catalogue SHA-256 `e957d6ec84d37a1de08b7008d2e7c8809581eea4b48e4c62d719ab272f975e5b`. Each retains 28 safe logical commands and 20 protocol results.

- PostgreSQL 17.10 / `laravel` schema: `storage/app/portability-rehearsals/20260830T142545Z-postgresql17-inpatient-documentation-70c6cb6c6fbc.json`; mode `0600`; SHA-256 `06343455eea2d7e93bdaf2397aff0fb2c2798cf214b75829cb6ada95e7349688`.
- MySQL 8.4.11 / InnoDB: `storage/app/portability-rehearsals/20260830T143123Z-mysql8411-inpatient-documentation-2599f5436a6f.json`; mode `0600`; SHA-256 `122ce5dddb5faebe670230e4ca4d96ee4c977d19dda607de6755181d1838a4e2`.

Independent review found no remaining actionable P1 or P2. Both records match the live closed source set with zero hash drift, contain no detected secret or connection identifier, report fresh migration PASS and strict cleanup, and form an acceptable matched local exact-engine evidence pair. All earlier inpatient-documentation engine records are superseded and must not be used as final evidence.

## Remaining boundary

This local checkpoint does not implement or accept transfer, class change, discharge, death/AMA disposition, bed release, orders/results, prescriptions, medication administration, RMIK completeness/coding, charges, cashier, claims, BPJS, reports, public displays or live integrations. It does not establish hosted migration compatibility, pooled/proxy behavior, browser UAT, backup/restore, load, capacity, SLA, Nursing/Clinical acceptance, complete PAR-CLN-005 acceptance, PAR-CLN-019 consolidation, PAR-RMIK-002 implementation, G0/G3 closure, Sahabat parity or production readiness. Any later commit, push, deployment or hosted `laravel` schema migration remains a separately authorized batch.
