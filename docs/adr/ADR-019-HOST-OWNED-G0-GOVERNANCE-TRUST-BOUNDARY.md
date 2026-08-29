# ADR-019: Root-only offline G0 governance trust boundary

Status: **PROPOSED / NOT APPROVED / NOT AUTHORITATIVE / NO EFFECT**

Date: 2026-08-29

## 1. Bound predecessors, scope, and present effect

This append-only proposal binds these exact predecessors:

- `docs/adr/ADR-018-PROPORTIONAL-G0-GOVERNANCE-PROFILE.md` SHA-256 `cd7834e7f5b0be99acee3f1b46f8af9fc81b77418541fddf7276fadc26edf962`.
- `docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ADOPTION_DECISION.json` SHA-256 `139bf2f952ab5c47cd34a43f80d93224e48386c57f180a799d8f206a9049c0f3`.
- `docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_EXTERNAL_TRUST_PROVISIONING_PROPOSAL_2026-08-29.md` SHA-256 `a5397de2333c9e107cc78175b09031450122bcada14c17afc5e30d48eccd6eea`.

The Gate-A adoption decision exists and accepts the governance-v2 proposal plus ADR-018 only for local governance-v2 implementation. It does not approve the later external-trust proposal, this ADR-019, host provisioning, request import, or any canonical consumer operation. No immutable approval artifact for the external-trust proposal or this ADR exists at authoring time. Conversation text, repository authorship, this prose, and its tests confer no new authority. The independent review of the earlier ADR-019 draft BLOCKED that draft; this revision resolves its architecture findings but is not itself an approval or passing review.

After, and only after, the exact approval and independent-review prerequisites in Section 24 exist, this ADR supersedes ADR-018 and the external-trust proposal solely for Gate-B canonical consumer activation, rollback, disable, recover, and governance/ledger active-chain resolution. ADR-018 continues to govern the 268-capability model, deterministic candidate validation, v1 preservation, synthetic-only boundaries, and every other area. The repository selector is validation-only and cannot mutate or authoritatively resolve the canonical deployment checkout.

This ADR does not activate governance v2, provision a host, enroll a key, import a request, approve an operation, select a consumer, authorize a capability or application slice, deploy or migrate anything, permit real patient data or live integration, close G0, establish G3 acceptance, or change any current all-false Gate-B authorization.

## 2. Fixed architecture and invocation boundary

The canonical mutator is one offline native macOS executable invoked directly by root. Its real UID and effective UID SHALL both equal `0`; its file owner/group/mode SHALL be `root:wheel` and `0700`; it SHALL NOT be setuid or setgid. Application runtimes, repository writers, ordinary operators, and service accounts cannot invoke it. There is no XPC service, socket, daemon, sudo environment handoff, caller-supplied descriptor, environment token, repository token, plug-in, or delegated identity path.

`resolve-active` is a root-only governance/ledger operator command. It produces evidence for governance reconciliation and ledger inspection; it is never an application-runtime authorization API. No application request, route, middleware, capability check, or clinical workflow may call it or treat its result as application authorization.

Before opening the state root, request inbox, deployment checkout, Git metadata, replay store, receipt store, current pointer, pin record, or any authority path, the executable reads only kernel process credentials and requires both real and effective UID `0`. Failure emits exactly the constant line `{"artifact_type":"g0_host_unauthorized_v2","schema_version":2,"status":"REJECTED_UNAUTHORIZED","reason_code":"UNAUTHORIZED_CALLER","current_pointer_sha256":null,"current_pin_sha256":null,"request_sha256":null,"reservation_sha256":null,"result_sha256":null}\n` on standard output, emits nothing on standard error, and exits `77`. It opens and writes no file. Mode `0700` normally causes kernel `EACCES` before execution; native acceptance SHALL test both kernel denial and the internal credential guard through a test-linked credential provider that cannot be compiled into the release binary.

## 3. Fixed macOS layout and dedicated deployment checkout

The only supported profile is `macos_local_v2`:

| Role | Exact path |
| --- | --- |
| Executable versions | `/Library/PrivilegedHelperTools/id.ac.esaunggul.simrs-campus-ueu.g0-governance/versions` |
| Bootstrap pin | `/Library/PrivilegedHelperTools/id.ac.esaunggul.simrs-campus-ueu.g0-governance/bootstrap-pin-v2.json` |
| State root | `/Library/Application Support/SIMRSCampusUEU/G0Governance` |
| Immutable pin records | `/Library/Application Support/SIMRSCampusUEU/G0Governance/pins` |
| Pin-directory identity artifact | `/Library/Application Support/SIMRSCampusUEU/G0Governance/pin-record-directory-v2.json` |
| Mutable current pointer | `/Library/Application Support/SIMRSCampusUEU/G0Governance/current-pin-v2.json` |
| Dedicated canonical deployment checkout | `/Library/Application Support/SIMRSCampusUEU/G0Governance/deployment-checkout` |
| Request inbox | `/Library/Application Support/SIMRSCampusUEU/G0Governance/requests/inbox` |
| Import receipts | `/Library/Application Support/SIMRSCampusUEU/G0Governance/requests/import-receipts` |
| Replay store | `/Library/Application Support/SIMRSCampusUEU/G0Governance/replay` |
| Receipt store | `/Library/Application Support/SIMRSCampusUEU/G0Governance/receipts` |
| Terminal receipts | `/Library/Application Support/SIMRSCampusUEU/G0Governance/receipts/terminal` |
| Failure receipts | `/Library/Application Support/SIMRSCampusUEU/G0Governance/receipts/failure` |
| Reconciliation receipts | `/Library/Application Support/SIMRSCampusUEU/G0Governance/receipts/reconciliation` |
| Trust stores | `/Library/Application Support/SIMRSCampusUEU/G0Governance/trust-stores` |
| Policies | `/Library/Application Support/SIMRSCampusUEU/G0Governance/policies` |
| Enrollments | `/Library/Application Support/SIMRSCampusUEU/G0Governance/enrollments` |
| Authorship rosters | `/Library/Application Support/SIMRSCampusUEU/G0Governance/rosters` |
| OpenSSH verifier | `/usr/bin/ssh-keygen` |
| Git executable | `/usr/bin/git` |

The dedicated checkout is a root-owned deployment copy and is never the developer worktree. Protecting its phase-0 authority paths therefore does not alter, freeze, chmod, chown, or add ACLs to any developer checkout. Repository changes reach it only through a separately authorized root deployment/import procedure outside this ADR; this ADR authorizes no such procedure.

The provision-time host identity binding SHALL contain exactly:

`artifact_type`, `schema_version`, `profile`, `status`, `host_id`, `root_uid`, `root_gid`, `wheel_gid`, `repository_writer_uid`, `application_runtime_uid`, `canonical_checkout_path`, `canonical_checkout_device_id`, `canonical_checkout_inode`, `state_root_path`, `state_root_device_id`, `state_root_inode`, `bound_at`, and `bound_by_uid`.

Closed values are `artifact_type: g0_host_identity_binding_v2`, `schema_version: 2`, `profile: macos_local_v2`, `status: provisioned`, root UID/GID `0`, the discovered local `wheel_gid`, the fixed checkout and state-root paths above, and `bound_by_uid: 0`. Repository-writer and application-runtime UIDs are discovered at provisioning, are nonzero, differ from one another, and are not invented in a draft. Until all provision-time values are recorded, pinned, and independently accepted, canonical operations are unavailable.

## 4. Ancestor-safe filesystem protocol

Every external or canonical path is resolved from an already-open trusted ancestor descriptor. The native sequence is exact:

1. Open `/` with `open("/", O_RDONLY|O_DIRECTORY|O_NOFOLLOW|O_CLOEXEC)` and `fstat` it.
2. For each fixed ancestor component, call `openat(parent_fd, component, O_RDONLY|O_DIRECTORY|O_NOFOLLOW|O_CLOEXEC)`, reject `.`/`..`/slash/NUL/empty components, and `fstat` the returned descriptor.
3. Compare every descriptor's device, inode, type, owner, group, mode, flags, link count, and pinned ACL hash before descending; reopen none by absolute path.
4. Open a fixed regular leaf with `openat(parent_fd, leaf, O_RDONLY|O_NOFOLLOW|O_CLOEXEC|O_NONBLOCK)`, then require a regular file, link count one, pinned device/inode/ownership/mode, clear `O_NONBLOCK`, and read by descriptor.
5. Create by `openat` with `O_CREAT|O_EXCL|O_WRONLY|O_NOFOLLOW|O_CLOEXEC`, mode `0600`, then `fchmod`, `fchown`, `fsync`, descriptor readback, and parent `fsync`.
6. Rename only with fd-relative `renameat` between two verified descriptors on the same pinned device; verify source and destination parents again immediately before and after. No cross-device fallback or copy is allowed for an authority rename.
7. Before each security-sensitive syscall, and after every blocking syscall, re-`fstat` retained ancestors and reject device/inode/type/ownership/mode/ACL drift. Retained descriptors are `FD_CLOEXEC`. Git inherits none. One `ssh-keygen` verification inherits only the host-opened read-only signature and allowed-signers descriptors explicitly whitelisted in Section 7; descriptor enumeration before `posix_spawn` and in a test child proves no other descriptor is inherited.

Symlink, hard-link, mount swap, ancestor rename, leaf replacement, directory replacement, case-fold collision, Unicode-normalization collision, and concurrent rename races fail closed. Native tests SHALL race every component and leaf boundary and prove that no syscall escapes the fixed descriptor ancestry.

## 5. Exact macOS ACL and ownership contract

The phase-0 parent contract SHALL contain exactly:

`artifact_type`, `schema_version`, `profile`, `checkout_root`, `checkout_root_device_id`, `checkout_root_inode`, `phase0_parent_path`, `phase0_parent_device_id`, `phase0_parent_inode`, `owner_uid`, `group_gid`, `mode`, `file_flags`, `acl_type`, `acl_serialization`, `acl_sha256`, `aces`, `authority_entries`, `checked_at`, and `expires_at`.

