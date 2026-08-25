# ADR-017: Time-bound scoped break-glass replaces the permanent universal bypass

- **Status:** PROPOSED — NOT OWNER-APPROVED
- **Date:** 2026-08-25
- **Decision owner:** Product sponsor + Security/Privacy/Data owner + Operations/Recovery owner
- **Required reviewers:** Architecture/technical lead, teaching owner, institutional security/privacy reviewer
- **Related:** G1, E03, E16, R-04, R-05, R-14, R-21, `SECURITY_PRIVACY_AND_AUDIT.md`
- **Boundary:** Synthetic-only SIMRS Campus UEU teaching environments. This ADR does not authorize real patient data, clinical production use, or live BPJS/VClaim/SATUSEHAT or other production integrations.

> This document is a proposed design contract, not evidence that break-glass has been implemented, deployed, tested or accepted. The existing `is_system_administrator` behavior remains the current runtime truth until a separately reviewed implementation completes the cutover gate below.

## Context

The current foundation uses `users.is_system_administrator` as an unconditional shortcut: `User::canCapability()` returns `true` for every capability and `capabilityList()` returns the complete capability catalogue. The demo seeder creates the rebuild-admin account with this flag, and the Gate definitions delegate to that model behavior.

The bounded `rebuild:admin-reconcile` command correctly repairs the known five-role drift, can disable the account, revokes sessions, and records an attributable audit event. It intentionally does not remove the system-administrator flag. It is therefore a T0 containment and provenance control, not the G1 break-glass design.

This current state conflicts with the approved security baseline, which requires exceptional access to be time-bound, reasoned, reviewed, strongly authenticated, session-controlled and distinguishable from permanent privilege. It also permits a technical bootstrap identity to acquire clinical, RMIK and other capabilities that are not necessary for platform recovery.

The existing controls are useful but insufficient:

- Fortify supports password confirmation, two-factor authentication and passkeys, but privileged activation does not require recent strong authentication.
- Database sessions can be revoked centrally, but privilege is not bound to a post-approval session.
- `AuditEvent` prevents update/delete through its Eloquent model, but database-level mutation and `simulation:reset --purge-audit` remain possible.
- Normal session lifetime is independent of privilege lifetime.
- Current ownership records do not yet name a distinct institutional security approver and recovery custodians.

G1 must replace the permanent bypass rather than wrapping it in another free-form maintenance command.

## Decision drivers

- Fail closed when approval, audit, environment, clock, session or recovery evidence is unavailable.
- Preserve named, human attribution without putting secrets or credentials in commands, logs or Git.
- Record requester, approver and subject as separate capacities; enforce requester != approver and approver != subject. A subject may request their own access but cannot approve it.
- Enforce expiry during authorization, not only through a scheduler.
- Grant only explicitly allowlisted administrative capabilities; never silently grant all present or future capabilities.
- Revoke pre-existing sessions so a stolen session does not inherit elevated access.
- Keep activation, use, revocation and recovery evidence immutable and reviewable.
- Remain deployable on PostgreSQL and MySQL; SQLite-only tests are insufficient.
- Allow expand/observe/cutover/contract delivery and a fail-closed rollback.
- Preserve the synthetic-only teaching boundary even when recovery is invoked.

## Considered options

| Option | Benefits | Costs/risks | Disposition |
|---|---|---|---|
| Keep permanent `is_system_administrator` | Smallest change; simple bootstrap | Universal, indefinite privilege; no approval/TTL/session binding; conflicts with the security baseline | Rejected |
| Toggle `is_system_administrator` with an Artisan command | Adds a nominal time window | CLI names are not authenticated identities; scheduler failure can leave privilege; still universal; race and recovery risks | Rejected |
| Temporarily add/remove roles | Reuses RBAC tables | Mutates durable role provenance; existing sessions may inherit access; future role changes can broaden old approvals | Rejected |
| Scoped immutable grant with dual control and bound session | Least privilege, attributable, time-bound, independently auditable, testable | More schema, workflow, MFA, operations and recovery work | **Proposed** |

## Proposed decision

### 1. Remove universal authorization semantics

`is_system_administrator` must no longer affect Gate decisions or effective capability lists. During cutover, every value is set to `false`, a database constraint prevents `true`, and all affected sessions are revoked. The column is removed only in a later contract release.

The ordinary technical `admin` role remains explicit RBAC. Its clinical/patient capabilities must be reviewed and removed unless an owner approves a separate operational need. Platform break-glass scopes never include clinical documentation, laboratory results, RMIK sign-off, pharmacy, inventory, finance, claims or live-integration actions.

