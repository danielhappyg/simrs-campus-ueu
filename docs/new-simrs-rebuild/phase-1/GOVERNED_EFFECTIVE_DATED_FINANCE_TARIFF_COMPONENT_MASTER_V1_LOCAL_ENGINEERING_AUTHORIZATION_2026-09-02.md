# Governed Effective-Dated Finance Tariff and Cost-Component Master V1

Status: **locally authorized for bounded implementation and engineering verification**
Date: 2026-09-02
Primary coverage contribution: `PAR-ADM-011`, `PAR-ADM-018`, and `PAR-ADM-019`
Journey contribution: `E2E-16` master-data control; prerequisite only for later `E2E-05`, `E2E-06`, and `E2E-14` charge adapters

## Decision and outcome

Release 1.0 will add one governed, effective-dated finance master for tariff catalogues, cost-component groups, and cost components. It starts empty. A deliberately provisioned exact `finance_steward` enters every amount and effective date; the application seeds no tariff, default amount, real hospital price, or inferred clinical charge.

```text
cost-component group -> cost component -> effective-dated tariff item
        immutable versions and code reservations at every level
```

This record authorizes local synthetic teaching implementation and engineering verification only. It does not confer finance, cashier, clinical, diagnostic, facility, RMIK, or parity acceptance; hosted readiness; G0/G3 closure; or authority to use real patient or financial data. No commit, push, hosted migration, or deployment is authorized by this record.

## Requirements and bounded scope

- `PAR-ADM-011`: maintain a named tariff catalogue and its effective-dated tariff items.
- `PAR-ADM-018`: maintain stable cost-component groups.
- `PAR-ADM-019`: maintain stable cost components bound to one group.
- `E2E-16`: contribute a governed master-data workflow with attributable creation, revision, retirement, audit, recovery, and role separation.
- Laboratory, radiology, accommodation, clinical procedures, and the encounter bill remain consumers only after separately authorized source adapters. This slice creates no `finance_charge_events` and changes no pharmacy-only source admission rule.

## Options considered

| Option | Value | Risk | Decision |
|---|---|---|---|
| Seed plausible tariffs and connect them immediately | Fast visible billing | Invented prices and false completeness | Rejected |
| Build non-pharmacy adapters before a governed tariff source | Expands bill lines | No authorized valuation fact | Rejected |
| Empty governed master first, adapters later | Honest, auditable prerequisite | Requires deliberate master entry before charges | Selected |

The selected option makes the financial graph structurally complete without pretending that an observed Sahabat field or legacy value is an approved UEU tariff.

## Master and version contract

All codes are normalized uppercase stable identifiers and permanently reserved. Heads are mutable only through the governed service; versions, code reservations, and operation receipts are append-only.

### Cost-component group

- stable `group_code`, display name, state, version, and current content digest;
- lifecycle `ACTIVE -> RETIRED`; retirement is terminal and deletion is forbidden;
- rename creates a new immutable version without reusing or changing the code.

### Cost component

- stable `component_code`, one group binding, display name, optional description, state, version, and current content digest;
- group binding cannot silently move after creation; a replacement component requires a new code;
- optional terminology labels are manual reference metadata only. No SNOMED CT, LOINC, ICD, ICD-9-CM, KPTL, LIS, or other terminology lookup/integration is implied;
- a group with active or historically referenced components cannot be deleted.

### Tariff catalogue and item

- a catalogue has a stable `catalogue_code`, display name, state, version, and immutable versions;
- a tariff item has a stable `tariff_code`, catalogue and component binding, display name, care setting, optional service-domain/reference labels, optional ward-class label, amount in integer rupiah, state, version, `effective_from`, and content digest;
- closed care settings are `OUTPATIENT`, `EMERGENCY`, and `INPATIENT`;
- V1 service domains are descriptive closed labels only: `GENERAL_SERVICE`, `LABORATORY`, `RADIOLOGY`, and `ACCOMMODATION`. They do not authorize a charge adapter;
- amount must be a positive integer rupiah value. Floating-point money is prohibited. A zero-price policy or discount is a separate decision;
- the first version may start on the server date or a future date. A successor must have `effective_from` strictly after the current version's effective date and cannot rewrite a past row;
- one tariff code resolves to at most one active version for any service date. Overlapping or ambiguous effective periods are forbidden;
- each non-retired version applies on the half-open interval `[effective_from, next_effective_from)`. A terminal `RETIRED` version is effective at its own `effective_from` and makes the tariff unselectable from that instant while preserving every earlier interval;
- the head records the latest authored version, which may be future-dated. Resolution for a service date independently selects the applicable effective version; a future successor never hides the still-applicable version today;
- retirement creates a new terminal version and does not erase earlier effective versions.

Creation or versioning of a tariff requires an `ACTIVE` catalogue, component, and component group. Group retirement is denied while any active component depends on it. Component retirement is denied while any current or future tariff item depends on it. Catalogue retirement is denied while any current or future tariff item depends on it. No future tariff activation may occur at or after an upstream retirement. Historical version references remain valid and readable.

Tariff selection is based on the clinical/service event date, never the bill-issue date. A later adapter must snapshot tariff version public ID, code, amount, component identity, service date, and content digest into its immutable source event.

## Actor and authorization boundary

