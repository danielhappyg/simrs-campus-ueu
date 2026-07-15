# Contributing

## Before starting work

1. Read the [master plan](docs/SIMRS_CAMPUS_MASTER_PLAN.md), [legacy assessment](docs/LEGACY_ASSESSMENT.md), [ADR-001](docs/adr/ADR-001-REBUILD-ARCHITECTURE.md), and [ADR-002](docs/adr/ADR-002-PLATFORM-FOUNDATION.md).
2. Confirm that the requested work belongs to the currently approved increment.
3. Use synthetic data and simulation/test credentials only.
4. Define the user, patient journey, authorization rule, and acceptance evidence before implementation.

## Change workflow

- Do not work directly on `main`.
- Create a short-lived branch such as `feat/outpatient-registration`, `fix/encounter-policy`, or `docs/nursing-workshop`.
- Keep each pull request focused on one coherent outcome.
- Include migrations, tests, documentation, and rollback implications where applicable.
- Require review from the relevant domain owner for clinical-workflow changes.
- Merge only after required checks and approvals pass.

## Required local verification

Run all checks that apply before opening a pull request:

```bash
composer lint:check
composer types:check
php artisan test
php artisan wayfinder:generate --with-form
npm run format:check
npm run lint:check
npm run types:check
npm run test:unit
npm run build
```

Do not regenerate demo fixtures on a database whose data should be preserved. The application must remain usable with `DEMO_SEED_ENABLED=false`.

## Pull-request evidence

Every implementation pull request should explain:

- the problem and intended users;
- the workflow and authorization impact;
- whether any clinical terminology or interoperability mapping changes;
- the tests performed;
- screenshots for visible changes;
- migration, deployment, and rollback considerations;
- confirmation that no real patient data or secrets are included.

## Architecture rule

Deliver complete vertical journeys through bounded modules. Do not add disconnected menu demonstrations, browser-local system-of-record data, fake “connected” integration states, or silent overwrite/delete behavior.
