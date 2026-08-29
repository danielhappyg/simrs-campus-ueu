# G0 Governance v2 External Trust Provisioning Proposal

**Status:** `PROPOSAL / NOT APPROVED / NOT AUTHORITATIVE / NO EFFECT`

**Date:** 2026-08-29
**Boundary:** local governance-control implementation for synthetic-only SIMRS Campus UEU
**Current canonical state:** `unprovisioned_blocked_external_attestation_required`

## 1. Effect before a separate approval

None. This document is a design proposal and an approval prompt, not an approval, trust store, key enrollment, signature, attestation, operation decision, selection, journal entry, pointer, or activation instruction.

Creating, reviewing, testing, or committing this proposal does not:

- activate governance v2 or select a governance consumer;
- authorize candidate retention, a capability disposition, slice or workflow implementation, deployment, hosted migration, real patient data, live integration, domain acceptance, G0 closure, or G3 acceptance;
- provision a key, approve an identity, authorize a signer, invoke the selector, or change the current all-false Gate-B authorization state; or
- weaken the canonical selector's rejection before lock acquisition, filesystem probing, recovery-marker handling, or any write.

The adopted Gate-A decision remains limited to local governance-v2 implementation. A future approval of this proposal would authorize only the bounded verifier and trust-provisioning work stated in Section 14. Every canonical activate, rollback, disable, or recover operation would still require its own fresh, attributable, externally attested product-owner decision and independent technical/security reviewer attestation.

## 2. Problem and security objective

The current contract correctly says that repository-local approval artifacts are insufficient for a canonical operation. A person who can edit the repository can otherwise create both the claimed approval record and the code or data that accepts it.

The proposed control places trusted public keys and their lifecycle policy outside the repository. Repository writers may prepare unsigned subjects, but cannot mint a valid product-owner or independent-review attestation without the corresponding external private key. The selector remains offline and uses no institutional PKI, network service, database, queue, or remote timestamp service.

## 3. Closed trust-store boundary

The canonical trust store SHALL be a host-provisioned regular file outside the repository checkout and outside every candidate bundle. Its exact absolute path SHALL be resolved through an out-of-repository current-pin record owned by the host or operating-system administrator. The current pin, immutable pins, launcher, verifier/mutator bundle, trust store, allowed-signers file, enrollment and authorship records, replay-reservation directory, and canonical authority paths SHALL NOT be writable by the repository writer, application runtime, or unprivileged repository selector identity. None of their paths may be accepted from a command-line option, repository file, candidate artifact, operation decision, or ordinary process environment variable.

Canonical execution and authoritative active-chain reads SHALL enter through one fixed, root/host-administrator-owned launcher/verifier/mutator executable outside the repository. The single statically bound process image performs verification, locking, replay burn, authority mutation, readback, and authoritative active-chain reads; there is no launcher-to-mutator `exec`, plug-in, dynamic-library, socket, helper, or token handoff. Direct invocation of the repository selector remains validation-only and cannot mutate or authoritatively resolve the canonical checkout. The executable validates its own externally pinned bytes, then validates the exact canonical repository root and exact SHA-256 values of the repository selector, contract, and verification source before opening protected authority and replay descriptors and entering its internal mutator phase. It rejects a modified, replaced, symlinked, path-drifted, or hash-drifted repository selector, contract, verification source, or launcher/verifier/mutator. Re-validating repository-controlled code from that same code is insufficient.

The root/host administrator SHALL own the canonical pointer, selection, activation-decision, journal, lock, recovery-marker, and replay paths and SHALL deny write access to the repository writer, application runtime, repository selector identity, group, and other users. The single host-owned process opens protected file or directory descriptors itself, verifies their device/inode/path identities, and never accepts an authorization token, descriptor number, path, or capability through CLI arguments, environment variables, repository files, sockets, or temporary files. Before invoking any child process such as `/usr/bin/ssh-keygen`, it marks every authority/replay descriptor close-on-exec and proves the child receives none of them; there is no path or CLI fallback. It closes descriptors on every failure. Permission-level tests SHALL prove that direct repository-selector invocation and direct writes, renames, links, unlinks, or replacement of every canonical authority path fail for the repository-writer identity, while the pinned process can write only the enumerated paths after authorization.

Provisioning SHALL require all of the following:

1. Every external file, launcher, verifier member, replay record, current-pin member, and parent is a regular file or real directory, never a symlink.
2. They are owned by the named host trust administrator or operating-system administrator and are not writable by the repository writer, application runtime, selector execution identity, group, or other users.
3. The trust-store SHA-256, `trust_store_id`, and monotonically increasing `generation` are pinned together outside the repository.
4. The trust store contains public keys only. Private keys, passwords, tokens, seed phrases, connection strings, signing-agent sockets, and recovery secrets are forbidden.
5. The JSON manifest, OpenSSH allowed-signers companion, launcher, verifier/mutator bundle, external authorship/approval roster, protected authority-path policy, replay store, and immutable pin are provisioned and pinned as one generation. A canonical operation rejects a missing, unreadable, permission-invalid, path-drifted, hash-drifted, downgraded, expired, forked, or schema-invalid member before lock, probe, marker handling, or write.

