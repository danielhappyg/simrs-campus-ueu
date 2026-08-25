# BG-02c4b G1 controlled hosted cutover — execution control record

**Status:** template only; no authorization, hosted refresh, backup, restore, promotion, migration, smoke test or cleanup is recorded

**Boundary:** synthetic teaching demo only

**Rule:** blank and `PENDING` fields are blockers, not implied approvals or successful evidence

This record separates the immutable application payload from the later governance packet that controls its release. Complete it at action time without recording credentials, connection strings, application keys, encryption identities, cookies, audit-row payloads or patient-like values.

## 1. Frozen release identities

| Control | Frozen value | Status |
| --- | --- | --- |
| Application payload SHA | `5129e31d1077dd340feb6af016151cef64f50b2b` | Frozen; the only approved payload |
| Reviewed application merge contained by payload | `3a9ad49c67d1c6c56831eb39afa7e5df8501190a` | Informational |
| Authoritative procedure/evidence carrier SHA | `PENDING` | Must be the exact action-time-authorized commit containing this companion, the current hosted preflight, the acceptance SQL and the evidence blobs below |
| Historical carrier of the three earlier evidence blobs | `3e0cfd647d6fc57d847a0c0758d3af843a1f5a7c` | Continuity only; it does not contain this companion or the acceptance SQL |
| First carrier of the same three earlier evidence blobs | `cf16ce6b19aef8b70877a96fdaaa2f02a52d94c5` | Continuity anchor only |
| Historical hosted-preflight blob | `a0f3baa94bf3838340c96f335f4c09bdb33c0bfd` | Byte-identical at the two historical carrier SHAs above; superseded by the current externally pinned preflight |
| Exact-SHA Preview evidence blob | `0d4f5ae96732866bde98413c281d7e32228fd3e3` | Byte-identical at the two carrier SHAs above |
| Release-evidence index blob | `0e400d84eb8eb4601fecb2e7cee571bef547ad26` | Byte-identical at the two carrier SHAs above |
| Acceptance SQL Git blob | `fada760479fe0a43972cc5bd5b40124312898125` | Frozen exact query artifact |
| Acceptance SQL SHA-256 | `832cfa0a145c5d92f32114675d1249300899e554497b65e2d275d7877639b3ba` | Frozen exact query bytes |
| Exact candidate Preview deployment | `dpl_Bj1s8MgwZMjUxGkajt3B1f6whYAk` | Historical proof recorded; action-time metadata refresh required |
| Historical Preview state/source | `READY`, Preview, SHA `5129e31d1077dd340feb6af016151cef64f50b2b` | Satisfied on 2026-08-25; not proof of current availability |

The clean deployment checkout must remain at the application payload SHA. The operator must use this companion, the acceptance SQL and the evidence documents from one exact, action-time-authorized procedure/evidence carrier commit. The current hosted preflight externally pins this companion's Git blob; this file does not claim an impossible self-hash. The older preflight embedded inside the payload checkout does not override this boundary. Any payload, procedure blob, acceptance SQL or Preview deployment substitution requires a new exact-SHA review and Preview proof.

## 2. Action-time authority and environment record

| Required field | Action-time value |
| --- | --- |
| Authorization reference and exact authorized scope | `PENDING` |
| Authorization date/time and expiry | `PENDING` |
| Exact authorized procedure/evidence carrier commit | `PENDING` |
| Named cutover operator | `PENDING` |
| Named independent reviewer | `PENDING` |
| Named backup/restore custodian | `PENDING` |
| Named rollback/forward-recovery decision authority | `PENDING` |
| Named authority permitted to reactivate maintenance | `PENDING` |
| Short-lived access reference and expiry, without secret value | `PENDING` |
| Approved encrypted backup destination, outside repository | `PENDING` |
| Approved public-key encryption method/tool/version | `PENDING` |
| Approved public recipients-file SHA-256 | `PENDING` |
| Approved normalized public-recipient-set SHA-256 | `PENDING` |
| Approved public encryption-recipient fingerprints, never private identities | `PENDING` |
| Approved Production source libpq service name / service-file SHA-256 | `PENDING` |
| Approved Linux/platform custody-gate procedure | `PENDING — Linux template below or separately reviewed equivalent` |
| Approved immutable per-attempt secret-mount fingerprint | `PENDING`; SHA-256 of target/source/fstype/major:minor/options; kernel `ro` required |
| Trusted parent / mountpoint owner-mode-device-inode custody | `PENDING` |
| Approved PGSERVICEFILE owner/mode/device/inode | `PENDING` |
| Approved PGPASSFILE owner/mode/device/inode | `PENDING` |
| Secret-manager/custodian UID:GID / operator expected UID:GID:groups | `PENDING — operator is not parent/mount/service owner and is a member of the approved read-only custody GID; narrow PGPASSFILE exception below` |
| Approved operator effective-capability evidence | `PENDING`; Linux `CapEff=0000000000000000` required by this template |
| Approved platform enforcement evidence preventing remount, mount shadowing and privilege escalation | `PENDING` |
| Short-lived target-binding HMAC key ID / SHA-256 commitment / expiry | `PENDING — non-secret metadata only` |
| Approved Production target HMAC for source snapshot A | `PENDING` |
| Approved Production target HMAC for source snapshot B | `PENDING` |
| Isolated PostgreSQL 17 restore target A | `PENDING` |
| Independent isolated PostgreSQL 17 restore target B | `PENDING` |
| Backup retention deadline and custodian | `PENDING` |
| Restore-target and temporary-secret cleanup authority | `PENDING` |

Authorization must expressly cover Production environment inspection, logical backup and isolated restore, maintenance activation, exact deployment promotion, one migration attempt, verification, permitted/denied synthetic smoke checks, temporary-access revocation and the selected recovery path. It does not authorize manifest generation, attribution backfill, non-null contraction, real data or live integrations.

## 3. Last-known versus action-time hosted state

Last-known values are historical comparison points only. Do not copy them into the observed column without fresh evidence.

| Control | Last known on 2026-08-25 | Action-time observation | Reviewer / evidence reference |
| --- | --- | --- | --- |
| Public Production deployment | `dpl_4uGZACFzKsHMnNUy6YshiJjeQN1Q` | `PENDING` | `PENDING` |
| Public Production source SHA | `42ab482de577fe38cef539a74f0b749d64485b19` | `PENDING` | `PENDING` |
| Candidate Preview deployment/state/source SHA | `dpl_Bj1s8MgwZMjUxGkajt3B1f6whYAk` / `READY` / `5129e31d1077dd340feb6af016151cef64f50b2b` | `PENDING` | `PENDING` |
| Supabase project identity | `simrs-campus-ueu-demo` | `PENDING` | `PENDING` |
| Database engine | PostgreSQL `17.6` | `PENDING` | `PENDING` |
| Private schema / table count | `laravel` / 31 | `PENDING` | `PENDING` |
| Laravel migration ledger | 31 rows; last batch 7, `2026_08_24_000100_create_outpatient_documentation_tables` | `PENDING` | `PENDING` |
| Pending migration names | Exact six-name set in section 4 | `PENDING` | `PENDING` |
| Ordinary audit rows | 70 total / 69 with actor FK / 1 null actor FK | `PENDING` | `PENDING` |
| Users/public IDs | 15 users / 15 non-null / 15 distinct | `PENDING` | `PENDING` |
| Targeted sequence contracts | 13 compatible same-owner/owned/default-dependency contracts | `PENDING` | `PENDING` |
| Production maintenance keys | `APP_MAINTENANCE_DRIVER=cache`; `APP_MAINTENANCE_STORE=database` | `PENDING`; record only key names and pass/fail, not secret values | `PENDING` |
| Synthetic isolation keys | `APP_MODE=SIMULATION`; `APP_SYNTHETIC_ONLY=true`; `DB_SCHEMA=laravel`; Preview has no Production DB credential | `PENDING`; record only pass/fail | `PENDING` |

