# Medication Replenishment and Warehouse-to-Depot Custody V1

Status: **bounded local implementation present; active security correction and exact-engine acceptance remain open**

Date: 2026-09-02

Primary coverage contribution: `PAR-ADM-017`, `PAR-ADM-030`, `PAR-PWH-002`, `PAR-PWH-003`, `PAR-PWH-005`, `PAR-PWH-006`, `PAR-PWH-011`, `PAR-PWH-012`, `PAR-PWH-014`, `PAR-PWH-016`, and `PAR-PWH-017`

Existing prerequisite coverage: `PAR-PWH-001`, `PAR-PHA-001`, `PAR-PHA-002`, `PAR-PHA-003`, `PAR-PHA-015`, and `PAR-PHA-020`

Journey contribution: primary bounded contribution to `E2E-11`; required regression handoffs to `E2E-10`, `E2E-02`, `E2E-03`, `E2E-04`, `E2E-14`, and `E2E-16`; prerequisite contribution only for later `E2E-09`

## Authority and boundary

Under the standing product-owner instruction, Release 1.0 may locally implement and verify the exact bounded slice in this record. The application remains in `APP_MODE=SIMULATION` with synthetic-only medicines, suppliers, purchase orders, stock, staff, and encounters. No secret, live endpoint, outbound supplier message, production stock, or real patient data is authorized. Commit, push, hosted migration, and deployment remain outside this authorization.

This is standing product-owner local-engineering authorization only. It is not procurement, pharmacy, warehouse, clinical, finance-accounting, security/privacy, operations/recovery, domain-owner, or parity acceptance. It does not establish that these exact semantics were observed in SIMRS Sahabat, appoint a real-hospital authority, close G0 or G3, or authorize production use.

The ordinary worklists use concise Indonesian hospital language. Synthetic-only safety remains enforced by configuration, middleware, database constraints, tests, reset/recovery controls, and evidence; it does not require a persistent simulation banner on every operational screen or printable internal document.

### Single-checkpoint release boundary

The current single checkpoint may carry the warehouse source so the reviewed local work is not split across additional GitHub/Vercel releases. Shipping source bytes is not warehouse activation. For this checkpoint:

- Production keeps `WAREHOUSE_SCHEMA_MIGRATION_ENABLED=false`.
- `2026_09_03_000100_expand_warehouse_teaching_role_access_roster` and `2026_09_03_000200_create_medication_replenishment_warehouse_custody_tables` are excluded from the hosted migration allowlist.
- No warehouse route, navigation entry, hosted schema, hosted write, or role-based warehouse UAT is activated or claimed.
- The local application/service/migration/test source exists, but its active security-correction gate must finish before the checkpoint is frozen.
- PostgreSQL 17.10 and MySQL 8.4.11 evidence remains `READY / NOT RUN`; SQLite and documentation checks cannot substitute for exact-engine acceptance.

Warehouse activation requires a later, separately reviewed migration manifest, exact-engine PASS evidence, least-privilege hosted identities and guards, routes/UI, hosted migration, and role-based UAT. Procurement, pharmacy, warehouse, finance-accounting, security/privacy, operations/recovery, affected-domain, and parity acceptance remain open.

## Exact-engine migration release posture

The warehouse custody migration file may be shipped with an application release while its PostgreSQL/MySQL schema change remains **SHIPPED / PENDING — NOT APPLIED**. An ordinary exact-engine `migrate` run must skip the file without adding it to the migration ledger. Applying it is a separate governed cutover: `WAREHOUSE_SCHEMA_MIGRATION_ENABLED=true`, the default connection must be exactly `warehouse_migrator`, all pinned identities and grants must resolve to the same database target, and the identity/routine portability rehearsal must have passed. Setting the flag on any other default connection fails closed. SQLite continues to apply the migration for the fast local application gate.

Until that cutover is completed, exact-engine warehouse schema-dependent tests are intentionally skipped; authorization and source-level unit contracts continue to run. Recovery verification accepts only this exact migration as either absent or applied and rejects every other migration-ledger difference.

## Decision and bounded outcome

