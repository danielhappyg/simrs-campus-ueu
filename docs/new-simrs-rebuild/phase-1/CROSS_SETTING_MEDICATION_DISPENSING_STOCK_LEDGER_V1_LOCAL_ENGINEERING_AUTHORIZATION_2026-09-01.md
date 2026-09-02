# Cross-Setting Medication Dispensing and Stock Ledger V1

Status: **locally authorized for bounded implementation and engineering verification**
Date: 2026-09-01
Primary coverage contribution: `PAR-PHA-001`, `PAR-PHA-002`, `PAR-PHA-003`, `PAR-PHA-015`, `PAR-PHA-020`, and `PAR-PWH-001`
Journeys: `E2E-02`, `E2E-03`, and `E2E-04` medication handoff

## Decision and outcome

The next Release 1.0 graph node is one shared hospital-like medication workflow for outpatient, emergency, and inpatient encounters:

```text
Physician prescription
  -> pharmacist verification or refusal
  -> technician preparation
  -> pharmacist handover
  -> immutable lot stock and medication-charge-source ledgers
  -> attributable return and reversal when eligible
```

V1 also provides a versioned medicine master, managed dispensing depots, synthetic opening stock by lot and expiry, prescription history, and a stock card derived from immutable movements. This is not a restoration of the failed Antrean/work-queue MVP. New pharmacy behavior is built on the clean-slate encounter, RBAC, audit, receipt, recovery, and database-portability foundations.

This record is local engineering authority under the product owner's simplified governance direction. It does not confer pharmacy, clinical, warehouse, finance, RMIK, or parity acceptance; hosted readiness; deployment approval; G0/G3 closure; or use with real patient data. No commit, push, hosted migration, or deployment is authorized by this record.

## Architecture choice

Three next nodes were considered:

| Option | Release value | Complexity | Decision |
|---|---|---:|---|
| Cross-setting Apotek and stock | Closes a required RJ/IGD/RI handoff and unlocks billing reconciliation | High | **Selected** |
| Inpatient Gizi | Extends the RI journey with lower inventory risk | Medium | Follow after the medication/financial spine |
| Operational reports | Makes existing data visible but does not close a missing transaction handoff | Medium | Follow source-domain completion |

Apotek is selected because it is a larger mandatory predecessor for billing, claims preparation, inventory reconciliation, and several operational reports. Its higher risk is addressed by freezing the stock, lock-order, financial-source, return, and encounter-gate contracts before implementation.

## Exact actor and capability boundaries

- Exact `physician` creates a prescription during an eligible encounter, replaces a pre-verification prescription, and cancels their own prescription before pharmacy verification.
- Exact `pharmacist` verifies or refuses a prescription, performs final patient/caregiver handover, records an eligible return, and sees clinical context required for verification.
- Exact `pharmacy_technician` prepares a verified prescription and records the actual lot allocation proposal. A technician cannot verify, hand over, return, change masters, or adjust stock.
- Exact `pharmacy_inventory_controller` manages medicine and depot masters, records synthetic opening balances, quarantines or releases an eligible lot, and appends reasoned stock corrections. This role cannot prescribe, verify, prepare, hand over, or return medication.
- Exact `nurse` has read-only medication status inside an encounter and no pharmacy mutation capability.
- Exact `rmik` receives read-only prescription completeness and provenance facts for closure.
- Exact `admin` provisions roles and retains audit/reset duties but is not a pharmacist, inventory controller, technician, or clinical substitute.

Every mutation checks capability before route-resource lookup and repeats exact actor-policy checks inside the domain service before dereferencing protected relationships. A mixed-role or system-administrator flag does not bypass the exact-role boundary. Unauthorized actors receive no resource-existence disclosure.

## Medicine, depot, lot, and unit contract