Historical protected Preview boot is already evidenced. At action time, refresh only its Vercel state, target, deployment ID and full source SHA unless a new runtime proof is required by observed drift. Never attach Production database credentials to Preview.

## 4. Drift and abort contract

The only acceptable pre-migration pending set is:

1. `2026_08_21_000100_create_rebuild_foundation_tables`
2. `2026_08_22_000800_add_patient_marital_status`
3. `2026_08_22_001000_qualify_laravel_serial_sequence_defaults`
4. `2026_08_25_000100_create_break_glass_record_tables`
5. `2026_08_25_000200_create_security_ledger_tables`
6. `2026_08_25_000300_expand_audit_actor_attribution`

Abort before any migration when any condition below is true:

- a frozen SHA, evidence blob or exact Preview identity does not match;
- the public Production deployment/SHA changed without reviewed compatibility evidence;
- database/project/schema identity, table shape, grants, migration ledger, audit counts, user/public-ID counts or any of the 13 sequence contracts differs;
- the authorized libpq service name/config hash, immutable mount/file custody fingerprint, target-binding key commitment or Production target HMAC does not match for source snapshot A or B;
- PostgreSQL is not major version 17, or a patch-version change has not been reviewed;
- the pending migration set is missing, reordered, duplicated or has any seventh name;
- Production maintenance or synthetic-isolation configuration is absent, ambiguous or incompatible;
- the initial backup or its isolated restore receipt is incomplete;
- the shared maintenance marker, exact deployment promotion, `/up=200`, `/login=503`, writer-drain interval or no-old-writer proof is incomplete;
- the final post-drain backup or its independent restore receipt is incomplete;
- operator, reviewer, authorization, recovery authority or temporary-access expiry is blank;
- any command outcome, connection termination, timeout or deployment state is ambiguous.

An abort leaves or returns the environment to the safest non-writing state available. Do not insert migration-ledger rows manually, do not broaden compatibility checks and do not automatically retry a migration, promotion, backup or restore whose completion is uncertain.

## 5. Secret-safe PostgreSQL 17 logical-backup template

This exact template assumes approved `age` public-key encryption. If another method is selected, it requires a separately reviewed replacement template before cutover. For each A/B attempt, the approved secret manager must create unique `PGSERVICEFILE` and `PGPASSFILE` objects in one read-only immutable mount outside the repository. The custodian owns the trusted parent and mountpoint at exact mode `0550` and `PGSERVICEFILE` at `0440`; the operator reaches them only through the approved read-only custody group. The ordinary operator must be unable to write or replace the directory or either file. Their contents must not be printed, copied, `chmod`ed by the operator or pasted into evidence; only the authorized service-file hash and owner/group/mode/device/inode custody fingerprints may be retained.

There is one narrow ownership exception: libpq rejects a group-readable password file, so the secret manager must present `PGPASSFILE` as exact operator-UID-owned mode `0400` on that same kernel-`ro` mount. Its group, device and inode remain authorization-pinned. Nominal ownership must not provide write or `chmod` capability because the mount is kernel read-only, the operator is non-root with zero effective capabilities, and the parent/mountpoint remain custodian-owned and non-writable. If the platform cannot provide and evidence this exact contract, the attempt is `NO-GO`; do not weaken libpq's password-file check or move the password into the service file/environment.

The command below is the Linux custody gate. If `findmnt`, `/proc/self/status`, canonical-path checks or the expected kernel mount evidence are unavailable, stop with `NO-GO`; file modes and `test ! -w` alone are insufficient. Another platform requires a separately reviewed, action-time-authorized equivalent that proves a stable kernel-enforced read-only mount and a non-root operator without effective mount/admin capability. These local checks do not prove secret-manager provenance or independently rule out a platform that permits unprivileged mount shadowing or later privilege escalation. A separate approved platform enforcement/attestation reference must close those boundaries or the attempt remains `NO-GO`.

Required non-secret placeholders:

```text
PGSERVICEFILE=<approved absolute path outside repository>/pg_service.conf
PGPASSFILE=<approved absolute path outside repository>/pgpass
G1_SOURCE_SERVICE=<non-secret libpq service name for Production-demo source>
G1_BACKUP_DESTINATION=<approved absolute encrypted-backup directory outside repository>
G1_BACKUP_ID=<pre-drain-or-post-drain identifier>
G1_BACKUP_ATTEMPT_ID=<unique action-time attempt identifier>
G1_AGE_RECIPIENTS_FILE=<approved absolute path to public recipients file>
G1_EXPECTED_RECIPIENTS_FILE_SHA256=<authorized exact file hash>
G1_EXPECTED_RECIPIENT_SET_SHA256=<authorized normalized public-recipient-set hash>
G1_EXPECTED_SERVICE_CONFIG_SHA256=<authorized exact libpq service-file hash>
G1_SECRET_MOUNT_DIRECTORY=<approved immutable per-attempt secret-manager mount outside repository>
G1_EXPECTED_SECRET_PARENT_CUSTODY=<authorized owner:group:mode:device:inode>
G1_EXPECTED_SECRET_MOUNT_CUSTODY=<authorized owner:group:mode:device:inode>
G1_EXPECTED_SERVICE_FILE_CUSTODY=<authorized owner:group:mode:device:inode>
G1_EXPECTED_PASSWORD_FILE_CUSTODY=<authorized owner:group:mode:device:inode>
G1_EXPECTED_MOUNT_FINGERPRINT_SHA256=<authorized findmnt target/source/fstype/major:minor/options fingerprint>
G1_EXPECTED_SECRET_CUSTODIAN_UID=<authorized secret-manager/custodian numeric UID>
G1_EXPECTED_SECRET_CUSTODIAN_GID=<authorized read-only custody numeric GID>
G1_EXPECTED_OPERATOR_UID=<authorized non-root numeric UID>
G1_EXPECTED_OPERATOR_GID=<authorized numeric primary GID>
G1_EXPECTED_OPERATOR_GROUPS=<authorized sorted comma-separated numeric groups>
G1_EXPECTED_OPERATOR_CAP_EFF_HEX=0000000000000000
G1_TARGET_BINDING_HMAC_KEY_ID=<authorized non-secret short-lived key ID>
G1_TARGET_BINDING_HMAC_KEY=<dedicated short-lived secret injected by approved secret manager>
G1_EXPECTED_TARGET_BINDING_KEY_COMMITMENT_SHA256=<authorized non-secret key commitment>
G1_EXPECTED_TARGET_BINDING_HMAC_SHA256=<authorized Production target HMAC for pair A or B>
G1_EXPORTED_SNAPSHOT=<opaque snapshot ID from an open read-only source transaction>
G1_ACCEPTANCE_SQL=<absolute path in the authorized carrier checkout to the frozen .sql file>
G1_SOURCE_RESULT=<approved value-minimized evidence path outside repository>
```

Use two source sessions for each independently named pair, A before drain and B after drain. In exporter session E, run exactly `BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;` and `SELECT pg_export_snapshot();`, record the returned action-time snapshot reference/hash, and leave E open. In query session Q, begin the same transaction mode, import E's snapshot with `SET TRANSACTION SNAPSHOT`, run the frozen SQL, and commit Q. Pass the same snapshot to `pg_dump --snapshot`. Commit and close E only after Q and the dump's no-replace publication both finish unambiguously. A timeout or uncertain E close is a failed receipt and is never retried automatically.

The target-binding key must be randomly generated for this cutover, short-lived, dedicated to this control, and injected only by the approved secret manager after shell tracing is disabled. Its non-secret key ID, SHA-256 commitment and expected A/B target HMACs must be independently authorized before either source session begins; an operator must not bless the HMAC observed from the connection being checked. Service name/config hash and target HMAC are conjunctive controls, not substitutes.

Command template—run only after authorization and populate variables without exposing secret values:

```bash
set -euo pipefail
set +x
umask 077
export PGSERVICEFILE="<approved absolute path outside repository>/pg_service.conf"
export PGPASSFILE="<approved absolute path outside repository>/pgpass"
export G1_SOURCE_SERVICE="<approved non-secret libpq source service name>"
export G1_BACKUP_DESTINATION="<approved absolute encrypted-backup directory outside repository>"
export G1_BACKUP_ID="<approved pre-drain-or-post-drain identifier>"
export G1_BACKUP_ATTEMPT_ID="<unique action-time attempt identifier>"
export G1_AGE_RECIPIENTS_FILE="<approved absolute path to public recipients file>"
export G1_EXPECTED_RECIPIENTS_FILE_SHA256="<authorized exact public recipients-file SHA-256>"
export G1_EXPECTED_RECIPIENT_SET_SHA256="<authorized normalized public-recipient-set SHA-256>"
export G1_EXPECTED_SERVICE_CONFIG_SHA256="<authorized exact libpq service-file SHA-256>"
export G1_SECRET_MOUNT_DIRECTORY="<approved immutable per-attempt secret-manager mount outside repository>"
export G1_EXPECTED_SECRET_PARENT_CUSTODY="<authorized owner:group:mode:device:inode>"
export G1_EXPECTED_SECRET_MOUNT_CUSTODY="<authorized owner:group:mode:device:inode>"
export G1_EXPECTED_SERVICE_FILE_CUSTODY="<authorized owner:group:mode:device:inode>"
export G1_EXPECTED_PASSWORD_FILE_CUSTODY="<authorized owner:group:mode:device:inode>"
export G1_EXPECTED_MOUNT_FINGERPRINT_SHA256="<authorized findmnt fingerprint>"
export G1_EXPECTED_SECRET_CUSTODIAN_UID="<authorized secret-manager/custodian numeric UID>"
export G1_EXPECTED_SECRET_CUSTODIAN_GID="<authorized read-only custody numeric GID>"
export G1_EXPECTED_OPERATOR_UID="<authorized non-root numeric UID>"
export G1_EXPECTED_OPERATOR_GID="<authorized numeric primary GID>"
export G1_EXPECTED_OPERATOR_GROUPS="<authorized sorted comma-separated numeric groups>"
export G1_EXPECTED_OPERATOR_CAP_EFF_HEX="0000000000000000"
export G1_TARGET_BINDING_HMAC_KEY_ID="<authorized non-secret short-lived key ID>"
# G1_TARGET_BINDING_HMAC_KEY must already be exported by the approved secret manager.
export G1_EXPECTED_TARGET_BINDING_KEY_COMMITMENT_SHA256="<authorized non-secret key commitment>"
export G1_EXPECTED_TARGET_BINDING_HMAC_SHA256="<authorized Production target HMAC for pair A or B>"
export G1_EXPORTED_SNAPSHOT="<opaque snapshot ID from the open read-only source transaction>"
export G1_ACCEPTANCE_SQL="<authorized carrier checkout>/docs/operations/BG_02C4B_G1_SOURCE_RESTORE_ACCEPTANCE_2026-08-25.sql"
export G1_SOURCE_RESULT="<approved evidence directory outside repository>/source-${G1_BACKUP_ID}.csv"
export G1_BACKUP_FINAL="$G1_BACKUP_DESTINATION/$G1_BACKUP_ID.dump.age"
export G1_BACKUP_PARTIAL="$G1_BACKUP_DESTINATION/$G1_BACKUP_ID.dump.age.partial.$G1_BACKUP_ATTEMPT_ID"

g1_custody_fingerprint() {
  php -r '$s = lstat($argv[1]); if ($s === false) { exit(2); } printf("%u:%u:%04o:%u:%u", $s["uid"], $s["gid"], $s["mode"] & 07777, $s["dev"], $s["ino"]);' "$1"
}

g1_assert_libpq_custody() {
  G1_CUSTODY_PHASE="$1"
  test "$(uname -s)" = "Linux"
  command -v findmnt >/dev/null
  command -v realpath >/dev/null
  G1_ACTUAL_OPERATOR_UID="$(id -u)"
  G1_ACTUAL_OPERATOR_GID="$(id -g)"
  G1_ACTUAL_OPERATOR_GROUPS="$(id -G | tr ' ' '\n' | LC_ALL=C sort -n | paste -sd, -)"
  G1_ACTUAL_OPERATOR_CAP_EFF_HEX="$(awk '$1 == "CapEff:" { print $2 }' /proc/self/status)"
  test "$G1_ACTUAL_OPERATOR_UID" != "0"
  test "$G1_ACTUAL_OPERATOR_UID" = "$G1_EXPECTED_OPERATOR_UID"
  test "$G1_ACTUAL_OPERATOR_GID" = "$G1_EXPECTED_OPERATOR_GID"
  test "$G1_ACTUAL_OPERATOR_GROUPS" = "$G1_EXPECTED_OPERATOR_GROUPS"
  test "$G1_ACTUAL_OPERATOR_CAP_EFF_HEX" = "$G1_EXPECTED_OPERATOR_CAP_EFF_HEX"
  test "$G1_ACTUAL_OPERATOR_CAP_EFF_HEX" = "0000000000000000"
  test "$G1_ACTUAL_OPERATOR_UID" != "$G1_EXPECTED_SECRET_CUSTODIAN_UID"
  case ",${G1_ACTUAL_OPERATOR_GROUPS}," in *,"${G1_EXPECTED_SECRET_CUSTODIAN_GID}",*) ;; *) return 1 ;; esac
  G1_SECRET_MOUNT_PARENT="$(dirname -- "$G1_SECRET_MOUNT_DIRECTORY")"
  test -d "$G1_SECRET_MOUNT_PARENT"
  test ! -L "$G1_SECRET_MOUNT_PARENT"
  test ! -w "$G1_SECRET_MOUNT_PARENT"
  php -r '$s = lstat($argv[1]); if (($s["mode"] & 0777) !== 0550) { exit(2); }' "$G1_SECRET_MOUNT_PARENT"
  test -d "$G1_SECRET_MOUNT_DIRECTORY"
  test ! -L "$G1_SECRET_MOUNT_DIRECTORY"
  test ! -w "$G1_SECRET_MOUNT_DIRECTORY"
  php -r '$s = lstat($argv[1]); if (($s["mode"] & 0777) !== 0550) { exit(2); }' "$G1_SECRET_MOUNT_DIRECTORY"
  test -f "$PGSERVICEFILE"
  test ! -L "$PGSERVICEFILE"
  test -r "$PGSERVICEFILE"
  test ! -w "$PGSERVICEFILE"
  php -r '$s = lstat($argv[1]); if (($s["mode"] & 0777) !== 0440) { exit(2); }' "$PGSERVICEFILE"
  test -f "$PGPASSFILE"
  test ! -L "$PGPASSFILE"
  test -r "$PGPASSFILE"
  test ! -w "$PGPASSFILE"
  php -r '$s = lstat($argv[1]); if (($s["mode"] & 0777) !== 0400) { exit(2); }' "$PGPASSFILE"
  test "$(realpath -e -- "$G1_SECRET_MOUNT_PARENT")" = "$G1_SECRET_MOUNT_PARENT"
  test "$(realpath -e -- "$G1_SECRET_MOUNT_DIRECTORY")" = "$G1_SECRET_MOUNT_DIRECTORY"
  test "$(realpath -e -- "$PGSERVICEFILE")" = "$PGSERVICEFILE"
  test "$(realpath -e -- "$PGPASSFILE")" = "$PGPASSFILE"
  test "$(dirname -- "$PGSERVICEFILE")" = "$G1_SECRET_MOUNT_DIRECTORY"
  test "$(dirname -- "$PGPASSFILE")" = "$G1_SECRET_MOUNT_DIRECTORY"
  php -r '$s = lstat($argv[1]); if ($s === false || $s["uid"] !== (int) $argv[2] || $s["gid"] !== (int) $argv[3] || $s["uid"] === (int) $argv[4]) { exit(2); }' "$G1_SECRET_MOUNT_PARENT" "$G1_EXPECTED_SECRET_CUSTODIAN_UID" "$G1_EXPECTED_SECRET_CUSTODIAN_GID" "$G1_ACTUAL_OPERATOR_UID"
  php -r '$s = lstat($argv[1]); if ($s === false || $s["uid"] !== (int) $argv[2] || $s["gid"] !== (int) $argv[3] || $s["uid"] === (int) $argv[4]) { exit(2); }' "$G1_SECRET_MOUNT_DIRECTORY" "$G1_EXPECTED_SECRET_CUSTODIAN_UID" "$G1_EXPECTED_SECRET_CUSTODIAN_GID" "$G1_ACTUAL_OPERATOR_UID"
  php -r '$s = lstat($argv[1]); if ($s === false || $s["uid"] !== (int) $argv[2] || $s["gid"] !== (int) $argv[3] || $s["uid"] === (int) $argv[4]) { exit(2); }' "$PGSERVICEFILE" "$G1_EXPECTED_SECRET_CUSTODIAN_UID" "$G1_EXPECTED_SECRET_CUSTODIAN_GID" "$G1_ACTUAL_OPERATOR_UID"
  php -r '$s = lstat($argv[1]); if ($s === false || $s["uid"] !== (int) $argv[2] || $s["gid"] !== (int) $argv[3] || $s["uid"] === (int) $argv[4]) { exit(2); }' "$PGPASSFILE" "$G1_ACTUAL_OPERATOR_UID" "$G1_EXPECTED_SECRET_CUSTODIAN_GID" "$G1_EXPECTED_SECRET_CUSTODIAN_UID"
  G1_ACTUAL_MOUNT_RECORD="$(findmnt --noheadings --raw --mountpoint "$G1_SECRET_MOUNT_DIRECTORY" --output TARGET,SOURCE,FSTYPE,MAJ:MIN,OPTIONS)"
  test "$(printf '%s\n' "$G1_ACTUAL_MOUNT_RECORD" | wc -l | tr -d '[:space:]')" = "1"
  G1_ACTUAL_MOUNT_OPTIONS="${G1_ACTUAL_MOUNT_RECORD##* }"
  case ",${G1_ACTUAL_MOUNT_OPTIONS}," in *,ro,*) ;; *) return 1 ;; esac
  G1_ACTUAL_MOUNT_FINGERPRINT_SHA256="$(printf '%s\n' "$G1_ACTUAL_MOUNT_RECORD" | shasum -a 256 | awk '{print $1}')"
  unset G1_ACTUAL_MOUNT_RECORD G1_ACTUAL_MOUNT_OPTIONS
  test "$G1_ACTUAL_MOUNT_FINGERPRINT_SHA256" = "$G1_EXPECTED_MOUNT_FINGERPRINT_SHA256"
  G1_ACTUAL_SECRET_PARENT_CUSTODY="$(g1_custody_fingerprint "$G1_SECRET_MOUNT_PARENT")"
  G1_ACTUAL_SECRET_MOUNT_CUSTODY="$(g1_custody_fingerprint "$G1_SECRET_MOUNT_DIRECTORY")"
  G1_ACTUAL_SERVICE_FILE_CUSTODY="$(g1_custody_fingerprint "$PGSERVICEFILE")"
  G1_ACTUAL_PASSWORD_FILE_CUSTODY="$(g1_custody_fingerprint "$PGPASSFILE")"
  test "$G1_ACTUAL_SECRET_PARENT_CUSTODY" = "$G1_EXPECTED_SECRET_PARENT_CUSTODY"
  test "$G1_ACTUAL_SECRET_MOUNT_CUSTODY" = "$G1_EXPECTED_SECRET_MOUNT_CUSTODY"
  test "$G1_ACTUAL_SERVICE_FILE_CUSTODY" = "$G1_EXPECTED_SERVICE_FILE_CUSTODY"
  test "$G1_ACTUAL_PASSWORD_FILE_CUSTODY" = "$G1_EXPECTED_PASSWORD_FILE_CUSTODY"
  printf 'execution_identity_%s=uid:%s;gid:%s;groups:%s;CapEff:%s\n' "$G1_CUSTODY_PHASE" "$G1_ACTUAL_OPERATOR_UID" "$G1_ACTUAL_OPERATOR_GID" "$G1_ACTUAL_OPERATOR_GROUPS" "$G1_ACTUAL_OPERATOR_CAP_EFF_HEX"
  printf 'secret_mount_fingerprint_%s=%s\n' "$G1_CUSTODY_PHASE" "$G1_ACTUAL_MOUNT_FINGERPRINT_SHA256"
  printf 'libpq_custody_%s=%s;%s;%s;%s\n' "$G1_CUSTODY_PHASE" "$G1_ACTUAL_SECRET_PARENT_CUSTODY" "$G1_ACTUAL_SECRET_MOUNT_CUSTODY" "$G1_ACTUAL_SERVICE_FILE_CUSTODY" "$G1_ACTUAL_PASSWORD_FILE_CUSTODY"
}

test -f "$G1_AGE_RECIPIENTS_FILE"
test -f "$G1_ACCEPTANCE_SQL"
test -d "$G1_BACKUP_DESTINATION"
test -n "$G1_EXPORTED_SNAPSHOT"
test -n "$G1_BACKUP_ATTEMPT_ID"
test -n "$G1_TARGET_BINDING_HMAC_KEY_ID"
: "${G1_TARGET_BINDING_HMAC_KEY:?dedicated target-binding key was not injected}"
G1_ACTUAL_TARGET_BINDING_KEY_COMMITMENT_SHA256="$(printf '%s' "$G1_TARGET_BINDING_HMAC_KEY" | shasum -a 256 | awk '{print $1}')"
test "$G1_ACTUAL_TARGET_BINDING_KEY_COMMITMENT_SHA256" = "$G1_EXPECTED_TARGET_BINDING_KEY_COMMITMENT_SHA256"
test "$(git hash-object "$G1_ACCEPTANCE_SQL")" = "fada760479fe0a43972cc5bd5b40124312898125"
test "$(shasum -a 256 "$G1_ACCEPTANCE_SQL" | awk '{print $1}')" = "832cfa0a145c5d92f32114675d1249300899e554497b65e2d275d7877639b3ba"
test "$(sed -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' "$G1_AGE_RECIPIENTS_FILE" | wc -l | tr -d '[:space:]')" -gt 0
test "$(shasum -a 256 "$G1_AGE_RECIPIENTS_FILE" | awk '{print $1}')" = "$G1_EXPECTED_RECIPIENTS_FILE_SHA256"
test "$(sed -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' "$G1_AGE_RECIPIENTS_FILE" | LC_ALL=C sort -u | shasum -a 256 | awk '{print $1}')" = "$G1_EXPECTED_RECIPIENT_SET_SHA256"
test ! -e "$G1_BACKUP_FINAL"
test ! -e "$G1_BACKUP_PARTIAL"
test ! -e "$G1_SOURCE_RESULT"
pg_dump --version
age --version
php --version

case "$G1_EXPORTED_SNAPSHOT" in *[!0-9A-Fa-f-]*) exit 1 ;; esac
g1_assert_libpq_custody pre_q
G1_MOUNT_FINGERPRINT_PRE_Q="$G1_ACTUAL_MOUNT_FINGERPRINT_SHA256"
G1_SECRET_PARENT_CUSTODY_PRE_Q="$G1_ACTUAL_SECRET_PARENT_CUSTODY"
G1_SECRET_MOUNT_CUSTODY_PRE_Q="$G1_ACTUAL_SECRET_MOUNT_CUSTODY"
G1_SERVICE_FILE_CUSTODY_PRE_Q="$G1_ACTUAL_SERVICE_FILE_CUSTODY"
G1_PASSWORD_FILE_CUSTODY_PRE_Q="$G1_ACTUAL_PASSWORD_FILE_CUSTODY"
G1_SERVICE_CONFIG_SHA256_PRE_Q="$(shasum -a 256 "$PGSERVICEFILE" | awk '{print $1}')"
test "$G1_SERVICE_CONFIG_SHA256_PRE_Q" = "$G1_EXPECTED_SERVICE_CONFIG_SHA256"
printf 'service_config_sha256_pre_q=%s\n' "$G1_SERVICE_CONFIG_SHA256_PRE_Q"
# Query session Q: import exporter session E's still-open snapshot. Its first
# value-minimized row is consumed only in memory to bind this exact connection.
{
  printf '%s\n' 'BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;'
  printf "SET TRANSACTION SNAPSHOT '%s';\n" "$G1_EXPORTED_SNAPSHOT"
  printf '%s\n' '\pset format unaligned' '\pset tuples_only on'
  printf '%s\n' "SELECT json_build_array(current_database(), current_user, coalesce(inet_server_addr()::text, '<local>'), inet_server_port(), current_setting('server_version_num'))::text;"
  printf '%s\n' '\pset format csv' '\pset tuples_only off'
  sed -n '1,$p' "$G1_ACCEPTANCE_SQL"
  printf '%s\n' 'COMMIT;'
} | LC_ALL=C psql --dbname="service=${G1_SOURCE_SERVICE}" --no-psqlrc \
      --set=ON_ERROR_STOP=1 --quiet --csv \
  | {
      IFS= read -r G1_RAW_TARGET_BINDING
      test -n "$G1_RAW_TARGET_BINDING"
      G1_ACTUAL_TARGET_BINDING_HMAC_SHA256="$(
        printf '%s' "$G1_RAW_TARGET_BINDING" \
          | php -r '$key = getenv("G1_TARGET_BINDING_HMAC_KEY"); if ($key === false || $key === "") { exit(2); } echo hash_hmac("sha256", stream_get_contents(STDIN), $key);'
      )"
      unset G1_RAW_TARGET_BINDING G1_TARGET_BINDING_HMAC_KEY
      test "$G1_ACTUAL_TARGET_BINDING_HMAC_SHA256" = "$G1_EXPECTED_TARGET_BINDING_HMAC_SHA256"
      printf 'target_binding_hmac_sha256=%s\n' "$G1_ACTUAL_TARGET_BINDING_HMAC_SHA256"
      cat > "$G1_SOURCE_RESULT"
    }
unset G1_TARGET_BINDING_HMAC_KEY
test -s "$G1_SOURCE_RESULT"
shasum -a 256 "$G1_SOURCE_RESULT"

g1_assert_libpq_custody pre_dump
test "$G1_ACTUAL_MOUNT_FINGERPRINT_SHA256" = "$G1_MOUNT_FINGERPRINT_PRE_Q"
test "$G1_ACTUAL_SECRET_PARENT_CUSTODY" = "$G1_SECRET_PARENT_CUSTODY_PRE_Q"
test "$G1_ACTUAL_SECRET_MOUNT_CUSTODY" = "$G1_SECRET_MOUNT_CUSTODY_PRE_Q"
test "$G1_ACTUAL_SERVICE_FILE_CUSTODY" = "$G1_SERVICE_FILE_CUSTODY_PRE_Q"
test "$G1_ACTUAL_PASSWORD_FILE_CUSTODY" = "$G1_PASSWORD_FILE_CUSTODY_PRE_Q"
G1_SERVICE_CONFIG_SHA256_PRE_DUMP="$(shasum -a 256 "$PGSERVICEFILE" | awk '{print $1}')"
test "$G1_SERVICE_CONFIG_SHA256_PRE_DUMP" = "$G1_EXPECTED_SERVICE_CONFIG_SHA256"
test "$G1_SERVICE_CONFIG_SHA256_PRE_DUMP" = "$G1_SERVICE_CONFIG_SHA256_PRE_Q"
printf 'service_config_sha256_pre_dump=%s\n' "$G1_SERVICE_CONFIG_SHA256_PRE_DUMP"
pg_dump \
  --dbname="service=${G1_SOURCE_SERVICE}" \
  --format=custom \
  --compress=gzip:9 \
  --schema=laravel \
  --no-owner \
  --no-acl \
  --snapshot="$G1_EXPORTED_SNAPSHOT" \
  --file=- \
| age --encrypt \
    --recipients-file "$G1_AGE_RECIPIENTS_FILE" \
    --output "$G1_BACKUP_PARTIAL"

test -s "$G1_BACKUP_PARTIAL"
test ! -e "$G1_BACKUP_FINAL"
ln "$G1_BACKUP_PARTIAL" "$G1_BACKUP_FINAL"
test "$G1_BACKUP_PARTIAL" -ef "$G1_BACKUP_FINAL"
test -s "$G1_BACKUP_FINAL"
unlink "$G1_BACKUP_PARTIAL"
test ! -e "$G1_BACKUP_PARTIAL"
g1_assert_libpq_custody post_publication
test "$G1_ACTUAL_MOUNT_FINGERPRINT_SHA256" = "$G1_MOUNT_FINGERPRINT_PRE_Q"
test "$G1_ACTUAL_SECRET_PARENT_CUSTODY" = "$G1_SECRET_PARENT_CUSTODY_PRE_Q"
test "$G1_ACTUAL_SECRET_MOUNT_CUSTODY" = "$G1_SECRET_MOUNT_CUSTODY_PRE_Q"
test "$G1_ACTUAL_SERVICE_FILE_CUSTODY" = "$G1_SERVICE_FILE_CUSTODY_PRE_Q"
test "$G1_ACTUAL_PASSWORD_FILE_CUSTODY" = "$G1_PASSWORD_FILE_CUSTODY_PRE_Q"
G1_SERVICE_CONFIG_SHA256_POST_PUBLICATION="$(shasum -a 256 "$PGSERVICEFILE" | awk '{print $1}')"
test "$G1_SERVICE_CONFIG_SHA256_POST_PUBLICATION" = "$G1_EXPECTED_SERVICE_CONFIG_SHA256"
test "$G1_SERVICE_CONFIG_SHA256_POST_PUBLICATION" = "$G1_SERVICE_CONFIG_SHA256_PRE_Q"
printf 'service_config_sha256_post_publication=%s\n' "$G1_SERVICE_CONFIG_SHA256_POST_PUBLICATION"
shasum -a 256 "$G1_BACKUP_FINAL"
wc -c "$G1_BACKUP_FINAL"
```

