# Outpatient Role and Permission Matrix

- **Version:** 1.0 reference baseline
- **Authorization posture:** server-enforced, contextual, least privilege
- **Final product authority:** Daniel Happy Putra

## 1. Operating model

During the reference-build phase, Daniel is the sole project manager/PIC and final authority for scope, priority, design acceptance, and release decisions. Codex operates as the delegated research, product-design, engineering, testing, and GitHub execution agent under those decisions. It has no institutional, clinical, privacy, or legal approval authority.

Faculty and operational representatives are consulted at three concentrated checkpoints rather than being required for every construction decision:

1. workflow-baseline validation;
2. end-to-end user acceptance;
3. pilot-readiness approval.

Daniel can accept, reject, defer, or request changes to stakeholder input. Real-patient use would require separate institutional governance and cannot be authorized by this operating model.

## 2. Authorization dimensions

A permission decision is never based on a role name alone. The policy evaluates:

- authenticated account and active status;
- affiliation: learner, lecturer/supervisor, facilitator, operational teaching role, auditor, or administrator;
- program/profession and application role;
- organization and location;
- course, cohort, and simulation session;
- assigned patient and encounter;
- enabled competency or task;
- supervision relationship;
- document sensitivity, authorship, and lifecycle state;
- environment mode (`SIMULATION` or approved `SANDBOX` only).

A route hidden from navigation remains protected by the same back-end policy.

## 3. Reference roles

| Role ID | Indonesian label       | Purpose                                                                                             | Default scope                                                         |
| ------- | ---------------------- | --------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- |
| PO      | Pemilik Produk / PIC   | Final scope, priority, acceptance, and release decisions.                                           | Project and release metadata; no automatic clinical-record privilege. |
| FAC     | Fasilitator Simulasi   | Creates/starts sessions, assigns roles, injects case events, pauses/resets runs, and opens debrief. | Sessions explicitly facilitated.                                      |
| REG-L   | Peserta Registrasi     | Performs patient search, synthetic registration, appointment check-in, and queue placement.         | Assigned session, clinic, patient/encounter.                          |
| NUR-L   | Mahasiswa Keperawatan  | Performs nursing intake and safety screen and submits a draft.                                      | Assigned encounter and enabled nursing task.                          |
| MED-L   | Mahasiswa Kedokteran   | Performs medical assessment, diagnosis/plan, orders, prescription, and closure draft.               | Assigned encounter and enabled medical task.                          |
| PHA-L   | Mahasiswa Farmasi      | Reviews prescription, records interventions, and performs simulated dispensing.                     | Assigned encounter/pharmacy queue and enabled task.                   |
| RMIK-L  | Mahasiswa RMIK         | Reviews identity quality, completeness, assembly, and coding.                                       | Assigned record-review queue.                                         |
| NUR-S   | Supervisor Keperawatan | Reviews, requests correction, and approves nursing learner work.                                    | Learners/sessions under supervision.                                  |
| MED-S   | Supervisor Kedokteran  | Reviews medical work, responds to pharmacy intervention, and approves closure.                      | Learners/sessions under supervision.                                  |
| PHA-S   | Supervisor Farmasi     | Reviews and approves pharmacy work according to scenario policy.                                    | Learners/sessions under supervision.                                  |
| RMIK-S  | Supervisor RMIK        | Reviews identity/completeness/coding work and approves final record-quality outcome.                | Learners/sessions under supervision.                                  |
| AUD     | Auditor Pembelajaran   | Reads assigned finalized event history and debrief evidence without editing it.                     | Explicitly granted sessions and minimum necessary content.            |
| SYS     | Administrator Sistem   | Configures accounts, roles, reference data, and system health.                                      | Configuration only; no default unrestricted chart access.             |

In development fixtures, one named synthetic instructor account may hold multiple supervisor capabilities, but each action records the capability under which it was performed.

## 4. Workflow capability matrix

Legend: `D` create/edit own draft, `S` submit, `R` read when context allows, `A` approve/attest, `C` request correction, `O` operate state transition, `—` denied by default.

