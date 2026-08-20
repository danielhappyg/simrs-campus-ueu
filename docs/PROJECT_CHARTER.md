# SIMRS Campus UEU — Project Charter

- **Status:** Approved working baseline
- **Approval date:** 2026-07-15
- **Product owner:** Daniel Happy Putra
- **Product stage:** Discovery and foundation planning
- **Initial release type:** University teaching and simulation platform

## 1. Why this project exists

Universitas Esa Unggul needs a shared campus hospital information system where health-sciences students can learn realistic hospital workflows around the same synthetic patient. The system should connect professional work instead of presenting each study program with an isolated collection of forms.

The product is intended to help students understand:

- how information moves across a hospital;
- what each profession records, reads, and hands over;
- how documentation affects care, medication, coding, completeness, billing simulation, and reporting;
- which actions require authorization or supervisor review;
- how interprofessional decisions become one longitudinal patient record.

## 2. Product boundary

The first product is a **teaching and simulation system**, not a live clinical-care electronic medical record.

| Boundary | Approved position |
|---|---|
| Patient data | Synthetic patients only |
| Clinical use | Teaching and simulation; no real treatment decisions |
| Primary interface language | Indonesian, with recognized clinical/technical terminology where appropriate |
| Student work | Drafted by students and reviewed or approved by an authorized lecturer/supervisor |
| External systems | BPJS and SATUSEHAT simulation or approved sandbox connections only |
| Initial hosting | **Vercel + Supabase Free** for the current synthetic demo; **campus hosting TBD** when IT availability is known |
| Legacy application | Reference mock-up only; no code or database migration requirement |

## 3. First pilot

The first pilot is one complete **outpatient patient journey** involving four programs:

1. Medicine
2. Nursing
3. Medical Records and Health Information (RMIK)
4. Pharmacy

Nutrition, psychology, and physiotherapy remain part of the product vision and will be introduced in later multidisciplinary increments after the shared patient, encounter, authorization, audit, and supervision foundations are proven.

## 4. Pilot journey

The first synthetic patient should be able to move through this sequence:

```mermaid
flowchart LR
    A["Teaching scenario assigned"] --> B["Patient registration"]
    B --> C["Nursing intake and safety screen"]
    C --> D["Medical assessment"]
    D --> E["Diagnosis and orders"]
    E --> F["Results reviewed"]
    F --> G["Prescription"]
    G --> H["Pharmacy verification and dispensing"]
    H --> I["Encounter closure"]
    I --> J["RMIK completeness and coding review"]
    J --> K["Supervisor approval and debrief"]
```

The combined validation checkpoint may refine this order, but it must preserve one shared patient and encounter context across all participating roles.

## 5. Intended users

- Students acting within assigned professional roles
- Lecturers and clinical supervisors
- Simulation facilitators and scenario authors
- Registration and RMIK teaching roles
- Pharmacy teaching roles
- System administrators and authorized auditors

Access is not based on job title alone. It must also consider the course, cohort, simulation session, assigned patient, encounter, discipline, supervision relationship, and document sensitivity.

## 6. Pilot success criteria

The first pilot is successful when:

1. one synthetic patient completes the outpatient journey without switching to disconnected mock applications;
2. each of the four programs can perform its authorized work and see the information needed from earlier steps;
3. student entries preserve author, time, role, state, and revision history;
4. lecturers can request corrections and approve work without overwriting the original entry;
5. unauthorized role and context combinations are denied and tested;
6. RMIK can identify incomplete or unsigned documentation and complete coding review;
7. learners and instructors can debrief the full event history;
8. the interface clearly identifies the system and data as simulation;
9. the workflow passes faculty acceptance using an agreed test scenario;
10. a deployment and rollback rehearsal succeeds on staging.

## 7. Explicit non-goals for the pilot

- Real patient care or real patient records
- Production BPJS, SATUSEHAT, payment, laboratory, or other clinical integration
- Complete inpatient, emergency, operating-theatre, intensive-care, nutrition, psychology, or physiotherapy modules
- Recreating every menu from the legacy mock-up
- Migrating legacy code, database records, credentials, or browser-local configuration
- Advanced executive dashboards before trustworthy transactional data exists
- Mobile applications or offline synchronization