### 2. Use named dual control

The normal workflow is:

```text
named requester + recent MFA
  -> immutable request (subject, scope, reason, reference, TTL, environment, digest)
  -> different named approver + recent MFA
  -> atomic activation + strict audit + revoke all subject sessions
  -> subject signs in again with MFA
  -> one post-approval session binds to the activation
  -> request-time authorization checks exact scope, binding, expiry and revocation
  -> expiry/revocation denies immediately and revokes sessions
```

Rules:

- requester and approver are different user IDs;
- approver and subject are different user IDs;
- requester and subject may be the same named person for an attributable self-request;
- G1 human acceptance requires distinct accountable people, not two accounts controlled by the same person;
- requester, approver and subject are active, verified, named accounts;
- requester and approver have separate explicit capabilities;
- recent password/passkey plus MFA assurance is required for request and approval;
- the subject must complete a fresh post-activation login with MFA before use;
- shared demo passwords are prohibited for privileged control identities.

### 3. Grant a short, immutable capability snapshot

Initial maximum requested TTL is 15 minutes; the hard system maximum is 30 minutes. Database UTC is authoritative.

Initial allowlisted scope bundles:

| Scope | Initial capability ceiling | Explicit exclusions |
|---|---|---|
| `security-containment` | `user.manage`, `audit.view` | role expansion, reset, clinical/RMIK/pharmacy/finance/integration |
| `identity-recovery` | `user.manage`, `role.manage`, `audit.view` | clinical/RMIK/pharmacy/finance/integration |
| `platform-recovery` | identity recovery plus separately approved `master.manage` and/or `synthetic.reset` | all clinical and value-chain actions; all live integrations |

The server resolves a scope from an allowlist and stores the exact capability snapshot and a canonical request digest. Later role or configuration changes cannot broaden an existing activation. There is no `all`, wildcard, future-capability or client-supplied capability list.

Only one activation lease may exist for a subject at a time. A small mutable lease projection enforces concurrency; request, decision, activation, binding and revocation facts remain append-only.

### 4. Bind privilege to a new strongly authenticated session

Activation transactionally revokes every existing database session for the subject. The subject then authenticates again and completes MFA. Only the resulting server-side session may bind to the activation; the binding stores an HMAC of the session ID, not a browser cookie or credential.

Every elevated Gate decision requires:

1. `APP_MODE=SIMULATION` and `APP_SYNTHETIC_ONLY=true`;
2. global break-glass deny switch off;
3. active, verified subject;
4. matching post-MFA session binding;
5. activation started, not expired by database UTC and not revoked;
6. requested capability present in the immutable snapshot; and
7. exact environment/release binding still valid.

The check occurs for every protected action. Cross-request caching of an elevated capability list is prohibited. Queued jobs and service identities do not inherit human break-glass.

The UI displays an unmistakable synthetic break-glass banner with activation public ID, scope and countdown. Frontend visibility remains informational; server authorization is authoritative.

### 5. Make privileged audit strict and protected

Break-glass transitions use a strict security-audit writer that throws on failure. Successful activation without durable local audit is impossible.

Mandatory events include request, approval/denial, activation, session revocation, session binding, elevated use or activation reference on the corresponding domain event, authorization denial, manual revocation, automatic expiry, recovery-envelope verification/use/rejection and audit-externalization health.

Each event includes safe metadata:

- actor, requester, approver and subject stable references;
- assurance method/time;
- scope and exact capability snapshot;
- reason and incident/change reference;
- request digest, activation ID, environment and exact release SHA;
- before/after state and sessions revoked;
- audit schema version and integrity marker.

Passwords, cookies, session IDs, recovery private keys, raw recovery material and authentication factors are never logged.

Database controls prevent application-role `UPDATE`/`DELETE` on security and audit event tables on both PostgreSQL and MySQL. The local transaction also appends to a protected outbox for a separately controlled sink. External sink delay does not lose the event; it raises an alert and retries. `simulation:reset --purge-audit` is removed before G1 acceptance.

### 6. Separate normal activation from recovery

Normal request/approval/activation occurs through authenticated web/API endpoints. Free-form `--operator` text is not sufficient authentication and no ordinary Artisan command can activate privilege.

Read-only and service commands may include:

- `break-glass:status` — read-only state and evidence summary;
- `break-glass:sweep --apply` — idempotent session cleanup and expiry evidence; it never determines whether a grant is expired;
- `break-glass:recover --apply --envelope=... --signature=... --signature=...` — lockout recovery only.

The recovery envelope is canonical, short-lived, one-time, environment-bound and signed by two distinct pre-appointed Ed25519 recovery custodians. The application stores only public verification keys; private keys stay out of the application, database, repository and terminal history. Invalid, duplicate, expired, replayed or wrong-environment envelopes fail closed. Recovery still requires simulation/synthetic-only mode and durable local audit.

If the database/security ledger is unavailable, break-glass cannot be activated. Operations set a deployment-level `BREAK_GLASS_GLOBAL_DISABLED=true`, restore the protected ledger, invalidate all old activations, revoke sessions and then rehearse recovery. Database outage is an infrastructure recovery problem, not permission to bypass audit.

## Data model contract

| Record | Minimum immutable facts |
|---|---|
| `break_glass_requests` | public ID, subject/requester, scope key and capability snapshot, reason/reference, TTL, requested/approval-deadline times, environment, release SHA, canonical digest |
| `break_glass_decisions` | request, distinct approver, approve/deny, rationale, decision time, assurance, matching digest |
| `break_glass_activations` | request, subject, snapshot, start/expiry, nonce/version, approving identity snapshot |
| `break_glass_session_bindings` | activation, subject, HMAC session reference, bind time, assurance |
| `break_glass_revocations` | activation, revoker/service identity, reason/reference, time |
| `break_glass_subject_leases` | one mutable concurrency projection per subject; activation and expiry only; not the audit source of truth |

Foreign keys preserve actor attribution. Named user records are disabled, not deleted. Database constraints and service-level row locks enforce one decision/activation/revocation and one current subject lease. Authorization derives truth from immutable facts plus current time/revocation; a scheduler-created status is never authoritative.

## API contract

Proposed authenticated endpoints:

```text
POST /security/break-glass/requests
POST /security/break-glass/requests/{request}/approve
POST /security/break-glass/requests/{request}/deny
POST /security/break-glass/activations/{activation}/bind-session
POST /security/break-glass/activations/{activation}/revoke
GET  /security/break-glass/status
```

All state-changing endpoints require CSRF protection, rate limiting, exact capability authorization, recent strong authentication and strict audit. API responses expose public IDs and safe state only. They never return secrets, raw session identifiers, recovery material or internal numeric IDs.

## Exact implementation slices

Each slice is a small reviewable change and must retain green existing tests.

| Slice | Runtime change | Required evidence | Stop/rollback point |
|---|---|---|---|
| BG-01 Expand schema | Add immutable request/decision/activation/binding/revocation records and subject lease projection; no authorization change | Migration tests on PostgreSQL and MySQL; schema qualification; rollback on empty schema | Revert code/migration before any record exists |
| BG-02 Strict audit | Add strict security writer, protected outbox and DB mutation guards; remove audit purge path | Audit failure transaction tests; direct SQL update/delete denial; reset preservation | Disable new workflow; preserve event rows |
| BG-03 Scope/resolver shadow | Add allowlisted scopes and resolver that only records comparison telemetry; existing Gates unchanged | Exact capability/TTL/environment tests; no authorization effect | Remove shadow resolver |
| BG-04 Dual-control API | Add request/approve/deny/revoke services and endpoints with recent MFA | Server-side allow/deny, self-approval, replay, rate-limit and concurrency tests | Disable endpoints; no activation use yet |
| BG-05 Session binding | Revoke existing sessions, require fresh MFA, bind one session, add banner/countdown | Stolen/unbound session denial; binding and expiry tests | Global deny; revoke test activations |
| BG-06 Observe | Run shadow comparison, audit-outbox monitoring and synthetic role UAT with no legacy cutover | Zero unexplained decision divergence; owner-reviewed evidence | Continue legacy containment; do not cut over |
| BG-07 Recovery/restore readiness | Add two-custodian recovery, sweeper, alerts and restore rehearsal while the resolver remains non-authoritative | Recovery signature matrix; scheduler-down expiry; backup/restore drill | Keep legacy containment; disable recovery entry point; preserve evidence |
| BG-08 Cutover/contract | Global deny; clear/constraint legacy flags; update seeders; switch Gates to scoped resolver; revoke sessions; smoke; remove deny; later remove legacy flag/command | Exact-SHA PostgreSQL/MySQL suite, hosted synthetic UAT and proven recovery/rollback | Re-enable global deny; roll back code but keep flag constraint false; revoke all; preserve evidence |

