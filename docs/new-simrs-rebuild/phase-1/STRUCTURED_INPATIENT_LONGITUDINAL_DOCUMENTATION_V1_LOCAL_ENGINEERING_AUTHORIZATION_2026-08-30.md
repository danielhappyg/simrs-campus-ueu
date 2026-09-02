# Structured inpatient longitudinal documentation v1 — local engineering authorization

- Status: **LOCAL WORKING-TREE ENGINEERING IMPLEMENTATION AND DETERMINISTIC TESTING AUTHORIZED; DOMAIN AND PARITY ACCEPTANCE OPEN**
- Date: 2026-08-30
- Product owner: Daniel Happy Putra
- Environment: `APP_MODE=SIMULATION`; synthetic data only
- Direct scope: `PAR-CLN-005`
- Existing prerequisites: the managed inpatient episode from `PAR-REG-001` and managed ward/bed placement from `PAR-ADM-009`
- Future consumer, not implemented here: `PAR-RMIK-002`

## Simplified authority

This record freezes one bounded local teaching profile under the product owner's simplified governance direction. It needs no new ADR, exact-wording approval block, proposal-byte hash, or separate approval ceremony before local implementation and deterministic verification may continue.

This authorization does not approve `PAR-CLN-005`, consolidate `PAR-CLN-019`, approve `PAR-RMIK-002`, establish a clinical standard, or claim SIMRS Sahabat parity. Owner and parity decisions remain open. Existing safety and product boundaries from `DEC-006`, `DEC-008`, `DEC-013`, and `DEC-014` remain in force. The laboratory-only `DEC-016` is not a dependency of this slice.

## Closed teaching workflow

```text
active synthetic inpatient encounter with a managed ward/bed claim
  -> nurse creates, versions, and finalizes their daily nursing document
  -> physician creates, versions, and finalizes their daily medical document
  -> permitted care actors read the longitudinal Draft/Final history
```

This is a documentation workflow only. It adds no transfer, discharge, order/result, prescription, RMIK-completion, charge, claim, or integration transition.

### Document identity and service day

- Document types are exactly `NURSING_DAILY` and `MEDICAL_DAILY`.
- Document states are exactly `DRAFT` and `FINAL`.
- A document head is uniquely identified by `(encounter_id, document_type, service_date, author_user_id)`. This permits more than one attributable care actor to document on the same service day without sharing or overwriting another actor's draft.
- `service_date` is assigned by the server when the first draft is created, using the Asia/Jakarta calendar date from the database/application clock. It is immutable and cannot be supplied, backdated, or future-dated by the client in v1.
- The profile and stored definition version are exactly `INPATIENT_LONGITUDINAL_DOCUMENTATION_V1`.
- Every new head starts at version `1`. Each successful draft save or finalization appends exactly one immutable version and increments the current head by exactly one.
- A Draft may be edited or finalized only by its original author. A Final head and every historical version are immutable and cannot be updated or deleted through an ordinary workflow.
- There is no correction, amendment, co-signature, supervisor approval, withdrawal, reopening, or Final-to-Draft transition in v1. A later change to a Final document requires a separately authorized correction profile.

### Bounded structured fields

The client sends a `fields` object. Unknown keys, non-string values, invalid UTF-8, and any field longer than 10,000 Unicode scalar values fail validation. Values are trimmed and object keys are stored in bytewise order before digesting.

`NURSING_DAILY` permits exactly:

- `nursing_observation`
- `nursing_intervention`
- `nursing_evaluation`
- `additional_notes`

Final nursing documents require non-blank `nursing_observation`, `nursing_intervention`, and `nursing_evaluation`. `additional_notes` is optional.

`MEDICAL_DAILY` permits exactly:

- `subjective`
- `objective`
- `assessment`
- `plan`
- `additional_notes`

Final medical documents require non-blank `subjective`, `objective`, `assessment`, and `plan`. `additional_notes` is optional. These labels are bounded teaching fields; this authorization does not claim an approved CPPT, diagnosis, procedure, medication, or discharge-summary standard.

