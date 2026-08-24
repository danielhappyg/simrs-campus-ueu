# Release evidence index

Status: active
Rule: never claim **implemented**, **tested**, **committed**, **pushed**, or **deployed** unless each state is independently proven here or in a linked artifact.

## How to record a release or baseline

| Field | Required |
|---|---|
| Evidence ID | `REL-YYYYMMDD-NN` |
| What changed | short scope |
| Local verified | command + result summary |
| Committed | commit SHA + branch |
| Pushed | remote ref (or “not pushed”) |
| Deployed | environment + URL/id (or “not deployed”) |
| Authz / audit / reconciliation | links or “N/A” |
| Gate | G0 / G1 / G2 / … PASS, FAIL, BLOCKED |
| Rollback | path or “docs-only / N/A” |

## Register

| Evidence ID | Date | Scope | Local | Committed | Pushed | Deployed | Authz / audit / reconciliation | Gate | Rollback |
|---|---|---|---|---|---|---|---|---|---|
| REL-20260821-01 | 2026-08-21 | Phase 0 docs baseline: `docs/new-simrs-rebuild/`, vendor assessment pack, Phase 0 scaffolding | Docs authored; no app test run required for docs-only | `9d9c0d1` on `main` | *updated after push* | not deployed | Docs-only; N/A | G0 scaffolding | Docs-only; revert the documentation commit |
| REL-20260821-02 | 2026-08-21 | Phase 0 decisions + ADR-015 + hospital-shell Adapt for ASAP synthetic demo | `php artisan test` → 282 passed | *filled at commit* | authorized (DEC-010) | Vercel synthetic demo | RBAC and synthetic-boundary evidence recorded in Phase 0/2 artifacts | G0 PASS (interim) | Promote the recorded prior Vercel deployment/SHA; database recovery is separate |
| REL-20260825-01 | 2026-08-25 | Structured Outpatient Documentation and RM Completeness v1 current-truth baseline | `composer ci:check` → 151 PHP tests / 1,183 assertions, 20 frontend tests, TypeScript, ESLint, Prettier, PHPStan and Pint passed | Application baseline `aabfff562dbe75d022da203f3f44215b055be614`; this evidence update pending commit | Application `origin/main` at the same SHA; this evidence update not pushed | Vercel production `dpl_2th2jRdhHHof859F3WM9rWt3ZiTf`, `READY`; Supabase migration/table presence verified | Structured tests and private-schema grants reconciled in `T0_CURRENT_TRUTH_BASELINE_2026-08-25.md`; hosted authenticated UAT and owner acceptance open | T0 PARTIAL | Promote the recorded last-known-good Vercel deployment only if schema remains compatible; database correction/restore requires separate reviewed evidence |

## State vocabulary

- **Local:** exists in the developer worktree or local verification ran.
- **Committed:** present in git history on a named branch.
- **Pushed:** visible on the configured remote.
- **Deployed:** running in a named environment (demo/UAT/prod-capable).

These four must not be collapsed into a single “done.”
