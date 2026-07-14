# SIMRS Campus UEU — Project Start Checklist

**Purpose:** convert the approved master plan into a safe, testable first increment without rushing into disconnected screens.

## Gate 0 — Contain the legacy deployment

This is the first operational priority because the existing public site has security and truthfulness issues.

- [ ] Rotate the database credential exposed in the legacy source and deployment history.
- [ ] Remove public/default login credentials.
- [ ] Restrict access to authorized faculty and students.
- [ ] Add a persistent `SIMULATION — SYNTHETIC DATA ONLY` banner.
- [ ] Require server-side authentication and authorization for every API operation.
- [ ] Restrict CORS to the exact application origin.
- [ ] Confirm that the legacy database contains no real patient data.
- [ ] Preserve a backup and immutable snapshot before remediation.

## Gate 1 — Approve the product boundary

The steering group must record decisions on:

- [ ] simulation-only initial operation using synthetic patients;
- [ ] outpatient care as the first complete learning journey;
- [ ] student drafts with explicit instructor/supervisor review and preserved revisions;
- [ ] Hostinger as a conditional initial host, with documented migration triggers;
- [ ] Indonesian as the initial clinical UI language and the scope of English terminology;
- [ ] programs participating in the first case and their named representatives.

**Exit evidence:** ADR-001 is accepted or replaced, and decision owners are named.

## Gate 2 — Establish multidisciplinary governance

- [ ] Name one product owner with final scope authority.
- [ ] Name a technical lead and privacy/security owner.
- [ ] Nominate one workflow representative from medicine, nursing, RMIK, pharmacy, nutrition, psychology, and physiotherapy.
- [ ] Define who approves clinical logic, teaching logic, terminology, visual design, privacy, and releases.
- [ ] Agree meeting cadence, decision log format, and change-control process.

**Exit evidence:** governance roster and decision-rights table are approved.

## Gate 3 — Design one shared outpatient scenario

Run a facilitated workshop around one synthetic patient rather than asking each program for an independent menu wish list.

- [ ] Define learning outcomes and learner levels.
- [ ] Map registration, triage, medical assessment, orders, results, prescription, dispensing, payment simulation, coding, completeness review, and encounter closure.
- [ ] Identify each profession’s inputs, required information, handoffs, competence limits, and supervisor actions.
- [ ] Record normal flow, exceptions, amendments, cancellations, late results, and access restrictions.
- [ ] Agree the minimum data set and controlled terminology for the slice.
- [ ] Turn the journey into acceptance scenarios and role-policy tests.

**Exit evidence:** one signed-off service blueprint and acceptance-test catalogue.

## Gate 4 — Validate infrastructure and delivery

- [ ] Confirm the Hostinger plan supports the required PHP version and extensions.
- [ ] Verify SSH restrictions, Composer strategy, cron/queue behavior, private storage, database backups, logs, TLS, staging subdomain, symlinks/release directories, and recovery.
- [ ] Create separate staging and teaching-production databases, keys, storage, and hostnames.
- [ ] Record the migration trigger to a VPS or managed platform.
- [ ] Protect `main`, require pull requests, enable automated checks, and require production environment approval.
- [ ] Test a harmless staging deployment and rollback before application development.

**Exit evidence:** written preflight result and a verified deployment/rollback rehearsal.

## Gate 5 — Prepare the foundation backlog

Only after Gates 0–4 should implementation begin.

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

- [Campus master plan](SIMRS_CAMPUS_MASTER_PLAN.md)
- [Legacy assessment](LEGACY_ASSESSMENT.md)
- [ADR-001](adr/ADR-001-REBUILD-ARCHITECTURE.md)