Every `aces` item SHALL contain exactly:

`position`, `ace_type`, `principal_kind`, `principal_name`, `principal_uuid`, `rights`, and `inheritance_flags`.

`acl_type` is `ACL_TYPE_EXTENDED`; `acl_serialization` is `acl_get_fd_np_v2`. The implementation calls `acl_get_fd_np(fd, ACL_TYPE_EXTENDED)`, iterates native ACE index order, converts each qualifier UUID to lowercase RFC-4122 text, uses canonical lower-case symbolic permission names, UTF-8 byte-sorts names within `rights` and `inheritance_flags`, and serializes each ACE as `position|ace_type|principal_kind|principal_name|principal_uuid|comma-joined-rights|comma-joined-inheritance\n`. The recognized inheritance names are `directory_inherit`, `file_inherit`, `limit_inherit`, and `only_inherit`; the exact tables below permit only the listed values. An empty inheritance set serializes as an empty final column. No name-service lookup participates after the provisioned principal UUID/name binding. SHA-256 covers the exact ASCII serialization including each LF. Missing, duplicate, reordered, unexpected inherited, unknown, or extra ACEs/rights/flags fail closed.

The exact protected-directory ACE table, used by the phase-0 parent and every protected directory, is:

| Position | Type | Principal | Exact directory rights | Exact inheritance |
| --- | --- | --- | --- | --- |
| `0` | `deny` | provisioned repository writer | `add_file,add_subdirectory,chown,delete,delete_child,writeattr,writeextattr,writesecurity` | `directory_inherit,file_inherit` |
| `1` | `deny` | provisioned application runtime | `add_file,add_subdirectory,chown,delete,delete_child,writeattr,writeextattr,writesecurity` | `directory_inherit,file_inherit` |
| `2` | `allow` | root | `add_file,add_subdirectory,chown,delete,delete_child,list,readattr,readextattr,readsecurity,search,writeattr,writeextattr,writesecurity` | `directory_inherit,file_inherit` |

The exact protected-regular-file ACE table is:

| Position | Type | Principal | Exact regular-file rights | Exact inheritance |
| --- | --- | --- | --- | --- |
| `0` | `deny` | provisioned repository writer | `append,chown,delete,write,writeattr,writeextattr,writesecurity` | `empty` |
| `1` | `deny` | provisioned application runtime | `append,chown,delete,write,writeattr,writeextattr,writesecurity` | `empty` |
| `2` | `allow` | root | `append,chown,delete,read,readattr,readextattr,readsecurity,write,writeattr,writeextattr,writesecurity` | `empty` |

Protected directories are root:wheel `0700`; protected regular data files are root:wheel `0600`. Immediately after each `mkdirat` or file `openat(O_CREAT|O_EXCL)` succeeds, root obtains the new descriptor and calls `acl_set_fd_np(fd, ACL_TYPE_EXTENDED, exact_object_acl)` to replace inherited ACLs with the exact directory or regular-file table before writing any file content or performing fsync/publication. It then calls `acl_get_fd_np`, canonically serializes, and verifies exact round-trip equality and SHA. Set failure, get failure, inherited residue, table mismatch, or a content write before successful round-trip permanently aborts that request. The checkout root, phase-0 parent, creation parents, authority children, staging siblings, lock, recovery marker, pin directory/current pointer, request inbox, replay store, and receipt store each use the applicable exact object table.

Every authority entry SHALL contain exactly:

`relative_path`, `object_type`, `owner_uid`, `group_gid`, `mode`, `device_id`, `inode_or_null`, `creation_parent_relative_path`, `creation_parent_device_id`, `creation_parent_inode`, `entry_acl_sha256_or_null`, `parent_acl_sha256`, and `allowed_operations`.

The exact UTF-8 byte-sorted authority inventory is:

| Relative path | Type | Exact allowed operations |
| --- | --- | --- |
| `docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_JOURNAL` | `directory` | `exclusive_create_child,fsync_child,fsync_directory,read` |
| `docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_POINTER.json` | `regular_file_or_absent` | `atomic_replace,exclusive_stage_sibling,fsync_parent,fsync_stage,read` |
| `docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_POINTER_RECOVERY_REQUIRED.json` | `regular_file_or_absent` | `exclusive_create,fsync_file,fsync_parent,read,unlink_after_recovery` |
| `docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_SELECTION.lock` | `regular_file` | `advisory_exclusive_lock,open_existing,read` |
| `docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_SELECTIONS` | `directory` | `exclusive_create_child,fsync_child,fsync_directory,read` |
| `docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ACTIVATION_DECISIONS` | `directory` | `exclusive_create_child,fsync_child,fsync_directory,read` |

## 6. Two-stage binary and immutable-pin v2 trust

Provisioning installs a versioned binary at `/Library/PrivilegedHelperTools/id.ac.esaunggul.simrs-campus-ueu.g0-governance/versions/<binary_sha256>/g0-host`, root:wheel `0700`, one link, regular non-symlink, with the directory root:wheel `0700`. The basename digest must equal the binary bytes. Trust construction is one-way: immutable bootstrap, immutable generation-1 pin record that hash-binds the bootstrap, then a separate mutable current pointer that hash-binds the pin record. The bootstrap does not contain the generation-1 pin path or hash; the pin does not reference the current pointer. There is no mutual hash.

The bootstrap pin v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `profile`, `status`, `bootstrap_generation`, `host_identity_reference`, `immutable_binary`, `pin_record_directory_reference`, `current_pointer_path`, `created_at`, and `created_by_uid`.

The immutable pin-record directory identity SHALL contain exactly:

`artifact_type`, `schema_version`, `path`, `device_id`, `inode`, `owner_uid`, `group_gid`, `mode`, `acl_sha256`, and `created_at`.

The immutable binary reference SHALL contain exactly:

`path`, `device_id`, `inode`, `link_count`, `owner_uid`, `group_gid`, `mode`, `size_bytes`, `sha256`, and `code_identity`.

The code identity SHALL contain exactly:

`schema_version`, `designated_requirement_bytes_base64`, `designated_requirement_sha256`, `team_identifier`, `signing_certificate_sha256`, `signing_identifier`, `cdhashes_by_architecture`, `architectures`, `hardened_runtime_required`, `library_validation_required`, `permitted_apple_library_roots`, and `forbid_dlopen_and_plugins`.

The immutable pin record v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `profile`, `status`, `pin_id`, `generation`, `valid_from`, `expires_at`, `predecessor_pin_path`, `predecessor_pin_sha256`, `bootstrap_pin_sha256`, `host_identity_reference`, `immutable_binary`, `canonical_checkout_reference`, `git_tool_reference`, `ssh_tool_reference`, `state_acl_reference`, `request_inbox_reference`, `replay_identity_reference`, `replay_policy_reference`, `receipt_store_reference`, `trust_store_reference`, `authorship_roster_reference`, `reason_code_contract_reference`, and `created_at`.

The mutable current-pointer v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `profile`, `status`, `generation`, `pin_record_path`, `pin_record_sha256`, `updated_at`, and `updated_by_uid`.

Every `*_reference` not otherwise specialized is exactly `path`, `sha256`, `device_id`, and `inode`. A pin record's fixed direct-child path is `<pin-record-directory>/<pin_record_sha256>.json`; the lower-case 64-hex basename is derived after canonical pin bytes exist and is not stored inside those bytes. It is created with `O_EXCL`, ACL-set/readback, file fsync, and directory fsync and is never rewritten. Generation `1` has null predecessor path/SHA and must hash-bind the exact bootstrap bytes; later generations increment by one and require exact non-null predecessor pin path/SHA. A pin lifetime is at most `2592000` seconds and overlap at most `3600` seconds. Every predecessor remains byte-available.

Only after an immutable pin record is durable may root atomically replace the current pointer with exact bytes naming that pin path/SHA/generation. The pointer is root:wheel `0600`, uses the regular-file ACL, is read back and parent-fsynced, and never supplies any policy not already in the immutable pin. Pointer loss or drift fails closed; recovery requires a separately authorized root provisioning action.

The exact bootstrap graph is:

| Construction predecessor | Constructed dependent | Sole forward binding |
| --- | --- | --- |
| `bootstrap_pin` | `immutable_generation_1_pin_record` | generation-1 pin contains `bootstrap_pin_sha256` |
| `immutable_pin_record` | `mutable_current_pointer` | pointer contains exact pin-record path/SHA/generation |

There is no edge from bootstrap to current pointer, from bootstrap to a pin-record hash, from pin record to current pointer bytes/hash, or from current pointer back into bootstrap/pin bytes.

The executable obtains itself with `SecCodeCopySelf`, requires `SecCodeCheckValidity` with `kSecCSStrictValidate|kSecCSCheckAllArchitectures|kSecCSCheckNestedCode`, compares the exact designated-requirement bytes and SHA, Team ID, certificate SHA, signing identifier, architecture-sorted CDHashes, and architecture set, and requires hardened-runtime and library-validation flags. `architectures` is a unique UTF-8 byte-sorted nonempty subset of `arm64,x86_64`; `cdhashes_by_architecture` has exactly the same keys and each value is 40 lower-case hexadecimal; permitted Apple roots are exactly `/System/Library` and `/usr/lib`. The host enumerates every loaded image with `_dyld_image_count`/`_dyld_get_image_name`, resolves it without symlinks, and validates it with `SecStaticCodeCreateWithPath` and `SecCodeCheckValidity` against the Apple anchor and pinned roots before and after request verification. Release binaries contain no `dlopen`, `NSBundle` plug-in loading, JIT, unsigned executable memory, or project code loading. Pinned Apple frameworks and libraries under `/System/Library` and `/usr/lib` are permitted; caller-selected, repository-selected, injected, relative, writable, non-Apple, or unpinned runtime code is rejected. `DYLD_*`, `LD_*`, `RUBY*`, `BUNDLE*`, and project-code environment variables are rejected before state open.

