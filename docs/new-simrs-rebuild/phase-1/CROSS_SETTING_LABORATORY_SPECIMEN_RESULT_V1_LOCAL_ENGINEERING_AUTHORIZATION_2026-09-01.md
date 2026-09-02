# Cross-Setting Laboratory Specimen and Result V1

Status: **locally authorized for bounded implementation and engineering verification**
Date: 2026-09-01
Coverage: `PAR-ADM-014`, `PAR-ADM-043`, and `PAR-CLN-006`
Journey: `E2E-05` laboratory diagnostic lifecycle

## Decision and outcome

The next local graph node is one hospital-like laboratory workflow shared by outpatient, emergency, and inpatient encounters. It replaces new writes to the shallow outpatient-only laboratory shortcut with a governed examination master, physician order, attributable specimen collection and receipt, rejection/recollection, structured Draft-to-Verified result, manually identified critical-result communication, immutable signed amendment, and ordering-physician acknowledgement.

This record is local engineering authority under the simplified governance direction. It does not confer laboratory-owner acceptance, RMIK acceptance, parity acceptance, hosted readiness, deployment approval, or production use. No commit, push, hosted migration, or deployment is authorized by this record.

## Actors and exact role boundaries

- Exact `admin` manages the laboratory examination master only. Administrator status is not a clinical substitute.
- Exact `physician` creates an order during an eligible encounter, may cancel their own order before any specimen evidence exists, views released results for the encounter, and acknowledges the exact current released-result fingerprint for their own order.
- Exact `nurse` records bedside specimen collection against an existing order. A nurse cannot receive, accept, reject, result, verify, amend, or acknowledge.
- Exact `laboratory_technologist` receives a collected specimen, accepts or rejects it, and saves complete result Draft versions for an accepted specimen.
- Exact `laboratory_verifier` verifies a complete Draft, records required manual critical-result communication, and signs immutable post-verification amendments.
- Exact `rmik` receives read-only completeness facts through existing closure projections.
- No registrar, administrator, instructor, mixed-role account, or system-administrator flag may bypass these clinical boundaries.

Every mutation checks capability before route-resource lookup and repeats exact actor-policy checks inside the domain service before dereferencing protected relationships. Unauthorized actors receive no resource-existence disclosure.

## Managed examination and component contract

The managed examination master has a stable code, display name, specimen type, collection instruction, status, effective version, and immutable version history.

```text
ACTIVE -> RETIRED
```

Each version contains one to twelve ordered result components. A component snapshots a stable component code, display label, value kind (`TEXT`, `NUMERIC`, or `QUALITATIVE`), optional unit text, optional reference text, and whether a verifier may mark the component critical. The system stores and displays these texts; it does not calculate reference ranges, infer abnormality, or generate critical flags.

An Active master may be updated with an optimistic expected version. Retirement is terminal. Code reservation and immutable operation receipts prevent code reuse, replay ambiguity, and race-dependent identity. Updating or retiring a master never rewrites order, specimen, or result snapshots. Only the current Active version may be ordered.

## Cross-setting order contract

One laboratory order belongs to one existing encounter and snapshots the selected examination version and digest, examination/component definitions, specimen requirements, ordering physician, priority (`ROUTINE` or `URGENT`), clinical question, care setting, clinic/ward context, and order time. Eligible care settings are `OUTPATIENT`, `EMERGENCY`, and `INPATIENT`; the encounter must be exactly `IN_EXAMINATION` when ordered.

```text
ORDERED -> SPECIMEN_ACCEPTED -> REPORTED_VERIFIED
    |
    +----> CANCELLED  (only before any specimen evidence)
```

The active worklist is a projection, not a second stored state. A physician may cancel only their own `ORDERED` order, before any specimen attempt exists, with a bounded reason code and note where required. Cancellation preserves the order snapshot and operation evidence. A cancelled, accepted, Drafted, Verified, acknowledged, closed-encounter, or cancelled-encounter order cannot be cancelled or rewritten.

## Specimen identity, custody, and recollection

V1 supports one specimen requirement per order, repeated specimen attempts after rejection, and at most one accepted specimen. It does not support aliquots, pooled specimens, multiple simultaneous specimen types, derived specimens, microbiology isolates, pathology blocks/slides, or blood-bank custody.

The nurse appends one immutable collection attempt with a system-generated label identifier, collection time, collector, and optional bounded note. The exact technologist then appends receipt and one terminal assessment:

```text
COLLECTED -> RECEIVED -> ACCEPTED
                      +-> REJECTED
```

Rejection requires a reason code and optional note, preserves the complete attempt, and leaves the order unresolved so that a replacement specimen may be collected. Acceptance moves the order to `SPECIMEN_ACCEPTED`; no later specimen attempt may be added. Direct state rewrites, deletion, and duplicate acceptance are refused.

## Structured result, verification, and critical communication

For the accepted specimen, the technologist saves complete immutable Draft versions using optimistic `expected_version` control. Each Draft binds every snapshotted component to a value, optional component note, and verifier-facing manual interpretation flag (`NORMAL`, `ABNORMAL`, or `CRITICAL`). Numeric values are validated as decimal text but are not clinically interpreted by the system.

```text
no result -> DRAFT v1 -> DRAFT v2 ... -> VERIFIED vN
```

Verification is performed only by the exact laboratory verifier and appends an identical-content Verified version. Every required component must have a value and interpretation flag. A Verified base result cannot be edited, deleted, reverted, or verified twice.

