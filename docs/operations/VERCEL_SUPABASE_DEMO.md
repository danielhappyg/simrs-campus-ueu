# Vercel + Supabase synthetic demo

This deployment is a testing-only SIMRS Campus UEU environment. It must contain
only synthetic `example.invalid` accounts and the retained synthetic outpatient
reference session.

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
