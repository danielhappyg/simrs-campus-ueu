# Inpatient Accommodation Occupancy-Day Tariff Source V1

Status: **locally authorized for bounded implementation and engineering verification; application and exact-engine evidence not yet implemented**

Date: 2026-09-02

Primary coverage contribution: bounded accommodation-source extension of `PAR-REG-001`, `PAR-ADM-009`, `PAR-ADM-033`, `PAR-FIN-001`, and `PAR-FIN-002`

Upstream dependencies: managed ward/bed master, atomic inpatient location history, routine inpatient discharge, governed effective-dated finance tariffs, and the versioned encounter bill

Journey contribution: provisional engineering evidence for `E2E-14` and `E2E-16`

## Decision and outcome

Release 1.0 may add one local synthetic-only adapter that values completed inpatient accommodation from deliberately entered governed tariffs:

```text
prospectively snapshotted exact bed master version and digest
  -> explicit effective-dated finance-steward binding
  -> immutable half-open occupancy intervals from location history
  -> deterministic local-calendar occupancy-day allocation
  -> governed ACCOMMODATION tariff effective on each service date
  -> one immutable typed source per encounter and service date
  -> existing versioned encounter-bill admission
```

Only a **closed immutable occupancy interval** may yield accommodation sources. An `ADMISSION_LOCATION` event opens the first interval. Each later `BED_TRANSFER` closes the preceding interval at its `occurred_at` and opens the next interval at the same instant. The retained `inpatient_discharges.discharged_at` fact closes the final interval. Every interval is half-open `[start_at, end_at)`.

Admission alone, the mutable current-bed claim, wall-clock passage, a census read, a bill synchronization request, a discharge summary, and a cashier action are not charge triggers. The finance adapter runs after clinical facts commit. A finance gap never blocks registration, bed assignment, transfer, discharge documentation, routine discharge, clinical care, or RMIK work.

V1 uses one whole `OCCUPANCY_DAY` unit per occupied application-timezone calendar date. It does not prorate by hour or minute. One encounter can produce at most one positive accommodation source for a service date, so a same-day transfer never creates an extra day. Prices and bindings start empty and may be entered only by an exact finance steward; this record seeds or infers no amount.

This record authorizes bounded local implementation and engineering verification under `APP_MODE=SIMULATION` and `APP_SYNTHETIC_ONLY=true`. It does not confer facility, registration, inpatient-clinical, finance/cashier, RMIK, revenue, or parity acceptance; real-hospital day-count acceptance; hosted readiness; G0/G3 closure; or production authority. It authorizes no real data, live integration, commit, push, hosted migration, or deployment. Affected-owner and parity acceptance remain open.

## Evidence-driven precondition: prospective bed-version snapshots

Current `inpatient_location_events` retain ward/bed public IDs, codes, display labels, room label, service class, and a payload digest. They do **not** retain the exact `inpatient_bed_versions` public ID, integer version, and `after_digest` that were authoritative when the placement began. The current bed head may later change display name, room label, service class, state, or version.

Therefore V1 must prospectively extend every new admission/transfer location snapshot with exact `from` and `to` bed-version foreign keys, public IDs, version numbers, and content digests. Admission and transfer remain clinical/registration transactions and derive these values from the already locked bed master; they never query finance and never require a tariff.

No historical version is inferred from labels, codes, timestamps, current heads, or coincidental content. No backfill fabricates missing provenance. An existing episode whose interval lacks the prospective exact snapshot remains `RIWAYAT_LOKASI_TIDAK_LENGKAP`, produces no accommodation source, and blocks accommodation-complete bill issuance until a separately authorized reconciliation decision exists.

## Occupancy interval and service-day semantics

For an eligible encounter, retained events must form one contiguous ordered chain:

1. sequence begins at one with `ADMISSION_LOCATION` and its `occurred_at` equals the retained managed admission timestamp;
2. each `BED_TRANSFER` has the next sequence, its complete `from` snapshot equals the prior event's complete `to` snapshot, and its `occurred_at` is strictly later than the prior event;
3. each prospective `to` snapshot resolves to the exact retained bed version and digest;
4. no cancellation fact exists; and
5. exactly one routine discharge closes the final interval, with `discharged_at` strictly later than the last opening event and placement provenance matching the final location event.

