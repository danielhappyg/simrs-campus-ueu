# Assumption and Validation Register

- **Version:** 1.0
- **Purpose:** keep unresolved clinical, teaching, operational, and infrastructure choices visible while autonomous construction proceeds
- **Final decision-maker:** Daniel Happy Putra

## 1. Status and gate rules

| Status | Meaning |
|---|---|
| `CONFIRMED` | Daniel has decided the product position. |
| `WORKING ASSUMPTION` | Safe enough to design/build behind configuration; must be reviewed at the named checkpoint. |
| `EVIDENCE NEEDED` | Implementation may scaffold the boundary, but enabling the behavior depends on verified evidence. |
| `DEFERRED` | Explicitly outside the current increment. |
| `REJECTED` | Must not be implemented under the current scope. |

Risk levels:

- `P0`: blocks pilot or creates a serious safety/privacy misrepresentation;
- `P1`: materially affects the end-to-end workflow or teaching validity;
- `P2`: important refinement that can change after the reference slice works;
- `P3`: enhancement or preference.

Autonomous development can continue around `WORKING ASSUMPTION` items when the behavior is configurable, clearly labeled, and does not make a clinical decision. No `P0` item may remain unresolved at faculty-pilot release.

## 2. Confirmed product decisions

| ID | Decision | Status | Decision authority | Evidence |
|---|---|---|---|---|
| DEC-001 | The initial product is a teaching/simulation system using synthetic patient data only. | `CONFIRMED` | Daniel | Project charter D-001 |
| DEC-002 | Outpatient care is the first complete vertical slice. | `CONFIRMED` | Daniel | Project charter D-002 |
| DEC-003 | Medicine, nursing, RMIK, and pharmacy form the initial pilot. | `CONFIRMED` | Daniel | Project charter D-004 |
| DEC-004 | Learner work uses draft, submission, correction, and supervisor approval with preserved versions. | `CONFIRMED` | Daniel | Project charter D-003 |
| DEC-005 | The legacy app is a reference mock-up only; there is no migration requirement. | `CONFIRMED` | Daniel | Project charter D-006 |
| DEC-006 | Indonesian is the primary UI language, with recognized clinical/technical terms where appropriate. | `CONFIRMED` | Daniel | Project charter boundary |
| DEC-007 | Daniel is the sole project manager/PIC and final scope, priority, acceptance, and release authority for the reference build. | `CONFIRMED` | Daniel | Direct product-owner decision |
| DEC-008 | Codex may independently research, design, implement, test, and manage the GitHub workflow, while surfacing assumptions and preserving human clinical/legal validation gates. | `CONFIRMED` | Daniel | Direct product-owner delegation |
| DEC-009 | Stakeholder feedback is concentrated after a concrete model exists, not collected one program at a time before work begins. | `CONFIRMED` | Daniel | Direct product-owner delivery preference |
| DEC-010 | No autonomous diagnosis, treatment recommendation, prescription clearance, or production-integration claim is part of the MVP. | `CONFIRMED` | Daniel/product safety boundary | Evidence Register SAF-003 |

## 3. Clinical and workflow assumptions

