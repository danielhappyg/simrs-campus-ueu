# G1 break-glass acceptance contract

- **Status:** DRAFT — NOT OWNER-APPROVED
- **Date:** 2026-08-25
- **Applies to:** Proposed ADR-017 only
- **Gate:** G1 safe platform foundation
- **Boundary:** Synthetic-only SIMRS Campus UEU teaching environment

> This contract defines evidence required to accept a future implementation. It is not a claim that the capability exists, is deployed, or is safe for clinical production. It does not authorize real patient data or live BPJS/VClaim/SATUSEHAT or other production integrations.

## 1. Acceptance outcome

G1 may accept the break-glass control only when the exact reviewed release demonstrates that exceptional administrative access is:

- scoped to an allowlisted immutable capability snapshot;
- short-lived and denied at expiry without depending on a scheduler;
- requested and approved by distinct named, strongly authenticated people;
- bound only to a new post-approval MFA session;
- revoked with all subject sessions on activation, expiry and containment;
- represented in strict, protected, externally reviewable audit evidence;
- recoverable without restoring the permanent universal bypass; and
- incapable of escaping the simulation/synthetic-only boundary.

Passing unit tests alone is insufficient. PostgreSQL, MySQL, hosted role-based UAT and owner review are all required.

## 2. Non-negotiable invariants

| ID | Invariant | Failure disposition |
|---|---|---|
| BG-I01 | No runtime path grants capabilities because `is_system_administrator=true`. | P0; stop release |
| BG-I02 | Database prevents the legacy flag from becoming true after cutover. | P0; stop release |
| BG-I03 | No wildcard/all/future capability scope exists. | P0; stop release |
| BG-I04 | Platform scopes exclude clinical, RMIK, laboratory, pharmacy, inventory, finance, claim and live-integration actions. | P0; stop release |
| BG-I05 | Requester, approver and subject are separately recorded; requester != approver and approver != subject are enforced server-side. A subject may request their own access but cannot approve it. | P0; stop release |
| BG-I06 | Request/approval requires recent strong authentication and MFA. | P0; stop release |
| BG-I07 | Activation revokes all existing subject sessions; only one new MFA session can bind. | P0; stop release |
| BG-I08 | Authorization checks binding, exact snapshot, database UTC expiry and revocation on every action. | P0; stop release |
| BG-I09 | Scheduler failure cannot prolong privilege. | P0; stop release |
| BG-I10 | New activation cannot succeed without durable local strict audit. | P0; stop release |
| BG-I11 | Application-role update/delete of audit/security facts is denied on PostgreSQL and MySQL. | P0; stop release |
| BG-I12 | Synthetic reset cannot purge security/audit evidence. | P0; stop release |
| BG-I13 | Recovery requires two distinct valid custodians and cannot bypass simulation/synthetic-only guards. | P0; stop release |
| BG-I14 | Rollback and database restore begin deny-all and never restore permanent bypass. | P0; stop release |
| BG-I15 | Passwords, session IDs/cookies, private keys and recovery secret material do not appear in source, logs, audit or evidence. | P0; stop and rotate/contain |

## 3. Required implementation evidence

Evidence must identify:

- exact Git SHA and artifact/release identifier;
- environment, database engine/version and migration state;
- `APP_MODE=SIMULATION` and `APP_SYNTHETIC_ONLY=true`;
- test command, timestamp and unabridged machine result;
- actor role and synthetic fixture IDs without credentials;
- audit event public IDs and external-sink receipt/checkpoint;
- sessions found/revoked and final count;
- defects, accepted exceptions, owner and expiry;
- rollback/restore release and measured recovery result.

Local, committed, pushed, migrated, deployed, browser-verified and owner-accepted remain separate statuses.

## 4. Automated test matrix

### 4.1 Shared application-contract tests

