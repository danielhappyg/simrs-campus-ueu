# SIMRS Campus UEU — Project Start Checklist

**Purpose:** convert the approved master plan into a safe, testable first increment without rushing into disconnected screens.

## Gate 0 — Contain the legacy deployment

**Disposition:** closed as outside the new-project scope on 2026-07-15.

The product owner confirmed that the existing public application is only a mock-up. It will not be repaired, migrated, or used as the technical foundation.

- [x] Classify the application as non-production reference material.
- [x] Exclude its code, database, credentials, and local browser state from migration.
- [x] Keep the new GitHub repository independent from the legacy folder.
- [x] Record useful legacy screens only as requirements evidence.

If the mock-up is ever proposed for real data or operational use, it must undergo a separate security and privacy review first.

## Gate 1 — Approve the product boundary

The product owner has recorded decisions on:

- [x] simulation-only initial operation using synthetic patients;
- [x] outpatient care as the first complete learning journey;
- [x] student drafts with explicit instructor/supervisor review and preserved revisions;
- [x] Hostinger as a conditional initial host, with documented migration triggers;
- [x] Indonesian as the initial clinical UI language, with recognized clinical/technical terminology where appropriate;
- [x] medicine, nursing, RMIK, and pharmacy participating in the first pilot.

**Exit evidence:** the [project charter](PROJECT_CHARTER.md) records the approved product boundary. ADR-001 remains proposed until the technical preflight and deciders are confirmed.

## Gate 2 — Establish reference-build governance

- [x] Name Daniel Happy Putra as product owner with final scope and priority authority.
- [x] Record Daniel as sole project manager/PIC and final acceptance/release authority.
- [x] Delegate autonomous research, design, implementation, testing, documentation, and GitHub execution to Codex within the approved charter.
- [x] Define a three-checkpoint validation model instead of requiring separate up-front program interviews.
- [ ] Identify the combined medicine, nursing, RMIK, pharmacy, and teaching reviewers before Checkpoint 1.
- [ ] Identify the institutional privacy/security/deployment reviewer before the faculty-pilot gate.

**Exit evidence:** the [project charter](PROJECT_CHARTER.md) and [role matrix](product/OUTPATIENT_ROLE_MATRIX.md) record decision rights. Reviewer names may be added when each checkpoint is scheduled.

## Gate 3 — Design one shared outpatient scenario

Build a concrete reference journey around one synthetic patient, then validate it in a combined session rather than asking each program for an independent menu wish list.

- [ ] Validate learning outcomes and learner levels at Checkpoint 1.
- [x] Map registration, nursing intake/safety screen, medical assessment, orders, results, prescription, dispensing, coding, completeness review, and encounter closure.
- [x] Identify each profession’s inputs, required information, handoffs, competence limits, and supervisor actions.
- [x] Record normal flow, exceptions, amendments, cancellations, late results, and access restrictions.
- [x] Define the reference minimum data set and controlled-terminology boundaries.
- [x] Turn the journey into acceptance scenarios and role-policy tests.
- [ ] Record Daniel's acceptance of the baseline and the Checkpoint 1 corrections.

**Exit evidence:** the service blueprint, evidence register, role matrix, data dictionary, validation register, and acceptance-test catalogue form the build baseline; Checkpoint 1 records later clinical/teaching corrections.

## Gate 4 — Validate infrastructure and delivery

- [x] Implement a read-only, fail-closed runtime and sanitized-evidence preflight command.
- [ ] Confirm the Hostinger plan supports the required PHP version and extensions.
- [ ] Verify SSH restrictions, Composer strategy, cron/queue behavior, private storage, database backups, logs, TLS, staging subdomain, symlinks/release directories, and recovery.
- [ ] Create separate staging and teaching-production databases, keys, storage, and hostnames.
- [ ] Record the migration trigger to a VPS or managed platform.
- [ ] Protect `main`, require pull requests, enable automated checks, and require production environment approval.
- [ ] Test a harmless staging deployment and rollback before application development.

**Exit evidence:** written preflight result and a verified deployment/rollback rehearsal.

## Gate 5 — Prepare the foundation backlog

Foundation implementation may begin once the reference artifacts in Gates 0–3 exist. Staging deployment remains blocked until Gate 4 infrastructure evidence is complete.

The first technical backlog should contain:

1. UEU design tokens and accessible application shell;
2. secure identity, session handling, and contextual authorization policies;
3. organization, program, cohort, course, scenario, and simulation-session context;
4. immutable audit-event foundation;
5. synthetic-patient fixture generator and import guardrails;
6. patient identity, encounter, location, and care-team primitives;
7. health, readiness, logging, backup, and restore checks;
8. automated tests for role denial, provenance, and simulation boundaries.

**Exit evidence:** prioritized issues with owners, acceptance criteria, dependencies, estimates, and milestone assignment.

## Do not do first

- Do not migrate the legacy code into the new runtime.
- Do not recreate every existing menu.
- Do not connect production clinical services.
- Do not load real patient data.
- Do not start pharmacy, inpatient, claims, and dashboards in parallel before the shared patient/encounter foundation works.
- Do not deploy by manually editing files on the server.

## Reference documents

- [Project charter](PROJECT_CHARTER.md)
- [Campus master plan](SIMRS_CAMPUS_MASTER_PLAN.md)
- [Legacy assessment](LEGACY_ASSESSMENT.md)
- [ADR-001](adr/ADR-001-REBUILD-ARCHITECTURE.md)
