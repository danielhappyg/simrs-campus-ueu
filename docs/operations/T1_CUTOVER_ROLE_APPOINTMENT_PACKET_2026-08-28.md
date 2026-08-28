# T1 cutover role appointment packet — 28 August 2026

**Status:** `PROPOSAL / HUMAN ACCEPTANCE PENDING / NO MAINTENANCE AUTHORITY`

**Boundary:** SIMRS Campus UEU synthetic teaching simulation only. This packet does not authorize a push, deployment, database migration, credential rotation, maintenance marker, account activation, traffic reopen, or G3 acceptance.

## Why this packet exists

The technical remediation batch and exact-SHA security diff scan are complete locally, but `T1_NINE_MIGRATION_CUTOVER_EXECUTION_CONTROL_2026-08-27.md` still blocks execution until a named independent reviewer, backup/restore custodian, temporary-access expiry, and recovery path are accepted.

The small-team RACI permits one person to hold multiple roles when approvals remain distinct. This proposal therefore uses Daniel Happy Putra as the human authority and custodian while keeping the execution operator separate as the Codex orchestrator. The proposal is not effective until Daniel explicitly accepts each responsibility below.

## Proposed appointments

| Responsibility | Proposed authority | Independence / scope | Current state |
| --- | --- | --- | --- |
| Release authorization and final promotion decision | Daniel Happy Putra | Existing product and release authority; synthetic teaching release only | Previously evidenced; action-time decision still required |
| Rollback, forward-recovery, retained-write, and maintenance-reopen decision | Daniel Happy Putra | Human decision authority; Codex may recommend but may not substitute | Previously evidenced; action-time decision still required |
| Execution operator | Codex orchestrator | May execute only the frozen procedure and record value-minimized receipts | Named; no live execution authorized by this proposal |
| Independent technical reviewer | Daniel Happy Putra | Independent of the Codex execution operator; reviews exact receipts and recommends/records GO or NO-GO separately from execution | **Proposed; acceptance pending** |
| Backup/restore custodian | Daniel Happy Putra | Controls the approved encrypted destination, public recipient set, retention, and restore receipts; plaintext backup and private decryption material never enter the repository or chat | **Proposed; acceptance pending** |
| Temporary Vercel/Supabase access owner | Daniel Happy Putra | Grants the Codex operator only the minimum project-bound access needed for one controlled attempt | **Proposed; acceptance pending** |

These technical roles do not provide clinical, RMIK parity, privacy-institutional, or campus-production acceptance. Those sign-offs remain separate and pending.

## Proposed temporary-access contract

| Control | Proposed rule |
| --- | --- |
| Target | Vercel project `simrs-campus-ueu-demo` and Supabase project `xbmsfvstcpngizcplqyg`, synthetic teaching boundary only |
| Start | Only after the appointment acceptance is recorded and the action-time preflight returns GO |
| Maximum window | 90 minutes for one controlled attempt; earlier expiry immediately after post-action readback |
| Database use | Password/connection material is used ephemerally only; never echoed, written to repository files, committed, or copied into evidence |
| Vercel use | Only the exact project and deployment identities frozen at action time; no unrelated project access |
| Teaching-role accounts | Maximum 30-minute application lease, or shorter if the rehearsal ends earlier; revoke immediately after UAT |
| Expiry proof | Record only provider/project identity, operator, start/end timestamps, and pass/fail revocation result; never record secret values |
| Historical Preview credential | Rotate or revoke during the controlled maintenance window, then prove older immutable Preview deployments can no longer authenticate |

## Fail-closed recovery and unattended-work rule

1. Do not begin maintenance, rotation, migration, promotion, account activation, or traffic reopen while the human recovery authority is unavailable for an action-time decision.
2. If Daniel becomes unavailable before maintenance starts, remain `NO-GO` and perform read-only preparation only.
3. If contact is lost after maintenance starts, keep or restore the safest non-writing state, retain the maintenance marker, stop before migration or reopen, and preserve value-minimized receipts for human review.
4. Ambiguous provider, database, command, timeout, deployment, backup, restore, or revocation state is `NO-GO`; do not retry automatically.
5. Only Daniel may choose forward recovery versus rollback when writes may have occurred. Codex may not infer that decision from a prior general authorization.
6. Reopen requires the exact deployment and database checks to pass, the independent review to be recorded, and Daniel to issue the action-time `UP` decision.

## Acceptance record — deliberately blank

This proposal becomes an execution-role record only after Daniel supplies one explicit dated acceptance that covers all four statements:

1. I accept appointment as independent technical reviewer, separate from the Codex execution operator.
2. I accept appointment as backup/restore custodian and accept custody of the approved encrypted destination and recipient set.
3. I approve the proposed 90-minute maximum temporary Vercel/Supabase access contract and immediate post-action revocation/readback.
4. I accept the fail-closed recovery and unattended-work rule, including the requirement for a new action-time decision before migration, retained-write recovery, rollback, or reopen.

Until that acceptance is recorded, every proposed appointment above remains `PENDING`, and the cutover packet remains `NO-GO / NOT EXECUTED`.

## Bound evidence

- Security remediation register: `G3_SECURITY_REMEDIATION_REGISTER_2026-08-28.md`
- Sealed security diff scan: `de0afb55-7377-4370-886b-8f8046cc0da6`, exact range `c63c017a1e9dc2bd2922ce9fc9235b7cc90703db..0fa00978973e86f4a2375a6dfc2e07ce380cf1b9`
- Cutover execution control: `T1_NINE_MIGRATION_CUTOVER_EXECUTION_CONTROL_2026-08-27.md`
- Teaching-role access runbook: `T1_TEACHING_ROLE_ACCESS_RUNBOOK_2026-08-27.md`
- Current hosting posture: `CURRENT_HOSTING_POSTURE.md`

