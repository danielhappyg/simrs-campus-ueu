# Cross-Setting Radiology Order and Report V1

Status: **locally authorized for bounded teaching implementation and engineering verification**
Date: 2026-08-31
Coverage: `PAR-ADM-015` and `PAR-CLN-007`
Data boundary: internal `SIMULATION` mode with synthetic patients only

## Decision and outcome

The next local parity node is one hospital-like radiology workflow shared by outpatient, emergency, and inpatient encounters. It is not a copy of the shallow laboratory slice and does not create a second encounter. It provides a governed radiology examination master, physician order, radiology worklist, performance record, Draft-to-Verified report, append-only verified amendment, and ordering-physician acknowledgement.

This is local engineering authority under the simplified governance direction. It does not claim radiology-owner acceptance, parity acceptance, hosted readiness, deployment approval, or production use.

## Actors and exact role boundaries

- Exact `admin` manages the examination master only. System administrator status is not a clinical substitute.
- Exact `physician` creates an order during an eligible encounter, may cancel their own unperformed order with a reason, and acknowledges the current verified report for their own order.
- Exact `radiology_technologist` views the worklist and records the performed examination once.
- Exact `radiologist` views performed studies, saves report Draft versions, verifies the report, and appends a signed amendment after verification.
- `rmik` receives read-only completeness facts through the existing closure projections; it cannot perform, report, amend, acknowledge, or manage the master.
- No administrator, instructor, registrar, nurse, or mixed-role account may bypass the clinical role boundary.

Authorization is checked before route-resource lookup for every mutation. A direct service call repeats the exact actor-policy check before dereferencing an order or encounter relation. Unknown resources are not disclosed to unauthorized actors.

## Examination-master contract

The managed examination master has stable code, display name, optional preparation instruction, status, effective version, and immutable version history.

```text
ACTIVE -> RETIRED
```

An Active definition may be updated through an optimistic expected version. Retirement is terminal. Code reservation and operation receipts prevent code reuse, replay ambiguity, and race-dependent identity. Retiring or updating a master never rewrites an order snapshot or historical report. Only Active current masters may be ordered.

No tariff, modality device, contrast protocol, radiation-dose rule, specimen rule, charge mapping, insurer rule, or external catalogue is inferred in V1.

## Cross-setting order contract

One radiology order belongs to one existing synthetic encounter and snapshots the selected examination code, name, preparation instruction, ordering physician, clinical question, care setting, clinic/ward label, and creation time. Eligible care settings are `OUTPATIENT`, `EMERGENCY`, and `INPATIENT`; the encounter must be exactly `IN_EXAMINATION` when the order is created.

```text
ORDERED -> PERFORMED -> REPORTED_VERIFIED
    |
    +----> CANCELLED  (only before PERFORMED)
```

`ORDERED` is the worklist source. The worklist is a projection, not another stored state. A physician may cancel only their own `ORDERED` order and must supply a bounded reason code plus note when required. Performed, reported, acknowledged, closed-encounter, and cancelled-encounter orders cannot be cancelled or rewritten.

Creating, cancelling, or performing an order is transactional with mandatory audit and an immutable operation receipt. Exact same-key/same-payload replay returns the original result; changed-payload key reuse is denied and audited. Concurrent distinct-key operations resolve through mutable-head locking, optimistic versions, unique constraints, and replay/race reconciliation.

## Performance and report contract

Only the exact technologist role may append one immutable performance record to an `ORDERED` order. The performance records actor and time. V1 does not record image identifiers, acquisition metadata, modality worklist details, radiation dose, contrast administration, or device output.

After performance, the exact radiologist may create or replace the mutable report head by appending complete immutable Draft versions. Draft save requires `expected_version`; stale writes are denied. Verification appends an identical-content Verified version and makes the original report terminal:

```text
no report -> DRAFT v1 -> DRAFT v2 ... -> VERIFIED vN
```

