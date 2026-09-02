# Cross-Setting Versioned Encounter Bill V1

Status: **locally authorized for bounded implementation and engineering verification**
Date: 2026-09-01
Primary coverage contribution: `PAR-FIN-001` and `PAR-FIN-002`
Journey contribution: `E2E-14` bill assembly only

## Decision and outcome

The next Release 1.0 dependency-graph node is one shared encounter-bound bill spine for outpatient, emergency, and inpatient care:

```text
immutable source-domain financial event
  -> deterministic finance-source import
  -> encounter bill control totals
  -> cashier-issued immutable bill version and lines
  -> later source event makes a newer version issuable without rewriting history
```

V1 imports only the monetary facts that already exist: `pharmacy_financial_source_events` created atomically by medication handover and return. Registration, laboratory, radiology, emergency, inpatient placement, clinical documentation, and RMIK records provide encounter context but currently provide no authorized tariff or monetary source event. V1 must not invent prices for those domains or silently interpret clinical completion as a charge.

This is local synthetic teaching engineering authority under the product owner's simplified governance direction. The canonical Batch F proposal remains an observation and is not rewritten by this authorization. This record does not confer cashier/revenue, finance, pharmacy, clinical, RMIK, or parity acceptance; hosted readiness; deployment approval; G0/G3 closure; or use with real patient or financial data. No commit, push, hosted migration, or deployment is authorized by this record.

## Exact bounded scope

- `PAR-FIN-001`: locally contributes the versioned outpatient bill for admitted pharmacy charge and reversal facts.
- `PAR-FIN-002`: locally contributes the versioned inpatient bill for admitted pharmacy charge and reversal facts.
- Emergency encounters use the same safe application workflow, but V1 makes no `PAR-FIN-003` or revenue-report coverage claim.
- V1 does not implement tariff masters, manual/other charges, discounts, guarantees, deposits, receipts, payment allocation, receivables, settlement, journals, revenue recognition, claims, or reports.
- A bill is an immutable encounter charge statement. It is not a tax invoice, payment receipt, claim, accounting posting, or evidence that money was received.

## Source admission contract

The only admitted source domain and event types are closed:

| Source domain | Event | Direction | Required source fact |
|---|---|---:|---|
| Pharmacy | `CHARGE` | positive | One exact handed-over prescription item, whole-unit quantity, snapshotted teaching sale value, encounter and patient |
| Pharmacy | `REVERSAL` | negative | One exact returned prescription item linked to the original handover, whole-unit quantity, original snapshotted value, encounter and patient |

Every imported finance-source row snapshots source domain, source table, source public ID, source content digest, event type, patient, encounter, care setting, quantity, unit amount, signed amount, description, occurred time, and import time. A closed composite uniqueness key prevents the same source event from being imported twice. Import verifies the retained source row and its domain reconciliation before writing anything.

V1 also retains an explicit foreign key to the admitted `pharmacy_financial_source_events` row. A future source domain must add a separately authorized adapter and binding; it cannot masquerade as a pharmacy event or use an untyped polymorphic reference.

The source adapter never updates, deletes, acknowledges, or otherwise writes back to pharmacy evidence. Changed or missing retained source content is an integrity failure. Unsupported tables, event types, negative charge direction, positive reversal direction, cross-patient binding, cross-encounter binding, and arithmetic mismatch are denied before finance mutation.

## Bill aggregate and version contract

One bill head exists per encounter. It retains the synthetic patient binding, care setting, current issued version number, current source-set digest, current issued source-set digest, and lifecycle state:

```text
OPEN_NO_VERSION -> ISSUED_CURRENT -> NEW_SOURCE_PENDING
                         ^                 |
                         +---- issue next immutable version
```