Add one hospital-like replenishment and custody spine that connects an independently approved synthetic purchase order to central warehouse receipt, two-sided transfer, destination-depot acceptance, existing pharmacy consumption, linked return, and immutable correction:

```text
versioned supplier + medicine + central warehouse/depot
  -> PO drafted and submitted by procurement officer
  -> PO independently approved or rejected
  -> warehouse receipt against the exact approved PO version
  -> central lot enters AVAILABLE or QUARANTINED custody
  -> warehouse dispatch creates source OUT + IN_TRANSIT custody
  -> destination depot independently accepts or rejects custody
  -> accepted stock is eligible for the existing pharmacy workflow
  -> supplier/unit return or append-only correction when eligible
  -> warehouse and depot stock cards reconcile to one conserved quantity
```

V1 removes synthetic opening balance as the only replenishment source for newly received lots. Pre-existing synthetic opening lots remain truthful retained evidence and are not silently reclassified as purchased stock. The slice does not create an accounts-payable liability, accounting journal, supplier delivery, bank obligation, claim, patient charge, or proof that any live stock exists.

## Exact PAR mapping and canonical-consolidation caveats

- `PAR-ADM-017` provides a versioned supplier master. `PAR-ADM-030` provides the central warehouse and dispensing-depot destination versions used by custody events.
- `PAR-PWH-017` provides the bounded purchase-order lifecycle.
- `PAR-PWH-002` (`Obat Masuk`) and `PAR-PWH-012` (`Obat Masuk 2`) remain separate parity rows. V1 may use one canonical receipt engine, but no consolidation, retirement, or equivalence is claimed until their fields, states, outputs, and exclusions are authoritatively mapped.
- `PAR-PWH-003` (`Distribusi Obat`) and `PAR-PWH-016` (`Distribusi Antar Depo`) may share one two-sided custody engine. They remain separately traceable source rows until an owner decides whether their operational meanings are equivalent, overlapping, or distinct.
- `PAR-PWH-005` is a supplier return linked to one retained receipt and lot. `PAR-PWH-006` is a unit/depot return linked to one retained transfer and custody chain.
- `PAR-PWH-011` is the immutable warehouse stock card derived from movements, not a separately editable balance.
- `PAR-PWH-014` (`Edit Transaksi`) maps in V1 to an append-only, independently reviewed correction. Direct update or deletion of historical stock, receipt, transfer, or return evidence is prohibited. This is a proposed teaching-system safety replacement, not a legacy-parity claim.
- The existing `PAR-PWH-001` medicine/lot and `PAR-PHA-001/002/003/015/020` dispensing facts are prerequisites and integration surfaces, not newly accepted parity outcomes.

Stocktake and opening-stock-opname rows `PAR-PWH-004`, `PAR-PWH-015`, and `PAR-PWH-023` remain outside V1. `PAR-PWH-022` blood stock and `PAR-CLN-017` blood-bank compatibility remain a separate clinical-custody lifecycle and must never be implemented as ordinary medicine replenishment.

## Exact Indonesian roles, capabilities, and worklists

New exact roles are deliberately separated:

- Exact `procurement_officer` manages eligible supplier drafts, creates `DRAFT` purchase orders, submits them, and views their status. This role cannot approve its own or another purchase order, receive stock, dispatch stock, accept destination custody, or approve a correction.
- Exact `procurement_approver` approves or rejects a submitted purchase order and approves an eligible supplier return. This actor must differ from the purchase-order creator and supplier-return requester.
- Exact `warehouse_receiver` records physical synthetic receipt facts and variance against an approved purchase order. This role cannot create or approve the purchase order, dispatch received stock, accept destination custody, or correct retained evidence.
- Exact `warehouse_inventory_controller` manages central custody, proposes warehouse dispatch, records eligible unit/supplier returns, and requests reasoned corrections. This role cannot approve a purchase order, receive its own dispatch at the destination, or approve its own correction.
- Exact `warehouse_inventory_supervisor` independently approves or rejects an eligible append-only correction. This role cannot create the corrected source transaction or perform the compensating movement.
- Existing exact `pharmacy_inventory_controller` accepts or rejects destination-depot custody. This role cannot approve the source purchase order or dispatch the same transfer.
- Existing exact `pharmacist` and `pharmacy_technician` may consume accepted stock only through the already authorized prescription workflow and receive no procurement or warehouse mutation authority.
- Exact `admin` provisions roles, views audit evidence, and performs bounded reset/recovery duties. Administrator or system-administrator status is not a procurement, receiving, warehouse, pharmacy, or supervisor bypass.

