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
| REL-20260825-01 | 2026-08-25 | Structured Outpatient Documentation and RM Completeness v1 current-truth baseline | `composer ci:check` → 151 PHP tests / 1,183 assertions, 20 frontend tests, TypeScript, ESLint, Prettier, PHPStan and Pint passed | Application baseline `aabfff562dbe75d022da203f3f44215b055be614`; current-truth evidence committed as `c3ea57b` on `codex/t0-g0-governance` | Application `origin/main` at `aabfff562dbe75d022da203f3f44215b055be614`; evidence branch `origin/codex/t0-g0-governance` pushed through `bb179b3` | Vercel production `dpl_2th2jRdhHHof859F3WM9rWt3ZiTf`, `READY`; Supabase migration/table presence verified | Structured tests and private-schema grants reconciled in `T0_CURRENT_TRUTH_BASELINE_2026-08-25.md`; hosted authenticated UAT and owner acceptance open | T0 PARTIAL | Promote the recorded last-known-good Vercel deployment only if schema remains compatible; database correction/restore requires separate reviewed evidence |
| REL-20260825-02 | 2026-08-25 | G0 governance enforcement and attributable rebuild-admin correction path | `composer ci:check` → 161 PHP tests / 1,266 assertions and 20 frontend tests passed; validator 14 runs / 39 assertions; integrity passed 268 requirements; G0 correctly failed on 252 owner placeholders | Squash merge `42ab482de577fe38cef539a74f0b749d64485b19` on `main` via PR #55 | `origin/main` at `42ab482de577fe38cef539a74f0b749d64485b19` | Deployed through REL-20260825-03; no hosted account mutation performed | Reconciliation command, transactional audit tests, G0 validator, owner-appointment pack and `PRIVILEGED_REBUILD_ADMIN_RUNBOOK_2026-08-25.md` | T0 PARTIAL; G0 BLOCKED on named owners/decisions | Promote prior production `dpl_2th2jRdhHHof859F3WM9rWt3ZiTf`; no database rollback is needed because the command has not been applied |
| REL-20260825-03 | 2026-08-25 | Exact-SHA production promotion of PR #55 | All PR gates passed: Documentation, PHP/React/security, PostgreSQL 17, MySQL 8.4, release-candidate build and Vercel preview; post-promotion `/up` and `/login` HTTP 200; no runtime error clusters or error/fatal logs in the checked window | `42ab482de577fe38cef539a74f0b749d64485b19` on `main` | `origin/main` at the same SHA | Vercel production `dpl_4uGZACFzKsHMnNUy6YshiJjeQN1Q`, `READY`, alias `simrs-campus-ueu-demo.vercel.app`; source SHA matched exactly | Login payload retained `SIMULATION`, `syntheticOnly=true`, and `SIMULASI — DATA SINTETIS`; no migration, role reconciliation, temporary activation, or authenticated UAT was performed | T0 PARTIAL | Promote prior production `dpl_2th2jRdhHHof859F3WM9rWt3ZiTf`; database schema was unchanged by this release |
| REL-20260825-04 | 2026-08-25 | BG-02c4b fail-closed hosted migration adoption, shared Vercel writer drain and corrected final-backup release gate | Exact application gate at `3a9ad49c67d1c6c56831eb39afa7e5df8501190a`: 397 PHP tests, 395 passed, 2 expected skips, 4,686 assertions; 20 frontend tests; PostgreSQL 17 and MySQL 8.4 integration, documentation integrity and release-candidate build passed. Documentation-only PR #67 also passed the full required CI set | Application merge `3a9ad49c67d1c6c56831eb39afa7e5df8501190a` via PR #66; approved cutover candidate `5129e31d1077dd340feb6af016151cef64f50b2b` includes reviewed runbook amendment PR #67 | `origin/main` contained the approved cutover candidate at `5129e31d1077dd340feb6af016151cef64f50b2b`; later governance-only commits do not implicitly change the cutover SHA | Approved cutover-candidate Vercel Preview `dpl_Bj1s8MgwZMjUxGkajt3B1f6whYAk`, `READY`, `target: null`; protected boot evidence in `docs/operations/BG_02C4B3_EXACT_SHA_PREVIEW_BOOT_EVIDENCE_2026-08-25.md`. Public Production remains `dpl_4uGZACFzKsHMnNUy6YshiJjeQN1Q` at `42ab482de577fe38cef539a74f0b749d64485b19` | Read-only Supabase inventory, six-name migration-ledger drift, 13 sequence contracts and one blocking legacy audit row recorded in `BG_02C4B_HOSTED_ATTRIBUTION_PREFLIGHT_2026-08-25.md`; independent review found no code P0/P1 after the final-backup fix. No Production promotion, migration, credential transmission or account activation occurred | G1 BG-02c4b code/Preview PASS; hosted cutover PENDING action-time authorization, backup/restore, drain, migration and preflight | Production unchanged. Do not roll back the database because no hosted migration occurred; discard the Preview or promote a separately verified prior deployment if needed |

## State vocabulary

- **Local:** exists in the developer worktree or local verification ran.
- **Committed:** present in git history on a named branch.
- **Pushed:** visible on the configured remote.
- **Deployed:** running in a named environment (demo/UAT/prod-capable).

These four must not be collapsed into a single “done.”