## 7. Pinned Git execution

The Git tool reference SHALL contain exactly:

`path`, `device_id`, `inode`, `owner_uid`, `group_gid`, `mode`, `sha256`, `version_stdout_sha256`, `maximum_stdout_bytes`, `maximum_stderr_bytes`, `maximum_runtime_milliseconds`, and `sanitized_environment`.

Closed values are path `/usr/bin/git`, stdout maximum `1048576`, stderr maximum `65536`, runtime maximum `5000`, no stdin, and environment exactly `HOME=/var/empty`, `LANG=C`, `LC_ALL=C`, `PATH=/usr/bin:/bin`, `GIT_CONFIG_NOSYSTEM=1`, `GIT_CONFIG_GLOBAL=/dev/null`, `GIT_CONFIG_COUNT=0`, and `GIT_NO_REPLACE_OBJECTS=1`. All other `GIT_*`, `DYLD_*`, locale, pager, editor, prompt, askpass, and credential variables are absent. Git is spawned by absolute path without a shell, `-c`, aliases, hooks, pager, network transport, or caller arguments.

The dedicated checkout must be non-bare, non-shallow, at one exact detached 40-lowercase-hex SHA-1 commit. The host rejects `.git/shallow`, `.git/info/grafts`, any `refs/replace/*`, alternates, promisor/partial clone state, submodules, worktree indirection outside the checkout, ambiguous revision, unexpected object type, nonzero exit, timeout, signal, truncation, or stderr overflow. `canonical_commit_content_sha256` is SHA-256 of the exact bytes from `/usr/bin/git cat-file commit <oid>` with no normalization, Git object header, or OID text.

The SSH tool reference SHALL contain exactly:

`path`, `device_id`, `inode`, `owner_uid`, `group_gid`, `mode`, `sha256`, `version_stdout_sha256`, `maximum_stdin_bytes`, `maximum_stdout_bytes`, `maximum_stderr_bytes`, `maximum_runtime_milliseconds`, and `sanitized_environment`.

Closed values are path `/usr/bin/ssh-keygen`, stdin maximum `16384`, stdout/stderr maximum `65536`, runtime maximum `5000`, and environment exactly `HOME=/var/empty`, `LANG=C`, `LC_ALL=C`, and `PATH=/usr/bin:/bin`; all SSH agent, askpass, `DYLD_*`, locale, and caller variables are absent. It is spawned by absolute path without a shell. The host passes the payload on stdin and only `/dev/fd/<host-selected-number>` paths for one already-open signature and one pinned allowed-signers descriptor. Those numbers are selected by the host after closing all unrelated descriptors, are never accepted from CLI/JSON/environment, and are the only descriptors with `FD_CLOEXEC` temporarily cleared for that child. The host restores/closes them immediately and revalidates the original file descriptors afterward. Nonzero exit, timeout, signal, truncation, unexpected stdout, or stderr overflow fails closed.

## 8. Replay identity and renewable replay policy

The immutable replay identity SHALL contain exactly:

`artifact_type`, `schema_version`, `replay_store_id`, `directory_path`, `device_id`, `inode`, `owner_uid`, `group_gid`, `mode`, `acl_sha256`, `genesis_record_path`, `genesis_record_sha256`, `chain_algorithm`, and `created_at`.

It has no expiry. Its store ID, directory identity, genesis, and chain algorithm never change across pins. Empty, alternate, replaced, disappeared, cross-device, inode-changed, forked, truncated, reset, or non-tip stores fail closed.

The renewable replay policy SHALL contain exactly:

`artifact_type`, `schema_version`, `policy_id`, `status`, `replay_identity_reference`, `writer_uid`, `valid_from`, `expires_at`, `predecessor_policy_reference`, and `approved_reason`.

Renewal binds the same immutable replay identity and exact predecessor. Renewal cannot reset, fork, truncate, relocate, or replace replay history.

The external authorship roster v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `roster_id`, `status`, `valid_from`, `expires_at`, `canonical_checkout_reference`, `canonical_commit_oid_sha1`, `canonical_commit_content_sha256`, `executor_identity_id`, `product_owner_identity_id`, `reviewer_identity_id`, `covered_artifacts`, `excluded_identity_set_sha256`, `predecessor_roster_reference`, and `created_at`.

Every `covered_artifacts` item SHALL contain exactly:

`path`, `sha256`, `artifact_kind`, `verified_author_identity_ids`, `verified_approver_identity_ids`, and `provenance_records`.

Every `provenance_records` item SHALL contain exactly:

`record_type`, `external_record_path`, `external_record_sha256`, `identity_id`, `event_at`, and `verification_method`.

The host derives the excluded identity set from the pinned external provenance, executor, product owner, and reviewer facts; repository-authored identity arrays are ignored. Covered paths, author/approver arrays, and provenance records are unique and UTF-8 byte sorted. Missing an author, approver, role, artifact, or provenance record fails closed.

## 9. External request inbox and immutable imports

The root-owned request inbox is non-authoritative. Each request is one direct directory named by a 64-lowercase-hex `request_id`, root:wheel `0700`, on the pinned inbox device/inode. After finalization it is sealed: no file may be created, removed, renamed, linked, chmodded, chowned, or rewritten. Direct regular children only; no nesting, symlinks, hard links, sparse files, resource forks, extended attributes, or ACL drift.

`request_id` and `bundle_id` are independent 32-byte CSPRNG values encoded as exactly 64 lower-case hexadecimal characters, generated outside the repository before intent construction, and never reused. They are identifiers only and confer no authority.

An immutable file reference SHALL contain exactly:

`logical_role`, `filename`, `sha256`, `size_bytes`, `device_id`, `inode`, `link_count`, `owner_uid`, `group_gid`, and `mode`.

The request bundle v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `bundle_id`, `request_id`, `operation`, `created_at`, `expires_at`, `inbox_identity_reference`, `operation_intent_reference`, `approval_subject_reference`, `owner_attestation_payload_reference`, `owner_signature_reference`, `owner_signature_import_receipt_reference`, `owner_attestation_reference`, `reviewer_attestation_payload_reference`, `reviewer_signature_reference`, `reviewer_signature_import_receipt_reference`, `reviewer_attestation_reference`, `operation_decision_reference`, `request_reference`, and `file_inventory_sha256`.

All direct-child `*_reference` values are immutable file references. Each receipt reference and the inbox identity reference uses exactly `path`, `sha256`, `device_id`, and `inode`. `file_inventory_sha256` hashes the UTF-8 byte-sorted ten-line imported inventory; each exact line is `filename || "\\0" || sha256 || "\\0" || unsigned-decimal-size || "\\n"`. The generated bundle does not hash itself or reference its later bundle receipt/seal.

A signature import receipt v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `import_receipt_id`, `receipt_kind`, `bundle_id`, `request_id`, `operation`, `role`, `signer_key_id`, `approval_subject_sha256`, `attestation_payload_sha256`, `signature_filename`, `signature_sha256`, `signature_size_bytes`, `inbox_directory_path`, `inbox_directory_device_id`, `inbox_directory_inode`, `signature_inode`, `imported_at`, `imported_by_real_uid`, and `imported_by_effective_uid`.

The request-bundle import receipt v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `import_receipt_id`, `receipt_kind`, `bundle_id`, `request_id`, `operation`, `inbox_directory_path`, `inbox_directory_device_id`, `inbox_directory_inode`, `imported_file_references`, `request_bundle_path`, `request_bundle_sha256`, `imported_at`, `imported_by_real_uid`, and `imported_by_effective_uid`.

Both importer UIDs are `0`. A signature receipt has kind `signature`, and its ID is `sha256("g0-signature-import-v2\\0" || bundle_id || "\\0" || role || "\\0" || signature_sha256)`; it is created before the corresponding attestation JSON and is then hash-bound by both attestation and bundle. A bundle receipt has kind `request_bundle`, and its ID is `sha256("g0-bundle-import-v2\\0" || request_bundle_sha256)`. Fixed receipt paths are `<import-receipts>/<import_receipt_id>.json`; creation uses `O_EXCL`, ACL set/get verification, readback, file fsync, and directory fsync. `imported_file_references` has the exact ten imported files in UTF-8 byte-sorted filename order. Neither receipt contains its own SHA. This ordering is acyclic: signed payload, signature, signature receipt, attestation, final decision, bundle, then bundle receipt. An inbox bundle or receipt is evidence only; it grants no authority.

The mutable import state path is exactly `<import-receipts>/<request_id>.state-v2.json`; the immutable seal path is exactly `<import-receipts>/<request_id>.seal-v2.json`.

The request import-state v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `request_id`, `status`, `sealed`, `next_filename_or_null`, `imported_file_references`, `signature_import_receipt_references`, `request_bundle_reference_or_null`, `bundle_import_receipt_reference_or_null`, `request_seal_reference_or_null`, `last_transition_at`, and `last_transition_by_uid`.

The immutable request seal v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `seal_id`, `request_id`, `bundle_id`, `operation`, `request_bundle_reference`, `bundle_import_receipt_reference`, `sealed`, `sealed_at`, and `sealed_by_uid`.

Import state is `OPEN`, `ABORTED`, or `SEALED`; `sealed` is true only for `SEALED`. It is a root-owned non-authoritative state pointer updated by ACL-set stage/O_EXCL/fsync/readback/atomic-replace/parent-fsync. A seal is immutable, has `sealed: true`, is O_EXCL/fsynced/read back, and hash-binds the bundle and bundle receipt. The state then hash-binds the seal. Neither bundle nor receipt points forward to the seal, so no cycle exists.

