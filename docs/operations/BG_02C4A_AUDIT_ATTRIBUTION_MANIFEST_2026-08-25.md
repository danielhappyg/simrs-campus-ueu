# BG-02c4a — Private audit-attribution recovery manifest

**Status:** generator and offline verifier only; no apply/backfill executor exists
**Boundary:** `APP_MODE=SIMULATION`, synthetic-only, private evidence
**Date:** 2026-08-25

BG-02c4a turns an owner-reviewed BG-02c3 root into a short-lived, canonical recovery manifest without changing `audit_events`. It deliberately does not authorize a backfill or the later non-null contraction.

## Safety contract

- Generate only from one command-owned repeatable database snapshot with zero `BLOCKING` rows; candidate/root, database target, engine, semantic schema contract, schema fingerprint, and migration fingerprint are captured inside that same snapshot. The contract includes the exact engine-normalized column definitions, attribution index, unique user public reference, and restrictive same-schema user foreign key.
- Require the exact expected BG-02c3 root, an exact clean tracked Git `HEAD`, the bound Git tree, and runtime-file hashes cross-checked against immutable commit blobs before and after generation.
- Write by exclusive create beneath `storage/app/private/audit-attribution-manifests`, refuse traversal/symlinks/existing files, cap entries and bytes, and require mode `0600`.
- Emit only keyed locators, source-leaf digests, target-reference digests, finite classifications/rules, and target type. The file contains no raw audit/user ID, action, reference, name, email, reason, metadata, or payload.
- Null-actor teaching reset events remain ambiguous and block generation.
- The manifest is bound to its supplied ULID, nonce, timestamps, expiry, change reference, non-secret key ID, before/expected-after roots, sorted entries, canonical digest, and HMAC.
- Verification is offline and read-only. It validates private-directory custody, one-handle file identity/mode/size, exact canonical schema, order, an independently recorded expected digest, key ID, HMAC, and expiry; it does not connect to or mutate the database.

## Critical attribution limitation

`USER_FROM_RESTRICTED_FK_RECOVERY_V1` means recovery-time canonical attribution from the presently restricted `actor_user_id` foreign key. It is **not evidence of the user's event-time public reference**. `users.public_id` is not yet protected by an immutable historical contract. A future apply design must first establish durable, independently reviewed recovery provenance for each USER target. Without that evidence, apply and non-null contraction must fail closed.

## Controlled generation

First run BG-02c3 and independently record its root. On the exact reviewed commit, supply a unique manifest ULID and nonce plus a deliberately short UTC expiry:

```bash
php artisan audit:attribution:manifest-generate bg02c4a-01J....json \
  --expect-root=<64-lowercase-hex-preflight-root> \
  --expect-sha=<full-clean-tracked-head-sha> \
  --change-reference=BG-02C4A-REVIEW-001 \
  --manifest-ulid=<UPPERCASE-ULID> \
  --nonce=<32-to-64-lowercase-hex> \
  --created-at=2026-08-25T10:00:00Z \
  --expires-at=2026-08-25T10:30:00Z
```

Success prints only `AUDIT_ATTRIBUTION_MANIFEST_GENERATED`. Failure prints a finite safe code and leaves no partial file. Use `--json` only when a compact machine-readable safe summary is required.

## Offline verification

The verifying host must have the matching application HMAC key and the private file at the same private manifest location:

```bash
php artisan audit:attribution:manifest-verify bg02c4a-01J....json \
  --expect-digest=<64-lowercase-hex-digest-from-reviewed-generation-output>
```

The expected digest must come from the independently retained generation/review evidence, not from reading the candidate file immediately before verification. Success prints only `AUDIT_ATTRIBUTION_MANIFEST_VERIFIED`. Verification does not prove that the source database still matches the before-root; that future apply-time check belongs to a separate, owner-approved executor.

## Custody and recovery

Treat the manifest as private security evidence even though values are keyed. Do not move it into `storage/app/public`, attach it to a public issue, commit it, or deploy it. If generation fails, preserve the BG-02c3 report and investigate the safe code. If a file is expired, changed, has the wrong mode, or no longer matches the reviewed runtime/database binding, generate a new manifest from a fresh reviewed preflight; never edit or overwrite the old file.

## Explicitly deferred graph nodes

1. Independent USER recovery-provenance review and durable evidence.
2. Manifest-bound transactional apply with engine-specific locks and exact before/after root checks.
3. Post-apply observation and reconciliation.
4. A separate non-null contraction migration with forward recovery and rollback constraints.

None of those nodes is implemented or authorized by BG-02c4a.