Drafts may contain any subset of the permitted keys, including blank values. Finalization reuses the current stored Draft fields; a finalize request cannot replace content and fails when the current required fields are incomplete.

### Encounter and managed-placement snapshot

Every write locks and reloads the encounter before any document lookup. It accepts only a synthetic inpatient encounter in one of the current bed-occupying states `REGISTERED`, `IN_EXAMINATION`, or `READY_FOR_RM`. `CANCELLED`, `CLOSED`, non-inpatient, non-synthetic, missing-placement, stale-placement, and unmanaged-placement encounters fail closed before document mutation.

The locked encounter must reference an existing managed bed whose ward and bed remain `ACTIVE`. The server, never the client, derives and stores with every immutable version:

- encounter public ID and care setting;
- service date;
- managed ward public ID, immutable ward code, and display-name snapshot;
- managed bed public ID, immutable bed code, display-name snapshot, room-label snapshot, and service-class snapshot; and
- the current encounter status at the time of the version.

Historical placement snapshots never change after a ward/bed rename. This profile reads the current managed placement; it does not create an occupancy interval, transfer history, class-change record, or mutable census count.

### Actor and capability separation

Server-side capability and role checks occur before manual encounter or document lookup. UI visibility is not authorization.

- `NURSING_DAILY` Draft/Final writes require both the `nurse` role and `clinical.nursing.write`.
- `MEDICAL_DAILY` Draft/Final writes require both the `physician` role and `clinical.medical.write`.
- `encounter.open` permits the existing read-only encounter/detail projection but never implies either write capability.
- A nurse cannot write or finalize a medical document; a physician cannot write or finalize a nursing document.
- Administrator or system-administrator status grants no routine clinical-document write or finalization authority without the exact clinical role and capability.
- Read projections expose patient or encounter detail only through existing access rules; this slice creates no broader census or medical-record access.

### Lifecycle effect

- Saving a Draft does not change encounter status.
- The first successful Final daily document may move `REGISTERED` to `IN_EXAMINATION` atomically with the version and audit.
- This slice never sets `READY_FOR_RM`, `CLOSED`, or a discharge status and never releases a bed. An encounter already in `READY_FOR_RM` remains open and bed-occupying and may receive further daily documentation under this profile.
- Existing inpatient `clinical_entries` remain retained read-only history. No migration, import, rewrite, or silent conversion of those entries into v1 documents is authorized.

## Version, replay, audit, and race rules

Every state-changing operation uses the exact tuple `(actor_user_id, operation, idempotency_key)` with a canonical lowercase idempotency key and a lowercase SHA-256 canonical request digest. The digest includes the operation, encounter public ID, document type, expected version, definition version, and normalized fields for Draft saves; finalization includes the exact current Draft public ID and version.

- An identical retry returns the original result without duplicate mutation, version, status transition, or success audit.
- Reusing the tuple with a different digest fails as `idempotency_key_conflict` without changing the document.
- Creation requires `expected_version = 0`; every later operation requires the exact current version. Skipped or stale versions fail as `stale_version`.
- Lock order is encounter, managed ward, managed bed, document head, then operation receipt. Authorization precedes manual public-ID lookup; locked state and placement are rechecked before mutation.
- Encounter status change, head creation/update, immutable version append, operation receipt, and success audit commit atomically. An audit-write failure rolls back the entire success path.
- Concurrent creates for the same document identity yield one head. Concurrent operations from the same expected version yield one next version. Identical replay races reconcile to one mutation and one success audit; conflicting replay payloads fail closed.

Success and denial audit metadata may contain public IDs, document type/state/version, service date, definition version, placement codes/public IDs, correlation ID, expected/current versions, reason codes, and canonical digests. It must never contain patient identifiers, clinical field values, free text, secrets, connection strings, or live endpoint data.

## Acceptance scenarios

