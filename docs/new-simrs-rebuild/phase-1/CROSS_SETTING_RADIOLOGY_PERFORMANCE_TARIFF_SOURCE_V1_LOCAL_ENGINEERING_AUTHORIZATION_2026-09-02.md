# Cross-Setting Radiology Performance Tariff Source V1

Status: **locally authorized for bounded implementation and engineering verification**
Date: 2026-09-02
Primary coverage contribution: bounded radiology-source extension of `PAR-FIN-001` and `PAR-FIN-002`
Upstream dependencies: `PAR-ADM-015`, `PAR-ADM-011`, `PAR-ADM-018`, and `PAR-ADM-019`
Journey contribution: bounded radiology charge-source admission for `E2E-14`

## Decision and outcome

Release 1.0 may add one local, synthetic-only financial-source adapter that values a completed radiology examination from a deliberately entered governed tariff:

```text
exact radiology master version + care setting
  -> effective-dated finance-steward binding
  -> one immutable radiology performance at performed_at
  -> governed tariff version effective on that service date
  -> one immutable typed radiology source event
  -> existing versioned encounter-bill admission
```

The sole V1 charge trigger is the immutable `radiology_performances.performed_at` fact. Ordering, report Draft, report verification, verified amendment, and acknowledgement are not charge triggers. One performance may produce at most one positive charge source.

Clinical safety and continuity take precedence over financial configuration. A missing, future, retired, mismatched, or corrupt tariff binding never blocks radiology order creation, performance recording, report work, amendment, acknowledgement, encounter care, or clinical/RMIK closure. It appears as an unresolved cashier-readiness gap. Synchronization may admit individually valid sources, but bill issuance fails closed while any radiology performance visible at the issue cutoff remains unresolved.

This record authorizes local synthetic teaching implementation and engineering verification only. It does not confer radiology-owner, finance-owner, cashier, clinical, RMIK, or parity acceptance; hosted readiness; G0/G3 closure; production authority; or authority to use real patient, radiology, or financial data. No commit, push, hosted migration, or deployment is authorized by this record. Owner and parity acceptance remain open.

## Exact bounded scope

- Consume existing cross-setting radiology orders and their one-to-one immutable performance facts for `OUTPATIENT`, `EMERGENCY`, and `INPATIENT` encounters.
- Add an explicit, effective-dated finance binding from one exact radiology master version and digest plus care setting to one governed tariff item.
- Resolve the governed tariff item version at the performance service date and snapshot the exact valuation into an immutable typed source event.
- Extend cashier readiness, source synchronization, and versioned bill issuance without changing clinical workflow authority.
- Preserve the existing pharmacy source adapter and all already issued pharmacy-only bill versions.
- Start empty. This slice seeds no binding, tariff, amount, price, source event, or demonstration charge.
- Add no live integration and perform no external write.

## Preconditions and upstream truth

This adapter depends on, and does not redefine:

1. the existing radiology contract in which an exact `radiology_technologist` appends one immutable performance to an `ORDERED` radiology order;
2. the governed tariff/component master in which an exact `finance_steward` deliberately enters positive integer-rupiah amounts and effective dates; and
3. the versioned encounter bill in which admitted source rows and issued bill versions are immutable and later sources create a new issuable version.

`reference_label`, radiology examination code, tariff code, display name, preparation text, report text, terminology label, or a coincidental string match is never a binding. No label/code inference is permitted. A missing explicit binding is an unresolved gap, not a zero-valued charge and not permission to guess a tariff.

## Options considered and rejected alternatives