Mixed-role accounts fail closed for independence checks. Purchase-order creator and approver must differ; source dispatcher and destination acceptor must differ; correction requester and correction approver must differ. Authority is checked before route-resource lookup and repeated inside the domain service before protected relationships are loaded. Unauthorized users receive no resource-existence disclosure.

Proposed exact capability identifiers are:

- `warehouse.supplier.view`
- `warehouse.supplier.manage`
- `warehouse.purchase-order.view`
- `warehouse.purchase-order.create`
- `warehouse.purchase-order.submit`
- `warehouse.purchase-order.review`
- `warehouse.receipt.view`
- `warehouse.receipt.record`
- `warehouse.transfer.view`
- `warehouse.transfer.dispatch`
- `warehouse.transfer.accept`
- `warehouse.return.supplier`
- `warehouse.return.unit`
- `warehouse.stock-card.view`
- `warehouse.correction.request`
- `warehouse.correction.review`

Indonesian worklists and actions are `Master Pemasok`, `Pemesanan Obat`, `Ajukan PO`, `Persetujuan PO`, `Setujui PO`, `Tolak PO`, `Penerimaan Gudang Farmasi`, `Catat Penerimaan`, `Distribusi ke Depo`, `Kirim ke Depo`, `Penerimaan Depo`, `Terima Stok`, `Tolak & Kembalikan`, `Retur Pemasok`, `Retur Bagian`, `Kartu Stok Gudang`, and `Koreksi Transaksi Stok`.

## Master, unit, lot, and valuation contract

A supplier has a stable code, Indonesian name, optional synthetic contact/reference fields, Active/Retired state, and immutable versions. Duplicate active codes are forbidden. Retirement blocks new purchase orders but does not rewrite retained purchase-order, receipt, return, or stock evidence.

V1 reuses exact versioned medicine and depot facts. It adds one managed central warehouse destination and retains distinct RJ, IGD, and RI dispensing depots. Every purchase-order line and custody event snapshots the exact supplier, medicine, base issue unit, and relevant warehouse/depot versions. Quantities are positive whole base units; fractional packs, conversion, repacking, and compounding are outside V1.

Every received lot records a normalized lot code, received time, required expiry date, ordered quantity, accepted quantity, rejected/quarantined quantity, exact approved-PO unit acquisition value in integer whole rupiah, supplier reference, actor, and content digest. V1 does not accept a separately typed receipt value. Floating-point money is prohibited. Acquisition value is retained provenance only and creates no payable, journal, tax, bank, or accounting acceptance.

Expired stock cannot be received as `AVAILABLE`, dispatched, accepted into available destination stock, or consumed. A quarantined lot is never eligible for dispatch or pharmacy handover. V1 has no owner-approved cold-chain, narcotic, sterile-product, consignment, implant, or controlled-substance semantics.

## Purchase-order lifecycle and independent approval

```text
DRAFT -> SUBMITTED -> APPROVED -> PARTIALLY_RECEIVED -> FULLY_RECEIVED -> CLOSED
   |         |            |
   +---------+------------+-> CANCELLED or REJECTED where explicitly eligible
```

- Only the creating `procurement_officer` may revise their `DRAFT` purchase order.
- Submission appends an immutable submitted version and freezes supplier, medicine, unit, quantity, and integer-rupiah acquisition value for review.
- Exact `procurement_approver` may approve or reject the submitted fingerprint with an attributable decision. The creator can never review it, even if the account later gains another role.
- Receipt requires the exact current approved PO version. V1 refuses over-receipt; a quantity above the remaining approved quantity requires a replacement or separately authorized change to the purchase order, not a receiving edit.
- `PARTIALLY_RECEIVED`, `FULLY_RECEIVED`, and `CLOSED` are derived from retained receipt/return evidence. They are not manually editable labels.
- Cancellation is allowed only before any accepted or quarantined receipt evidence exists. A rejected PO cannot receive stock.