- `OPEN_NO_VERSION` means admitted source rows exist but no bill version has been issued.
- `ISSUED_CURRENT` means the latest immutable bill version exactly matches the current admitted source set.
- `NEW_SOURCE_PENDING` means one or more later charge/reversal events exist after the latest issued version. The earlier version remains intact and visible.
- A new source event never edits an issued bill or line. The cashier issues the next version from a deterministic source snapshot.
- A bill version includes version number, previous-version link, source-set digest, gross charges, reversals, net amount, source-event count, issuer, issue reason, server issue time, and content digest.
- Bill lines are immutable one-to-one snapshots of admitted source rows in deterministic occurred-time, source-domain, source-public-ID order.
- Integer rupiah is mandatory. Floating-point money is prohibited.
- `gross + reversals = net`, where charge amounts are non-negative and reversal amounts are non-positive. The net amount may not fall below zero; an over-reversal is a hard integrity failure.
- Same retained source-set digest and same idempotency key replays the original issue result. A changed payload under the same key is denied. Issuing an identical new version without source-set change is denied.
- A cancelled encounter or an encounter without at least one valid valued source cannot issue a bill.

V1 does not mark an encounter financially settled or closed. Ordinary clinical/RMIK closure does not erase or rewrite bill evidence, and bill issuance does not reopen or advance the encounter.

## Proposed local data model

- `finance_charge_events`: append-only normalized source facts with explicit pharmacy-source foreign key, encounter/patient snapshots, event direction, quantity, unit value, signed amount, source/content digests, and unique source-event binding.
- `finance_bills`: one governed mutable head per encounter with stable public bill number, current version, current source digest, and current-issued source digest.
- `finance_bill_versions`: immutable version chain with patient, encounter, care-setting, payer, service-location, source-cutoff, coverage-profile, prior-version, gross, reversal, net, issuer, and content-digest snapshots.
- `finance_bill_lines`: immutable one-to-one version snapshots of normalized charge events, unique per bill version and charge event.
- `finance_operation_receipts`: immutable actor, operation, canonical key, payload digest, result digest, and retained result identity.

No tariff, discount, tax, payment, receipt, receivable, claim, journal, or settlement table belongs in V1.

## Actor and authorization boundary

- Exact `cashier` may view the role-scoped bill worklist and encounter bill, synchronize admitted source facts, and issue the next immutable bill version.
- Exact `pharmacist` retains ownership of medication handover/return only and receives read-only visibility of whether its financial source events were imported.
- Exact `rmik` receives read-only bill-version identity and reconciliation status for record traceability, not payment or claim authority.
- `physician`, `nurse`, `registrar`, laboratory, radiology, pharmacy technician, and pharmacy inventory-controller roles cannot import or issue bills.
- Exact `admin` can provision the cashier role and run bounded reset/audit duties but cannot act as cashier. A system-administrator flag or mixed-role account does not bypass the exact-role rule.

Capability checks happen before route-resource lookup and repeat inside the finance service before protected relationship access. Unauthorized actors receive no bill, patient, encounter, or source existence disclosure.

## Lock order and concurrency

The finance writer follows one deterministic order:

```text
encounter
  -> bill head
  -> admitted source rows ordered by source identity
  -> latest bill version
  -> immutable version lines
  -> audit and operation receipt
```

The adapter reads immutable pharmacy source evidence and never acquires pharmacy inventory, prescription, or lot locks. Pharmacy remains the sole writer of its source events. This separation avoids a reverse finance-to-pharmacy lock edge.

Two-process verification must prove:

- same-key same-payload issue produces one `APPLIED` and one `REPLAYED` outcome with one durable version;
- different-key competition on the same expected source-set/version produces one applied result and one attributable stale denial;
- a source event committed before the finance snapshot is included exactly once;
- a source event committed after the finance snapshot is excluded from that version and makes the bill `NEW_SOURCE_PENDING` for the next version; and
- reset/recovery competition produces no partial version, orphan line, or duplicated source import.

## Receipts, audit, guards, reset, and recovery

Every import/issue mutation requires a normalized idempotency key, canonical payload SHA-256, optional correlation ID, and immutable operation receipt unique on actor, operation, and canonical key. Same-key/same-payload replay recomputes retained finance evidence before returning the original result. Changed-payload key reuse is denied and audited.

