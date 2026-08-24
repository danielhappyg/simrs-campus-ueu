# Vercel + Supabase synthetic demo

- **Status:** Active hosted-demo runbook
- **Scope:** Synthetic teaching and demonstration only
- **Posture:** See [Current hosting posture](CURRENT_HOSTING_POSTURE.md)

Use this runbook to prepare and verify the current Vercel + Supabase demo. It does not authorize a deployment, database migration, reset, or production integration. Those actions require an approved release target and operator.

The hosted demo must contain only synthetic patients and `example.invalid` accounts. It is not a clinical production system and must not connect to BPJS/VClaim, SATUSEHAT, LIS, PACS, payment, or any other live healthcare service.

## Runtime model

- Vercel Hobby hosts two PHP 8.3 community-runtime functions: the Laravel application entry point and a Vite asset responder.
- Supabase PostgreSQL is the persistent store. Production-demo tables, sessions, and cache use the `laravel` schema.
- Laravel writable runtime directories are relocated to `/tmp`; the Vercel application filesystem is read-only and ephemeral.
- The function region is Singapore (`sin1`) to stay close to the Supabase project in `ap-southeast-1`.
- `public/build` is committed for Vercel releases. Vercel cannot generate Laravel Wayfinder bindings in its generic build container, so assets must be built and verified in a trusted checkout before release.
- Static files served through `api/assets.php` must also be routed in `vercel.json`. Unlisted paths fall through to Laravel and may return 404 even when present under `public/`.

The PHP runtime is community-supported rather than an official Vercel PHP runtime. That is acceptable for this explicitly synthetic demo, not for real hospital or patient data.

## Environment separation

Never give a Preview deployment the Production-demo database credentials.

| Vercel scope | Persistence boundary | Allowed verification |
| --- | --- | --- |
| Production | Dedicated synthetic-demo database/schema (`laravel`) | Approved authenticated teaching rehearsal |
| Preview with persistence | A separate Supabase project or explicitly isolated Preview schema and a separate `APP_KEY` | Authenticated feature smoke using Preview-only synthetic accounts |
| Preview without isolated persistence | No Production `DB_URL`; do not sign in, seed, migrate, or submit forms | Public `/up` and committed static-asset checks only |

A shared Preview schema is not isolation when concurrent branches can mutate it. Name the owning branch/environment and reset owner before using persistent Preview data.

Set secrets in the matching Vercel environment scope, never in Git. Each persistent environment needs its own values for:

- `APP_KEY`
- `APP_NAME`
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_MODE=SIMULATION`
- `APP_SYNTHETIC_ONLY=true`
- `APP_URL`
- `DB_CONNECTION=pgsql`
- `DB_URL`
- `DB_SCHEMA` (`laravel` for the Production demo; a distinct name for an isolated Preview schema)
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

`DEMO_ACCOUNT_PASSWORD` is a bootstrap secret, not source code. Keep it in the approved secret store or a temporary ignored local environment file only while creating disposable demo accounts. It is not needed by the running application after the password hashes have been seeded.

## Release preparation

Start from a clean, reviewed checkout of the exact commit intended for deployment:

```bash
git status --short
git rev-parse HEAD
composer install --no-interaction
composer run vercel:assets
composer ci:check
git status --short
```

`composer run vercel` deliberately skips asset generation on Vercel. The release command is `composer run vercel:assets`; commit the resulting `public/build` changes with the application changes. Do not publish when generated assets are missing, stale, or introduce unexplained files.

Record the intended full Git SHA before any migration or promotion. Passing tests in another checkout or against another commit is not release evidence.

## Manual Production database migration

Vercel does **not** run Laravel migrations. Every release containing migrations needs a separate, explicit Supabase migration step from the same trusted checkout and commit.

Before the first application write, create the private PostgreSQL schema `laravel` in the Production-demo Supabase project and set `DB_SCHEMA=laravel`. Before later migrations, confirm the reviewed migration is compatible with both the currently deployed application and the release being promoted. Establish a tested backup/restore or disposable reset path appropriate to the target before changing the schema.

Pull the Production values into the ignored `.env.vercel.local` file, or create that file through the approved secret manager. Do not load it with `export $(grep ...)`; shell parsing can corrupt values and expose them through process state or history.

```bash
vercel env pull .env.vercel.local --environment=production
chmod 600 .env.vercel.local
php artisan --env=vercel.local config:clear
php artisan --env=vercel.local migrate:status
php artisan --env=vercel.local migrate --force
php artisan --env=vercel.local migrate:status
```

Review the before/after migration list and record it with the release evidence. Never run `migrate:fresh` on a retained rehearsal or UAT environment.

### RBAC reconciliation is separate

Migrations create schema; they do not reconcile the role/capability matrix. Run the idempotent RBAC seeder separately after migrations from the same checkout:

```bash
php artisan --env=vercel.local db:seed --class=RbacSeeder --force
```

Then compare every stored role's permissions with `RoleCapabilityMatrix`:

```bash
php artisan --env=vercel.local tinker --execute='foreach (\App\Support\Authorization\RoleCapabilityMatrix::roles() as $slug => $meta) { $actual = \App\Models\Role::query()->where("slug", $slug)->firstOrFail()->permissions()->pluck("name")->sort()->values()->all(); $expected = collect(\App\Support\Authorization\RoleCapabilityMatrix::capabilitiesFor($slug))->sort()->values()->all(); if ($actual !== $expected) { throw new \RuntimeException("RBAC drift: ".$slug); } echo "RBAC OK: ".$slug.PHP_EOL; }'
```

The seeder and comparison are both required when a release adds or changes capabilities. Finish with one permitted-role and one denied-role browser check; a successful seed alone does not prove route authorization.

Do not rerun `DemoActorsSeeder` to repair a single actor's role membership. For the known rebuild-admin drift, use the narrowly scoped [privileged rebuild-admin reconciliation runbook](PRIVILEGED_REBUILD_ADMIN_RUNBOOK_2026-08-25.md). Its role correction is distinct from RBAC capability seeding and from the still-open G1 break-glass design.

## Disposable bootstrap only

Creating the synthetic actors or teaching census is a separate destructive bootstrap operation. On a new, disposable database only, set `DEMO_SEED_ENABLED=true`, `APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`, and a temporary `DEMO_ACCOUNT_PASSWORD` of at least 12 characters in the ignored environment file, then run:

```bash
php artisan --env=vercel.local migrate:fresh --seed --force
```

Immediately return `DEMO_SEED_ENABLED=false`, remove the bootstrap password from local/runtime environment files when no longer needed, and verify that only synthetic data exists. Do not use this procedure to refresh a retained rehearsal database.

## Deployment and SHA evidence

Deployment/promotion remains an external action requiring explicit authorization. For every authorized release:

1. Record the intended full Git SHA and test results from the trusted checkout.
2. Record the Vercel deployment ID and the source Git SHA displayed for that deployment.
3. Confirm the two SHAs match exactly. Do not infer the deployed version from a branch name or deployment timestamp.
4. If the release has migrations, complete the manual migration and RBAC reconciliation above in the reviewed order.
5. Promote only the deployment whose recorded SHA passed the checks.
6. Record the final Production URL, deployment ID, source SHA, migration list, RBAC result, smoke results, operator, and timestamp.

## Hosted-demo verification

Run public checks first:

```bash
curl --fail --show-error --silent https://simrs-campus-ueu-demo.vercel.app/up
curl --fail --show-error --silent --output /dev/null https://simrs-campus-ueu-demo.vercel.app/login
```

Then use the browser and separately stored demo credentials:

1. Sign in with one reserved `example.invalid` account.
2. Confirm the persistent `SIMULASI — DATA SINTETIS` indicator is visible.
3. Confirm the account can open only the care desks permitted by its role.
4. Run the [current RJ facilitator flow](TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md) on a fresh synthetic encounter: Pendaftaran → nursing → physician → Order Lab → laboratory result → RM close → cetak → rekap.
5. Attempt one known denied action using the wrong role and record the 403/denial evidence.
6. Confirm Klaim, BPJS, and Apotek remain `Soon` and that no live external integration is enabled.
7. Record synthetic encounter/order/result identifiers and cleanup/reset status without copying credentials or patient-like sensitive values into the evidence file.

Public 200 responses prove only that the deployed application boots. Authenticated workflow, role-denial, database, and audit checks are separate evidence.

## Rollback and recovery

Vercel application rollback does not roll back Supabase.

1. Stop the rehearsal and record the failed deployment ID, source SHA, symptoms, and last known-good deployment ID/SHA.
2. If the database remains backward-compatible, promote the last known-good Vercel deployment and repeat the SHA, public, authenticated, and denied-role checks.
3. If a migration is involved, do not run a generic `migrate:rollback` or restore a snapshot over retained sessions. Review the migration's reverse path and data impact. Prefer a forward corrective migration when rollback could lose or reinterpret retained records.
4. Restore data only through the target's previously tested backup/restore procedure and with explicit operator approval. A disposable Preview may instead be destroyed and rebuilt from approved synthetic fixtures.
5. Record whether application rollback, database recovery, or both occurred. Keep the environment out of teaching use until the evidence reconciles.

Never paste `.env.vercel.local`, database URLs, `APP_KEY`, account passwords, cookies, or Vercel/Supabase tokens into Git, screenshots, logs, UAT records, or chat. Delete temporary local environment material through the approved secure cleanup process when the operation is complete.
