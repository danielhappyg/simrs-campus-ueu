# Local MySQL and Recovery Validation Record

- **Date:** 2026-07-16
- **Environment:** local disposable MySQL 9.7.1 database on macOS arm64
- **Application mode:** `SIMULATION` with synthetic-only enforcement
- **Release status:** development evidence only; not a hosted recovery or production-readiness approval

## Outcome

The complete migration set through `2026_07_16_001300_create_procedure_documentation_corrections_table` installs on a real MySQL server. The latest migration also rolls back and reapplies successfully. The opt-in simulation seeder loads without introducing non-synthetic patients or broken encounter/task relationships.

The current full-journey rehearsal imported the two verified local ICD workbooks, completed the reference encounter through guarded domain services, created human-attributed manual ICD-10 and ICD-9-CM assignments, reached `FINALIZED`, and released the debrief tasks. Its single-transaction logical backup restored into a second empty database with byte-identical normalized schema metadata and ordered data. The restored application returned HTTP 200 from `/up`, recognized the encounter as already finalized without mutation, and passed the checked relationship queries.

A preceding logical backup/restore rehearsal through migration `001200` restored the foundation fixture into a second empty database. Its hashes remain recorded as historical migration evidence. The newer `001300` rehearsal below supersedes it for OPS-01 local full-journey evidence. All disposable databases and temporary dump files were deleted after both validations.

After migration `001400` added shared debrief teaching evidence, the complete backend suite was also run against a fresh disposable real-MySQL database. All 147 tests and 1,825 assertions passed across 57 base tables. This verifies the current application test contract on MySQL 9.7.1; it does not substitute for the configured MySQL 8.4 GitHub job's first remote run.

The first cross-database run exposed one test-only portability defect: a diagnostic-result assertion treated decoded JSON object-key order as significant. MySQL normalizes JSON object keys while SQLite preserves insertion order. The assertion now checks semantic array equality while the source integrity hash remains derived from `CanonicalJson`, preserving deterministic provenance.

## Defects found and corrected

The first real-MySQL migration exposed three automatically generated identifiers longer than MySQL's 64-character limit:

- the replacement-request foreign key on `pharmacy_intervention_messages`;
- the candidate foreign key on `coding_suggestion_decisions`; and
- the unique key on `coding_documentation_corrections.coding_suggestion_decision_id`.

Each migration now declares a short, stable identifier explicitly. The relational model and delete behavior were not changed.

## Migration and seed evidence

| Check                          | Result                                                                  |
| ------------------------------ | ----------------------------------------------------------------------- |
| Fresh migration on MySQL 9.7.1 | Passed: all 18 migrations through `001300`                              |
| One-step rollback of `001300`  | Passed                                                                  |
| Reapply of `001300`            | Passed                                                                  |
| Opt-in `DemoSimulationSeeder`  | Passed                                                                  |
| Effective schema               | 55 base tables; 17 constraints on `procedure_documentation_corrections` |
| Seeded users                   | 10 reserved training accounts                                           |
| Seeded simulation context      | 1 scenario session, 1 synthetic patient, 1 encounter                    |
| Seeded assignments/tasks       | 10 assignments, 4 initial work tasks                                    |
| Debrief authorization fixtures | 10 active assignments with `DebriefView` capability                     |
| Seeded pharmacy fixture        | 1 synthetic stock lot                                                   |
| Non-synthetic patients         | 0                                                                       |
| Orphan encounters              | 0                                                                       |
| Orphan work tasks              | 0                                                                       |

No raw ICD workbook or real patient data was copied into either disposable database.

## Full reference-journey backup and restore evidence for `001300`

The source database was created fresh, seeded only after the explicit simulation opt-in, and populated through `simulation:complete-reference-journey`. The command used the same domain services and invariants as the application workflow. It required one active release for each classification and selected fixture codes manually through an attributed coder decision; it did not create an autonomous final code.

