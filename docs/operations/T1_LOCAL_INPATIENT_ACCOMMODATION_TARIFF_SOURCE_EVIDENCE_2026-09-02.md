# T1 Local Inpatient Accommodation Occupancy-Day Tariff-Source Exact-Engine Evidence

Status: **PASS — LOCAL DISPOSABLE SQLITE, POSTGRESQL 17.10, AND MYSQL 8.4.11 ONLY**

Recorded: 2026-09-02

Deployment, hosted readiness, owner/domain/parity acceptance, real-hospital day-count acceptance, G0, G3, and production claims: **false / not established by this record**

## Executed boundary

The fail-closed rehearsal exercised prospectively retained exact bed-version provenance, half-open inpatient occupancy intervals, deterministic local teaching calendar-day allocation, deliberately entered effective accommodation tariffs, one typed source per encounter/service date, four-domain typed finance charges, complete routine-discharge billing, exact constraints and triggers, least privilege, real database waits, reset/recovery, rollback refusal, and strict cleanup. It used only repository-local simulation configuration, synthetic records, SQLite, and harness-owned disposable exact engines.

No hosted database, live integration, real hospital price or approved day-count policy, proration, payment, accounting, claim, BPJS, VClaim, SATUSEHAT, LIS, PACS/RIS, deployment, or hosted migration authority was exercised.

The pre-run template at `docs/operations/T1_LOCAL_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_EVIDENCE_TEMPLATE_2026-09-02.md`, SHA-256 `08f563898f6b625238bdb62f3fbcea3a698a4fde37427347e997adc68993334e`, is part of the immutable executed source binding and remains unchanged. This final record was created only after both exact artifacts completed and is intentionally not claimed as an input to those executions.

## Immutable execution binding

Both exact artifacts bind the identical 39-file executed source set:

| Binding | SHA-256 |
|---|---|
| Application/source manifest | `3a2efebaf98d6911722eb995be7ef5cc0870bc188fa09f26e0b7d8e0fe02717f` |
| Embedded worker | `67e537f205c7a5aae18fc99b5d6a85189a15f2e428c7f10dcbd507f96e2c8855` |
| Closed 28-scenario catalogue | `daed4abe034738ff45a7a8c65db7023b9c404301b671b4fc677a428fbb21cc75` |
| Closed 18-test SQLite gate catalogue | `fb624094c805d1d1bc5eb23ad2aaa457cbfe3693825382ce732f3f2b7de45bae` |
| Exact 40-table SELECT-only grant catalogue | `4684fe0394cb17bb12d0b38237fedefca2dfb20c2a90fccccb9668a17df1363d` |
| Command catalogue | `604825c29c64389a0c2bb308ee4079bc4bafca1f7cf1597d416d23caf3c6c980` |
| Result catalogue | `c6997741bf43516c6c504132db53479a641887f34b99ef5d767ee3d8c43b9d0d` |

## Exact artifact register

| Engine | Exact reported version | Scenarios | SQLite gate | Strict cleanup | Artifact | Artifact SHA-256 |
|---|---:|---:|---|---|---|---|
| PostgreSQL | `170010` (17.10) | 28/28 PASS | PASS | PASS | `storage/app/portability-rehearsals/20260902T085041Z-postgresql17-inpatient-accommodation-tariff-source-c142b60f25f8.json` | `34de88da216fa8ab40b6116039a94a85348ca8147102ecb1ae965eaf0ddfa824` |
| MySQL | `8.4.11` (InnoDB) | 28/28 PASS | PASS | PASS | `storage/app/portability-rehearsals/20260902T085713Z-mysql8411-inpatient-accommodation-tariff-source-5b8728694230.json` | `cb425544f6231693efce9b9687ba98c1cf7a58dcb66078dfb8c914bc43ec117c` |

Both artifacts were published create-only with mode `0600`. Both record `status=PASS`, `owner_acceptance_claim=false`, `deployment_claim=false`, `g0_claim=false`, `g3_claim=false`, and `cleanup.strict_cleanup_verified=true`.

## Closed scenario result

| Exact scenario | PostgreSQL 17.10 | MySQL 8.4.11 |
|---|---|---|
| `fresh-migration` | PASS | PASS |
| `empty-down-reapply` | PASS | PASS |
| `exact-role-boundary` | PASS | PASS |
| `prospective-bed-version-provenance` | PASS | PASS |
| `database-provenance-trigger-refusal` | PASS | PASS |
| `legacy-provenance-no-inference` | PASS | PASS |
| `occupancy-day-calendar-allocation` | PASS | PASS |
| `admission-census-cancellation-open-interval-no-charge` | PASS | PASS |
| `exact-effective-dated-binding` | PASS | PASS |
| `future-half-open-terminal-retirement` | PASS | PASS |
| `exact-context-and-positive-integer-refusal` | PASS | PASS |
| `four-domain-typed-union` | PASS | PASS |
| `closed-interval-source-materialization` | PASS | PASS |
| `open-interval-partial-sync-issue-refusal` | PASS | PASS |
| `routine-discharge-complete-bill` | PASS | PASS |
| `replay-conflict-retroactive-stale-refusal` | PASS | PASS |
| `same-service-day-import-concurrency` | PASS | PASS |
| `transfer-synchronization-concurrency` | PASS | PASS |
| `discharge-issue-cutoff-concurrency` | PASS | PASS |
| `bed-version-binding-resolution-concurrency` | PASS | PASS |
| `competing-binding-writer-concurrency` | PASS | PASS |
| `application-sql-guard-refusal` | PASS | PASS |
| `database-append-only-head-trigger-check-refusal` | PASS | PASS |
| `audit-corruption-reconciliation-refusal` | PASS | PASS |
| `least-privilege-runtime` | PASS | PASS |
| `reset-recovery-concurrency` | PASS | PASS |
| `retained-evidence-rollback-refusal` | PASS | PASS |
| `strict-cleanup` | PASS | PASS |

The concurrency scenarios used independent application processes, reported the actual write-connection backend IDs, observed native database waits and the expected lock order, and reconciled durable state. The binding-resolution race additionally proved both participants locked the same canonical binding row. The routine-discharge flow retained transfer at 2026-09-03 00:00:00, discharge at 2026-09-04 08:00:00, exact 2026-09-02/03/04 accommodation source dates, three accommodation bill lines, and net amount 34000 in synthetic local evidence only.

## Validation register

- Harness contract: 16 runs, 523 assertions, PASS.
- SQLite application gate: 18 closed filtered tests, PASS before both exact-engine runs.
- Reset-order regression: a real routine discharge with three discharge-backed accommodation sources/charges and an issued bill/lines is deleted in dependency order while required audit evidence is preserved; focused recovery/reset checks passed before the final exact runs.
- PostgreSQL and MySQL artifact source, worker, scenario, SQLite-catalog, grant-catalogue, command-catalogue, and result-catalogue bindings are identical.
- PostgreSQL reported `server_version_num=170010`; MySQL reported `8.4.11`, InnoDB, isolated loopback, and harness-owned disposable state.
- Both exact disposable databases, temporary servers, temporary identities, startup barriers, and worker files were strictly removed.

## Claim boundary

This is local engineering evidence for the bound inpatient accommodation occupancy-day tariff-source slice only. It does not establish facility, registration, clinical, finance/revenue/cashier, RMIK, day-count-policy, affected-domain, or Sahabat parity acceptance. It does not establish hosted UAT, hosted migration, deployment, production readiness, G0 closure, or G3 acceptance. Governance selection remains unavailable with `pointer_missing`; G0 and G3 remain `OPEN`.