The calendar allocation is deterministic:

- the admission date has one anchor at the admission instant;
- every later date has one anchor at application-timezone local midnight;
- an anchor belongs to the unique half-open occupancy interval containing it;
- a discharge instant is exclusive, so discharge exactly at midnight does not create a unit for the new date;
- admission and discharge on the same date still yield exactly one unit because the admission anchor lies inside a positive-duration interval; and
- transfer exactly at midnight assigns the new date to the destination interval, while any number of later transfers that date creates no additional day.

The source service date is the anchor's local date. Binding and tariff resolution use that date, never synchronization date, bill issue date, discharge-document date, current date, current bed head, or latest-authored tariff head.

The selected no-proration rule is deliberately narrow and reproducible. It does not claim that a real hospital uses this convention. Facility, finance, and revenue owners must accept or replace it before parity or production claims.

Calendar-day allocation is the deterministic local teaching V1 policy. It is not a claim of universally observed hospital practice or SIMRS Sahabat billing policy. Tariff amounts and bindings remain deliberately blank until an exact finance steward configures them; engineering must not seed, suggest, derive, or infer either one.

## Transfer, discharge, cancellation, and correction behavior

- A transfer is simultaneously the immutable close of one interval and open of the next. It creates no finance row in its clinical transaction.
- Completed intervals may be synchronized individually, but an active inpatient episode always has an open final interval. Bill issuance fails closed with `INTERVAL_MASIH_TERBUKA` until retained discharge closes it.
- Routine discharge is the sole V1 final-interval close. Finance configuration and finance availability are not discharge prerequisites.
- A retained preclinical `encounter_cancellations` fact creates no accommodation source. Existing cancellation policy permits no post-transfer cancellation; therefore V1 never turns a cancelled registration into a billable stay.
- A forward bed transfer is not a correction of prior occupancy. It preserves the earlier interval exactly.
- V1 defines no retroactive location correction, accommodation void, reversal, refund, negative day, manual day override, or reallocation. If the retained location/discharge chain is wrong or incomplete, readiness fails as `BUKTI_TIDAK_KONSISTEN`; finance does not repair clinical history.
- Any future correction with monetary effect requires a separately authorized append-only occupancy-correction or occupancy-void fact. A reversal must reference and negate the original snapshotted source amount, never re-resolve a current tariff.

## Options considered and rejected alternatives

| Option | Benefit | Risk or trade-off | Decision |
|---|---|---|---|
| Charge at admission | Immediate amount | Bills cancelled or never-completed occupancy and cannot know the final location chain | Rejected |
| Charge from the mutable current-bed claim or census | Simple query | Mutable state is not historical evidence and races with transfer/discharge | Rejected |
| Charge once per location interval | Easy cardinality | Transfer frequency changes price and a same-day move creates a duplicate day | Rejected |
| Continuously accrue from wall-clock time | Current estimate | Time is not an immutable domain fact and retries/cutoffs become non-reproducible | Rejected |
| Prorate every interval by seconds | Mathematically smooth | Invents a rounding and proration policy with no owner acceptance | Rejected |
| Assign the whole transfer date to the longest interval | Appears equitable | Requires comparing later events and makes results sensitive to future facts | Rejected |
| Create one charge for every bed touched on a date | Preserves every placement | Double-charges one service date after transfer | Rejected |
| Infer historical bed version from labels, code, or current head | Avoids schema change | Ambiguous provenance and silent historical mispricing | Rejected |
| Backfill an exact version for existing events | Enables more fixtures | Fabricates evidence the original transaction did not retain | Rejected |
| Allow issue while the final interval is open | Earlier bill version | Issued accommodation coverage can falsely appear complete | Rejected |
| Write finance rows inside transfer/discharge | Immediate source | Finance failure can block patient flow and creates reverse lock coupling | Rejected |
| Seed a default daily price or class mapping | Faster demonstration | Invented financial facts and unsafe automatic valuation | Rejected |

The chosen anchor rule trades intraday proration for a single deterministic daily identity. This is acceptable only inside the bounded synthetic teaching profile while owner acceptance remains open.