| Check                            | Result                                                                                                                                                                                                                                                            |
| -------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Verified ICD-10 import           | Passed: `ICD10_2010`, 18,543 rows, SHA-256 `3c22aa15012dd2e15576657e49001291fd21a5b30ce797998a495aac548c5f4e`                                                                                                                                                     |
| Verified ICD-9-CM import         | Passed: `ICD9CM_2010`, 4,626 rows, SHA-256 `9f625ada077b198e75e5f6a51596191cb9de94be198a967cedf07a52e08f8d78`                                                                                                                                                     |
| Reference-journey completion     | Passed: `FINALIZED`, two approved clinical versions, one result/acknowledgement, one pharmacy review/dispense/stock movement, one approved closure/procedure, one approved RMIK review, two human-approved coding assignments, 27 work tasks, and 37 audit events |
| Idempotent completed-state rerun | Passed: reported `ALREADY_FINALIZED`; material counts unchanged                                                                                                                                                                                                   |
| Non-synthetic patients           | 0                                                                                                                                                                                                                                                                 |
| Checked source relationships     | Passed: zero orphan encounter, clinical-version, result, pharmacy, closure/procedure, RMIK/coding, and audit-context links                                                                                                                                        |
| Base-table count                 | Matched: 55 source / 55 restored                                                                                                                                                                                                                                  |
| Normalized schema metadata       | Exact byte match; SHA-256 `cfb399be7fa0527bcab9382c1992855faf0ed7c2e1934ab60f0980be51114bac`                                                                                                                                                                      |
| Ordered data-only logical dump   | Exact byte match; SHA-256 `10b40e3beff2cda394a652c8d38877b255015e2ab42925aa9adc3e6f9219f938`                                                                                                                                                                      |
| Restored relationship check      | Passed: zero checked orphan links                                                                                                                                                                                                                                 |
| Restored application behavior    | Passed: command reported `ALREADY_FINALIZED` with unchanged counts                                                                                                                                                                                                |
| Restored health endpoint         | Passed: HTTP 200 from `/up`                                                                                                                                                                                                                                       |

This locally satisfies the relationship, immutability, synthetic-data, and health/readiness evidence described by OPS-01. It is development evidence, not proof of hosted backup retention, access control, restore timing, or disaster-recovery governance.

## Backup and restore evidence for the `001200` snapshot

The backup used a single-transaction logical dump with table locks and GTID replay disabled. The restore ran in a newly created empty database and did not invoke application workers, webhooks, or external integrations.

| Comparison                                | Result                                                                                              |
| ----------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Base-table count                          | Matched: 54 source / 54 restored                                                                    |
| Normalized column and constraint metadata | Exact match: 1,558 rows; SHA-256 `1302c24242b5cdef86f50d895935c0b921bcbfe31351d7e1e5b32d18be4ef1e5` |
| Ordered data-only logical dump            | Exact byte match; SHA-256 `f597bdba45dc11211d2ee0a47e279055ce464db295d5c1c87b1e26c5afca8b85`        |
| Representative entity counts              | Exact match for users, sessions, patients, encounters, assignments, and tasks                       |
| Restored encounter relationship check     | Passed: zero orphaned patient/session links                                                         |
| Restored task relationship check          | Passed: zero orphaned encounter/assignment links                                                    |

The raw schema dump text was not used as the equivalence test because MySQL serializes inherited table character sets as explicit column character sets after import. `information_schema` reported the same effective column definitions and constraints in both copies.

## Reproduction boundary

Use only an isolated database that can be deleted. Never run `migrate:fresh` against shared, staging, production-like, or production data.

The validated full-journey sequence is:

1. create an empty disposable MySQL database;
2. run the complete migration set;
3. roll back and reapply the latest migration;
4. enable simulation-only demo seeding explicitly;
5. import the exact checksummed ICD-10 and ICD-9-CM releases;
6. run `simulation:complete-reference-journey` and verify its finalized summary;
7. rerun it to verify the completed-state no-op boundary;
8. create a single-transaction logical backup with GTID replay disabled;
9. restore into a second empty disposable database;
10. compare effective schema metadata, ordered data, material counts, and critical relationships;
11. start the restored application and verify `/up` plus the finalized no-op behavior; and
12. delete only the disposable databases and temporary backup artifacts.

Passwords, host credentials, and environment-specific database names must stay outside this record and outside Git.

## Remaining gates

The local full-journey rehearsal completes the current OPS-01 development evidence. It does **not** complete OPS-02 and does not prove Hostinger backup controls, recovery time, release-artifact promotion, or application rollback.

The following remain required:

1. the configured repository workflow's exact MySQL 8.4 migration and 147-test backend run after Git publication;
2. read-only Hostinger capability, backup-retention, and isolation preflight;
3. staging artifact deployment, smoke test, failed-promotion behavior, and application rollback;
4. hosted restore rehearsal with approved recovery-time and access-control evidence; and
5. stakeholder approval before any teaching pilot.
