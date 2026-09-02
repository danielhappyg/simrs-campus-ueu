# Cross-Setting Laboratory Verified-Result Tariff Source V1

Status: **locally authorized for bounded implementation and engineering verification; application and exact-engine evidence not yet implemented**

Date: 2026-09-02

Primary coverage contribution: bounded extension of `PAR-CLN-006`, `PAR-FIN-001`, and `PAR-FIN-002`

Upstream dependencies: `PAR-ADM-011`, `PAR-ADM-018`, and `PAR-ADM-019`

Journey contribution: provisional engineering evidence for `E2E-05` and `E2E-16`

## Decision and outcome

Release 1.0 may add one local financial-source adapter that values a completed laboratory examination from a deliberately entered governed tariff:

```text
exact laboratory master version + care setting
  -> effective-dated finance-steward binding
  -> original immutable VERIFIED result at verified_at
  -> governed LABORATORY tariff version effective on that service date
  -> one immutable typed laboratory source event
  -> existing versioned encounter-bill admission
```

The sole V1 completed-service and charge trigger is the original immutable `laboratory_result_versions` row whose state is `VERIFIED` and whose `base_verified_version_id` is `NULL`. Its `verified_at` timestamp supplies the service date. Specimen collection, receipt, acceptance, rejection, recollection, result `DRAFT`, `AMENDED_VERIFIED`, critical communication, acknowledgement, and pre-verification cancellation are not charge triggers.

One laboratory order may produce at most one positive source, tied to the original verified result. A later verified amendment or acknowledgement never creates, replaces, updates, reverses, or duplicates that source. V1 has no reversal. A future cancellation or correction with financial effect requires a separately authorized immutable service-void fact and must reverse the original snapshotted amount rather than resolve the then-current tariff.

Clinical continuity takes precedence over financial configuration. Missing, future, retired, mismatched, or corrupt mappings never block ordering, specimen work, result verification, amendment, critical communication, acknowledgement, encounter care, or RMIK closure. They remain explicit cashier-readiness gaps. Individually valid sources may synchronize, but bill issuance fails closed while any original verified result visible at the issue cutoff is unresolved.

This record authorizes only bounded local implementation and engineering verification under `APP_MODE=SIMULATION` and `APP_SYNTHETIC_ONLY=true`. It does not confer laboratory-owner, finance-owner, cashier, clinical, RMIK, or parity acceptance; hosted readiness; deployment authority; G0/G3 closure; or permission to use real patient, laboratory, or financial data. It authorizes no commit, push, hosted migration, or deployment.

## Exact bounded scope

- Consume retained cross-setting laboratory orders and their original verified results for `OUTPATIENT`, `EMERGENCY`, and `INPATIENT` encounters.
- Add an explicit effective-dated binding from one exact laboratory master version and care setting to one governed tariff item whose service domain is `LABORATORY`.
- Resolve valuation on the application-timezone calendar date of the original `verified_at` timestamp.
- Snapshot one positive integer-rupiah amount into an immutable typed laboratory source event and one typed finance charge.
- Extend the closed finance source coordinator, readiness projection, immutable bill admission, recovery, reset, audit, and database guards without changing laboratory workflow authority.
- Preserve pharmacy and radiology source evidence and every issued historical bill version.
- Start empty: seed no mapping, tariff, amount, source, or demonstration charge.
- Add no LIS, analyser, payer, bank, insurer, or other external write.

## Explicit binding contract

The finance-owned binding identity is:

```text
(laboratory_master_version_public_id, laboratory_master_content_digest, care_setting)
```

Free-text, code, label, specimen name, component name, result value, or reference-range matching is never a binding. A revised laboratory master version does not inherit its predecessor's mapping.

The bounded schema is expected to add:

- `finance_laboratory_tariff_bindings`: governed mutable heads with stable identity, exact laboratory-master/version/digest snapshots, care setting, latest authored version/effective date, state, and current digest;
- `finance_laboratory_tariff_binding_versions`: append-only versions pointing to an exact governed tariff item, with effective date, state, reason, actor, previous digest, and content digest;
- `finance_laboratory_tariff_operation_receipts`: append-only operation/idempotency receipts; and
- `finance_laboratory_source_events`: append-only typed source snapshots.