BG-08 must not start until BG-01 through BG-07 pass and the accountable owners approve this ADR and the linked acceptance contract. BG-01 through BG-03 may proceed only with the new control `off` or non-authoritative in `shadow`; they cannot grant access.

## Migration strategy

### Expand

- Add forward-compatible tables, indexes, integrity constraints and strict audit/outbox.
- Add new capabilities and roles without granting them to shared/demo actors.
- Deploy resolver in shadow mode; no active grant affects authorization.

### Observe

- Exercise deterministic synthetic requests on PostgreSQL and MySQL.
- Compare proposed effective decisions with current Gate decisions.
- Resolve every unexplained difference.
- Verify outbox delivery, time synchronization, session revocation and alerting.

### Cut over

1. Set global deny.
2. Confirm exact reviewed SHA, simulation/synthetic-only mode, current migration state and strict audit health.
3. Revoke all rebuild-admin and activation-subject sessions.
4. Set every `is_system_administrator=false` and add a database constraint preventing `true`.
5. Update seeders and preflight checks.
6. Switch Gate evaluation to ordinary RBAC plus the bound scoped resolver.
7. Run server-side denials, synthetic smoke and audit verification.
8. Remove global deny only after all checks pass.

### Contract

- Keep the false-only legacy column for at least one compatible release.
- Remove it only after rollback rehearsal.
- Retire the role-drift reconciliation command only after the new recovery path passes.
- Preserve all request, decision, activation, revocation and audit history.

## Rollback and recovery

Rollback is fail-closed:

1. Set global deny.
2. Revoke every activation subject session.
3. Append containment/release evidence.
4. Roll application code back only to a schema-compatible version.
5. Keep the database constraint that prevents the legacy Boolean from becoming true.
6. Do not restore the five-role drift or re-enable permanent bypass.
7. Preserve all security/audit records and outbox state.
8. If restoring a backup, start isolated with global deny, invalidate all restored activations, rotate the privilege epoch, revoke sessions and verify audit continuity before reconnecting users.

If strict local audit cannot append, new activation fails. If externalization is delayed, local durable evidence remains and an alert opens. If the security ledger is unavailable, no activation proceeds until the recovery runbook restores it.

## Consequences

### Positive

- Removes permanent universal privilege.
- Makes exceptional access attributable, short-lived, scoped and reviewable.
- Prevents pre-existing or stolen sessions from silently inheriting elevation.
- Keeps scheduler failure from extending access.
- Creates a realistic hospital-style teaching control without using real data.
- Preserves safe rollback without restoring the insecure bypass.

### Costs and trade-offs

- Requires six recorded capacities with at least five accountable people, MFA enrollment and split recovery-key custody.
- Adds schema, authorization, UI, monitoring and operational complexity.
- An audit/security-ledger outage intentionally prevents activation.
- PostgreSQL and MySQL database-control implementations require separate verification.
- The existing demo seeder/shared password is unsuitable for privileged custodians.

## Owner decisions required before activation or cutover

BG-01 through BG-03 may be engineered and tested in `off`/`shadow` mode before these decisions because they are non-authoritative. BG-04 through BG-07 must remain disabled until their applicable authority, identity, MFA and custody prerequisites are recorded. BG-08 cannot begin until every decision below is approved.

1. Approve or revise the initial scope bundles and TTL.
2. Record the six capacities and minimum five-person separation in the [G1 privileged-access authority appointment pack](../phase-0/G1_PRIVILEGED_ACCESS_AUTHORITY_APPOINTMENT_PACK_2026-08-25.md).
3. Decide which ordinary capabilities remain on the technical admin role.
4. Approve the protected audit sink and retention/review owner.
5. Approve recovery-key storage and rotation procedure.
6. Accept that database/audit outage denies activation rather than bypassing evidence.
7. Approve the [G1 break-glass acceptance contract](G1_BREAK_GLASS_ACCEPTANCE_CONTRACT.md).

## Validation

This ADR becomes **Accepted** only after the decision owners sign it. Implementation does not prove acceptance. G1 exit additionally requires every mandatory item in the linked acceptance contract to pass on the exact release SHA with no open P0 or unaccepted P1 finding.

Revisit this ADR before any real-data or clinical-production path, any patient-emergency-access design, any new external integration, a material identity-provider change, or a change to the recovery/audit trust boundary.