| Capability                                  | FAC | REG-L | NUR-L | MED-L | PHA-L | RMIK-L | Discipline supervisor | AUD | SYS |
| ------------------------------------------- | --: | ----: | ----: | ----: | ----: | -----: | --------------------: | --: | --: |
| Start/pause/end assigned simulation session |   O |     — |     — |     — |     — |      — |                     R |   R |   — |
| Assign learner/supervisor to session        |   O |     — |     — |     — |     — |      — |                     R |   — |   — |
| Search assigned synthetic patients          |   R |     R |     R |     R |     R |      R |                     R |   R |   — |
| Create synthetic patient/registration       |   — |   D/S |     — |     — |     — |   D/S¹ |                   R/C |   — |   — |
| Check in and manage clinic queue            |   O |     O |     R |     R |     R |      R |                     R |   R |   — |
| Record nursing intake/safety screen         |   — |     R |   D/S |     R |     R |      R |                  A/C² |   R |   — |
| Record medical assessment/diagnosis/plan    |   — |     R |     R |   D/S |     R |      R |                  A/C² |   R |   — |
| Create order/prescription draft             |   — |     — |     R |   D/S |     R |      R |                  A/C² |   R |   — |
| Release synthetic result                    |  O³ |     — |     R |     R |     R |      R |                  A/C³ |   R |   — |
| Acknowledge result/change plan              |   — |     — |     R |   D/S |     R |      R |                  A/C² |   R |   — |
| Perform prescription review/intervention    |   — |     R |     R |     R |   D/S |      R |                  A/C² |   R |   — |
| Respond to pharmacy intervention            |   — |     — |     R |   D/S |     R |      R |                  A/C² |   R |   — |
| Prepare/check/dispense simulated medicine   |   — |     — |     R |     R |   D/S |      R |                  A/C² |   R |   — |
| Draft encounter disposition/closure         |   — |     — |     R |   D/S |     R |      R |                  A/C² |   R |   — |
| Perform completeness review                 |   — |     R |     R |     R |     R |    D/S |                  A/C² |   R |   — |
| Assign coding draft linked to source        |   — |     — |     R |     R |     R |    D/S |                  A/C² |   R |   — |
| Request clinical correction                 |   — |     — |     — |     — |     — |    D/S |                  A/C² |   R |   — |
| Create amendment to own entry               |   — |     — |   D/S |   D/S |   D/S |    D/S |                  A/C² |   R |   — |
| Finalize simulation record                  |  O⁴ |     — |     — |     — |     — |      S |                    A⁴ |   R |   — |
| View debrief timeline                       | O/R |     R |     R |     R |     R |      R |                     R |   R |   — |
| Author shared debrief note                  | D/S |     — |     — |     — |     — |      — |                    R⁶ |   R |   — |
| View/print assigned finalized reports       |   R |     R |     R |     R |     R |      R |                     R |   R |   — |
| Configure users, roles, and reference data  |   — |     — |     — |     — |     — |      — |                     — |   — |   O |
| Read audit/security logs                    |  R⁵ |     — |     — |     — |     — |      — |                    R⁵ |  R⁵ |  R⁵ |

Notes:

1. RMIK registration capability is granted only when the learner is explicitly assigned the registration task.
2. A supervisor acts only in their discipline and supervision relationship; cross-discipline approval is denied unless a separately configured capability allows it.
3. The MVP facilitator may release pre-authored synthetic results. Future laboratory/radiology roles will replace this shortcut.
4. Finalization requires configured clinical and RMIK approvals. A facilitator can execute the transition but cannot manufacture missing approvals.
5. Audit access is purpose-scoped; configuration privilege does not automatically expose clinical content.
6. The reference profile grants `debrief.write` only to the facilitator. A supervisor can author only when a separate assignment explicitly grants the capability; viewing the note does not imply write authority.
7. Report access requires the separate `report.view` capability, a finalized synthetic encounter, and an exact case assignment or deliberate session-wide facilitator assignment. Print access is not legal-document or disclosure authority.

## 5. Record-level rules

