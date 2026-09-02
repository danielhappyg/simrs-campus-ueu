# Routine inpatient discharge summary v1 — local engineering authorization

- Status: **LOCAL WORKING-TREE ENGINEERING IMPLEMENTATION AND DETERMINISTIC TESTING AUTHORIZED; CLINICAL, RMIK, AND PARITY ACCEPTANCE OPEN**
- Date: 2026-08-31
- Product owner: Daniel Happy Putra
- Environment: `APP_MODE=SIMULATION`; synthetic data only
- Direct scope: one bounded documentation dependency of `PAR-CLN-005`
- Existing prerequisites: a synthetic managed inpatient episode, managed ward/bed placement, and the atomic inpatient location-history profile

## Simplified authority

This record freezes one bounded local teaching profile under the product owner's simplified governance direction. Local implementation and deterministic verification need no new ADR, ADR-019 exact-wording approval, proposal-byte hash, or separate approval ceremony.

This authorization does not approve a discharge workflow, a clinical discharge standard, RMIK acceptance or closure, SIMRS Sahabat parity, G0/G3 acceptance, production use, real data, live integration, commit, push, pull request, hosted migration, or deployment. Existing synthetic-only, no-secrets, no-live-integration, and no-premature-publication boundaries remain in force.

## Closed teaching workflow

```text
eligible synthetic managed inpatient episode
  -> exact-role/capability physician creates the episode's one discharge-summary Draft
  -> the same physician appends Draft revisions using the expected current version
  -> the same physician finalizes only when all five bounded fields are non-blank
  -> readers see one terminal immutable Final plus its attributable immutable version history
```

This is a documentation workflow only. A Final summary records that the document is complete; it does not discharge or close the encounter, release its bed, create a disposition, or trigger any downstream action.

## Episode identity, authorship, and lifecycle

- The profile and stored definition version are exactly `ROUTINE_INPATIENT_DISCHARGE_SUMMARY_V1`.
- There is exactly one discharge-summary head per inpatient episode, uniquely identified by `encounter_id`. It cannot be shared across, copied to, or silently recreated for another episode.
- Document states are exactly `DRAFT` and `FINAL`.
- The creating physician is stored as the author. Only that original author may append a Draft revision or finalize the summary in v1; another physician cannot take over, co-sign, overwrite, or replace it.
- Creation requires `expected_version = 0` and creates version `1`. Every successful Draft save or Final operation appends exactly one immutable version and increments the current head by exactly one.
- A Draft may be partial and may have any subset of the five permitted fields, including blank values.
- Finalization reuses the current stored Draft fields. A Final request cannot replace content, and all five required fields must be non-blank after trimming.
- Exactly one terminal Final is permitted. The Final head and every historical version are immutable and cannot be updated or deleted through an ordinary workflow.
- There is no Final-to-Draft transition, reopening, amendment, correction, withdrawal, co-signature, supervisor approval, second Final, or ordinary deletion in v1. Any later correction profile requires separate bounded authorization.

## Exact fields and Indonesian UI labels

The client sends a `fields` object. The permitted keys and displayed Indonesian labels are exactly:

| Stored key | Indonesian UI label |
| --- | --- |
| `admission_reason` | `Alasan Masuk` |
| `significant_findings` | `Temuan Penting` |
| `care_and_treatment_summary` | `Ringkasan Perawatan dan Pengobatan` |
| `condition_at_discharge` | `Kondisi Saat Pulang` |
| `follow_up_plan` | `Rencana Tindak Lanjut` |

Unknown keys, non-string values, invalid UTF-8, and any value longer than 10,000 Unicode scalar values fail validation. Values are trimmed and keys are stored in bytewise order before canonical digesting. Drafts may be incomplete. Final requires non-blank `admission_reason`, `significant_findings`, `care_and_treatment_summary`, `condition_at_discharge`, and `follow_up_plan`.

These five values are bounded physician-authored narrative fields. `condition_at_discharge` is documentation text only; it is not a coded disposition, legal declaration, death record, AMA decision, referral instruction, encounter transition, or bed-release command.

## Exact actor and access separation

- Every create, Draft-save, and Final operation requires both the exact `physician` role and the dedicated server-side capability `clinical.inpatient.discharge-summary.write`.
- Role alone, capability alone, `clinical.medical.write`, `encounter.open`, nurse status, registrar status, RMIK status, administrator status, or system-administrator status never implies discharge-summary write or Final authority.
- Authorization occurs before manual encounter or summary lookup. Wrong-role and missing-capability requests expose no resource existence and create no summary, version, receipt, or success audit.
- Existing read access rules continue to govern the encounter detail projection. This profile grants no broader patient-list, census, clinical-document, billing, claim, or medical-record access.