The versioned medicine master has a stable code, generic display name, optional brand display name, strength text, dosage form, one indivisible base issue unit, route choices, Active/Retired state, standard acquisition value in whole rupiah, and teaching sale value in whole rupiah. Monetary values use integers; floating-point money is prohibited. Versions are immutable and updates never rewrite a prescription, allocation, movement, or financial-source snapshot.

```text
ACTIVE -> RETIRED
```

V1 quantities are positive whole base units. Fractional tablets, compounding, unit conversion, split packs, narcotic/psychotropic controls, formularies, substitutions, interaction engines, and automated dose or allergy advice are excluded until separately defined. Prescribers record dose text, route, frequency text, duration text, requested whole-unit quantity, and clinical instruction explicitly; the system stores but does not clinically interpret those fields.

Each managed depot has a stable code, Indonesian display name, eligible care settings, Active/Retired state, and immutable versions. V1 seeds distinct `DEPO_RJ`, `DEPO_IGD`, and `DEPO_RI` teaching depots. A prescription may be routed only to the current Active depot eligible for its encounter setting.

Stock exists only as attributed lots. Every lot snapshots medicine version, depot version, lot code, received/opened time, optional expiry date, available quantity, quarantined quantity, acquisition value, source reference, and actor. Non-expiring lots require an explicit `NO_EXPIRY_ASSIGNED` reason and sort after dated eligible lots. An expired or quarantined lot is never eligible for preparation or handover.

Opening balances and corrections append immutable stock movements; direct balance edits are forbidden. A negative available or quarantined balance is forbidden by service and database guards.

## Prescription and verification lifecycle

One prescription belongs to exactly one synthetic patient and one active encounter in `OUTPATIENT`, `EMERGENCY`, or `INPATIENT`. It snapshots the encounter setting and location, selected depot version, medicine versions, instructions, quantities, ordering physician, clinical note, server time, and canonical digest.

```text
DRAFT -> ORDERED -> VERIFIED -> PREPARED -> HANDED_OVER
   |         |          |
   +---------+----------+-> CANCELLED or REFUSED where explicitly allowed
```

- Only the physician may save or revise Draft content.
- Ordering appends an immutable Ordered version; ordered content is never silently edited.
- Before verification, the ordering physician may cancel or create a linked replacement with a bounded reason and expected fingerprint.
- Verification is attributable and item-by-item. The pharmacist confirms identity/context, medicine/instruction readability, manual allergy-review status, and requested quantity; records `VERIFIED` or `REFUSED`; and supplies a reason for every refusal. The application does not infer clinical suitability.
- A pharmacist may reduce but never increase an item quantity during verification. Any reduction requires a reason and becomes the maximum dispensable quantity. A changed medicine, route, dose, frequency, or duration requires physician replacement, not pharmacy editing.
- Preparation records the proposed actual lots and quantities but does not reduce stock. It may allocate multiple lots only in deterministic FEFO order and may be replaced before handover if the retained stock facts are stale.
- Final handover revalidates the exact current prescription, verification, preparation, lot, depot, encounter, and actor fingerprints; atomically decrements stock; appends movement and medication financial-source events; marks the dispense handed over; and records the mandatory audit and operation receipt.
- Partial dispensing is allowed only when the pharmacist explicitly records the unfilled quantity and reason. The prescription becomes `PARTIALLY_HANDED_OVER`; the remaining quantity may be prepared later or closed as `UNFILLED_CLOSED` by a pharmacist with a reason. It never disappears from reconciliation.
- A refused, cancelled, fully handed-over, or unfilled-closed prescription is terminal except for the bounded return workflow.

## FEFO and global lock hierarchy

Every patient-facing pharmacy mutation follows one lock order:

```text
patient active-admission claim mutex when an inpatient placement may be read
  -> sorted inventory mutexes by (depot code, medicine code)
  -> encounter rows in deterministic ID order
  -> current inpatient location/ward/bed when applicable
  -> prescription and dispense heads
  -> eligible lot rows ordered by:
       expiry-null-last, expiry date, received time, lot code, row ID
  -> audit and immutable receipt
```