Portable read-only repository validation remains available without this file. Canonical mutation remains unavailable until the external trust store has been separately approved and provisioned.

## 4. Trust-store schema and pinning

The implementation SHALL define a closed JSON schema with exactly these top-level fields:

`artifact_type`, `schema_version`, `trust_store_id`, `generation`, `status`, `valid_from`, `expires_at`, `predecessor_sha256`, `allowed_signers`, `keys`, and `policy`.

Required semantics:

- `artifact_type`: `g0_governance_v2_external_trust_store`.
- `schema_version`: `1`.
- `status`: `active`.
- `generation`: a positive integer that must be greater than its predecessor generation.
- `predecessor_sha256`: `null` only for the separately approved bootstrap generation; otherwise the SHA-256 of the exact predecessor bytes.
- `valid_from` and `expires_at`: RFC 3339 timestamps with explicit offsets; the store is accepted only while `valid_from <= now < expires_at`.
- `allowed_signers`: the exact external absolute path and SHA-256 of a regular, non-symlink OpenSSH allowed-signers file under the same ownership and write restrictions. Each active key has exactly one line using its stable identity, the exact namespace `simrs-campus-ueu-g0-governance-v2-gate-b`, and its exact `ssh-ed25519` public key; comments, wildcard principals, multiple namespaces, certificate-authority options, and unknown options are forbidden.
- `keys`: exactly one active product-owner key and at least one active independent technical/security reviewer key. Additional roles and unknown fields fail closed.
- `policy`: the exact algorithm, signer-separation, expiry, revocation, rotation, and replay rules defined by this proposal.

The nested `allowed_signers` object SHALL contain exactly:

`path`, `sha256`, `format`, and `namespace`.

The external immutable pin SHALL contain exactly:

`artifact_type`, `schema_version`, `pin_id`, `status`, `host_identity`, `trust_store_path`, `trust_store_sha256`, `trust_store_id`, `trust_store_generation`, `allowed_signers_path`, `allowed_signers_sha256`, `launcher_path`, `launcher_sha256`, `selector_path`, `selector_sha256`, `contract_path`, `contract_sha256`, `verification_source_path`, `verification_source_sha256`, `authorship_roster_path`, `authorship_roster_sha256`, `authority_paths_policy_path`, `authority_paths_policy_sha256`, `replay_directory_path`, `replay_directory_device_id`, `replay_directory_inode`, `replay_genesis_record_sha256`, `replay_policy_path`, `replay_policy_sha256`, `ssh_keygen_path`, `ssh_keygen_version`, `provisioner_identity`, `provisioned_at`, `valid_from`, `expires_at`, `predecessor_pin_path`, `predecessor_pin_sha256`, and `rotation_reason`.

The protected authority-path policy SHALL contain exactly:

`artifact_type`, `schema_version`, `policy_id`, `status`, `canonical_root`, `authority_paths`, `owner_identity`, `writer_identity`, `directory_mode`, `file_mode`, `valid_from`, and `expires_at`.

The protected replay policy SHALL contain exactly:

`artifact_type`, `schema_version`, `policy_id`, `status`, `replay_directory_path`, `device_id`, `inode`, `genesis_record_path`, `genesis_record_sha256`, `chain_algorithm`, `writer_identity`, `directory_mode`, `file_mode`, `valid_from`, and `expires_at`.

Every `authority_paths` entry SHALL contain exactly:

`path`, `object_type`, `allowed_operations`, `owner_identity`, `writer_identity`, `device_id`, `inode`, `creation_parent_path`, `creation_parent_device_id`, `creation_parent_inode`, `directory_mode`, and `file_mode`.

`authority_paths` is a unique UTF-8 byte-sorted array containing exactly these repository-relative paths and no others:

| Path | Object type | Exact allowed operations |
| --- | --- | --- |
| `docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_POINTER.json` | `regular_file_or_absent` | `read`, `exclusive_stage_sibling`, `fsync_stage`, `atomic_replace`, `fsync_parent` |
| `docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_POINTER_RECOVERY_REQUIRED.json` | `regular_file_or_absent` | `read`, `exclusive_create`, `fsync_file`, `unlink_after_recovery`, `fsync_parent` |
| `docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_SELECTIONS` | `directory` | `read`, `exclusive_create_child`, `fsync_child`, `fsync_directory` |
| `docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ACTIVATION_DECISIONS` | `directory` | `read`, `exclusive_create_child`, `fsync_child`, `fsync_directory` |
| `docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_JOURNAL` | `directory` | `read`, `exclusive_create_child`, `fsync_child`, `fsync_directory` |
| `docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_SELECTION.lock` | `regular_file` | `read`, `open_existing`, `advisory_exclusive_lock` |

