# T1 Local Laboratory Verified-Result Tariff/Source Exact-Engine Evidence

Status: **PASS — LOCAL DISPOSABLE SQLITE, POSTGRESQL 17.10, AND MYSQL 8.4.11 ONLY**

Recorded: 2026-09-02

Deployment, hosted readiness, owner/domain/parity acceptance, G0, G3, and production claims: **false / not established by this record**

## Executed boundary

The fail-closed rehearsal exercised only the original immutable laboratory result whose state is `VERIFIED`, whose `base_verified_version_id` is `NULL`, and whose `verified_at` supplies the service date. It used the repository-local simulation configuration, synthetic records, SQLite, and harness-owned disposable exact engines. No hosted database, live integration, real hospital price, payment, accounting, claim, BPJS, VClaim, SATUSEHAT, LIS, analyser, deployment, or migration authority was exercised.

The pre-run template at `docs/operations/T1_LOCAL_LABORATORY_TARIFF_SOURCE_EVIDENCE_TEMPLATE_2026-09-02.md` is part of the immutable executed source binding and therefore remains unchanged. This final record was created after both exact artifacts completed and is intentionally not claimed as an input to those executions.

## Immutable execution binding

Both exact artifacts bind the identical executed bytes:

| Binding | SHA-256 |
|---|---|
| Application/source manifest | `7ac7e49b2a1577ad307fa0a37ae68beadb6b2c39962df66ffaf5ae5f0aacc74e` |
| Embedded worker | `b29447189b58731bb26a49ddcbce8d1a8d18701d14ac8f9ace51a0a87733f33b` |
| Closed 22-scenario catalogue | `bc8e4417f234cc80cc355cb1ed7f25e76b6eff8c5cd24921a2ba37da6f85c5dd` |
| Closed SQLite gate catalogue | `6386c172fd36d7de79de263b111b209fe21b51d9518642b398cf3a7ad6278e46` |
| Least-privilege grant catalogue | `affb34965f90aa43b8c03f513a4813bad7f6c274d76a7c66dd9e3268c3d657f6` |

## Exact artifact register

| Engine | Exact reported version | Scenarios | SQLite gate | Strict cleanup | Artifact | Artifact SHA-256 |
|---|---:|---:|---|---|---|---|
| PostgreSQL | `170010` (17.10) | 22/22 PASS | PASS | PASS | `storage/app/portability-rehearsals/20260902T052603Z-postgresql17-laboratory-tariff-source-fcfeacb4735a.json` | `b7403d60e0e667e61d5d53ff1575e3bbdca8717ac86d90379573235b80c71efa` |
| MySQL | `8.4.11` | 22/22 PASS | PASS | PASS | `storage/app/portability-rehearsals/20260902T052935Z-mysql8411-laboratory-tariff-source-eab90648c5b1.json` | `720a838f46555d20db554404935ccebabd43c310c724ad53ae92c1e960bf0c64` |

Both artifacts were published create-only with mode `0600`. Both record `status=PASS`, `owner_acceptance_claim=false`, `deployment_claim=false`, `g0_claim=false`, `g3_claim=false`, and `cleanup.strict_cleanup_verified=true`.

## Closed scenario result

| Scenario | PostgreSQL 17.10 | MySQL 8.4.11 |
|---|---|---|
| Fresh migration and empty typed tables | PASS | PASS |
| Empty down/reapply | PASS | PASS |
| Exact finance-steward/cashier/admin/mixed boundary | PASS | PASS |
| Explicit outpatient, emergency, and inpatient bindings | PASS | PASS |
| Original VERIFIED `verified_at` service-date resolution | PASS | PASS |
| Future authoring, half-open selection, and terminal retirement | PASS | PASS |
| Missing/retired/mismatched result-chain refusal | PASS | PASS |
| Order/specimen/Draft/cancellation activity creates no charge | PASS | PASS |
| One original VERIFIED result creates one typed source and charge | PASS | PASS |
| Verified amendment and acknowledgement create no extra charge | PASS | PASS |
| Laboratory-only immutable bill snapshot | PASS | PASS |
| Partial synchronization and unresolved issuance refusal | PASS | PASS |
| Later gap resolution creates a new version without rewriting history | PASS | PASS |
| Replay, changed-key payload, retroactive and stale refusal | PASS | PASS |
| Two-process same-result import serialization/reconciliation | PASS | PASS |
| Two-process competing binding writer serialization/refusal | PASS | PASS |
| Verification/synchronization cutoff race | PASS | PASS |
| Application SQL guard refusal | PASS | PASS |
| Database mutable-head, append-only, and typed-source refusal | PASS | PASS |
| Required audit, corruption, and reconciliation refusal | PASS | PASS |
| Read-back least-privilege runtime | PASS | PASS |
| Bounded reset/recovery, rollback refusal, and strict cleanup | PASS | PASS |

The three race scenarios used two independent application processes, observed a real database wait and the expected lock order, and completed without a deadlock. The verification/synchronization cutoff admitted only a committed original `VERIFIED` result.

## Validation register

- Harness contract: 10 runs, 348 assertions, PASS.
- Authorization contract: 9 runs, 132 assertions, PASS.
- SQLite application gate: 14 closed filtered tests, PASS on both exact-engine runs.
- PostgreSQL and MySQL artifact source, worker, scenario, SQLite-catalog, and least-privilege-catalog bindings are identical.
- Both exact disposable databases, temporary servers, temporary identities, and worker files were strictly removed.

## Claim boundary

This is local engineering evidence for the bound laboratory verified-result tariff/source slice only. It does not establish owner acceptance, clinical-domain acceptance, Sahabat parity, hosted UAT, hosted migration, deployment, production readiness, G0 closure, or G3 acceptance. Governance selection remains unavailable with `pointer_missing`; G0 and G3 remain `OPEN`.