| Area | Mandatory cases |
|---|---|
| Scope registry | known scopes resolve exact ordered snapshots; unknown, empty, wildcard, client-supplied and future capabilities deny |
| TTL/time | below-minimum, above-maximum, invalid and past windows deny; 15-minute default and 30-minute hard ceiling; database UTC boundary at one microsecond before/at/after expiry |
| Request | active/verified/named requester succeeds; inactive, unverified, shared/demo or missing-reason/reference requester denies |
| Approval | correct separate approver succeeds; self-approval, approver=subject, inactive/unverified/no-MFA/wrong capability/late approval denies |
| Digest/replay | changed subject, scope, TTL, environment, release or reason invalidates digest; a decision/envelope cannot activate twice |
| Activation | one activation per approved request and subject lease; no approval/no audit/wrong environment denies; concurrent approvals yield one activation |
| Session control | all pre-activation sessions deleted; old/stolen/unbound/new-non-MFA sessions deny; one post-MFA session binds; second binding denies or explicitly replaces with revocation evidence |
| Authorization | each granted capability permits only while bound/active; every non-snapshot capability denies; domain event includes activation reference |
| Expiry | access denies at expiry even with scheduler disabled; subsequent sweep is idempotent and revokes residual sessions |
| Revocation | authorized containment revokes and deletes sessions atomically; repeated revoke is honest/idempotent; use-versus-revoke race denies after revocation commits |
| Audit | request through expiry/revoke events contain required safe fields; strict writer failure blocks activation; no secret-like values; outbox retries without duplicate semantic event |
| Global deny | denies all elevated actions immediately while ordinary authorized RBAC remains governed normally |
| Synthetic boundary | non-SIMULATION, non-synthetic-only, live endpoint configuration and restored wrong-environment activation all deny |
| Recovery | two distinct valid signatures pass; one/duplicate/unrecognized/expired/replayed/tampered/wrong-environment signatures deny; no private material retained |
| Legacy retirement | all flags false, database rejects true, seeders never create true, old reconciliation cannot restore bypass, passwords unchanged |
| Reset | ordinary synthetic reset preserves request/decision/activation/revocation/session-binding/audit/outbox evidence; audit purge option absent/refused |

### 4.2 PostgreSQL mandatory suite

Run against the supported PostgreSQL/Supabase-compatible engine, not an SQLite emulation.

- schema-qualified reads/writes for every new table;
- foreign keys and unique decision/activation/revocation constraints;
- subject-lease row locking under concurrent approval and revoke/use races;
- database UTC and timestamp precision at expiry;
- database trigger/privilege denial for audit/security `UPDATE` and `DELETE`;
- strict transaction rollback when local audit/outbox append fails;
- session deletion and session-binding evidence in the same activation/revocation boundary;
- pooled-connection/search-path behavior;
- forward migration and schema-compatible application rollback.

### 4.3 MySQL mandatory suite

Run against the supported MySQL version intended for portable/campus hosting.

- strict SQL mode;
- equivalent foreign keys, uniqueness and check/application constraints;
- subject-lease locking with `SELECT ... FOR UPDATE` under concurrency;
- UTC timestamp precision and no session-time-zone extension of TTL;
- engine-specific trigger/privilege denial for audit/security `UPDATE` and `DELETE`;
- JSON capability snapshot/digest round trip without order or encoding drift;
- strict transaction rollback on audit/outbox failure;
- expand migration and schema-compatible rollback.

Any engine-specific behavior requires an explicit adapter/test, not conditional weakening of an invariant.

### 4.4 SQLite role

SQLite remains useful for fast deterministic unit/feature feedback. It does not prove production-like row locking, database privileges, trigger behavior, strict SQL mode, pooled schema qualification or concurrency. A green SQLite suite cannot close this contract.

## 5. Security abuse cases

Each case requires an automated denial and, where specified, a hosted demonstration:

1. A stolen session exists before activation.
2. Requester attempts to approve their own request.
3. Two accounts controlled by one fixture try to satisfy dual control.
4. Client submits a capability not in the selected scope.
5. Scope configuration changes after approval.
6. Approval or recovery envelope is replayed.
7. Scheduler is stopped before expiry.
8. Database/session clock differs from application clock.
9. Revocation races an elevated write.
10. Audit insert or protected outbox insert fails.
11. Application-role SQL attempts to update/delete audit or activation facts.
12. Seeder/recovery/rollback attempts to set the legacy flag true.
13. Backup containing an apparently active grant is restored.
14. Recovery signatures are missing, duplicated, expired or bound to another environment.
15. Reason/reference contains credential-like or excessive sensitive content.
16. A technical admin attempts a clinical/RMIK/pharmacy/finance action while elevated.

## 6. Hosted role-based UAT

Use synthetic accounts and data only. Temporary credentials follow the approved activation/revocation procedure and are never placed in this document.

Required sequence:

1. Security operator requests `identity-recovery` for a different named admin subject, with reason, approved reference and 15-minute TTL.
2. The subject cannot use elevated capability before approval.
3. A different named security approver completes recent MFA and approves.
4. All existing subject sessions become invalid.
5. The subject signs in again with MFA and binds one session.
6. UI displays the simulation break-glass banner, scope, activation public ID and countdown.
7. One exact in-scope administrative action succeeds and records the activation reference.
8. One non-scope administrative action denies.
9. One clinical/RMIK action denies.
10. Approver or security operator revokes, or the test clock reaches expiry.
11. The bound session loses elevated access immediately; residual subject sessions are zero.
12. Auditor reviews request, approval, activation, session revocation/binding, use, denial and revoke/expiry evidence in the protected sink.
13. Accounts return to their approved ordinary roles/status; no legacy flag is true.

Retain screenshots only after privacy/secret review. Browser evidence supplements but does not replace server/database evidence.

## 7. Scheduler, outage and recovery evidence