`object_type` accepts only `directory`, `regular_file`, or `regular_file_or_absent`. `allowed_operations` must equal the table's ordered closed list; no wildcard, parent write, recursive mutation, arbitrary child path, chmod, chown, truncate, or delete operation is permitted. Existing objects require exact device and inode with null creation-parent identity fields. An absent future file requires null device/inode and the exact protected phase-0 creation-parent path/device/inode. `owner_identity` and `writer_identity` must exactly equal the immutable pin's host-administrator owner and pinned launcher/verifier/mutator identity; self-declared or additional identities fail. Modes are four-character ASCII octal strings: directories exactly `0700`, regular files exactly `0600`; symbolic, decimal, omitted, group/world-writable, setuid/setgid, ACL-expanded, or unknown modes fail.

The only permitted replay `chain_algorithm` is `sha256_domain_separated_reservation_chain_v1`. The genesis record begins with the exact ASCII domain `SIMRS-CAMPUS-UEU/G0-GOVERNANCE-V2/REPLAY-CHAIN/v1`; each reservation is canonical UTF-8 JSON with LF and one final LF, the fixed reservation artifact type, sequence, and exact predecessor record path/SHA-256. The next-link value is SHA-256 over the predecessor reservation's exact file bytes. No alternate digest, canonicalization, domain, encoding, implicit field, or algorithm negotiation is accepted.

Both policy paths are absolute, outside repository-writer control, direct files under the pinned host policy directory, and are validated for exact bytes, ancestry, owner, mode, device, inode, validity, and SHA-256. Omission, substitution, relocation, symlink, ownership/mode drift, or a hash without its resolvable policy path fails closed.

The fixed external current-pin record SHALL contain exactly:

`artifact_type`, `schema_version`, `current_pin_path`, `current_pin_sha256`, and `updated_at`.

The current-pin record resolves exactly one immutable direct-child pin path and SHA-256. The pin resolves one trust-store generation, allowed-signers file, launcher, host-owned verifier/mutator, canonical repository selector, contract, verification source, externally authenticated authorship/approval roster, protected authority-path policy, and protected replay store. Path traversal, symlinks, duplicate pin IDs or generations, missing predecessors, generation gaps, forks, hash drift, predecessor/current reversal, and a current pointer to a non-tip pin fail closed.

Every pin uses explicit-offset RFC 3339 `valid_from` and `expires_at`, is valid only while `valid_from <= now < expires_at`, and has a maximum lifetime of 2,592,000 seconds. A successor generation is exactly predecessor generation plus one, hash-binds its predecessor path and exact bytes, and may overlap the predecessor for at most 3,600 seconds. The separately approved bootstrap alone has null predecessor fields. At `valid_from` the new pin is eligible; at `expires_at` it is invalid. Boundary tests SHALL cover exact start, one second before expiry, exact expiry, maximum lifetime, maximum plus one second, zero/negative lifetime, maximum overlap, excessive overlap, stale-current, fork, gap, and rollback attempts.

Replacement is append-only: a new pin and trust-store generation supersede but never rewrite or delete predecessor bytes. Every successor pin SHALL retain the identical replay-directory absolute path, device identity, inode, genesis-record SHA-256, and replay-policy SHA-256. The replay directory contains one immutable hash chain rooted at the pinned genesis record; every reservation binds its predecessor record SHA-256, and the verifier must walk the complete chain. An alternate, empty, replaced, disappeared, cross-device, inode-changed, forked, truncated, or non-tip replay store fails closed. Rotation cannot reset replay history. The root/host administrator atomically replaces the current-pin record and durably synchronizes its file and directory; the launcher re-reads and re-hashes it immediately before reservation and authority mutation.

## 5. Public-key records and signer separation

Each closed key record SHALL contain exactly:

`key_id`, `role`, `identity`, `capacity`, `algorithm`, `public_key_encoding`, `public_key`, `openssh_fingerprint_sha256`, `enrollment_record_path`, `enrollment_record_sha256`, `custodian_identity`, `custody_method`, `custody_domain`, `separation_group_id`, `status`, `not_before`, `not_after`, `revoked_at`, `revocation_reason`, and `supersedes_key_id`.

Each immutable external enrollment record SHALL contain exactly:

`artifact_type`, `schema_version`, `enrollment_id`, `status`, `identity`, `display_name`, `role`, `capacity`, `key_id`, `openssh_fingerprint_sha256`, `custodian_identity`, `custody_method`, `custody_domain`, `separation_group_id`, `approved_by_host_trust_administrator`, `approved_at`, `valid_from`, `expires_at`, `predecessor_enrollment_path`, and `predecessor_enrollment_sha256`.

The two accepted roles are exactly:

- `product_owner`; and
- `independent_technical_security_reviewer`.

The product owner and independent reviewer SHALL have distinct stable identities, `key_id` values, public-key fingerprints, custodians, custody domains, and separation-group IDs. The reviewer SHALL also be distinct from the implementation executor and all authors or approvers of the operation subject. One person, one key, an alias, a common custodian, a common custody domain, or two keys under common private-key custody cannot satisfy both roles.

