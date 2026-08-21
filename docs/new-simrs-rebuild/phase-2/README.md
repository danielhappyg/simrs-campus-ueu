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
- `php artisan simulation:reset {--force} {--purge-audit}` via `SyntheticResetService`

## Not in this phase

- Clinical/domain tables and outpatient UI (Phase 3)
- Patient/Encounter policies beyond capability gates
- Cohort/unit-scoped authorization

## Evidence

- `tests/Feature/Authorization/RoleCapabilityDenialTest.php`
- `tests/Feature/Simulation/SimulationResetCommandTest.php`
- Matrix: [RBAC_MATRIX.md](./RBAC_MATRIX.md)