## Eligible episode and coherent placement snapshot

Every write uses the canonical inpatient bed-operation lock coordinator. It locks and reloads the episode plus its current managed placement before resolving or mutating the summary.

- The episode must belong to a synthetic patient, have care setting `INPATIENT`, have status `REGISTERED`, `IN_EXAMINATION`, or `READY_FOR_RM`, have no cancellation, and still occupy one coherent managed placement.
- `CANCELLED`, `CLOSED`, non-inpatient, non-synthetic, missing-placement, unmanaged-placement, stale-placement, inactive-ward, and inactive-bed episodes fail closed before summary mutation.
- The locked episode's `inpatient_bed_id` and immutable `bed_code` must identify the same managed bed; its ward and bed must both remain `ACTIVE`.
- The server, never the client, derives one coherent episode/current-placement snapshot for every immutable version: encounter public ID, care setting, current encounter status, managed ward public ID/code/display-name, managed bed public ID/code/display-name, room label, service class, and current location sequence.
- When a real current location event exists, the snapshot also stores that event's public ID and event type and verifies that its destination equals the locked current managed placement. A retained legacy baseline is represented explicitly as location sequence `0`, `history_baseline = LEGACY_CURRENT_PLACEMENT`, and `history_complete = false`; no historical event is fabricated.
- A transfer racing a Draft or Final write yields either the complete pre-transfer snapshot or the complete post-transfer snapshot, never a hybrid. Historical summary-version snapshots never change after a transfer or master-data display rename.
- Placement and location data cannot be supplied or overridden by the client. This profile creates no location event and mutates no current placement.

## Optimistic versioning and actor-scoped replay

Every state-changing operation uses the exact tuple `(actor_user_id, operation, idempotency_key)`. Operations are exactly `DISCHARGE_SUMMARY_DRAFT_SAVE` and `DISCHARGE_SUMMARY_FINALIZE`. The idempotency key is trimmed and canonicalized lowercase.

A lowercase SHA-256 canonical request digest covers the operation, encounter public ID, exact expected version, definition version, and normalized fields for Draft creation/save. Finalization covers the operation, encounter public ID, current Draft public ID, exact expected version, definition version, and current stored-field digest; a Final request cannot inject replacement fields.

- An identical retry by the same actor returns the original result with `replayed = true` without duplicate head, version, Final, receipt, or success audit.
- Reusing the same actor/operation/key tuple with a different digest fails as `idempotency_key_conflict` without mutation.
- A receipt and its result resolution are actor-scoped. Another actor cannot discover or replay the original result by reusing the key.
- Creation requires `expected_version = 0`; every later Draft or Final operation requires the exact current version. A skipped, missing, or stale version fails as `stale_version` and requires reload.
- Concurrent creates for one episode yield one head. Concurrent writes from one expected version yield at most one next version. Concurrent Final attempts yield exactly one terminal Final. Identical replay races reconcile to one mutation and one success audit.

## Atomicity and sanitized audit evidence

Locked eligibility rechecks, head creation/update, immutable version append, operation receipt, and exactly one applicable success audit commit atomically. An audit-write or receipt-write failure rolls back the entire success path. Denials create no head/version/receipt/success-audit mutation and preserve the episode, placement, and prior summary state.

Success or denial audit metadata may contain action, outcome, safe reason code, actor public ID, encounter public ID, summary/version public IDs, state, version, definition version, placement public IDs/codes, location sequence, correlation ID, expected/current versions, and canonical digests.

Audit metadata, idempotency receipts, logs, URLs, and exception text must never contain patient identifiers, names, medical-record numbers, any of the five clinical field values, clinical free text, secrets, credentials, connection strings, or live endpoint data.

## Explicit non-effects and future workflow boundary

A Draft save and a Final operation cause no encounter-status transition. They do not set `READY_FOR_RM` or `CLOSED`, mark a discharge date/time, release or retire a bed, change location sequence, create a charge or bill, submit a claim, notify BPJS, create a prescription, dispense or reconcile medication, perform a pharmacy action, or accept/close a record in RMIK.

A later separately authorized atomic discharge workflow may require a terminal `ROUTINE_INPATIENT_DISCHARGE_SUMMARY_V1` Final as one of its locked prerequisites. That future workflow remains responsible for its own disposition rules, status transition, discharge timestamp, bed release, downstream coordination, idempotency, audit, rollback, and race behavior. This authorization neither implements nor pre-approves it.