Because `pipefail` is active, publication is reached only after both `pg_dump` and `age` succeed and the encrypted partial is nonempty. Partial and final are in the same approved directory. `ln partial final` is the atomic no-replace publication primitive: it fails when final already exists, and the `-ef` check proves both names reference the same completed bytes before only the known partial name is unlinked. The destination must support atomic same-filesystem hard-link creation with `EEXIST` no-clobber behavior; otherwise abort and obtain a separately reviewed publication method. Ordinary `mv` or copy-to-final is prohibited. If the pipeline, link, verification or unlink is ambiguous or fails, quarantine the clearly labelled partial/final state for review; do not overwrite, manually rename or automatically retry it. A new authorized attempt requires a new attempt ID and separate evidence.

The custody assertion and exact authorized service-file hash must pass immediately before Q, immediately before `pg_dump`, and after successful no-replace publication. Each checkpoint requires the same authorized `findmnt` target/source/fstype/major:minor/options fingerprint with an exact `ro` option; canonical non-symlink paths; a trusted non-writable custodian-owned parent; the same parent/mount/service/password owner, group, restrictive mode, device and inode; and the same authorized non-root UID/GID/groups with zero effective Linux capabilities. The operator must differ from the secret custodian and from the parent, mountpoint and service-file owner; the exact operator-owned `0400` passfile exception above is checked separately and gains no write ability on the read-only mount. All three service hashes must also be identical. A mismatch at any checkpoint aborts the attempt. If final publication already occurred, the final archive remains quarantined and must not receive a PASS receipt or be selected for restore.

