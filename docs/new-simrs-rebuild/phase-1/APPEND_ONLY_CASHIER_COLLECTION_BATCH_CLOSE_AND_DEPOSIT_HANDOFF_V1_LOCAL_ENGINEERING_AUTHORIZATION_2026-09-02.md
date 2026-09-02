# Append-only Cashier Collection Batch Close and Deposit Handoff V1

Status: **product-owner locally authorized for bounded implementation and engineering verification**

Date: 2026-09-02

Primary coverage contribution: bounded implementation contribution to `PAR-FIN-012` (`Setoran`)

Journey contribution: provisional local engineering evidence for the settlement/deposit-handoff segment of `E2E-14`

## Authority and boundary

Under the standing product-owner instruction, Release 1.0 may locally implement and verify the exact bounded slice in this record. The application remains in `APP_MODE=SIMULATION` with synthetic-only data, no secrets, and no live integration. Commit, push, hosted migration, and deployment remain outside this authorization.

This is product-owner local engineering authorization, not cashier/revenue, finance-accounting, treasury, facility, domain, or parity acceptance. It does not decide or claim `PAR-FIN-015` (`TERIMA SETORAN`), close G0 or G3, appoint a real-hospital authority, authorize real patient or payment data, or establish that these exact semantics were observed in SIMRS Sahabat.

This record does not close G0 or G3.

The ordinary screens use concise Indonesian hospital language. The technical synthetic-data boundary remains enforced by middleware, configuration, database constraints, tests, and evidence rather than a distracting persistent banner on operational screens or printable handoff proof.

## Decision

Add one cashier-owned cash-collection batch that binds future exact cash settlements at creation, freezes a deterministic membership set at close request, supports append-only recount evidence, requires independent cashier-supervisor verification, and produces one internal deposit-handoff proof:

```text
OPEN
  -> exact CASH settlements and completed full refunds bind to the open batch
  -> CLOSE_REQUESTED freezes membership, cutoff, expected net cash and counted cash
     -> RECOUNT_SUBMITTED, zero or more times, while counted cash differs
     -> CLOSE_VERIFIED by an independent cashier supervisor when variance is zero
        -> DEPOSIT_HANDOFF_CREATED by the owning cashier for the verified exact amount
```

Derived worklist states are `OPEN`, `RECOUNT_REQUIRED`, `AWAITING_SUPERVISOR`, `VERIFIED`, and `HANDED_OFF`. No state may be backdated, overwritten, deleted, reopened, or skipped. A recount is not a correction to historical evidence; it is a new append-only observation against the same frozen expected amount and membership digest.

`DEPOSIT_HANDOFF_CREATED` means that the owning cashier recorded an internal handoff of the verified collection batch. It must never be labelled `Setoran Diterima`, `Diterima Treasury`, reconciled to a bank, posted to accounting, or treated as treasury acceptance. `PAR-FIN-015` remains a separate dependent slice with a different actor and lifecycle.

## Evidence model and immutability

The bounded model comprises:

- an immutable cashier-collection batch header with public identifier, batch number, cashier identity/name snapshot, opening timestamp, and content digest;
- a unique active-batch coordination slot per cashier, treated as mutable coordination state rather than financial evidence;
- one immutable membership row created atomically for each new exact cash settlement, binding batch, settlement, receipt, amount, settlement digest, cashier, and collection timestamp;
- an append-only ordered batch event stream for close request, recount, and supervisor verification;
- one immutable deposit-handoff row after verification, binding the verified batch state and amount; and
- actor-scoped technical operation receipts for idempotent replay.

The existing settlement, patient-facing receipt, correction case, refund events, and their audit evidence are never updated to simulate membership or handoff. Pre-migration settlements remain truthful legacy evidence and are not silently adopted into a new batch. New settlements created after this slice is active require exactly one current `OPEN` batch for the exact cashier and acquire their membership row in the same transaction as settlement, receipt, technical replay receipt, and audit.

The active coordination slot may be released only when `CLOSE_REQUESTED` commits, allowing a cashier to open a later batch while the frozen predecessor awaits recount, verification, or handoff. The frozen predecessor's membership never changes.

## Exact batch equation

The system computes integer-rupiah expected net cash from retained immutable evidence. No actor enters or edits the expected amount or handoff amount:

```text
expected batch net cash
  = sum(amount of all retained exact CASH settlements bound to the frozen batch)
  - sum(original settlement amounts in that batch whose correction reached REFUND_COMPLETED before freeze)
```

`REFUND_APPROVED` does not reduce expected cash. A replacement settlement contributes as a distinct retained settlement only when it was created within the same still-open batch. The membership count, ordered settlement public identifiers and content digests, completed-refund event identifiers and digests, cutoff, gross collected amount, completed-refund amount, expected net amount, latest counted amount, variance, and canonical membership digest are frozen or derived for every close projection.