| Option | Benefit | Risk or tradeoff | Decision |
|---|---|---|---|
| Match radiology and tariff codes or labels | Minimal schema | Silent mispricing after rename, reuse, or coincidental match | Rejected |
| Charge when the physician orders | Early visibility | Bills work that may be cancelled or never performed | Rejected |
| Charge when a report is verified or acknowledged | Uses a later clinical milestone | Delays valuation and makes report amendment/acknowledgement look monetary | Rejected |
| Write the charge inside the technologist performance transaction | Immediate source row | A finance outage or tariff gap can block clinical care and creates a reverse clinical-to-finance lock edge | Rejected |
| Let an incomplete bill issue with an unresolved radiology gap | Avoids cashier delay | The issued version can falsely appear complete | Rejected |
| Roll back all valid synchronization when one performance is unresolved | Strong all-or-nothing import | Repeats valid work and hides reconcilable progress | Rejected |
| Store only an untyped polymorphic source reference | Fewer columns | Database cannot prove the admitted radiology source type | Rejected |
| Revalue an old performance from the current tariff | Simple query | Retroactive repricing and non-reproducible bills | Rejected |
| Create a reversal when a report is amended | Provides a correction-looking event | No clinical performance-void fact exists and report correction is not service cancellation | Rejected |
| Seed plausible prices or a default binding | Faster demonstration | Invented financial facts | Rejected |
| Implement laboratory and accommodation in the same slice | More visible coverage | Laboratory milestone and accommodation interval/proration rules remain unresolved | Rejected |

The selected design accepts that a valid source can synchronize before every gap is resolved. That progress is honest only because the readiness projection remains explicit and issuance is fail-closed at its cutoff.

## Explicit effective-dated binding contract

The finance-owned binding identity is the tuple:

```text
(radiology_master_version_public_id, radiology_master_content_digest, care_setting)
```

It points to one stable governed tariff-item identity. It never points by free text.

Proposed tables are:

- `finance_radiology_tariff_bindings`: governed mutable head with stable public ID, explicit foreign key to `radiology_examination_masters`, exact master-version public ID/version/content-digest snapshots, exact master-code snapshot, care setting, latest authored version, latest effective date, state, and current content digest;
- `finance_radiology_tariff_binding_versions`: append-only versions with explicit binding and `finance_tariff_items` foreign keys, tariff-item public-ID/code snapshots, state, `effective_from`, reason, actor, previous digest, and content digest; and
- `finance_radiology_tariff_operation_receipts`: append-only actor/operation/idempotency-key receipts with payload and retained-result digests.

The head records the latest authored binding version, which may be future-dated. Resolution independently selects the applicable version for the service date. Each non-retired binding version applies on the half-open interval `[effective_from, next_effective_from)`. A terminal `RETIRED` version makes the binding unavailable from its own effective date while preserving every earlier interval. Effective dates must be strictly increasing; retroactive rewrite, overlap, ambiguous selection, deletion, reopening, and reuse of a retired binding are forbidden.

A radiology master revision creates a new clinical master version and digest. It does not inherit the old binding implicitly. The exact new master version and each applicable care setting require deliberate finance-steward binding. Existing orders retain their original master-version snapshot and continue using its historical binding.

Binding creation or revision requires that:

- the retained radiology master version exists and its recomputed content digest exactly matches the submitted snapshot;
- care setting is exactly one of `OUTPATIENT`, `EMERGENCY`, or `INPATIENT`;
- the tariff item exists and resolves to service domain `RADIOLOGY` for the proposed effective interval;
- the tariff version care setting equals the binding care setting;
- the governed catalogue, cost component, and component group provenance remains internally reconcilable; and
- no current or future binding interval becomes ambiguous.

Retiring or revising a tariff, catalogue, component, group, radiology master, or binding never rewrites an already materialized source event or issued bill version. Historical version references remain readable.

## Performance trigger and source snapshot

Only one retained `radiology_performances` row linked to one retained `radiology_orders` row is eligible. The adapter validates the one-to-one performance constraint, encounter/patient binding, synthetic-patient boundary, care setting, exact order master version/digest, order lifecycle, and retained evidence before valuation.

The service date is the application-timezone calendar date of `performed_at`. Tariff and binding selection use that date, never order date, synchronization date, report date, bill-issue date, current date, or latest-authored head state.

The proposed append-only `finance_radiology_source_events` row has explicit foreign keys to:

- the exact `radiology_performances` row, unique so one performance creates at most one source;
- its retained `radiology_orders` row;
- the selected `finance_radiology_tariff_binding_versions` row; and
- the selected `finance_tariff_item_versions` row.

