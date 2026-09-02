# Local outpatient post-closure amendment evidence — 2026-08-30

## Evidence boundary

**Classification: `LOCAL / NOT DEPLOYED`.** This record covers the current uncommitted outpatient post-closure amendment and renewed RMIK review slice. It records no commit, push, pull request, GitHub Actions run, Vercel deployment, hosted Supabase migration, hosted UAT, production-readiness claim, Clinical/RMIK acceptance, product-owner acceptance, or Sahabat parity acceptance.

The implementation remains inside the established simulation boundary: synthetic records only, no real patient data, no secrets, and no live BPJS, VClaim, SATUSEHAT, LIS, PACS, payment, pharmacy, device, or other external integration.

## Implemented workflow

The local workflow now preserves the original closed outpatient record and appends a separate attributable correction chain:

1. the original encounter remains `CLOSED`, with its final clinical document and original RMIK sign-off immutable;
2. the requesting physician submits a bounded reason against the exact final source version;
3. a different physician approves or denies the request;
4. the requester alone writes and finalizes the separate addendum;
5. finalization atomically makes the addendum `FINAL` and the request `CONSUMED` without reopening the encounter; and
6. RMIK saves an append-only addendum-specific completeness snapshot and may create one terminal renewed `SIGNED_OFF` snapshot when its exact source fingerprint is current and no active laboratory order remains.

The renewed sign-off applies only to the addendum-specific snapshot. It does not replace, revise, erase, or relabel the original RMIK review or sign-off.

## Integrity and authorization controls

- Physician request, approval, addendum-write, and addendum-finalize capabilities are narrow and server-authorized before manual public-ID lookup. Administrator, system-administrator, registrar, nurse, and RMIK roles cannot invoke physician amendment mutations.
- The requester cannot approve or deny their own request; the approver cannot author or finalize the addendum.
- RMIK review and sign-off remain RMIK-only. A renewed `SIGNED_OFF` snapshot is terminal, including direct endpoint retries using an older save key.
- Request, addendum, version, review, checklist item, source document/version, original RMIK snapshot/item, and operation-receipt model boundaries reject ordinary mutation or deletion as applicable.
- Guarded aggregate creation rejects fabricated final addenda, orphan `CONSUMED` requests, malformed renewed reviews, incomplete checklist evidence, provenance drift, and out-of-sequence versions.
- The canonical `outpatient-amendment-source-fingerprint-v1` input contains the latest original signed review identity/version/fingerprint, all final addendum identities/versions/content digests in bytewise order, and all active laboratory-order public IDs in bytewise order.
- Active laboratory orders are fingerprinted and block renewed sign-off.
- Actor + operation + idempotency-key receipts use payload digests, immutable result references, atomic success audit, conflict denial audit, and locking current reads for replay reconciliation under both PostgreSQL `READ COMMITTED` and MySQL `REPEATABLE READ`.
- The migration refuses non-simulation/non-synthetic operation, adds portable row-local checks outside SQLite, qualifies PostgreSQL sequences for the `laravel` schema, and refuses rollback while amendment rows or correlated audit evidence remain.
- The Indonesian UI uses server permission plus non-null action URL gating, required-field and validation-summary association, projected version/fingerprint rebasing, keyboard-safe confirmation, focus recovery, and accessible status announcements.

## Local quality gates

| Gate | Result |
| --- | --- |
| Full PHP gate (`composer test`) | PASS — 603 tests; 599 passed; 4 skipped; 8,235 assertions; Pint PASS; PHPStan 0 errors |
| Full frontend unit suite | PASS — 14 files; 59 tests |
| TypeScript, ESLint, Prettier | PASS |
| Focused amendment + renewed RMIK regression | PASS — 29 tests; 330 assertions |
| Amendment concurrency harness contract | PASS — 10 tests; 175 assertions |

The four full-PHP skips are retained pre-existing conditional tests, not amendment failures.

The repository-wide Ruby documentation sweep is not treated as a passing gate for this uncommitted checkout. It correctly exposed existing stale/hash-bound T1 and G0 artifacts, including a historical manifest pinned to another HEAD, evidence-map/ledger projection drift, and older decision-pack hashes. Those authority artifacts were not regenerated, selected, or published. The amendment authorization, governance-simplification decision, and concurrency harness contracts are checked separately.

## Deterministic independent-process concurrency

`scripts/rehearse-local-outpatient-amendment-concurrency.rb` owns an isolated disposable engine, applies a fresh migration, launches separate PHP application processes, requires engine-native lock-wait observation, verifies durable state from a third connection, checks source binding before and after all scenarios, emits mode-`0600` sanitized evidence, and removes database/server/user state before returning PASS.

Every engine passed the same five-scenario catalogue:

| Scenario | Durable result on PostgreSQL 17.10 and MySQL 8.4.11 |
| --- | --- |
| Same actor/operation/key and same digest | one applied mutation plus one stable replay |
| Same actor/operation/key and different digest across encounters | one applied mutation plus one audited idempotency conflict |
| Competing physician decisions | one version-2 approval plus one audited `request_not_submitted` denial |
| Competing addendum writes | one version-1 draft plus one audited stale-version denial |
| Competing finalization | one version-2 final addendum and version-3 consumed request plus one audited already-consumed denial |

For every scenario and engine, the retained record states `independent_processes = 2`, `real_database_wait_observed = true`, and `durable_third_connection_assertions = true`.

The first MySQL attempt failed closed and emitted no PASS evidence because a result lookup remained on the transaction's older `REPEATABLE READ` snapshot after a blocked unique-key race. Replay reconciliation was corrected to use bounded locking current reads for receipts and result rows. The final PostgreSQL run was then repeated so the final pair binds the exact same implementation bytes.

## Final exact-engine evidence

The final MySQL and PostgreSQL records share source-binding aggregate SHA-256 `814dcfadf0cd7902ef21c7517444a7a89fb7a0da99509606628bfca71bb02d06` and scenario-catalogue SHA-256 `1bd98408f58c2c10c1d3c38d9dd4d712fa5fa8aa0251bef99a9bf20c5679930f`.

- MySQL 8.4.11 / InnoDB: `storage/app/portability-rehearsals/20260830T042237Z-mysql8411-outpatient-amendment-concurrency-e9c31f67f494.json`; mode `0600`; SHA-256 `86a8b6fcdf7718295a161ab563f9263ccdb3a90fc0edcf74890a4f0b4d0fca73`.
- PostgreSQL 17.10 / `laravel` schema: `storage/app/portability-rehearsals/20260830T042321Z-postgresql17-outpatient-amendment-concurrency-bb65d4fee0a8.json`; mode `0600`; SHA-256 `89f2b0ca92085cf8d3f81c905db6c70d6292701dc256065d1580ac08e69abf91`.

The records contain no database password, DSN, database/user name, port, socket path, process identifier, raw test output, patient/account value, or external target. Both state that disposable database, temporary server, and temporary user state were removed.

## Remaining boundary

This local checkpoint does not establish hosted migration compatibility, pooled/proxy behavior, browser UAT, backup/restore, load, capacity, SLA, clinical safety acceptance, RMIK policy acceptance, owner acceptance, complete PAR-CLN-004/PAR-RMIK-001 acceptance, full 268-capability coverage, G0/G3 closure, Sahabat parity, or production readiness. Any later push, deployment, or hosted `laravel` schema migration remains a separate explicitly authorized batch.
