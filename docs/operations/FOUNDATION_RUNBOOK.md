# Platform Foundation Runbook

## Purpose and boundary

This runbook covers local development and evidence collection for the simulation-only platform foundation. It is not a Hostinger deployment procedure and does not authorize real patient data.

## Environment contract

Required safety values:

```dotenv
APP_MODE=SIMULATION
APP_SYNTHETIC_ONLY=true
```

The application returns HTTP 503 from protected simulation routes if either value is unsafe. Each deployed page must continue to show `SIMULASI — DATA SINTETIS` and `Tidak untuk pelayanan pasien nyata`.

`APP_KEY`, database credentials, passkey secrets, mail credentials, and deployment keys belong in the local or protected deployment environment. They must never be committed.

## Local initialization

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm run build
```

Run the application with `composer dev`, or serve the production build locally with `php artisan serve`.

## Synthetic fixture procedure

Fixtures are disabled by default. To create the reference learner session, set a local password of at least 12 characters and enable the demo seeder in `.env`, then run `php artisan migrate:fresh --seed` against a disposable database.

Expected evidence:

- learner: `mahasiswa.keperawatan@example.invalid`;
- scenario: `OPD-REF-001` version 1;
- session: `SIM-RJ-UEU-001` in `ACTIVE` status;
- one ready and one changes-requested nursing task;
- `fixture_spec.synthetic_only` is true.

Never enable demo seeding simply to repair an existing database. Seeders are reference-data tools, not migrations.

## Verification

Run the repository quality gates from the README. Additional operational checks:

```bash
php artisan about
php artisan migrate:status
php artisan route:list
php artisan config:show simulation
```

Confirm that:

- no `register` route exists;
- `/work` requires authentication and verified email;
- the health endpoint `/up` responds successfully;
- a user cannot see another user's assignment or task;
- a revoked assignment disappears from the work queue;
- each work-queue view creates a correlated audit event;
- the browser console is clear at desktop and narrow viewports.

## Recovery and rollback boundary

Before patient or encounter data exists, a local foundation database can be recreated from migrations and synthetic fixtures. Do not use destructive migration commands on shared, staging, or production-like environments.

For a hosted release, rollback must restore a previously built application artifact while preserving a compatible database. Database rollback is permitted only when the migration has an explicitly reviewed reverse path and no retained record would be lost. Otherwise deploy a forward corrective migration.

The SSH deployment workflow remains blocked until the project records evidence for:

1. separate staging and production databases, keys, storage, and hostnames;
2. backup creation and test restoration;
3. PHP extensions and writable/private storage;
4. release-directory or equivalent atomic switching;
5. queue/cron behavior and log retention;
6. maintenance-mode and health-check behavior; and
7. a tested code and database recovery exercise.