If any component is manually marked `CRITICAL`, verification also requires an immutable communication record containing communication time, method, recipient physician identity, outcome (`COMMUNICATED` or `ESCALATED`), and a bounded note. This records an operational handoff; it does not infer clinical thresholds, replace a hospital escalation protocol, or claim automated alerting.

## Verified amendment and physician acknowledgement

A correction after verification is a new immutable signed amendment by the exact laboratory verifier. It binds the order, accepted specimen, exact Verified base digest, prior amendment digest, complete replacement component result, correction reason, signer, and timestamp. It never edits the base result or an earlier amendment. Amendments form one deterministic append-only chain and are allowed only while the encounter remains open.

The ordering physician acknowledges the exact current result fingerprint: order snapshot digest, accepted-specimen digest, Verified result digest, latest amendment digest, and critical-communication digest when present. A later amendment makes the prior acknowledgement historical and stale. The current acknowledgement is unique and append-only.

## Closure, cancellation, and compatibility effects

Outpatient and inpatient RM closure must fail closed while either the governed v1 graph or the legacy compatibility graph contains unresolved laboratory work. The deterministic v1 checks are:

- `NO_ACTIVE_LAB_ORDERS`;
- `NO_UNRESOLVED_LAB_SPECIMENS`; and
- `NO_UNACKNOWLEDGED_VERIFIED_LAB_RESULTS`.

`ORDERED`, rejected-without-recollection, accepted-without-Verified-result, Draft, and Verified/amended-without-current-acknowledgement conditions block closure. A correctly cancelled order does not block closure but remains visible as history. The same closure facts are exposed for emergency care without inventing an IGD RM-closure transition.

Pre-clinical encounter cancellation is denied once any governed or legacy laboratory order/specimen/result evidence exists. Laboratory order cancellation is not encounter cancellation and never deletes evidence.

The existing `lab_service_requests` and `lab_diagnostic_results` remain readable compatibility evidence. New UI writes use the governed v1 graph. Closure, reset, recovery, and encounter projections inspect both graphs until a later separately authorized retirement removes the legacy path. No destructive reinterpretation or backfill of legacy rows occurs.

## User experience contract

- One laboratory worklist presents role-appropriate actions and links to the correct RJ, IGD, or RI encounter.
- Encounter pages show physicians the ordered examination, specimen state, released result/amendment chain, critical communication fact, and current acknowledgement.
- Nurses see collection tasks but not Draft result content.
- Technologists see collection/receipt/result tasks but cannot verify.
- Verifiers see complete Draft content and verification/amendment actions.
- RMIK sees deterministic completeness facts but not editing controls.
- Master management uses Indonesian labels and established UEU design tokens.
- No user-facing simulation or synthetic-data wording is added.

## Concurrency, receipts, audit, and database guards

Every mutation requires a normalized idempotency key, canonical payload SHA-256, optional correlation ID, and immutable short operation receipt unique on actor, operation, and canonical key. Same-key/same-payload replay returns the original result after recomputing retained evidence digests; changed-payload key reuse is denied and audited.

Writers lock the encounter first, then the mutable order/result/master head. Mutable heads have explicit legal transition guards. Master versions, code reservations, specimen events, result versions, critical communications, amendments, acknowledgements, cancellations, and receipts are append-only. Database constraints and triggers refuse snapshot mutation, illegal transitions, evidence deletion, and PostgreSQL evidence-table truncation. Schema-qualified Laravel tables are never passed as strings to `Rule::exists`.

Success and denial audits use closed action/reason schemas. A missing success audit rolls back the business mutation. Bounded reset deletes governed and legacy laboratory graphs in dependency order while preserving audit evidence. Migration rollback refuses while governed business rows or correlated audit facts remain.

## Verification gate

Local completion requires:

1. feature tests for all three settings, exact role denial, authorization-before-lookup, master lifecycle, order/cancellation, specimen rejection/recollection, Draft/Verified/amendment, critical communication, acknowledgement invalidation, compatibility projections, closure/cancellation dependencies, replay/conflict, reset, and recovery snapshot behavior;
2. database/model/SQL guard, audit-contract, fresh migration, empty down/reapply, and retained-evidence rollback-refusal tests;
3. keyboard, error-focus, status announcement, contrast, and 44-pixel target checks for master, encounter task panels, and the role-scoped laboratory worklist;
4. full PHP, frontend, formatting, type, lint, production-build, and diff gates; and
5. disposable PostgreSQL 17 and MySQL 8.4 evidence for least-privilege runtime grants, two-process races, replay/conflict, append-only refusal, complete evidence-chain verification, reset/audit preservation, rollback refusal, and strict cleanup.

## Explicit exclusions

- LIS, analyser/instrument, label-printer, barcode scanner, device output, external terminology, or live endpoint;
- automated reference-range, delta-check, abnormal/critical inference, clinical decision support, or automated notification;
- microbiology, anatomic pathology, cytology, blood bank, transfusion, genetic testing, send-out referral integration, or chain-of-custody beyond the bounded specimen attempts above;
- tariff, charge, billing, cashier, claim, INA-CBG, BPJS/VClaim, SATUSEHAT, pharmacy, or inventory effects;
- post-closure result correction, encounter reopen, external message, email, SMS, or push notification;
- real patient data, secrets, commit, push, deployment, hosted migration, or external write;
- laboratory-owner acceptance, RMIK acceptance, parity acceptance, production readiness, or G0/G3 closure.
