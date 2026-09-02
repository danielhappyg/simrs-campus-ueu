# Atomic inpatient bed transfer and location history v1 — local engineering authorization

- Status: **LOCAL WORKING-TREE ENGINEERING IMPLEMENTATION AND DETERMINISTIC TESTING AUTHORIZED; DOMAIN AND PARITY ACCEPTANCE OPEN**
- Date: 2026-08-30
- Product owner: Daniel Happy Putra
- Environment: `APP_MODE=SIMULATION`; synthetic data only
- Direct scope: the bounded transfer dependency of `PAR-REG-001`, `PAR-CLN-005`, and `PAR-ADM-009`
- Existing prerequisites: managed inpatient ward/bed master, managed inpatient registration, live occupancy projection, and structured inpatient longitudinal documentation v1

## Simplified authority

This record directly freezes one safe teaching profile for atomic movement between managed beds. It needs no new ADR, exact-wording approval block, proposal-byte hash, or separate approval ceremony before local implementation and deterministic verification may continue.

This authorization does not approve the still-open owner rules for transfer acceptance, clinical approval, class change, discharge, charges, claims, or parity. It authorizes no real data, live integration, commit, push, hosted migration, or deployment.

## Closed teaching workflow

```text
authorized registrar opens an active synthetic inpatient encounter
  -> selects a different ACTIVE managed bed in the same service class
  -> server serializes both bed claims and rechecks encounter plus occupancy
  -> one immutable BED_TRANSFER event and the encounter's current placement commit atomically
  -> encounter detail shows ordered location history and census shows only the new current claim
```

This slice changes current ward/bed placement only. It creates no discharge, class-upgrade, clinical-approval, waitlist, reservation, billing, claim, or external-integration transition.

## Exact actor and eligibility

- A transfer requires both the `registrar` role and the new server-side capability `inpatient.bed.transfer`. Role alone, capability alone, `inpatient.occupancy.view`, `patient.register`, ward/bed-master management, administrator status, or system-administrator status never implies transfer authority.
- Authorization occurs before manual encounter or bed lookup. Wrong-role and missing-capability requests expose no target existence and produce no placement, location-event, receipt, or success-audit mutation.
- The encounter must belong to a synthetic patient, have care setting `INPATIENT`, have status `REGISTERED`, `IN_EXAMINATION`, or `READY_FOR_RM`, and have no cancellation. `CANCELLED`, `CLOSED`, non-inpatient, non-synthetic, missing-placement, unmanaged-placement, and stale-placement encounters fail closed.
- The encounter's current `inpatient_bed_id` and immutable `bed_code` must identify the same managed source bed. The source bed and its ward must both remain `ACTIVE`.
- The target bed and its ward must both remain `ACTIVE`, the target bed must differ from the source bed, and no current bed-occupying inpatient encounter may claim it. A same-bed request fails as `same_bed`; an occupied target fails as `target_occupied`.
- Target `service_class` must exactly equal the source bed's current `service_class`. A different class fails as `service_class_change_not_authorized`; v1 never interprets a transfer as an upgrade/downgrade or creates charge effects.

## Canonical bed-operation locking prerequisite

Transfer implementation must first introduce one bounded canonical bed-operation lock coordinator and migrate the existing managed admission claim, occupied-bed retirement, occupied whole-ward retirement, inpatient-document placement snapshot write, and this transfer path to it. No transfer route may be enabled while those participants retain incompatible lock orders.

For every participating transaction the canonical order is:

1. perform role/capability and shape validation without disclosing looked-up resources;
2. resolve candidate immutable identifiers without treating the unlocked reads as authoritative;
3. create missing `inpatient_bed_claim_mutexes` rows safely, then lock all involved mutex rows in normalized bed-code byte order;
4. lock all affected existing encounters in ascending database ID when the operation has any;
5. lock all involved ward rows in ascending database ID, then all involved bed rows in ascending database ID; and
6. reload and recheck encounter eligibility, source identity, master state, class, and target occupancy before mutation.

Admission has no pre-existing encounter and therefore follows the same order without step 4, creating the encounter only after the target mutex and managed placement are locked. Bed retirement uses the same single-bed prefix and rechecks occupancy. Inpatient documentation uses the same current-bed prefix so a concurrent document version records either the complete pre-transfer placement or the complete post-transfer placement, never a hybrid. Opposite-direction transfers sort the same two mutex, encounter, ward, and bed sets identically.

