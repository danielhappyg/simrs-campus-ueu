# Phase 2 RBAC matrix (teaching demo)

Source of truth in code: `app/Support/Authorization/RoleCapabilityMatrix.php`.

Role slugs: `registrar`, `nurse`, `physician`, `rmik`, `admin`.

| Capability | registrar | nurse | physician | rmik | admin |
|---|---|---|---|---|---|
| `patient.search` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `patient.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `patient.register` | ✓ | | | | ✓ |
| `encounter.list` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `encounter.open` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `encounter.cancel` | ✓ | | | | ✓ |
| `clinical.nursing.write` | | ✓ | | | |
| `clinical.medical.write` | | | ✓ | | |
| `clinical.order.create` | | | ✓ | | |
| `clinical.amend` | | ✓ | ✓ | | |
| `rmik.review` | | | | ✓ | |
| `rmik.coding.write` | | | | ✓ | |
| `rmik.completeness.signoff` | | | | ✓ | |
| `audit.view` | | | | | ✓ |
| `user.manage` | | | | | ✓ |
| `role.manage` | | | | | ✓ |
| `synthetic.reset` | | | | | ✓ |
| `master.manage` | | | | | ✓ |

## Demo actors (when `DEMO_SEED_ENABLED`)

| Email | Role | Notes |
|---|---|---|
| `registrar.demo@example.invalid` | registrar | |
| `nurse.demo@example.invalid` | nurse | |
| `physician.demo@example.invalid` | physician | |
| `rmik.demo@example.invalid` | rmik | |
| `admin.rebuild@example.invalid` | admin | Also `is_system_administrator` |

Password: `DEMO_ACCOUNT_PASSWORD` (min 12 chars). Accounts are `ACTIVE` with verified email.

## Break-glass

`users.is_system_administrator` makes `canCapability()` return true for every capability. Prefer role grants for teaching accounts; keep the flag for bootstrap admin only.
