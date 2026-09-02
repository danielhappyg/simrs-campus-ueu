# Exact Cash Settlement and Receipt V1

Status: **locally authorized for bounded implementation and engineering verification**

Date: 2026-09-02

Primary coverage contribution: bounded continuation of `PAR-FIN-001` and `PAR-FIN-002`

Journey contribution: provisional local engineering evidence for `E2E-14`

## Decision and outcome

Release 1.0 may add one exact cash-settlement path for the current issued version of a synthetic encounter bill:

```text
reconciled immutable source events
  -> current issued bill version
  -> locked server-derived outstanding balance
  -> one exact CASH settlement
  -> immutable cashier receipt
```

This is the smallest bounded step that turns the existing cashier worklist, bill detail, and bill-version issuance into a usable cashier flow. It is not a generic payment engine. The amount is never entered by the cashier and is never recomputed from current tariffs. It is the exact locked outstanding balance in integer rupiah: the current cumulative bill-version net amount minus every earlier retained settlement on the same bill.

A later cumulative bill version may therefore collect only newly outstanding value, never the already-paid prefix. If version 1 for Rp14,000 is settled and version 2 later totals Rp19,000, the only eligible V2 settlement is Rp5,000. A zero outstanding balance is not payable. A negative balance fails closed as `refund_required`; V1 does not invent a refund or erase earlier evidence.

The standing project authorization permits this local implementation under `APP_MODE=SIMULATION` and `APP_SYNTHETIC_ONLY=true`. The ordinary cashier screens should use normal Indonesian hospital workflow language; the technical data boundary remains enforced by middleware, configuration, database constraints, tests, and operational evidence rather than a distracting banner on the patient-facing receipt.

This record authorizes local implementation and verification only. It does not close G0 or G3 and does not confer facility or finance-owner acceptance, authorize real data, activate a live integration, or authorize commit, push, hosted migration, or deployment.

## Settlement eligibility

A settlement may be created only when all of the following remain true inside one transaction:

1. the exact cashier actor is authorized before protected resource disclosure;
2. the encounter and bill head are locked in the canonical order;
3. the bill state is `ISSUED_CURRENT` and its current version is positive;
4. the selected immutable bill version is the bill head's current version;
5. the bill version, source-set digest, and client-confirmed settlement fingerprint still match;
6. source synchronization finds no new, unresolved, duplicated, or corrupt source;
7. the encounter is not cancelled;
8. the current version net amount minus earlier retained settlements is a positive integer rupiah value; and
9. no retained settlement already owns that bill version.

An `OPEN_NO_VERSION` bill, `NEW_SOURCE_PENDING` bill, zero-value version, stale page, unresolved source, changed source set, cancelled encounter, duplicate attempt with different payload, or audit outage fails atomically without a settlement or receipt.

## Immutable settlement and receipt

`finance_cash_settlements` is append-only business evidence. One row records one exact outstanding-balance settlement for one immutable bill version. It snapshots the settlement number, receipt number, bill and bill-version identities, version number, source-set and bill-version content digests, encounter and patient public identifiers needed for traceability, tender `CASH`, exact amount, immutable cashier display name, immutable coverage profile/label/exclusion, paid time, and canonical content digest.

The patient-facing receipt is an integrity-checked projection of this retained row, its technical operation receipt, all earlier settlements, and the referenced immutable bill version. It shows the receipt and bill numbers, bill version, service episode, patient display identity, exact paid amount, cash tender, snapshotted cashier attribution, covered source domains, and an explicit warning that services outside this bill version are not declared paid. It never reads a mutable current tariff to reconstruct the amount. It also never reads a mutable current tariff or current user display name to reconstruct historical evidence. Missing or corrupt linkage fails closed rather than rendering a green paid receipt.

`finance_settlement_operation_receipts` is separate technical replay evidence. It binds actor, operation, idempotency key, canonical request digest, and retained settlement result. It is not the patient-facing receipt number.

Successful settlement, business receipt, replay receipt, and required audit are atomic. Audit failure rolls back all business mutation. Settlement rows, technical receipts, and historical bill versions cannot be updated, deleted, truncated, backdated, or overwritten by ordinary runtime code or direct SQL.

## Idempotency and concurrency

The normalized idempotency key is unique per actor and operation. Same-key/same-payload replay returns the same settlement and receipt without another payment. Same-key/different-payload fails as a conflict before mutation.

Canonical lock direction is:

```text
encounter
  -> bill head
  -> current immutable bill version
  -> ordered source evidence
  -> settlement row
  -> required audit and technical replay receipt
```

Competing processes for the same bill version may yield one applied result and one exact replay/reconciliation result, but never two settlements. A third connection must prove the durable single-row result in exact-engine rehearsal.

## Actor and access boundary

- Exact `cashier` may view eligible bills, create an exact cash settlement, and view its receipt.
- Exact `rmik` may receive read-only trace access only if separately represented in the role-capability matrix; it cannot settle.
- Exact `finance_steward` manages tariff facts and cannot settle merely because it manages prices.
- Exact `admin`, system-administrator flags, and mixed-role accounts do not bypass the cashier boundary.
- Clinical, registration, pharmacy, laboratory, radiology, and inpatient actors receive no settlement authority.

New capabilities are `finance.settlement.view`, `finance.settlement.create`, and `finance.receipt.view`. Controllers authorize before route-resource lookup, and the service repeats authorization before loading protected relationships.

## Recovery, reset, and verification

Recovery must reconcile exactly one settlement per bill version, all snapshotted identities/digests, the equation `current_version_net - prior_settlements = exact_settlement_amount`, cashier attribution, operation receipt, and correlated audit evidence. Synthetic reset deletes settlement technical receipts and settlements before dependent bill versions and source evidence while preserving required audit evidence. Migration rollback refuses while settlement or correlated audit evidence remains.

Local completion requires focused authorization, behavior, denial, idempotency, stale-state, audit-rollback, recovery, reset, guard, HTTP, receipt-projection, frontend interaction, and accessibility tests. Exact-engine completion later requires disposable PostgreSQL 17 and MySQL 8.4 evidence for database checks, append-only guards, least-privilege grants, a real concurrent wait, single-settlement reconciliation, rollback refusal, reset, and strict cleanup.

## Explicit V1 exclusions

V1 does not include partial payment, overpayment or change calculation, split tender, card, bank transfer, QR payment, deposit, guarantee, receivable, installment, discount entry, tax entry, refund, void, settlement reversal, cashier shift closing, treasury deposit, accounting journal, revenue report, claim, BPJS, VClaim, Antrol, Aplicares, E-Klaim, iDRG, SATUSEHAT, bank endpoint, ERP, printer integration, real patient data, commit, push, hosted migration, or deployment.

Those are not silently approximated. Refund/void and partial-payment workflows require separate append-only compensation and receivable decisions before implementation.

## Local completion boundary

When the focused and full local gates pass, this slice may be described as **implemented and verified locally**. It must not be described as deployed, hosted-UAT accepted, production-ready, facility-approved, parity-complete, G0 closed, or G3 closed until those separate facts actually exist.
