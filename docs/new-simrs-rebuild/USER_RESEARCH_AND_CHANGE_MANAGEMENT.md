# User Research and Change Management

**Core strategy:** build a workflow-faithful baseline first, let users perform the work, then use their experience to decide what should change. The old system supplies evidence of scope and operating intent; users supply the evidence needed to improve the new experience.

## 1. Purpose and operating posture

The new system is a campus teaching and simulation platform, initially using synthetic patients and supervised student work. It is not a live clinical deployment. User research must therefore learn two things at once:

1. whether the rebuilt system can support the required hospital work and handoffs represented by the old system; and
2. after parity is accepted, what users need to be clearer, faster, safer, more teachable, or different.

Do not ask users to choose an abstract menu list before they have a concrete patient journey. Use the full-SIMRS workflow model and a working reference build as the discussion object.

## 2. Users, stakeholders, and decision rights

| Group | What they need to accomplish | Research/adoption role |
|---|---|---|
| Students: medicine | Assessment, diagnosis/problem, orders, procedures, referrals, plan and handoff | Task testers; explain learning friction and competence boundaries |
| Students: nursing | Intake, safety screen, care plan, observations, interventions, handover, education | Task testers; validate sequence, required information and supervision |
| Students: RMIK | Identity quality, encounter assembly, coding, completeness, amendment, reporting and disclosure exercises | Task testers; validate provenance and record-quality work |
| Students: pharmacy | Verification, dispensing, counselling simulation, stock/return and medication handoff | Task testers; validate medication and inventory flow |
| Future disciplines | Nutrition, psychology, physiotherapy and support services | Later discovery and staged pilot participants |
| Lecturers/supervisors | Assign roles, observe, review, correct, approve, score and debrief | Acceptance owners for teaching safety and learning value |
| Facilitators/scenario authors | Seed/reset cases, inject events, monitor sessions and capture evidence | Operational owners of pilot execution |
| Registration/front office | Identity, appointment, payer/referral, queue and encounter creation | Workflow/parity validators where included |
| Clinical/diagnostic departments | Clinical, nursing, laboratory, radiology, surgery, blood, ambulance, mortuary workflows | Subject-matter reviewers for later increments |
| Pharmacy/GF/cashier/claims/RM | Handoffs, ledger, billing, coding, claim and reporting outcomes | Cross-module workflow owners |
| IT/security/privacy/records | Boundaries, access, audit, continuity, environment and data controls | Gatekeepers; not optional reviewers for real data or deployment |

UEU must formally appoint the product owner and final scope/priority/acceptance authority before delivery begins. Daniel may fill that role if UEU confirms it, but this planning document does not assign institutional authority. Workflow reviewers provide evidence and recommendations; they do not create an uncontrolled parallel requirements system.

## 3. Research stages

### Stage A — Workflow-baseline review (before build completion)

Purpose: test whether the reference model represents the old system's necessary work, not whether the new UI is attractive.

Activities:

- Walk through one synthetic outpatient case across registration, nursing, medicine, pharmacy and RMIK.
- Review the full-system workflow catalogue: emergency, inpatient, diagnostics, surgery/IBS, pharmacy/GF, billing, claims, reports and administration.
- Ask each workflow owner to identify required inputs, outputs, decisions, handoffs, exceptional states, signatures, reports and role restrictions.
- Mark evidence as observed, manual-documented, inferred, or unknown; do not convert an inference into a requirement without a decision.
- Confirm the baseline scope and acceptance scenarios.

Output: approved reference journey, role/context matrix, parity traceability matrix, unknowns register, and named reviewers.

### Stage B — End-to-end parity UAT

Purpose: establish whether users can complete the required baseline work.

- Use controlled synthetic fixtures and release-candidate builds.
- Give testers realistic tasks rather than a screen tour.
- Record pass/fail, evidence, parity defects, ambiguities, and improvement observations separately.
- Ask “Could you complete the work and hand it off?” before asking “What would you redesign?”
- Resolve contradictory expectations through the workflow owner/product owner and record the decision.

Output: parity sign-off or an explicit gap list with owners and release disposition.

### Stage C — Supervised pilot and debrief

Purpose: see how the accepted baseline behaves in a real teaching session.

