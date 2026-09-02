# Append-only Cash Settlement Correction V1

Status: **locally authorized for bounded implementation and engineering verification**

Date: 2026-09-02

Primary coverage contribution: bounded correction continuation of `PAR-FIN-001` and `PAR-FIN-002`

Journey contribution: provisional local engineering evidence for the reversal path in `E2E-14`

## Context

Exact cash settlement can now collect and print the positive outstanding balance of an immutable bill version without double-charging a cumulative predecessor. A hospital cashier workflow is still unsafe if an erroneous completed receipt can only remain forever active. Deleting or editing that receipt would destroy financial evidence, while broadening immediately to partial, split, card, receivable, treasury, or accounting behavior would introduce unrelated policy.

V1 therefore adds one bounded, append-only full-cash correction lifecycle:

```text
active exact cash settlement
  -> cashier correction request
  -> independent cashier-supervisor review
     -> rejected (original remains active)
     -> refund approved
        -> full cash refund completed
           -> original remains immutable but no longer contributes to net collected cash
           -> exact replacement settlement may become eligible
```

This is a teaching-system safety decision, not a claim that the same approval or refund semantics were observed in SIMRS Sahabat. The ordinary workflow uses Indonesian hospital language without a persistent front-screen simulation banner. The existing application-mode, synthetic-data, no-secret, and no-live-integration controls remain mandatory.

This record authorizes local implementation and verification only. It does not close G0 or G3, confer finance/facility/domain acceptance, activate governance, authorize real data or live integrations, or authorize commit, push, hosted migration, or deployment.

## Decision

Choose an immutable correction case plus an append-only event stream. The original settlement and receipt are never updated, deleted, hidden, renumbered, or overwritten.

One correction case may exist for one settlement. Its request header snapshots the original settlement identity, receipt number, amount, settlement digest, bill/version identities, requesting cashier identity/name, standardized reason code, bounded explanation, request time, and canonical content digest. Review and refund transitions are separate append-only events linked by case identifier, monotonic sequence, previous-event digest, actor identity/name snapshot, event time, and canonical content digest.

The closed event vocabulary is:

- `REVIEW_REJECTED`: terminal; the original settlement remains fully active;
- `REFUND_APPROVED`: the exact full refund is due but does not yet reduce net collected cash; and
- `REFUND_COMPLETED`: terminal; the exact original cash amount was handed back and may reduce net collected cash.

No event may be backdated. No actor enters an amount. A rejected or completed case cannot be reopened, replaced, or mutated. A new correction attempt for the same original settlement is refused.

## Options considered

| Option | Outcome | Reason |
|---|---|---|
| Leave settlements irreversible | Rejected | An erroneous cash receipt would have no safe operational correction path. |
| Update/delete the original settlement or receipt | Rejected | This destroys historical evidence and breaks append-only recovery and audit. |
| Append-only request, dual-control review, and exact refund events | Selected | Preserves evidence, separates duties, and establishes a bounded net-cash correction. |
| Generalized partial/split/non-cash payment and receivables now | Deferred | Requires allocations, tender policy, overpayment/change, guarantee, aging, collection, and broader reversal decisions. |

## Roles and separation of duties

- Exact `cashier` may view their own settlements, request correction only for a settlement they created, and view the resulting bounded case and receipts.
- Exact `cashier_supervisor` is a new separated role that may view all correction cases, reject a request, approve a full refund, and record completion of the approved full cash refund.
- The requesting cashier may never review, approve, reject, or complete their own case, even if an account is later assigned another role.
- Exact `finance_steward` remains a tariff steward and receives no correction authority.
- Exact `admin`, system-administrator flags, mixed-role accounts, clinical users, RMIK, pharmacy, laboratory, radiology, registration, and inventory roles receive no correction authority.

New capabilities are `finance.settlement-correction.view`, `finance.settlement-correction.request`, `finance.settlement-correction.review`, `finance.settlement-refund.complete`, and `finance.settlement-correction.receipt.view`. Authorization occurs before route-resource lookup and is repeated inside the service before protected relationships are loaded.

## Eligibility and state transitions