This order is shared with discharge, transfer, cancellation, reset, recovery, and later warehouse transfers. No medication writer may acquire an inventory mutex after locking the encounter. Existing encounter-first diagnostic writers do not acquire inventory or placement locks and therefore remain outside this hierarchy.

Preparation may display a provisional FEFO allocation, but handover always recomputes it inside the final transaction. An operator may not skip an earlier eligible lot. Ties use received time, lot code, then row ID. The transaction uses atomic conditional decrements and fails closed when retained stock is insufficient. Database default NULL ordering is never relied upon.

## Return and reversal

V1 accepts only a whole-unit return against an exact handed-over dispense item, within the same open encounter or before RMIK closure, with original quantity remaining, reason, receiving pharmacist, return time, and condition:

- `RETURN_TO_STOCK`: unopened and manually accepted; appends a positive movement back to the original lot and reverses the corresponding financial-source amount.
- `QUARANTINE`: not eligible for issue; appends a positive quarantined movement and financial-source reversal.
- `DESTROYED_OR_NOT_RETURNABLE`: records custody outcome and financial-source reversal but does not increase available or quarantined stock.

The software does not decide whether a medicine is safe to return. The pharmacist records the manual decision. Returns never edit the original dispense or movement. Cumulative returned quantity cannot exceed handed-over quantity, and same-key replay cannot duplicate stock or money effects.

## Financial-source boundary and reconciliation

Handover appends one immutable medication charge-source event per dispensed item using the snapshotted teaching sale value and whole-unit quantity. Return appends an equal-and-opposite reversal event for the returned quantity. These events are source facts for the later cashier/billing node; they are not invoices, receivables, payments, claims, BPJS transactions, or proof of accounting acceptance.

Every prescription exposes deterministic control totals:

- ordered quantity;
- verified quantity;
- handed-over quantity;
- returned quantity by condition;
- net stock delta by lot and depot;
- gross medication charge-source amount;
- reversed amount; and
- net medication charge-source amount.

The stock card is derived solely from immutable movement rows and must reconcile opening plus movements to the guarded lot balance. A discrepancy is a hard integrity failure, not an informational warning.

## Encounter, handoff, discharge, and closure dependencies

- Pre-clinical encounter cancellation is denied after any medication Draft, order, verification, preparation, handover, return, movement, or financial-source evidence exists.
- RJ clinical/RMIK closure, IGD disposition completion, and RI discharge fail closed while a prescription is Draft, Ordered, Verified, Prepared, Partially Handed Over with an open remainder, or has an in-progress return operation.
- An ordered prescription must be fully handed over, refused, cancelled where eligible, or explicitly unfilled-closed before the episode may advance.
- IGD-to-RI handoff never reparents a prescription. The source IGD prescription remains source evidence and must be terminal before handoff. Any medication evidence in the receiving RI episode makes later handoff compensation ineligible.
- An inpatient prescription and dispense snapshot bind the current location-event fingerprint. Transfer is permitted only when no preparation is active. Ordered/Verified prescriptions remain encounter-bound and route to the new eligible depot or require an attributable cancellation/replacement; Prepared prescriptions block transfer until released or completed.
- A return after ordinary discharge but before RMIK closure locks the discharged encounter and does not reactivate the bed or clinical episode.
- Closed or cancelled encounters reject every new medication mutation.

## User experience contract

- Encounter pages provide `Resep & Obat` with physician ordering and role-safe status/history.
- `Antrean Resep` is a role-scoped pharmacy worklist, not the rejected general Antrean/work-queue MVP.
- Dedicated Indonesian screens provide `Verifikasi Resep`, `Penyiapan`, `Penyerahan Obat`, `Retur Obat`, `Riwayat Resep`, `Master Obat & Depo`, and `Kartu Stok`.
- Medicine name, strength, dosage form, unit, route, frequency, quantity, depot, lot, expiry, status, and warnings are always textual and never colour-only.
- Worklists are keyboard complete, controls have at least 44-pixel targets, error summaries receive focus, status changes are announced, and final handover/return requires deliberate confirmation.
- No user-facing simulation or synthetic-data wording is added.

