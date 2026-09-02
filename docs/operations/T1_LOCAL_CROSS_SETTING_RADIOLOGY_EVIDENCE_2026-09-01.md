# T1 Local Cross-Setting Radiology Exact-Engine Evidence

Status: **PASS — LOCAL DISPOSABLE ENGINES ONLY**
Executed: 2026-09-01 Asia/Jakarta
Scope: PostgreSQL 17.10 and MySQL 8.4.11 in isolated local databases

Deployment, hosted readiness, radiology-owner acceptance, parity acceptance, and production claims remain **false**. This record does not authorize a push, hosted migration, or deployment.

## Bound implementation and verification

- Harness: `scripts/rehearse-local-radiology-portability.rb`
- Harness contract: `tests/Documentation/LocalRadiologyPortabilityHarnessContractTest.rb`
- Migration: `database/migrations/2026_08_31_001100_create_cross_setting_radiology_tables.php`
- Feature reference: `tests/Feature/Radiology/CrossSettingRadiologyWorkflowTest.php`
- Authorization: `docs/new-simrs-rebuild/phase-1/CROSS_SETTING_RADIOLOGY_ORDER_REPORT_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-08-31.md`

## Exact-engine execution register

| Engine | Result | Scenarios | Evidence | SHA-256 | Mode | Strict cleanup |
|---|---|---:|---|---|---|---|
| PostgreSQL 17.10 | PASS | 17/17 | `storage/app/portability-rehearsals/20260831T175508Z-postgresql17-radiology-da18dc09a70a.json` | `03d0efdbb7347bc865a134cd052785262866a4b54d3f3d9a7943760434857656` | `0600` | PASS |
| MySQL 8.4.11 | PASS | 17/17 | `storage/app/portability-rehearsals/20260831T175738Z-mysql8411-radiology-2b2daf3ea1a9.json` | `e0ea863950a421dee35dcf0408fe9985eeef9c6090db7a7ce1dedb1b1a566117` | `0600` | PASS |

Each evidence file reports kind `SIMRS_LOCAL_CROSS_SETTING_RADIOLOGY_PORTABILITY`, status `PASS`, and all four cleanup flags as true: disposable database removed, temporary server removed, temporary user state removed, and temporary worker removed.

## Scenario readback

| Scenario | PostgreSQL 17.10 | MySQL 8.4.11 |
|---|---|---|
| Fresh migration | PASS | PASS |
| Empty down/reapply | PASS | PASS |
| Exact role boundary | PASS | PASS |
| Outpatient, emergency, and inpatient orders | PASS | PASS |
| Master create/update/retire | PASS | PASS |
| Order → perform → Draft → Verified → amend → acknowledge | PASS | PASS |
| Closure and cancellation blockers | PASS | PASS |
| Exact replay after later head advance | PASS | PASS |
| Changed-payload same-key conflict | PASS | PASS |
| Two-process same-key race and unique reconciliation | PASS | PASS |
| Application SQL write guard refusal | PASS | PASS |
| Database snapshot/delete/truncate refusal | PASS | PASS |
| Full evidence-chain corruption refusal | PASS | PASS |
| Least-privilege runtime grants | PASS | PASS |
| Bounded reset with amendment-first deletion and audit preservation | PASS | PASS |
| Retained-evidence rollback refusal | PASS | PASS |
| Durable invariant verification | PASS | PASS |

## Regression readback

- PHP: 740 tests executed; 736 passed; 4 intentional skips; 12,870 assertions.
- Frontend: 123 tests passed.
- Dedicated radiology portability contract: 8 runs, 178 assertions, 0 failures.
- Shared portability registration contract: 15 runs, 200 assertions, 0 failures.
- TypeScript, production frontend build, Ruby syntax, Pint, and diff checks passed.

## Least-privilege boundary

- Reference tables are read-only.
- The encounter head has only the additional update authority required for PostgreSQL row locks; it has no insert or delete authority.
- Radiology master/order heads allow select, insert, and update, but no delete.
- Immutable reservations, versions, cancellations, performances, reports, acknowledgements, receipts, and audit events allow select and insert only.
- Reset uses a separate bounded identity and deletes the synthetic radiology graph in dependency order while retaining master and audit evidence.

## Claim boundary

This record proves only the bound local implementation bytes on disposable PostgreSQL 17.10 and MySQL 8.4.11. It does not prove hosted Supabase migration, production configuration, capacity, clinical validation, radiology-owner acceptance, operational support readiness, PACS/RIS interoperability, or deployment approval. No PACS, RIS, BPJS, VClaim, SATUSEHAT, mail, or other external integration was exercised.