Finance source imports, bill versions, bill lines, and operation receipts are append-only. The bill head is writable only through a governed finance mutation scope. Database guards refuse ordinary update/delete/truncate of immutable evidence and refuse unscoped head updates. Required success audit failure rolls back the whole operation.

Bounded synthetic reset deletes the finance graph in dependency order while retaining required audit evidence. Recovery snapshots include stable table digests, orphan checks, duplicate-source checks, bill-line/source reconciliation, per-version arithmetic, latest-head/version reconciliation, operation-receipt reconciliation, and post-reset emptiness. Migration rollback refuses while business rows or correlated audit facts remain.

## User experience contract

- `Kasir` becomes a dedicated live module only for actors with the new billing capability.
- Indonesian screens provide `Daftar Tagihan`, `Detail Tagihan Pasien`, `Sumber Biaya`, `Terbitkan Versi Tagihan`, and `Riwayat Versi`.
- Worklist columns include encounter number, patient, care setting, latest version, gross charge, reversal, net amount, reconciliation state, and latest source time.
- Detail groups lines by source domain and shows the exact source reference, description, quantity, unit amount, signed amount, and occurrence time.
- The interface clearly distinguishes `Belum diterbitkan`, `Versi terkini`, and `Ada sumber biaya baru` with text, not colour alone.
- The detail states the exact admitted coverage, such as `Obat yang telah diserahkan dan retur terkait`; it must not imply that the version is a complete hospital bill while other source domains have no authorized tariff facts.
- Issuance requires deliberate confirmation, an issue reason, expected bill fingerprint, and idempotency key; error summaries receive focus; status updates use an accessible live region; controls have at least 44-pixel targets; tables retain semantic headers and keyboard access.
- No user-facing simulation or synthetic-data wording is added.

## Verification gate

Local completion requires:

1. feature tests for RJ, IGD, and RI import/read/issue flows, exact roles, authorization-before-lookup, immutable versions, later source events, replay/conflict, arithmetic, unsupported-source refusal, and source drift/corruption refusal;
2. database/model/SQL-guard, audit-contract, fresh migration, empty down/reapply, retained-evidence rollback-refusal, reset, and recovery tests;
3. two-process PostgreSQL/MySQL concurrency tests for issue replay, competing expected versions, source-before/after-snapshot behavior, and reset/recovery competition;
4. frontend tests for Indonesian labels, role-safe actions, semantic totals, keyboard behavior, error focus, live status, and 44-pixel targets;
5. full PHP, PHPStan, Pint, frontend test/type/lint/format/build, documentation, and diff gates; and
6. disposable PostgreSQL 17 and MySQL 8.4 evidence for least-privilege grants, races, guards, reconciliation, reset/audit preservation, rollback refusal, and strict cleanup.

## Explicit exclusions

- real patient data, real prices, real financial records, secrets, or external write;
- invented registration, laboratory, radiology, room, procedure, professional-fee, or other tariff facts;
- `PAR-FIN-003` through `PAR-FIN-019`, except that their future dependencies must not be blocked by the V1 schema;
- tariff/component masters (`PAR-ADM-011`, `PAR-ADM-018`, `PAR-ADM-019`) until separately defined;
- manual charges, discounts, guarantees, deposits, receipts, cash/card/transfer handling, payment allocation, refund, receivables, settlement, treasury deposit, revenue recognition, tax invoice, general ledger, or journal;
- claim, INA-CBG/iDRG, BPJS/VClaim/SATUSEHAT, insurer, payment gateway, bank, mail, printer, fiscal device, or other integration;
- cashier/revenue, finance, pharmacy, clinical, RMIK, or parity acceptance; hosted readiness; production readiness; G0/G3 closure; commit; push; deployment; or hosted migration.

## Next graph boundary

After this V1 passes both exact engines, the next finance work must be selected from evidence, not assumed. Likely successors are separately versioned tariff/component masters and source-domain adapters, followed by bill detail projections. Payment/receivable/settlement work starts only after the charge and bill spine is complete and accepted for its bounded use.
