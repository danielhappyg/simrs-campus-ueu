# Managed inpatient ward/bed master and live census — local engineering authorization

- Status: **LOCAL WORKING-TREE ENGINEERING IMPLEMENTATION AND DETERMINISTIC TESTING AUTHORIZED; DOMAIN AND PARITY ACCEPTANCE OPEN**
- Date: 2026-08-30
- Product owner: Daniel Happy Putra
- Environment: `APP_MODE=SIMULATION`; synthetic data only
- Scope: `PAR-ADM-009` and the bed-selection dependency of `PAR-REG-001`

## Simplified authority

This record directly freezes the bounded teaching defaults below. It needs no new ADR, exact-wording approval block, or proposal-byte hash before local implementation and deterministic verification may continue. It does not change the pending owner decisions for `PAR-ADM-009` or `PAR-REG-001`, and it does not claim SIMRS Sahabat parity, production readiness, or domain acceptance.

## Closed teaching profile

The locally implementable workflow is:

```text
authorized master manager
  -> create/version/retire ward and bed masters
  -> registrar selects an ACTIVE bed during the existing inpatient admission path
  -> live census projects occupancy from current non-cancelled inpatient encounter claims
  -> permitted operational roles inspect the read-only census
```

This slice manages the ward/bed catalogue and its current occupancy projection only. It does not create a second inpatient-registration workflow.

### Master identity, versions, and lifecycle

- Ward codes and bed codes are trimmed, normalized uppercase codes, each protected by a database unique constraint and immutable after creation. Codes are never recycled, including after retirement.
- A bed has one immutable ward identity. Its display name, service class, room label, and active state are mutable only by appending an attributable master version; ward display-name and active-state changes follow the same rule.
- Every master starts at version `1`. A change requires the exact expected current version, increments by exactly one, and records actor, timestamp, bounded reason, correlation ID, before digest, and after digest. Historical versions are immutable.
- Master states are exactly `ACTIVE` and `RETIRED`. `RETIRED` is terminal: a retired ward or bed cannot be reactivated, renamed, remapped, deleted through an ordinary route, selected for a new admission, or have its code reused.
- Retiring a bed requires a same-transaction live-occupancy recheck. An occupied bed cannot retire. Retiring a ward requires no live claim in the ward and all child beds already `RETIRED`.
- The existing inpatient admission path must validate the selected bed against the current `ACTIVE` ward and bed versions inside the same bed-claim transaction. A typed or stale retired code fails closed without creating a patient, encounter, success audit, or partial claim.

### Live occupancy source of truth

Live occupancy is a read-only projection, never a stored or operator-editable available/occupied flag and never a mutable cached count. A bed is occupied only when a current encounter claim:

1. has inpatient care setting;
2. references that immutable bed code;
3. has a status in the application's declared bed-occupying encounter states; and
4. is not `CANCELLED`.

Each census response derives occupied and available totals from the current master rows plus those qualifying encounter claims at query time. A cancelled or otherwise non-bed-occupying encounter immediately ceases to contribute. The `inpatient_bed_claim_mutexes` rows are serialization locks only; they are not occupancy facts, capacity rows, or census counters.

The census presents ward code/name, room, class, bed code/name, master state, and derived occupancy state. Encounter or patient details may appear only when the actor also has the existing corresponding patient/encounter access; the census capability alone never expands clinical-record access.

### Authorization

Server-side capability checks are mandatory; route or button visibility is not authorization.

- `master.inpatient.ward-bed.manage` authorizes ward/bed create, version, and retirement. It is assigned only to the `admin` role and is explicitly required for both ordinary administrators and system administrators.
- `inpatient.occupancy.view` authorizes the read-only census. It may be assigned to `registrar`, `nurse`, `physician`, `rmik`, and `admin`; any encounter-level detail remains filtered by the actor's existing access.
- The census capability never implies master mutation. The master capability never implies transfer, discharge, clinical edit, charge, claim, or external-integration authority.
- Wrong-role and missing-capability requests are denied before manual master lookup and produce no domain mutation.

### Atomicity, replay, audit, and races

Every state-changing operation uses `(actor_user_id, operation, idempotency_key)` plus a lowercase SHA-256 canonical request digest. An identical retry returns the original result without duplicate mutation or success audit. Reusing the tuple with a different digest fails as `idempotency_key_conflict`.