- Run a small class/cohort with synthetic scenarios, unique accounts, assigned roles, supervisor review, reset, and facilitator support.
- Observe queue handoffs, incomplete work, confusion, workarounds, time-on-task, unauthorized attempts, and debrief quality.
- Do not silently modify the baseline during a pilot. Log a change request, apply a safe workaround, or pause if a safety/security issue occurs.
- Hold a same-day debrief and a delayed follow-up after users have reflected.

Output: pilot readiness decision, adoption baseline, improvement backlog, training changes, and risk updates.

### Stage D — Post-parity improvement discovery

Purpose: capture what users want to change after trying the system.

Methods may include task replay, contextual interview, observation, short survey, card sort of work queues, report review, and structured debrief. Ask users to demonstrate the problem using a synthetic case and describe the desired outcome, not only a preferred button.

Output: prioritised improvement backlog linked to evidence, affected roles, workflow, learning outcome, risk, effort, and acceptance test.

### Stage E — Continuous adoption review

Review metrics, support themes, training completion, parity regressions, and improvement outcomes each release. Retire features or training that create unsafe workarounds; do not measure adoption by login count alone.

## 4. Research methods and session protocol

### Recruitment and sampling

For each checkpoint, include users from every role that touches the journey, including at least one novice and one experienced representative where available. Include supervisors/facilitators and one person responsible for record quality or privacy. If a department cannot attend, record it as a limitation and do not claim that department's workflow is validated.

### Session preparation

- Publish purpose, duration, role, scenario, data boundary, recording policy, and what decisions the session can make.
- Provide accounts with least privilege and a synthetic scenario assignment; never use shared personal credentials.
- Prepare a facilitator script, task cards, expected handoffs, observation sheet, issue form, reset plan, and stop criteria.
- Announce whether the session is parity testing or improvement discovery. Do not mix the two without labels.

### Session flow

1. Brief participants: this is a simulation, not clinical care; explain roles, success criteria, recording/consent, and how to report uncertainty.
2. Ask the participant to narrate the goal and perform the task; avoid teaching the intended click path.
3. Observe what they do, what information they seek, what they skip, where they hesitate, and what workaround they invent.
4. Ask neutral probes: “What were you expecting?”, “What would you do next in the old workflow?”, “What information was missing?”, “What would make this unsafe?”
5. Capture objective evidence: time, errors, retries, denied actions, handoff delays, record state, and facilitator intervention.
6. Conduct a short debrief: parity result first; improvements and new ideas second.
7. Reset the scenario and verify no unintended data or external event remains.

### Privacy and research records

Use synthetic data, minimise participant personal data, obtain permission before recording, restrict recordings/notes, and define retention. Do not copy clinical-looking rows or screenshots into public issue trackers. Mark quotes as participant-reported, not as independent proof of system behaviour.

## 5. Separating parity, usability, and new needs

Every observation is classified before prioritisation:

| Type | Question | Example handling |
|---|---|---|
| Parity defect | Can the role complete an agreed old-system capability or necessary handoff? | Fix, clarify, or formally defer before parity sign-off |
| Safety/security defect | Could this cause unauthorised access, wrong patient, wrong result, wrong medication, wrong charge, lost provenance, or unsafe external transmission? | Stop/pause; escalate immediately |
| Usability friction | The task is possible but confusing, slow, or error-prone | Improvement backlog with observed evidence |
| Teaching gap | The feature works but does not make the workflow or learning objective understandable | Teaching/design improvement; validate with supervisor |
| New requirement | User asks for capability absent from the agreed baseline | Discovery, scope, and impact assessment |
| Preference | A personal layout or wording preference without material task impact | Batch for design review; do not automatically prioritise |

An improvement item must state: user/role, situation, evidence, desired outcome, workflow impact, safety/privacy impact, learning impact, dependencies, effort/uncertainty, and measurable acceptance criteria.

## 6. Change-management plan

### Before first pilot

- Name a champion/facilitator for each participating program.
- Publish the product boundary: synthetic-only, simulation watermark, supervised work, and no production integrations.
- Provide a short orientation, role/task map, scenario guide, privacy rules, and support channel.
- Train the “why” of handoffs and states (draft, review, correction, approval), not only where buttons are.
- Provide a sandbox practice period and a resettable “safe failure” case.
- Brief supervisors on how to review, request correction, preserve provenance, and debrief.

### During pilot