## Explicit effective-dated binding

The finance-owned binding identity is:

```text
(inpatient_bed_version_public_id, inpatient_bed_content_digest)
```

It is always for care setting `INPATIENT` and pricing unit `OCCUPANCY_DAY`. Ward code, bed code, display name, room label, service-class label, encounter class, payer label, or free text is never a binding. No label/code inference is permitted.

Proposed tables are:

- `finance_accommodation_tariff_bindings`: mutable governed heads tied to exact bed and bed-version foreign keys, snapshotted version/public ID/digest, ward/bed code, service class, state, latest authored version/effective date, and current digest;
- `finance_accommodation_tariff_binding_versions`: append-only versions tied to an exact governed tariff item, state, effective date, reason, actor, previous digest, and content digest;
- `finance_accommodation_tariff_operation_receipts`: append-only actor/operation/idempotency receipts; and
- `finance_accommodation_source_events`: immutable daily valuation snapshots.

Each non-retired binding version applies on `[effective_from, next_effective_from)`. A terminal `RETIRED` version closes it from its own date. Dates strictly increase. Retroactive rewrite, overlap, reopening, deletion, ambiguous resolution, and automatic inheritance by a revised bed version are forbidden.

The resolved tariff version must have service domain `ACCOMMODATION`, care setting `INPATIENT`, a positive integer-rupiah amount, and intact catalogue/component/group provenance. The daily unit amount is the exact governed tariff amount effective on the service date. Quantity is exactly `1`; signed amount equals unit amount. No multiplication by elapsed hours, payer class, ward label, or current head is allowed.

## Immutable typed source and finance admission

`finance_accommodation_source_events` has explicit foreign keys to:

- the inpatient encounter and patient;
- the interval-opening `inpatient_location_events` row;
- the exact `inpatient_bed_versions` row;
- the interval-closing transfer event or final `inpatient_discharges` row, with exactly one close type;
- the selected accommodation binding version;
- the selected governed tariff item version; and
- the importing actor.

A unique `(encounter_id, service_date)` constraint proves one source per inpatient day. The source snapshots the anchor time, interval start/end, opening/closing evidence identities and digests, exact ward/bed/version/service-class provenance, encounter/patient identities, binding/tariff/component provenance, service date, `OCCUPANCY_DAY`, quantity one, positive integer-rupiah amount, non-clinical description, import time/actor, and canonical content digest. It copies no diagnosis, clinical note, discharge summary text, transfer reason, patient name, or unnecessary protected content.

`finance_charge_events` extends its closed typed-source union with one nullable unique `finance_accommodation_source_event_id`. Exactly one pharmacy, radiology, laboratory, or accommodation foreign key is non-null and agrees with the closed source domain/table pair. A generic string reference never substitutes for the typed foreign key.

`FinanceAccommodationSourceAdapter` validates intervals, materializes eligible daily sources, and reconciles retained evidence. `FinanceSourceCoordinator` composes it without weakening pharmacy, radiology, or laboratory rules. No inpatient registration, transfer, discharge, ward/bed, or clinical controller writes finance rows.

## Readiness and bill cutoff

Cashier readiness adds accommodation rows without inventing an amount for gaps. Closed states include:

- `SIAP_DISINKRONKAN`: a closed day resolves to an exact effective tariff but has no source;
- `TERSINKRONISASI`: one source and typed charge reconcile;
- `INTERVAL_MASIH_TERBUKA`: the active final interval lacks discharge closure;
- `RIWAYAT_LOKASI_TIDAK_LENGKAP`: exact prospective bed-version provenance is absent;
- `TARIF_BELUM_DIPETAKAN`;
- `TARIF_TIDAK_EFEKTIF`;
- `KONTEKS_TIDAK_COCOK`; and
- `BUKTI_TIDAK_KONSISTEN`.

Synchronization may persist every individually valid closed service day. Bill issuance fails closed while any occupancy day visible at the stable issue cutoff is unresolved or duplicated, while any interval is open, or while the retained chain is incomplete/corrupt. A transfer or discharge committed after the database snapshot is excluded from that attempt; the next readiness read exposes it. Client readiness is never trusted.