Each non-retired binding version applies on the half-open interval `[effective_from, next_effective_from)`. A terminal `RETIRED` version closes the mapping from its own effective date. Effective dates are strictly increasing. Retroactive rewrite, overlap, ambiguous selection, deletion, reopening, or reuse after retirement is forbidden.

The selected tariff version must have service domain `LABORATORY`, the same care setting, a positive integer-rupiah amount, and intact catalogue/component/group provenance. Current-tariff substitution is forbidden. Historical master, mapping, tariff, component, source, and bill versions remain readable and immutable.

## Original VERIFIED trigger and source snapshot

An eligible result must be the one original retained result version for its order:

- state exactly `VERIFIED`;
- `base_verified_version_id` exactly `NULL`;
- non-null immutable `verified_at`;
- linked to the same retained accepted specimen attempt, order, encounter, patient, care setting, and exact laboratory-master snapshot; and
- backed by a valid retained digest chain and required critical-result communication where applicable.

The service date is the application-timezone calendar date of original `verified_at`, never order time, collection time, specimen-acceptance time, amendment time, acknowledgement time, synchronization time, bill issue time, current date, or latest-authored head state.

`finance_laboratory_source_events` must hold explicit foreign keys to the original verified result, its laboratory order, accepted specimen attempt, selected binding version, and selected tariff version. Both the original verified-result foreign key and laboratory-order foreign key are unique so the database proves one source per completed order.

The source snapshots source identity/type, original verified-result identity/time/content digest, order/encounter/patient/care-setting identities, exact laboratory master version/code/digest, accepted specimen identity, binding identity/version/digest, tariff identity/version/code/digest, component identity/code/digest, service date, quantity `1`, positive integer-rupiah unit and signed amounts, a non-clinical description, import actor/time, and canonical digest. It must not copy result values, reference ranges, critical flags, clinical questions, communication notes, or other unnecessary clinical content into finance evidence.

## Typed finance admission and readiness

`finance_charge_events` becomes a closed typed-source union across pharmacy, radiology, and laboratory. Exactly one typed source foreign key must be non-null and must agree with its closed `source_domain`/`source_table` pair. Laboratory cannot masquerade as pharmacy or radiology.

`FinanceLaboratorySourceAdapter` may materialize only an eligible original verified result. `FinanceSourceCoordinator` composes the laboratory adapter with the existing pharmacy and radiology adapters and verifies each retained source by its closed type. No laboratory controller or workflow service writes a finance row.

Cashier readiness uses the existing `Kesiapan Sumber Biaya` states: `SIAP_DISINKRONKAN`, `TERSINKRONISASI`, `TARIF_BELUM_DIPETAKAN`, `TARIF_TIDAK_EFEKTIF`, `KONTEKS_TIDAK_COCOK`, and `BUKTI_TIDAK_KONSISTEN`. Unresolved states expose no invented amount and no result content.

Synchronization may retain every individually valid source in its transaction snapshot. Bill issuance must refuse while any original verified result visible at the cutoff lacks exactly one reconcilable source and charge. A verification committed after the stable cutoff is excluded from that version and becomes a later pending source; it never mutates an issued version.

## Actors and least privilege

- Exact `finance_steward` (`Pengelola Tarif`) may view and manage laboratory-tariff bindings but cannot perform laboratory work, synchronize sources, or issue bills.
- Exact `cashier` may view mappings/readiness, synchronize valid sources, and issue a complete bill but cannot manage bindings or laboratory evidence.
- Exact `laboratory_technologist` retains laboratory workflow authority and receives no tariff, synchronization, or issue authority.
- Exact `physician`, `nurse`, `rmik`, and `admin` receive no laboratory-tariff management or cashier mutation authority.
- A system-administrator flag or mixed-role identity never bypasses an exact-role boundary.

New capabilities are `finance.laboratory-tariff.view` and `finance.laboratory-tariff.manage`. Controllers authorize before route-resource lookup; services repeat exact-role checks before protected relationship access. Runtime database grants use a closed table map. Ordinary runtime has no wildcard, DDL, trigger, user-administration, reset, payment, claim, or external-integration authority.

## Transactions, idempotency, and concurrency

Binding create/revise/retire requires a normalized idempotency key, canonical payload SHA-256, reason, actor, expected version and digest where applicable, required audit, and immutable receipt. Same-key/same-payload returns the retained result. Changed-payload key reuse, stale version/digest, ambiguous interval, and mutation after retirement fail atomically.