Master/current-version locking, expected-version validation, live-claim recheck, immutable version append, current projection update, replay receipt, and success audit commit atomically. An audit-write failure rolls the whole operation back. Audit metadata contains public IDs, codes, versions, state, reason code, correlation, and digests only; it contains no patient identifiers or free-text clinical content.

Database uniqueness and per-bed serialization must make concurrent behavior deterministic:

- concurrent creates for the same normalized ward or bed code yield one master only;
- concurrent updates from the same expected version yield one new version only;
- identical replay races yield one mutation and one success audit;
- conflicting replay payloads fail closed; and
- an admission claim racing a bed retirement can never leave an encounter on a retired bed: either the claim wins and retirement is refused as occupied, or retirement wins and the claim is refused as retired.

## Acceptance scenarios

1. An authorized administrator creates an active ward and active beds, then version-updates Indonesian display name, class, or room with attributable history while codes remain unchanged.
2. Duplicate normalized ward or bed codes, skipped/stale versions, invalid state values, code edits, and ordinary deletes are rejected without partial history.
3. A registrar selects an active bed in the existing synthetic inpatient registration flow; a retired or unknown ward/bed is rejected before encounter creation.
4. Registrar, nurse, physician, and RMIK actors with `inpatient.occupancy.view` see a read-only census; a missing-capability actor and direct mutation attempt are denied server-side.
5. The census counts an active inpatient encounter once, does not count outpatient/ED/closed/cancelled encounters, changes immediately after an authorized cancellation, and ignores mutex rows as occupancy facts.
6. An occupied bed and a ward containing an occupied bed cannot retire. A retired bed remains visible in history but cannot be selected or reactivated.
7. Replay, digest conflict, stale-version, audit-failure, concurrent duplicate-code, concurrent version-update, and admission-versus-retirement races preserve one coherent result and complete audit attribution.
8. Synthetic reset removes the synthetic master and patient-domain chains through declared reset behavior without providing an ordinary deletion path.
9. Focused SQLite tests plus PostgreSQL 17 and MySQL 8.4 runs prove migration, database constraints, locking/race behavior, reset, and rollback/refusal semantics before this local slice is called complete.

## Retention, rollback, and reset

- No backfill or import of real or legacy ward/bed data is authorized.
- Empty, never-used local structures may be rolled back.
- Once any ward, bed, immutable version, replay receipt, or correlated domain audit exists, migration rollback/down must refuse to drop those populated structures. Disable the narrow routes/capabilities and retain evidence for reconciliation.
- Synthetic reset may remove the bounded synthetic master and encounter chains through declared database relationships while preserving the separate reset audit record. It is not an ordinary master deletion or code-reuse mechanism.

## Verification and authority boundary

Local completion requires deterministic feature, authorization, immutability, audit-atomicity, replay, reset, rollback, and independent-process race tests. PostgreSQL 17 and MySQL 8.4 must both verify database constraints and real lock behavior; SQLite alone is insufficient concurrency evidence. Verification evidence must record exact engine versions, test commands, results, and any unverified boundary.

| Authority | Current value |
| --- | --- |
| Create local application code, migrations, fixtures, and deterministic tests for this exact profile | `true` |
| Run local synthetic migrations and tests on disposable/local databases | `true` |
| Produce local synthetic verification evidence | `true` |
| Facility/bed-management, Registration, Clinical, RMIK, Finance, or Reporting owner acceptance | `false` |
| SIMRS Sahabat parity, G0 closure, G3 acceptance, or production readiness | `false` |
| Real patient data or production clinical use | `false` |
| Live BPJS, VClaim, E-Klaim, SATUSEHAT, Aplicares, LIS, PACS, payment, or device integration | `false` |
| Commit, push, pull request, release, or publication | `false` |
| Hosted migration or deployment | `false` |

## Explicit exclusions

This authorization does not implement or define transfer, discharge, class-change charge effects, billing, cashier, claims, BPJS/Aplicares publication, pharmacy, radiology, laboratory, clinical documentation, RMIK completion, waitlists, reservations, public room displays, or historical occupancy reports. No secrets, real data, or live integration endpoints may be introduced. Any widening of lifecycle states, actor assignments, occupancy source, reuse rules, or downstream behavior requires a new bounded local record or named owner decision.
