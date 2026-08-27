# T1 temporary teaching-role access runbook — 2026-08-27

**Status:** `LOCAL_IMPLEMENTATION_IN_REVIEW_NOT_DEPLOYED`
**Boundary:** SIMRS Campus UEU synthetic teaching simulation only
**Accounts:** exact four-account roster; one account active at a time
**Maximum window:** 30 minutes; explicit revocation remains mandatory

This runbook controls temporary access for the hosted Structured RJ/RM UAT. It does not authorize a database migration, Vercel promotion, real-patient use, live integration, or activation before the exact release has passed its release gates.

## Exact roster

| Account | Required role |
| --- | --- |
| `registrar.demo@example.invalid` | `registrar` |
| `nurse.demo@example.invalid` | `nurse` |
| `physician.demo@example.invalid` | `physician` |
| `rmik.demo@example.invalid` | `rmik` |

The command refuses a missing account, any other email, role drift, administrator drift, an incomplete roster, or a second active roster account.

## Runtime prerequisites

Stop unless all conditions are true:

1. `APP_MODE=SIMULATION` and `APP_SYNTHETIC_ONLY=true`.
2. `SESSION_DRIVER=database`, the session table is `sessions`, and it uses the application database connection.
3. The exact deployment environment comes from `VERCEL_ENV`, the exact 40-character source binding comes from `VERCEL_GIT_COMMIT_SHA`, the deployment host comes from `VERCEL_URL`, and the approved canonical host comes from `APP_URL`.
4. The application and operator command receive the same dedicated `TEACHING_ROLE_ACCESS_COMMITMENT_KEY` from the approved secret store. It must be at least 32 bytes and must never enter source, chat, screenshots, command arguments, or retained evidence.
5. Each activation receives a new high-entropy `TEACHING_ROLE_ACCESS_PASSWORD` of at least 24 bytes through private process environment injection. A value used in any earlier access window cannot be reused.
6. The `2026_08_27_000100_create_teaching_role_access_leases` migration is recorded and its user columns, lease table, indexes, sequence and restrictive user foreign key match the reviewed schema.
7. The named operator and independent reviewer have verified the exact public deployment ID, source SHA and database migration ledger.

The access value is not needed by the running web application. Only its keyed one-way commitment is stored. The web runtime needs the commitment key so it can compare a submitted password with the active lease.

## Command contract

All actions require the exact account-specific confirmation, accountable operator, specific reason, runtime environment, release SHA, deployment host and canonical host. `activate` also requires a lease duration from 5 minutes through the configured maximum.

```text
php artisan teaching:role-access status <account> \
  --confirm="SIMRS CAMPUS UEU STATUS <account>" \
  --operator="<accountable operator>" \
  --reason="<specific UAT reason>" \
  --expected-environment=<exact VERCEL_ENV> \
  --expected-release-sha=<exact 40-character VERCEL_GIT_COMMIT_SHA> \
  --expected-deployment-url=<exact VERCEL_URL host> \
  --expected-canonical-host=<exact APP_URL host>

php artisan teaching:role-access activate <account> \
  --confirm="SIMRS CAMPUS UEU ACTIVATE <account>" \
  --operator="<accountable operator>" \
  --reason="<specific UAT role and run>" \
  --expected-environment=<exact VERCEL_ENV> \
  --expected-release-sha=<exact 40-character VERCEL_GIT_COMMIT_SHA> \
  --expected-deployment-url=<exact VERCEL_URL host> \
  --expected-canonical-host=<exact APP_URL host> \
  --ttl-minutes=<5..30>

php artisan teaching:role-access revoke <account> \
  --confirm="SIMRS CAMPUS UEU REVOKE <account>" \
  --operator="<accountable operator>" \
  --reason="<specific UAT closeout reason>" \
  --expected-environment=<exact VERCEL_ENV> \
  --expected-release-sha=<exact 40-character VERCEL_GIT_COMMIT_SHA> \
  --expected-deployment-url=<exact VERCEL_URL host> \
  --expected-canonical-host=<exact APP_URL host>
```

Angle-bracket values are placeholders and must be replaced. Never place the access value or commitment key in a command argument.

## Activation procedure