Preauthorization reads and verifies the sealed external bundle, its state, seal, and receipts. The final decision remains external until after durable replay burn. Only then may the exact byte-identical `operation-decision-v2.json` be published with `O_EXCL` into the protected canonical decision directory. A preauthorization rejection writes neither canonical state nor replay/receipt state.

## 10. Exact acyclic approval DAG and schemas

The operation intent v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `intent_id`, `request_id`, `bundle_id`, `operation`, `environment`, `candidate_reference`, `prior_state_reference`, `actor`, `conditions`, `created_at`, and `expires_at`.

The approval subject v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `subject_id`, `request_id`, `bundle_id`, `operation`, `operation_intent_reference`, `contract_reference`, `technical_preflight_reference`, `authorship_roster_reference`, `excluded_identity_set_sha256`, `issued_at`, and `expires_at`.

The attestation signed payload v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `payload_id`, `request_id`, `bundle_id`, `operation`, `role`, `signer_key_id`, `approval_subject_reference`, `issued_at`, `expires_at`, and `nonce`.

The detached signature is over the exact canonical UTF-8-plus-one-LF bytes of `owner-attestation-payload-v2.json` or `reviewer-attestation-payload-v2.json`, not the subject bytes, attestation wrapper, hash text, or reconstructed data. The payload hash is recomputed from those exact bytes before SSHSIG verification.

An operation decision reference SHALL contain exactly:

`artifact_type`, `schema_version`, `operation`, `decision_id`, `filename`, `sha256`, and `canonical_publish_relative_path`.

The attestation v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `attestation_id`, `request_id`, `bundle_id`, `operation`, `role`, `signer_key_id`, `approval_subject_reference`, `attestation_payload_reference`, `external_signature_reference`, and `signature_import_receipt_reference`.

An external signature reference SHALL contain exactly:

`filename`, `sha256`, `size_bytes`, `inbox_directory_path`, `inbox_directory_device_id`, `inbox_directory_inode`, `file_inode`, `link_count`, `owner_uid`, `group_gid`, `mode`, `import_receipt_path`, and `import_receipt_sha256`.

The final operation decision v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `decision_id`, `request_id`, `bundle_id`, `operation`, `operation_intent_reference`, `approval_subject_reference`, `owner_attestation_reference`, `reviewer_attestation_reference`, `approval_evidence_reference`, `independent_review_reference`, `decision_effect`, `conditions`, `issued_at`, and `expires_at`.

Attestation closed values are `artifact_type: g0_external_attestation_v2`, `schema_version: 2`, role `product_owner` or `independent_technical_security_reviewer`, and exact same-bundle subject/payload/signature/receipt references. `approval_evidence_reference` is byte-equal to `owner_attestation_reference`; `independent_review_reference` is byte-equal to `reviewer_attestation_reference`. For canonical v2 only, this construction supersedes governance contract 1.3's repository-authored `approval_evidence` construction; contract 1.3 remains historical and cannot satisfy this host path. The predecessor v1 attestation and its `signature_path` field are canonically rejected. Signer enrollment, role, payload, subject, operation, request, bundle, TTL, and separation are verified together.

The final decision adds no unsigned discretion: its request/bundle/operation/intent/subject values are byte-equal to upstream values; `conditions` is byte-equal to the signed intent conditions; `issued_at` is not earlier than both attestation payload issue times; `expires_at` is the earliest of intent, subject, and both payload expiries; and `decision_effect` is exactly `authorize_one_gate_b_consumer_operation_after_all_preconditions`. Its decision ID is `sha256("g0-final-decision-v2\\0" || intent_sha256 || "\\0" || subject_sha256 || "\\0" || owner_attestation_sha256 || "\\0" || reviewer_attestation_sha256)`. Any differing projection fails before finalization.

The exact DAG edges are:

| From node | To node | Required hash/reference edge |
| --- | --- | --- |
| `operation_intent` | `approval_subject` | `operation_intent_reference` |
| `approval_subject` | `owner_attestation_payload` | `approval_subject_reference` |
| `approval_subject` | `reviewer_attestation_payload` | `approval_subject_reference` |
| `owner_attestation_payload` | `owner_signature` | signature covers exact payload bytes |
| `reviewer_attestation_payload` | `reviewer_signature` | signature covers exact payload bytes |
| `approval_subject` | `owner_signature_import_receipt` | `approval_subject_sha256` |
| `owner_attestation_payload` | `owner_signature_import_receipt` | `attestation_payload_sha256` |
| `owner_signature` | `owner_signature_import_receipt` | `signature_filename`, `signature_sha256`, `signature_size_bytes`, `signature_inode` |
| `approval_subject` | `reviewer_signature_import_receipt` | `approval_subject_sha256` |
| `reviewer_attestation_payload` | `reviewer_signature_import_receipt` | `attestation_payload_sha256` |
| `reviewer_signature` | `reviewer_signature_import_receipt` | `signature_filename`, `signature_sha256`, `signature_size_bytes`, `signature_inode` |
| `approval_subject` | `owner_attestation` | `approval_subject_reference` |
| `owner_attestation_payload` | `owner_attestation` | `attestation_payload_reference` |
| `owner_signature` | `owner_attestation` | `external_signature_reference` |
| `owner_signature_import_receipt` | `owner_attestation` | `signature_import_receipt_reference` |
| `owner_signature_import_receipt` | `owner_attestation` | `external_signature_reference.import_receipt_path`, `external_signature_reference.import_receipt_sha256` |
| `approval_subject` | `reviewer_attestation` | `approval_subject_reference` |
| `reviewer_attestation_payload` | `reviewer_attestation` | `attestation_payload_reference` |
| `reviewer_signature` | `reviewer_attestation` | `external_signature_reference` |
| `reviewer_signature_import_receipt` | `reviewer_attestation` | `signature_import_receipt_reference` |
| `reviewer_signature_import_receipt` | `reviewer_attestation` | `external_signature_reference.import_receipt_path`, `external_signature_reference.import_receipt_sha256` |
| `operation_intent` | `final_operation_decision` | `operation_intent_reference` |
| `approval_subject` | `final_operation_decision` | `approval_subject_reference` |
| `owner_attestation` | `final_operation_decision` | `owner_attestation_reference` and `approval_evidence_reference` |
| `reviewer_attestation` | `final_operation_decision` | `reviewer_attestation_reference` and `independent_review_reference` |
| `final_operation_decision` | `mutation_request` | `operation_decision_reference` |
| `operation_intent` | `request_bundle` | `operation_intent_reference` |
| `approval_subject` | `request_bundle` | `approval_subject_reference` |
| `owner_attestation_payload` | `request_bundle` | `owner_attestation_payload_reference` |
| `owner_signature` | `request_bundle` | `owner_signature_reference` |
| `owner_signature_import_receipt` | `request_bundle` | `owner_signature_import_receipt_reference` |
| `owner_attestation` | `request_bundle` | `owner_attestation_reference` |
| `reviewer_attestation_payload` | `request_bundle` | `reviewer_attestation_payload_reference` |
| `reviewer_signature` | `request_bundle` | `reviewer_signature_reference` |
| `reviewer_signature_import_receipt` | `request_bundle` | `reviewer_signature_import_receipt_reference` |
| `reviewer_attestation` | `request_bundle` | `reviewer_attestation_reference` |
| `final_operation_decision` | `request_bundle` | `operation_decision_reference` |
| `mutation_request` | `request_bundle` | `request_reference` |
| `operation_intent` | `request_bundle` | `file_inventory_sha256[operation-intent-v2.json]` |
| `approval_subject` | `request_bundle` | `file_inventory_sha256[approval-subject-v2.json]` |
| `owner_attestation_payload` | `request_bundle` | `file_inventory_sha256[owner-attestation-payload-v2.json]` |
| `reviewer_attestation_payload` | `request_bundle` | `file_inventory_sha256[reviewer-attestation-payload-v2.json]` |
| `owner_signature` | `request_bundle` | `file_inventory_sha256[owner-attestation.sshsig]` |
| `reviewer_signature` | `request_bundle` | `file_inventory_sha256[reviewer-attestation.sshsig]` |
| `owner_attestation` | `request_bundle` | `file_inventory_sha256[owner-attestation-v2.json]` |
| `reviewer_attestation` | `request_bundle` | `file_inventory_sha256[reviewer-attestation-v2.json]` |
| `final_operation_decision` | `request_bundle` | `file_inventory_sha256[operation-decision-v2.json]` |
| `mutation_request` | `request_bundle` | `file_inventory_sha256[request.json]` |
| `request_bundle` | `bundle_import_receipt` | `request_bundle_path`, `request_bundle_sha256` |
| `operation_intent` | `bundle_import_receipt` | `imported_file_references[operation-intent-v2.json]` |
| `approval_subject` | `bundle_import_receipt` | `imported_file_references[approval-subject-v2.json]` |
| `owner_attestation_payload` | `bundle_import_receipt` | `imported_file_references[owner-attestation-payload-v2.json]` |
| `reviewer_attestation_payload` | `bundle_import_receipt` | `imported_file_references[reviewer-attestation-payload-v2.json]` |
| `owner_signature` | `bundle_import_receipt` | `imported_file_references[owner-attestation.sshsig]` |
| `reviewer_signature` | `bundle_import_receipt` | `imported_file_references[reviewer-attestation.sshsig]` |
| `owner_attestation` | `bundle_import_receipt` | `imported_file_references[owner-attestation-v2.json]` |
| `reviewer_attestation` | `bundle_import_receipt` | `imported_file_references[reviewer-attestation-v2.json]` |
| `final_operation_decision` | `bundle_import_receipt` | `imported_file_references[operation-decision-v2.json]` |
| `mutation_request` | `bundle_import_receipt` | `imported_file_references[request.json]` |
| `request_bundle` | `request_seal` | `request_bundle_reference` |
| `bundle_import_receipt` | `request_seal` | `bundle_import_receipt_reference` |
| `operation_intent` | `replay_reservation` | `intent_id`, `intent_sha256` |
| `approval_subject` | `replay_reservation` | `subject_id`, `subject_sha256` |
| `owner_attestation` | `replay_reservation` | `owner_attestation_id`, `owner_attestation_sha256` |
| `reviewer_attestation` | `replay_reservation` | `reviewer_attestation_id`, `reviewer_attestation_sha256` |
| `final_operation_decision` | `replay_reservation` | `decision_id`, `decision_sha256` |
| `request_bundle` | `replay_reservation` | `request_bundle_sha256` |
| `bundle_import_receipt` | `replay_reservation` | `bundle_import_receipt_sha256` |
| `request_seal` | `replay_reservation` | `request_seal_sha256` |

