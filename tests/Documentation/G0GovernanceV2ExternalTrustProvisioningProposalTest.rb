# frozen_string_literal: true

require 'digest'
require 'minitest/autorun'

class G0GovernanceV2ExternalTrustProvisioningProposalTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PROPOSAL_PATH = File.join(
    ROOT,
    'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_EXTERNAL_TRUST_PROVISIONING_PROPOSAL_2026-08-29.md'
  )

  EXACT_APPROVAL_STATEMENT = <<~TEXT.strip
    > Approve `G0_GOVERNANCE_V2_EXTERNAL_TRUST_PROVISIONING_PROPOSAL_2026-08-29.md` exactly as written. Authorize only local implementation and deterministic testing of the closed host-owned external-trust verifier/mutator boundary, separate enrollment of public keys by the named product owner and independent technical/security reviewer, and host-side provisioning and pinning of a public-only out-of-repository trust store, external authorship/approval roster, protected authority paths, and continuous replay store. Keep every canonical consumer operation fail-closed until the approved verifier/mutator and external trust are provisioned and a fresh exact one-operation decision, dual detached attestations, current technical evidence, and action-time prior state all pass. This approval does not activate governance v2, select a consumer, authorize a capability disposition or application slice, deploy or migrate anything, permit real patient data or live integration, close G0, establish G3 acceptance, or alter the current all-false Gate-B authorization state.
  TEXT

  SIGNED_PAYLOAD = <<~TEXT
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
  TEXT

  TRUST_STORE_FIELDS = %w[
    artifact_type schema_version trust_store_id generation status valid_from
    expires_at predecessor_sha256 allowed_signers keys policy
  ].freeze
  ALLOWED_SIGNERS_FIELDS = %w[path sha256 format namespace].freeze
  KEY_FIELDS = %w[
    key_id role identity capacity algorithm public_key_encoding public_key
    openssh_fingerprint_sha256 enrollment_record_path enrollment_record_sha256
    custodian_identity custody_method custody_domain separation_group_id status
    not_before not_after revoked_at revocation_reason supersedes_key_id
  ].freeze
  ENROLLMENT_FIELDS = %w[
    artifact_type schema_version enrollment_id status identity display_name role
    capacity key_id openssh_fingerprint_sha256 custodian_identity custody_method
    custody_domain separation_group_id approved_by_host_trust_administrator
    approved_at valid_from expires_at predecessor_enrollment_path
    predecessor_enrollment_sha256
  ].freeze
  SIGNER_SEPARATION_FIELDS = %w[
    execution_identity authorship_roster_path authorship_roster_sha256
    excluded_identity_set_sha256 signer_separation_policy_sha256
  ].freeze
  AUTHORSHIP_ROSTER_FIELDS = %w[
    artifact_type schema_version roster_id status canonical_commit_sha256
    covered_artifacts execution_identity verified_author_identities
    verified_approver_identities host_trust_administrator_identity verified_at
    expires_at predecessor_roster_path predecessor_roster_sha256
  ].freeze
  EXTERNAL_PIN_FIELDS = %w[
    artifact_type schema_version pin_id status host_identity trust_store_path
    trust_store_sha256 trust_store_id trust_store_generation allowed_signers_path
    allowed_signers_sha256 launcher_path launcher_sha256 selector_path
    selector_sha256 contract_path contract_sha256
    verification_source_path verification_source_sha256 authorship_roster_path
    authorship_roster_sha256 authority_paths_policy_path authority_paths_policy_sha256 replay_directory_path
    replay_directory_device_id replay_directory_inode replay_genesis_record_sha256
    replay_policy_path replay_policy_sha256 ssh_keygen_path ssh_keygen_version provisioner_identity
    provisioned_at valid_from expires_at predecessor_pin_path predecessor_pin_sha256
    rotation_reason
  ].freeze
  AUTHORITY_POLICY_FIELDS = %w[
    artifact_type schema_version policy_id status canonical_root authority_paths
    owner_identity writer_identity directory_mode file_mode valid_from expires_at
  ].freeze
  AUTHORITY_PATH_FIELDS = %w[
    path object_type allowed_operations owner_identity writer_identity device_id
    inode creation_parent_path creation_parent_device_id creation_parent_inode
    directory_mode file_mode
  ].freeze
  AUTHORITY_PATHS = %w[
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_JOURNAL
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_POINTER.json
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_POINTER_RECOVERY_REQUIRED.json
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_SELECTION.lock
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_SELECTIONS
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ACTIVATION_DECISIONS
  ].freeze
  REPLAY_POLICY_FIELDS = %w[
    artifact_type schema_version policy_id status replay_directory_path device_id
    inode genesis_record_path genesis_record_sha256 chain_algorithm writer_identity
    directory_mode file_mode valid_from expires_at
  ].freeze
  CURRENT_PIN_FIELDS = %w[
    artifact_type schema_version current_pin_path current_pin_sha256 updated_at
  ].freeze
  ATTESTATION_FIELDS = %w[
    artifact_type schema_version attestation_id role key_id algorithm
    trust_store_id trust_store_generation trust_store_sha256 subject_path
    subject_sha256 signed_at expires_at nonce payload_encoding payload_sha256
    signature_path signature_sha256 signature_encoding
  ].freeze
  RESERVATION_FIELDS = %w[
    artifact_type schema_version reservation_id sequence predecessor_record_path
    predecessor_record_sha256 status effect data_boundary
    operation environment approval_subject_path approval_subject_sha256
    operation_decision_path operation_decision_sha256
    product_owner_attestation_id product_owner_attestation_sha256
    product_owner_nonce reviewer_attestation_id reviewer_attestation_sha256
    reviewer_nonce trust_pin_path trust_pin_sha256 trust_store_generation
    prior_state_sha256 reserved_at invocation_id
  ].freeze

  def setup
    @proposal = File.binread(PROPOSAL_PATH)
  end

  def test_proposal_is_explicitly_pending_and_has_no_authority_effect
    assert_includes @proposal, '**Status:** `PROPOSAL / NOT APPROVED / NOT AUTHORITATIVE / NO EFFECT`'
    assert_includes @proposal, '**Current canonical state:** `unprovisioned_blocked_external_attestation_required`'
    assert_includes @proposal, 'This document is a design proposal and an approval prompt, not an approval'
    assert_includes @proposal, 'change the current all-false Gate-B authorization state'
    assert_includes @proposal, 'canonical selector\'s rejection before lock acquisition, filesystem probing, recovery-marker handling, or any write'
    refute_match(/^\s*(?:[-*+]\s+)?\[[xX]\]/, @proposal)
  end

  def test_out_of_repository_trust_store_is_pinned_and_not_repository_controlled
    assert_includes @proposal, 'host-provisioned regular file outside the repository checkout and outside every candidate bundle'
    assert_includes @proposal, 'exact absolute path SHALL be resolved through an out-of-repository current-pin record owned by the host or operating-system administrator'
    assert_includes @proposal, 'None of their paths may be accepted from a command-line option, repository file, candidate artifact, operation decision, or ordinary process environment variable.'
    assert_includes @proposal, 'not writable by the repository writer, application runtime, selector execution identity, group, or other users'
    assert_includes @proposal, 'public keys only'
    assert_includes @proposal, 'OpenSSH allowed-signers companion'
    assert_includes @proposal, 'before lock, probe, marker handling, or write'
  end

  def test_host_owned_launcher_rejects_repository_controlled_verifier_drift
    assert_includes @proposal, 'one fixed, root/host-administrator-owned launcher/verifier/mutator executable outside the repository'
    assert_includes @proposal, 'Direct invocation of the repository selector remains validation-only and cannot mutate or authoritatively resolve the canonical checkout.'
    assert_includes @proposal, 'single statically bound process image performs verification, locking, replay burn, authority mutation, readback, and authoritative active-chain reads'
    assert_includes @proposal, 'there is no launcher-to-mutator `exec`, plug-in, dynamic-library, socket, helper, or token handoff'
    assert_includes @proposal, 'exact SHA-256 values of the repository selector, contract, and verification source'
    assert_includes @proposal, 'opening protected authority and replay descriptors and entering its internal mutator phase'
    assert_includes @proposal, 'A modified repository selector is rejected and never executed; direct selector execution remains validation-only.'
    assert_includes @proposal, 'modified selector/contract/verification-source rejection before any mutator execution'
  end

  def test_trust_store_and_key_schemas_are_closed_and_append_only
    assert_equal TRUST_STORE_FIELDS, inline_fields_after('exactly these top-level fields:')
    assert_equal ALLOWED_SIGNERS_FIELDS, inline_fields_after('The nested `allowed_signers` object SHALL contain exactly:')
    assert_equal EXTERNAL_PIN_FIELDS, inline_fields_after('The external immutable pin SHALL contain exactly:')
    assert_equal CURRENT_PIN_FIELDS, inline_fields_after('The fixed external current-pin record SHALL contain exactly:')
    assert_equal KEY_FIELDS, inline_fields_after('Each closed key record SHALL contain exactly:')
    assert_equal ENROLLMENT_FIELDS, inline_fields_after('Each immutable external enrollment record SHALL contain exactly:')
    assert_equal AUTHORSHIP_ROSTER_FIELDS, inline_fields_after('Each externally authenticated authorship/approval roster SHALL contain exactly:')
    assert_equal AUTHORITY_POLICY_FIELDS, inline_fields_after('The protected authority-path policy SHALL contain exactly:')
    assert_equal REPLAY_POLICY_FIELDS, inline_fields_after('The protected replay policy SHALL contain exactly:')
    assert_includes @proposal, '`predecessor_sha256`: `null` only for the separately approved bootstrap generation'
    assert_includes @proposal, 'Replacement is append-only: a new pin and trust-store generation supersede but never rewrite or delete predecessor bytes.'
    assert_includes @proposal, 'Rotation creates a new key ID, a new trust-store generation bound to the predecessor SHA-256, and a new external pin.'
  end

  def test_external_pin_has_closed_resolution_ttl_rotation_and_boundaries
    assert_includes @proposal, 'current-pin record resolves exactly one immutable direct-child pin path and SHA-256'
    assert_includes @proposal, 'current pointer to a non-tip pin fail closed'
    assert_includes @proposal, 'maximum lifetime of 2,592,000 seconds'
    assert_includes @proposal, 'successor generation is exactly predecessor generation plus one'
    assert_includes @proposal, 'overlap the predecessor for at most 3,600 seconds'
    assert_includes @proposal, 'At `valid_from` the new pin is eligible; at `expires_at` it is invalid.'
    assert_includes @proposal, 'maximum plus one second, zero/negative lifetime, maximum overlap, excessive overlap, stale-current, fork, gap, and rollback attempts'
    assert_includes @proposal, 'atomically replaces the current-pin record and durably synchronizes its file and directory'
  end

  def test_two_external_signers_are_mandatory_and_independent
    assert_includes @proposal, '`product_owner`'
    assert_includes @proposal, '`independent_technical_security_reviewer`'
    assert_includes @proposal, 'distinct stable identities, `key_id` values, public-key fingerprints, custodians, custody domains, and separation-group IDs'
    assert_includes @proposal, 'distinct from the implementation executor and all authors or approvers of the operation subject'
    assert_includes @proposal, 'Both attestations are mandatory for every canonical `activate`, `rollback`, `disable`, and `recover` operation.'
    assert_includes @proposal, 'It does not substitute for product-owner authority.'
  end

  def test_enrollment_custody_and_excluded_identity_set_are_hash_bound
    assert_equal SIGNER_SEPARATION_FIELDS, inline_fields_after('The future approval subject SHALL contain a closed `signer_separation` object with exactly:')
    assert_includes @proposal, 'exact immutable external enrollment record path and SHA-256'
    assert_includes @proposal, 'ignore repository-authored author or approver arrays and recompute the excluded set from the pinned external roster'
    assert_includes @proposal, 'subject-bound artifact without exactly one roster entry, a missing real author/approver'
    assert_includes @proposal, 'SIMRS-CAMPUS-UEU/G0-GOVERNANCE-V2/EXCLUDED-REVIEWER-IDENTITIES/v1'
    assert_includes @proposal, '`excluded_identity_set_sha256` hashes those exact bytes.'
    assert_includes @proposal, '`signer_separation_policy_sha256` binds the exact external trust-store policy bytes'
    assert_includes @proposal, 'exact external authorship/approval roster path and SHA-256, the externally derived excluded-identity-set SHA-256'
    assert_includes @proposal, 'Omission tests SHALL remove one real covered-artifact author and one approver'
  end

  def test_host_permissions_and_protected_handoff_prevent_direct_mutation
    assert_includes @proposal, 'deny write access to the repository writer, application runtime, repository selector identity, group, and other users'
    assert_includes @proposal, 'single host-owned process opens protected file or directory descriptors itself'
    assert_includes @proposal, 'marks every authority/replay descriptor close-on-exec and proves the child receives none of them'
    assert_includes @proposal, 'there is no path or CLI fallback'
    assert_includes @proposal, 'direct repository-selector invocation and direct writes, renames, links, unlinks, or replacement of every canonical authority path fail'
    assert_includes @proposal, 'host-owned mutator, never the repository selector'
    assert_includes @proposal, 'externally validates the authoritative active chain'
  end

  def test_cryptographic_profile_is_exact_and_has_no_downgrade
    %w[openssh_sshsig_ed25519_sha512 openssh_sshsig_armored_lf utf8_lf_exact_v1].each do |term|
      assert_includes @proposal, "`#{term}`"
    end
    assert_includes @proposal, 'Ed25519 OpenSSH public key, exactly `ssh-ed25519`'
    assert_includes @proposal, 'SSHSIG namespace: `simrs-campus-ueu-g0-governance-v2-gate-b`'
    assert_includes @proposal, 'SSHSIG message hash: SHA-512'
    assert_includes @proposal, 'the externally pinned absolute executable `/usr/bin/ssh-keygen` using `ssh-keygen -Y verify`'
    assert_includes @proposal, 'The exact payload bytes are streamed to standard input'
    assert_includes @proposal, 'No native Ruby Ed25519 API is assumed.'
    assert_includes @proposal, 'pass a deterministic runtime capability test'
    assert_includes @proposal, 'No RSA, ECDSA, DSA, PKCS#1, SHA-1, MD5, symmetric MAC'
  end

  def test_strict_sshsig_parser_rejects_valid_sha256_signature_before_openssh
    assert_includes @proposal, 'strictly decode the canonical armor and parse the SSHSIG binary envelope with bounded lengths'
    %w[SSHSIG sha512 ssh-ed25519].each { |term| assert_includes @proposal, "`#{term}`" }
    assert_includes @proposal, 'an empty reserved field'
    assert_includes @proposal, 'no trailing or non-canonical bytes'
    assert_includes @proposal, 'cryptographically valid Ed25519 SSHSIG using `hashalg=sha256`'
    assert_includes @proposal, 'strict parser rejects it before `ssh-keygen -Y verify` is called'
  end

  def test_attestation_schema_and_exact_signed_payload_are_deterministic
    assert_equal ATTESTATION_FIELDS, inline_fields_after('Each product-owner and reviewer attestation SHALL be a separate immutable JSON file with exactly:')
    payload = @proposal.split("```text\n", 2).fetch(1).split("```", 2).first
    assert_equal SIGNED_PAYLOAD, payload
    assert_includes @proposal, '`payload_sha256` is SHA-256 over exactly those bytes.'
    assert_includes @proposal, '`signature_path` is a repository-relative regular, non-symlink file'
    assert_includes @proposal, '`signature_sha256` binds its exact bytes'
    assert_includes @proposal, 'Unicode normalization, whitespace trimming, reordered lines, CRLF conversion, implicit defaults, alternate timestamp spelling, or reserialization is forbidden.'
    assert_includes @proposal, 'The subject SHALL be the exact immutable Gate-B operation-approval artifact'
    assert_includes @proposal, 'new approval-subject schema version that contains the current closed approval fields plus exact `technical_evidence`, `operation_decision_contract`, and `signer_separation` objects'
    assert_includes @proposal, 'Contract 1.3 approval artifacts are not signature-eligible.'
    assert_includes @proposal, 'current contract, selector, verification source, local observation, canonical preflight, independent review'
  end

  def test_expiry_replay_revocation_and_rotation_fail_closed
    assert_includes @proposal, 'The operation-decision window remains at most 7,200 seconds.'
    assert_includes @proposal, 'Each attestation window is strictly positive and at most 7,200 seconds.'
    assert_includes @proposal, 'The effective authorization expiry is the earliest expiry'
    assert_includes @proposal, 'already present in an immutable reservation, successful, or failed-operation record'
    assert_includes @proposal, 'A revoked, expired, not-yet-valid, unknown, duplicate, or superseded key cannot authorize a new operation.'
    assert_includes @proposal, 'A compromise revocation effective at or before `signed_at` invalidates that attestation'
    assert_includes @proposal, 'Loss of a private key does not justify a bypass.'
  end

  def test_replay_reservation_schema_and_semantics_are_closed
    assert_equal RESERVATION_FIELDS, inline_fields_after('Each immutable one-use reservation record SHALL contain exactly:')
    assert_includes @proposal, 'Sequence 1 binds the exact pinned genesis-record path and SHA-256'
    assert_includes @proposal, 'every later record has sequence exactly predecessor sequence plus one'
    assert_includes @proposal, 'gaps, duplicate sequences, alternate predecessors, forks, omissions, truncation, or a non-tip append fail closed'
    assert_includes @proposal, 'only status is `burned_before_authority_mutation`'
    assert_includes @proposal, 'effect is `none_replay_prevention_only`'
    assert_includes @proposal, 'Pre-authorization rejection writes nothing.'
    assert_includes @proposal, 'exclusively create and durably synchronize the closed reservation record'
    assert_includes @proposal, 'Only after that durable non-authoritative burn may filesystem probing, recovery-marker handling, selection, journal, or pointer mutation begin.'
    assert_includes @proposal, 'Any failure or crash after durable reservation preserves the burn permanently'
    assert_includes @proposal, 'Concurrent invocations serialize on the stable lock'
  end

  def test_replay_store_identity_and_history_survive_pin_rotation
    assert_includes @proposal, 'retain the identical replay-directory absolute path, device identity, inode, genesis-record SHA-256, and replay-policy SHA-256'
    assert_includes @proposal, 'immutable hash chain rooted at the pinned genesis record'
    assert_includes @proposal, 'alternate, empty, replaced, disappeared, cross-device, inode-changed, forked, truncated, or non-tip replay store fails closed'
    assert_includes @proposal, 'Rotation cannot reset replay history.'
    ordering = section('## 11. Canonical ordering and mutation boundary', '## 12. Recovery and rollback impact')
    assert_includes ordering, 'alternate, empty, replaced, disappeared, cross-device, inode-changed, forked, truncated, and rotation-reset replay stores rejected'
  end

  def test_protected_policy_paths_are_resolvable_and_closed
    assert_equal AUTHORITY_PATH_FIELDS, inline_fields_after('Every `authority_paths` entry SHALL contain exactly:')
    assert_includes @proposal, '`authority_paths_policy_path`'
    assert_includes @proposal, '`replay_policy_path`'
    assert_includes @proposal, 'Both policy paths are absolute, outside repository-writer control'
    assert_includes @proposal, 'validated for exact bytes, ancestry, owner, mode, device, inode, validity, and SHA-256'
    assert_includes @proposal, 'hash without its resolvable policy path fails closed'
  end

  def test_authority_path_policy_has_complete_exact_safe_semantics
    policy = section('Every `authority_paths` entry SHALL contain exactly:', 'Both policy paths are absolute')
    rows = policy.lines.map do |line|
      match = line.match(/^\| `([^`]+)` \| `([^`]+)` \| `([^`]+(?:`, `[^`]+)*)` \|$/)
      match && [match[1], match[2], match[3].split('`, `')]
    end.compact
    assert_equal AUTHORITY_PATHS, rows.map(&:first).sort
    assert_equal rows.length, rows.map(&:first).uniq.length
    assert rows.all? { |_path, type, _ops| %w[directory regular_file regular_file_or_absent].include?(type) }
    assert rows.all? { |_path, _type, ops| !ops.empty? && ops.uniq == ops }
    assert_includes policy, 'Modes are four-character ASCII octal strings: directories exactly `0700`, regular files exactly `0600`'
    assert_includes policy, 'self-declared or additional identities fail'
    assert_includes policy, 'no wildcard, parent write, recursive mutation, arbitrary child path, chmod, chown, truncate, or delete operation is permitted'
    assert_includes policy, 'only permitted replay `chain_algorithm` is `sha256_domain_separated_reservation_chain_v1`'
    assert_includes policy, 'No alternate digest, canonicalization, domain, encoding, implicit field, or algorithm negotiation is accepted.'
    ordering = section('## 11. Canonical ordering and mutation boundary', '## 12. Recovery and rollback impact')
    assert_includes ordering, 'authority-path omission, addition, duplicate, traversal, wrong object type, operation widening, wrong owner/writer, unsafe mode, policy substitution, and replay-algorithm downgrade rejected'
  end

  def test_canonical_ordering_revalidates_and_burns_before_authority_mutation
    ordering = section('## 11. Canonical ordering and mutation boundary', '## 12. Recovery and rollback impact')
    phases = [
      '**External entry check, read-only:**',
      '**Pre-authorization, read-only:**',
      '**Serialization:**',
      '**Under-lock revalidation, read-only:**',
      '**Durable one-use burn, non-authoritative:**',
      '**Mutation capability and recovery checks:**',
      '**Authority transaction:**'
    ]
    offsets = phases.map { |phase| ordering.index(phase) }
    refute_includes offsets, nil
    assert_equal offsets.sort, offsets
    assert_includes ordering, 'Any failure through this phase writes nothing'
    assert_includes ordering, 'Drift releases the lock and writes no reservation or authority artifact.'
    assert_includes ordering, 'From this point the exact authorization is permanently consumed'
    assert_includes ordering, 'direct repository-selector mutation and direct authority-path write/rename/link/unlink failures'
    assert_includes ordering, 'omission of one real author or approver from the external roster rejected'
    assert_includes ordering, 'two concurrent invocations produce one burn and one replay rejection'
    assert_includes ordering, 'crashes immediately after burn, during probe, and before/after authority writes retain the burn and reject replay'
  end

  def test_recovery_and_threat_model_have_no_bypass
    assert_includes @proposal, 'External trust does not create an emergency bypass.'
    assert_includes @proposal, 'Rollback, disable, and both recovery outcomes remain canonical mutations'
    assert_includes @proposal, 'consumers remain held, disabled, unresolved, or otherwise fail closed, and project G0 and G3 remain `OPEN`'
    %w[
      fabricates substitutes downgrade Replay revoked Clock Symlink Secret verifier Recovery
    ].each { |term| assert_includes @proposal, term }
  end

  def test_catastrophic_trust_root_compromise_is_not_misstated_as_fail_closed
    assert_includes @proposal, 'these are catastrophic trust-root compromises, not fail-closed cases'
    assert_includes @proposal, 'Compromise of both external signer environments can mint both required attestations'
    assert_includes @proposal, 'compromise of the host trust administrator can replace protected executables/trust state or directly alter authority storage'
    assert_includes @proposal, 'no claim of integrity or absence of unauthorized authority may be made from the affected host alone'
    refute_includes section('Residual risks SHALL be documented before approval', '## 14. Proposed implementation and test sequence'),
                    'The safe result for each is no new canonical authority'
  end

  def test_implementation_sequence_requires_separate_decisions_and_safe_fixtures
    sequence = section('## 14. Proposed implementation and test sequence', '## 15. Decision options')
    assert_includes sequence, 'Obtain an attributable product-owner approval of this exact proposal'
    assert_includes sequence, 'This is not Gate-B operation approval.'
    assert_includes sequence, 'Preserve historical contract bytes and keep canonical activation blocked.'
    assert_includes sequence, 'Make the repository selector validation-only.'
    assert_includes sequence, 'direct-selector/direct-write permission'
    assert_includes sequence, 'replay-store substitution/continuity'
    assert_includes sequence, 'Test keys are clearly marked fixtures and rejected by canonical identity and path guards.'
    assert_includes sequence, 'Obtain a fresh exact one-operation product-owner decision and fresh external attestations'
    assert_includes sequence, 'Invoke the selector only under a separate explicit operation authorization.'
  end

  def test_exact_approval_statement_is_single_and_strictly_bounded
    assert_equal 1, @proposal.scan(EXACT_APPROVAL_STATEMENT).length
    assert_includes EXACT_APPROVAL_STATEMENT, 'Authorize only local implementation and deterministic testing'
    assert_includes EXACT_APPROVAL_STATEMENT, 'public-only out-of-repository trust store'
    assert_includes EXACT_APPROVAL_STATEMENT, 'protected authority paths, and continuous replay store'
    assert_includes EXACT_APPROVAL_STATEMENT, 'Keep every canonical consumer operation fail-closed'
    assert_includes EXACT_APPROVAL_STATEMENT, 'does not activate governance v2'
    assert_includes EXACT_APPROVAL_STATEMENT, 'alter the current all-false Gate-B authorization state'
  end

  def test_secrets_and_private_keys_are_forbidden
    assert_includes @proposal, 'Private keys, passwords, tokens, seed phrases, connection strings, signing-agent sockets, and recovery secrets are forbidden.'
    assert_includes @proposal, 'Private keys and all other secrets remain outside the repository, logs, receipts, fixtures used against canonical paths, and support bundles.'
    refute_match(/-----BEGIN (?:RSA )?PRIVATE KEY-----/, @proposal)
    refute_match(/\b(?:password|token|secret)\s*[:=]\s*["'][^"']+/i, @proposal)
  end

  def test_proposal_bytes_are_stable_utf8_lf_text
    assert @proposal.valid_encoding?
    refute_includes @proposal, "\r"
    assert @proposal.end_with?("\n")
    assert_match(/\A[\x00-\x7F]+\z/, @proposal)
    assert_equal 64, Digest::SHA256.hexdigest(@proposal).length
  end

  private

  def inline_fields_after(marker)
    tail = @proposal.split(marker, 2).fetch(1)
    line = tail.lines.find { |candidate| candidate.start_with?('`') }
    line.scan(/`([^`]+)`/).flatten
  end

  def section(start_heading, end_heading)
    @proposal.split(start_heading, 2).fetch(1).split(end_heading, 2).first
  end
end
