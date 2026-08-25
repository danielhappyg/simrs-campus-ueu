# BG-02c3 Audit Attribution Preflight

Status: read-only implementation evidence; not authorization to backfill, contract the schema, migrate, or deploy.

## Purpose and boundary

`audit:attribution:preflight` inventories only the actor-attribution state of ordinary audit rows. It does not change rows, infer missing people, validate every audit tuple, prove raw-database immutability, or certify that all required clinical and operational actions are audited.

Run it only in `APP_MODE=SIMULATION` with synthetic-only enforcement. The command refuses unsafe runtime modes, unsupported database drivers, an unexpanded audit schema, and a missing application digest key.

The scanner reads only audit ID, action, actor foreign key, actor type/reference, and the joined user's public ULID. It never selects or emits names, email addresses, reasons, metadata, user agents, IP values, credentials, or patient/domain payloads.

## Classifications

| Classification | Meaning | Permitted next action |
| --- | --- | --- |
| `CURRENT` | A complete `USER` snapshot matches the currently verifiable persisted user, or an exact allowlisted `SERVICE` identity is present. | Retain. |
| `BACKFILLABLE_USER` | Both snapshot fields are null and the persisted actor foreign key resolves to a user with a valid public ULID. | Eligible only for a separately reviewed, manifest-bound backfill. |
| `BACKFILLABLE_SERVICE` | The actor foreign key and both snapshot fields are null, and the action is the historically service-exclusive rebuild-admin reconciliation. | Eligible only for a separately reviewed, manifest-bound backfill. |
| `BLOCKING` | Identity evidence is incomplete, inconsistent, invalid, or absent. | Stop; investigate without guessing an actor. |

A typed `USER` reference remains an immutable event-time snapshot and is never rewritten. This preflight can certify it as current only when it matches the presently verifiable persisted user. A mismatch blocks unless a future control can prove immutable public-ID history; the scanner never assumes that a well-formed ULID belonged to that user. A legacy null user snapshot is backfillable only when its existing actor foreign key is intact.

Legacy null teaching-reset actors are always blocking. Earlier reset code accepted either a user or a service, while the old foreign key could erase a deleted user's link with `SET NULL`; action alone therefore cannot prove service identity.

## Commands and exits

Human-readable, safe summary:

```bash
php artisan audit:attribution:preflight
```

One-line machine-readable evidence:

```bash
php artisan audit:attribution:preflight --json
```

Strict contraction gate:

```bash
php artisan audit:attribution:preflight --json --require-current
```

Exit codes are:

- `0`: no blocking rows; without `--require-current`, reviewed backfill candidates may remain;
- `1`: safe operational/precondition failure, including invocation inside an existing transaction whose snapshot cannot be proven;
- `2`: `--require-current` was requested and reviewed backfill candidates remain;
- `3`: one or more blocking rows exist.

The JSON contains fixed classification/reason counts and a deterministic keyed, length-prefixed SHA-256 root. Roots are comparable only when the logical rows and configured application key are unchanged; never record or expose that key. The report contains no row samples or raw values. Retain the exact application SHA, command, exit code, root, counts, database engine/version, resolved private schema, operator, and reviewer in the release evidence pack. Never retain credentials or raw audit payloads.

## Hosted sequence

1. Confirm the exact release SHA, Supabase target, private `laravel` schema, backup/restore point, and drained old writers.
2. Apply and verify the BG-02c2 expansion explicitly; Vercel does not run database migrations.
3. Run the default JSON preflight once. Do not retry an ambiguous failure automatically.
4. If any `BLOCKING` count is non-zero, stop. A separately reviewed diagnostic must locate the affected synthetic rows without exposing payload values or guessing identity.
5. If only backfillable rows remain, prepare a separate manifest-bound BG-02c4 change with review, rollback, and exact-root binding.
6. After that controlled change, run `--require-current`. A zero exit and `contract_ready=true` are prerequisites for a later non-null contraction; they are not by themselves authorization to perform it.

No hosted command, migration, backfill, contraction, Vercel promotion, or credential transmission is part of BG-02c3 implementation alone.