`identity`, `capacity`, fingerprint, custodian, custody method, custody domain, and separation group must match the exact immutable external enrollment record path and SHA-256. Enrollment validity must cover signing and verification time. A repository author, commit author, test fixture, candidate manifest, or self-declared JSON field cannot enroll or elevate a signer.

Each externally authenticated authorship/approval roster SHALL contain exactly:

`artifact_type`, `schema_version`, `roster_id`, `status`, `canonical_commit_sha256`, `covered_artifacts`, `execution_identity`, `verified_author_identities`, `verified_approver_identities`, `host_trust_administrator_identity`, `verified_at`, `expires_at`, `predecessor_roster_path`, and `predecessor_roster_sha256`.

`covered_artifacts` is a closed, path-sorted array containing every subject, decision, contract, selector, verification-source, candidate, and semantic-evidence path, exact byte SHA-256, and externally verified stable author identities. The host trust administrator verifies it against protected review/approval records before provisioning and pins its exact path and SHA-256. A subject-bound artifact without exactly one roster entry, a missing real author/approver, duplicate path, hash drift, expired roster, or roster omission fails closed.

The future approval subject SHALL contain a closed `signer_separation` object with exactly:

`execution_identity`, `authorship_roster_path`, `authorship_roster_sha256`, `excluded_identity_set_sha256`, and `signer_separation_policy_sha256`.

The verifier SHALL ignore repository-authored author or approver arrays and recompute the excluded set from the pinned external roster: the executor, every externally verified author of every covered artifact, every externally verified approver, and the product owner. Its hash input is the ASCII domain line `SIMRS-CAMPUS-UEU/G0-GOVERNANCE-V2/EXCLUDED-REVIEWER-IDENTITIES/v1`, LF, each UTF-8 identity in byte-sort order followed by LF, and no other bytes. `excluded_identity_set_sha256` hashes those exact bytes. `signer_separation_policy_sha256` binds the exact external trust-store policy bytes defining this derivation. The independent reviewer identity, custodian, custody domain, and separation group must not collide with any externally derived excluded identity or the product-owner enrollment. Omission tests SHALL remove one real covered-artifact author and one approver from an otherwise valid roster and prove rejection.

## 6. Exact cryptographic and runtime profile

The version-1 signature path SHALL use OpenSSH SSHSIG with Ed25519:

- key type: Ed25519 OpenSSH public key, exactly `ssh-ed25519`;
- signature algorithm identifier: `openssh_sshsig_ed25519_sha512`;
- SSHSIG namespace: `simrs-campus-ueu-g0-governance-v2-gate-b`;
- SSHSIG message hash: SHA-512, selected explicitly as `hashalg=sha512` when signing;
- verifier: the externally pinned absolute executable `/usr/bin/ssh-keygen` using `ssh-keygen -Y verify` with the pinned external allowed-signers file, exact stable signer identity, exact namespace, and detached signature file;
- public-key encoding: one canonical OpenSSH `ssh-ed25519` public-key line without a comment;
- public-key fingerprint: the canonical OpenSSH `SHA256:<base64-without-padding>` fingerprint of the decoded public-key blob;
- subject, trust-store, allowed-signers, and signature-file hashes: SHA-256 over exact file bytes; and
- detached signature encoding: ASCII-armored OpenSSH SSH SIGNATURE with LF line endings and no surrounding bytes.

The exact payload bytes are streamed to standard input of `ssh-keygen -Y verify`; the verifier reads the already-retained detached signature file and creates no temporary file. No native Ruby Ed25519 API is assumed.

Before trust provisioning or contract enablement, the host SHALL pass a deterministic runtime capability test using the exact `/usr/bin/ssh-keygen` real path: confirm `ssh-keygen -Y sign` and `ssh-keygen -Y verify`, Ed25519 SSHSIG, the exact namespace, SHA-512 message hashing, allowed-signers restrictions, valid-signature acceptance, and tampered-message/signature/key rejection. The accepted executable real path and observed OpenSSH version are recorded in the external pin. Missing `ssh-keygen`, an unsupported `-Y` operation, version drift without re-provisioning, a failed self-test, or incompatible behavior keeps canonical mutation blocked.

Before invoking `ssh-keygen -Y verify`, the external verifier SHALL strictly decode the canonical armor and parse the SSHSIG binary envelope with bounded lengths. It requires magic `SSHSIG`, version `1`, an `ssh-ed25519` public-key blob equal to the enrolled key, the exact namespace, an empty reserved field, hash algorithm exactly `sha512`, an inner signature algorithm exactly `ssh-ed25519`, and no trailing or non-canonical bytes. Unknown, duplicate, truncated, oversized, reordered, or additional fields fail closed. Because OpenSSH also supports SSHSIG with SHA-256, deterministic negative tests SHALL generate a cryptographically valid Ed25519 SSHSIG using `hashalg=sha256` and prove that the strict parser rejects it before `ssh-keygen -Y verify` is called.

