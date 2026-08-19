# Render + Supabase synthetic demo deployment

## Status and scope

This topology is for the temporary **SIMULATION / synthetic-only** testing phase. It does not authorize real patient data, clinical care, production interoperability, or external claim transmission.

- Render Free runs the complete Laravel + React/Inertia application as one same-origin Docker service.
- Supabase Free supplies PostgreSQL persistence in a private `laravel` schema.
- Laravel Fortify remains the authentication authority; Supabase Auth and the public Data API are not used.
- Queue work runs synchronously because the free Render plan does not provide a dedicated worker.
- Application uploads remain disabled by product scope. Render's local filesystem is ephemeral and must not become the authoritative location for future private uploads.

## Platform configuration

The tracked `render.yaml` declares only non-secret settings. These values must be stored in Render's encrypted environment configuration:

- `APP_KEY`
- `DB_URL` using the Supabase Session Pooler on port `5432` with SSL required
- `DEMO_ACCOUNT_PASSWORD` containing at least 12 characters

The Supabase database must contain a private `laravel` schema before the first Render deployment. The application sets PostgreSQL's `search_path` to that schema, applies all Laravel migrations on container startup, and seeds the reserved synthetic reference fixture only when `SIM-RJ-UEU-001` is absent.

## Free-tier operating boundary

- Expect a cold start after inactivity.
- Supabase may pause an inactive free project.
- Render's local filesystem is disposable.
- No uptime, backup, recovery-time, or worker reliability claim is made.
- A successful `/up` response proves only that the deployed Laravel process is responding. Release acceptance additionally requires sign-in, work-queue, synthetic workflow, and database-persistence checks.

## Verification checklist

1. Confirm the Render deploy references the intended Git commit.
2. Confirm `/up` returns HTTP 200 over HTTPS.
3. Sign in with one reserved `example.invalid` account and the separately stored demo password.
4. Confirm the work queue renders the synthetic reference session.
5. Perform one reversible synthetic action and confirm it persists after a Render restart or redeploy.
6. Confirm the permanent simulation banner remains visible.
7. Confirm no real patient data, external endpoint, or production credential is present.