The cashier manually records `Kas Fisik Terhitung` as an observed synthetic cash count. This is not an invented tariff or editable financial source. A non-zero variance yields `RECOUNT_REQUIRED`; it cannot be supervisor-verified or handed off. `CLOSE_VERIFIED` requires the latest counted amount to equal the unchanged expected net amount exactly. `DEPOSIT_HANDOFF_CREATED` uses the verified server-derived amount and never accepts a separately entered amount.

## Roles, capabilities, and separation of duties

Exact `cashier` may view only their own batches and handoffs, open a batch, request close, submit a recount, and create the handoff only after independent verification. Exact `cashier_supervisor` may view every cashier batch and handoff and verify an eligible frozen batch, but cannot open, close, recount, or create a handoff as the cashier.

The cashier who owns a batch may never verify it, even if the account later gains another role. Mixed-role accounts fail closed. Exact `finance_steward` remains a tariff steward and receives no batch-close, verification, handoff, or treasury authority. Exact `admin`, system-administrator flags, clinical, registration, RMIK, pharmacy, laboratory, radiology, inpatient, inventory, and other accounts receive no mutation authority.

New exact capability identifiers are:

- `finance.cashier-collection.view`
- `finance.cashier-collection.open`
- `finance.cashier-collection.close-request`
- `finance.cashier-collection.recount`
- `finance.cashier-collection.verify`
- `finance.cash-deposit-handoff.view`
- `finance.cash-deposit-handoff.create`

Authorization occurs before route-resource lookup and is repeated inside every service before protected relationships are loaded. A cashier ownership check follows authorization without disclosing another cashier's protected batch.

## Eligibility and lifecycle controls

Opening succeeds only for an exact cashier with no current active coordination slot. Server time establishes the opening timestamp; the actor cannot backdate it. Opening does not accept an opening float, petty cash, manual receipt, or off-ledger collection.

Exact settlement creation requires the actor's locked active `OPEN` batch before the existing encounter, bill, immutable bill-version, settlement, correction, operation-receipt, and audit checks. Missing, frozen, corrupt, or foreign-owned batch state refuses settlement atomically.

Close request succeeds only when the batch header, active slot, ordered membership rows, settlements, patient-facing/technical receipts, correction cases/events, and correlated success audits all reconcile. A `CORRECTION_REQUESTED` or `REFUND_APPROVED` case blocks close. A `REVIEW_REJECTED` case leaves the original amount included. A `REFUND_COMPLETED` case subtracts the exact original amount. Close request freezes membership and releases the active slot atomically; no late settlement or refund may enter the frozen batch.

Recount succeeds only for the owning cashier after close request and before verification. Verification succeeds only for an exact, separate cashier supervisor, zero variance, unchanged batch fingerprint, exact ordered evidence, and no prior verification. Handoff creation succeeds only for the owning cashier after verification, against the verified fingerprint, with no prior handoff. Double close, recount after verification, same-actor verification, duplicate verification, double handoff, stale fingerprint, corrupt evidence, missing audit, or changed membership fails atomically.

Once close request freezes a batch, new correction requests and refund completion for its member settlements fail closed. After handoff, they use reason `cash_handoff_exists`. Post-freeze or post-handoff refund/reversal requires a separately authorized treasury-adjustment lifecycle; V1 never changes or deletes the handoff to hide that dependency.

## Idempotency, audit, and concurrency

Closed operations are `FINANCE_CASHIER_COLLECTION_OPEN`, `FINANCE_CASHIER_COLLECTION_CLOSE_REQUEST`, `FINANCE_CASHIER_COLLECTION_RECOUNT`, `FINANCE_CASHIER_COLLECTION_VERIFY`, and `FINANCE_CASH_DEPOSIT_HANDOFF_CREATE`. Every operation uses an actor-scoped normalized idempotency key, canonical payload digest, and integrity-checked result receipt. Same key and same payload returns the same retained result; same key with changed payload fails as `idempotency_key_conflict`.

Required audit, batch evidence, membership, event, handoff, and technical operation receipt commit in the same transaction; audit failure rolls back every new row and coordination-slot change. Audit success metadata uniquely binds operation, batch public identifier and digest, event or handoff public identifier and digest when present, actor, state, expected net amount, and evidence count. A success audit for another batch cannot satisfy recovery for the selected batch.

For affected mutations, the cashier active-slot and collection-batch coordination lock precede the existing canonical finance lock chain:

```text
cashier active slot / collection batch
  -> encounter
  -> bill head
  -> immutable bill version
  -> ordered settlements and correction events
  -> ordered batch membership
  -> ordered batch events
  -> deposit handoff
  -> required audit and operation receipt
```

