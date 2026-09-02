# Local managed inpatient ward/bed master evidence — 2026-08-30

## Evidence boundary

**Classification: `LOCAL / NOT DEPLOYED`.** This record covers the current uncommitted managed inpatient ward/bed master, encounter-derived occupancy census, and inpatient-registration integration. It records no commit, push, pull request, GitHub Actions run, Vercel deployment, hosted Supabase migration, hosted UAT, facility/registration acceptance, product-owner release acceptance, or Sahabat parity acceptance.

The implementation remains inside the established simulation boundary: synthetic records only, no real patient data, no secrets, and no live BPJS, VClaim, SATUSEHAT, LIS, PACS, payment, pharmacy, device, or other external integration.

## Implemented workflow

The local workflow now provides one managed source of truth for inpatient placement:

1. authorized administrators create, rename, activate, and retire wards and beds through versioned server operations;
2. ward and bed codes remain immutable, unique after canonical normalization, and permanently reserved after reset so an old identity cannot be silently reused;
3. every successful mutation produces an immutable version, operation receipt, and atomic audit event;
4. inpatient registration accepts an immutable managed bed identity, derives the ward, bed label, class and foreign-key snapshots on the server, and rejects unmanaged, unavailable, retired, stale or already occupied beds;
5. the occupancy census is derived from managed masters plus active inpatient encounters and keeps empty, full, historical and retired wards visible where their workflow requires them; and
6. registration and examination filters use managed and historical ward identities instead of mutable names or a static fallback catalogue.

This slice does not implement transfer, discharge, charge, claim, pharmacy, external bed feeds, or any live integration.

## Integrity and authorization controls

- Read access uses `inpatient.occupancy.view`; mutations use the narrower `master.inpatient.ward-bed.manage` capability plus the administrator/system-administrator actor policy.
- Authorization is checked before manual public-ID lookup, and the service repeats the actor-policy check rather than relying on the controller alone.
- Ward/bed aggregates, versions, receipts and reservations reject ordinary direct writes; a SQL listener also rejects accidental direct data-changing statements outside the bounded mutation scope.
- Server mutations use ward → bed → claim-mutex → occupancy locking, optimistic expected versions, canonical lowercase idempotency keys, stable same-payload replay and audited conflicting replay.
- Registration uses `SchemaAwareRules` with model classes, never a schema-qualified string in `Rule::exists`.
- PostgreSQL foreign keys and sequences are explicitly qualified for the `laravel` schema; the migration is structural only and performs no legacy/import backfill.
- Rollback succeeds only when the new structures contain no managed or correlated audit evidence; populated state and retained evidence fail closed.
- Census reads use an engine-appropriate repeatable snapshot so claim counts and master labels cannot be mixed across two committed states.
- Reset removes synthetic master chains while retaining permanent code reservations; teaching census seeding resolves one current `ACTIVE` managed bed and records its foreign key.
- The Indonesian UI exposes semantic totals, accessible filters and dialogs, status text in addition to color, permission-aware actions, retirement blockers, server replay/error announcements and focus recovery.

## Local quality gates

| Gate | Result |
| --- | --- |
| Full PHP gate (`composer test`) | PASS — 623 tests; 619 passed; 4 skipped; 8,979 assertions; Pint PASS; PHPStan 0 errors |
| Full frontend unit suite | PASS — 15 files; 72 tests |
| TypeScript, ESLint, Prettier | PASS |
| Focused ward/bed feature suite on PostgreSQL 17.10 | PASS — 20 tests; 352 assertions |
| Focused ward/bed feature suite on MySQL 8.4.11 | PASS — 20 tests; 352 assertions |
| Portability harness contract | PASS — 19 tests; 356 assertions |
| Working-tree diff check | PASS |

The four full-PHP skips are retained conditional tests, not ward/bed failures. The full application and frontend suites were completed after the ward/bed implementation; subsequent changes were limited to hardening and correcting the portability harness and its evidence assertions.

## Exact-engine portability and concurrency evidence

`scripts/rehearse-local-inpatient-master-portability.rb` owns an isolated disposable engine, applies a fresh migration, seeds the RBAC fixture, runs the focused feature suite, launches independent PHP application processes for locking scenarios, verifies durable results from a third connection, executes controlled census interleaving, checks source binding, writes mode-`0600` sanitized evidence, and removes database/server/user state before returning PASS.

Both engines passed the same nine-scenario catalogue:

| Scenario | Verified durable result |
| --- | --- |
| Duplicate normalized code | one canonical identity is created; the competing normalized duplicate is denied |
| Same expected-version update | one update applies; the stale competing update is denied |
| Identical replay | one mutation applies and the same actor/operation/key/payload returns a stable replay |
| Conflicting replay | the changed payload is denied and audited without changing the original result |
| Admission vs retirement | observed admission-first lock race leaves one active encounter and denies retirement; a separate sequential retirement-commit then fresh-admission proof denies the new admission |
| Database constraints | engine-native uniqueness, state and relationship constraints hold |
| Census repeatable read | an exact immutable ward-code target is observed once with its pre-rename master after a controlled concurrent rename commit |
| Empty rollback | clean down succeeds and the migration reapplies |
| Populated/evidence rollback refusal | raw populated and audit-evidence cases refuse down; reset removes master chains, retains the code reservation and refuses code reuse |

The first five scenarios use two independent application processes, observe a real database wait, and verify durable results from a third connection. Only the admission-first ordering is claimed as an observed concurrent lock race. The retirement-first guarantee is intentionally labeled as a committed retirement followed by a fresh denied admission.

## Final exact-engine records

The final PostgreSQL and MySQL records share source-binding aggregate SHA-256 `080fe8f9183c8c88f8d0027456560ae36f9f0ab6bdaa85f346ec7cdfe6bdcae4`, scenario-catalogue SHA-256 `95e508c400f365154c36cb95588c16ffd4195c535efb1fe906b07faa2d828033`, and PHP-worker SHA-256 `af1241634134c4af6e31fde6813c9b48d51afcddcd13edfde65b59f489498b69`.

- PostgreSQL 17.10 / `laravel` schema: `storage/app/portability-rehearsals/20260830T122410Z-postgresql17-inpatient-master-portability-a9a3262b9cde.json`; mode `0600`; SHA-256 `ca641eee9a5aea99ed1248115801e25f13fcbb9c8ce86a71820e17bef613f478`.
- MySQL 8.4.11 / InnoDB: `storage/app/portability-rehearsals/20260830T122554Z-mysql8411-inpatient-master-portability-2c25d2504c24.json`; mode `0600`; SHA-256 `cdfbc88872be78136697c378fc7e352845e2ae92b1e9f7caae389ba25f22b804`.

The retained records contain no supplied database password, DSN, database/user name, port, socket path, process identifier, raw test output, patient/account value, or external target. Both report that disposable database, temporary server and temporary user state were removed.

## Remaining boundary

This local checkpoint does not establish hosted migration compatibility, pooled/proxy behavior, browser UAT, backup/restore, load, capacity, SLA, facility/registration acceptance, complete PAR-ADM-009/PAR-REG-001 acceptance, full 268-capability coverage, G0/G3 closure, Sahabat parity, or production readiness. Any later commit, push, deployment, or hosted `laravel` schema migration remains a separately authorized batch.