## Receipt, variance, and central custody

`warehouse_receiver` records one immutable receiving event against the exact approved PO and supplier reference. The event distinguishes ordered, physically presented, accepted, rejected, and quarantined whole-unit quantities. Every difference requires one closed reason code and bounded explanation.

Accepted quantity appends a positive `SUPPLIER_RECEIPT_AVAILABLE` movement into the central lot. Quantity requiring manual investigation appends `SUPPLIER_RECEIPT_QUARANTINED`; it is not available stock. Rejected quantity creates no owned stock. Receipt, lot, movements, PO projection, required audit, and technical operation receipt commit atomically.

Duplicate supplier reference plus supplier version is refused. Same-key replay cannot create another receipt or movement. A variance, missing expiry, retired supplier/medicine/destination, stale PO version, corrupt predecessor digest, or unauthorized actor fails closed without partial stock.

## Two-sided dispatch, transit, and destination acceptance

Dispatch never posts directly from source available quantity to destination available quantity. It appends a conserved two-sided transition:

```text
central AVAILABLE
  -- dispatch --> central OUT + IN_TRANSIT
  -- accept ----> IN_TRANSIT OUT + destination AVAILABLE
  -- reject ----> IN_TRANSIT OUT + source AVAILABLE or source QUARANTINED
```

The source OUT and matching IN_TRANSIT event commit atomically with one transfer digest. Destination acceptance or rejection requires the exact retained transfer fingerprint and commits its matching pair atomically. No one-sided movement is durable. In-transit quantity is not available to the source or destination pharmacy.

The `warehouse_inventory_controller` dispatches using deterministic FEFO over eligible source lots. The existing `pharmacy_inventory_controller` independently confirms destination, medicine, lot, expiry, quantity, intact synthetic custody, and accept/reject result. Partial destination acceptance is outside V1; the exact transfer is accepted in full or rejected in full with a reason. Rejection returns stock to source `AVAILABLE` only when manually recorded as intact and eligible; otherwise it returns to source `QUARANTINED`.

Accepted stock becomes visible to the existing pharmacy stock card and may be consumed only by the existing FEFO preparation/handover workflow. Warehouse code cannot bypass prescription verification or create a medication charge-source event.

## Supplier returns, unit returns, and immutable correction

A supplier return under `PAR-PWH-005` must reference the exact retained receipt, supplier, medicine, lot, remaining owned quantity, and approved reason. It requires request by `warehouse_inventory_controller` and independent approval by `procurement_approver`. Approval appends a negative custody movement and immutable supplier-return evidence; it does not create a credit note, payable adjustment, cash flow, or accounting journal.

A unit/depot return under `PAR-PWH-006` must reference the exact accepted transfer and remaining destination quantity. The destination proposes the return; warehouse custody independently accepts it. Each leg uses the same paired `IN_TRANSIT` conservation model. A return never reparents or edits the original transfer.

Historical supplier, PO, receipt, lot, transfer, acceptance, rejection, return, and stock-movement evidence is never updated, deleted, hidden, renumbered, or overwritten. `PAR-PWH-014` correction is implemented as an immutable request plus independent supervisor decision and, when approved, one linked compensating movement set. The original remains visible. Correction quantity cannot exceed the uncorrected retained quantity, cannot make any custody bucket negative, and cannot change supplier, medicine, unit, lot, or destination identity. Material identity errors require rejection/return and a new valid operation.

## Quantity conservation and reconciliation

For every medicine version, lot, and custody chain:

```text
retained source inflow quantity
  = retained synthetic opening quantity
  + accepted supplier-receipt quantity
  + signed quantity from approved compensating corrections

and must equal
  central available
  + central quarantined
  + in transit
  + destination available
  + destination quarantined
  + net pharmacy-issued quantity from retained handover/return movements
  + quantity returned to supplier or otherwise terminally dispositioned
```

