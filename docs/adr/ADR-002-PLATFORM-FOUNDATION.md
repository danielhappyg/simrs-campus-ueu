# ADR-002: Same-Origin Platform Foundation

- **Status:** Accepted for the outpatient reference MVP
- **Date:** 2026-07-15
- **Decision owner:** Daniel Happy Putra, project manager/PIC
- **Scope:** First-party web platform, identity, simulation safety, teaching context, audit, and delivery checks

## Context

ADR-001 selects a Laravel modular monolith and React frontend. The implementation still needed a precise first-party delivery model, dependency baseline, authorization context, browser/API boundary, identifier policy, and simulation guard before any patient or encounter module could be safely added.

The platform must remain affordable on the likely Hostinger topology, keep browser and server state consistent, prevent public account creation, support supervised teaching assignments, and avoid implying that the reference environment is ready for real clinical use.

## Decision

Use the official Laravel React starter architecture as a maintained foundation, adapted into the UEU Clinical product rather than retaining starter-kit branding or generic screens.

The accepted baseline is:

- Laravel 13 on a Composer platform target of PHP 8.3;
- React 19 and TypeScript delivered through Inertia 3 and Vite 8;
- Fortify server-side authentication with secure same-origin sessions, CSRF protection, throttling, email verification, two-factor authentication, and passkeys;
- public self-registration disabled; accounts are provisioned by authorized administrators;
- Inertia for the first-party browser experience; versioned REST endpoints are introduced only for approved external, integration, or separately deployed consumers;
- SQLite for local development and isolated tests, with MySQL as the initial hosted database target and a migration smoke test in CI;
- internal numeric database keys plus non-sequential ULID public identifiers for route binding and browser payloads;
- assignment-scoped program, role, capability, cohort, and simulation-session context;
- fail-closed middleware that permits application work only when `APP_MODE=SIMULATION` and synthetic-only enforcement is true;
- immutable audit-event creation through the application model, with request correlation IDs and HMAC-hashed IP addresses;
- self-hosted Atkinson Hyperlegible Next, IBM Plex Sans Condensed, and IBM Plex Mono assets; no runtime font CDN;
- automated pull-request checks with read-only GitHub permissions and pinned action revisions.

## Initial bounded modules

The foundation introduces only these domain boundaries:

1. `Identity`: users and authentication;
2. `Teaching`: scenarios, sessions, assignments, capabilities, and work tasks;
3. `Audit`: append-only application events and request correlation;
4. `Work`: the signed-in user's scoped queue and session context.

Patient, encounter, clinical, RMIK, and pharmacy models are deliberately deferred to the outpatient vertical-slice decision and migrations. The queue may reference synthetic case labels, but it is not yet a patient record.

## Safety and authorization rules

- The server, not the browser, derives visible assignments and tasks.
- Revoked, expired, future, completed, cancelled, paused-session, and other users' tasks are excluded from the work response.
- Internal database identifiers are not shared in authentication or work-queue browser payloads.
- An inactive account is rejected during password authentication and forbidden from protected routes.
- Demo fixtures are opt-in, refuse an unsafe environment, require a locally supplied password, and use only `example.invalid` identities.
- A persistent banner and environment label appear on authenticated and unauthenticated screens.
- The implementation does not claim clinical validation, legal approval, production interoperability, or real-care readiness.

## Consequences

### Positive

- First-party pages share server authorization and validation without duplicating an API authentication surface.
- The application can add outpatient modules incrementally while keeping one transactional boundary.
- Public identifiers and explicit browser serializers reduce accidental leakage of internal keys.
- Synthetic fixtures and account provisioning are testable safety controls rather than documentation-only promises.
- The UI has a distinct, accessible UEU identity before clinical forms are added.

### Costs and limitations

- Inertia couples the first-party browser release to the Laravel application; approved external consumers will need explicit APIs later.
- Application-level audit immutability does not replace database privileges, backup protection, or an external tamper-evident archive for a future real-care system.
- SQLite cannot prove MySQL runtime behavior, so CI and staging must continue to test target-database migrations.
- Passkeys depend on a correct HTTPS origin and relying-party configuration in deployed environments.
- Hostinger deployment, queue supervision, storage privacy, backup restore, and atomic rollback remain unproven and therefore blocked from automation.

## Verification contract

The foundation is acceptable only while these checks pass:

- PHP unit/feature tests for authentication, environment safety, assignment isolation, audit behavior, public identifiers, and fixtures;
- React component tests including automated accessibility checks;
- PHPStan level 7, TypeScript strict checking, Pint, ESLint, and Prettier;
- production Vite build with generated Wayfinder routes;
- SQLite feature tests and MySQL migration smoke test;
- manual desktop and narrow-viewport review with no browser-console errors.

## Revisit triggers

Create a new ADR when:

- a mobile app, external teaching tool, or integration needs a stable API;
- the university approves a real-care environment;
- identity must federate with institutional SSO;
- audit retention or tamper evidence requires a dedicated store;
- target hosting cannot reliably run queues, private files, backups, or reversible releases;
- a bounded module requires independent deployment based on measured operational evidence.

## References

- [ADR-001](ADR-001-REBUILD-ARCHITECTURE.md)
- [Project charter](../PROJECT_CHARTER.md)
- [Outpatient role matrix](../product/OUTPATIENT_ROLE_MATRIX.md)
- [Outpatient acceptance scenarios](../product/OUTPATIENT_ACCEPTANCE_SCENARIOS.md)
- [UEU Clinical design system](../design/UEU_CLINICAL_DESIGN_SYSTEM.md)