- Exact `finance_steward` (`Pengelola Tarif`) may view, create, revise, future-date, and retire these masters.
- Exact `cashier` may view the effective master and history but cannot create, revise, activate, future-date, or retire it.
- Exact `admin` may provision or revoke the role and perform bounded reset/audit duties but cannot act as `finance_steward`.
- Clinical, nursing, registration, RMIK, laboratory, radiology, pharmacy, and inventory roles receive no master mutation authority.
- A system-administrator flag or mixed-role account does not bypass the exact-role boundary.

Every controller checks capability before route-resource lookup; the service repeats the exact-role check before protected relationship access. Unauthorized actors receive no master, group, component, catalogue, or tariff existence disclosure.

New capabilities are `finance.tariff.view` and `finance.tariff.manage`. The dedicated UI is `Manajemen Data > Tarif & Komponen Biaya`, with Indonesian labels and no user-facing simulation wording.

## Operations, concurrency, and correction

Mutations are limited to:

1. create/revise/retire a component group;
2. create/revise/retire a component;
3. create/revise/retire a tariff catalogue; and
4. create a tariff item or append its next effective-dated version.

Every mutation requires a normalized idempotency key, canonical payload SHA-256, actor, reason, expected version/content fingerprint where applicable, server time, immutable receipt, and required success audit. Same-key/same-payload replay returns the retained result; changed-payload key reuse is denied. Stale expected versions, code reuse, group drift, effective-date overlap, retroactive rewrite, and mutation after retirement fail atomically.

Lock order is catalogue, component group, component, tariff head, current tariff version, then receipt/audit. Competing writers must produce one applied result and one attributable replay or stale denial, never duplicate versions or split heads.

Corrections are forward-only versions. There is no update/delete of immutable history and no reopening of a retired code. An incorrect amount is corrected by a later effective version; historical service dates continue resolving to the retained historical version.

## Audit, database guards, reset, and recovery

- Required events cover create, revise, future activation, retirement, replay, stale denial, authorization denial, and integrity refusal without leaking protected data.
- Database guards refuse ordinary update/delete/truncate of immutable versions, reservations, and receipts, and refuse unscoped master-head writes.
- Bounded synthetic reset removes the finance-master graph in dependency order while preserving required audit evidence.
- Recovery evidence includes stable table digests, version-chain continuity, current-head/version reconciliation, duplicate/overlap checks, orphan checks, code-reservation reconciliation, receipt reconciliation, and post-reset emptiness.
- Migration rollback refuses while master rows, retained receipts, or correlated audit evidence remain.

## User experience contract

- The landing screen shows `Tarif & Komponen Biaya`, `Katalog Tarif`, `Group Komponen Biaya`, and `Komponen Biaya`.
- Empty state explains that a tariff must be deliberately added before it can be selected; it supplies no sample amount.
- View-only users can search, filter by status/effective date/care setting, inspect current values, and open immutable version history.
- Steward actions require deliberate confirmation, a reason, expected fingerprint, and accessible error summary.
- Status is communicated with text as well as colour; updates use an accessible live region; controls have at least 44-pixel targets; tables retain semantic headers and keyboard access.
- Currency is rendered as rupiah from integer values; form input never uses floating-point arithmetic.

## Verification gate

Local completion requires:

1. migration/model/service tests for all three `PAR-ADM-*` capabilities, effective-date resolution, version history, retirement, idempotent replay, stale denial, overlap/reflexive binding refusal, integer money, and authorization-before-lookup;
2. exact-role tests for `finance_steward`, read-only `cashier`, non-bypass `admin`, mixed-role denial, provisioning, and revocation;
3. database guard, required-audit rollback, reset, recovery, fresh migration, empty rollback/reapply, and retained-evidence rollback-refusal tests;
4. two-process PostgreSQL/MySQL concurrency tests for code creation, competing version append, and reset/recovery competition;
5. frontend tests for Indonesian labels, empty state, read-only versus steward controls, effective-date history, semantic currency, keyboard behavior, error focus, live status, and 44-pixel targets;
6. full PHP, PHPStan, Pint, frontend test/type/lint/format/build, documentation, and diff gates; and
7. disposable PostgreSQL 17 and MySQL 8.4 evidence with strict cleanup and exact source bindings.

## Explicit exclusions

- seeded/default/inferred prices, real hospital tariffs, real patient or financial data, or secret material;
- automatic charge generation, non-pharmacy `finance_charge_events`, manual charges, discounts, packages, professional-fee distribution, taxes, guarantees, deposits, receipts, payment allocation, receivables, settlement, journals, or revenue recognition;
- claims, INA-CBG/iDRG, BPJS, VClaim, SATUSEHAT, insurer, bank, payment gateway, LIS, PACS/RIS, terminology server, or other live integration;
- bulk spreadsheet upload/download until formula, validation, authorization, and partial-failure behavior are separately specified;
- finance, cashier, clinical, diagnostic, facility, RMIK, or parity acceptance; G0/G3 closure; hosted readiness; production readiness; commit; push; deployment; or hosted migration.

## Consequences and next graph boundary

This design adds a durable valuation source without adding a charge. Once exact-engine evidence is green, the next graph node should be one separately authorized laboratory or radiology financial-source adapter that snapshots a resolved tariff version into an immutable source event. Payment and claims remain downstream until the bill has multiple reconciled source domains and domain-owner acceptance.