| ID | Assumption/question | Risk | Current working position | Validation method and checkpoint | Required before |
|---|---|---:|---|---|---|
| VAL-A01 | What should routine outpatient “triage” mean? | P0 | Use `Asesmen Awal dan Skrining Keselamatan`, not an emergency acuity scale. Record intake facts and a human-authored escalation decision. | Combined medicine/nursing workflow walkthrough; Checkpoint 1. | Faculty pilot |
| VAL-A02 | Which safety-screen questions are appropriate? | P0 | Store a versioned scenario-specific questionnaire; initial content is placeholder fixture data, not a universal protocol. | Nursing and medicine reviewers approve question set and wording; Checkpoint 1. | Faculty pilot |
| VAL-A03 | Which vital-sign thresholds trigger a warning or escalation? | P0 | No clinical thresholds in core code. Optional ranges belong to an approved, versioned ruleset and produce a warning requiring human judgment. | Review source/SOP and approve ruleset owner/version; Checkpoint 1. | Enabling threshold alerts |
| VAL-A04 | Where does an escalated outpatient case go? | P0 | Pause routine flow and route to supervisor/facilitator; allow configured simulated transfer/disposition. | Medicine/nursing agree teaching disposition options; Checkpoint 1. | Faculty pilot |
| VAL-A05 | Which nursing assessment sections are required for the first learner level? | P1 | Minimum intake in the data dictionary; optional sections controlled by scenario. | Map course outcome/semester to required sections; Checkpoint 1. | Faculty pilot |
| VAL-A06 | Which medical assessment structure and learner autonomy apply? | P1 | Standard outpatient history/exam/assessment/plan with all learner content in draft and configurable co-sign requirements. | Medicine reviewer maps competencies and sign-off rules; Checkpoint 1. | Faculty pilot |
| VAL-A07 | What first synthetic case, diagnosis, findings, and follow-up should be used? | P1 | Build a neutral configurable case fixture that exercises the workflow; do not label it faculty-approved. | One joint case-design review; Checkpoint 1. | Faculty pilot content freeze |
| VAL-A08 | Are simulated laboratory/radiology results needed in the first case? | P2 | Support one generic order-result loop; facilitator releases a pre-authored synthetic result. | Daniel decides after reference workflow review; Checkpoint 1. | Scope freeze |
| VAL-A09 | Which prescription and pharmacy issues should the case exercise? | P1 | Use scenario-authored medicine data and at most one pre-authored review/clarification teaching branch; no automated clinical verdict. | Pharmacy/medicine joint review; Checkpoint 1. | Faculty pilot |
| VAL-A10 | Which pharmacy steps require supervisor approval or a second checker? | P1 | Review and final-check capabilities are separate; faculty-pilot profile prevents learner self-approval. | Pharmacy reviewer confirms assessment model; Checkpoint 1. | Faculty pilot |
| VAL-A11 | Which RMIK completeness checklist applies? | P1 | Versioned checklist references required document types, states, authorship, and source links; local/form-specific fields remain configurable. | RMIK reviewer validates checklist; Checkpoint 1. | Faculty pilot |
| VAL-A12 | Which ICD-10/procedure classification version and coding depth should learners use? | P1 | Code system/version mandatory; terminology dataset/version not hard-coded in UI. | RMIK course owner approves curriculum version and permitted lookup source; Checkpoint 1. | Coding exercise release |
| VAL-A13 | Who can reopen/amend content after clinical closure/finalization? | P0 | Only through a correction/amendment request tied to author/supervisor; no direct reopen or overwrite. | Joint RMIK/clinical review; Checkpoint 2. | Faculty pilot |
| VAL-A14 | What correction timing should the simulation enforce? | P1 | Preserve the legal correction concept and timestamps; use configurable teaching window. Do not silently block a valid amendment because a classroom session is shorter. | RMIK/legal-operational interpretation; Checkpoint 2. | Institutional pilot policy |
| VAL-A15 | What public queue identity is permitted? | P1 | Ticket number and minimal masked patient cue only; never display diagnosis. | Privacy/teaching review; Checkpoint 2. | Shared-display use |

## 4. Teaching assumptions

| ID | Assumption/question | Risk | Current working position | Validation method and checkpoint | Required before |
|---|---|---:|---|---|---|
| VAL-T01 | What semester/competence level is the first cohort? | P1 | Make required sections and capabilities scenario-configurable; default to closely supervised novice/intermediate behavior. | Daniel selects target cohort with course owners; Checkpoint 1. | Faculty pilot |
| VAL-T02 | Can one instructor supervise several disciplines during early demos? | P2 | Development/demo account can hold multiple supervisor capabilities; faculty-pilot profile records discipline-specific approval and prevents learner self-approval. | Daniel confirms demo staffing; Checkpoint 2. | UAT/pilot profile |
| VAL-T03 | What learner scoring rubric is required? | P2 | Record workflow evidence and rubric references; defer automated scoring. | Cross-program review of learning outcomes; Checkpoint 2. | Graded pilot |
| VAL-T04 | Should the system reveal all case data immediately? | P1 | Scenario events and results can be time/release gated; default learner view exposes only role-appropriate available information. | Facilitator/course review; Checkpoint 1. | Faculty pilot |
| VAL-T05 | How long are sessions and how many simultaneous students? | P1 | Avoid design assumptions tied to one class size; collect telemetry/load-test targets once Daniel provides expected scale. | Capacity questionnaire and test; Checkpoint 2. | Deployment sizing |
| VAL-T06 | What should a debrief show? | P2 | Chronological event history, handoffs, versions, review actions, and selected learning objectives; no hidden surveillance scoring. | Instructor UAT; Checkpoint 2. | Faculty pilot |

## 5. Product, design, and terminology assumptions