It snapshots at least source public ID/type, performance public ID and `performed_at`, order public ID, encounter and patient identities, care setting, radiology master version/public ID/code/content digest, binding version/public ID/content digest, tariff item/version public IDs and code, tariff content digest, component public ID/code/content digest, service date, quantity `1`, positive integer-rupiah unit amount, equal positive signed amount, description, creation/import actor and time, and canonical content digest.

Floating-point money, zero price, negative charge, quantity other than one, current-tariff substitution, missing retained evidence, and cross-patient/cross-encounter/cross-setting binding fail closed. The source snapshot is immutable after creation.

Report Drafts, verification, verified amendments, and acknowledgements do not create, replace, update, reverse, or duplicate a financial source. V1 has no reversal. A later reversal requires a separately authorized, append-only radiology performance-void or performance-correction fact and must reverse the original snapshotted amount rather than re-resolving a current tariff.

## Finance-source admission and typed foreign key

`finance_charge_events` is extended from a pharmacy-only admission shape to a closed typed-source union:

- the existing pharmacy foreign key becomes nullable;
- a nullable unique `finance_radiology_source_event_id` explicitly references `finance_radiology_source_events`; and
- a database check requires exactly one typed source foreign key and requires it to match the closed `source_domain` and `source_table` pair.

The generic source domain/table/public-ID snapshot remains useful for ordering and evidence, but it never substitutes for the typed foreign key. Radiology cannot masquerade as pharmacy. Unsupported domain/table/type combinations are rejected by service and database guards.

`FinanceRadiologySourceAdapter` validates and materializes eligible radiology source rows. `FinanceSourceCoordinator` composes it with the existing `FinancePharmacySourceAdapter`, dispatches retained-evidence verification by the closed source domain, and returns both admitted events and unresolved gaps. Individual adapters may return zero rows; only the coordinator decides whether the encounter has no valued source at all.

`FinanceBillService` uses the coordinator instead of depending directly on the pharmacy adapter. Existing cashier mutation routes remain the admission boundary:

- `finance.sources.synchronize` may persist every individually valid source visible in its transaction snapshot even when another radiology performance is unresolved; and
- `finance.bills.issue` must refuse issuance while any performed radiology fact visible at the issue cutoff is unresolved, corrupt, or not represented by exactly one valid source snapshot.

No radiology controller or clinical route writes finance rows. In particular, `radiology.orders.perform`, report, amendment, and acknowledgement routes remain clinically available without any finance-steward binding or active tariff.

## Readiness projection and cutoff semantics

`FinanceSourceReadinessProjection` extends the cashier encounter detail with a deterministic `Kesiapan Sumber Biaya` view. For each visible performed radiology order it reports a non-monetary identity, service time, and one closed readiness state:

- `SIAP_DISINKRONKAN`: exact binding and effective tariff resolve, but no source has yet been materialized;
- `TERSINKRONISASI`: one retained source and one admitted finance charge reconcile;
- `TARIF_BELUM_DIPETAKAN`: no exact effective binding exists;
- `TARIF_TIDAK_EFEKTIF`: the bound tariff has no active version for the service date;
- `KONTEKS_TIDAK_COCOK`: care setting, service domain, master digest, or typed binding does not match; or
- `BUKTI_TIDAK_KONSISTEN`: retained source or digest reconciliation fails.

The projection does not invent an amount for unresolved states. Exact `cashier` and exact `finance_steward` may view the readiness information necessary for their own duties. Clinical actors do not gain tariff or bill authority.

For issuance, the cutoff is the stable database snapshot taken inside the governed finance transaction after the encounter and bill head are locked. Every radiology performance visible in that snapshot with `performed_at` on or before the snapshot boundary must be resolved and represented exactly once. A performance committed after the snapshot is excluded from that version and becomes a later pending source; it never mutates the issued version.