The canonical target row is derived inside the same imported-snapshot Q transaction and over the same `G1_SOURCE_SERVICE` connection and immutable service-file bytes used by `pg_dump`. It is HMACed immediately client-side with PHP so the dedicated key is never sent to PostgreSQL and the raw server address, database and role tuple is neither printed nor written. The HMAC comparison must pass before `pg_dump` starts. Record only the non-secret service name/config hash, custody fingerprints, key ID/commitment, target HMAC and snapshot reference. Raw host, DSN, database, role, password and service-file contents are prohibited from the receipt.

Commit and close exporter session E only after query session Q and `pg_dump` plus final publication complete unambiguously. Do not create an unencrypted intermediate dump. Bind receipt A to source snapshot/result A and archive A; repeat with a new exporter snapshot, result and archive for pair B. Record only tool versions, opaque snapshot references/hashes, acceptance-SQL/result hashes, backup and attempt identifiers, encrypted final-file byte count/SHA-256, destination reference, approved public recipient hashes/fingerprints, target-binding metadata, times, exit status and operator/reviewer—not connection material.

## 6. Secret-safe isolated PostgreSQL 17 restore template

Each receipt uses a newly provisioned empty database with no `laravel` schema. Restore A proves the pre-drain backup. Restore B independently proves the final post-drain backup. They must not reuse the same database or treat one restore as evidence for both backups. These isolated restore identities are recorded separately and are not expected to equal the Production source target HMAC.