## Receipts, audit, guards, reset, and recovery

Every mutation requires a normalized idempotency key, canonical payload SHA-256, optional correlation ID, and immutable operation receipt unique on actor, operation, and canonical key. Same-key/same-payload replay recomputes retained evidence and returns the original result. Changed-payload key reuse is denied and audited.

Medicine/depot versions, code reservations, prescription versions, verification/refusal events, preparation allocations, handovers, returns, stock movements, financial-source events, and receipts are append-only. A stale active preparation may only change once to the retained `SUPERSEDED` state with an attributable replacement reason and link; other mutable heads and lot balances are writable only through the governed pharmacy mutation scope. Portable database guarantees cover foreign keys, uniqueness, closed state/type domains, positive quantities, signed financial-source direction, non-negative balances, governed mutable-head writes, immutable evidence update/deletion, and PostgreSQL evidence-table truncation refusal. Cross-patient/context binding, legal graph transitions, cumulative terminal behavior, and movement-to-balance consistency are fail-closed service invariants and are independently rechecked by recovery reconciliation; they are not claimed as portable transaction-end database guarantees. Schema-qualified Laravel tables are never passed as strings to `Rule::exists`.

Success and denial audit actions/reasons use a closed registry. A missing success audit rolls back the complete operation. Synthetic reset deletes pharmacy children in dependency order while retaining audit evidence. Recovery snapshots include counts, stable digests, orphan checks, movement-to-balance reconciliation, stock-to-financial-source reconciliation, prescription-state reconciliation, and reset/recovery semantic checks. Migration rollback refuses while business rows or correlated audit facts remain.

## Verification gate

Local completion requires:

1. feature tests across RJ, IGD, and RI for exact actors, authorization-before-lookup, masters, ordering, replacement/cancellation, verification/refusal, FEFO preparation, full/partial handover, stock shortage, expiry/quarantine, return outcomes, history, stock card, financial-source reconciliation, encounter gates, replay/conflict, reset, and recovery;
2. database/model/SQL-guard, audit-contract, fresh migration, empty down/reapply, and retained-evidence rollback-refusal tests;
3. two-process concurrency tests for competing handovers, return-versus-handover, transfer/discharge-versus-preparation, and reset/recovery-versus-operation;
4. keyboard, error-focus, status announcement, contrast, semantic-table, and 44-pixel target checks;
5. full PHP, frontend test/type/lint/format/build, documentation, and diff gates; and
6. disposable PostgreSQL 17 and MySQL 8.4 evidence for least-privilege grants, exact lock ordering, races, append-only refusal, reconciliation, reset/audit preservation, rollback refusal, and strict cleanup.

## Explicit exclusions

- real patient data, production stock, real prices, secrets, or external write;
- live BPJS/VClaim/SATUSEHAT, e-prescribing, supplier, procurement, payment, claim, formulary, drug information, or other integration;
- automated dose, allergy, interaction, contraindication, duplicate-therapy, renal/hepatic, or clinical decision support;
- compounding, sterile products, chemotherapy, narcotic/psychotropic custody, ward floor stock, consignment, controlled temperature, supplier purchase/receipt, stocktake, inter-depot transfer, or destruction authorization;
- medication administration record, bedside scanning, administration schedule, or nurse administration evidence;
- invoice, payment, general ledger, claim, charge acceptance, or accounting-period closure;
- post-RMIK-closure return/correction, real printer/scanner/device, or external notification;
- domain-owner acceptance, parity acceptance, hosted readiness, production readiness, G0/G3 closure, commit, push, deployment, or hosted migration.