The exact external-root dependency table is separate from the approval DAG:

| External root | Dependent node | Required hash/reference field |
| --- | --- | --- |
| `candidate_evidence` | `operation_intent` | `candidate_reference` |
| `prior_state_evidence` | `operation_intent` | `prior_state_reference` |
| `governance_contract` | `approval_subject` | `contract_reference` |
| `technical_preflight` | `approval_subject` | `technical_preflight_reference` |
| `authorship_roster` | `approval_subject` | `authorship_roster_reference` |
| `authorship_roster` | `approval_subject` | `excluded_identity_set_sha256` |
| `request_inbox_identity` | `owner_signature_import_receipt` | `inbox_directory_path`, `inbox_directory_device_id`, `inbox_directory_inode` |
| `request_inbox_identity` | `reviewer_signature_import_receipt` | `inbox_directory_path`, `inbox_directory_device_id`, `inbox_directory_inode` |
| `request_inbox_identity` | `owner_attestation` | `external_signature_reference.inbox_directory_path`, `external_signature_reference.inbox_directory_device_id`, `external_signature_reference.inbox_directory_inode` |
| `request_inbox_identity` | `reviewer_attestation` | `external_signature_reference.inbox_directory_path`, `external_signature_reference.inbox_directory_device_id`, `external_signature_reference.inbox_directory_inode` |
| `request_inbox_identity` | `request_bundle` | `inbox_identity_reference` |
| `request_inbox_identity` | `bundle_import_receipt` | `inbox_directory_path`, `inbox_directory_device_id`, `inbox_directory_inode` |
| `current_pointer` | `replay_reservation` | `current_pointer_sha256` |
| `immutable_pin_record` | `replay_reservation` | `pin_record_sha256` |
| `prior_canonical_pointer` | `replay_reservation` | `prior_pointer_sha256` |
| `replay_predecessor` | `replay_reservation` | `replay_predecessor_sha256` |

The exact non-authoritative import-state dependency table is:

| From node | To node | Required hash/reference field |
| --- | --- | --- |
| `operation_intent` | `import_state` | `imported_file_references[operation-intent-v2.json]` |
| `approval_subject` | `import_state` | `imported_file_references[approval-subject-v2.json]` |
| `owner_attestation_payload` | `import_state` | `imported_file_references[owner-attestation-payload-v2.json]` |
| `reviewer_attestation_payload` | `import_state` | `imported_file_references[reviewer-attestation-payload-v2.json]` |
| `owner_signature` | `import_state` | `imported_file_references[owner-attestation.sshsig]` |
| `reviewer_signature` | `import_state` | `imported_file_references[reviewer-attestation.sshsig]` |
| `owner_attestation` | `import_state` | `imported_file_references[owner-attestation-v2.json]` |
| `reviewer_attestation` | `import_state` | `imported_file_references[reviewer-attestation-v2.json]` |
| `final_operation_decision` | `import_state` | `imported_file_references[operation-decision-v2.json]` |
| `mutation_request` | `import_state` | `imported_file_references[request.json]` |
| `owner_signature_import_receipt` | `import_state` | `signature_import_receipt_references[product_owner]` |
| `reviewer_signature_import_receipt` | `import_state` | `signature_import_receipt_references[independent_reviewer]` |
| `request_bundle` | `import_state` | `request_bundle_reference_or_null` |
| `bundle_import_receipt` | `import_state` | `bundle_import_receipt_reference_or_null` |
| `request_seal` | `import_state` | `request_seal_reference_or_null` |

Construction follows a deterministic topological order and never permits a node to hash-bind a later node. The implementation derives edges from the closed schema fields and requires exact equality with all three tables; unknown, backward, self, omitted, or extra edges fail closed.

## 11. Strict SSHSIG envelope contract

Before `/usr/bin/ssh-keygen -Y verify`, the native parser requires SSHSIG magic `SSHSIG`, version `1`, enrolled `ssh-ed25519`, the exact namespace `simrs-campus-ueu-g0-governance-v2-gate-b`, empty reserved field, hash algorithm `sha512`, and inner algorithm `ssh-ed25519`. Numeric maximums are: payload `16384` bytes, armor `8192`, decoded envelope `4096`, public-key blob `128`, namespace `64`, reserved field `0`, hash-algorithm field `16`, outer signature blob `128`, and inner Ed25519 signature exactly `64`. All length additions use checked arithmetic; the parser requires the exact version-1 field count and no trailing byte. A cryptographically valid SHA-256 SSHSIG is rejected before OpenSSH is invoked.

The signature inbox identity is the sealed request directory identity from Section 9. Signatures are exact direct-child filenames, root:wheel `0600`, link count one, no xattrs/resource forks, and hash-bound by both bundle and import receipt. The signer key ID, attestation role, attestation subject SHA, request ID, bundle ID, and operation must match the allowed-signers/enrollment/policy records and signed payload. Substituting either signature, receipt, signer, subject, or sibling request fails closed.

## 12. Closed root-only CLI, import state machine, and request schema

The release executable accepts only these exact argument forms and input contracts:

| Operation | Exact argv after executable | External request needed | State-write rule |
| --- | --- | --- | --- |
| `self-test` | `--operation self-test` | `no` | no persistent write |
| `preflight` | `--operation preflight` | `no` | no persistent write |
| `resolve-active` | `--operation resolve-active` | `no` | no persistent write |
| `import-file` | `--operation import-file --request-id <64-lowercase-hex> --filename <closed-basename>` | bounded stdin bytes for that filename | non-authoritative request import only |
| `finalize-request` | `--operation finalize-request --request-id <64-lowercase-hex>` | empty stdin | non-authoritative bundle/receipt/seal only |
| `activate` | `--operation activate --request-id <64-lowercase-hex>` | `governance_mutation_request_v2` | burn then canonical mutation/receipt |
| `rollback` | `--operation rollback --request-id <64-lowercase-hex>` | `governance_mutation_request_v2` | burn then canonical mutation/receipt |
| `disable` | `--operation disable --request-id <64-lowercase-hex>` | `governance_mutation_request_v2` | burn then canonical mutation/receipt |
| `recover` | `--operation recover --request-id <64-lowercase-hex>` | `governance_mutation_request_v2` | burn then canonical mutation/receipt |

No other flag, positional argument, stdin byte, environment override, source path, descriptor, token, filename, or operation is accepted. Import receives bytes only on stdin and never accepts an arbitrary source/destination path. Defining and fixture-testing import/finalize does not authorize invoking either operation; canonical invocation remains separately unauthorized until ADR approval, reviewed implementation, host provisioning, and a separate import authorization exist.

The exact import order, content kind, and inclusive byte caps are:

| Order | Closed basename | Content kind | Maximum stdin bytes |
| --- | --- | --- | --- |
| `1` | `operation-intent-v2.json` | `canonical_json_lf` | `16384` |
| `2` | `approval-subject-v2.json` | `canonical_json_lf` | `32768` |
| `3` | `owner-attestation-payload-v2.json` | `canonical_json_lf` | `16384` |
| `4` | `reviewer-attestation-payload-v2.json` | `canonical_json_lf` | `16384` |
| `5` | `owner-attestation.sshsig` | `openssh_sshsig_armored_lf` | `8192` |
| `6` | `reviewer-attestation.sshsig` | `openssh_sshsig_armored_lf` | `8192` |
| `7` | `owner-attestation-v2.json` | `canonical_json_lf` | `16384` |
| `8` | `reviewer-attestation-v2.json` | `canonical_json_lf` | `16384` |
| `9` | `operation-decision-v2.json` | `canonical_json_lf` | `32768` |
| `10` | `request.json` | `canonical_json_lf` | `16384` |

`canonical_json_lf` is exactly the RFC 8785 JSON Canonicalization Scheme (JCS) byte sequence followed by exactly one LF byte (`0x0a`). Parsing rejects duplicate object keys, invalid UTF-8, lone UTF-16 surrogates, a BOM, trailing bytes, every non-integer number, and every integer outside the exact IEEE-754 safe-integer range `-9007199254740991` through `9007199254740991`. Artifacts use integers only within that range. Object members follow JCS ordering; arrays preserve source order unless their closed schema explicitly requires UTF-8 byte sorting. No Unicode pre-normalization is performed before validation or canonicalization.

`import-file` first reads at most cap-plus-one bytes into locked process memory, requires nonempty exact EOF at or below the cap, rejects secrets, validates the content and every already-available DAG reference, and only then changes the filesystem. The first accepted file creates the request directory by exclusive `mkdirat`, immediately replaces/verifies its exact directory ACL, and creates OPEN import state. For every file, root calls `openat(O_CREAT|O_EXCL|O_NOFOLLOW|O_CLOEXEC)`, applies and round-trip verifies the regular-file ACL before content write, writes all bytes, file-fsyncs, descriptor-readbacks and rehashes, then directory-fsyncs before atomically advancing import state. A signature step also O_EXCL-creates/fsyncs/readbacks its deterministic signature receipt before state advance. Duplicate, skipped, reordered, oversized, empty, noncanonical, secret-bearing, reference-mismatched, extra, or post-seal input is rejected.

