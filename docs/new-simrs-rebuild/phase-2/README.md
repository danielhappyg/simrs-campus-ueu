# Phase 2 — Identity / RBAC / synthetic reset

**Status:** MVP foundation landed (teaching-demo quality)  
**Date:** 2026-08-21

## Delivered

- Database-backed roles, permissions, and pivots (`roles`, `permissions`, `permission_role`, `role_user`)
- Capability constants + `RoleCapabilityMatrix` as single source for seeder and gates
- `User::hasRole()` / `User::canCapability()` with `is_system_administrator` break-glass
- Laravel Gates for each capability; `capability` middleware alias
- Inertia share: `auth.roles`, `auth.capabilities`
- `RbacSeeder` (always safe) + `DemoActorsSeeder` (demo-seed gated)
- `php artisan simulation:reset {--force}` via `SyntheticResetService`; audit and security-ledger evidence is always preserved
- BG-02 foundation: strict, semantically idempotent security-ledger/outbox records plus database immutability guards for the five BG-01 fact tables; no authorization path consumes these records yet
- BG-02b ordinary-audit contracts: the 15 registered action families are validated at the `AuditEvent` Eloquent creating boundary, unsafe/secret-like payloads are rejected, and print output fails closed when its audit cannot be stored
- BG-02c1 atomic success auditing: outpatient, emergency, and inpatient registration plus emergency and inpatient clinical-note writes roll back their domain mutation with HTTP 503 when required audit evidence cannot be stored
- BG-02c2 actor-attribution expansion: new ordinary-audit rows capture a finite `USER` or `SERVICE` identity reference, attributed users cannot be physically deleted, and account containment remains status-based
- BG-02c3 read-only attribution preflight: schema-qualified streaming classifies legacy rows without payload disclosure or identity inference and produces deterministic, keyed readiness evidence
- BG-02c4a private attribution manifest: exact-root/clean-source generation and offline verification bind reviewed recovery candidates as expiring keyed evidence; no apply/backfill executor exists
- BG-02c4b hosted preflight evidence: exact Vercel/Supabase release inventory identified migration-ledger drift, the mandatory Free-plan logical-backup gate, and one actor-provenance blocker; migration and promotion remain held
- BG-03 non-authoritative privileged-access comparison: `off` remains query/telemetry free, while `shadow` compares exact allowlisted scope, environment/release, database-time lease, approval-chain, revocation and HMAC-bound session facts without changing the existing Gate result. Accidental `enforce` is also deliberately non-authoritative until BG-08.

## Not in this phase

- Clinical/domain tables and outpatient UI (Phase 3)
- Patient/Encounter policies beyond capability gates
- Cohort/unit-scoped authorization
- Database-level immutability and raw SQL/DB-role protection for `audit_events` remain deferred. BG-02b closes normal Eloquent creation and adds an application architecture check, but it does not claim protection from a database credential that can issue raw writes.
- Hosted execution of the legacy-attribution preflight, durable USER recovery provenance, reviewed manifest apply/backfill, and a later non-null contraction remain deferred. BG-02c2 intentionally keeps the new snapshot columns nullable for rollout compatibility; BG-02c3 never infers a missing historical actor; BG-02c4a generates/verifies private evidence only.
- Complete denial auditing for the five BG-02c1 mutation routes remains outside this atomic-success slice; existing authorization and validation denial behavior is unchanged.
- A G1-accepted long-term break-glass control. The current permanent `is_system_administrator` bypass remains runtime truth; [ADR-017](ADR-017-TIME-BOUND-SCOPED-BREAK-GLASS.md) and the [G1 acceptance contract](G1_BREAK_GLASS_ACCEPTANCE_CONTRACT.md) are proposed and not owner-approved.
- BG-04 through BG-08 request/approval, real activation, session revocation/binding, hosted observation, recovery and cutover. BG-03 creates no usable privilege and no state-changing endpoint.

BG-02c2 rollout remains governed by the [audit-attribution rollout runbook](../../operations/BG_02C2_AUDIT_ATTRIBUTION_ROLLOUT_2026-08-25.md).
BG-02c3 execution remains governed by the [read-only attribution-preflight runbook](../../operations/BG_02C3_AUDIT_ATTRIBUTION_PREFLIGHT_2026-08-25.md).
BG-02c4a manifest custody remains governed by the [private attribution-manifest runbook](../../operations/BG_02C4A_AUDIT_ATTRIBUTION_MANIFEST_2026-08-25.md).
BG-02c4b hosted release ordering remains governed by the [hosted attribution-preflight evidence](../../operations/BG_02C4B_HOSTED_ATTRIBUTION_PREFLIGHT_2026-08-25.md).

## Evidence

- `tests/Feature/Authorization/RoleCapabilityDenialTest.php`
- `tests/Feature/Simulation/SimulationResetCommandTest.php`
- Matrix: [RBAC_MATRIX.md](./RBAC_MATRIX.md)
- Proposed G1 design: [ADR-017 — time-bound scoped break-glass](ADR-017-TIME-BOUND-SCOPED-BREAK-GLASS.md)
- Proposed G1 gate: [break-glass acceptance contract](G1_BREAK_GLASS_ACCEPTANCE_CONTRACT.md)
- Local BG-03 evidence: [`T1_BG03_PRIVILEGED_ACCESS_SHADOW_EVIDENCE_2026-08-27.md`](../../operations/T1_BG03_PRIVILEGED_ACCESS_SHADOW_EVIDENCE_2026-08-27.md)
