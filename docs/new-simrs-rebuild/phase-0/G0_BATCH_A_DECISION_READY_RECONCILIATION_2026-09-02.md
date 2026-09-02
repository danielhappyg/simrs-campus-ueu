# G0 Batch A Decision-Ready Reconciliation — 2026-09-02

**Status:** DECISION-READY CANDIDATE — NOT APPROVED, NOT ACTIVE
**Effect:** none; this is an append-only reconciliation pack
**Scope:** the exact 20 Batch A IDs in `G0_PARITY_BATCH_MANIFEST.json`
**Machine-readable companion:** `G0_BATCH_A_DECISION_READY_RECONCILIATION_2026-09-02.json`

## Outcome

This pack puts the current Batch A material into one bounded form for an accountable owner and required co-owners to review. It binds the exact manifest scope, normalizes the current legacy and engineering observations, carries the proposed dispositions and canonical targets from the existing Batch A proposal, records candidate authorities without treating them as appointed, and makes a normal plus denial/correction scenario explicit for every row.

It does not modify the manifest, pending decision register, parity matrix, coverage ledger, governance selector, application, database, or deployment. It does not activate any capability. G0 remains **OPEN** and G3 remains **OPEN**.

No candidate name is an appointment. No code path, test, local evidence record, or candidate scenario is owner acceptance. Every accountable-owner and co-owner appointment remains unresolved, every proposed disposition remains pending, and every approval field remains empty. The normal and denial/correction scenarios remain unexecuted acceptance proposals until attributable evidence is retained and reviewed by validly appointed authorities.

## Bound evidence

The companion JSON binds by SHA-256:

- the deterministic Batch A manifest;
- the 25 August Batch A shared-controls proposal;
- the existing pending Batch A decision register;
- the current 2 September R8 G0/G3 coverage observation;
- the parity requirements matrix; and
- the legacy menu taxonomy and manual map.

Evidence language is deliberately narrow:

- `O_STRUCTURAL` means a menu, route, form, or table structure was observed. It does not prove save behavior, rules, authorization, correction, or downstream effects.
- `U_UNRESOLVED` means the legacy destination was forbidden, errored, or could not be completed.
- `I_RELATED` means current code or tests implement a related control. It does not prove legacy equivalence, appointment, approval, activation, or parity.
- `NO_CURRENT_IMPLEMENTATION` preserves the current R8 status even when adjacent models or fields exist.

Current R8 reports partial/provisional related engineering observations only for `PAR-ADM-001`, `PAR-ADM-002`, and `PAR-ADM-037`. It reports the other 17 Batch A rows as not implemented with no automated coverage. This pack copies those observations; it does not upgrade them.

## Decision slate