An already issued pharmacy/radiology/laboratory bill remains immutable. Once accommodation becomes complete, a later version may add accommodation sources under a new explicit coverage profile. Historical source amounts never change after tariff, bed master, binding, or display revisions.

## Exact actors and least privilege

- Exact `finance_steward` (`Pengelola Tarif`) may view and manage accommodation bindings. It cannot register, assign, transfer, discharge, synchronize, or issue.
- Exact `cashier` may view accommodation readiness/effective bindings, synchronize valid sources, and issue only a complete bill. It cannot manage bindings or occupancy evidence.
- Exact `registrar` retains managed admission, transfer, and bounded cancellation authority and receives no tariff/source/bill authority.
- Exact `physician` retains routine discharge authority and receives no tariff/source/bill authority.
- Exact facility/bed-master manager retains ward/bed master authority and receives no finance-steward authority.
- Exact `rmik` may trace immutable bill and occupancy provenance but cannot manage prices, synchronize, or issue.
- Exact `admin` may provision exact roles and run bounded reset/audit duties but cannot act as another domain role.
- System-administrator flags and mixed-role accounts never bypass exact-role boundaries.

New capabilities are `finance.accommodation-tariff.view` and `finance.accommodation-tariff.manage`. Controllers authorize before route-resource lookup; services repeat exact-role checks before relationship access. Runtime database grants are explicit and least privilege, with no wildcard, DDL, trigger, reset, user-administration, claim, payment, or integration authority.

## Transactions, idempotency, and concurrency

Binding mutations require normalized idempotency key, canonical payload digest, reason, actor, expected version/digest where applicable, immutable receipt, and required audit. Same-key/same-payload replays the retained result. Changed payload, stale head, overlap, retroactive date, retired head, or digest drift fails atomically.

Source materialization is idempotent by `(encounter_id, service_date)` and canonical source digest. A retry returns `RECONCILED` only when the retained source and charge match byte-for-byte. Otherwise it refuses corruption; it never inserts a duplicate or silently revalues.

Canonical lock direction remains encounter-first:

```text
patient claim mutex and encounter
  -> bill head
  -> ordered location/discharge evidence
  -> exact ward/bed master heads and versions
  -> ordered accommodation binding heads and immutable versions
  -> tariff versions
  -> accommodation source and finance charge rows
  -> bill version/lines
  -> required audit and receipt
```

Clinical admission/transfer/discharge never locks finance tables. Finance reads retained clinical evidence after its encounter lock. Required exact-engine races include same-day source import, transfer versus synchronization, discharge versus issue cutoff, bed-version/binding revision versus resolution, competing binding writers, and reset/recovery competition. Each race uses independent processes, proves a real database wait, and performs a third-connection durable reconciliation.

## Audit, recovery, reset, and portability

Required audit covers binding create/revise/retire/replay/denial, source synchronization/reconciliation/refusal, incomplete-readiness issue denial, and successful bill issuance. Required audit failure rolls back its business mutation. Audit metadata contains bounded public IDs, dates, state, counts, digests, and reason codes—not patient identity, transfer reason, diagnosis, or clinical text.

Application, SQL, head, append-only, typed-union, and database guards refuse direct mutation, invalid version chains, update/delete/truncate of immutable evidence, duplicate day, invalid close type, non-positive/non-integer amount, and cross-encounter/patient/bed/binding/tariff mismatch.

Recovery reconciles location sequence and continuity, prospective bed-version snapshots, half-open intervals, discharge closure, cancellation exclusion, one-day/one-source/one-charge cardinality, binding heads/versions/receipts, tariff/component provenance, source/charge/bill digests, and audit evidence. Synthetic reset deletes accommodation charges, daily sources, binding receipts/versions/heads, then dependent clinical synthetic chains in declared order while preserving required audit evidence. Migration down refuses while retained source, charge, binding, receipt, or correlated audit evidence exists.

Exact-engine evidence starts as `NOT RUN`. Local completion requires focused behavior/role/idempotency/recovery/reset/guard tests, full PHP/static/frontend/Ruby gates, and disposable PostgreSQL 17 plus MySQL 8.4 proof of constraints, triggers, least-privilege grants, real waits/races, rollback refusal, reset, and strict cleanup. SQLite is not concurrency or exact-engine evidence.

