# SIMRS Campus Universitas Esa Unggul

Teaching-first hospital information system for integrated health-sciences education at Universitas Esa Unggul.

This repository contains the active **clean-slate teaching rebuild** for the SIMRS parity program. It is a **simulation environment only**: synthetic data, fail-closed `APP_MODE=SIMULATION` / `APP_SYNTHETIC_ONLY=true`, and no production clinical or BPJS integrations.

**Continuing this work?** Start with the durable handoff pack: [`docs/operations/HANDOFF_SIMRS_TEACHING_REBUILD_2026-08-23.md`](docs/operations/HANDOFF_SIMRS_TEACHING_REBUILD_2026-08-23.md) (product arc, PRs #39–#48, UAT evidence, traps, next slices). Live demo: https://simrs-campus-ueu-demo.vercel.app

## Branch status

| Branch | Meaning |
|---|---|
| `main` | Canonical integration branch for the clean-slate rebuild and the source of the hosted demo. |
| `rebuild/clean-slate` | Historical foundation branch retained for traceability; it is not the current delivery branch. |
| Feature / `codex/*` branches | Short-lived review branches. Their contents are not delivered until merged and verified. |

Planning baseline: [`docs/new-simrs-rebuild/`](docs/new-simrs-rebuild/). Stack ratification: [`docs/adr/ADR-015-STACK-SELECTION-FOR-PARITY-PROGRAM.md`](docs/adr/ADR-015-STACK-SELECTION-FOR-PARITY-PROGRAM.md). Clean-slate decision: [`docs/adr/ADR-016-CLEAN-SLATE-REPLACE-IN-PLACE.md`](docs/adr/ADR-016-CLEAN-SLATE-REPLACE-IN-PLACE.md).

The retired Antrean/work-queue MVP remains only in Git history and historical evidence. Do not restore it as the product path (DEC-013). The rebuild uses the vendor system as evidence for information architecture and workflow discovery; it does not claim unobserved vendor backend parity.

## Delivered teaching workflows

- Rawat Jalan: Pendaftaran, nursing and physician entries, laboratory order/result, RM close, cetak, and rekap.
- Adjacent teaching desks: IGD registration/examination/triage and Rawat Inap admission/examination.
- Server-side RBAC, simulation middleware, inactive-account enforcement, schema-aware PostgreSQL models, and append-only audit events.
- UEU navigation and Indonesian care-desk labels.

Klaim, BPJS, and Apotek remain `Soon`. Radiology, pharmacy dispensing, live SEP/VClaim/SATUSEHAT, LIS, and PACS are not delivered integrations.

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

Open `http://localhost:8000`. Guests are sent to login; public self-registration remains unavailable.

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

- Start-here handoff: [`docs/operations/HANDOFF_SIMRS_TEACHING_REBUILD_2026-08-23.md`](docs/operations/HANDOFF_SIMRS_TEACHING_REBUILD_2026-08-23.md)
- Current RJ facilitator flow: [`docs/operations/TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md`](docs/operations/TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md)
- Hosted demo operations: [`docs/operations/VERCEL_SUPABASE_DEMO.md`](docs/operations/VERCEL_SUPABASE_DEMO.md)
- Rebuild program: [`docs/new-simrs-rebuild/`](docs/new-simrs-rebuild/)
- Vendor assessment pack: [`docs/vendor-simrs-assessment-2026-08-21/`](docs/vendor-simrs-assessment-2026-08-21/)
- ADRs: [`docs/adr/`](docs/adr/)
- Security notes: [`SECURITY.md`](SECURITY.md)
- Contribution notes: [`CONTRIBUTING.md`](CONTRIBUTING.md)
