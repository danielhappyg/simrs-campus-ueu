# Current Hosting Posture

- **Status:** Active project decision as of August 2026
- **Decision authority:** Daniel Happy Putra
- **Scope:** synthetic teaching and demonstration environments only

## 1. What is decided now

The reference build currently runs its disposable hosted demo on **Vercel + Supabase Free**:

- **Vercel** hosts the same-origin Laravel application through the community PHP runtime.
- **Supabase PostgreSQL** (`laravel` schema) is the persistent store for that demo.
- Data remains **synthetic only** (`APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`).

This topology exists because it is the free stack available today for development, review, and classroom demonstration. It is **not** a claim that Vercel is the final campus production host.

See the active runbook: [Vercel + Supabase synthetic demo](VERCEL_SUPABASE_DEMO.md).

**Teaching rebuild handoff (full RJ arc + evidence):** [HANDOFF_SIMRS_TEACHING_REBUILD_2026-08-23.md](HANDOFF_SIMRS_TEACHING_REBUILD_2026-08-23.md)

An alternate disposable demo path using **Render + Supabase** remains documented in [Render + Supabase demo](RENDER_SUPABASE_DEMO.md).

## 2. What is not decided yet

The eventual **campus environment is TBD**. Universitas Esa Unggul may later provide shared PHP hosting, a VPS, on-premises infrastructure, a managed platform, or another arrangement through IT.

When that environment is known, the project should reassess:

- deploy target and release switching;
- database engine, backup, and restore;
- queue/cron and long-running workers;
- private file storage and TLS;
- network isolation and institutional security review.

The application is intentionally host-agnostic at the code level: environment variables, migrations, and release artifacts should transfer without redesigning the outpatient product model.

## 3. Relationship to older Hostinger planning

Earlier charter and master-plan documents treated **Hostinger** as a conditional initial host. That position is now **demoted to one possible future option**, not the active deployment target.

The read-only `php artisan ops:hosting-preflight` command and [Hostinger staging preflight](HOSTINGER_STAGING_PREFLIGHT.md) remain useful as a **host-neutral capability checklist** for shared PHP hosting. They do not authorize deployment and do not require a Hostinger account while the Vercel + Supabase demo is the working hosted environment.

## 4. Environment summary

| Environment | Purpose | Current posture |
|---|---|---|
| Local | developer work | SQLite or local MySQL; see README |
| CI | pull-request gates | ephemeral fixtures and database-specific jobs configured in `.github/workflows` |
| Hosted demo | synthetic review/UAT | **Vercel + Supabase** (active runbook) |
| Campus staging/production | teaching pilot later | **TBD** — pending institutional IT decision |
| Future clinical | real patient data | out of scope; separate governance program |

## 5. Teaching rehearsal implication

Faculty rehearsal does **not** wait for campus IT. Use a local disposable database or the current Vercel + Supabase demo, then follow the [current RJ facilitator runbook](TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md). Preview deployments must use isolated persistence; when that is unavailable, they are limited to public, non-authenticated health and asset checks. A hosted demo rehearsal is not evidence that campus staging, backup/restore, or rollback is ready.

## 6. Non-negotiable boundary

No hosted environment may be described as production clinical care, SATUSEHAT-connected, or BPJS-live until a separate institutional approval process is completed. Every page and export must continue to identify the system as simulation using synthetic data.

## References

- [Vercel + Supabase synthetic demo](VERCEL_SUPABASE_DEMO.md)
- [Render + Supabase demo](RENDER_SUPABASE_DEMO.md)
- [Platform foundation runbook](FOUNDATION_RUNBOOK.md)
- [Hostinger staging preflight (optional shared-hosting reference)](HOSTINGER_STAGING_PREFLIGHT.md)
- [Release candidate artifact](RELEASE_CANDIDATE_ARTIFACT.md)
- [Current RJ facilitator runbook](TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md)
- [Project charter](../PROJECT_CHARTER.md)
