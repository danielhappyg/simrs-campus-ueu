# Privileged rebuild-admin reconciliation runbook

**Scope:** Synthetic SIMRS Campus UEU demo only  
**Target:** The account configured by `simulation.rebuild_admin_email`  
**Command:** `rebuild:admin-reconcile`

Use this runbook to correct the known rebuild-admin role-membership drift without reseeding demo actors or directly editing the role pivot table. It does not make the account a clinical actor and does not authorize real data or live integrations.

## Important security boundary

The canonical account has only the `admin` role, but `is_system_administrator=true` still makes `canCapability()` grant every capability. Role reconciliation repairs identity and role provenance; it does **not** remove that bootstrap bypass.

Until G1 approves and implements a time-bound break-glass design:

- never use the rebuild-admin account for clinical, laboratory, pharmacy, financial, claim, or RMIK work;
- prefer `--disable` when the account is not needed;
- activate it only through a separately attributable administrative operation;
- revoke all sessions after each privileged task; and
- do not change the system-administrator flag as part of drift correction.

## Preconditions

1. Work from the exact reviewed commit intended for the operation.
2. Confirm `APP_MODE=SIMULATION` and `APP_SYNTHETIC_ONLY=true`.
3. Confirm the configured target email is the synthetic rebuild-admin address.
4. Confirm the target is a system administrator and its roles are either:
   - canonical `admin` only; or
   - the known drift set `admin`, `nurse`, `physician`, `registrar`, and `rmik`.
5. Record an accountable operator and a specific operational reason.
6. Do not place passwords, connection strings, cookies, or tokens in the command, evidence, terminal transcript, or Git.

The command refuses an unknown role combination, missing admin role, non-system-administrator target, missing target, or non-simulation/non-synthetic mode.

## Dry run

The default mode reads and reports state without changing roles, status, sessions, passwords, or audit records:

```bash
php artisan --env=vercel.local rebuild:admin-reconcile
```

Review the target public ID, before/after roles, status, and session count. Stop if the target or role set differs from the expected state.

## Apply with containment

When the account is not needed, use the containment form:

```bash
php artisan --env=vercel.local rebuild:admin-reconcile \
  --apply \
  --disable \
  --operator="NAMED OPERATOR" \
  --reason="SPECIFIC APPROVED REASON"
```

The operation transactionally:

1. locks and revalidates the exact target and role rows;
2. syncs the account to `admin` only when the known drift exists;
3. disables the account when requested;
4. revokes every database session for the account; and
5. records `authorization.rebuild_admin.reconciled` with before/after roles and status, sessions revoked, operator, reason, mutation flags, and the remaining bypass warning.

If audit persistence fails, the role, status, and session changes roll back together. The password and `is_system_administrator` flag are never changed.

Omit `--disable` only when an approved administrative task requires the account to remain in its existing status:

```bash
php artisan --env=vercel.local rebuild:admin-reconcile \
  --apply \
  --operator="NAMED OPERATOR" \
  --reason="SPECIFIC APPROVED REASON"
```

## Verification and evidence

After apply:

1. run the dry command again and confirm `admin -> admin`;
2. confirm the intended status (`DISABLED` for containment);
3. confirm zero sessions for the target;
4. confirm dedicated registrar, nurse, physician, and RMIK accounts were not changed;
5. retain the audit-event public identifier and timestamp, but no credential material; and
6. record the exact Git SHA, environment, operator, reason, before/after state, and result in the release evidence.

An idempotent apply on already canonical state still records an attributable audit event and honestly reports that no domain mutation was needed.

## Rollback and escalation

Do not restore the five-role drift. If an administrative task later needs the contained account, use the approved account-status activation path, perform only that task, and disable/revoke it afterward.

If the command refuses the current state, do not bypass it with SQL or rerun `DemoActorsSeeder`. Investigate the unexpected role or account state, decide the intended disposition, and add a tested correction path. The G1 break-glass decision remains separate from this T0 drift repair.

## Related controls

- `docs/new-simrs-rebuild/phase-2/RBAC_MATRIX.md`
- `docs/operations/T0_CURRENT_TRUTH_BASELINE_2026-08-25.md`
- `docs/operations/VERCEL_SUPABASE_DEMO.md`
- `docs/new-simrs-rebuild/phase-0/RELEASE_EVIDENCE_INDEX.md`