No RSA, ECDSA, DSA, PKCS#1, SHA-1, MD5, symmetric MAC, embedded repository key, SSH certificate, wildcard signer, algorithm negotiation, unknown algorithm, or fallback verifier is permitted.

## 7. Exact detached-attestation schema and signed payload

Each product-owner and reviewer attestation SHALL be a separate immutable JSON file with exactly:

`artifact_type`, `schema_version`, `attestation_id`, `role`, `key_id`, `algorithm`, `trust_store_id`, `trust_store_generation`, `trust_store_sha256`, `subject_path`, `subject_sha256`, `signed_at`, `expires_at`, `nonce`, `payload_encoding`, `payload_sha256`, `signature_path`, `signature_sha256`, and `signature_encoding`.

Required values include:

- `artifact_type`: `g0_governance_v2_external_detached_attestation`;
- `schema_version`: `1`;
- `algorithm`: `openssh_sshsig_ed25519_sha512`;
- `payload_encoding`: `utf8_lf_exact_v1`;
- `signature_encoding`: `openssh_sshsig_armored_lf`; and
- `nonce`: 32 lowercase hexadecimal characters generated by the external signer from a cryptographically secure random source.

The exact signed UTF-8 byte sequence, with LF line endings and one final LF, SHALL be:

```text
SIMRS-CAMPUS-UEU/G0-GOVERNANCE-V2/GATE-B-ATTESTATION/v1
role=<role>
key_id=<key_id>
trust_store_id=<trust_store_id>
trust_store_generation=<base-10 generation without leading zeroes>
trust_store_sha256=<64 lowercase hexadecimal characters>
subject_path=<repository-relative approval-subject path>
subject_sha256=<64 lowercase hexadecimal characters>
signed_at=<RFC 3339 timestamp with explicit offset>
expires_at=<RFC 3339 timestamp with explicit offset>
nonce=<32 lowercase hexadecimal characters>
```

`payload_sha256` is SHA-256 over exactly those bytes. OpenSSH SSHSIG signs and verifies exactly those bytes using the fixed namespace and SHA-512 message-hash profile. `signature_path` is a repository-relative regular, non-symlink file containing only the detached ASCII-armored SSHSIG bytes, and `signature_sha256` binds its exact bytes. Unicode normalization, whitespace trimming, reordered lines, CRLF conversion, implicit defaults, alternate timestamp spelling, or reserialization is forbidden. Copying a signature into the attestation JSON is also forbidden.

The subject SHALL be the exact immutable Gate-B operation-approval artifact, not this proposal, a draft, a commit, or the operation decision that references it. Before any signing is possible, the append-only contract revision SHALL introduce a new approval-subject schema version that contains the current closed approval fields plus exact `technical_evidence`, `operation_decision_contract`, and `signer_separation` objects. Contract 1.3 approval artifacts are not signature-eligible. The new subject therefore hash-binds the operation, environment, actor, attribution, conditions, time window, candidate or held selection, prior state, current contract, selector, verification source, local observation, canonical preflight, independent review, exact external authorship/approval roster path and SHA-256, the externally derived excluded-identity-set SHA-256, and the exact signer-separation policy SHA-256. The operation decision must independently hash-bind that approval subject and both detached attestations without creating a signature cycle.

## 8. Role-specific attestation meaning

The product-owner attestation means only: the named product owner approved the exact one-operation subject within its recorded conditions and time window.

The independent-reviewer attestation means only: the named independent technical/security reviewer verified the exact subject and its exact local observation, canonical preflight, candidate or held selection, validator contract, selector source, prior state, and failure boundaries. It does not substitute for product-owner authority.

Both attestations are mandatory for every canonical `activate`, `rollback`, `disable`, and `recover` operation. Neither attestation authorizes capability disposition, slice implementation, deployment, migration, real patient data, live integration, domain acceptance, G0 closure, or G3 acceptance.

## 9. Time, expiry, and replay controls

All timestamps SHALL be RFC 3339 with explicit offsets. The decision source message, operation approval, detached attestations, semantic evidence, keys, trust store, and external pin SHALL all be valid at verification time.

- The operation-decision window remains at most 7,200 seconds.
- Each attestation window is strictly positive and at most 7,200 seconds.
- `signed_at <= now < expires_at` is required for each attestation.
- The effective authorization expiry is the earliest expiry among the operation decision, approval, both attestations, all semantic evidence, both key records, trust store, and external pin.
- Evidence observation order remains local observation, canonical preflight, independent review, then the product-owner source message and attestations.
- Clock parsing errors, clock rollback detected within one invocation, future signatures, or an unavailable trustworthy host clock fail closed.

