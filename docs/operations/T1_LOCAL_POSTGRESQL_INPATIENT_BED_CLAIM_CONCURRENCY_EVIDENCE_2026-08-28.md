# T1 local PostgreSQL inpatient bed-claim concurrency evidence — 2026-08-28

## Decision

**LOCAL TECHNICAL PASS / SYNTHETIC-ONLY / NOT PUSHED / NOT DEPLOYED / NOT HOSTED ACCEPTANCE**

Commit `28ab1d8122f59bd36ffbb5909650efdc07438334` replaces the global inpatient bed-claim mutex with one stable mutex row per `bed_code`. It also adds a schema-qualified mutex table, the `encounters_care_bed_status_idx` lookup index, a disposable PostgreSQL rehearsal, and deterministic frontend test execution.

This closes the local PostgreSQL proof gap for concurrent inpatient bed claims. It does not authorize Supabase migration, Vercel deployment, Production maintenance, role activation, or G3 acceptance.

## Safety boundary

- `APP_MODE=SIMULATION`
- synthetic-only records
- no real patient data
- no Supabase or hosted database access
- no BPJS, VClaim, SATUSEHAT, payment, LIS, PACS, pharmacy, messaging, or other live integration
- harness-owned PostgreSQL `17.10` cluster only
- Unix socket only; TCP disabled; host authentication rejected
- cluster, socket, and evidence directory mode `0700`; evidence file mode `0600`
- generated database and owned cluster removed before PASS evidence was written

The rehearsal rejected inherited `DB_URL`, `PG*`, and executable-override variables. It generated its own application key, synthetic account password, database name, and socket path. No generated value is recorded here.

## Implemented control

`InpatientBedClaimGuard` now:

1. requires an active database transaction;
2. inserts or resolves the mutex row for the exact selected bed;
3. obtains `SELECT ... FOR UPDATE` on that bed's row;
4. rechecks for a non-closed inpatient encounter in the bed;
5. retains the lock through queue allocation, encounter creation, required audit write, and commit or rollback.

Different beds no longer wait on one global bed mutex. `DailyQueueAllocator` remains unchanged: complete registrations for the same date may still serialize briefly on the one fact that must be serialized, the next daily queue number.

## Independent-process PostgreSQL result

| Phase | Required invariant | Result |
| --- | --- | --- |
| Same bed | two independent PostgreSQL sessions; the second is observed waiting on the first bed claimant; one commits; one is rejected as occupied; exactly one active encounter remains | PASS |
| Different beds | two independent PostgreSQL sessions; worker B commits while worker A still holds bed A; no bed-mutex lock wait or blocker for worker B; both active encounters remain | PASS |
| Full registration | different bed mutexes remain independent; the expected daily-counter wait is observed separately; both registrations commit with queue numbers `1` and `2`; high-water mark is `2` | PASS |

Aggregate evidence:

- local ignored path: `storage/app/inpatient-bed-claim-rehearsals/20260828T063754Z-c04442482cc0.json`
- SHA-256: `227503a35bba8ed62098f4d4e7b633e4b9fb1eedd0ca63c91e2cecbe8bab39ce`
- source files bound: `19`
- source-contract SHA-256: `102cce6d8843878ab62d81459ae2b13474021a6dea6f47d8ecf756af03dbb45e`
- migration-file-set SHA-256: `38e557db55eb5a39e04564a6a05a4409986e5a0a6af390dfb83ca38425701ef8`

The aggregate evidence intentionally omits database identifiers, run token, socket/host, backend PIDs, bed codes, credentials, and patient identifiers.

## Verification

Authoritative integrated run:

```text
composer ci:check
Frontend: 12 files, 45 tests passed
PHP formatting: passed
PHP static analysis: passed, 0 errors
PHPUnit: 546 tests, 542 passed, 4 skipped, 6,830 assertions
Exit: 0
```

Additional checks:

- rehearsal contract: `11` tests, `176` assertions, PASS;
- disposable PostgreSQL migration, focused feature tests, rollback, and reapply: PASS;
- rollback confirmed the mutex table and encounter lookup index absent before reapply;
- `git diff --check`: PASS.

The migration file SHA-256 is `c2ba20c90dcc61e6b957306258bdad0ebc40dc82272d0d600482c68a78c4da36`.

## Sealed security review

Codex Security diff scan `9baa507e-425c-4579-afb3-8bcb7fcff440` reviewed exact range:

```text
46ce2eaaa610aa10164854f761c2be38441942bb
..
28ab1d8122f59bd36ffbb5909650efdc07438334
```

- TAC advisory: `granted`, level `tac1`;
- changed-source inventory: `6/6` closed;
- reportable findings: `0`;
- coverage: complete;
- report SHA-256: `75b0340cdb6c6d205a6ca7a5f9accd1b2482c5c4999282d09fd704306bc00b8d`;
- measured usage: `9,948,448` total tokens, `9,910,046` input tokens, `9,586,176` cached input tokens.

The readable report is retained outside the repository at the scanner-owned path recorded in the G3 remediation register.

## Release consequence

The existing nine-migration cutover packet is no longer executable for this candidate. The new migration changes the ordered manifest, expected ledger, post-migration schema contract, preservation/acceptance SQL, rollback evidence, exact release SHA, and Preview identity. The local [ten-migration cutover packet](T1_TEN_MIGRATION_CUTOVER_EXECUTION_CONTROL_2026-08-28.md) now freezes the replacement contract; independent acceptance, exact carrier/Preview binding, and action-time receipts remain required before any maintenance or hosted migration.

## Remaining boundary

This evidence proves local PostgreSQL locking behavior, not hosted pool capacity, request latency, worker topology, application deployment, database migration, backup/restore, or role UAT. The trusted-edge forwarding-header and shared-cache proof remains separately open in the G3 register.
