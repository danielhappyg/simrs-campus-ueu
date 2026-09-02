# T1 Local Cross-Setting Pharmacy and Stock Exact-Engine Evidence

Status: **PASS — LOCAL DISPOSABLE ENGINES ONLY**
Executed: 2026-09-01 Asia/Jakarta
Scope: PostgreSQL 17.10 and MySQL 8.4.11 in isolated local databases

Deployment, hosted readiness, pharmacy-owner acceptance, parity acceptance, and production claims remain **false**. This record does not authorize a commit, push, hosted migration, or deployment.

## Bound implementation and verification

- Harness: `scripts/rehearse-local-pharmacy-stock-portability.rb`
- Harness contract: `tests/Documentation/LocalPharmacyStockPortabilityHarnessContractTest.rb`
- Migration: `database/migrations/2026_09_01_000600_create_cross_setting_pharmacy_tables.php`
- Feature references: `tests/Feature/Pharmacy/CrossSettingPharmacyWorkflowTest.php` and `tests/Feature/Pharmacy/PharmacyBackendHardeningTest.php`
- Authorization: `docs/new-simrs-rebuild/phase-1/CROSS_SETTING_MEDICATION_DISPENSING_STOCK_LEDGER_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-01.md`
- Shared source-binding aggregate: `c3a5846d0a1242902aca72dc0bad3ee5e0276f3211ede78f7415216d7cffb3cc`
- Embedded worker binding: `3f50c24609e2a8617d22d3236796c59b32f0800db38e6e34c613c4e52a2c4697`
- Scenario catalogue binding: `6138f167e02716b298aa137aa3bbed1275f772d0a240dffa1d528e6b204e5d09`
- Runtime-grant catalogue binding: `2200049857bbedc514ac823db796464e342d5a1e2ed4420c545cc7d543e3a204`

## Exact-engine execution register

| Engine | Result | Scenarios | Evidence | SHA-256 | Mode | Strict cleanup |
|---|---|---:|---|---|---|---|
| PostgreSQL 17.10 (`server_version_num=170010`) | PASS | 26/26 | `storage/app/portability-rehearsals/20260901T073139Z-postgresql17-pharmacy-0392edc79734.json` | `0050eb11e2ad96e5df728fb6920867ee58527108b1632a8d586c98d0e6306f2` | `0600` | PASS |
| MySQL 8.4.11 (InnoDB) | PASS | 26/26 | `storage/app/portability-rehearsals/20260901T073002Z-mysql8411-pharmacy-10f068a262d7.json` | `9562ee4b30af3573b059769ffeeb2809438c1ff69f7bf62937e25784aab930e3` | `0600` | PASS |

Both evidence files report kind `SIMRS_LOCAL_CROSS_SETTING_PHARMACY_STOCK_PORTABILITY`, claim `LOCAL_DISPOSABLE_CROSS_SETTING_PHARMACY_STOCK_ONLY`, status `PASS`, the same source, worker, scenario, and runtime-grant bindings, 49 commands, and 15 protocol results. All four cleanup flags are true: disposable database removed, temporary server removed, temporary user state removed, and temporary worker removed. Hosted-readiness, deployment, and owner-acceptance claims are explicitly false.

## Scenario readback

| Scenario | PostgreSQL 17.10 | MySQL 8.4.11 |
|---|---|---|
| Fresh migration | PASS | PASS |
| Empty down/reapply | PASS | PASS |
| Exact physician, pharmacist, technician, and inventory-controller boundaries | PASS | PASS |
| RJ, IGD, and RI prescription-to-handover journeys | PASS | PASS |
| Medicine/depot create, version, and retire | PASS | PASS |
| Opening balance and lot/expiry/quarantine controls | PASS | PASS |
| Pharmacist verification and refusal | PASS | PASS |
| Deterministic FEFO multi-lot preparation and final revalidation | PASS | PASS |
| Full, partial, and unfilled-closed handover reconciliation | PASS | PASS |
| Return-to-stock, quarantine, and non-returnable outcomes | PASS | PASS |
| Stock movement, lot balance, and charge-source reconciliation | PASS | PASS |
| Encounter cancellation, IGD handoff, RI transfer/discharge, and RM closure blockers | PASS | PASS |
| Exact replay after later head advance | PASS | PASS |
| Changed-payload same-key conflict | PASS | PASS |
| Two-process competing handover and no-negative-stock proof | PASS | PASS |
| Handover-versus-return race | PASS | PASS |
| Preparation-versus-transfer/discharge races | PASS | PASS |
| Reset/recovery-versus-operation race and clean post-race readback | PASS | PASS |
| Exact patient-claim, inventory, encounter, location, bed, and prescription lock-order observability | PASS | PASS |
| Application SQL write-guard refusal | PASS | PASS |
| Database snapshot/update/delete/truncate refusal | PASS | PASS |
| Evidence-chain corruption refusal | PASS | PASS |
| Least-privilege runtime grants | PASS | PASS |
| Bounded reset, audit preservation, and semantic recovery snapshot | PASS | PASS |
| Retained-evidence rollback refusal | PASS | PASS |
| Durable invariant verification | PASS | PASS |

## Regression readback

- Full PHP gate: 816 tests executed; 812 passed; 4 intentional skips; 16,096 assertions. Pint passed and PHPStan reported zero errors.
- Dedicated pharmacy portability contract: 10 runs; 494 assertions; 0 failures and 0 errors.
- Pharmacy authorization contract: 8 runs; 130 assertions; 0 failures and 0 errors.
- Shared inpatient discharge-coding portability contract: 8 runs; 126 assertions; 0 failures and 0 errors.
- Adjacent portability contracts remained green: radiology 9 runs/188 assertions, laboratory 9 runs/212 assertions, and emergency 7 runs/141 assertions.
- Frontend sources were unchanged after their prior complete gate: 24 files, 161 tests, TypeScript, lint, Prettier, and production build all passed.

## Least-privilege boundary

- Reference and immutable evidence tables remain read-only or insert-only as appropriate; immutable handover evidence does not require update authority.
- Mutable medicine, depot, lot, inventory-mutex, prescription, and preparation heads receive only the select/insert/update permissions needed by the bound workflow and no delete or DDL authority.
- A separate bounded reset identity deletes the pharmacy transaction graph in dependency order. Replacement-preparation self-references are detached inside the guarded reset before deletion, while required audit evidence remains retained.
- The exact-engine runs verify application write-guard refusal, database update/delete/truncate refusal, evidence-chain corruption refusal, and retained-evidence rollback refusal.

## Claim boundary

This record proves only the bound local implementation bytes on disposable PostgreSQL 17.10 and MySQL 8.4.11. It does not prove hosted Supabase migration, production configuration, real medicine/stock/price correctness, capacity, pharmacy/clinical/warehouse/finance/RMIK validation, operational support readiness, owner acceptance, parity acceptance, deployment approval, or G0/G3 closure. No supplier, payment, BPJS, VClaim, SATUSEHAT, mail, device, or other external integration was exercised.
