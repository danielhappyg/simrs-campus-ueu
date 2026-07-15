# Outpatient Requirements Traceability Matrix

- **Version:** 1.0
- **Purpose:** connect evidence to workflow, data, implementation, and verification

## 1. Traceability rule

A requirement is ready for implementation when it has:

1. a classification and source/decision in the evidence register;
2. an observable workflow or policy response;
3. defined data/provenance where applicable;
4. at least one acceptance scenario; and
5. no hidden clinical rule outside the validation register.

Implementation status values are `NOT STARTED`, `IN PROGRESS`, `IMPLEMENTED`, and `VERIFIED`. This baseline begins as `NOT STARTED` because it defines the contract before application code.

## 2. Matrix

| Requirement | Workflow/policy response | Canonical data | Acceptance evidence | Planned module | Status |
|---|---|---|---|---|---|
| EMR-001 | Blueprint stages 1–10 | Patient, Encounter, ClinicalEntry, Pharmacy, RecordQualityReview | E2E-01, E2E-11 | Registration, Clinical, Pharmacy, RMIK | NOT STARTED |
| EMR-002 | Registration stage and duplicate exception | SyntheticPatient, PatientIdentifier, AppointmentRegistration | REG-01, REG-02, SAF-01 | Patient Identity | NOT STARTED |
| EMR-003 | Documentation state model and longitudinal record | ClinicalEntryVersion, ReviewAction | DOC-01, E2E-11 | Clinical Documentation | NOT STARTED |
| EMR-004 | Shared handoff contracts | Patient/Encounter foreign-key boundary | E2E-01, EDU-01 | Cross-module foundation | NOT STARTED |
| EMR-005 | RMIK review stage | RecordQualityReview, CodeAssignment | RMIK-01, RMIK-02 | RMIK | NOT STARTED |
| EMR-006 | Source-linked coding rule | CodeAssignment source/version | RMIK-03 | RMIK | NOT STARTED |
| EMR-007 | Context policies and operational safeguards | Assignment, AuditEvent, private storage metadata | AUTH-01–04, AUD-01, OPS-01 | Identity/Audit/Operations | NOT STARTED |
| EMR-008 | Versioned completeness review | RecordQualityReview/findings | RMIK-02 | RMIK | NOT STARTED |
| EMR-009 | Separate create/review/approve/amend permissions | Assignment, ClinicalEntryVersion, ReviewAction | DOC-02, AUTH-01–04, E2E-05 | Identity/Clinical | NOT STARTED |
| EMR-010 | Honest simulation attestation | ReviewAction | DOC-03, SAF-02 | Supervision | NOT STARTED |
| EDU-001 | Shared session/service/debrief model | Scenario, SimulationSession, Assignment, AuditEvent | E2E-01, EDU-01, EDU-02 | Teaching | NOT STARTED |
| EDU-002 | Interprofessional case and handoffs | Assignment, Encounter | EDU-01 | Teaching | NOT STARTED |
| EDU-003 | Contextual competence/supervision policy | Assignment, task capabilities, supervision link | AUTH-01–04 | Identity/Teaching | NOT STARTED |
| EDU-004 | Activity and supervisor event history | ReviewAction, AuditEvent | EDU-02, AUD-01 | Teaching/Audit | NOT STARTED |
| EDU-005 | Permanent simulation boundary | Environment mode and synthetic flags | SAF-01–03 | Platform | NOT STARTED |
| EDU-006 | Product-owner-led autonomous build | Governance decision-rights table | Checkpoint records | Project governance | IMPLEMENTED¹ |
| OPD-001 | Outpatient encounter stages | Encounter/service type/disposition | E2E-01 | Encounter | NOT STARTED |
| OPD-002 | Stable Patient/Encounter mapping | Internal IDs plus adapter mapping metadata | INT-01 | Interoperability | NOT STARTED |
| OPD-003 | First-class outpatient concepts | Observation, Condition, ServiceRequest, Result, Medication, Composition source | E2E-01, INT-01 | Clinical/Integration | NOT STARTED |
| OPD-004 | Terminology/unit separation | Code and quantity conventions | DATA-01, NUR-01, RMIK-01 | Terminology | NOT STARTED |
| OPD-005 | Intake/safety-screen stage and language | Intake fields and decision | NUR-01, UX-01 | Nursing | NOT STARTED |
| OPD-006 | Configurable/unvalidated clinical thresholds | Ruleset version and question responses | E2E-02, SAF-04 | Scenario/Nursing | NOT STARTED² |
| OPD-007 | Concrete reference before broad review | Three-checkpoint validation | Scenario-linked UAT feedback | Product delivery | IMPLEMENTED¹ |
| PHA-001 | Pharmacy review/dispense scope | PharmacyReview, Intervention, Dispense | PHA-01–03 | Pharmacy | NOT STARTED |
| PHA-002 | Three separate review domains | PharmacyReview domain outcomes | PHA-01 | Pharmacy | NOT STARTED |
| PHA-003 | Derive administrative source data | Patient/Encounter/MedicationRequest/author | PHA-01 | Pharmacy | NOT STARTED |
| PHA-004 | Authored pharmaceutical/clinical outcomes, no automatic clearance | PharmacyReview and comments | PHA-01, PHA-02, SAF-04 | Pharmacy | NOT STARTED² |
| PHA-005 | Receive/review/prepare/check/dispense truth | Dispense and simulated StockMovement | PHA-03 | Pharmacy/Inventory | NOT STARTED |
| SAF-001 | Reject real-data input | Synthetic flags, fixture provenance, identifier namespace | SAF-01 | Platform/Data | NOT STARTED |
| SAF-002 | Honest labels/signatures/integrations | Environment mode, ReviewAction, integration status | DOC-03, SAF-02, SAF-03 | Platform/UI | NOT STARTED |
| SAF-003 | No autonomous clinical decision support | Data-quality/clinical-rule separation | SAF-04 | Platform | NOT STARTED |