| ID | Assumption/question | Risk | Current working position | Validation method and checkpoint | Required before |
|---|---|---:|---|---|---|
| VAL-U01 | Which UEU logo variant and brand usage rules are authoritative? | P1 | Derive an accessible clinical theme from the supplied logo as visual reference; do not invent formal brand claims. | Daniel approves design-system specimen; design review before UI build. | Design freeze |
| VAL-U02 | Which Indonesian clinical labels are preferred locally? | P1 | Use current official terms and a centralized glossary; preserve code-system displays separately. | Combined terminology walkthrough; Checkpoint 1/2. | Faculty pilot |
| VAL-U03 | Must the first release support mobile phones? | P2 | Responsive for basic access, but primary clinical work optimized for desktop/tablet; no native app. | Daniel approves breakpoint demos. | UI acceptance |
| VAL-U04 | What accessibility target applies? | P1 | WCAG 2.2 AA as the product target for core workflows, including keyboard and non-color status cues. | Automated plus manual accessibility review; Checkpoint 2. | Faculty pilot |
| VAL-U05 | Which documents must print/export? | P2 | Begin with outpatient summary and debrief/audit-friendly report, all watermarked as simulation. | Daniel selects minimum exports; Checkpoint 2. | Pilot release |

## 6. Infrastructure and governance evidence needed

| ID | Question | Risk | Current position | Evidence/decision needed | Required before |
|---|---|---:|---|---|---|
| VAL-I01 | Does the actual Hostinger plan support the selected PHP runtime, extensions, SSH, deployment directories, cron/queue behavior, private storage, and backup/restore? | P0 | Architecture remains Hostinger-compatible in principle; deployment is not assumed proven. | Read-only account preflight plus harmless staging deployment/rollback. | Staging deployment |
| VAL-I02 | What staging/production-like hostnames and database separation are available? | P1 | Require separate staging and teaching-pilot configuration/data. | Hosting inventory approved by Daniel. | Deployment pipeline |
| VAL-I03 | Can GitHub environment approval and protected branches be enforced on the current private-repository plan? | P2 | CI/PR workflow remains mandatory; document plan limitations and use repository settings available. | Recheck GitHub plan/settings as needed. | Release process |
| VAL-I04 | Who holds institutional privacy/security approval for a faculty pilot? | P0 | Daniel owns project decisions; institutional approval role remains separate and can be appointed near Checkpoint 3. | Named institutional reviewer or written determination. | Institutional faculty pilot |
| VAL-I05 | What retention/reset policy applies to learner activity and synthetic sessions? | P1 | Preserve sessions through development/UAT; implement configurable lifecycle without silent deletion. | Daniel plus institutional teaching/privacy decision. | Faculty pilot |

## 7. Explicitly deferred or rejected

| ID | Item | Status | Revisit trigger |
|---|---|---|---|
| OUT-001 | Real patient records or clinical treatment use | `REJECTED` for MVP | Separate institutional clinical, privacy, security, legal, and operational program |
| OUT-002 | Production SATUSEHAT/BPJS connectivity | `DEFERRED` | Formal access, governance, sandbox validation, and production readiness |
| OUT-003 | Emergency-department triage module | `DEFERRED` | Outpatient vertical slice accepted; separate emergency workflow research |
| OUT-004 | Inpatient, surgery, intensive care, nutrition, psychology, and physiotherapy workflows | `DEFERRED` | Shared foundation and outpatient flow proven |
| OUT-005 | Autonomous clinical decision support | `REJECTED` for reference MVP | Separate evidence, safety case, governance, validation, and regulatory review |
| OUT-006 | Complete billing/INA-CBG claim engine | `DEFERRED` | Validated clinical and coding foundations plus formal scope |
| OUT-007 | Legacy code/data migration | `REJECTED` | None under current charter |

## 8. Checkpoint agendas

### Checkpoint 1 — one combined workflow review

Review a clickable/reference journey rather than blank-sheet menu requests:

- patient and scenario assumptions;
- intake/safety screen and escalation;
- medical assessment and supervision;
- order/result branch;
- prescription review/dispense branch;
- closure, completeness, coding, and amendment;
- Indonesian terminology and required learner fields.

Output: corrections classified as must-change-before-build, must-change-before-pilot, or later enhancement. Daniel records final decisions.

### Checkpoint 2 — end-to-end UAT

Participants complete the same synthetic case in assigned roles. Collect only evidence tied to failed scenarios, unsafe ambiguity, missing data, role violations, or learning-outcome gaps. Daniel decides the corrective scope.

### Checkpoint 3 — pilot readiness

Review automated/manual test results, unresolved P0/P1 assumptions, access model, synthetic-data guardrails, accessibility, backup/restore, staging deployment/rollback, facilitator guide, and limitations. Approval is for a teaching pilot only.

## 9. Decision-record rule

When an assumption is resolved:

1. retain the original question;
2. change its status and record the decision/date/decider;
3. link the source or validation evidence;
4. update affected requirements, data definitions, screens, and acceptance scenarios;
5. record material architecture choices in an ADR; and
6. never rewrite an assumption as if it was always known.