Whole-ward retirement must resolve the current child-bed set non-authoritatively, lock every child-bed mutex in normalized bed-code byte order, lock every currently occupying affected encounter in ascending database ID, then lock the ward and every child bed in ascending database ID. It must reload and compare the complete child-bed set, master states, and occupancy under those locks before retirement. If a concurrent child-bed create or remap changed the resolved set, the attempt aborts or retries from the beginning; it must never acquire an additional mutex out of order. This replaces the existing ward-to-beds-to-encounters order, which is incompatible with encounter-to-placement documentation locking.

## Immutable location-event history

- Event types are exactly `ADMISSION_LOCATION` and `BED_TRANSFER`. Events are append-only and have a per-encounter integer `sequence` beginning at `1`, increasing by exactly one, with a database unique constraint on `(encounter_id, sequence)`.
- Every future managed inpatient registration created after this migration appends `ADMISSION_LOCATION` sequence `1` in the same transaction as the encounter, placement, registration audit, and any operation evidence. Its `from` placement is null and its `to` placement is the server-derived admitted bed.
- No historical event is fabricated or backfilled. A pre-existing managed synthetic inpatient encounter is projected with `history_baseline = LEGACY_CURRENT_PLACEMENT` and `history_complete = false`. Its current managed placement is shown as a non-event baseline; its first real transfer appends `BED_TRANSFER` sequence `1`, followed by contiguous real events.
- Each `BED_TRANSFER` stores immutable, server-derived full `from` and `to` snapshots: ward public ID, immutable ward code, ward display name, bed public ID, immutable bed code, bed display name, room label, and service class. Later master renames never rewrite an event.
- Each event also stores encounter public ID, actor user ID, event type, sequence, bounded reason, request correlation ID, canonical payload digest, and occurred-at timestamp. An event cannot be updated or deleted through an ordinary route.
- Transfer reason is required free text, trimmed, valid UTF-8, and between 5 and 500 Unicode scalar values. It is stored in the immutable event but never copied into audit metadata, denial metadata, logs, URLs, or idempotency receipts.

## Optimistic command and canonical replay

The client must send `expected_location_sequence`, `expected_source_bed_public_id`, `target_bed_public_id`, required `reason`, `idempotency_key`, and optional correlation ID. For a pre-existing baseline with no real events, the expected sequence is `0`. The locked current sequence and source identity must exactly match; otherwise the request fails as `stale_location` or `source_bed_changed` and the client must reload.

Every transfer uses the exact tuple `(actor_user_id, operation, idempotency_key)`, where operation is `INPATIENT_BED_TRANSFER` and the idempotency key is trimmed and canonicalized lowercase. A lowercase SHA-256 digest covers operation, encounter public ID, expected sequence, expected source bed public ID, target bed public ID, and normalized reason.

- An identical retry returns the original event and sequence with `replayed = true`, even when observed after a later successful transfer; it creates no duplicate event, placement update, receipt, or success audit.
- Reusing the tuple with a different digest fails as `idempotency_key_conflict` without mutation.
- A receipt stores only safe identifiers, digest, result event public ID/sequence, correlation, and completion time; the receipt and result resolution are actor-scoped.

## Atomic mutation, audit, denial, and races

Source/target locking, all locked-state rechecks, encounter current-placement update, immutable event append, replay receipt, and exactly one `inpatient.bed.transfer` success audit commit atomically. An audit-write or receipt-write failure rolls the whole success path back. Success audit metadata contains public IDs, immutable codes, sequence, correlation, and digest only; it contains no patient identifiers, free-text reason, clinical content, secrets, connection strings, or live endpoint data.

Denials preserve the prior encounter placement and create no location event, success receipt, or success audit. Safe denial audit remains attributable without including patient identity, reason text, or concealed resource existence.

Required deterministic outcomes are:

- two commands for one encounter from the same expected source/sequence produce at most one next event; the loser reloads as stale;
- two encounters racing for one target produce one transfer and one `target_occupied` denial;
- a managed admission racing for the target is serialized by the same mutex and only one current claim wins;
- target retirement racing the transfer yields either transfer then retirement denied as occupied, or retirement then transfer denied as inactive;
- whole-ward retirement racing transfer or documentation follows the all-child-bed mutex and affected-encounter order, then either denies on the recheck or completes without deadlock;
- source retirement remains denied while the encounter claims it, including during transfer;
- opposite-direction swaps cannot bypass the target-unoccupied rule and cannot deadlock; and
- a documentation write racing transfer records one coherent placement snapshot under the shared lock coordinator.

## Read projection and occupancy truth

- The encounter detail location projection requires existing `encounter.open`; it grants no new patient/clinical access. Transfer action metadata appears only when the exact actor contract and current eligibility permit it.
- The projection orders real events by sequence ascending, exposes `history_baseline` and `history_complete`, distinguishes the non-event legacy baseline, and shows immutable from/to snapshots, actor attribution, occurred time, and reason.
- The live census continues to derive occupancy only from each current bed-occupying encounter claim. Location events, receipts, and mutex rows are history/evidence or locks, never current occupancy facts or mutable counts.

