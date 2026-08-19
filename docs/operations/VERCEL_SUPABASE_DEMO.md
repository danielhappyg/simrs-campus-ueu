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
- `QUEUE_CONNECTION=database`
- `LOG_CHANNEL=stderr`
- `LOG_LEVEL=warning`
- `DEMO_SEED_ENABLED=false`
- `DEMO_ACCOUNT_PASSWORD`

## Deployment boundary

The PHP runtime is community-supported rather than an official Vercel runtime.
This is acceptable for the explicitly synthetic demo phase, but it is not the
recommended production architecture for real hospital or patient data.