An issuance denial records gap counts and closed reason codes, not clinical indication, report text, patient name, or unbounded protected payloads. Resolving a gap later permits a new synchronization and then a new immutable bill version.

## Actor and least-privilege boundary

- Exact `finance_steward` (`Pengelola Tarif`) may view readiness relevant to mapping and create, append, future-date, or retire radiology-tariff bindings. This role cannot order, perform, report, amend, acknowledge, synchronize, or issue a bill.
- Exact `cashier` may view effective bindings and cashier readiness, synchronize individually valid sources, and issue only when cutoff readiness is complete. This role cannot create, revise, future-date, or retire a binding and cannot mutate radiology evidence.
- Exact `radiology_technologist` remains the only V1 performance writer and receives no tariff, source-import, or bill-issue authority.
- Exact `physician` and exact `radiologist` retain their existing clinical authorities and receive no tariff, source-import, or bill-issue authority.
- Exact `rmik` retains read-only clinical completeness/bill-version traceability and receives no price-management or bill-issue authority.
- Exact `admin` may provision or revoke exact roles and run bounded reset/audit duties, but cannot act as finance steward, cashier, technologist, physician, or radiologist.
- A system-administrator flag or mixed-role account does not bypass any exact-role boundary.

Controllers check capability before route-resource lookup. Services repeat exact-role checks before protected relationship access. Unauthorized actors receive no binding, tariff, performance, encounter, patient, source-gap, or bill existence disclosure.

New mapping capabilities are bounded to `finance.radiology-tariff.view` and `finance.radiology-tariff.manage`. Existing finance source synchronization and bill-issue capabilities remain unchanged. Runtime database credentials receive only the least privileges required by governed services; schema mutation, trigger management, direct immutable-row mutation, and synthetic reset remain outside ordinary runtime authority.

## Proposed classes, routes, and bounded modifications

New finance models and support classes:

- `FinanceRadiologyTariffBinding`, `FinanceRadiologyTariffBindingVersion`, and `FinanceRadiologyTariffOperationReceipt`;
- `FinanceRadiologySourceEvent`;
- `FinanceRadiologyTariffBindingService`, actor policy, projection, canonical digest, mutation scope, and append-only/head/SQL guards;
- `FinanceRadiologySourceAdapter`; and
- `FinanceSourceCoordinator` plus `FinanceSourceReadinessProjection`.

Bounded existing modifications are limited to finance admission and operational integrity surfaces: `FinanceChargeEvent`, `FinanceBillService`, `FinanceBillVersion` coverage constants, finance projection/controller composition, provider guard registration, audit schema registry, synthetic reset, recovery snapshot, and the finance migration/guard suite. Radiology order, performance, report, amendment, and acknowledgement services remain unchanged.

Finance-steward mapping routes sit under the existing `Manajemen Data > Tarif & Komponen Biaya` boundary:

- `GET /manajemen-data/tarif-komponen-biaya/pemetaan-radiologi`;
- `POST /manajemen-data/tarif-komponen-biaya/pemetaan-radiologi`;
- `PATCH /manajemen-data/tarif-komponen-biaya/pemetaan-radiologi/{binding}`; and
- `POST /manajemen-data/tarif-komponen-biaya/pemetaan-radiologi/{binding}/nonaktifkan`.

No new cashier mutation route is needed. The existing encounter bill detail, source synchronization, and issue routes expose readiness and enforce the new rules. No route is added to perform an automatic clinical-to-finance write.

## Transactions, idempotency, and concurrency

Binding create/revise/retire requires a normalized idempotency key, canonical payload SHA-256, reason, actor, expected version and content digest where applicable, server time, required audit, and immutable receipt. Same-key/same-payload returns the retained result. Changed-payload key reuse, stale expected state, digest drift, interval overlap, master-version mismatch, and mutation after retirement fail atomically.

Finance synchronization and issuance retain the existing encounter-scoped receipt contract. Source materialization additionally relies on the unique performance foreign key and exact canonical source digest. A repeated synchronization either reconciles the retained row byte-for-byte or fails with an integrity refusal; it never inserts a duplicate.