## Acceptance scenarios

1. An exact-role/capability registrar transfers an eligible synthetic inpatient between different ACTIVE same-class managed beds; source becomes available, target becomes occupied, encounter placement and one immutable event/audit/receipt agree.
2. Wrong-role, missing-capability, role-only, capability-only, administrator-only, and system-administrator-only requests are denied before manual lookup without domain mutation.
3. Non-synthetic, non-inpatient, cancelled, closed, missing/unmanaged/stale-placement, inactive-source, inactive-target, same-bed, different-class, and occupied-target requests fail closed.
4. Missing, invalid, too-short, too-long, or invalid-UTF-8 reason; malformed IDs/key/correlation; stale sequence; and changed source identity fail without partial evidence.
5. A new managed registration receives `ADMISSION_LOCATION` sequence `1` atomically; an existing encounter receives no invented event and exposes the explicit incomplete legacy baseline before its first real transfer.
6. Later master display changes do not rewrite event snapshots; the read projection remains ordered and permission-safe while census reflects only current encounter claims.
7. Identical retry, changed-payload key conflict, audit failure, receipt failure, same-encounter race, same-target race, admission race, bed/whole-ward retirement race, opposite-direction race, and documentation race preserve one coherent durable outcome.
8. Direct event update/delete, direct encounter placement mutation outside the bounded service, and direct receipt insertion are refused by application architecture and database constraints where enforceable.
9. Synthetic reset removes the synthetic encounter/location-event/receipt chain through declared reset behavior while retaining reset audit evidence; it is not an ordinary history deletion route.
10. Empty rollback succeeds, while populated location-event, receipt, or correlated audit evidence makes migration down refuse; disable routes/capability and retain evidence instead.
11. Focused SQLite behavior tests plus independent PostgreSQL 17 and MySQL 8.4 migration, constraint, lock-order, replay, rollback/refusal, reset, and separate-process race evidence pass before local completion is claimed.

## Retention, rollback, reset, and portability

No legacy or real-data backfill/import is authorized. Empty, never-used local structures may be rolled back. Once an admission/transfer event, transfer receipt, or correlated audit exists, migration down must refuse to discard it.

Synthetic reset may remove synthetic encounter, location-event, and receipt chains through declared relationships while preserving the separate reset audit record. It cannot fabricate a complete baseline, renumber retained history, permit ordinary deletion, or recycle master codes.

PostgreSQL 17 and MySQL 8.4 evidence must record exact engine versions and source/catalog digests and must use independent processes plus a durable third-connection readback. It must prove database uniqueness/foreign-key/immutability controls, both transfer race orderings, whole-ward retirement coordination, observed lock waiting, no deadlock, atomic rollback on audit/receipt failure, truthful baseline behavior, reset, and down refusal. SQLite alone is insufficient concurrency evidence.

## Authority matrix

| Authority | Current value |
| --- | --- |
| Create local application code, bounded lock-order refactor, migrations, fixtures, UI, and deterministic tests for this exact profile | `true` |
| Run local synthetic migrations and tests on disposable/local databases | `true` |
| Produce local synthetic verification evidence | `true` |
| Registration, facility/bed-management, Clinical, RMIK, Finance, or Claims owner acceptance | `false` |
| SIMRS Sahabat parity, G0 closure, G3 acceptance, or production readiness | `false` |
| Real patient data or production clinical use | `false` |
| Live BPJS, VClaim, E-Klaim, SATUSEHAT, Aplicares, LIS, PACS, payment, device, or other integration | `false` |
| Commit, push, pull request, release, or publication | `false` |
| Hosted migration or deployment | `false` |

Local evidence can prove only this bounded profile. It cannot convert any `false` above to `true`.

## Explicit exclusions

This authorization does not define transfer request/acceptance queues, clinical approval, transport, isolation/cohort rules, sex/age compatibility, waitlist/reservation, temporary leave, service-responsibility change, class upgrade/downgrade, tariff, charge, cashier, billing, claim, BPJS, Aplicares publication, discharge, death/AMA disposition, final-bill gates, RMIK completion, historical occupancy reports, pharmacy, diagnostics, devices, or any live integration.

No secrets, real patient data, production endpoints, live credentials, retroactive location history, or guessed downstream effects may be introduced. Any widening of actors, states, placement compatibility, approval gates, event types, class behavior, discharge/charge effects, reporting formulas, or external systems requires a new bounded local record or named owner decision.