```bash
set -euo pipefail
umask 077
export PGSERVICEFILE="<approved absolute path outside repository>/pg_service.conf"
export PGPASSFILE="<approved absolute path outside repository>/pgpass"
export G1_RESTORE_SERVICE="<approved non-secret libpq service name for one empty PostgreSQL 17 restore target>"
export G1_BACKUP_FILE="<approved absolute path to the selected encrypted .dump.age file>"
export G1_EXPECTED_BACKUP_ID="<receipt A or B backup ID>"
export G1_EXPECTED_BACKUP_SHA256="<receipt A or B encrypted archive SHA-256>"
export G1_EXPECTED_BACKUP_BYTES="<receipt A or B exact encrypted archive byte count>"
export G1_AGE_RECIPIENTS_FILE="<approved absolute path to public recipients file>"
export G1_EXPECTED_RECIPIENTS_FILE_SHA256="<authorized exact public recipients-file SHA-256>"
export G1_EXPECTED_RECIPIENT_SET_SHA256="<authorized normalized public-recipient-set SHA-256>"
export G1_EXPECTED_RESTORE_PUBLIC_RECIPIENT_SHA256="<authorized public fingerprint for this restore custodian>"
export G1_AGE_IDENTITY_FILE="<approved absolute path to private age identity outside repository>"
export G1_ACCEPTANCE_SQL="<authorized carrier checkout>/docs/operations/BG_02C4B_G1_SOURCE_RESTORE_ACCEPTANCE_2026-08-25.sql"
export G1_EXPECTED_SOURCE_RESULT_SHA256="<source A or source B result SHA-256 from the same receipt>"
export G1_RESTORE_RESULT="<approved value-minimized evidence path outside repository>/restore-${G1_EXPECTED_BACKUP_ID}.csv"

test -f "$PGSERVICEFILE"
test -f "$PGPASSFILE"
test -f "$G1_BACKUP_FILE"
test -f "$G1_AGE_RECIPIENTS_FILE"
test -f "$G1_AGE_IDENTITY_FILE"
test -f "$G1_ACCEPTANCE_SQL"
test "$(basename "$G1_BACKUP_FILE")" = "$G1_EXPECTED_BACKUP_ID.dump.age"
test "$(shasum -a 256 "$G1_BACKUP_FILE" | awk '{print $1}')" = "$G1_EXPECTED_BACKUP_SHA256"
test "$(wc -c < "$G1_BACKUP_FILE" | tr -d '[:space:]')" = "$G1_EXPECTED_BACKUP_BYTES"
test "$(git hash-object "$G1_ACCEPTANCE_SQL")" = "fada760479fe0a43972cc5bd5b40124312898125"
test "$(shasum -a 256 "$G1_ACCEPTANCE_SQL" | awk '{print $1}')" = "832cfa0a145c5d92f32114675d1249300899e554497b65e2d275d7877639b3ba"
test "$(sed -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' "$G1_AGE_RECIPIENTS_FILE" | wc -l | tr -d '[:space:]')" -gt 0
test "$(shasum -a 256 "$G1_AGE_RECIPIENTS_FILE" | awk '{print $1}')" = "$G1_EXPECTED_RECIPIENTS_FILE_SHA256"
test "$(sed -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' "$G1_AGE_RECIPIENTS_FILE" | LC_ALL=C sort -u | shasum -a 256 | awk '{print $1}')" = "$G1_EXPECTED_RECIPIENT_SET_SHA256"
G1_DERIVED_PUBLIC_RECIPIENT="$(age-keygen -y "$G1_AGE_IDENTITY_FILE")"
test -n "$G1_DERIVED_PUBLIC_RECIPIENT"
G1_DERIVED_PUBLIC_RECIPIENT_SHA256="$(printf '%s\n' "$G1_DERIVED_PUBLIC_RECIPIENT" | shasum -a 256 | awk '{print $1}')"
test "$G1_DERIVED_PUBLIC_RECIPIENT_SHA256" = "$G1_EXPECTED_RESTORE_PUBLIC_RECIPIENT_SHA256"
sed -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' "$G1_AGE_RECIPIENTS_FILE" \
  | LC_ALL=C sort -u \
  | grep -Fqx -- "$G1_DERIVED_PUBLIC_RECIPIENT"
printf '%s\n' "$G1_DERIVED_PUBLIC_RECIPIENT_SHA256"
chmod 600 "$PGSERVICEFILE" "$PGPASSFILE" "$G1_AGE_IDENTITY_FILE"
pg_restore --version
psql --dbname="service=${G1_RESTORE_SERVICE}" --no-psqlrc --set=ON_ERROR_STOP=1 \
  --command="SELECT current_setting('server_version'), current_database(), EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'laravel') AS laravel_schema_exists;"

age --decrypt --identity "$G1_AGE_IDENTITY_FILE" "$G1_BACKUP_FILE" \
| pg_restore \
    --dbname="service=${G1_RESTORE_SERVICE}" \
    --exit-on-error \
    --single-transaction \
    --no-owner \
    --no-privileges

test ! -e "$G1_RESTORE_RESULT"
LC_ALL=C psql --dbname="service=${G1_RESTORE_SERVICE}" --no-psqlrc \
  --set=ON_ERROR_STOP=1 --quiet --csv --file="$G1_ACCEPTANCE_SQL" > "$G1_RESTORE_RESULT"
test -s "$G1_RESTORE_RESULT"
test "$(shasum -a 256 "$G1_RESTORE_RESULT" | awk '{print $1}')" = "$G1_EXPECTED_SOURCE_RESULT_SHA256"
```

The archive basename, SHA-256 and exact byte count must match the selected receipt before decryption. The public recipients file must match both authorized hashes, and the public recipient derived from the private restore identity must match its authorized public fingerprint and be a member of that frozen set. Record only its public recipient string/hash, never private identity material. The pre-restore query must report PostgreSQL 17 and `laravel_schema_exists=false`. Otherwise stop; do not decrypt, add `--clean` or overwrite an existing restore target. Restore A compares only with source result A; restore B compares only with source result B.

## 7. Value-minimized source and restore acceptance queries

