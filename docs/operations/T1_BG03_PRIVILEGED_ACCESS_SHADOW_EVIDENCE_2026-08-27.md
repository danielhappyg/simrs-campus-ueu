# T1 BG-03 Privileged-Access Shadow Evidence — 2026-08-27

## Evidence boundary

**Classification: `LOCAL / NOT_DEPLOYED`.** This record covers the unpublished BG-03 increment on the fixed repository base `d04b35f1f85ab0e6e56818d08c374f3a5bb3cb88`. It uses synthetic users and facts only. It does not record a commit, push, pull request, GitHub Actions run, Vercel deployment, hosted Supabase migration, hosted UAT, owner acceptance, or production release.

ADR-017 explicitly permits BG-01 through BG-03 engineering in `off` or non-authoritative shadow mode before the owner decisions required for activation. The ADR remains **PROPOSED / NOT OWNER-APPROVED**. This increment does not implement BG-04 request/approval endpoints, BG-05 session activation, BG-06 hosted observation, BG-07 recovery, or BG-08 cutover.

## Implemented boundary

- `BREAK_GLASS_MODE=off` remains the default. The resolver performs no database query and emits no comparison telemetry in this mode.
- `BREAK_GLASS_GLOBAL_DISABLED=true` remains the default for the future control.
- `shadow` compares a proposed scoped decision only after the existing `User::canCapability()` result has been calculated.
- An accidental `enforce` configuration remains deliberately non-authoritative in BG-03: the resolver still returns the exact precomputed legacy result.
- A shadow allow requires the simulation/synthetic-only boundary, a valid global switch, exact environment and release bindings, an active verified named subject, exactly one subject lease, engine-specific UTC database-time activation bounds, approved TTL/deadline chronology, strong approval and session assurance, an attributable digest-linked fact chain, distinct requester/approver/subject constraints, an exact canonical scope snapshot, no revocation, and a matching HMAC-bound request session.
- Missing or malformed configuration, database/session/fact errors, unsafe application mode, unknown capability, invalid snapshots, expiry, revocation, runtime drift, and binding mismatch deny only the shadow comparison. They cannot grant or remove legacy access.
- Telemetry is structured and explicitly marked `authoritative=false`. It contains only reason codes, booleans, registered capability names, and safe public ULIDs. It excludes names, email addresses, raw session identifiers, session HMACs, cookies, credentials, and the HMAC key.

The existing permanent `is_system_administrator` shortcut remains the current authorization truth. BG-03 observes it; it does not remove, wrap, or replace it. No break-glass request or activation can be created through this increment.

## Focused verification

```bash
vendor/bin/pint \
  app/Providers/AppServiceProvider.php \
  app/Support/PrivilegedAccess/PrivilegedAccessDatabaseClock.php \
  app/Support/PrivilegedAccess/PrivilegedAccessSessionReference.php \
  app/Support/PrivilegedAccess/PrivilegedAccessShadowResolver.php \
  config/break_glass.php \
  tests/Feature/PrivilegedAccess/PrivilegedAccessShadowResolverTest.php

php artisan test \
  tests/Feature/PrivilegedAccess/PrivilegedAccessShadowResolverTest.php \
  tests/Feature/PrivilegedAccess/BreakGlassRecordSchemaTest.php \
  tests/Feature/PrivilegedAccess/ProtectedSecurityFactsDatabaseTest.php \
  tests/Feature/PrivilegedAccess/SecurityLedgerWriterTest.php \
  tests/Unit/Support/PrivilegedAccess
```

Result: **PASS — 66 tests, 315 assertions**.

The focused cases prove:

1. direct and Gate-integrated `off` return legacy decisions unchanged, with zero resolver queries and zero telemetry;
2. a fully valid synthetic shadow fact graph cannot grant `user.manage` when ordinary RBAC denies it;
3. a shadow denial cannot remove the existing system-administrator result;
4. accidental `enforce` still cannot become authoritative;
5. expired, revoked, wrong-environment, wrong-release, malformed-snapshot, overlong-TTL, late-approval, weak-assurance, pre-activation/future-dated binding, inactive/malformed-subject and unbound-session cases fail the comparison closed;
6. missing HMAC key, request-subject mismatch, database failure, logger failure, global deny and a non-simulation mode preserve the legacy decision;
7. emitted telemetry contains no tested session identifier, HMAC key, user name or email; and
8. SQLite database time is current UTC, while one valid shadow comparison has a regression ceiling of ten database queries.

The ten-query ceiling is a safety regression bound, not a production-performance acceptance. Shadow mode adds synchronous work to each observed Gate decision and must remain `off` until a later, separately approved observation plan defines sampling, load, and telemetry budgets.

An independent read-only re-review returned **GO for SQLite-local, comparison-only BG-03 evidence** after the future-dated binding case was closed. A later current-manifest portability rehearsal also passed the bounded E2E-16 administration/audit/BG-03 slice on PostgreSQL 17.10 and exact MySQL 8.4.11: 79 tests and 321 assertions on each engine. The retained records and exact bindings are documented in `T1_LOCAL_CURRENT_MANIFEST_PORTABILITY_EVIDENCE_2026-08-27.md`. Hosted observation, engine-specific performance acceptance, activation, enforcement, and production cutover remain **NO-GO / NOT_RUN / unauthorized** as applicable.

## Claims this evidence does not make

- It does not prove the ADR is accepted or G1 is closed.
- It does not provide a usable temporary privileged account, request, approval, activation, revocation, banner, expiry sweeper, recovery envelope, external audit sink, or cutover.
- It does not change any current authorization outcome.
- It does not prove hosted proxy/session behavior, engine-specific contention or performance acceptance, recovery, or cross-failure-domain restore for BG-03. The bounded resolver slice does pass both local PostgreSQL 17.10 and MySQL 8.4.11 execution, but that is portability evidence rather than hosted or load evidence.
- It does not close the separately recorded three P3 security findings.
- It does not convert an administration capability or E2E-16 from partial engineering evidence to owner acceptance.
- It does not authorize publication, deployment, hosted migration, credential use, real patient data, or live BPJS/VClaim/SATUSEHAT or other production integrations.