A settlement-versus-close race has only two acceptable durable outcomes: settlement commits first and belongs to the frozen batch, or close freezes first and settlement is refused until a new batch exists. A refund-completion-versus-close race similarly includes the completed refund before freeze or freezes first and refuses the refund. Competing close, verify, recount, or handoff commands may replay or fail closed, but never create two active slots, omit a committed member, alter frozen membership, verify non-zero variance, or create two handoffs.

## Indonesian UI and printable proof

Cashier navigation uses `Batch Penerimaan Kas`; actions use `Buka Batch`, `Ajukan Tutup Batch`, `Catat Hitung Ulang`, and `Serahkan Setoran`. Supervisor navigation uses `Verifikasi Tutup Batch`. The worklists show cashier, batch number, open/freeze time, receipt count, gross collection, completed refund, expected net cash, latest counted cash, variance, and derived state without exposing another cashier's patient detail.

The printable `Bukti Penyerahan Setoran` shows batch number, owning cashier, supervisor verifier, membership count, gross cash, completed refund, exact net cash, counted cash, zero variance, freeze/verify/handoff timestamps, and integrity reference. It must state `Menunggu penerimaan treasury` and must not claim treasury receipt, bank reconciliation, journal posting, or revenue recognition. Missing or corrupt linkage fails closed instead of rendering a successful proof.

## Recovery, reset, rollback, and verification

Recovery must reconcile one active slot at most per cashier, exactly one membership per post-activation settlement, ordered batch/event chains, unique event sequences, immutable identities and digests, exact cashier ownership, supervisor separation, settlement/refund membership, the batch equation, zero-variance verification, one handoff at most, operation receipts, and uniquely bound audits. Healthy reconciliation requires every mismatch count to be zero; deleting or tampering one member, refund, event, handoff, or audit cannot be masked by sibling evidence.

Synthetic reset removes collection operation receipts, handoffs, batch events, membership rows, active slots, and batch headers before settlement/correction and bill parents while preserving required audit evidence. Migration rollback refuses while any batch, member, event, handoff, technical receipt, active slot, correlated audit, or post-activation settlement binding remains. Database triggers and SQL-write guards prohibit ordinary update, delete, truncate, backdating, and user-settable reset-bypass escalation.

Local completion requires focused authorization, ownership, separation, lifecycle, equation, mismatch/recount, idempotency, stale-state, audit-rollback, recovery, reset, migration, SQL-guard, HTTP, Indonesian UI, printable-proof, frontend interaction, and accessibility tests. One continuous local `E2E-14` journey must cover an issued immutable bill, exact cash settlement, correction outcome where applicable, batch freeze, supervisor verification, and deposit handoff without duplicate posting.

Exact PostgreSQL 17 and MySQL 8.4 rehearsal must cover fresh migration, failed-install guard reinstall, constraints/triggers, shortened cross-engine identifier inventory, least-privilege grants, runtime reset-bypass denial, owner-only bounded reset, a real settlement-versus-close database wait, a real refund-versus-close database wait, double-close/verify/handoff refusal, third-connection membership and net-cash readback, audit failure rollback, recovery tamper detection, populated rollback refusal, strict cleanup, and identical source bindings.

Local evidence does not become G3 evidence by assertion. A create-only successor coverage publication may bind current exact evidence only after implementation and verification. Hosted role-based UAT, reconciliation, recovery, security, accessibility, performance, defect closure, named cashier/revenue and finance-accounting/treasury-boundary acceptance, parity acceptance, current deployed SHA evidence, and G0 remain separate prerequisites.

## Explicit V1 exclusions

V1 excludes `PAR-FIN-015` treasury acceptance, bank deposit or reconciliation, bank account master, payment gateway, cash drawer opening float, denomination counting, petty cash, manual/off-ledger receipt, partial payment, partial refund, split tender, overpayment/change, card, bank transfer, QR payment, deposit received, guarantee, receivable, installment, write-off, discount/tax entry, post-handoff refund or reversal, closed accounting period, treasury adjustment, accounting journal, revenue recognition/report, medical-fee allocation, claim, BPJS, VClaim, Antrol, Aplicares, E-Klaim, iDRG, SATUSEHAT, bank endpoint, ERP, printer integration, real patient or payment data, secrets, commit, push, hosted migration, and deployment.

Those capabilities require separate decisions and evidence. This slice may be described as locally authorized now and, only after all gates pass, implemented and verified locally. It must not be described as treasury-accepted, bank-reconciled, journal-posted, revenue-recognized, deployed, hosted-UAT accepted, production-ready, facility-approved, domain-accepted, parity-complete, G0 closed, or G3 closed.
