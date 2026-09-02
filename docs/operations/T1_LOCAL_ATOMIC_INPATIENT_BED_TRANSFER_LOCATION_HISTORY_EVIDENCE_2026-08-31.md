# Local atomic inpatient bed transfer and location history evidence — 2026-08-31

## Evidence boundary

**Classification: `LOCAL / NOT DEPLOYED`.** This record covers the current uncommitted Atomic Inpatient Bed Transfer + Location History v1 slice and its integration with managed inpatient admission, ward/bed retirement, longitudinal documentation, occupancy, audit, and bounded reset behavior. It records no commit, push, pull request, GitHub Actions run, Vercel deployment, hosted Supabase migration, hosted UAT, clinical/registration/RMIK acceptance, product-owner release acceptance, or Sahabat parity acceptance.

The implementation remains within the established simulation boundary: synthetic records only, no real patient data, no secrets, and no live BPJS, VClaim, SATUSEHAT, E-Klaim, LIS, PACS, payment, pharmacy, device, or other external integration.

## Implemented workflow

The local inpatient workflow now supports an atomic same-class transfer between active managed beds:

1. an exact registrar role plus `inpatient.bed.transfer` capability submits the current encounter, expected location sequence, expected source bed, target bed, reason, and idempotency key;
2. the service locks canonical bed mutexes, the encounter, wards, beds, current claims, and replay evidence before rechecking eligibility;
3. one transaction updates the encounter's current placement, appends one immutable location event, records one success audit, and inserts one immutable replay receipt;
4. the encounter detail exposes an ascending location timeline and explicitly labels a pre-history encounter as `LEGACY_CURRENT_PLACEMENT` without fabricating a backfill event;
5. transfer candidates and occupancy use current active encounter claims, matching either managed bed ID or immutable bed code so inconsistent legacy snapshots fail closed; and
6. documentation racing a transfer records one coherent source or target placement snapshot, depending on which service wins the canonical ordering.

No transfer path performs a discharge, bed-class billing change, claim submission, medication action, or live external update.

## Integrity, concurrency, and least-privilege controls

- Authorization is checked before manual encounter/bed lookup and repeated inside the service. Role-only, capability-only, administrator-only, and system-administrator-only paths do not gain transfer authority.
- Source and target mutexes are acquired in canonical code order. Current encounter, ward, bed, and occupancy rechecks use locking reads where required so MySQL `REPEATABLE READ` cannot decide from a pre-wait snapshot.
- Admission and bed retirement use the same ID-or-code claim rule. A legacy active encounter whose managed bed ID and bed code disagree blocks both a second admission and retirement of the claimed bed.
- Documentation never acquires a newly discovered bed mutex after locking the encounter. If placement changed while it waited, its owned transaction rolls back and restarts from the current placement; nested callers fail safely instead of inverting the mutex/encounter order.
- Exact same-key replay on MySQL uses current shared reads for both the immutable receipt and its referenced immutable event. The runtime identity needs no update/delete permission on either history table.
- Location events and receipts are append-only at the model, SQL-listener, database-trigger, and database-privilege layers. Ordinary update/delete/truncate and trigger removal are refused.
- Disposable-engine execution uses three separate identities: migration owner, application runtime, and bounded-reset identity. Runtime history grants are exactly `SELECT, INSERT`; the runtime cannot use a forged reset marker to delete or truncate history.
- PostgreSQL separately proves that owner-authorized direct update, delete, and truncate attempts still reach and are refused by the append-only database triggers.
- Bounded reset uses only the separate reset identity, removes the synthetic location chain, retains transfer audit evidence, and clears the engine-specific reset marker.
- Audit and receipt write failures roll back placement, event, receipt, and success-audit state together. Audit metadata contains safe identifiers, codes, sequences, digests, and correlation only; it excludes patient identifiers, free-text transfer reason, clinical content, secrets, and connection details.
- The migration explicitly qualifies PostgreSQL `laravel` schema objects, performs no backfill, rolls down only when empty, and refuses rollback when event, receipt, or correlated audit evidence remains.

## Exact-engine scenario catalogue

Both engines passed the same closed 26-scenario catalogue:

- migration and rollback: fresh migration, empty down/reapply, populated-event refusal, populated-receipt refusal, and correlated-audit refusal;
- normal and denial paths: successful atomic transfer, occupied-target denial, truthful legacy baseline, exact idempotent replay, audit-failure rollback, and receipt-failure rollback;
- database protection and lifecycle: append-only refusal, bounded reset, and final invariant verification;
- concurrent operations: same encounter competing transfers, two encounters for one target, admission-first and transfer-first target races, retirement-first and transfer-first target races, whole-ward retirement against transfer and documentation, source retirement during a claim, opposite-direction swaps, and documentation-first and transfer-first documentation races.

The exact replay proof is an unassisted two-process same-key `InpatientBedTransferService` race. Each engine observed the owner-installed disposable service delay, a native database wait, one `APPLIED` result, one `REPLAYED` result, no deadlock, and a fresh third-connection verification of exactly one event, one receipt, and one success audit.

Both documentation/transfer orderings call the real application services without harness bed pre-locks. Each engine observed two independent application processes, a native wait, two committed operations, no deadlock, and a fresh third-connection verification of the correct coherent source or target placement snapshot.

The source-retirement scenario also contains an unassisted inconsistent legacy-claim sub-proof. With one active encounter whose managed bed ID points to bed Y while its immutable bed code points to bed X, two real service processes acquire only application-defined locks. Each engine observed a native wait, `DENIED` + `DENIED` (`bed_occupied` and `target_occupied`), no deadlock, and a fresh third-connection verification that the encounter and both bed state/version values remained unchanged.

## Local quality gates

| Gate | Result |
| --- | --- |
| Full PHPUnit (`php artisan test`) | PASS — 658 tests; 654 passed; 4 skipped; 10,048 assertions |
| Pint | PASS |
| Full frontend unit suite | PASS — 18 files; 88 tests |
| TypeScript, ESLint, Prettier | PASS |
| Local engineering authorization contract | PASS — 8 tests; 212 assertions |
| Transfer portability harness contract | PASS — 17 tests; 421 assertions |
| Full portability-suite source-binding contract | PASS — 12 tests; 152 assertions |
| Focused final application review gate | PASS — 54 tests; 688 assertions |
| Independent adversarial P0–P2 review | PASS — no actionable finding |
| Working-tree diff check | PASS |

The four PHPUnit skips are retained conditional tests, not failures in this slice. Frontend gates were completed on the current frontend tree; subsequent changes were backend services, tests, the portability harness, and this evidence record only.

## Final matched exact-engine records

The final PostgreSQL and MySQL records share source aggregate SHA-256 `da9e356d2d388dc300ac71e067a8a07e43dbd607ac23be470bacaab4d3bfc999`, worker SHA-256 `da600a167e333966b1f2f078d90afc35d129a09704700e8554e6b65491ff4576`, and scenario-catalogue SHA-256 `4e352252fe78ff578a0ed2e992bbba8d2491785a081a4f8eb487c0839c66ac7a`. Both contain 26 PASS scenarios, no failed scenario, mode-`0600` evidence, and successful database/server/user/worker cleanup. This refresh binds the shared SQL-guard correction that permits genuine locking reads without permitting read-prefixed multi-statement writes.

- MySQL 8.4.11 / InnoDB: `storage/app/portability-rehearsals/20260831T043730Z-mysql8411-inpatient-transfer-37ccb391a39c.json`; 72 safe logical commands; 60 protocol results; artifact SHA-256 `e36e2d008526d90d5b51cddbfb63d748f38f9af5cfd741d59c20fe6f29be231a`.
- PostgreSQL 17.10 / `laravel` schema: `storage/app/portability-rehearsals/20260831T043727Z-postgresql17-inpatient-transfer-e99bce9036f1.json`; 73 safe logical commands; 61 protocol results; artifact SHA-256 `3e513b27dc4322464efc052ab3149ce6c7f601a59edbee941f3e4f2141446834`.

All earlier inpatient-transfer portability records are superseded and must not be used as final evidence.

## Remaining boundary

This local checkpoint does not establish hosted migration compatibility, pooled/proxy behavior, browser UAT, backup/restore, load, capacity, SLA, clinical/registration/RMIK acceptance, complete PAR-ADM/PAR-CLN acceptance, G0/G3 closure, Sahabat parity, or production readiness. It does not implement discharge, class-change charges, billing, cashier, claims, BPJS, pharmacy, radiology, laboratory orders/results, public room displays, or live integrations. Any later commit, push, deployment, or hosted Supabase `laravel` schema migration remains a separate release action and was intentionally not performed here.