Record the following environment-identity query separately on the freshly inventoried Production source, isolated restore A and isolated restore B. It is deliberately excluded from the exact result-hash comparison because each isolated restore must have a different database identity and may use a separately reviewed PostgreSQL 17 patch release.

```sql
SELECT current_setting('server_version') AS server_version,
       current_database() AS database_name,
       EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'laravel') AS laravel_schema_exists;
```

Run [`BG_02C4B_G1_SOURCE_RESTORE_ACCEPTANCE_2026-08-25.sql`](BG_02C4B_G1_SOURCE_RESTORE_ACCEPTANCE_2026-08-25.sql), Git blob `fada760479fe0a43972cc5bd5b40124312898125`, SHA-256 `832cfa0a145c5d92f32114675d1249300899e554497b65e2d275d7877639b3ba`, byte-for-byte unchanged. For source A and source B, query session Q must import the same still-open snapshot that the corresponding `pg_dump --snapshot` uses. Run the same file on restore A and restore B with PostgreSQL 17 `psql --no-psqlrc --set=ON_ERROR_STOP=1 --quiet --csv` and hash complete stdout.

The hashed output contains only structural metadata, counts and migration identifiers. It does not select environment-specific database names, credentials, names, email addresses, audit payloads, reasons, IP values, user agents or patient/domain values.

For each refreshed pre-migration source snapshot, every sequence-contract row must report `table_kind='r'`, `id_type` in `integer`/`bigint`, `id_not_null=true`, `sequence_exists=true`, `sequence_kind='S'`, `owners_match=true`, `sequence_owned_by_id=true`, `default_depends_on_sequence=true` and `qualified_default=true`. Within each pair, source and restore must have the same exact base-table count, full ordered migration ledger, audit/user counts, foreign-key result and complete result hash. The encrypted archive identity/hash/size must match that pair's receipt. Source A and source B are not required to match because authorized pre-drain writes may occur between their snapshots. Any false/null contract flag or A-to-restore-A or B-to-restore-B difference is an abort, not a tolerance.

Record the deterministic results without exposing the underlying connection material:

| Result artifact | SQL text SHA-256 | Result SHA-256 | Reviewer |
| --- | --- | --- | --- |
| Source snapshot A | `832cfa0a145c5d92f32114675d1249300899e554497b65e2d275d7877639b3ba` | `PENDING — source result A` | `PENDING` |
| Isolated restore A | Same frozen SQL | `PENDING — must exactly equal source result A` | `PENDING` |
| Source snapshot B | Same frozen SQL | `PENDING — source result B; may differ from A` | `PENDING` |
| Independent isolated restore B | Same frozen SQL | `PENDING — must exactly equal source result B` | `PENDING` |

After the single authorized migration attempt, additionally record:

```sql
SELECT migration, batch
FROM laravel.migrations
WHERE migration IN (
  '2026_08_21_000100_create_rebuild_foundation_tables',
  '2026_08_22_000800_add_patient_marital_status',
  '2026_08_22_001000_qualify_laravel_serial_sequence_defaults',
  '2026_08_25_000100_create_break_glass_record_tables',
  '2026_08_25_000200_create_security_ledger_tables',
  '2026_08_25_000300_expand_audit_actor_attribution'
)
ORDER BY id;

SELECT count(*) AS expected_new_tables
FROM information_schema.tables
WHERE table_schema = 'laravel'
  AND table_name IN (
    'break_glass_requests', 'break_glass_decisions', 'break_glass_activations',
    'break_glass_revocations', 'break_glass_session_bindings',
    'break_glass_subject_leases', 'security_ledger_entries', 'security_ledger_outboxes'
  );

SELECT count(*) AS attribution_columns
FROM information_schema.columns
WHERE table_schema = 'laravel'
  AND table_name = 'audit_events'
  AND column_name IN ('actor_type', 'actor_reference');

SELECT conname, confdeltype, convalidated
FROM pg_constraint
WHERE conrelid = 'laravel.audit_events'::regclass
  AND conname = 'audit_events_actor_user_fk';

SELECT count(*) AS protected_security_triggers
FROM pg_trigger trigger
JOIN pg_class table_class ON table_class.oid = trigger.tgrelid
JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
WHERE namespace.nspname = 'laravel'
  AND NOT trigger.tgisinternal
  AND (
    trigger.tgname LIKE '%_no_update'
    OR trigger.tgname LIKE '%_no_delete'
    OR trigger.tgname IN (
      'security_ledger_outboxes_validate_insert',
      'security_ledger_outboxes_protect_update',
      'security_ledger_outboxes_no_delete'
    )
  );
```

Expected structural totals after migration are six named migration rows in one new batch, eight named new tables, two attribution columns, one validated restrictive actor foreign key (`confdeltype='r'`) and 15 protected-security triggers. Also re-run the 13-sequence query, confirm ordinary audit counts were preserved, and retain the value-free BG-02c3 preflight summary.

## 8. Independent restore receipts

### Receipt A — pre-drain backup

| Field | Recorded value |
| --- | --- |
| Backup A ID / attempt ID / source cutoff / final basename | `PENDING` |
| Source snapshot A reference/hash | `PENDING` |
| Production source service name / authorized service-file SHA-256 | `PENDING — non-secret name/hash only` |
| Authorized mount fingerprint / custodian UID:GID / trusted-parent custody | `PENDING — non-secret hashes/IDs only` |
| Platform non-bypass / secret-manager provisioning evidence reference | `PENDING` |
| Pre-Q operator UID:GID:groups / `CapEff` / non-owner verdict | `PENDING — non-root; zero capabilities required` |
| Pre-Q kernel mount fingerprint / exact `ro` verdict | `PENDING — must equal authorization` |
| Pre-Q parent / mount / PGSERVICEFILE / PGPASSFILE custody | `PENDING — owner/group/mode/device/inode only` |
| Pre-Q ownership/mode contract | `PENDING — custodian 0550/0550/0440; operator-owned PGPASSFILE 0400 exception` |
| Pre-dump execution / mount-`ro` / custody / exact service-file SHA-256 | `PENDING — must equal pre-Q and authorization` |
| Post-publication execution / mount-`ro` / custody / exact service-file SHA-256 | `PENDING — must equal pre-Q and authorization` |
| Target-binding key ID / commitment / expiry | `PENDING — non-secret metadata only` |
| Approved versus actual Production target HMAC for A | `PENDING — exact equality required before dump` |
| Exporter transaction start/end | `PENDING` |
| Exporter transaction unambiguous commit/close status | `PENDING` |
| Acceptance SQL Git blob / SHA-256 verified | `PENDING — must equal frozen values in section 1` |
| Source result A SHA-256 | `PENDING` |
| Archive A expected and actual SHA-256 / exact byte count | `PENDING — both comparisons must PASS before decrypt` |
| pg_dump / age versions | `PENDING` |
| Approved recipients-file SHA-256 / normalized-set SHA-256 | `PENDING` |
| Restore identity-derived public recipient/hash / approved-fingerprint equality / membership result | `PENDING — public value only; must match approval and approved set` |
| Empty restore target identity / PostgreSQL 17 version | `PENDING` |
| pg_restore start/end/exit status | `PENDING` |
| Migration, audit, user and sequence acceptance results | `PENDING` |
| Restore A result SHA-256 / exact equality to source result A | `PENDING` |
| Pair A binding: Production target HMAC + snapshot A + source result A + backup A ID/hash/size + restore A | `PENDING` |
| Operator / independent reviewer / timestamp | `PENDING` |
| Verdict | `PENDING — must be PASS before maintenance/promotion` |

### Receipt B — final post-drain backup

