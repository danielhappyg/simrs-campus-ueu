# SIMRS Campus Universitas Esa Unggul

Teaching-first hospital information system for integrated health-sciences education. The reference MVP models a coherent outpatient journey for Medicine, Nursing, Medical Records and Health Information (RMIK), and Pharmacy before later expansion to other study programs.

This is a **simulation environment only**. It accepts synthetic patient data and is not authorized for real clinical care.

## Current status

The research, product contract, UEU Clinical design package, and secure application foundation are implemented. The current application includes:

- Laravel 13, React 19, TypeScript, Inertia 3, Vite, and Fortify;
- administrator-provisioned accounts with inactive-account rejection, password throttling, email verification, passkeys, and two-factor support;
- fail-closed `SIMULATION` and synthetic-only middleware;
- versioned scenarios, simulation sessions, contextual assignments, capabilities, and work tasks;
- a user-scoped work queue with the UEU encounter-orbit design;
- public ULID identifiers and an application-level append-only audit trail;
- deterministic opt-in demo fixtures using reserved `example.invalid` accounts;
- automated PHP, JavaScript, static-analysis, formatting, build, and database-migration checks.

Patient registration, encounters, clinical documentation, prescribing, pharmacy review, dispensing, coding, and record closure will be delivered as the next outpatient vertical slice. No production deployment workflow is enabled until the Hostinger preflight and rollback design are verified.

## Local development

Requirements: PHP 8.3+, Composer 2, Node.js 22+, npm, and SQLite.

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm run build
composer dev
```

Open `http://localhost:8000`. Public self-registration is intentionally unavailable.

### Optional synthetic demo

Set these values only in the ignored local `.env` file:

```dotenv
DEMO_SEED_ENABLED=true
DEMO_ACCOUNT_PASSWORD=choose-at-least-12-characters
```

Then, on a disposable local database:

```bash
php artisan migrate:fresh --seed
```

The learner account is `mahasiswa.keperawatan@example.invalid`; its password is the local value you selected. `migrate:fresh` deletes existing tables and must never be used against an environment containing data that should be preserved.

## Quality gates

```bash
composer lint:check
composer types:check
php artisan test
npm run format:check
npm run lint:check
npm run types:check
npm run test:unit
npm run build
```

The application workflow also validates dependency manifests, vulnerability advisories, and MySQL migrations. See the [foundation runbook](docs/operations/FOUNDATION_RUNBOOK.md) for environment checks and recovery boundaries.

## Product and architecture references

- [Approved project charter](docs/PROJECT_CHARTER.md)
- [Campus SIMRS master plan](docs/SIMRS_CAMPUS_MASTER_PLAN.md)
- [Legacy assessment](docs/LEGACY_ASSESSMENT.md)
- [ADR-001: Teaching-first modular monolith](docs/adr/ADR-001-REBUILD-ARCHITECTURE.md)
- [ADR-002: Same-origin platform foundation](docs/adr/ADR-002-PLATFORM-FOUNDATION.md)
- [Outpatient evidence register](docs/research/OUTPATIENT_EVIDENCE_REGISTER.md)
- [Outpatient service blueprint](docs/product/OUTPATIENT_SERVICE_BLUEPRINT.md)
- [Outpatient role and permission matrix](docs/product/OUTPATIENT_ROLE_MATRIX.md)
- [Outpatient data dictionary](docs/product/OUTPATIENT_DATA_DICTIONARY.md)
- [Assumption and validation register](docs/product/ASSUMPTION_AND_VALIDATION_REGISTER.md)
- [Outpatient acceptance scenarios](docs/product/OUTPATIENT_ACCEPTANCE_SCENARIOS.md)
- [Outpatient traceability matrix](docs/product/OUTPATIENT_TRACEABILITY_MATRIX.md)
- [UEU Clinical design system](docs/design/UEU_CLINICAL_DESIGN_SYSTEM.md)
- [Information architecture](docs/design/INFORMATION_ARCHITECTURE.md)
- [Outpatient critical-path wireframes](docs/design/OUTPATIENT_WIREFRAMES.md)
- [Outpatient interaction specifications](docs/design/OUTPATIENT_INTERACTION_SPECIFICATIONS.md)

## Authority and validation gates

Daniel Happy Putra is the sole project manager/PIC and final authority for scope, priority, acceptance, and releases during the reference-build phase. Stakeholder input is concentrated at three checkpoints after a concrete model exists:

1. workflow-baseline validation;
2. end-to-end UAT using one shared synthetic case; and
3. faculty-pilot readiness after security, accessibility, and deployment evidence is available.

These checkpoints improve the model without transferring final product authority. Real patient data remains prohibited until a separate institutional clinical, privacy, legal, security, and operational approval process is completed.