The lock direction is encounter-first. Laboratory verification never writes a finance row, so it cannot introduce a reverse clinical-to-finance lock edge. The finance path reads immutable laboratory evidence after acquiring its governed finance locks.

Required exact-engine races are:

1. Two independent synchronizers for the same original verified result produce one `MATERIALIZED` and one `RECONCILED` outcome, one durable source, and one typed charge.
2. Two binding writers with the same expected head/digest and distinct keys produce one `APPLIED` result and one closed stale/concurrent `DENIED` result.
3. Verification racing with synchronization produces either a complete pre-verification snapshot with no source or a complete post-verification snapshot with one source. It never produces a partial or duplicate source, and a later readiness read exposes pending work honestly.

Every race must observe a real database wait using independent application processes and finish with a third-connection durable reconciliation. Deadlock, mixed tariff identities, duplicate source, duplicate charge, or silent unresolved issuance is failure.

## Audit, integrity, reset, and recovery

Required audit covers binding create/revise/retire, replay, stale and authorization denial, source synchronization, unresolved issuance denial, corruption refusal, and successful issue. Required audit failure rolls back its business mutation.

Application and database guards refuse unauthorized direct writes, update/delete/truncate of append-only rows, invalid head transitions, typed-source mismatch, non-integer or non-positive charge values, and source/result/order/binding/tariff digest corruption.

Recovery must reconcile binding version chains and heads, upstream laboratory-master snapshots, receipts, original-result/order/specimen relationships, one-result/one-source/one-charge cardinality, source and charge digests, bill versions/lines, and retained audit evidence. Bounded reset may delete the new finance-laboratory graph in dependency order while preserving required audit evidence. Migration rollback refuses while retained source, charge, binding, receipt, or audit evidence exists.

## Required verification before exact engines

- Structural authorization and harness contracts pass.
- Focused laboratory, tariff, billing, authorization, guard, recovery, reset, and audit tests pass.
- The full local suite and static/format gates pass.
- The application implementation paths are stable and the harness reports no missing source bindings.
- Exact-engine evidence still starts as `NOT RUN` until both disposable PostgreSQL 17.10 and MySQL 8.4.11 rehearsals actually execute.

SQLite, syntax, documentation, mocked, inherited laboratory, inherited billing, or inherited tariff evidence does not substitute for the new exact-engine run. Each scenario requires its own worker, filtered-test, database, or observed-race proof. Source hashes are captured immediately before execution, checked again before publication, and any drift fails closed.

## Closed future exact-engine catalogue

The future harness has exactly 22 scenarios: fresh migration; empty down/reapply; exact roles; three care-setting bindings; original-verified-result service-date resolution; future/half-open/terminal retirement; upstream result-chain refusal; order/specimen/Draft/cancellation no-charge behavior; one original verified result/one typed source; amendment and acknowledgement no-extra-charge behavior; laboratory-only bill snapshot; partial synchronization and issue refusal; later gap resolution/new bill version; replay/key/retroactive/stale refusal; same-result import race; competing-binding race; verification/synchronization cutoff race; application guard; database guards; audit/corruption/reconciliation; least privilege; and reset/recovery/rollback/strict cleanup.

## Explicit exclusions

- real patient, laboratory, or financial data;
- inferred, seeded, default, or purportedly hospital-approved prices;
- automatic finance writes from laboratory workflow transactions;
- result-value-based pricing, component-level quantity pricing, panels split into multiple charges, discounts, tax, payment, accounting, receivables, or claims;
- a reversal without a separately authorized immutable service-void fact;
- LIS, analyser, barcode/label printer, middleware, BPJS, VClaim, SATUSEHAT, bank, payer, mail, or other live integration;
- accommodation/bed-day tariff calculation;
- hosted migration, browser UAT, owner/domain/parity acceptance, G0/G3 closure, commit, push, or deployment.

## Claim boundary

This authorization is not implementation evidence. The evidence template and status-only harness scaffold remain `NOT RUN`. Even a later truthful two-engine `PASS` proves only the exact bound local bytes on disposable engines. It does not prove a real hospital price, automatic-charge authority, clinical or finance acceptance, hosted readiness, deployment, capacity, production operations, parity acceptance, G0, or G3.
