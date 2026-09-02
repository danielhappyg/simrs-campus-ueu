# T1 Local Governed Finance Tariff and Cost-Component Master Exact-Engine Evidence

Status: **PASS — LOCAL DISPOSABLE ENGINES ONLY**
Executed: 2026-09-02 Asia/Jakarta
Scope: PostgreSQL 17.10 and MySQL 8.4.11 in isolated local databases

Deployment, hosted migration, tariff-owner acceptance, affected-domain acceptance, parity acceptance, automatic-charge readiness, and production claims remain **false**. This record does not authorize a commit, push, Supabase migration, or Vercel deployment.

## Bound implementation and verification

- Authorization: `docs/new-simrs-rebuild/phase-1/GOVERNED_EFFECTIVE_DATED_FINANCE_TARIFF_COMPONENT_MASTER_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md`
- Harness: `scripts/rehearse-local-finance-tariff-component-master-portability.rb`
- Harness contract: `tests/Documentation/LocalFinanceTariffComponentMasterPortabilityHarnessContractTest.rb`
- Migration: `database/migrations/2026_09_02_000100_create_governed_finance_tariff_component_master.php`
- Shared 49-file application-source binding: `96e4340c20cc272a9be0fa92db87c84e589600f94977abe1618ae3d34658fc32`
- Embedded worker binding: `c50123e5b97376e78708d3080e00084b3fde51ae338f46eae2f237cf9b583598`
- Scenario catalogue binding: `f7650ddff378b8ea4a53f7909a2fb8ac12e0a8dbb541b662db3bb918ca4f9047`
- Runtime-grant catalogue binding: `d91cfb3d6e1f55ea6fbcb511199422f0c9ad6280e45116fbc377185fdd0d8eae`
- Command catalogue binding: `796265db7816c11778ba9f06e050b5be3f09507ff034552c0fc7f00b5b1f1750`

Both evidence files bind the same application, worker, scenario, runtime-grant, and command catalogue. Their result-catalogue digests differ only because the database engines expose different native behavior.

## Exact-engine execution register

| Engine | Result | Scenarios | Evidence | SHA-256 | Mode | Strict cleanup |
|---|---|---:|---|---|---|---|
| PostgreSQL 17.10 (`server_version_num=170010`, schema `laravel`) | PASS | 20/20 | `storage/app/portability-rehearsals/20260901T231657Z-postgresql17-finance-tariff-component-master-6dd35de99de9.json` | `d50846596ee2d4e1277cd32a966843c3446bc19ef570238407f8342f7e5999ae` | `0600` | PASS |
| MySQL 8.4.11 (InnoDB) | PASS | 20/20 | `storage/app/portability-rehearsals/20260901T231741Z-mysql8411-finance-tariff-component-master-37b4ab3671ed.json` | `99a067f3a4e4f84bf4de369e700f9df11d3f8edbdbb983844d0ed490c102f94a` | `0600` | PASS |

The repository-owned evidence directory was mode `0700`. Each engine recorded 20 sanitized commands, eight worker-protocol results, all four cleanup flags, no hosted target, and no external integration. Independent readback found no surviving harness-owned PostgreSQL or MySQL temporary directory.

## Scenario readback

| Scenario | PostgreSQL 17.10 | MySQL 8.4.11 |
|---|---|---|
| Fresh migration and empty-by-default catalogue/group/component/tariff graph | PASS | PASS |
| Empty down/reapply | PASS | PASS |
| Exact finance-steward mutation boundary and authorization before lookup | PASS | PASS |
| Cashier read-only; administrator, system-administrator, and mixed-role non-bypass | PASS | PASS |
| Stable catalogue, group, component, and tariff code reservation | PASS | PASS |
| Positive integer-rupiah contract and floating-point refusal | PASS | PASS |
| Current effective-version resolution | PASS | PASS |
| Future-authored version leaves today's version effective | PASS | PASS |
| Half-open service-date boundary | PASS | PASS |
| Terminal retirement preserves history and blocks later selection | PASS | PASS |
| Upstream group/component/catalogue retirement refusal while depended upon | PASS | PASS |
| Exact idempotent replay and changed-payload conflict | PASS | PASS |
| Stale fingerprint refusal and observed two-process writer serialization | PASS | PASS |
| Application SQL write-guard refusal | PASS | PASS |
| Database mutable-head refusal outside governed scope | PASS | PASS |
| Append-only update/delete/truncate refusal | PASS | PASS |
| Required-audit rollback and receipt-corruption refusal | PASS | PASS |
| Reduced least-privilege runtime and separate reset identity | PASS | PASS |
| Bounded reset, recovery reconciliation, and retained audit evidence | PASS | PASS |
| Retained-evidence rollback refusal and strict engine-owned cleanup | PASS | PASS |

## Regression readback

- Full PHP gate after the exact-engine fixes: 886 tests executed; 882 passed; four intentional skips; 17,673 assertions.
- Full frontend gate: 26 files and 173 tests passed; TypeScript, ESLint, Prettier, and the production Vite build passed.
- Tariff-focused PHP gate: 28 tests and 318 assertions passed before the full suite; audit architecture added 80 tests and 10,671 assertions.
- Tariff harness contract: 10 runs; 376 assertions; zero failures or errors.
- Engineering-authorization contract: seven runs; 110 assertions; zero failures or errors.
- Pint, PHPStan, and `git diff --check` passed.
- The production page resolver now excludes `*.test.tsx` modules; its contract passed two runs and ten assertions, and the rebuilt manifest contains no test-page entry.

## Defects found by the exact-engine gate

The first PostgreSQL runs refused the reduced runtime because immutable operation receipts and tariff versions were read with `FOR UPDATE`. Those locks had no serialization value: receipts and versions are append-only, while mutation serialization already occurs on the mutable head plus unique receipt/effective-date constraints and post-conflict replay. The locks were removed instead of granting broader UPDATE authority. The focused suite, complete PHP suite, and both exact engines then passed on the same bound tariff source bytes.

## Claim boundary

This evidence proves only the bound local implementation bytes on disposable PostgreSQL 17.10 and MySQL 8.4.11. It proves an empty-by-default governed tariff catalogue, cost-component hierarchy, effective-date resolution, immutable history, exact-role access, denial behavior, audit, recovery, and reset semantics. It does not assert that any entered amount is a real or approved hospital price, does not generate a charge automatically, and does not prove hosted Supabase migration, deployment, browser UAT, capacity, operational support, finance/clinical/diagnostic/facility acceptance, parity acceptance, G0, or G3. Payment, accounting, claims, BPJS, VClaim, SATUSEHAT, LIS, PACS/RIS, and other live integrations were not exercised.
