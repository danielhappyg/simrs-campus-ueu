# G1 privileged-access authority appointment pack — 2026-08-25

**Status:** DRAFT FOR ORGANIZATIONAL APPOINTMENT; no role below is appointed or approved
**Scope:** Privileged administration of the SIMRS Campus UEU synthetic teaching environment
**Boundary:** This pack grants no clinical authority, real-data authority or live-integration authority

## Purpose

G1 requires privileged access to be named, separated, MFA-protected, time-bounded, attributable and recoverable. This pack records the organizational authorities needed before the bootstrap rebuild-admin capability can be treated as governed break-glass access.

The application-level `is_system_administrator` flag can technically reach every capability. Technical capability is not organizational authority. A privileged subject must never use that reach to perform clinical, Laboratory, Pharmacy, Finance, claims/coding or RMIK work.

## Required appointments

All six capacities must be separately recorded and must identify at least five people. The privileged-access requester and subject may be the same named person; six people remains preferred when those capacities are naturally separate. Every other capacity must identify a different person. A candidate, blank field, shared mailbox, team name or unrecorded interim cover does not satisfy this gate.

| Authority | Required accountability | Named person | Position/unit | Appointment evidence | Effective / expiry / review | Status |
| --- | --- | --- | --- | --- | --- | --- |
| Privileged-access requester | States the exact administrative need, environment, scope, start/end time and rollback; cannot approve the request |  |  | DEC/ticket/reference: |  | **Unappointed** |
| Privileged-access approver | Approves or rejects scope, subject, duration and risk; cannot request or receive the access |  |  | DEC/ticket/reference: |  | **Unappointed** |
| Privileged-access subject | Unique named human who receives the time-bound privileged session and accepts the prohibited-use boundary |  |  | Workforce/appointment reference: |  | **Unappointed** |
| Security reviewer | Independently verifies least scope, MFA, audit, expiry, session revocation and post-use evidence |  |  | Review reference: |  | **Unappointed** |
| Recovery custodian A | Holds only the approved first recovery component or sealed recovery responsibility; cannot recover alone |  |  | Custody reference: |  | **Unappointed** |
| Recovery custodian B | Holds the distinct second recovery component or sealed recovery responsibility; cannot recover alone |  |  | Custody reference: |  | **Unappointed** |

Minimum appointment evidence for each person:

- verified name, position, unit and organizational contact;
- authority role, permitted scope and explicit exclusions;
- appointing authority and dated approval reference;
- effective date, expiry date and review/replacement date;
- acknowledgement of separation, confidentiality, evidence-retention and revocation duties; and
- named delegate only when separately appointed under the same controls.

## Separation-of-duty rules

The six capacities require at least five named people. The requester may request access for themselves as the subject. The approver must be different from the requester/subject, the security reviewer must be different from both requester/subject and approver, and the two custodians must each be different from everyone else. No person may approve their own request, receive access they approved, review their own activity, or unilaterally reconstruct recovery access.

The two custodians:

- must not be the privileged subject, requester, approver or security reviewer;
- hold separate recovery responsibilities so neither can recover access alone;
- never place passwords, MFA seeds, recovery codes, keys or secret values in this repository, a ticket, screenshots, chat, audit metadata or this pack; and
- participate together only under an approved, attributable recovery event.

If the organization cannot staff the minimum five-person separation, G1 remains open. A four-person exception that combines approver and reviewer is not normal acceptance: it requires an explicit, expiring risk decision and a different retrospective reviewer. Do not silently combine roles or convert an interim product/technical appointment into independent security approval.

## Appointment versus access approval

| State | Meaning | Does not mean |
| --- | --- | --- |
| Candidate | A proposed person/unit awaiting recorded appointment | Authorized to request, approve, receive, review or recover access |
| Interim appointment | A named person has written, limited, expiring authority for this synthetic environment | Permanent institutional authority or permission outside the recorded scope |
| Appointed | Identity, scope, limits, appointing evidence and validity period are complete | Any privileged session is approved or active |
| Access approved | One specific request, subject, task, environment and expiry were approved | Standing access, clinical authority or permission to extend the task |
| Access active | The approved subject has a current privileged session | Continued access after expiry or permission to bypass audit/review |
| Access revoked | Account/session/recovery access was ended and evidenced | The appointment itself is revoked unless separately recorded |

Appointment expiry blocks new approvals. Access expiry requires automatic or operator-enforced disablement and session revocation even when the underlying appointment remains valid.

## MFA and identity readiness gate

Before approving the first privileged activation, retain evidence that:

1. the subject uses a unique named identity and the synthetic rebuild-admin target is correctly bound to that subject for the approved event;
2. strong MFA enrollment is complete and verified by the security reviewer without recording the factor secret or recovery material;
3. reauthentication or step-up verification is required for activation and high-impact administration;
4. active-session inventory, centralized disablement and session revocation work;
5. recovery responsibilities are split between the two appointed custodians;
6. login, activation, privileged action, denial, expiry and revocation events are attributable; and
7. dedicated clinical and teaching accounts remain separate and unchanged.