## 8. Working principles

- Build vertical patient journeys, not isolated screens.
- Use synthetic data from the first test to the classroom environment.
- Make student supervision part of the domain model.
- Preserve provenance and amendments; do not silently overwrite clinical records.
- Display simulation, sandbox, and future production states honestly.
- Validate clinical workflow with the relevant program representatives.
- Release incrementally through reviewed GitHub changes and tested staging deployments.
- Keep architecture proportional to the team: begin with a modular monolith and revisit only when measured constraints justify it.

## 9. Recorded decisions

| ID | Decision | Status |
|---|---|---|
| D-001 | The initial product is a teaching/simulation platform using synthetic patients. | Approved |
| D-002 | The first complete vertical slice is outpatient care. | Approved |
| D-003 | Student documentation uses draft, review, correction, and approval states. | Approved |
| D-004 | Medicine, nursing, RMIK, and pharmacy participate in the first pilot. | Approved |
| D-005 | Nutrition, psychology, and physiotherapy enter after the common foundation is proven. | Approved |
| D-006 | The legacy system is reference material, not a migration source or remediation workstream. | Approved |
| D-007 | Campus production hosting is TBD; Vercel + Supabase is the current free synthetic demo; shared PHP hosting such as Hostinger remains one possible future option pending IT decision and preflight. | Approved in principle; campus target pending |

## 10. Reference-build operating model

Daniel Happy Putra is the sole project manager/PIC and has final authority over product scope, priorities, acceptance, and release decisions. Codex is delegated to independently research, design, implement, test, document, and prepare GitHub changes within this charter. Codex does not hold institutional, clinical, privacy, or legal approval authority.

The project will not wait for separate blank-sheet requirements interviews with every program. It will build one evidence-grounded outpatient reference model and collect multidisciplinary corrections against that concrete model at three checkpoints:

1. workflow-baseline review;
2. end-to-end user acceptance;
3. faculty-pilot readiness.

| Governance responsibility | Responsible party during reference build | Decision position |
|---|---|---|
| Scope, priority, design acceptance, and release | Daniel Happy Putra | Final authority |
| Research, product design, engineering, tests, documentation, and GitHub execution | Codex, delegated by Daniel | Recommends and executes; no institutional approval authority |
| Clinical and professional-workflow validation | Relevant medicine, nursing, RMIK, and pharmacy reviewers when available | Advisory evidence; mandatory before claiming clinical validity |
| Teaching usefulness and competency alignment | Designated course/program reviewers when available | Advisory evidence; required before a graded faculty pilot |
| Institutional privacy, security, and deployment approval | Designated university authorities | Required at the applicable pilot/real-data gate |

Stakeholder names are not required to begin the reference build. Daniel may appoint or invite reviewers when a checkpoint is ready.

## 11. Information still to collect

- Names or roles of checkpoint reviewers when Daniel is ready to invite them
- Target student semester and competence level for each program
- Expected number of simultaneous students and classes
- Typical class/simulation duration
- Existing forms, SOPs, rubrics, or course outcomes that should inform the outpatient case
- Available combined validation-session date and format
- Campus IT hosting inventory and environment capabilities when available

## References

- [Current hosting posture](operations/CURRENT_HOSTING_POSTURE.md)
- [Campus master plan](SIMRS_CAMPUS_MASTER_PLAN.md)
- [Project start checklist](PROJECT_START_CHECKLIST.md)
- [Legacy assessment](LEGACY_ASSESSMENT.md)
- [ADR-001](adr/ADR-001-REBUILD-ARCHITECTURE.md)
- [Outpatient evidence register](research/OUTPATIENT_EVIDENCE_REGISTER.md)
- [Outpatient service blueprint](product/OUTPATIENT_SERVICE_BLUEPRINT.md)
- [Outpatient assumption and validation register](product/ASSUMPTION_AND_VALIDATION_REGISTER.md)
