# Parity Requirement: PAR-REG-003 Rawat Jalan registration (canonical)

## Control information

- Legacy menu/category: Pendaftaran / Rawat Jalan
- Disposition: **Reproduce** (canonical); PAR-REG-005 consolidates here
- Business owner: Daniel Happy Putra (interim registration); confirm with front-office SME when appointed
- Affected actors: registrar / front office; teaching student in registrar role
- Evidence: **Observed** form/route 2026-08-21 (`/pendaftaran/rawatjalan`); Manual-documented historic registration chapters (2018) — confirm before treating as current rules
- Related standard: teaching synthetic identity policy (NEW)
- Target milestone: Phase 3 outpatient vertical slice (build after Phase 2 foundation)

## Business outcome

Create or locate a synthetic outpatient patient and open a coherent outpatient encounter with payer/referral/queue context so downstream clinical, RM, pharmacy, and billing modules share one encounter identity.

## Preconditions and master data

- Required patient/encounter state: none for new patient; existing synthetic patient searchable for returning
- Required roles/permissions: `patient.search`, `patient.register` (server-enforced)
- Required reference data: clinics/schedules/payer types — **Unknown** exact catalogue until master-data discovery
- Required integration state: BPJS eligibility **sandbox or offline** only; production blocked

## Workflow and state transitions

1. Trigger: authorized registrar opens outpatient registration
2. Normal sequence: search → create or select patient → capture registration context → create encounter/appointment → queue visibility
3. Resulting state: encounter planned/registered (**exact status vocabulary Unknown**)
4. Downstream: Pemeriksaan RJ queue; optional display; charge stubs as configured

## Business rules and validation

| Rule ID | Rule | Error behavior | Evidence/source |
|---|---|---|---|
| BR-REG-001 | Dual identifier verification required for returning patients | Block check-in/register completion | Proposed NEW (teaching safety); legacy exact rule **Unknown** |
| BR-REG-002 | Synthetic marker on all patients | Reject non-synthetic in teaching env | Proposed NEW / NFR-SYN-01 |
| BR-REG-003 | Field-level required set | TBD | Observed many inputs — **Unknown** which are mandatory |

## Exceptions and reversibility

- Duplicate: discovery — duplicate detection behaviour **Unknown**
- Cancellation: must not destroy audit; exact charge/queue unwind **Unknown**
- Decision candidate: the [cross-setting pre-clinical cancellation pack](../CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_FR_PACK_2026-08-27.md) proposes `REGISTERED` + zero dependent facts, preserved queue history, and no downstream reversal. It remains **unapproved**.
- Correction: attributable amendment — Proposed NEW
- External failure: fail closed; no false eligibility success — Proposed NEW

## Authorization and audit

- View/search: registrar capability
- Create/change: registrar; server-side deny others
- Audit: actor, time, patient, encounter, action, correlation id

## Outputs and reports

- Encounter + queue ticket/context (**print layouts Unknown**)
- Contribution to Register Pendaftaran / Kunjungan RJ reports later (PAR-RPT-*)

## Acceptance criteria (synthetic)

1. Registrar can register a new synthetic outpatient and see encounter id.
2. Non-registrar is denied server-side with audit.
3. Production BPJS hostnames cannot be called.
4. Cancellation/correction paths defined before UAT sign-off (presently blocked; proposed cancellation pack is not owner-approved).

## Open unknowns

Exact mandatory fields; SEP/payer posting; schedule_id semantics; relationship between classic and v2 forms (v2 consolidated).