Internal dispatch, acceptance, rejection, and unit-return legs only move quantity between custody buckets and therefore never change retained source inflow. Supplier return and pharmacy handover move quantity out of current custody but remain attributable terms in the conservation equation. A compensating correction contributes only its signed, independently approved quantity and can never be used as an unexplained balancing plug.

Every stock card is derived only from immutable movements. The guarded current lot balance is a projection and must equal opening/receipt movements plus all paired transfer, pharmacy, return, and approved correction movements. Control totals include approved PO quantity, received available/quarantined/rejected quantity, outstanding PO quantity, dispatched quantity, in-transit quantity, accepted/rejected-returned quantity, supplier/unit returns, correction quantity, pharmacy consumption, and current custody by lot and location.

No negative custody bucket, duplicate movement, duplicate receipt, duplicate return, over-receipt, over-return, one-sided transfer, expired issue, quarantined issue, or unexplained quantity loss is permitted. A mismatch is a hard integrity failure that blocks further mutation for the affected chain; it is never presented as an informational warning.

## Idempotency, audit, locking, and concurrency

Every mutation requires an actor-scoped normalized idempotency key, canonical payload SHA-256, optional correlation ID, and integrity-checked immutable operation receipt. Same key and same canonical payload recomputes retained evidence and returns the same result. Same key with changed payload fails as `idempotency_key_conflict`. A replay never duplicates a PO decision, receipt, movement pair, acceptance, return, correction, or audit.

Required domain evidence, paired movements, projections, operation receipt, and audit commit in one transaction. Audit failure rolls back every new row and balance/projection change. A sibling or predecessor audit cannot satisfy the selected operation; recovery binds each success audit to exact actor, operation, public identifiers, sequence, content digest, and before/after control totals.

Stock-mutating operations extend the existing pharmacy lock hierarchy rather than inventing an incompatible order:

```text
sorted inventory mutexes by (location code, medicine code)
  -> purchase order / receipt coordination when applicable
  -> source and destination depot versions in stable code order
  -> eligible lots ordered by expiry-null-last, expiry, received time, lot code, row ID
  -> transfer / return / correction heads in stable public-ID order
  -> immutable movements, required audit, and operation receipt
```

No warehouse operation may acquire an inventory mutex after an encounter or pharmacy prescription head. Dispatch-versus-pharmacy-handover, competing dispatches, competing receipts against one PO, accept-versus-reject, return-versus-dispense, correction-versus-return, and reset/recovery-versus-operation have only serializable durable outcomes. Concurrency may cause one command to wait, replay, or fail stale; it must never overspend stock, over-receive a PO, accept and reject the same transfer, create two correction decisions, or leave one side of custody missing.

## Indonesian UI and accessibility contract

Worklists show supplier, PO/receipt/transfer number, source, destination, medicine, base unit, lot, expiry, ordered/remaining quantity, custody quantity, actor, timestamp, reason, and textual state without relying on color alone. The source and destination views share one transfer reference and clearly distinguish `Dikirim — dalam perjalanan`, `Diterima depo`, and `Ditolak — kembali ke gudang`.

Every field has an accessible Indonesian label and help/error association. Worklists are semantic tables with useful empty, loading, stale, integrity-failure, and forbidden states. All controls are keyboard complete, have at least 44-pixel targets, use visible focus, move focus to the error summary after a failed submission, and announce committed state changes. Approval, dispatch, receipt, acceptance/rejection, return, and correction require deliberate confirmation that names the affected PO, lot, quantity, and location.

No UI may label a submitted PO as approved, dispatched stock as accepted, in-transit stock as available, a correction request as completed, or a supplier return as an accounting credit. No user enters a correction balance directly.

## Recovery, reset, rollback, and database guards

Recovery recomputes stable counts and digests for supplier/master versions, PO versions and decisions, receipts, lot bindings, transfer/custody pairs, returns, correction requests/decisions/compensations, projections, operation receipts, and uniquely bound audits. It verifies PO received totals, receipt-to-lot binding, movement-to-balance equality, source/transit/destination conservation, pharmacy-consumption linkage, no-negative stock, closed state domains, exact role separation, and absence of orphans. Every mismatch count must be zero.