## Acceptance scenarios

1. An exact-role/capability physician creates an incomplete Draft for an eligible synthetic managed inpatient episode, appends a revision using the expected current version, and reads the attributable immutable version chain.
2. The same physician finalizes when all five fields are non-blank; exactly one terminal immutable Final is produced and any later Draft, Final, update, deletion, reopening, or amendment attempt is denied.
3. Missing required Final content, unknown keys, non-string content, oversized or invalid-UTF-8 values, injected Final fields, invalid definition version, and stale/skipped expected versions fail without partial mutation.
4. Wrong-role, missing-capability, role-only, capability-only, nurse-only, registrar-only, RMIK-only, administrator-only, and system-administrator-only requests are denied before manual lookup.
5. A second physician cannot edit, finalize, take over, co-sign, or create a second summary for the episode, and cannot replay the original author's receipt.
6. Cancelled, closed, non-inpatient, non-synthetic, missing/unmanaged/stale-placement, inactive-ward, and inactive-bed episodes reject Draft and Final operations without summary, status, placement, receipt, or success-audit mutation.
7. A transfer racing a Draft or Final operation records one coherent pre-transfer or post-transfer placement/location-sequence snapshot; later transfers and master renames never rewrite stored versions.
8. Same-key replay, changed-payload conflict, concurrent create, concurrent Draft, concurrent Final, audit failure, and receipt failure preserve one coherent append-only summary chain and complete attributable evidence.
9. Draft and Final operations leave encounter status, cancellation, placement, location sequence, bed occupancy, charges, billing, claims, medication/pharmacy, and RMIK state unchanged.
10. Synthetic reset may remove the synthetic patient/episode/summary chain through declared reset behavior while retaining reset/audit evidence; populated migration down refuses to discard summary/version/receipt or correlated audit evidence.
11. Focused SQLite behavior tests plus independent PostgreSQL 17 and MySQL 8.4 migration, constraints, immutable history, replay, rollback/refusal, reset, locking, transfer-snapshot, and separate-process race evidence pass before local completion is claimed.

## Retention, rollback, reset, and portability

No legacy or real-data backfill/import is authorized. Empty, never-used local structures may be rolled back. Once any summary, immutable version, operation receipt, or correlated audit evidence exists, migration down must refuse to discard it; disable the narrow route/capability and retain evidence for reconciliation.

Synthetic reset may remove the synthetic patient, encounter, and discharge-summary chain through declared relationships while preserving the separate reset audit record. Reset is not an ordinary summary deletion, correction, Final reversal, RMIK closure, discharge transition, or evidence-erasure route.

PostgreSQL 17 and MySQL 8.4 evidence must record exact engine versions and source/catalog digests and use independent processes plus a durable third-connection readback for races. SQLite alone is insufficient concurrency evidence.

## Authority matrix

| Authority | Current value |
| --- | --- |
| Create local application code, migrations, fixtures, Indonesian UI, and deterministic tests for this exact profile | `true` |
| Run local synthetic migrations and tests on disposable/local databases | `true` |
| Produce local synthetic verification evidence | `true` |
| Clinical, RMIK, registration, facility/bed-management, pharmacy, finance, or claims owner acceptance | `false` |
| Encounter discharge/closure, bed release, downstream action, or `PAR-RMIK-002` implementation | `false` |
| SIMRS Sahabat parity, G0 closure, G3 acceptance, or production readiness | `false` |
| Real patient data or production clinical use | `false` |
| Live BPJS, VClaim, E-Klaim, SATUSEHAT, Aplicares, LIS, PACS, payment, pharmacy, device, or other integration | `false` |
| Commit, push, pull request, release, or publication | `false` |
| Hosted migration or deployment | `false` |

Local evidence can prove only this bounded teaching profile. It cannot convert any `false` above to `true`.

## Explicit exclusions

This authorization does not implement or define encounter-status transition, discharge timestamp, bed release, bed retirement, billing, tariff, charge, cashier, claim, BPJS/VClaim/E-Klaim, prescription, medication reconciliation, medication administration, dispensing, pharmacy action, RMIK acceptance/completeness/coding/custody/closure, AMA or death or referral disposition semantics, transfer, clinical orders/results, diagnostics, reporting formulas, public display, notification, or any live integration.

No secrets, real patient data, production endpoints, live credentials, retroactive summary, or guessed downstream effects may be introduced. Any widening of fields, actors, authorship, lifecycle, placement behavior, status or bed effects, disposition semantics, downstream consumers, or external systems requires a new bounded local record or named owner decision.