- Use office hours or a facilitator channel with response ownership and escalation levels.
- Keep quick-reference guides for the first task in each role and common recovery actions.
- Maintain a visible known-issues list with workaround and expected resolution; do not hide defects from learners.
- Monitor adoption and safety indicators daily during a concentrated pilot.

### After pilot

- Publish a short release note: what stayed the same for parity, what changed, known limits, and what is being evaluated.
- Demonstrate improvements back to the users who reported them and record whether the outcome improved.
- Refresh training from observed mistakes and add regression scenarios for changed behaviour.
- Retire temporary workarounds only after the underlying issue is closed and users are informed.

## 7. Improvement backlog prioritisation

Use a transparent score rather than voting alone. A suggested 1–5 scoring model is:

`Priority = (safety/privacy/compliance x 4) + (workflow/learning impact x 3) + (frequency/evidence x 2) + (adoption/friction x 1) - (effort/uncertainty x 1)`

The score supports conversation; it does not override a safety stop or a legal/institutional gate. Prioritise:

1. safety, privacy, authorization, provenance, and data-integrity fixes;
2. blockers to a complete end-to-end handoff or learning objective;
3. repeated high-impact usability friction evidenced across roles;
4. improvements that remove duplication, re-keying, or confusing states;
5. reporting/analytics enhancements after transactional data is trustworthy;
6. aesthetic or preference changes with lower risk and broad benefit.

Each item has one owner, a target release, dependencies, acceptance test, and a status: `NEW`, `TRIAGED`, `READY`, `IN_PROGRESS`, `PILOT`, `ACCEPTED`, `DEFERRED`, or `REJECTED` with reason.

## 8. Adoption and outcome metrics

Collect metrics by role, cohort, scenario, release, and environment; do not compare users as individuals without context.

### Readiness metrics

- percentage of critical role/workflow scenarios with trained users;
- supervisor/facilitator readiness and support coverage;
- synthetic fixture/reset success rate;
- open P0/P1 issues and unresolved parity questions;
- UAT completion and evidence completeness.

### Behaviour and usability metrics

- task completion rate without facilitator intervention;
- median time and error/retry count for key tasks;
- successful handoff rate and queue aging;
- percentage of drafts reaching review/approval;
- correction requests that preserve original provenance;
- unauthorized-attempt detection and correct denial;
- help requests, workaround frequency, and repeat issue themes.

### Teaching and system outcomes

- percentage of journeys completed end-to-end;
- learner ability to explain information flow and downstream effects in debrief;
- rubric attainment for role-specific competencies;
- supervisor confidence in auditability and feedback;
- report/charge/stock/coding reconciliation for synthetic cases;
- regression rate after improvements;
- improvement adoption and user-reported outcome change.

Do not use logins, page views, or time spent alone as success metrics. A short session with a complete, correct handoff is more valuable than high activity with broken work.

## 9. Governance cadence and escalation

| Cadence | Participants | Output |
|---|---|---|
| Each test session | Facilitator, testers, QA | UAT record, defects, observations, stop decisions |
| Daily during pilot | Facilitator, champions, QA/support | Safety/adoption summary, workarounds, escalation |
| Weekly while building | Product owner, workflow owners, engineering, QA | Parity status, risk review, improvement triage |
| Each release | Product owner, security/privacy/IT as applicable | Go/no-go, release notes, training/support update |
| Monthly after pilot | Product owner, programs, support, reviewers | Adoption trends, learning outcomes, backlog reprioritisation |

Escalate immediately for real-data exposure, unassigned-record access, unsafe medication/clinical/financial/stock outcome, lost audit trail, external production transmission, or inability to restore/rollback. Pause the affected session and preserve evidence.

## 10. Deliverables

Maintain these artefacts in the project documentation:

- stakeholder and role map;
- research plan and consent/recording note;
- task scripts and scenario fixtures;
- observation/UAT records;
- parity traceability matrix;
- improvement backlog and prioritisation decisions;
- training and quick-reference guides;
- support/escalation log;
- adoption dashboard and pilot report;
- release notes showing parity baseline versus improvements.

References: [project charter](../PROJECT_CHARTER.md), [master plan](../SIMRS_CAMPUS_MASTER_PLAN.md), [full workflow model](../vendor-simrs-assessment-2026-08-21/FULL_SIMRS_WORKFLOW_MODEL.md), and [testing/UAT strategy](TESTING_AND_UAT_STRATEGY.md).