1. Refresh the Vercel deployment metadata and migration ledger. Stop on any SHA, environment or schema mismatch.
2. Run `status` for the intended account. Require all four accounts to have exact roles, no administrator flag and no drift. Require zero active accounts and zero retained sessions, passkeys and reset records.
3. Privately inject a newly generated access value and the approved commitment key into the operator process. Do not reuse a value from another role or earlier window.
4. Run `activate` with a duration no longer than the planned role step.
5. Require `mutated=yes`, `idempotent=no`, `audit=recorded`, exactly one active account, exactly one database-enforced active lease, a valid password-state commitment, and zero pre-login authentication artifacts. Every non-target roster account must be fully closed before activation.
6. Record only the activation audit public ID, lease public ID, environment, source SHA, deployment host, canonical host, expiry and non-secret aggregate state. Do not record either secret, any hash, cookie or session identifier.
7. Deliver the access value to the approved facilitator through the approved secret channel. Browser entry is a separate action-time credential step.

Re-running `activate` is idempotent only while the exact current account, password state, unexpired lease, release, environment and clean artifact state still match. Any drift fails closed.

## Web enforcement

For the four roster accounts only:

- password login requires the active, unexpired, release-bound lease and forces remember-me off;
- successful login stamps the access epoch and lease public ID in the session;
- protected application requests reload the account before and after controller execution and reject a stale epoch, replaced lease, password-state drift, expiry, revocation, role drift, administrator drift, deployment drift, canonical-host drift or request-host drift;
- every unsafe roster-account request runs inside one outer database transaction; immediately before commit it takes shared user-then-lease locks, rechecks the exact epoch and sole active lease with database wall-clock time, and rolls back all nested clinical, RM and audit writes if revocation or expiry linearized first;
- password reset issuance/consumption, passkey login/management, two-factor completion/management, verification mutation and profile/security credential changes are blocked;
- a reset-link request returns the ordinary non-enumerating response but creates no reset record or notification.

Non-roster users retain the ordinary application authentication behavior.

## Mandatory revocation and handoff

1. Complete the current role's evidence step and log out.
2. Run `revoke` immediately; do not wait for lease expiry.
3. Require `DISABLED`, unverified, null remember/two-factor state, no current lease, and zero sessions, passkeys and reset records.
4. Prove the just-used access value can no longer authenticate.
5. Run `status` again and retain the secret-free aggregate and revocation audit public ID.
6. Remove the access value from the operator process and secret-delivery channel according to the approved handling procedure.
7. Only then prepare a different, newly generated value for the next account.

Expiry denies authentication and invalidates the next roster request, but it is a fail-safe boundary rather than a substitute for explicit revocation and zero-artifact closeout.

`status` and `activate` fail closed on simulation, session, environment, release, deployment or canonical-host drift. `revoke` is a containment operation: it still disables every account matching the canonical email or immutable roster marker, closes every active lease, rotates to an unknown login state and purges authentication artifacts. If those identities are split across multiple rows or audit evidence cannot be committed, the command returns failure after containment and requires incident review before any further activation.

PostgreSQL enforces each of the four canonical email-to-roster-marker mappings, refuses changes to a bound email or marker, and permits at most one `ACTIVE` lease per roster account. Status readback classifies missing and ambiguous identities explicitly rather than selecting the first match.

## Failure and stop rules

Stop without workaround if:

- the command prints its generic safe-failure message or lacks an audit event;
- final readback is not exact;
- a lease is expired, bound to another environment/release/deployment/canonical host, or its access value has appeared before;
- a session backend other than the application database is configured;
- any account, role, administrator flag or authentication artifact drifts;
- direct SQL, Tinker, a seeder, reset command or stale `simulation:lab-access` command would be required;
- any real or plausibly real patient data or live integration appears.

A failed post-transaction readback invokes a bounded compensation transaction that disables the account, advances its access epoch, rotates to an unknown login state, removes authentication artifacts and ends the lease. Treat the original operation as failed even if compensation succeeds. If closeout cannot be proven, stop UAT and escalate through the reviewed incident path; do not activate another account.

## Rollback boundary

Before any lease row or non-default access fence exists, a failed deployment may roll back the application and the new migration through the reviewed release procedure. After the first access-window record or epoch, mutex, lease pointer, or expiry fence exists, the migration `down()` path refuses to remove access-lifecycle evidence and fencing columns even if a privileged error has already removed the lease row. Roll back application traffic, keep the schema, explicitly revoke every roster account through the reviewed compatible release, and preserve the audit/lease records for investigation.