| Field | Recorded value |
| --- | --- |
| Writer-drain proof and backup source cutoff | `PENDING` |
| Backup B ID / attempt ID / final basename | `PENDING` |
| Source snapshot B reference/hash | `PENDING` |
| Production source service name / authorized service-file SHA-256 | `PENDING — non-secret name/hash only` |
| Authorized mount fingerprint / custodian UID:GID / trusted-parent custody | `PENDING — non-secret hashes/IDs only` |
| Platform non-bypass / secret-manager provisioning evidence reference | `PENDING` |
| Pre-Q operator UID:GID:groups / `CapEff` / non-owner verdict | `PENDING — non-root; zero capabilities required` |
| Pre-Q kernel mount fingerprint / exact `ro` verdict | `PENDING — must equal authorization` |
| Pre-Q parent / mount / PGSERVICEFILE / PGPASSFILE custody | `PENDING — owner/group/mode/device/inode only` |
| Pre-Q ownership/mode contract | `PENDING — custodian 0550/0550/0440; operator-owned PGPASSFILE 0400 exception` |
| Pre-dump execution / mount-`ro` / custody / exact service-file SHA-256 | `PENDING — must equal pre-Q and authorization` |
| Post-publication execution / mount-`ro` / custody / exact service-file SHA-256 | `PENDING — must equal pre-Q and authorization` |
| Target-binding key ID / commitment / expiry | `PENDING — non-secret metadata only` |
| Approved versus actual Production target HMAC for B | `PENDING — exact equality required before dump` |
| Exporter transaction start/end | `PENDING` |
| Exporter transaction unambiguous commit/close status | `PENDING` |
| Acceptance SQL Git blob / SHA-256 verified | `PENDING — must equal frozen values in section 1` |
| Source result B SHA-256 | `PENDING — may differ from source result A` |
| Archive B expected and actual SHA-256 / exact byte count | `PENDING — both comparisons must PASS before decrypt` |
| pg_dump / age versions | `PENDING` |
| Approved recipients-file SHA-256 / normalized-set SHA-256 | `PENDING` |
| Restore identity-derived public recipient/hash / approved-fingerprint equality / membership result | `PENDING — public value only; must match approval and approved set` |
| Independent empty restore target identity / PostgreSQL 17 version | `PENDING` |
| pg_restore start/end/exit status | `PENDING` |
| Migration, audit, user and sequence acceptance results | `PENDING` |
| Restore B result SHA-256 / exact equality to source result B | `PENDING` |
| Pair B binding: Production target HMAC + snapshot B + source result B + backup B ID/hash/size + restore B | `PENDING` |
| Operator / independent reviewer / timestamp | `PENDING` |
| Verdict | `PENDING — must be PASS before migrate --force` |

## 9. Controlled execution ledger

| Order | Gate | Status / evidence |
| ---: | --- | --- |
| 1 | Frozen payload/procedure/blob identities verified in separate clean checkouts | `PENDING` |
| 2 | Historical Preview boot acknowledged; action-time deployment metadata freshly matches | `PENDING` |
| 3 | Production/environment/database inventory refreshed with no drift | `PENDING` |
| 4 | Authority, operator, reviewer, temporary access and recovery roles complete | `PENDING` |
| 5 | Receipt A independently PASS | `PENDING` |
| 6 | Shared maintenance marker active; exact candidate promoted drained; `/up=200`, `/login=503` | `PENDING` |
| 7 | More than 60 seconds plus approved margin elapsed; no old writer remains | `PENDING` |
| 8 | Receipt B independently PASS | `PENDING` |
| 9 | `migrate:status` shows the exact six-name pending set | `PENDING` |
| 10 | `migrate --force` attempted once with unambiguous completion | `PENDING` |
| 11 | Schema, ledger, triggers, attribution, sequence and audit-preservation checks PASS | `PENDING` |
| 12 | Value-free `audit:attribution:preflight --json` retained; expected blocker acknowledged | `PENDING` |
| 13 | Deployment full SHA equals payload; logs reviewed; temporary direct access revoked | `PENDING` |
| 14 | Maintenance removal authorized; public/permitted/denied synthetic checks PASS | `PENDING` |
| 15 | Final go/no-go and forward-recovery evidence reconciled | `PENDING` |

## 10. Ambiguity, rollback and forward recovery

- A connection loss, timeout or missing exit status never authorizes a retry. Keep maintenance active and inspect the migration ledger, schema, deployment identity, shared marker and database activity first.
- Before migration commits, preserve the Production database and use only the recovery path authorized in section 2. Do not manually repair the Laravel ledger.
- After schema expansion or any new ordinary audit write, do not reopen the old `42ab482` writer as normal rollback. It can create rows without the new actor snapshot.
- Prefer an exact reviewed forward corrective release. Post-drain database recovery must use receipt-proven backup B when it is available. Backup A predates the database-backed maintenance marker and is not a safe routable recovery by itself. Selecting A requires a documented reason B cannot be used, an independent external write block before any routing, installation and verification of the shared maintenance marker in the restored target, and proof that no writer can reach it. Database restore remains subject to the named recovery authority and an explicit retained-write impact decision.
- Any failed gate reactivates or retains the shared marker. Only the named maintenance authority may approve reopening.
- The blocking `lab_access.enabled` row remains immutable. Do not generate/apply an attribution manifest or contract attribution columns during this cutover.

| Recovery decision field | Recorded value |
| --- | --- |
| Failure/ambiguity timestamp and last unambiguous gate | `PENDING` |
| Maintenance-marker state | `PENDING` |
| Migration-ledger/schema inspection result | `PENDING` |
| Selected forward correction or restore decision / receipt A-or-B identity | `PENDING` |
| Receipt B used, or documented reason B is unavailable | `PENDING` |
| If A: independent external write block active before routing | `PENDING / NOT APPLICABLE` |
| If A: shared maintenance marker installed and independently verified on restored target | `PENDING / NOT APPLICABLE` |
| If A: no-writer reachability proof before and after routing change | `PENDING / NOT APPLICABLE` |
| Recovery routing authority / exact route-change receipt | `PENDING` |
| Retained-write impact assessment | `PENDING` |
| Decision authority / reviewer | `PENDING` |
| Recovery evidence and final disposition | `PENDING` |

## 11. Retention and secure cleanup evidence

Do not delete a backup, restore target or temporary access material merely because cutover completed. Apply the approved retention and cleanup decision, and record evidence without secret paths or values.

| Evidence | Recorded value |
| --- | --- |
| Backup custodian and retention expiry | `PENDING` |
| Encrypted backup integrity recheck | `PENDING` |
| Temporary database/Vercel access revoked and independently verified | `PENDING` |
| Temporary pgpass/service material cleanup reference | `PENDING` |
| Per-attempt immutable secret mount revocation/unmount receipt | `PENDING` |
| Private encryption identity handling/cleanup reference | `PENDING` |
| Target-binding HMAC key expiry/destruction reference | `PENDING` |
| Restore target A disposition and authority | `PENDING` |
| Restore target B disposition and authority | `PENDING` |
| Final secure deletion or archive receipt, when retention permits | `PENDING` |
| Evidence-pack classification/location and reviewer | `PENDING` |

## 12. Decision

**Current decision: `NO-GO / UNAUTHORIZED`.** This template records no current Production state, approval, backup, restore, migration, deployment or G1 pass. It becomes an execution record only when every applicable field is completed from action-time evidence and independently reviewed.

## References

- [Hosted attribution preflight](BG_02C4B_HOSTED_ATTRIBUTION_PREFLIGHT_2026-08-25.md)
- [Hash-frozen source/restore acceptance SQL](BG_02C4B_G1_SOURCE_RESTORE_ACCEPTANCE_2026-08-25.sql)
- [Exact-SHA Preview boot evidence](BG_02C4B3_EXACT_SHA_PREVIEW_BOOT_EVIDENCE_2026-08-25.md)
- [Vercel + Supabase synthetic demo runbook](VERCEL_SUPABASE_DEMO.md)
- [BG-02c3 attribution preflight](BG_02C3_AUDIT_ATTRIBUTION_PREFLIGHT_2026-08-25.md)
- [Release evidence index](../new-simrs-rebuild/phase-0/RELEASE_EVIDENCE_INDEX.md)
