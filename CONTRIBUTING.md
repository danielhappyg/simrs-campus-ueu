# Contributing

## Before starting work

1. On branch `rebuild/clean-slate`, read [`docs/new-simrs-rebuild/`](docs/new-simrs-rebuild/), [ADR-015](docs/adr/ADR-015-STACK-SELECTION-FOR-PARITY-PROGRAM.md), and [ADR-016](docs/adr/ADR-016-CLEAN-SLATE-REPLACE-IN-PLACE.md). Historical ADRs (`ADR-001` …) remain context only.
2. Confirm that the requested work belongs to the currently approved rebuild phase/increment.
3. Use synthetic data and simulation/test credentials only.
4. Define the user journey, authorization rule, and acceptance evidence before implementation. Do not bulk-copy the retired outpatient MVP from `main` without an explicit DEC.

## Change workflow

- Do not work directly on `main` for rebuild slices; prefer `rebuild/clean-slate` or short-lived branches from it.
- Create a short-lived branch such as `feat/phase2-role-matrix`, `fix/simulation-guard`, or `docs/parity-slice`.
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

Do not seed on a database whose data should be preserved. The application must remain usable with `DEMO_SEED_ENABLED=false`. Opt-in seed creates only `admin.rebuild@example.invalid`.

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
