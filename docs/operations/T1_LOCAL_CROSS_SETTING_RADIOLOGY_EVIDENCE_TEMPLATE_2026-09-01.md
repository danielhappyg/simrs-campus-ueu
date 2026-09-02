# T1 Local Cross-Setting Radiology Exact-Engine Evidence Template

Status: **NOT RUN — TEMPLATE ONLY**
Scope: local disposable PostgreSQL 17.10 and MySQL 8.4.11; synthetic-only SIMULATION mode
Deployment, hosted readiness, radiology-owner acceptance, parity acceptance, and production claims: **not authorized by this record**

## Bound artifacts

- Harness: `scripts/rehearse-local-radiology-portability.rb`
- Contract: `tests/Documentation/LocalRadiologyPortabilityHarnessContractTest.rb`
- Migration: `database/migrations/2026_08_31_001100_create_cross_setting_radiology_tables.php`
- Focused feature reference: `tests/Feature/Radiology/CrossSettingRadiologyWorkflowTest.php`
- Generated evidence directory: `storage/app/portability-rehearsals/`

The harness records a closed source-binding digest, worker digest, scenario-catalogue digest, runtime-grant digest, sanitized command/result catalogues, and engine-owned cleanup result. Generated JSON evidence must be a regular mode-`0600` file inside the repository-owned mode-`0700` evidence directory.

## Authorized local commands

Run only from the repository root with no inherited database, executable, or external-service overrides:

```sh
SIMRS_RADIOLOGY_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_RADIOLOGY_PORTABILITY \
  ruby scripts/rehearse-local-radiology-portability.rb postgresql17

SIMRS_RADIOLOGY_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_RADIOLOGY_PORTABILITY \
  ruby scripts/rehearse-local-radiology-portability.rb mysql8411
```

The harness creates and destroys only its own disposable local database/server/user state. It does not contact PACS, RIS, BPJS, VClaim, SATUSEHAT, mail, hosted databases, deployment services, or other external integrations.

## Execution register

| Engine | Exact version required | Result | Generated evidence path | File mode | Cleanup verified |
|---|---:|---|---|---|---|
| PostgreSQL | 17.10 | NOT RUN | — | — | — |
| MySQL | 8.4.11 | NOT RUN | — | — | — |

Do not replace `NOT RUN` with `PASS` unless the corresponding command returns a final `PASS` document and strict cleanup succeeds. A contract or syntax pass is not engine execution evidence.

## Required scenario readback

For each engine, copy the generated JSON result for all closed scenarios below; do not infer one engine from the other.

| Scenario | PostgreSQL 17.10 | MySQL 8.4.11 |
|---|---|---|
| Fresh migration | NOT RUN | NOT RUN |
| Empty down/reapply | NOT RUN | NOT RUN |
| Exact role boundary | NOT RUN | NOT RUN |
| Outpatient, emergency, and inpatient orders | NOT RUN | NOT RUN |
| Master create/update/retire | NOT RUN | NOT RUN |
| Order → perform → Draft → Verified → amend → acknowledge | NOT RUN | NOT RUN |
| Closure and cancellation blockers | NOT RUN | NOT RUN |
| Exact replay after later head advance | NOT RUN | NOT RUN |
| Changed-payload same-key conflict | NOT RUN | NOT RUN |
| Two-process same-key race and unique reconciliation | NOT RUN | NOT RUN |
| Application SQL write guard refusal | NOT RUN | NOT RUN |
| Database snapshot/delete/truncate refusal | NOT RUN | NOT RUN |
| Full evidence-chain corruption refusal | NOT RUN | NOT RUN |
| Least-privilege runtime grants | NOT RUN | NOT RUN |
| Bounded reset with amendment-first deletion and audit preservation | NOT RUN | NOT RUN |
| Retained-evidence rollback refusal | NOT RUN | NOT RUN |
| Durable invariant verification | NOT RUN | NOT RUN |

## Least-privilege and evidence checks

- Runtime reference tables are `SELECT` only. The encounter head has `SELECT, UPDATE` because PostgreSQL row locking requires update authority; it has no `INSERT` or `DELETE`.
- `radiology_examination_masters` and `radiology_orders` allow `SELECT, INSERT, UPDATE`, but no `DELETE`.
- Master reservations/versions, cancellations, performances, report versions, acknowledgements, operation receipts, and audit events allow `SELECT, INSERT`, but no `UPDATE` or `DELETE`.
- The reset identity is separate and is used only for the bounded synthetic reset.
- The reset must remove the synthetic radiology order graph in dependency order, deleting amended report versions before their verified bases, while preserving master evidence and radiology/reset audit events.

## Claim boundary

Even after both rows are truthfully recorded as `PASS`, this artifact proves only the bound local bytes on disposable exact engines. It does not prove hosted migration, production configuration, capacity, PACS/RIS interoperability, clinical validation, radiology-owner acceptance, operational support readiness, or deployment approval.