### Scheduler-down exercise

- activate a short synthetic grant;
- stop/omit the sweeper;
- prove request-time authorization denies exactly at expiry;
- restart sweeper and prove idempotent session cleanup/evidence;
- record no privilege extension.

### Audit externalization exercise

- make the external sink temporarily unavailable while local protected storage remains available;
- prove local activation event/outbox commits once;
- prove lag alert opens;
- restore sink and prove one semantic delivery plus checkpoint;
- verify no activation can occur if the durable local security event itself cannot append.

### Backup/restore exercise

- capture a backup containing an active synthetic grant;
- restore into an isolated environment with global deny already enabled;
- prove all restored activations are invalidated and sessions revoked before network/user access;
- verify audit continuity and recovery-key configuration without exposing private keys;
- run a new two-custodian recovery request only after the restored environment is verified;
- measure and record recovery time and result.

## 8. Migration/cutover acceptance

### Expand gate

- new schema deploys without changing current authorization;
- PostgreSQL and MySQL migration suites pass;
- new tables are empty/protected as expected;
- current demo continues operating.

### Observe gate

- shadow resolver produces no unexplained decision differences;
- all test grants remain non-authoritative;
- audit/outbox lag and time-drift monitoring works;
- distinct owners and recovery custodians are appointed and MFA-enrolled.

### Cutover gate

- maintenance/change authority recorded;
- exact SHA and migrations verified;
- global deny enabled before changes;
- all rebuild-admin/activation-subject sessions revoked;
- every legacy flag false and database-constrained false;
- seeders/preflight cannot recreate bypass;
- Gate resolver switched;
- allow/deny/audit smoke passes;
- global deny removed only after evidence review.

### Contract gate

- one schema-compatible rollback rehearsal passes without restoring bypass;
- recovery envelope and backup/restore exercises pass;
- legacy column removed only after the compatibility window;
- old role-drift command retired only after the replacement recovery path is accepted;
- security/audit history remains queryable and externalized.

## 9. Rollback acceptance

Before deployment, prove the runbook can:

1. enable global deny;
2. revoke all activation-subject sessions;
3. preserve/append containment evidence;
4. return to a schema-compatible application artifact;
5. retain the database false-only constraint;
6. keep all security/audit history;
7. verify ordinary RBAC and simulation guards;
8. refuse any instruction to restore permanent bypass or the five-role drift.

Rollback is unsuccessful if it restores availability by weakening audit, dual control, expiry, session binding or the synthetic-only boundary.

## 10. Owner and evidence sign-off

The following are mandatory and must be different accountable functions even when the campus team is small:

| Decision | Required authority | Name/evidence | Status |
|---|---|---|---|
| ADR/scope/TTL | Product sponsor + Security/Privacy/Data owner | | Pending |
| Operational activation/recovery | Operations/Recovery owner | | Pending |
| Requester role | Named security operator | | Pending |
| Approval role | Different named security approver | | Pending |
| Privileged subject | Named recipient; may also be requester, never approver or reviewer | | Pending |
| Recovery custody | Two distinct named custodians | | Pending |
| Audit sink/review | Audit/security reviewer | | Pending |
| G1 technical evidence | Technical lead + QA | | Pending |
| G1 gate | Product sponsor + Security/Data owner | | Pending |

One person may coordinate evidence, but the same person cannot supply both human sides of the dual-control acceptance scenario.

## 11. Exit decision

Verdict choices:

- **ACCEPT:** every invariant and mandatory test/UAT/recovery item passes; owners sign; no open P0 or unaccepted P1.
- **REVISE:** implementation is directionally correct but one or more mandatory items are incomplete; permanent bypass remains disabled/contained and G1 stays open.
- **REJECT:** design or evidence weakens the security baseline or cannot recover without restoring universal privilege.
- **DEFER:** no break-glass activation is released; the account stays contained and ordinary least-privilege administration continues.

Implementation, deployment and a green narrow test suite do not by themselves equal **ACCEPT**.

## References

- [ADR-017 proposed design](ADR-017-TIME-BOUND-SCOPED-BREAK-GLASS.md)
- [G1 privileged-access authority appointment pack](../phase-0/G1_PRIVILEGED_ACCESS_AUTHORITY_APPOINTMENT_PACK_2026-08-25.md)
- [Phase 2 RBAC matrix](RBAC_MATRIX.md)
- [Security, privacy and audit specification](../SECURITY_PRIVACY_AND_AUDIT.md)
- [Operations and reliability specification](../OPERATIONS_AND_RELIABILITY.md)
- [Testing and UAT strategy](../TESTING_AND_UAT_STRATEGY.md)
- [Privileged rebuild-admin reconciliation runbook](../../operations/PRIVILEGED_REBUILD_ADMIN_RUNBOOK_2026-08-25.md)
