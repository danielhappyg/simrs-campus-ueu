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

## Not in this phase

- Clinical/domain tables and outpatient UI (Phase 3)
- Patient/Encounter policies beyond capability gates
- Cohort/unit-scoped authorization
- Database-level immutability and raw SQL/DB-role protection for `audit_events` remain deferred. BG-02b closes normal Eloquent creation and adds an application architecture check, but it does not claim protection from a database credential that can issue raw writes.
- Durable actor attribution after user deletion remains deferred because the current `actor_user_id` foreign key uses `nullOnDelete`; BG-02b does not preserve an immutable actor snapshot for that case.
- Complete denial auditing for the five BG-02c1 mutation routes remains outside this atomic-success slice; existing authorization and validation denial behavior is unchanged.
- A G1-accepted long-term break-glass control. The current permanent `is_system_administrator` bypass remains runtime truth; [ADR-017](ADR-017-TIME-BOUND-SCOPED-BREAK-GLASS.md) and the [G1 acceptance contract](G1_BREAK_GLASS_ACCEPTANCE_CONTRACT.md) are proposed and not owner-approved.

## Evidence

- `tests/Feature/Authorization/RoleCapabilityDenialTest.php`
- `tests/Feature/Simulation/SimulationResetCommandTest.php`
- Matrix: [RBAC_MATRIX.md](./RBAC_MATRIX.md)
- Proposed G1 design: [ADR-017 — time-bound scoped break-glass](ADR-017-TIME-BOUND-SCOPED-BREAK-GLASS.md)
- Proposed G1 gate: [break-glass acceptance contract](G1_BREAK_GLASS_ACCEPTANCE_CONTRACT.md)