1. `IMPLEMENTED` here means recorded in approved project/product governance documentation; it does not mean application code exists.
2. Human clinical validation remains pending by design. The application can implement the configurable boundary and non-decision behavior before content is approved.

## 3. Cross-cutting quality coverage

| Quality concern | Governing artifacts | Acceptance scenarios | Required delivery evidence |
|---|---|---|---|
| Contextual authorization | Role Matrix POL-001–012 | AUTH-01–04 | Policy/feature tests plus direct API denial tests |
| Immutable provenance | Blueprint documentation state; Data Dictionary sections 5 and 10 | DOC-01–03, AUD-01 | Versioning and audit tests |
| Transaction integrity | Pharmacy/data model | PHA-03 | Database transaction failure test |
| Synthetic-data safety | Evidence SAF-001/002; Data Dictionary section 12 | SAF-01–03 | Seeder/import/configuration tests and UI/export checks |
| No clinical overclaim | Evidence SAF-003; Data Dictionary section 13 | E2E-02, SAF-04 | Content/design review and feature tests |
| Accessibility | Validation VAL-U04 | UX-01–04 | Automated axe checks plus keyboard/manual review |
| Recovery | ADR-001; Data Dictionary relationships | OPS-01, OPS-02 | Backup/restore and deploy/rollback rehearsal |
| Interoperability honesty | Evidence OPD-002–004 | INT-01, INT-02, SAF-03 | Local mapping tests; no production network dependency |

## 4. Change control

When a source, decision, or accepted workflow changes, the pull request must identify:

- affected requirement IDs;
- source/version or product decision causing the change;
- blueprint/data/policy changes;
- acceptance scenarios added or modified;
- migration/backfill effect if code already exists;
- whether an assumption was resolved or newly introduced; and
- Daniel's scope/release decision for a material change.
