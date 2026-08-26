# Parity Requirement: PAR-REG-001 Rawat Inap registration (canonical)

## Control information

- Legacy menu/category: Pendaftaran / Rawat Inap
- Disposition: **Reproduce** (canonical admission)
- Business owner: Daniel Happy Putra (interim registration); confirm with front-office / bed-management SME when appointed
- Affected actors: registrar / admission officer; teaching student in registrar role
- Evidence: **Observed** form/route 2026-08-21 (`/pendaftaran/rawatinap`; processForm/grid; fields include asal, clinic_id, payment_type_id, continue_id, address/region); **Manual-documented** Pendaftaran Rawat Inap (2018); bed assignment mechanics **Inferred/Unknown** (workflow model)
- Related standard: teaching synthetic identity policy (NEW); Bangsal master PAR-ADM-009
- Target milestone: Phase 3 inpatient vertical slice (build after Phase 2 foundation)
- Slice pack: [`../INPATIENT_SLICE_DISPOSITIONS.md`](../INPATIENT_SLICE_DISPOSITIONS.md)

## Business outcome

Create or locate a synthetic inpatient and open a coherent admission with payer/referral/continue and ward/class/bed context so ward clinical, RM, pharmacy, and billing modules share one inpatient episode identity.

## Preconditions and master data

- Required patient/encounter state: none for direct admission; may continue from ED/outpatient (**Unknown** continue_id semantics — Observed field name only)
- Required roles/permissions: `patient.search`, `patient.register` / admission capabilities — server-enforced
- Required reference data: bangsal/kelas/bed (PAR-ADM-009), payment types, clinics — **Unknown** exact catalogues and occupancy rules
- Required integration state: BPJS eligibility **sandbox or offline** only; production blocked; Aplicares-style bed publish not enabled to production

## Workflow and state transitions

1. Trigger: authorized registrar opens inpatient registration (direct or from ED disposition handoff)
2. Normal sequence: search → create or select patient → capture admission context → assign/reserve ward/class/bed (**Unknown** locking) → create inpatient episode
3. Resulting state: admitted / waiting for bed (**exact status vocabulary Unknown**)
4. Downstream: Pemeriksaan Rawat Inap (PAR-CLN-005); Apotek RI; Kasir RI; Register Rawat Inap; RM RI

## Business rules and validation

| Rule ID | Rule | Error behavior | Evidence/source |
|---|---|---|---|
| BR-RIREG-001 | Dual identifier verification required for returning patients | Block admission completion | Proposed NEW (teaching safety); legacy exact rule **Unknown** |
| BR-RIREG-002 | Synthetic marker on all patients | Reject non-synthetic in teaching env | Proposed NEW / NFR-SYN-01 |
| BR-RIREG-003 | Field-level required set | TBD | Observed inputs — **Unknown** which are mandatory |
| BR-RIREG-004 | Bed occupancy: prevent silent double-book in teaching scenarios | Block or queue with clear message | Proposed NEW / NFR-BED-01; legacy concurrency **Unknown** |

## Exceptions and reversibility

- Duplicate: discovery — **Unknown**
- No free bed: behaviour **Unknown** (waitlist Inferred possible only)
- Cancellation before clinical start: must not destroy audit; bed release rules **Unknown**
- Decision candidate: the [cross-setting pre-clinical cancellation pack](../CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_FR_PACK_2026-08-27.md) proposes an immutable cancellation, retained placement history, and bed reuse only when no dependent fact exists. It remains **unapproved**.
- Correction / class change: attributable amendment — Proposed NEW; charge effects **Unknown**
- External failure: fail closed — Proposed NEW

## Authorization and audit

- View/search: registrar / bed-view capabilities as assigned
- Create/change admission and bed assignment: authorized roles only; server-side deny others
- Audit: actor, time, patient, episode, bed before/after, action, correlation id

## Outputs and reports

- Inpatient episode + bed context (**print layouts Unknown**; Manual-documented gelang pasien — confirm for teaching)
- Contribution to Register Rawat Inap / occupancy reports later

## Acceptance criteria (synthetic)

1. Registrar can admit a new synthetic inpatient with ward/class/bed context and see episode id.
2. Non-registrar is denied server-side with audit.
3. Production BPJS / Aplicares hostnames cannot be called.
4. Admitted patient appears on ward examination worklist when PAR-CLN-005 is in slice.
5. Bed double-book and cancellation/correction paths defined before UAT sign-off (presently blocked; proposed cancellation/bed rule is not owner-approved).

## Open unknowns

Exact mandatory fields; continue_id / ED→RI atomicity; bed lock and transfer rules; class upgrade semantics (`bpjs_naik_kelas` Observed on RM filters only); relationship to public Info Kamar displays.