MFA readiness is **not established** by the presence of an MFA feature, a screenshot of a QR code, a shared recovery-code list or an administrator's verbal assurance.

## Per-activation approval record

Every activation requires a new record; an appointment is not standing authorization.

| Field | Required entry |
| --- | --- |
| Request/decision ID |  |
| Synthetic environment and exact account public ID |  |
| Named requester / appointment reference |  |
| Named approver / appointment reference |  |
| Named subject / appointment reference |  |
| Security reviewer / appointment reference |  |
| Administrative task and minimum required scope |  |
| Specific reason and affected release/incident/change |  |
| Approved start, expiry and maximum session duration |  |
| MFA/step-up readiness evidence reference |  |
| Pre-activation status, roles and session count |  |
| Rollback/containment path |  |
| Decision: approve / reject / revise / defer |  |
| Decision time and signatures/references |  |

Approval must fail closed when any identity, appointment, scope, MFA, expiry, audit or recovery field is missing.

## Explicit authority exclusions

Privileged access under this pack is limited to approved administrative maintenance in `APP_MODE=SIMULATION` with synthetic-only enforcement. It does **not** authorize:

- nursing or medical authorship, finalisation, supervision or amendment;
- Laboratory order/result work, RMIK completeness sign-off, coding or claim decisions;
- Pharmacy/GF, cashier, revenue or financial transactions;
- use of real patient, workforce or payer data;
- live BPJS, VClaim, SATUSEHAT, LIS, PACS, payment, messaging or other production connections;
- secret readback, direct database workflow correction or bypass of application audit;
- migration to clinical production or representation of the demo as production care; or
- restoration of the failed Antrean/work-queue MVP.

If an approved administrative task exposes a clinical or domain decision, stop and obtain the applicable professional owner; do not use system-administrator capability as substitute authority.

## Expiry, revocation and review evidence

Each privileged event must retain safe metadata only:

- exact Git SHA, environment, account public ID and request/decision ID;
- named requester, approver, subject and reviewer appointment references;
- approved scope, start, expiry and actual end time;
- before/after account status, canonical role membership and session count;
- attributable audit-event IDs for activation, administrative action, denial and revocation;
- confirmation that no credential, MFA factor, recovery material or protected content was retained; and
- post-use security review, deviations, residual risk and follow-up owner.

At task completion or expiry, disable the account when not required, revoke every session, verify zero active sessions and review the attributable event chain. Do not restore broad clinical role membership.

## Required revocation and recovery drills

G1 evidence requires controlled synthetic drills, not only written procedure:

1. **Expiry drill:** activate for a short approved window, reach expiry, disable/revoke and prove a prior session cannot continue.
2. **Emergency revocation drill:** security reviewer initiates immediate containment; account and sessions are revoked; evidence and escalation are retained.
3. **Role-reduction drill:** reconcile to canonical `admin` role, revoke sessions and prove dedicated clinical accounts are unchanged.
4. **Dual-custodian recovery drill:** custodians A and B jointly recover access without exposing recovery material, then rotate/reseal it and revoke the recovery session.
5. **Unavailable-custodian drill:** prove one custodian alone cannot recover access and that escalation does not bypass dual control.

For every drill, record scenario ID, participants by appointment reference, approved window, expected result, actual result, audit IDs, defects, corrective owner and retest decision. Drill evidence uses synthetic data only.

## G1 decision record

G1 privileged-access authority remains **open** until all six recorded capacities with the minimum five-person separation, MFA readiness, activation controls and successful expiry/revocation/recovery drills are evidenced.

| Decision area | Required authority | Decision / conditions | Evidence | Date |
| --- | --- | --- | --- | --- |
| Six capacities / five-person minimum separation | Product sponsor + security owner |  |  |  |
| MFA and named-identity readiness | Security reviewer + operations |  |  |  |
| Activation/expiry/revocation control | Security reviewer + operations |  |  |  |
| Dual-custodian recovery | Security owner + recovery custodians |  |  |  |
| G1 residual risk | Product sponsor + security/data owner |  |  |  |

Blank fields are unresolved. This template records no appointment, approval or G1 pass by itself.

## Governing references

- [G0 owner appointment pack](G0_OWNER_APPOINTMENT_PACK_2026-08-25.md)
- [Owners and RACI](OWNERS_AND_RACI.md)
- [Security, privacy and audit](../SECURITY_PRIVACY_AND_AUDIT.md)
- [Operations and reliability](../OPERATIONS_AND_RELIABILITY.md)
- [Phase 2 RBAC matrix](../phase-2/RBAC_MATRIX.md)
- [Privileged rebuild-admin runbook](../../operations/PRIVILEGED_REBUILD_ADMIN_RUNBOOK_2026-08-25.md)
- [T0 current-truth baseline](../../operations/T0_CURRENT_TRUTH_BASELINE_2026-08-25.md)