An attestation binds one exact subject hash and one role. Read-only pre-authorization rejects an attestation ID, nonce, decision reference, approval-subject hash, or operation-decision hash already present in an immutable reservation, successful, or failed-operation record. Pre-authorization rejection writes nothing. Reusing one role's attestation for the other role, another operation, another environment, another prior state, another candidate, another trust-store generation, or another expiry is invalid.

Each immutable one-use reservation record SHALL contain exactly:

`artifact_type`, `schema_version`, `reservation_id`, `sequence`, `predecessor_record_path`, `predecessor_record_sha256`, `status`, `effect`, `data_boundary`, `operation`, `environment`, `approval_subject_path`, `approval_subject_sha256`, `operation_decision_path`, `operation_decision_sha256`, `product_owner_attestation_id`, `product_owner_attestation_sha256`, `product_owner_nonce`, `reviewer_attestation_id`, `reviewer_attestation_sha256`, `reviewer_nonce`, `trust_pin_path`, `trust_pin_sha256`, `trust_store_generation`, `prior_state_sha256`, `reserved_at`, and `invocation_id`.

The only status is `burned_before_authority_mutation`; effect is `none_replay_prevention_only`; data boundary is `synthetic_only`. `sequence` is a positive base-10 integer. Sequence 1 binds the exact pinned genesis-record path and SHA-256; every later record has sequence exactly predecessor sequence plus one and binds the exact immediately preceding reservation path and SHA-256. The unique tip is the highest valid sequence reachable from genesis; gaps, duplicate sequences, alternate predecessors, forks, omissions, truncation, or a non-tip append fail closed. After successful read-only pre-authorization, the host-owned mutator acquires the canonical stable lock through the protected descriptor, rederives prior state, re-resolves the current external pin, revalidates every byte/hash/signature/expiry/separation/replay fact, and then uses exclusive create, file sync, exact readback/hash, directory sync, and predecessor-chain validation to durably burn the reservation in the pinned protected external replay directory. Only after that durable non-authoritative burn may filesystem probing, recovery-marker handling, selection, journal, or pointer mutation begin.

The reservation consumes the exact approval, decision, both attestation IDs, hashes, and nonces but confers no consumer or gate authority. A success or failure record cross-references it. Any failure or crash after durable reservation preserves the burn permanently and requires a new decision, approval subject, attestations, and nonces; it never deletes or reuses the reservation. A failure before reservation writes no replay record and may retry only if all evidence remains fresh. Concurrent invocations serialize on the stable lock; after the first burn, every contender revalidates under lock and rejects reuse.

## 10. Revocation, rotation, and compromise

Key and trust-store changes are append-only. Rotation creates a new key ID, a new trust-store generation bound to the predecessor SHA-256, and a new external pin. It never edits the predecessor bytes or silently reassigns an existing key ID.

Revocation requires `status: revoked`, `revoked_at`, and a non-empty `revocation_reason` in a new trust-store generation. A revoked, expired, not-yet-valid, unknown, duplicate, or superseded key cannot authorize a new operation. Administrative rotation after a valid historical signature preserves auditability of the completed transaction. A compromise revocation effective at or before `signed_at` invalidates that attestation and makes consumers fail closed pending an explicitly authorized recovery; no automatic rollback or replacement is allowed.

Loss of a private key does not justify a bypass. The public key may remain for historical verification, while a separately approved replacement key is enrolled. Trust-store expiry or loss leaves canonical mutation unavailable and active resolution fail-closed according to the future approved contract.

## 11. Canonical ordering and mutation boundary

For `local_canonical_checkout`, the future host-owned launcher and mutator SHALL use this exact phase order within one protected process boundary:

1. **External entry check, read-only:** the single host-owned launcher/verifier/mutator validates the fixed current-pin path, its own bytes, canonical root, exact selector, contract, verification-source, authorship-roster, authority-policy, replay-policy, and replay-store paths and hashes. It opens only the pinned protected descriptors and enters its internal mutator phase without `exec` or an external handoff. A modified repository selector is rejected and never executed; direct selector execution remains validation-only.
2. **Pre-authorization, read-only:** resolve and validate the pin chain, trust store, allowed signers, enrollment/custody records, external authorship/approval roster and complete artifact coverage, approval subject, operation decision, both detached attestations, strict SSHSIG envelopes and signatures, externally derived identity separation, time windows, candidate or held target, prior state, semantic evidence, complete replay chain, and absence from replay/history. Any failure through this phase writes nothing and does not acquire or create the stable lock, probe the filesystem, handle a recovery marker, or create a reservation or authority artifact.
3. **Serialization:** acquire the canonical stable lock. This coordination step has no authority effect.
4. **Under-lock revalidation, read-only:** re-resolve current pin and complete chain; rederive prior state; re-read and re-hash launcher, mutator, repository sources, external roster, approval, decision, attestations, signatures, enrollments, evidence, target, and complete replay history; recheck time, externally derived independence, replay, protected descriptor identity, file identity, and non-symlink facts. Drift releases the lock and writes no reservation or authority artifact.
5. **Durable one-use burn, non-authoritative:** exclusively create and durably synchronize the closed reservation record in the protected external replay directory. Re-read and hash it before proceeding. Collision or durability uncertainty fails closed. From this point the exact authorization is permanently consumed even if later work fails.
6. **Mutation capability and recovery checks:** run the filesystem write-capability probe and handle any recovery marker. Failure retains the burn but creates no selection, journal, or pointer.
7. **Authority transaction:** the host-owned mutator, never the repository selector, creates and synchronizes the immutable decision/selection and journal through protected descriptors, stages and atomically publishes the pointer, externally validates the authoritative active chain, and cross-references the burn. Existing atomicity and failure-recovery rules remain mandatory.