| Rule ID | Rule                                                                                                                                                                                                      |
| ------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| POL-001 | A learner can access a patient only through an active assignment to the relevant simulation session and encounter.                                                                                        |
| POL-002 | A learner can create or edit only a document type enabled for their program, task, and competency configuration.                                                                                          |
| POL-003 | A learner can edit only the latest own `DRAFT`; submitted, approved, and superseded versions are immutable.                                                                                               |
| POL-004 | A supervisor can review only learners and sessions within their recorded supervision relationship.                                                                                                        |
| POL-005 | Approval attaches to an exact content version. A later amendment requires a new review.                                                                                                                   |
| POL-006 | RMIK can inspect authorized chart content and request correction but cannot rewrite another profession's authored clinical statement.                                                                     |
| POL-007 | Pharmacy can record a review and intervention but cannot edit the prescriber's medication request.                                                                                                        |
| POL-008 | Administrators can manage configuration without default chart-reading authority. Temporary support access, if ever introduced, requires a separate reasoned grant and audit.                              |
| POL-009 | A session that is paused, ended, or outside its active time window rejects new learner drafts except through an authorized amendment workflow.                                                            |
| POL-010 | Every allow/deny decision involving clinical content is enforceable in the API and testable by direct request, not only through the UI.                                                                   |
| POL-011 | Export, print, or debrief access obeys the same patient/session scope and carries simulation labeling.                                                                                                    |
| POL-012 | No role can transmit to a production external endpoint in the MVP.                                                                                                                                        |
| POL-013 | Debrief notes are shared, versioned teaching evidence. They cannot mutate clinical sources, create a hidden learner score, or become private surveillance notes in the reference profile.                 |
| POL-014 | A finalized report is an on-demand projection from approved/current sources. It cannot create a divergent clinical source, expose raw audit internals, or imply a signed document or external submission. |

## 6. Separation of duties

The reference MVP supports these controls:

- the author and approver are normally different accounts;
- the prescriber and pharmacist reviewer are distinct capabilities;
- a learner cannot approve their own work even if another role is accidentally assigned;
- a system administrator cannot use configuration access as clinical access;
- a coder cannot create the source diagnosis they code;
- finalization requires completed source approvals and record-quality review;
- any development-only multi-role exception is visibly labeled and disabled in the faculty-pilot profile.

## 7. Governance decision rights

| Decision                                  | Daniel                                                 | Codex execution agent                                | Faculty/operational reviewers           | Institutional authority                     |
| ----------------------------------------- | ------------------------------------------------------ | ---------------------------------------------------- | --------------------------------------- | ------------------------------------------- |
| Reference-MVP scope and priority          | **Final decision**                                     | Recommend and implement                              | Consulted at checkpoints                | Informed as arranged by Daniel              |
| Product workflow and UX baseline          | **Accept/reject**                                      | Research, design, test                               | Validate/correct                        | —                                           |
| Technical architecture and implementation | **Accept/reject material changes**                     | Design, implement, document, verify                  | Consulted when domain impact exists     | IT review before institutional deployment   |
| Clinical content validity                 | Decide whether to include in simulation after evidence | Structure and flag assumptions; no clinical approval | Validate within professional competence | Required for real-care claim/use            |
| Privacy/security readiness                | Decide reference-MVP posture                           | Implement/test safeguards; report limitations        | Consulted as designated                 | Required before institutional/real-data use |
| GitHub branch/PR/release preparation      | **Release authority**                                  | Execute and verify                                   | Review optional                         | —                                           |
| Faculty pilot                             | **Go/no-go within project**                            | Prepare evidence and deployment                      | Validate usefulness and safety          | Institutional approval where required       |
| Real-patient deployment                   | Cannot be inferred from MVP acceptance                 | Out of scope                                         | Cannot authorize alone                  | **Mandatory separate approval**             |

## 8. Policy test minimum

Every protected capability requires at least:

- one allowed test with the correct role and context;
- one same-role/wrong-session denial;
- one wrong-role/same-session denial;
- one unassigned-patient denial;
- one wrong-document-state denial;
- one server/API test that bypasses UI hiding;
- an audit assertion for material allow/deny actions.

The complete functional scenarios are in [Outpatient Acceptance Scenarios](OUTPATIENT_ACCEPTANCE_SCENARIOS.md).
