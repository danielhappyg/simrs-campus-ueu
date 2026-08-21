# Parity Requirement: PAR-REG-002 IGD registration (canonical)

## Control information

- Legacy menu/category: Pendaftaran / IGD
- Disposition: **Reproduce** (canonical)
- Business owner: Daniel Happy Putra (interim registration); confirm with front-office / ED SME when appointed
- Affected actors: registrar / ED front office; teaching student in registrar role
- Evidence: **Observed** form/route 2026-08-21 (`/pendaftaran/ugd`; process posts including `riwayatsep` / `riwayatgrid`); **Manual-documented** Pendaftaran IGD chapters (2018) — confirm before treating as current rules
- Related standard: teaching synthetic identity policy (NEW)
- Target milestone: Phase 3 ED vertical slice (build after Phase 2 foundation)
- Slice pack: [`../ED_SLICE_DISPOSITIONS.md`](../ED_SLICE_DISPOSITIONS.md)

## Business outcome

Create or locate a synthetic emergency patient and open a coherent ED encounter with payer/arrival/accident/family-contact context so triage, ED clinical, RM, pharmacy, and billing modules share one encounter identity.

## Preconditions and master data

- Required patient/encounter state: none for new patient; existing synthetic patient searchable for returning
- Required roles/permissions: `patient.search`, `patient.register` (or ED-specific equivalents) — server-enforced
- Required reference data: ED clinic/unit, payment types, arrival/case catalogues — **Unknown** exact catalogue until master-data discovery
- Required integration state: BPJS eligibility / SEP history **sandbox or offline** only; production blocked

## Workflow and state transitions

1. Trigger: authorized registrar opens ED registration
2. Normal sequence: search → create or select patient → capture ED registration context (including responsible-party fields Observed on form) → create ED encounter → available to triage / ED examination queues
3. Resulting state: encounter planned/registered for ED (**exact status vocabulary Unknown**)
4. Downstream: Triage (PAR-CLN-002); Pemeriksaan IGD (PAR-CLN-003); optional Apotek IGD; Register IGD reports

## Business rules and validation

| Rule ID | Rule | Error behavior | Evidence/source |
|---|---|---|---|
| BR-EDREG-001 | Dual identifier verification required for returning patients | Block register completion | Proposed NEW (teaching safety); legacy exact rule **Unknown** |
| BR-EDREG-002 | Synthetic marker on all patients | Reject non-synthetic in teaching env | Proposed NEW / NFR-SYN-01 |
| BR-EDREG-003 | Field-level required set (identity, penanggung jawab, NIK, etc.) | TBD | Observed many inputs — **Unknown** which are mandatory |
| BR-EDREG-004 | Accident / case / arrival flags | TBD | Manual-documented labels — confirm currency |

## Exceptions and reversibility

- Duplicate: discovery — duplicate detection behaviour **Unknown**
- Unknown / unidentified patient: **Unknown** legacy rule; teaching policy TBD (Proposed NEW if needed)
- Cancellation: must not destroy audit; exact charge/queue unwind **Unknown**
- Correction: attributable amendment — Proposed NEW
- External failure: fail closed; no false eligibility success — Proposed NEW

## Authorization and audit

- View/search: registrar capability
- Create/change: registrar; server-side deny others
- Audit: actor, time, patient, encounter, action, correlation id

## Outputs and reports

- ED encounter + queue context (**print layouts Unknown**; Manual-documented Lembar IGD / prints — confirm)
- Contribution to Register IGD (PAR-RPT-013) later

## Acceptance criteria (synthetic)

1. Registrar can register a new synthetic ED patient and see encounter id.
2. Non-registrar is denied server-side with audit.
3. Production BPJS hostnames cannot be called.
4. Registered patient appears for triage/ED exam actors when those modules are in slice.
5. Cancellation/correction paths defined before UAT sign-off (presently blocked as Unknown).

## Open unknowns

Exact mandatory fields; SEP/payer posting; relationship between registration and triage gate; unidentified-patient rules; print set for teaching.