Isolated test fixtures remain available only behind the existing explicit test guard and cannot be used against the canonical checkout. Tests SHALL prove phase ordering with invocation counters and failure hooks: no pre-authorization write or launcher bypass; modified selector/contract/verification-source rejection before any mutator execution; direct repository-selector mutation and direct authority-path write/rename/link/unlink failures under the repository-writer identity; authority-path omission, addition, duplicate, traversal, wrong object type, operation widening, wrong owner/writer, unsafe mode, policy substitution, and replay-algorithm downgrade rejected; externally verified active-chain reads; no reservation before lock; under-lock replay recheck; durable reservation before any probe, marker, selection, journal, or pointer; alternate, empty, replaced, disappeared, cross-device, inode-changed, forked, truncated, and rotation-reset replay stores rejected; omission of one real author or approver from the external roster rejected; two concurrent invocations produce one burn and one replay rejection; crash before burn permits only a still-fresh retry; crashes immediately after burn, during probe, and before/after authority writes retain the burn and reject replay.

## 12. Recovery and rollback impact

External trust does not create an emergency bypass. Rollback, disable, and both recovery outcomes remain canonical mutations and therefore require fresh product-owner and independent-review attestations over their exact operation facts.

A missing or corrupt pointer, recovery marker, compromised key, unavailable trust store, or expired attestation cannot activate a candidate or restore a predecessor by itself. Until a fresh valid operation is separately authorized, consumers remain held, disabled, unresolved, or otherwise fail closed, and project G0 and G3 remain `OPEN`.

Historical selections, journals, decisions, attestations, trust-store generations, and pins are immutable evidence. Recovery may reference them for reconstruction, but none confers new authority.

## 13. Threat model and required controls

| Threat | Required fail-closed control |
| --- | --- |
| Repository writer fabricates an approval | Require valid signatures from externally enrolled private keys over the exact subject hash |
| Repository writer substitutes a trust store or path | Out-of-repository path pin, ownership/mode checks, exact SHA/ID/generation pin, no CLI/repository/environment override |
| Repository writer modifies its selector or validator | Host-owned pinned launcher executes only a host-owned verifier/mutator after exact selector, contract, and verification-source hash checks; repository selector is validation-only |
| Repository writer bypasses the launcher or writes authority paths directly | Root/host-owned paths deny writes; only the pinned mutator receives protected descriptors and performs canonical reads or mutations |
| Repository roster omits a real author or approver | Verifier derives separation only from the pinned external roster and requires complete exact-hash coverage of every bound artifact |
| Same signer claims both roles | Distinct identity, key ID, fingerprint, custody, capacity, and reviewer-separation checks |
| Signature downgrade or ambiguity | One closed OpenSSH SSHSIG/Ed25519/SHA-512 profile and namespace, exact payload bytes, no negotiation or fallback |
| Valid SSHSIG uses SHA-256 | Strict envelope parser rejects non-`sha512` before invoking `ssh-keygen -Y verify` |
| Replay across operations or state | Pinned replay-store identity and complete hash chain plus under-lock revalidation and durable non-authoritative burn before authority mutation |
| Stale, revoked, or compromised key | Validity checks, append-only revocation/rotation, compromise-effective-time handling |
| Clock manipulation | Explicit-offset timestamps, bounded TTL, future/rollback rejection, unavailable-clock failure |
| Symlink, permission, or time-of-check/time-of-use swap | Secure ancestry and regular-file checks plus identity/hash recheck before mutation |
| Secret disclosure | Public keys and signatures only; reject private keys, credentials, tokens, and connection strings |
| Missing or incompatible verifier | Exact `/usr/bin/ssh-keygen` path, pinned runtime version, deterministic SSHSIG self-test, no fallback |
| Trust service or network outage | No network dependency; missing local external trust state fails closed |
| Recovery used to bypass authority | Fresh dual attestations for rollback, disable, and recover; no emergency override |

Residual risks SHALL be documented before approval. Compromise of both external signer environments can mint both required attestations, and compromise of the host trust administrator can replace protected executables/trust state or directly alter authority storage; these are catastrophic trust-root compromises, not fail-closed cases. Mitigation requires organizational separation, protected administrator credentials, tamper-evident host/audit evidence, incident recovery, and, if this residual risk is unacceptable, a separately administered hardware or transparency-witness decision before provisioning. Malicious host-clock administration within the bounded window can falsify freshness. Deletion of external public trust material causes denial of service and should fail closed. Suspected compromise requires held or failed-closed resolution until independently reviewed; no claim of integrity or absence of unauthorized authority may be made from the affected host alone.