1. A nurse with the exact role and capability saves an incomplete `NURSING_DAILY` Draft, updates it with the expected version, finalizes complete fields, and sees every immutable version in chronological order.
2. A physician independently saves and finalizes a complete `MEDICAL_DAILY` document for the same encounter and service day without sharing the nurse's head or version sequence.
3. Missing required Final fields, unknown keys, oversized values, client-supplied service date or placement, invalid definition version, and stale/skipped expected versions fail without partial mutation.
4. Wrong-role, missing-capability, administrator-only, system-administrator-only, and cross-document-type writes are denied before manual encounter/document lookup.
5. Cancelled, closed, non-inpatient, non-synthetic, unmanaged, missing-placement, and retired/stale-placement encounters reject Draft and Final operations without status, document, receipt, or success-audit changes.
6. Ward/bed display changes after a version do not rewrite its stored placement snapshot; a later permitted version records the then-current server-derived display values while immutable codes and public IDs remain stable.
7. Draft save changes no encounter status; the first Final may move `REGISTERED` to `IN_EXAMINATION`; no v1 operation sets `READY_FOR_RM`, closes/discharges the encounter, or releases the bed.
8. Same-key replay, changed-payload conflict, stale-version, concurrent create/update, and audit-failure scenarios preserve one coherent document chain, one applicable status transition, and complete attributable evidence.
9. Longitudinal read projection orders service day, creation time, document type, author, and version deterministically and never exposes server action URLs that the actor is not authorized to use.
10. Synthetic reset removes the patient/document domain chain through declared reset behavior while retaining reset/audit evidence; rollback/down refuses to discard populated document/version/receipt or correlated audit evidence.
11. Focused SQLite behavior tests plus independent PostgreSQL 17 and MySQL 8.4 migration, constraint, idempotency, locking, rollback/refusal, reset, and race verification pass before this local slice is called complete.

## Retention, rollback, and reset

- No legacy or real-data backfill/import is authorized.
- Empty, never-used local structures may be rolled back.
- Once any document, immutable version, operation receipt, or correlated audit evidence exists, rollback/down must refuse to drop the populated structures. Disable the narrow routes/capabilities and retain evidence for reconciliation.
- Synthetic reset may remove synthetic patient, encounter, and inpatient-document chains through declared relationships while preserving the separate reset audit record. Reset is not an ordinary document deletion, correction, Final reversal, or evidence-erasure route.

## Authority matrix

| Authority | Current value |
| --- | --- |
| Create local application code, migrations, fixtures, UI, and deterministic tests for this exact profile | `true` |
| Run local synthetic migrations and tests on disposable/local databases | `true` |
| Produce local synthetic verification evidence | `true` |
| Clinical or Nursing owner acceptance | `false` |
| RMIK owner acceptance or `PAR-RMIK-002` implementation | `false` |
| `PAR-CLN-019` consolidation or broader inpatient parity acceptance | `false` |
| SIMRS Sahabat parity, G0 closure, G3 acceptance, or production readiness | `false` |
| Real patient data or production clinical use | `false` |
| Live BPJS, VClaim, E-Klaim, SATUSEHAT, LIS, PACS, payment, pharmacy, device, or other external integration | `false` |
| Commit, push, pull request, release, or publication | `false` |
| Hosted migration or deployment | `false` |

Local engineering evidence may prove that this teaching profile behaves as frozen; it cannot convert any `false` above to `true`.

## Explicit exclusions

This authorization does not implement or define transfer, class change, discharge, death/AMA disposition, bed release, waitlist/reservation, clinical orders or results, laboratory, radiology, prescription, medication administration, pharmacy, procedure, surgery, nutrition, rehabilitation, RMIK completeness/coding/custody, diagnosis coding, tariff, charge, cashier, billing, claim, BPJS, report formulas, public display, or any live integration.

No secrets, real patient data, production endpoints, or live integration credentials may be introduced. Any widening of document types or fields, actor assignments, service-date policy, correction/finalization lifecycle, encounter status effects, placement behavior, downstream outputs, or external systems requires a new bounded local record or named owner decision.