## Proposed implementation surface

New models/support are expected to include `FinanceAccommodationTariffBinding`, `FinanceAccommodationTariffBindingVersion`, `FinanceAccommodationTariffOperationReceipt`, `FinanceAccommodationSourceEvent`, their binding service/projection/digest/policy/guards, and `FinanceAccommodationSourceAdapter`.

Bounded existing changes include prospective location-event provenance, finance source coordination/readiness, typed charge admission, bill coverage/reason codes, provider guard registration, authorization/audit registries, reset/recovery, exact-engine harness, and focused tests. No clinical lifecycle authority changes.

Finance-steward mapping routes may sit under the existing tariff/component management boundary:

- `GET /manajemen-data/tarif-komponen-biaya/pemetaan-akomodasi`;
- `POST /manajemen-data/tarif-komponen-biaya/pemetaan-akomodasi`;
- `PATCH /manajemen-data/tarif-komponen-biaya/pemetaan-akomodasi/{binding}`; and
- `POST /manajemen-data/tarif-komponen-biaya/pemetaan-akomodasi/{binding}/nonaktifkan`.

No new clinical or cashier mutation route is authorized. Existing cashier synchronization/issue routes enforce readiness.

## Verification gate

Before local completion, tests must prove:

1. prospective exact bed-version snapshots on admission/transfer and strict refusal of legacy/inferred provenance;
2. contiguous half-open intervals and deterministic admission/midnight anchors, including same-day stay, midnight discharge, midnight transfer, multiple same-day transfers, and multi-day transfers;
3. no charge on admission alone, mutable census, cancellation, open interval, or wall-clock passage;
4. one source per encounter/date across replay and concurrency;
5. historical binding/tariff resolution for each service date, future versions, retirement, no inference, no proration, and positive integer-rupiah amounts;
6. partial synchronization with fail-closed issue until discharge and complete accommodation readiness;
7. unchanged pharmacy/radiology/laboratory evidence and immutable prior bill versions;
8. exact roles, authorization-before-lookup, audit rollback, idempotency conflict, database/application guards, recovery corruption refusal, reset, and rollback refusal;
9. independent PostgreSQL/MySQL races and least-privilege runtime grants; and
10. full test, PHPStan, Pint, frontend, Ruby documentation, build, and diff gates.

Passing engineering evidence proves only this exact synthetic profile. It never proves a real hospital's price, day-count rule, revenue recognition, owner acceptance, or production readiness.

## Explicit exclusions

- real patient, occupancy, tariff, or financial data;
- inferred, seeded, default, sample, or purportedly hospital-approved prices;
- historical location backfill or bed-version inference;
- hourly/minute proration, partial-day rounding, longest-stay allocation, multiple charges per date, manual day count, class upgrade/downgrade, payer-specific pricing, packages, discounts, deposits, tax, or professional fees;
- automatic finance writes from admission, transfer, discharge, census, or ward/bed services;
- occupancy correction, void, reversal, refund, negative day, reopen, undischarge, death/AMA transfer semantics, temporary leave, reservation, waitlist, isolation/cohort, or inter-hospital transfer;
- payment, receipt, allocation, receivable, settlement, accounting journal, revenue recognition, claim, INA-CBG/iDRG, BPJS, VClaim, E-Klaim, SATUSEHAT, Aplicares, bank, gateway, fiscal device, or live integration;
- owner/domain/parity acceptance, browser/hosted UAT, G0/G3 closure, commit, push, hosted migration, or deployment.

## Consequences and claim boundary

The design makes accommodation reproducible from existing append-only patient-flow facts without allowing finance to control a bed move or discharge. It deliberately delays accommodation-complete issuance until discharge and refuses older location histories that lack exact master provenance. It avoids invented proration and duplicate transfer-day charges at the cost of a synthetic day-anchor convention that still requires named facility, finance, revenue, registration, clinical, cashier, and RMIK acceptance.

This authorization is not implementation evidence. The application, migration, tests, harness, and exact-engine evidence remain unimplemented and `NOT RUN`. No claim beyond bounded local engineering authority is permitted.