The deterministic finance lock order is:

```text
encounter
  -> bill head
  -> finance radiology binding heads in stable identity order
  -> selected immutable binding and tariff versions
  -> visible radiology performance/order evidence in stable source order
  -> radiology source rows
  -> finance charge events
  -> latest bill version and lines
  -> audit and operation receipt
```

Finance reads immutable radiology evidence and never acquires a reverse clinical mutation lock. The radiology workflow never locks or writes finance tables. Concurrent same-performance synchronization produces one durable source and one replay/reconciliation result. Concurrent binding writers produce one applied result and one replay or stale denial. A tariff or binding revision racing with source resolution yields either the complete prior snapshot or the complete successor snapshot according to service-date and transaction visibility; it never mixes identities or amounts.

## Audit contract

Required closed audit events cover binding create, revise, future activation, retirement, replay, stale denial, authorization denial, source synchronization, unresolved-gap issue denial, source-integrity refusal, and successful bill issuance. Audit metadata uses stable public IDs, operation, state/version, effective date, care setting, closed reason, replay marker, source/gap counts, and content digests. It excludes clinical indication, findings, impression, amendment text, patient name, and arbitrary payloads.

Required success-audit failure rolls back its business mutation. Denial audit failure does not convert a denial into success and is surfaced as an audit availability failure under the existing governed pattern.

Closed denial reasons include at least `role_not_permitted`, `resource_not_found`, `validation_failed`, `stale_version`, `stale_digest`, `idempotency_key_conflict`, `binding_retired`, `effective_date_not_after_latest`, `retroactive_effective_date`, `master_version_mismatch`, `tariff_not_effective`, `source_binding_invalid`, `unresolved_radiology_source`, `source_integrity_failure`, `receipt_corrupt`, and `concurrent_state_conflict`.

## Database guards, reset, recovery, and rollback

- Binding heads are writable only through the governed binding mutation scope.
- Binding versions, binding receipts, and radiology source events are append-only. Ordinary update/delete/truncate and direct SQL writes are refused.
- The finance charge-event check and typed foreign keys reject untyped, dual-typed, or domain/table-mismatched rows.
- Bounded synthetic reset removes bill lines, bill versions, finance receipts, bill heads, charge events, radiology source events, binding receipts, binding versions, and binding heads in dependency order while preserving required audit evidence.
- Post-reset checks require all new business tables and typed finance references to be empty while correlated audit evidence remains attributable.
- Recovery includes stable counts and table digests; binding head/latest-authored reconciliation; half-open overlap and duplicate checks; master-version/digest, tariff-version, source, encounter, patient, and typed-FK orphan checks; one-performance/one-source reconciliation; source snapshot recomputation; readiness-gap recomputation; bill-line/source and per-version arithmetic; receipt reconciliation; audit correlation; and reset/audit preservation.
- Migration rollback refuses while any binding, version, receipt, radiology source, typed finance charge, bill line/version depending on it, or correlated audit fact remains.

Reset and recovery competition must produce no partial source, orphan charge, dual-typed source, orphan line, split binding head, lost audit, or issue version with an unresolved in-cutoff performance.

## User experience contract

- `Manajemen Data > Tarif & Komponen Biaya > Pemetaan Radiologi` shows exact radiology master version, digest indicator, care setting, tariff item, binding effective interval, state, and immutable history.
- The empty state says no radiology tariff mapping has been deliberately configured and supplies no suggested code, price, or amount.
- Cashier detail shows `Kesiapan Sumber Biaya`, resolved and unresolved counts, closed readiness labels, service time, and a clear issuance blocker when any in-cutoff performance is unresolved.
- `Sinkronkan sumber valid` may synchronize individually valid sources. It never changes or dismisses unresolved rows.
- `Terbitkan Versi Tagihan` remains disabled or is denied server-side until the latest authoritative readiness check is complete; client state is never trusted.
- The issued version states its exact admitted coverage profile. Existing pharmacy-only versions keep their historical coverage label; a new mixed-domain version uses a new explicit pharmacy-and-radiology coverage label.
- Status is communicated by text as well as colour; error summaries receive focus; changes use an accessible live region; controls have at least 44-pixel targets; tables retain semantic headers and keyboard access.
- No user-facing simulation or synthetic-data wording is added.