A request succeeds only when the original settlement, bill, immutable bill version, operation receipt, and correlated success audit all reconcile; the settlement is `CASH/SETTLED`; no correction case already exists; and no downstream cash-handoff evidence owns the settlement. The request reason is one closed code plus a bounded Indonesian explanation. V1 reason codes are `WRONG_BILL`, `DUPLICATE_COLLECTION`, `CASHIER_INPUT_CONTEXT_ERROR`, and `OTHER_SUPERVISOR_REVIEW`.

Review locks the original settlement, correction case, and ordered event stream. Rejection records an explanation and is terminal. Refund approval binds the exact original amount and settlement digest. Refund completion is allowed only after approval, binds the approval event digest, and records the exact full cash amount as server-derived evidence. Same-actor request/review, approval after rejection, completion before approval, changed source evidence, duplicate transition, stale page, or corrupt digest fails atomically.

## Net cash and replacement settlement

Every service, projection, receipt, recovery check, handoff candidate, and future report must use one canonical equation:

```text
net collected for a bill version
  = sum(all retained exact cash settlements)
  - sum(original amounts whose correction case reached REFUND_COMPLETED)
```

`REFUND_APPROVED` alone does not reduce net collected cash because the money has not yet been recorded as handed back. A replacement exact settlement for the same immutable bill version becomes eligible only after `REFUND_COMPLETED`, and only for the positive outstanding amount derived from the canonical equation. The existing database uniqueness that assumes one lifetime settlement per bill version must be replaced by guarded concurrency plus exact recovery invariants; retained receipt numbers and settlement rows remain unique and immutable.

The original receipt remains printable with a prominent derived correction state and link to the immutable correction/refund evidence. It must never display an active green `Lunas` state after `REFUND_COMPLETED`. The separate refund receipt shows original receipt number, correction number, exact amount, reason, requester, approving supervisor, completion actor, and timestamps. Missing or corrupt linkage fails closed rather than rendering a successful refund.

## Idempotency, locks, and atomicity

Every request, review, approval, and refund-completion command has an actor-scoped idempotency receipt. Same key and same canonical payload replays the same retained result after integrity revalidation. Same key with changed payload fails as a conflict.

Canonical lock order is:

```text
encounter
  -> bill head
  -> immutable bill version
  -> ordered settlement rows
  -> correction case
  -> ordered correction events
  -> required audit and operation receipt
```

Competing correction or replacement-payment processes may produce one applied result and one replay/refusal, never two correction cases, two terminal review outcomes, two refund completions, or an over-collected replacement. Required audit, business evidence, and technical receipt commit atomically; audit failure rolls back every new row.

## Recovery, reset, and exact-engine verification

Recovery must reconcile case/event chains, prior-event digests, original settlement and bill/version snapshots, role separation, event order, exact refund amount, operation receipts, correlated audit, and the canonical net-cash equation. Synthetic reset removes correction operation receipts and events before cases and settlements, while preserving required audit evidence. Migration rollback refuses while any correction case, event, technical receipt, or correlated audit evidence remains.

Local completion requires focused authorization, behavior, denial, same-actor separation, idempotency, stale-state, replay-integrity, audit-rollback, replacement-payment, receipt-projection, recovery, reset, SQL guard, HTTP, frontend interaction, and accessibility tests. Exact PostgreSQL 17 and MySQL 8.4 rehearsal must cover migration failure guard reinstall, constraints/triggers, least privilege, a real competing-process wait, double-review/refund refusal, third-connection net-cash readback, rollback refusal, reset, and strict cleanup.

## Explicit V1 exclusions

V1 excludes partial refund, partial payment, split tender, overpayment/change, card, bank transfer, QR payment, deposit, guarantee, receivable, installment, discount/tax entry, cashier shift closing, treasury deposit/acceptance, closed accounting period, journal, revenue recognition/report, claim, BPJS, VClaim, Antrol, Aplicares, E-Klaim, iDRG, SATUSEHAT, bank endpoint, ERP, printer integration, real patient data, commit, push, hosted migration, and deployment.

Those capabilities require separate decisions and evidence. This slice may be described as implemented and verified locally only after all local and exact-engine gates pass. It must not be described as deployed, hosted-UAT accepted, production-ready, facility-approved, parity-complete, G0 closed, or G3 closed.