`finalize-request` requires empty stdin, OPEN state after exact order `10`, and the exact ten-file inventory. It revalidates the entire DAG and operation/ID/reference equality, then creates in order: `request-bundle-v2.json`, bundle import receipt, and request seal, each O_EXCL with object ACL set/get before content, write/fsync/readback, and parent fsync. Only after all three are durable does it atomically set import state to SEALED with `sealed: true`. It never creates a replay reservation or canonical authority artifact.

Any failure before the first state-producing syscall returns without write. Any error after request directory, child, receipt, bundle, or seal creation but before the matching durable state transition marks the request ABORTED immediately if the protected state remains writable; a crash or state-write failure makes only the next `import-file` or `finalize-request` invocation for the same `request_id` mark it ABORTED before processing new input. Partial bytes/artifacts are retained for evidence, never overwritten/deleted/resumed, and that request ID is never reusable. A fully durable SEALED state is immutable. Import/finalize success and abort receipts are non-authoritative and cannot satisfy operation approval. `self-test`, `preflight`, and `resolve-active` never inspect, repair, import, abort, or write request state.

`self-test` verifies compiled fixed public vectors for canonical JSON, SHA-256, SHA-512, strict SSHSIG parsing, valid Ed25519 verification, valid-SHA256 rejection, import state transitions, DAG construction, and malformed lengths. A provisioning-only `preflight` may create an ephemeral signing key in a root-owned `mkdtemp` directory outside the repository and state root, sign/verify one fixed smoke payload, then unlink the temporary public/private files and directory; release canonical mutation never generates or reads private keys. Failure to clean up fails preflight. `preflight` otherwise verifies the entire pinned host contract read-only. Both return stdout-only diagnostic receipts.

A governance mutation request v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `request_id`, `bundle_id`, `operation`, `requested_at`, `expires_at`, `nonce`, and `operation_decision_reference`.

The request is the exact `request.json` in the sealed bundle. `resolve-active` takes no request ID or bundle and is root-only/read-only. Mutation bundles require all files in Section 9.

## 13. Operation equality and substitution rejection

For a mutation, the canonical lower-case operation in CLI argv, request, request bundle, bundle/signature import receipts, operation intent, approval subject, both attestation payloads, both attestations, final operation decision, operation-decision reference, request seal, replay reservation, and every receipt common block SHALL be byte-equal and one of `activate`, `rollback`, `disable`, or `recover`. Request ID and bundle ID SHALL likewise match every schema that carries them. Intent, subject, payload, signature, receipt, attestation, decision, bundle, and seal paths/IDs/hashes/roles SHALL match their exact references.

No field is inferred, normalized, defaulted, or copied to conceal mismatch. Deterministic tests perform pairwise substitution for every pair of operation-bearing artifacts and independently replace each request ID, bundle ID, decision reference, subject reference, attestation reference, signer, and signature. Every substitution fails before replay burn.

## 14. Reservation and protected receipt-store schemas

The replay reservation v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `reservation_id`, `request_id`, `bundle_id`, `operation`, `current_pointer_sha256`, `pin_record_sha256`, `intent_id`, `intent_sha256`, `decision_id`, `decision_sha256`, `subject_id`, `subject_sha256`, `owner_attestation_id`, `owner_attestation_sha256`, `reviewer_attestation_id`, `reviewer_attestation_sha256`, `request_bundle_sha256`, `bundle_import_receipt_sha256`, `request_seal_sha256`, `prior_pointer_sha256`, `replay_predecessor_sha256`, and `reserved_at`.

The protected receipt-store identity SHALL contain exactly:

`artifact_type`, `schema_version`, `store_id`, `root_path`, `device_id`, `inode`, `owner_uid`, `group_gid`, `mode`, `acl_sha256`, `terminal_directory_reference`, `failure_directory_reference`, `reconciliation_directory_reference`, `genesis_receipt_path`, `genesis_receipt_sha256`, `predecessor_store_reference`, and `created_at`.

Receipt-store root and three child directories are root:wheel `0700`, pinned to one device, and use the ancestor/ACL protocol. Generation one has null `predecessor_store_reference`; later replacements are prohibited, so a non-null predecessor is permitted only for an independently approved same-directory metadata-format successor that preserves the exact root path/device/inode and all historical bytes. Empty, alternate, replaced, disappeared, cross-device, inode-changed, forked, truncated, or reset receipt stores fail closed.

A common receipt block SHALL contain exactly:

`receipt_id`, `request_id`, `bundle_id`, `operation`, `status`, `reason_code`, `phase`, `caller_real_uid`, `caller_effective_uid`, `started_at`, `finished_at`, `current_pointer_sha256`, `current_pin_sha256`, `request_sha256`, `bundle_sha256`, `reservation_sha256`, `active_chain_sha256`, `result_sha256`, and `secret_scan_passed`.

A diagnostic receipt v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `receipt_kind`, and `common`.

A terminal receipt v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `receipt_kind`, `common`, `decision_sha256`, `selection_sha256`, `journal_sha256`, `recovery_marker_sha256`, `prior_pointer_sha256`, and `result_pointer_sha256`.

A failure receipt v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `receipt_kind`, `common`, `failed_boundary`, `errno_or_null`, `observed_decision_sha256`, `observed_selection_sha256`, `observed_journal_sha256`, `observed_recovery_marker_sha256`, `observed_pointer_sha256`, and `required_next_action`.

A reconciliation receipt v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `receipt_kind`, `common`, `detected_reservation_sha256`, `observed_decision_sha256`, `observed_selection_sha256`, `observed_journal_sha256`, `observed_recovery_marker_sha256`, `observed_pointer_sha256`, `classified_partial_state`, and `required_next_action`.

For persisted receipts, `receipt_id = sha256("g0-receipt-v2\0" || reservation_sha256 || "\0" || receipt_kind)`, and the exact path is `<receipt-store>/<receipt_kind>/<receipt_id>.json`, where kinds are `terminal`, `failure`, and `reconciliation`. Creation is `O_CREAT|O_EXCL`, then file fsync, descriptor readback/hash comparison, and directory fsync. Receipt IDs and paths are deterministic; overwrite, alternate path, duplicate differing bytes, or receipt-device drift fails closed.

## 15. Closed statuses, phases, and reason codes

Receipt statuses are exactly `PASS_NO_WRITE`, `PASS_NONAUTHORITATIVE_IMPORT`, `PASS_NONAUTHORITATIVE_FINALIZE`, `ABORTED_NONAUTHORITATIVE_IMPORT`, `PASS`, `FAIL_NO_WRITE`, `BURNED_NO_AUTHORITY_CHANGE`, `FAIL_CLOSED_ACTIVE_UNRESOLVED`, and `RECONCILED_BURNED_NO_REPLAY`. Phases are exactly `startup_authorization`, `self_pin`, `self_test`, `preflight`, `import_read`, `import_acl`, `import_publish`, `import_state`, `finalize_bundle`, `finalize_receipt`, `finalize_seal`, `preauthorization`, `stable_lock`, `reconciliation`, `under_lock_revalidation`, `replay_burn`, `decision_publish`, `selection_publish`, `journal_publish`, `marker_publish`, `pointer_stage`, `pointer_rename`, `active_readback`, and `terminal_receipt`.

Reason codes are exactly `OK`, `UNAUTHORIZED_CALLER`, `SELF_PIN_INVALID`, `CODE_IDENTITY_INVALID`, `ENVIRONMENT_REJECTED`, `FILESYSTEM_IDENTITY_INVALID`, `ACL_INVALID`, `TOOL_IDENTITY_INVALID`, `GIT_STATE_INVALID`, `REQUEST_NOT_FOUND`, `REQUEST_SCHEMA_INVALID`, `BUNDLE_INVALID`, `IMPORT_RECEIPT_INVALID`, `IMPORT_ORDER_INVALID`, `IMPORT_SIZE_INVALID`, `IMPORT_ABORTED`, `FINALIZE_INVALID`, `OPERATION_MISMATCH`, `REFERENCE_MISMATCH`, `ATTESTATION_V1_REJECTED`, `ATTESTATION_INVALID`, `SIGNATURE_INVALID`, `SSHSIG_ENVELOPE_INVALID`, `TRUST_EXPIRED`, `SEPARATION_INVALID`, `PRIOR_STATE_INVALID`, `LOCK_FAILED`, `DRIFT_BEFORE_BURN`, `RECONCILIATION_REQUIRED`, `REPLAY_REJECTED`, `BURN_FAILED`, `CROSS_DEVICE_AUTHORITY_REJECTED`, `DECISION_PUBLISH_FAILED`, `SELECTION_PUBLISH_FAILED`, `JOURNAL_PUBLISH_FAILED`, `MARKER_PUBLISH_FAILED`, `POINTER_STAGE_FAILED`, `POINTER_RENAME_FAILED`, `ACTIVE_READBACK_FAILED`, `RECEIPT_PERSIST_FAILED`, and `ACTIVE_CHAIN_UNRESOLVED`. Unknown status, phase, or reason fails closed.

For `activate`, `rollback`, `disable`, and `recover`, every failure before replay burn is a stdout-only diagnostic receipt with `FAIL_NO_WRITE`; it writes no replay reservation, canonical decision, selection, journal, marker, pointer, log, or cache. Import/finalize alone may write the non-authoritative state exactly defined in Sections 9 and 12 and emit their three dedicated statuses; those writes confer no mutation authority. For a diagnostic receipt, `receipt_kind` is `diagnostic`; `receipt_id`, `request_id`, `bundle_id`, and `reservation_sha256` are null when not applicable. Successful `self-test` and `preflight` emit stdout-only diagnostic receipts with `PASS_NO_WRITE`. `resolve-active` emits only the active-chain result in Section 17. After burn, a mutation persists and emits the identical terminal or failure receipt bytes. Reconciliation persists its receipt before the current invocation emits any replay-rejection diagnostic. Receipt persistence failure never restores or reuses the burned authorization.