## Verification gate

Local completion requires:

1. binding migration/model/service tests for exact master-version/digest and care-setting identity, half-open resolution, future-authored versions, retirement, replay/conflict, stale state, overlap denial, no inference, and historical selection;
2. source-adapter tests proving order alone creates no charge, one performance creates exactly one source, report/verification/amendment/acknowledgement create no duplicate, all three care settings resolve exactly, and unbound/inactive/future/mismatched/corrupt inputs remain gaps;
3. bill tests for radiology-only and pharmacy-plus-radiology source sets, valid partial synchronization, issue refusal with any unresolved in-cutoff performance, later gap resolution, immutable next-version issue, deterministic ordering/digests, old coverage preservation, and unchanged pharmacy reversal arithmetic;
4. exact-role and authorization-before-lookup tests for finance steward, cashier, admin, technologist, physician, radiologist, RMIK, mixed-role, provisioning, and revocation;
5. audit-contract, required-audit rollback, SQL/head/append-only guard, fresh migration, empty down/reapply, retained-evidence rollback-refusal, reset, post-reset emptiness, recovery, and corruption tests;
6. two-process PostgreSQL/MySQL tests for same-performance import, competing binding versions, binding/tariff revision versus source resolution, performance-before/after issue snapshot, unresolved-gap issue refusal, and reset/recovery competition;
7. frontend tests for Indonesian labels, deliberate mapping, no suggested price, readiness states, partial synchronization, issue blocker, semantic currency, keyboard behavior, error focus, live status, and 44-pixel targets;
8. full PHP, PHPStan, Pint, frontend test/type/lint/format/build, Ruby documentation, and diff gates; and
9. disposable PostgreSQL 17 and MySQL 8.4 evidence with exact versions recorded, least-privilege runtime grants, real races, database checks/triggers, source reconciliation, reset/audit preservation, rollback refusal, and strict cleanup.

Exact-engine evidence must start truthfully as not run. SQLite feature results do not substitute for PostgreSQL 17 and MySQL 8.4 behavior. A passing engineering harness proves only the bounded technical contract. It does not prove that any entered tariff is a real or approved hospital price.

## Explicit exclusions

- seeded/default/inferred prices, default bindings, real hospital tariffs, real patient/radiology/financial data, secrets, or external write;
- charge on order, report, verification, amendment, or acknowledgement;
- performance void, reversal, refund, discount, package, professional-fee split, tax, guarantee, deposit, receipt, payment allocation, receivable, settlement, journal, revenue recognition, or manual charge;
- laboratory charge admission, pathology, microbiology, accommodation/bed-day calculation, procedure charges, or registration charges;
- PACS, RIS, DICOM, modality worklist, device output, LIS, terminology server, insurer, claim, INA-CBG/iDRG, BPJS, VClaim, SATUSEHAT, bank, payment gateway, fiscal device, or other live integration;
- alteration of radiology clinical lifecycle, clinical closure criteria, or existing pharmacy handover/return facts;
- finance-owner, cashier, radiology-owner, clinical, RMIK, or parity acceptance; hosted readiness; production readiness; G0/G3 closure; commit; push; deployment; or hosted migration.

## Consequences and next graph boundary

This slice converts one already-authorized clinical completion fact into an attributable monetary source without allowing finance configuration to control clinical care. The cost is an explicit mapping workload and a deliberate cashier issuance blocker when a performed study remains unresolved.

After exact-engine evidence is green, the next source domain must be separately authorized from its own lifecycle facts. Laboratory must first decide whether specimen acceptance or another immutable fact is the charge trigger and how rejected/recollected attempts behave. Accommodation must first define immutable occupancy intervals, day boundaries, transfer allocation, proration, correction, discharge, and reversal semantics. No future adapter may inherit radiology rules by analogy alone.