| ID | Legacy surface | Proposed disposition and canonical target | Accountable candidate, not appointed | Required co-owner domains, all unresolved |
|---|---|---|---|---|
| `PAR-ADM-001` | Group | Consolidate into `PAR-ADM-002`; retain explicit group-to-role migration | Daniel Happy Putra — interim security/product candidate | Product delivery; security/privacy/data; affected role owner |
| `PAR-ADM-002` | Pengguna | Reproduce as workforce/user-account administration | Daniel Happy Putra — interim security/product candidate | Product delivery; security/privacy/data; workforce identity |
| `PAR-ADM-003` | Settings | Replace with a versioned configuration registry | Daniel Happy Putra — interim product/operations candidate | Product delivery; security/privacy/data; operations; affected domain owner |
| `PAR-ADM-005` | Menu | Replace with deployment-controlled navigation/capability registry | Daniel Happy Putra — architecture/security candidate | Product delivery; security/privacy/data; operations |
| `PAR-ADM-006` | Staff Medis | Reproduce as workforce/clinician directory | Daniel Happy Putra — data-steward candidate | Product delivery; medical administration; security/privacy/data |
| `PAR-ADM-008` | Template Tanda Tangan | Replace with versioned signature-template registry; sandbox signer only | RMIK Department — candidate custodian | Product delivery; RMIK; security/privacy/data |
| `PAR-ADM-012` | Printer & Service | Replace with controlled print-service configuration | Daniel Happy Putra — operations candidate | Product delivery; operations; affected document owner |
| `PAR-ADM-013` | Unit & Poliklinik | Reproduce as organization/unit/clinic master | Daniel Happy Putra — interim registration/data candidate | Product delivery; registration/admission; medical administration; reporting |
| `PAR-ADM-022` | Pekerjaan | Replace with governed occupation terminology registry | RMIK Department — candidate data custodian | RMIK; registration/admission; reporting |
| `PAR-ADM-023` | Pendidikan | Replace with governed education terminology registry | RMIK Department — candidate data custodian | RMIK; registration/admission; reporting |
| `PAR-ADM-024` | Suku | Replace with privacy-scoped ethnicity terminology registry | RMIK Department — candidate data custodian | RMIK; registration/admission; security/privacy/data; reporting |
| `PAR-ADM-025` | Bahasa | Replace with language/communication-support terminology registry | RMIK Department — candidate data custodian | RMIK; registration/admission; accessibility; reporting |
| `PAR-ADM-032` | Pasien | Reproduce as patient identity stewardship with merge/unmerge | RMIK Department — candidate custodian | RMIK; registration/admission; clinical; finance/claims; security/privacy/data |
| `PAR-ADM-037` | Log Activity | Replace with immutable canonical audit viewer | Daniel Happy Putra — interim security candidate | Security/privacy/data; operations; affected domain owner |
| `PAR-ADM-038` | LOG ESIGN | Replace with sandbox e-sign adapter/audit view | Daniel Happy Putra — interim security candidate | RMIK; security/privacy/data; operations |
| `PAR-ADM-040` | Log Bridging | Replace with integration message/retry/reconciliation log | Daniel Happy Putra — interim integration/security candidate | Product delivery; security/privacy/data; operations; affected domain owner |
| `PAR-ADM-044` | Logsatset | Replace with one non-transmitting SatuSehat sandbox log | Daniel Happy Putra — interim integration/security candidate | Product delivery; RMIK; security/privacy/data; operations |
| `PAR-ADM-045` | logsatsetri | Consolidate into `PAR-ADM-044`; retain inpatient filter and migration trace | Daniel Happy Putra — interim integration/security candidate | Product delivery; RMIK; inpatient clinical; security/privacy/data |
| `PAR-HLP-001` | Manual Book | Replace with versioned Indonesian role manuals | RMIK Department — candidate teaching owner | RMIK; teaching facilitation; operations; affected role owner |
| `PAR-IOT-001` | Temperature | Replace with synthetic temperature gateway | Daniel Happy Putra — interim operations/security candidate | Operations; cold chain; security/privacy/data |

## Scenario completeness

Each JSON entry contains two decision-ready but unexecuted scenarios:

1. `normal` states the synthetic action and at least two observable assertions for the proposed canonical outcome.
2. `denial_correction` states an unauthorized, invalid, stale, unsafe, or correction action and at least two fail-closed or reconciliation assertions.

Execution must retain the fixture and starting state, exact actor and role, authorization result, correlation identifier, before/after state or immutable replacement event, audit/denial evidence, downstream reconciliation, output evidence, and the later accountable/co-owner disposition. Both scenarios must pass. Passing tests cannot substitute for appointment or approval.

## Explicit exclusions and non-effects

This pack authorizes none of the following:

- accountable-owner or co-owner appointment;
- capability disposition or owner acceptance;
- governance consumer selection or activation;
- update of the parity matrix or current coverage status;
- implementation, commit, push, deployment, hosted migration, or production use;
- real patient data, secrets, production devices, production TTE certificates, or live payment/bank integration; or
- live BPJS, VClaim, or SATUSEHAT endpoints, credentials, or transmission.

All external integration candidates remain non-transmitting or simulated. Candidate consolidation of `PAR-ADM-001` and `PAR-ADM-045` remains pending and cannot remove either source ID, its evidence gap, its scenario, or its downstream impacts.

## Required decision action

For any row to move beyond this candidate state, retain verifiable appointment evidence for the accountable owner and every required co-owner, then record an attributable `approve`, `revise`, `defer`, or `reject` decision with date, reference, conditions, and independently verified artifact hash under the existing governance contract. Until that occurs, the only truthful state is pending and inactive.
