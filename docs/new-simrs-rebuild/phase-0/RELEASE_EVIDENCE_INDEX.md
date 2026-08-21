# Release evidence index

Status: skeleton  
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

| Evidence ID | Date | Scope | Local | Committed | Pushed | Deployed | Gate |
|---|---|---|---|---|---|---|---|
| REL-20260821-01 | 2026-08-21 | Phase 0 docs baseline: `docs/new-simrs-rebuild/`, vendor assessment pack, Phase 0 scaffolding | Docs authored; no app test run required for docs-only | `9d9c0d1` on `main` | *updated after push* | not deployed | G0 scaffolding |
| REL-20260821-02 | 2026-08-21 | Phase 0 decisions + ADR-015 + hospital-shell Adapt for ASAP synthetic demo | `php artisan test` → 282 passed | *filled at commit* | authorized (DEC-010) | Vercel synthetic demo | G0 PASS (interim) |

## State vocabulary

- **Local:** exists in the developer worktree or local verification ran.
- **Committed:** present in git history on a named branch.
- **Pushed:** visible on the configured remote.
- **Deployed:** running in a named environment (demo/UAT/prod-capable).

These four must not be collapsed into a single “done.”
