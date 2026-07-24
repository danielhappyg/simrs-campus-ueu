# Outpatient Laboratory Access-Control Validation — 24 July 2026

- **Implementation revision:** `2da7716`
- **Branch:** `agent/outpatient-domain-spine`
- **Review surface:** private draft PR #10
- **Status:** automated validation complete; not merged, deployed, or faculty-pilot approved
- **Decision authority:** Daniel Happy Putra, project manager/PIC

## 1. Scope and boundary

This record validates the reserved-account access lifecycle for the synthetic outpatient laboratory reference MVP. It covers the ten configured `@example.invalid` demo accounts, their retained `SIM-RJ-UEU-001` source assignments, temporary-password rotation, authentication-artifact revocation, audit minimization, and the operator entry/closeout instructions.

It does not validate real patient data, hospital clinical use, production integrations, a hosted laboratory environment, a faculty pilot, or any deferred module. No deployment or merge occurred.

## 2. Enforced contract

The implementation:

1. accepts only `enable` or `disable` with the exact action-specific confirmation phrase;
2. runs only outside production when `APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`, and the centrally revocable `database` / `sessions` session backend are configured;
3. requires `DEMO_SEED_ENABLED=true` and a temporary `DEMO_ACCOUNT_PASSWORD` of at least 12 characters before enablement;
4. validates an exact, unique ten-account synthetic roster and resolves the same ten users through the pristine `OPD-REF-001` version 1 retained source assignments;
5. enables the roster by setting accounts active and verified, rotating all ten passwords, and clearing prior browser sessions, passkeys, reset tokens, remembered login, and two-factor state;
6. disables the roster by setting accounts suspended and clearing the same authentication artifacts without deleting simulation records;
7. leaves unrelated accounts and their authentication artifacts unchanged;
8. performs each transition and aggregate audit write in one transaction, including rollback on a late audit failure;
9. treats an already-complete target state as the successful idempotent `UNCHANGED` result without duplicating the audit event; and
10. excludes account identities, passwords, confirmation input, request fingerprints, and authentication secrets from command output and audit metadata.

The read-only laboratory preflight now also fails when the required centrally revocable session backend is absent.

## 3. Automated evidence

### 3.1 Clean local candidate

A clean archive of branch revision `1c35c5a` was overlaid with only the access-control increment and validated independently from the unrelated dirty working-tree files.

| Gate | Result |
| --- | --- |
| PHP formatting | Pass |
| PHP static analysis | Pass, zero errors |
| PHP suite | Pass, 269 tests and 3,537 assertions |
| Focused access suite after portability correction | Pass, 7 tests and 249 assertions |
| Frontend formatting, lint, and TypeScript | Pass |
| React unit/accessibility suite | Pass, 28 files and 75 tests |
| Production frontend build | Pass |
| Markdown link/fence validation | Pass, 59 files |
| Composer audit | No advisory found |
| npm audit at high threshold | Zero vulnerability reported |

### 3.2 Private draft PR

The final implementation revision `2da7716` passed:

- Documentation checks;
- PHP, React, and security checks;
- the complete MySQL 8.4 backend integration suite; and
- immutable release-candidate assembly with no deployment.

The first pushed test used strict JSON key ordering and failed only because MySQL canonicalized the audit-metadata object keys differently from SQLite. Revision `2da7716` compares the same typed key/value contract independent of storage ordering; the complete MySQL job then passed.

GitHub separately reported three moderate Dependabot alerts on the default branch during push. Local locked-file audits reported no release-blocking advisory. The alerts were not changed as part of this bounded increment and require separate triage before any deployment decision.

## 4. Scenario evidence

Automated tests prove:

- unsafe production/mode/synthetic/session configurations and wrong confirmation fail without account mutation or protected-value disclosure;
- disablement suspends exactly ten reserved users, revokes their authentication artifacts, denies a subsequent password login, and preserves an unrelated user;
- repeated disablement is idempotent;
- enablement rotates all ten passwords, invalidates the old password, clears stale authentication factors, and is idempotent;
- an incomplete retained-source roster fails closed without mutation; and
- an audit write failure rolls back account states and all deleted authentication artifacts.

## 5. Operator path

Before a rehearsal:

```bash
php artisan simulation:lab-access enable --confirm=ENABLE-RESERVED-DEMO-ACCESS
php artisan simulation:lab-preflight
```

After evidence capture:

```bash
php artisan simulation:lab-access disable --confirm=DISABLE-RESERVED-DEMO-ACCESS
```

Only `ENABLED` / `UNCHANGED` followed by preflight `READY` is valid preparation evidence. Only `DISABLED` / `UNCHANGED` is valid access-closeout evidence. The temporary password remains an environment secret distributed out-of-band.

## 6. Remaining validation

This increment does not claim the laboratory is operational yet. Before Daniel can authorize a controlled participant pilot, the exact hosted or isolated laboratory environment still needs:

- environment-specific enable, preflight, login, session-revocation, and disable evidence;
- one complete facilitator rehearsal using a disposable outpatient session;
- native-browser and participant-role observations recorded in a dated UAT record;
- confirmed infrastructure access restriction and recovery procedure; and
- Daniel's explicit written pilot decision.

Any `BLOCKED` lifecycle result, surviving reserved-account access after disablement, roster mismatch, protected-value disclosure, transaction partiality, or production integration discovery is a stop condition.

## 7. Related operating documents

- [Outpatient Laboratory Pilot Runbook](OUTPATIENT_LAB_PILOT_RUNBOOK.md)
- [Checkpoint 2 UAT Facilitator Guide](OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md)
- [Checkpoint 2 UAT Record Template](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_TEMPLATE.md)