Synthetic reset removes only the named synthetic warehouse fixture in dependency order: operation receipts and derived projections, correction/return/transfer events, receipt and PO children, then eligible warehouse masters after pharmacy references are removed by the existing coordinated reset. Required audit evidence is preserved. Reset never performs a broad delete, fabricates a balancing movement, or leaves a pharmacy lot referencing deleted custody evidence.

Migration rollback refuses while any supplier/warehouse business row, custody movement, operation receipt, or correlated audit remains. Portable database guards cover foreign keys, uniqueness, closed state/type/reason domains, positive whole quantities, non-negative guarded balances, exact paired-movement identifiers, immutable evidence update/delete refusal, governed mutable-projection writes, and PostgreSQL evidence-table truncation refusal. MySQL runtime roles must not obtain any user-settable trigger-bypass or session-reset escape.

## Verification and exact-engine evidence gate

Local completion requires focused documentation, authorization-before-lookup, role/separation, supplier/master, PO approval/rejection, partial/full receipt, variance/quarantine, duplicate reference, paired transfer, accept/reject, supplier/unit return, correction, stock-card, idempotency/conflict, audit rollback, recovery, reset, migration, SQL guard, HTTP, Indonesian UI, frontend interaction, keyboard, focus, status announcement, and 44-pixel-control tests.

One continuous local journey must demonstrate `E2E-11` supplier/PO/receipt/central custody/transfer/depot acceptance and then reuse accepted stock in the existing `E2E-10` pharmacy flow for at least one synthetic `E2E-02`, `E2E-03`, or `E2E-04` encounter. The test must reconcile source, transit, destination, pharmacy consumption, returns/corrections where exercised, and audit without creating an AP/accounting or patient-charge claim.

Exact PostgreSQL 17 and MySQL 8.4 rehearsal must cover fresh migration, failed-install guard reapply, empty down/reapply, constraints/triggers, shortened cross-engine identifier inventory, least-privilege grants, runtime bypass denial, real competing-receipt and dispatch-versus-dispense database waits, accept-versus-reject race, third-connection custody/control-total readback, audit-failure rollback, tamper recovery, bounded reset with audit preservation, populated rollback refusal, strict cleanup, and identical application/document/test source bindings. SQLite remains a fast application gate but does not substitute for either exact engine.

No evidence record may start as PASS. A later evidence template or record must begin `NOT RUN` and bind exact source hashes, commands, artifacts, engine versions, cleanup, and scenario results. Local engineering evidence does not become owner acceptance, hosted UAT, parity, G0, or G3 evidence by assertion.

## Explicit V1 exclusions

V1 excludes stocktake and opening stocktake (`PAR-PWH-004`, `PAR-PWH-015`, `PAR-PWH-023`); blood stock and transfusion compatibility (`PAR-PWH-022`, `PAR-CLN-017`, `E2E-07`); buffer/reorder planning and bulk warehouse reports; outside-patient replenishment; controlled temperature/cold chain; narcotic, psychotropic, chemotherapy, sterile, implant, consignment, recall, destruction authorization, and medication-administration custody; fractional quantity, unit conversion, repacking, and compounding; supplier portal, e-procurement, e-catalogue, invoice, three-way accounting match, tax, accounts payable/AP, credit note, payment, treasury, bank, general ledger, accounting journal, period close, budget encumbrance, claim, BPJS, VClaim, E-Klaim, iDRG, SATUSEHAT, patient charge, sale-price decision, revenue recognition, or financial acceptance; printer/scanner/device integration; production stock, real patient data, real staff data, real supplier data, real prices, or any other production warehouse/pharmacy data; secrets; live integrations or outbound delivery; commit, push, hosted migration, and deployment.

Those capabilities require separate owner decisions and evidence. This slice may be described as a bounded local implementation whose source is present and whose security corrections and SQLite gates are being completed. It must not be described as exact-engine verified, domain-accepted, pharmacy-approved, procurement-approved, warehouse-approved, finance-accounting accepted, route-complete, schema-enabled, deployed as a hosted capability, hosted-UAT accepted, production-ready, parity-complete, G0 closed, or G3 closed until the relevant work and gates actually occur.