## 14. Proposed implementation and test sequence

No step may begin until the preceding decision or evidence requirement is satisfied:

1. Obtain an attributable product-owner approval of this exact proposal and an independent technical/security review of the design. This is not Gate-B operation approval.
2. Prove the exact host OpenSSH SSHSIG capability and negative self-tests, then publish an append-only contract revision defining the closed launcher, host-owned verifier/mutator, protected authority-path policy, trust-store, allowed-signers, enrollment, external authorship/approval roster, external current-pin and immutable-pin, replay-store continuity, attestation, signer-separation, one-use reservation, reason-code, runtime, and operation-decision reference schemas. Preserve historical contract bytes and keep canonical activation blocked.
3. Implement the host-owned launcher and verifier/mutator, strict SSHSIG envelope parser, read-only trust/enrollment/roster verification, protected-descriptor boundary, externally verified active reads, under-lock replay revalidation, durable reservation burn, and authority transaction in the exact Section 11 order. Make the repository selector validation-only. Do not provision production signer material in repository fixtures.
4. Add deterministic unit, negative, mutation-order, launcher/source-hash, direct-selector/direct-write permission, active-read, closed authority-path set/type/operation/identity/mode, protected-policy substitution, path-safety, enrollment/custody, external-roster omission, signer-separation, namespace, algorithm and replay-algorithm downgrade, valid-SHA256-SSHSIG rejection, runtime-drift, pin-boundary, expiry, replay-store substitution/continuity, concurrency, rotation, revocation, compromise, crash/fault-injection, and isolated-fixture tests. Test keys are clearly marked fixtures and rejected by canonical identity and path guards.
5. Have the externally named product owner and independent reviewer separately enroll keys and immutable custody/separation records. A host trust administrator provisions the public-only trust store, allowed signers, launcher, host-owned verifier/mutator, protected authority paths, external authorship/approval roster, current pin, immutable pin, and protected replay directory outside repository-writer control; independently verify exact paths, ownership, modes, filesystem identities, SHA-256 values, IDs, generations, replay genesis/chain, roster coverage, enrollment records, excluded identities, and fingerprints.
6. Run a read-only canonical trust preflight. Refresh candidate, local observation, canonical filesystem preflight, independent review, and prior-state evidence when stale or drifted.
7. Obtain a fresh exact one-operation product-owner decision and fresh external attestations within the bounded time window. A different operator verifies the complete pre-mutation receipt.
8. Invoke the selector only under a separate explicit operation authorization. Deployment, migration, capability work, real data, live integration, domain acceptance, G0 closure, and G3 acceptance remain separate.

## 15. Decision options

Record exactly one in a new immutable attributable decision artifact:

- [ ] **Approve as written.** Authorize only the implementation, tests, external public-key enrollment, and host-side public trust-store provisioning described in Section 14. Keep canonical activation blocked until every later Gate-B requirement is independently satisfied.
- [ ] **Approve with revisions.** Record exact replacement wording and affected sections; revise and independently review before implementation or provisioning.
- [ ] **Defer.** Keep the current external-attestation block and record a reconsideration trigger.
- [ ] **Reject.** Keep canonical governance-v2 mutation unavailable and record the reason.

### Exact approval statement

> Approve `G0_GOVERNANCE_V2_EXTERNAL_TRUST_PROVISIONING_PROPOSAL_2026-08-29.md` exactly as written. Authorize only local implementation and deterministic testing of the closed host-owned external-trust verifier/mutator boundary, separate enrollment of public keys by the named product owner and independent technical/security reviewer, and host-side provisioning and pinning of a public-only out-of-repository trust store, external authorship/approval roster, protected authority paths, and continuous replay store. Keep every canonical consumer operation fail-closed until the approved verifier/mutator and external trust are provisioned and a fresh exact one-operation decision, dual detached attestations, current technical evidence, and action-time prior state all pass. This approval does not activate governance v2, select a consumer, authorize a capability disposition or application slice, deploy or migrate anything, permit real patient data or live integration, close G0, establish G3 acceptance, or alter the current all-false Gate-B authorization state.

## 16. Secret handling and immutable history

Only public keys, public-key fingerprints, detached signatures, hashes, nonces, identities, roles, references, and non-secret lifecycle metadata may be retained. Private keys and all other secrets remain outside the repository, logs, receipts, fixtures used against canonical paths, and support bundles.

This proposal must not be mutated into an approval. Approval, revision, rejection, enrollment, trust-store generation, external pin, revocation, rotation, attestation, operation decision, selection, journal, and pointer records are separate artifacts with their own effects. Historical bytes remain immutable; corrections and replacements are append-only and hash-bind their predecessors.
