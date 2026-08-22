# README — SIMRS Campus Universitas Esa Unggul (clean-slate rebuild)

Teaching-first hospital information system for integrated health-sciences education at Universitas Esa Unggul.

This repository branch is a **clean-slate rebuild foundation** for the full SIMRS parity program. It is a **simulation environment only**: synthetic data, fail-closed `APP_MODE=SIMULATION` / `APP_SYNTHETIC_ONLY=true`, and no production clinical or BPJS integrations.

**Continuing this work?** Start with the durable handoff pack: [`docs/operations/HANDOFF_SIMRS_TEACHING_REBUILD_2026-08-23.md`](docs/operations/HANDOFF_SIMRS_TEACHING_REBUILD_2026-08-23.md) (product arc, PRs #39–#48, UAT evidence, traps, next slices). Live demo: https://simrs-campus-ueu-demo.vercel.app

## Branch status

| Branch | Meaning |
|---|---|
| `rebuild/clean-slate` (this branch) | Option B replace-in-place: Laravel 13 + Inertia/React foundation after removing the outpatient teaching MVP application domain. Ready for Phase 2 identity/authorization and subsequent parity slices. |
| `main` (history) | Previous outpatient teaching MVP remains in git history on `main`. Do not treat it as the rebuild target path. |

Planning baseline: [`docs/new-simrs-rebuild/`](docs/new-simrs-rebuild/). Stack ratification: [`docs/adr/ADR-015-STACK-SELECTION-FOR-PARITY-PROGRAM.md`](docs/adr/ADR-015-STACK-SELECTION-FOR-PARITY-PROGRAM.md). Clean-slate decision: [`docs/adr/ADR-016-CLEAN-SLATE-REPLACE-IN-PLACE.md`](docs/adr/ADR-016-CLEAN-SLATE-REPLACE-IN-PLACE.md).

This is **not** a vendor SIMRS clone and **not** the retired outpatient MVP UI.

## What remains on this foundation

- Laravel 13, React 19, TypeScript, Inertia 3, Vite, Fortify (sessions, passkeys, 2FA);
- simulation safety middleware and inactive-account enforcement;
- append-only `audit_events` table + authorization-denial audit hook;
- rebuild home page (authenticated) stating Phase 0/2 foundation status;
- settings (profile / password / security) for Fortify;
- release-candidate / hosting-preflight ops commands (no domain clinic workflows);
- CI workflows under `.github/workflows`.

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

Open `http://localhost:8000`. Guests are sent to login; authenticated users see the rebuild home. Public self-registration remains unavailable.

Confirm foundation status:

```bash
php artisan rebuild:status
```

### Optional synthetic admin seed

Set only in the ignored local `.env`:

```dotenv
DEMO_SEED_ENABLED=true
DEMO_ACCOUNT_PASSWORD=choose-at-least-12-characters
```

Then on a disposable database:

```bash
php artisan migrate:fresh --seed
```

Creates verified ACTIVE admin `admin.rebuild@example.invalid`. Seeding refuses non-simulation or non-synthetic-only environments.

## Quality checks

```bash
vendor/bin/pint --dirty
composer types:check
php artisan test
npm run types:check
npm run build
```

Full CI suite: `composer ci:check` (after `php artisan wayfinder:generate --with-form`).

## Safety constraints

- `APP_MODE` must remain `SIMULATION`; `APP_SYNTHETIC_ONLY` must remain `true` for application routes.
- No production BPJS, SATUSEHAT, LIS, PACS, or payment endpoints.
- Do not commit `.env`, secrets, `.vercel/`, or `deliverables/`.

## Documentation map

- Rebuild program: `docs/new-simrs-rebuild/`
- Vendor assessment pack: `docs/vendor-simrs-assessment-2026-08-21/`
- ADRs: `docs/adr/`
- Security notes: `SECURITY.md`
- Contribution notes: `CONTRIBUTING.md`
