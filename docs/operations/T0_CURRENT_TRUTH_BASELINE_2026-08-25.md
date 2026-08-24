# T0 current-truth baseline — 2026-08-25

**Evidence ID:** `REL-20260825-01`
**Status:** Current engineering and hosted baseline reconciled; T0 remains open
**Boundary:** Synthetic teaching environment only. This is not clinical-production authorization or parity acceptance.

## Reconciled state

| Layer | Verified state |
| --- | --- |
| Local repository | `main` at `aabfff562dbe75d022da203f3f44215b055be614`; `origin/main` matched |
| Local verification | `composer ci:check` passed: 151 PHP tests / 1,183 assertions; 20 frontend tests; TypeScript, ESLint, Prettier, PHPStan and Pint passed |
| Focused verification | Structured documentation and lifecycle filter passed 19 tests / 253 assertions |
| Vercel production | Deployment `dpl_2th2jRdhHHof859F3WM9rWt3ZiTf`; source SHA `aabfff562dbe75d022da203f3f44215b055be614`; state `READY`; target `production` |
| Public runtime | `/up` and `/login` returned HTTP 200; login payload reported `SIMULATION`, `syntheticOnly=true`, and `SIMULASI — DATA SINTETIS` |
| Runtime errors | No Vercel runtime error clusters or error/fatal logs were returned for the checked 24-hour window |
| Supabase project | `simrs-campus-ueu-demo` was `ACTIVE_HEALTHY` |
| Laravel migration | `2026_08_24_000100_create_outpatient_documentation_tables` recorded in `laravel.migrations`, batch 7 |
| Structured tables | `outpatient_clinical_documents`, `outpatient_clinical_document_versions`, `outpatient_rm_completeness_reviews`, and `outpatient_rm_completeness_items` exist |
| Hosted structured-v1 evidence | All four new tables contained zero rows at reconciliation time; focused authenticated structured-v1 UAT has not yet been recorded |
| Temporary role access | The four dedicated role accounts remain disabled with their prior sessions revoked; no credential is recorded here |

## Security interpretation

The table-list tool emitted a generic warning because RLS is disabled on the private `laravel` schema. Direct privilege verification showed:

- `anon`, `authenticated`, and `service_role` have no `USAGE` or `CREATE` privilege on `laravel`;
- those roles have no table grants in `laravel`;
- only the dedicated `laravel_app` role has application table privileges; and
- the Supabase security advisor returned no active finding.

Therefore the generic warning is not classified as a current Data API exposure. Do not enable RLS mechanically: doing so without an application-compatible policy design could block Laravel. Reassess if the Data API exposed-schema configuration or grants change.

The performance advisor reported informational missing-index observations, including several foreign-key columns. These are not T0 blockers, but they must be profiled and addressed before performance acceptance rather than patched blindly.

Role reconciliation also found that `admin.rebuild@example.invalid` is active with `admin`, `nurse`, `physician`, `registrar`, and `rmik`, while `DemoActorsSeeder` defines that account as admin-only. It has zero active sessions and is not required for the focused UAT. Treat this as privileged-role drift: do not use the account as a clinical actor, and reconcile it through an attributable least-privilege operation before a wider teaching pilot.

The reviewed correction path is `rebuild:admin-reconcile`, documented in `PRIVILEGED_REBUILD_ADMIN_RUNBOOK_2026-08-25.md`. It is dry-run by default, accepts only the canonical or known-drift role sets, and makes role/status/session changes and their audit event one transaction. The command has not been applied to the hosted database in this baseline. Even after role sync, the separate `is_system_administrator` bypass remains and requires a G1 break-glass decision.

## Evidence boundaries

This reconciliation proves that the structured-v1 code, assets, deployment, schema and public runtime are aligned. It does not prove:

- authenticated Draft/Final and immutable-version behaviour on the hosted system;
- RMIK checklist, review, sign-off and closed-record retrieval on the hosted system;
- role-specific permitted and denied behaviour for the new slice;
- Clinical or RMIK acceptance of the bounded fields and workflow;
- Clinical/Laboratory and RMIK acceptance of DEC-016; or
- complete Sahabat parity or suitability for real-patient clinical use.

## Remaining T0 exit work

1. Temporarily activate only the role account needed for each UAT step and revoke it after use.
2. Execute one fresh synthetic encounter through nursing Draft/Final, medical Draft/Final, automatic completeness review, attributable sign-off, and closed read-only retrieval.
3. Record exact encounter, document-version, review, audit and denial identifiers without recording credentials.
4. Verify no unauthorized mutation, revoke all temporary access, and confirm zero retained role sessions.
5. Present the evidence and decision tables to the Clinical and RMIK owners.
6. Resolve DEC-016 with Clinical/Laboratory and RMIK authority, or record an explicit defer/revision decision.
7. Reconcile the rebuild-admin role assignment to its approved least-privilege disposition with retained operational evidence.

## Governing references

- `docs/operations/HANDOFF_SIMRS_TEACHING_REBUILD_2026-08-23.md`
- `docs/operations/TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md`
- `docs/operations/VERCEL_SUPABASE_DEMO.md`
- `docs/operations/PRIVILEGED_REBUILD_ADMIN_RUNBOOK_2026-08-25.md`
- `docs/new-simrs-rebuild/phase-3/README.md`
- `docs/new-simrs-rebuild/phase-1/STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_FR_PACK.md`
- `docs/new-simrs-rebuild/phase-0/DECISION_LOG.md`
