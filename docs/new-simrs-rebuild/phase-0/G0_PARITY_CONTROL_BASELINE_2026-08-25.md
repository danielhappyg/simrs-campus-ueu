# G0 parity-control baseline — 2026-08-25

**Status:** Inventory structurally reconciled; owner-approved disposition baseline incomplete
**Scope:** All 268 assessed SIMRS Sahabat menu capabilities
**Gate:** G0 remains open

## Structural result

The canonical matrix and vendor menu taxonomy reconcile one-for-one:

- 268 matrix rows;
- 268 unique requirement IDs;
- no duplicate or missing IDs;
- every row has the declared ten semantic columns; and
- category, menu order, identifier family, and assessed taxonomy agree.

The deterministic [`G0 parity batch manifest`](G0_PARITY_BATCH_MANIFEST.json) additionally assigns every canonical PAR ID to exactly one dependency batch and enforces the counts `A=20`, `B=8`, `C=19`, `D=19`, `E=48`, `F=34`, and `G=120`. This is sequencing metadata, not an owner-approved disposition.

This proves inventory integrity only. It does not prove business behaviour, owner approval, implementation, or parity acceptance.

## Decision-readiness snapshot

| Control field | Current resolved state | Current unresolved state |
| --- | ---: | ---: |
| ID/category/menu | 268 | 0 |
| Initial disposition | 20 concrete candidates plus 1 proposed consolidation | 247 pending-evidence variants |
| Evidence | 15 more-specific observations | 253 menu-presence-only records |
| Parity status | 19 Specified | 249 Unspecified; 0 Acceptance review, Accepted, or Deferred |
| Business owner | 16 without `TBD` | 252 contain `TBD` |
| Target capability/slice | 28 | 240 exact `TBD` |
| Detailed requirement | 26 non-`TBD` pointers or notes | 242 exact `TBD` |
| Acceptance test | 13 pointers or notes | 255 exact `TBD` |

`PAR-CLN-001` and `PAR-CLN-019` were returned from `Specified` to `Unspecified` because their consolidation candidates still have only `Clinical TBD` ownership. This is a governance correction, not lost implementation.

## Evidence limitation

The assessment observed menu visibility for most rows. It did not submit clinical, diagnostic, medication, stock, claim, financial, or reporting transactions. Menu presence cannot by itself decide Reproduce, authorization, state transitions, calculations, corrections, reversals, downstream postings, or acceptance.

Every disposition therefore requires either:

1. stronger read-only or synthetic evidence;
2. an authorized domain-owner decision;
3. an explicitly bounded safe teaching simulator;
4. an authorized deferral/teaching exclusion; or
5. a documented consolidation/retirement decision with impact analysis.

## Dependency-safe decision batches

Every requirement belongs to exactly one batch as enumerated in [`G0_PARITY_BATCH_MANIFEST.json`](G0_PARITY_BATCH_MANIFEST.json). The category `Manajemen Data` is not synonymous with Batch A: 18 ADM rows plus IOT-001 and HLP-001 form Batch A, while the other 28 ADM masters are assigned to their dependent B–G domains.

| Batch | Requirement groups | Count | Primary decision outcome |
| --- | --- | ---: | --- |
| A. Shared governance and controls | Exact 20-ID set in the manifest: 18 ADM controls/identity/audit/configuration rows, IOT-001 and HLP-001 | 20 | Canonical identity, RBAC, audit, configuration, integration-log, documentation and safe IoT boundaries; proposals are in [`G0_BATCH_A_SHARED_CONTROLS_PROPOSAL_2026-08-25.md`](G0_BATCH_A_SHARED_CONTROLS_PROPOSAL_2026-08-25.md) |
| B. Patient access and encounter masters | REG-001–005; ward/class/payer encounter masters | 8 | Canonical RJ/IGD/RI registration, correction, cancellation, queue and encounter outcomes |
| C. Core care and record completion | Core CLN/RMIK documentation, completeness, filing and EMR masters | 19 | One clinical-documentation and RM-completion model replacing parallel generations |
| D. Diagnostics, allied care, blood and surgery | CLN-006–018/020, ORP-001, diagnostic/surgery masters | 19 | Order, service, specimen, result, verification, correction, release, acknowledgement and surgery boundaries |
| E. Pharmacy and warehouse | PHA-001–020, PWH-001–023 and related masters | 48 | Prescription, dispense/return, procurement, receipt, distribution, lot/expiry, stocktake and reconciliation |
| F. RMIK claims, BPJS and revenue | Coding/claim rows, BPJS, FIN-001–019 and finance masters | 34 | Safe claims simulation and coherent tariff, charge, payment, reversal, receivable, settlement and journal behaviour |
| G. Reports and public-health definitions | RPT-001–117 and three reporting/public-health masters | 120 | Approved owner, purpose, source, formula, period, control total, access, format, retention, consolidation or teaching exclusion |
| **Total** |  | **268** |  |

Decision dependency:

```text
A Shared controls
  → B Patient/encounter
  → C Core care/RMIK
  → D Diagnostics/allied/surgery
  → E Pharmacy/warehouse
  → F Claims/BPJS/revenue
  → G Reporting/public health
```

Report discovery may begin early, but a report cannot be approved before its source workflow, states, formula, and control totals are settled.

## Required per-row decision record

Before moving a row to `Specified`:

1. assign an accountable owner and affected actors;
2. record normalized evidence class `O`, `M`, `I`, `U`, or `P`, with date/source/reference;
3. identify unresolved rules, states, exceptions and discovery method;
4. approve exactly one canonical disposition;
5. name the canonical target or authorized exclusion;
6. record downstream clinical, stock, financial, claim, report and integration effects;
7. define a synthetic acceptance scenario, expected result, denial/correction path, and owner;
8. retain approval identity, date and decision reference; and
9. verify every consolidation target exists and is acyclic.

## G0 exit condition

G0 is complete only when all are true:

- 268/268 named accountable owners;
- 268/268 normalized evidence records;
- 268/268 owner-approved canonical dispositions or explicit authorized deferrals;
- 268/268 named target slices/exclusions;
- 268/268 linked requirement or decision artifacts;
- 268/268 defined acceptance scenarios;
- no `Pending evidence`, owner `TBD`, target `TBD`, or orphaned/cyclic consolidation; and
- every cross-domain dependency is represented in the delivery graph.

G0 does not require all 268 capabilities to be implemented. Implementation and G2/G3 acceptance remain later gates.

## References

- `docs/new-simrs-rebuild/PARITY_REQUIREMENTS_MATRIX.md`
- `docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json`
- `docs/new-simrs-rebuild/phase-0/G0_BATCH_A_SHARED_CONTROLS_PROPOSAL_2026-08-25.md`
- `docs/vendor-simrs-assessment-2026-08-21/FULL_MENU_TAXONOMY.md`
- `docs/vendor-simrs-assessment-2026-08-21/FULL_SIMRS_WORKFLOW_MODEL.md`
- `docs/new-simrs-rebuild/DELIVERY_ROADMAP.md`
- `docs/new-simrs-rebuild/REQUIREMENTS_GOVERNANCE.md`