The structured V1 report contains examination/findings narrative, impression, and optional recommendation. Findings and impression are required for verification. A Verified report cannot be edited, deleted, reverted to Draft, or verified twice.

## Verified amendment and acknowledgement

A post-verification correction is a new immutable, signed amendment. It binds the order, exact Verified report version/content digest, prior amendment digest, radiologist, reason, amended statement, and timestamp. It never changes the original report or earlier amendments. Multiple amendments form a deterministic append-only chain.

The ordering physician acknowledges the exact current result fingerprint: Verified report version/content digest plus the latest amendment digest. A later amendment makes the earlier acknowledgement historical and no longer current. A current acknowledgement is unique and append-only; changed-payload replay is denied.

The encounter remains in its normal care-setting lifecycle. Radiology does not reopen a Closed encounter and cannot append a report or amendment after closure.

## Closure, cancellation, and read-model effects

Encounter/RMIK closure must fail closed while any order is `ORDERED` or `PERFORMED`, any report is Draft, or any Verified report/amendment lacks a current acknowledgement. The deterministic checks are exposed as:

- `NO_ACTIVE_RADIOLOGY_ORDERS`; and
- `NO_UNACKNOWLEDGED_VERIFIED_RADIOLOGY_REPORTS`.

Pre-clinical encounter cancellation is denied once any radiology order or evidence exists. Cancelling the radiology order is not equivalent to cancelling the encounter and does not delete its evidence.

Physician encounter pages show the order/report/amendment/acknowledgement chain for their setting. The radiology worklist links back to the correct outpatient, emergency, or inpatient route. RMIK sees the completeness state read-only. No user-facing simulation or synthetic-data wording is added.

## Concurrency, evidence, and database guards

Every mutation requires a normalized idempotency key, canonical payload SHA-256, optional request correlation ID, and immutable receipt unique on actor, operation, and canonical key. Replay is checked before locking, after locking the mutable order/report/master head, and after uniqueness races.

Mutable heads have explicit legal transition guards. Master versions, order cancellations, performances, report versions, amendments, acknowledgements, code reservations, and receipts are append-only. PostgreSQL also refuses evidence-table truncation. MySQL CHECK, trigger, foreign-key, and index names are explicit, distinct, and dropped in safe order. Schema-qualified Laravel tables are used everywhere; schema-qualified strings are never passed to `Rule::exists`.

Success and denial audits use closed schemas and exact reason codes. A missing success audit rolls back the business mutation. Bounded synthetic reset deletes the radiology graph in dependency order while preserving radiology and reset audit evidence. Migration rollback refuses while business rows or correlated audit evidence remain.

## Verification gate

Local completion requires:

1. feature tests for all three care settings, exact role denials, authorization-before-lookup, lifecycle transitions, stale versions, replay/conflict, cancellation, amendment, acknowledgement invalidation, closure/cancellation dependencies, projections, and reset;
2. database/model/SQL guard tests, audit-contract tests, and migration fresh/down/reapply checks;
3. keyboard, error-focus, status announcement, contrast, and 44-pixel target checks for the master, encounter panel, and radiology worklist;
4. full PHP, frontend, formatting, type, lint, and production-build gates; and
5. disposable PostgreSQL 17 and MySQL 8.4 evidence covering least-privilege runtime grants, real race behavior, replay/conflict, append-only refusal, reset/audit preservation, rollback refusal, and complete cleanup.

## Explicit exclusions

- PACS, RIS, DICOM, images, viewer, modality worklist, accession integration, device output, or live endpoint;
- radiation-dose, contrast, pregnancy-screening, consent, or modality-specific clinical rules not yet established;
- tariff, charge, billing, cashier, claims, INA-CBG, BPJS/VClaim, SATUSEHAT, pharmacy, or inventory effects;
- generalized order engine, laboratory rewrite, pathology, microbiology, blood bank, surgery, nutrition, or rehabilitation;
- real patient data, secrets, push, deployment, hosted migration, or external write;
- radiology-owner acceptance, parity acceptance, production readiness, or G0/G3 closure.