## 16. Exact mutation sequence and partial-state contract

The canonical mutation order is: root credential guard; self/bootstrap/current-pin/code/environment verification; fixed tool and ancestor/ACL verification; sealed request/bundle/import/subject/decision/attestation/signature/trust/roster/evidence/prior-state validation; stable lock; reconciliation of every burned reservation lacking a terminal receipt; complete under-lock revalidation; durable replay burn; byte-identical decision publish; selection publish; journal publish; recovery-marker publish when required; pointer stage; pointer rename; authoritative governance/ledger active readback; terminal receipt.

Reconciliation occurs under the stable lock before the current request is rejected as replay. A burned reservation without a terminal receipt is classified from fd-relative, hash-bound canonical reads and gets exactly one reconciliation receipt. Two reconcilers contend for the stable lock; the first creates with `O_EXCL`, and the second, after lock acquisition, reads and verifies the identical existing bytes. Fault tests also bypass scheduling assumptions and race the receipt-create primitive directly, where `O_EXCL` permits exactly one winner. A crash before receipt fsync causes the next reconciler to repeat classification but never resume mutation or reuse the reservation.

The exact observable partial-state table is:

| Last durable boundary | Required observable state | Reconciliation classification | Required action; never resume |
| --- | --- | --- | --- |
| `replay_burn` | reservation only; prior pointer unchanged | `BURN_ONLY` | write reconciliation receipt; fresh external authorization required |
| `decision_publish` | reservation plus exact decision; prior pointer unchanged | `DECISION_ONLY` | retain decision; write reconciliation receipt; fresh recovery authorization required |
| `selection_publish` | exact decision and selection; no journal; prior pointer unchanged | `SELECTION_PARTIAL` | retain artifacts; write reconciliation receipt; fresh recovery authorization required |
| `journal_publish` | exact decision, selection, and journal; prior pointer unchanged | `JOURNAL_PARTIAL` | retain artifacts; write reconciliation receipt; fresh recovery authorization required |
| `marker_publish` | exact recovery marker also exists; prior pointer unchanged | `MARKER_PARTIAL` | fail closed; write reconciliation receipt; fresh recover authorization required |
| `pointer_stage` | exact stage exists; canonical pointer remains prior bytes | `STAGED_POINTER_PARTIAL` | do not rename; write reconciliation receipt; fresh recovery authorization required |
| `pointer_rename` | canonical pointer is result bytes; readback/receipt absent | `RENAMED_UNREAD` | fail active resolution closed; write reconciliation receipt; fresh recover authorization required |
| `active_readback` | pointer and complete active chain verify; terminal receipt absent | `READBACK_NO_RECEIPT` | write reconciliation receipt recording verified state; authorization remains burned |
| `terminal_receipt` | exact terminal receipt and complete chain | `COMPLETE` | return existing result; reject replay |

Any impossible combination, unexpected hash, missing predecessor, cross-device authority component, receipt-store drift, or extra artifact is `FAIL_CLOSED_ACTIVE_UNRESOLVED`; it writes only a deterministic reconciliation/failure receipt if the receipt store remains valid. Cross-device replay and authority filesystems are permitted only because replay burns first; authority rename components themselves must share the deployment-checkout device. Crash injection is required before and after every file fsync, directory fsync, create, marker boundary, stage, rename, readback, and receipt boundary.

## 17. Active-chain governance/ledger result

The active-chain result v2 SHALL contain exactly:

`artifact_type`, `schema_version`, `request_id_or_null`, `operation`, `status`, `resolved_at`, `current_pointer_sha256`, `current_pin_sha256`, `pointer_exists`, `pointer_reference_or_null`, `selection_reference_or_null`, `decision_reference_or_null`, `approval_subject_reference_or_null`, `owner_attestation_reference_or_null`, `reviewer_attestation_reference_or_null`, `reservation_reference_or_null`, `journal_reference_or_null`, `recovery_marker_reference_or_null`, `active_operation_or_null`, `active_candidate_bundle_id_or_null`, `active_contract_version_or_null`, `prior_chain_sha256_or_null`, `active_chain_sha256_or_null`, and `synthetic_only`.

The result verifies every exact byte/hash/predecessor/reservation/receipt link needed to explain the current governance pointer to a governance or ledger operator. `current_pointer_sha256` hashes the exact mutable pointer bytes read for this invocation; `current_pin_sha256` hashes the immutable pin record selected by those bytes. Every non-null `*_reference_or_null` is exactly `path`, `sha256`, `device_id`, and `inode`. `request_id_or_null` is null, `operation` is `resolve-active`, `synthetic_only` is true, and unresolved history returns `FAIL_CLOSED_ACTIVE_UNRESOLVED` with nullable references. The result is invocation-scoped evidence, not a capability grant, session token, application policy, or clinical/runtime authorization.

## 18. Historical rotation and compromise

New mutations require every current pin, policy, trust store, roster, enrollment, key, request, evidence item, subject, decision, and attestation valid at verification and reservation time. Historical active-chain resolution proves that each dependency was valid at `reserved_at` and remains byte-for-byte reachable through append-only predecessor chains. Ordinary rotation after signing does not invalidate a valid historical transaction.

A compromise record has an independently approved `compromise_effective_at`. If it is at or before `signed_at`, the attestation and dependent active chain are invalid. If it is after `signed_at`, it blocks new use but preserves prior history. Missing historical bytes, predecessor forks, rewritten enrollment, or uncertain compromise time fails closed. Compromise of root plus both human signers remains a catastrophic trust-root compromise and is not disproved by host receipts.

## 19. Required implementation and acceptance tests

This Markdown test is a prose/contract lock only. It cannot establish native syscall behavior, macOS ACL enforcement, code signing, root separation, fsync durability, or host provisioning. A separately reviewed machine contract, native implementation, and provisioned-macOS acceptance suite are mandatory before any canonical operation.

Deterministic local tests SHALL cover every closed top-level and nested schema; schema-reference closure; duplicate/unknown/missing fields; exact order/path/operation tables; v1 attestation rejection; strict SSHSIG caps and valid-SHA256 rejection; signature/signature-receipt/signer/subject binding; all pairwise operation and ID substitutions; Git environment/object-state negatives; immutable/current pin rotation; replay and receipt continuity; every reason/status/phase; all partial-state rows; two reconcilers; crashes; and no-write ordering.

Native provisioned-host acceptance SHALL cover actual root:wheel `0700` release execution; non-root kernel and internal denial before state open; `SecCodeCopySelf` and validity checks; architecture/CDHash/library/hardened-runtime checks; empirical `acl_set_fd_np` then `acl_get_fd_np` round trips for both exact object tables; newly created child inheritance replacement before first content write; direct repository-writer/application-runtime/selector writes denied; ancestor and leaf races; fd leakage; fixed Git/OpenSSH process bounds; real fsync/readback/rename behavior; same-device authority enforcement; cross-device replay receipts; two-process reconciliation; and clean-host unprovisioned failure. Fixed public-vector self-test and provisioning-only ephemeral-sign smoke must both be reachable.

## 20. Threat and consequence summary

The design removes repository-writer, application-runtime, operator-service, IPC, and caller-token paths from canonical authority. It deliberately increases operational friction: every governance/ledger operation requires an offline root invocation and independently signed sealed request. A compromised root, compromised signed native binary, or simultaneous owner/reviewer compromise remains catastrophic. Root-only operation is accepted because this is a rare governance transition, not application runtime functionality.

## 21. Synthetic and no-authority boundary

All data and operations remain synthetic. Neither this design, its future approval, provisioning, a signature, a replay burn, a pointer, nor an active-chain result authorizes real patient data; live BPJS, VClaim, SATUSEHAT, payment, LIS, PACS, or device integration; application deployment; hosted migration; a capability disposition; domain acceptance; G0 closure; or G3 acceptance. Klaim, BPJS, and Apotek remain Soon until separately built and authorized. The failed Antrean/work-queue MVP remains excluded under DEC-013.

## 22. Secret and history rules

Public keys, fingerprints, signatures, hashes, nonces, and non-secret lifecycle metadata may be retained. Private keys, passwords, tokens, seed phrases, signing-agent sockets, recovery secrets, connection strings, patient data, and credentials never enter repository files, inbox bundles, receipts, logs, test fixtures, or stdout. Provisioning-only ephemeral private keys exist only in the root temporary directory described in Section 12 and are never canonical evidence. Pins, policies, rosters, imports, replay records, receipts, decisions, and predecessors are append-only and never rewritten.

## 23. Post-approval sequence

1. Record exact attributable product-owner approval of Section 24 and a separate independent technical/security review of this exact SHA; neither activates Gate-B.
2. Create a separately reviewed closed machine contract and reason-code contract as local working-tree artifacts only while canonical operation remains blocked; commit, push, pull request creation, release, deployment, and migration remain separately unauthorized.
3. Implement fixture-only native contracts and tests without provisioning the canonical host.
4. Independently review the implementation.
5. Separately authorize root host provisioning and run native macOS acceptance.
6. Separately enroll human-controlled owner and reviewer keys.
7. Refresh time-bound evidence and obtain one exact request, decision, subject, and two attestations.
8. Invoke one root-only operation under that separate authority.

## 24. Exact approval block

> Approve ADR-019 exactly as written as the append-only Gate-B root-only offline host-trust-boundary successor to the bound ADR-018 and external-trust proposal. Authorize only publication in the limited sense of local working-tree artifact creation of a corresponding closed machine contract, and later local working-tree fixture implementation and deterministic tests after a separate independent technical/security review of this exact ADR passes. Commit, push, pull request creation, release, deployment, and migration remain unauthorized. Keep canonical mutation and resolution, request import, host provisioning, human key enrollment, consumer activation, capability or slice implementation, real patient data, live integration, domain acceptance, G0 closure, and G3 acceptance unauthorized until their own later prerequisites and decisions are satisfied. This approval has no activation effect and does not change the current all-false Gate-B authorization state.

