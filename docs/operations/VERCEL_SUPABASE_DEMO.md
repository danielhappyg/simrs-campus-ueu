# Vercel + Supabase synthetic demo

- **Status:** Active hosted demo runbook
- **Posture:** See [Current hosting posture](CURRENT_HOSTING_POSTURE.md)

This is the **current working hosted environment** for the reference build. It exists because Vercel and Supabase Free are the disposable tools available now; campus production hosting remains TBD.

This deployment is a testing-only SIMRS Campus UEU environment. It must contain only synthetic `example.invalid` accounts and the retained synthetic outpatient reference session.

## Runtime model

- Vercel Hobby hosts two PHP 8.3 community-runtime functions: the Laravel
  application entry point and a Vite asset responder.
- Supabase PostgreSQL is the only persistent store. Database sessions, cache,
  and queued records use the existing `laravel` schema.
- Laravel's writable runtime directories are relocated to `/tmp`; Vercel's
  application filesystem is read-only and ephemeral.
- The function region is Singapore (`sin1`) to stay close to the Supabase
  project in `ap-southeast-1`.
- `public/build` is committed on the Vercel deployment branch. Vercel's generic
  build container does not provide PHP, so it cannot run Laravel Wayfinder
  before Vite; the assets must be rebuilt and verified locally before release.

## Required Vercel environment variables

Set these for Production and Preview without committing their values:

- `APP_KEY`
- `APP_NAME`
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_MODE=SIMULATION`
- `APP_SYNTHETIC_ONLY=true`
- `APP_URL`
- `DB_CONNECTION=pgsql`
- `DB_URL`
- `DB_SCHEMA=laravel`
- `DB_SSLMODE=require`
- `SESSION_DRIVER=database`
- `SESSION_TABLE=sessions`
- `SESSION_ENCRYPT=true`
- `SESSION_SECURE_COOKIE=true`
- `CACHE_STORE=database`
- `QUEUE_CONNECTION=sync`
- `LOG_CHANNEL=stderr`
- `LOG_LEVEL=warning`
- `DEMO_SEED_ENABLED=false`
- `DEMO_ACCOUNT_PASSWORD`

## Deployment boundary

Before publishing a new deployment commit, run `composer run vercel` and commit
the resulting `public/build` changes together with the application changes.

The PHP runtime is community-supported rather than an official Vercel runtime.
This is acceptable for the explicitly synthetic demo phase, but it is not the
recommended production architecture for real hospital or patient data.

## Supabase schema and one-time bootstrap

Create a private PostgreSQL schema named `laravel` in the Supabase project before the first application write. The application sets `search_path` to that schema through `DB_SCHEMA=laravel`.

Unlike Render, Vercel does not run Laravel migrations on deploy. Bootstrap the hosted database once from a trusted local checkout with the Supabase Session Pooler URL loaded into a local-only environment file (never commit `DB_URL`, `APP_KEY`, or `DEMO_ACCOUNT_PASSWORD`):

```bash
# Example: copy Vercel Production env to a local-only file, then:
export $(grep -v '^#' .env.vercel.local | xargs)
php artisan migrate --force
```

For a disposable demo database only, enable the synthetic reference fixture and seed once:

```dotenv
DEMO_SEED_ENABLED=true
APP_MODE=SIMULATION
APP_SYNTHETIC_ONLY=true
SESSION_DRIVER=database
```

```bash
php artisan migrate:fresh --seed --force
```

After the pristine `SIM-RJ-UEU-001` fixture exists, import both checksum-locked development terminology releases from local workbook paths. Use the exact SHA-256 values in the [Coding Reference Register](../research/CODING_REFERENCE_REGISTER.md):

```bash
php artisan terminology:import ICD_10 /absolute/path/to/icd10.xlsx --sha256=3c22aa15012dd2e15576657e49001291fd21a5b30ce797998a495aac548c5f4e
php artisan terminology:import ICD_9_CM /absolute/path/to/icd9cm.xlsx --sha256=c13d074be8fb271fccddfce4825ff56b6c958e48360ac148f7768c2de59e9697
```

Then run the read-only Checkpoint 2 gate against the hosted database before inviting participants:

```bash
php artisan simulation:lab-access enable --confirm=ENABLE-RESERVED-DEMO-ACCESS
php artisan simulation:lab-preflight
```

Set `DEMO_SEED_ENABLED=false` on Vercel after bootstrap unless you are intentionally rebuilding a disposable database. Never run `migrate:fresh` against a retained rehearsal or UAT environment.

## Hosted demo verification

1. Confirm the Vercel deployment references the intended Git commit.
2. Confirm `/up` returns HTTP 200 over HTTPS.
3. Confirm `simulation:lab-preflight` reports `READY` against the hosted Supabase database.
4. Sign in with one reserved `example.invalid` account and the separately stored demo password.
5. Confirm the work queue renders the synthetic reference session.
6. Confirm the permanent simulation banner remains visible.
7. Confirm no real patient data, external endpoint, or production credential is present.

For a full Checkpoint 2 rehearsal on the hosted demo, follow the [Outpatient Laboratory Pilot Runbook](OUTPATIENT_LAB_PILOT_RUNBOOK.md) and [Checkpoint 2 UAT Facilitator Guide](OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md).