Approval must be a new immutable attributable artifact binding this file's exact SHA-256 plus the exact ADR-018, Gate-A adoption-decision, and external-trust-proposal SHA-256 values in Section 1. It must also bind the exact final-byte SHA-256 of `docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_HOST_TRUST_BOUNDARY_APPROVAL_DECISION_DRAFT_2026-08-29.json`; that pending draft cannot embed its own SHA-256, so only the later successor approval artifact supplies this binding.

The successor actor must exactly match the adopted Gate-A decider: identity `Daniel Happy Putra`, capacity `product_owner`, and decision-reference prefix `codex_thread:01a02b58-641d-7090-aad4-00871c7ddf47#decision-message-sha256:`. A host/platform task record outside repository-writer control must prove that the exact pending-draft path and SHA-256 plus the exact requested reply were presented before the actor authored the approval, and must supply the exact whole-message bytes and their SHA-256. The successor must bind that external record and use non-null RFC 3339 timestamps satisfying `request_presented_at < source_message_at < recorded_at < effective_at`. `recorded_at` is the host recorder's observation time and `effective_at` is the host recorder's first effect time after exact approval plus bound review pass; neither is an actor approval event. A different actor, capacity, task/thread prefix, message byte, message hash, request context, timestamp order, null field, or unverifiable external record fails closed. Repository content, commit authorship, and a copied reply are not attribution.

The future immutable successor approval SHALL contain exactly:

`artifact_type`, `schema_version`, `artifact_id`, `status`, `effect`, `data_boundary`, `draft_reference`, `source_bindings`, `independent_review_reference`, `actor`, `decision_reference`, `decision_message`, `decision_message_encoding`, `decision_message_sha256`, `external_task_record_reference`, `request_presented_at`, `source_message_at`, `recorded_at`, `effective_at`, `recorded_at_basis`, `effective_at_basis`, `conditions`, `approved_scope`, `authorization`, `immutability`, and `secret_handling`.

Closed values are `artifact_type: g0_governance_v2_host_trust_boundary_approval_decision`, `schema_version: 1`, `status: approved_exact_with_required_review_pass`, `effect: authorizes_local_working_tree_contract_fixture_and_tests_only`, `data_boundary: synthetic_only`, `decision_message_encoding: exact_utf8_no_trailing_newline`, `recorded_at_basis: host_recorder_observed_resolved_external_record`, `effective_at_basis: host_recorder_first_effect_after_exact_approval_and_bound_review_pass`, and `conditions: []`. `draft_reference` is exactly `path`, `sha256`; each of the four byte-sorted `source_bindings` items is exactly `role`, `path`, `sha256`; `actor` is exactly `identity`, `authority_capacity`; and `independent_review_reference` uses the separate hash-addressed schema below.

`approved_scope` SHALL contain exactly `closed_machine_contract_local_creation`, `local_fixture_implementation`, and `local_deterministic_tests`. The contract value is `authorized_local_working_tree_after_exact_approval`; the fixture and test values are `authorized_local_working_tree_after_exact_approval_and_review_pass`. `authorization` SHALL contain exactly `closed_machine_contract_local_creation`, `local_fixture_implementation`, `local_deterministic_tests`, `canonical_mutation`, `canonical_resolution`, `request_import`, `host_provisioning`, `human_key_enrollment`, `consumer_activation`, `capability_disposition`, `slice_implementation`, `commit`, `push`, `pull_request`, `release`, `deployment`, `migration`, `real_patient_data`, `live_integration`, `domain_acceptance`, `g0_closure`, and `g3_acceptance`. The first three are true only in this reviewed successor profile; every other value is exactly false. `immutability` is exactly `record_mutable`, `correction_method`, with false and `append_new_hash_bound_successor`; `secret_handling` is exactly `credentials_permitted`, `tokens_permitted`, `private_keys_permitted`, `connection_strings_permitted`, all false.

The hash-addressed external task-record reference SHALL contain exactly:

`artifact_type`, `schema_version`, `provider_id`, `task_id`, `message_id`, `event_id`, `account_id`, `record_sha256`, and `resolver_id`.

Closed reference values include `provider_id: codex`, `task_id: 01a02b58-641d-7090-aad4-00871c7ddf47`, and `message_id` byte-equal to `decision_message_sha256`. `account_id` is byte-equal to the resolved `author_account_id`; the decision reference is the fixed task prefix plus that same message ID.

The resolver API's verified result SHALL contain exactly:

`verification_status`, `resolver_id`, `external_verification_identity_id`, and `resolved_envelope`.

It requires `verification_status: VERIFIED`; the approval resolver identity is pinned out of band as `codex-platform-trust-anchor-v1`. Returning an envelope without this verified result is rejection.

The trusted resolver's resolved envelope SHALL contain exactly:

`artifact_type`, `schema_version`, `record`, `record_sha256`, and `verified_result_metadata`.

The resolved `record` SHALL contain exactly:

`provider_id`, `task_id`, `message_id`, `event_id`, `account_id`, `author_role`, `author_account_id`, `author_identity_id`, `author_display_identity`, `request_draft_path`, `request_draft_sha256`, `requested_reply_sha256`, `request_presented_at`, `whole_message_bytes`, `whole_message_sha256`, and `source_message_at`.

Approval `verified_result_metadata` SHALL contain exactly:

`resolver_id`, `resolver_kind`, `verification_method`, `external_verification_identity_id`, `verification_evidence_sha256`, `verified_reference_sha256`, `verified_record_sha256`, `account_binding_status`, `bound_author_account_id`, `bound_author_identity_id`, `bound_gate_a_identity`, `bound_gate_a_capacity`, and `verified_at`.

The author is closed to `author_role: user`; author display identity and bound Gate-A identity are `Daniel Happy Putra`, bound capacity is `product_owner`, and the resolver-attested author account/identity values must cross-equal the record and top-level actor. Assistant, service, system, different-account, different-identity, or unbound authors fail closed.

The independent-review reference SHALL contain exactly:

`artifact_type`, `schema_version`, `review_id`, `record_sha256`, and `resolver_id`.

Its resolved review `record` SHALL contain exactly:

`review_id`, `reviewer_identity_id`, `reviewer_display_identity`, `reviewer_capacity`, `reviewer_kind`, `reviewer_author_role`, `reviewer_account_id`, `executor_identity_id`, `reviewed_adr_sha256`, `reviewed_draft_sha256`, `review_bytes`, `review_sha256`, `verdict`, and `reviewed_at`.

Review `verified_result_metadata` SHALL contain exactly:

`resolver_id`, `resolver_kind`, `verification_method`, `external_verification_identity_id`, `verification_evidence_sha256`, `verified_reference_sha256`, `verified_record_sha256`, `verified_reviewer_identity_id`, `verified_reviewer_account_id`, `verified_reviewer_capacity`, `verified_reviewer_kind`, `reviewer_eligibility_status`, `verified_executor_identity_id`, and `verified_at`.

The review resolver/trust anchor is separate and pinned out of band as `independent-review-platform-trust-anchor-v1`. It must return `VERIFIED` through the same closed verified-result wrapper. The resolver derives and attests reviewer identity, reviewer account, reviewer capacity, reviewer kind, reviewer eligibility, and actual executor identity rather than accepting those facts from repository-authored bytes. Closed verified values are `verified_reviewer_capacity: independent_technical_security_reviewer`, `verified_reviewer_kind: independent_not_product_owner_or_executor`, and `reviewer_eligibility_status: VERIFIED_ELIGIBLE_INDEPENDENT_REVIEWER`. The verified reviewer identity, account, capacity, kind, and executor identity must byte-equal their review-record counterparts. The review record binds this ADR and pending-draft hashes, exact review bytes and SHA-256, `verdict: PASS`, and author role `reviewer`. Reviewer identity and account must differ from product owner identity and account, and reviewer identity must differ from `verified_executor_identity_id`; a review record cannot override the actual resolver-verified executor. Missing, unresolved, authenticated-but-ineligible, self-authored, owner-authored, executor-authored, forged, hash-mismatched, cross-equality-mismatched, wrong-target, wrong-verdict, or time-invalid review fails closed.

The successor validator must receive both trusted resolvers out of band as host/platform interfaces or externally keyed attestation verifiers. It calls `resolve_verified`, rejects any non-`VERIFIED` result, resolves each exact reference, requires every identifier to match, canonicalizes each `record` with `canonical_json_lf`, verifies its `record_sha256`, canonicalizes each exact reference and verifies `verified_reference_sha256`, and verifies each separately pinned external identity and evidence. Approval top-level `request_presented_at`/`source_message_at` cross-equal the resolved approval record. Here `approval_verified_at` and `review_verified_at` mean the respective verified-result metadata `verified_at` fields. Times require `request_presented_at < source_message_at <= approval_verified_at <= recorded_at < effective_at` and `reviewed_at <= review_verified_at <= recorded_at < effective_at`, all explicit-offset RFC 3339. Malformed, future-after-recording, or reordered time fails closed. An offline, embedded, repository-authored, self-claimed, missing, differently addressed, locally fabricated, byte-perfect but unresolved, unverified, or signature/attestation-identity-mismatched record fails closed.

Unknown, duplicate, or missing fields at the successor top level or any nested reference, source, review, actor, scope, authorization, immutability, secret, resolved-record, or resolver-verification level fail closed. Conflicting status, effect, scope, conditions, authorization, hash, identity, or time also fails closed.

Do not edit this ADR or the pending draft to mark either accepted; create a new local working-tree successor decision artifact under the same no-commit/no-push/no-PR/no-release/no-deploy/no-migrate boundary.
