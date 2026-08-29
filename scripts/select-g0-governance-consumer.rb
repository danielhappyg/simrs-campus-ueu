#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'json'
require 'optparse'
require 'pathname'
require 'securerandom'
require 'tmpdir'
require 'time'

require_relative 'g0-proportional-governance-v2'
require_relative 'compare-g0-governance-v1-v2'

# Fixture-only implementation of the governance-v2 consumer-selection
# transaction described by ADR-018. The canonical checkout is deliberately
# read-only until a separate Gate-B operation decision authorizes activation.
module G0GovernanceConsumerSelector
  Core = G0ProportionalGovernanceV2
  Comparator = G0GovernanceV1V2Comparator

  class Error < StandardError
    attr_reader :reason_code

    def initialize(reason_code)
      @reason_code = reason_code
      super(reason_code)
    end
  end
  class UsageError < Error; end
  class ValidationFailure < Error; end
  class CapabilityFailure < ValidationFailure; end
  class DurabilityFailure < ValidationFailure; end
  class ConflictError < Error; end
  class ReceiptError < ValidationFailure; end
  class ResolutionError < ValidationFailure; end

  CANONICAL_ROOT = Pathname.new(File.expand_path('..', __dir__)).realpath.freeze
  TEST_ROOT_GUARD = 'G0_GOVERNANCE_V2_TEST_ROOT'
  TEST_FAULT_ENV = 'G0_GOVERNANCE_V2_TEST_FAULT'

  PHASE0_RELATIVE_PATH = 'docs/new-simrs-rebuild/phase-0'
  CONTRACT_RELATIVE_PATH = "#{PHASE0_RELATIVE_PATH}/G0_GOVERNANCE_V2_CONTRACT.json"
  POINTER_RELATIVE_PATH = "#{PHASE0_RELATIVE_PATH}/G0_GOVERNANCE_CONSUMER_POINTER.json"
  RECOVERY_MARKER_RELATIVE_PATH = "#{PHASE0_RELATIVE_PATH}/G0_GOVERNANCE_CONSUMER_POINTER_RECOVERY_REQUIRED.json"
  SELECTIONS_RELATIVE_PATH = "#{PHASE0_RELATIVE_PATH}/G0_GOVERNANCE_CONSUMER_SELECTIONS"
  DECISIONS_RELATIVE_PATH = "#{PHASE0_RELATIVE_PATH}/G0_GOVERNANCE_V2_ACTIVATION_DECISIONS"
  JOURNAL_RELATIVE_PATH = "#{PHASE0_RELATIVE_PATH}/G0_GOVERNANCE_CONSUMER_JOURNAL"
  LOCK_RELATIVE_PATH = "#{PHASE0_RELATIVE_PATH}/G0_GOVERNANCE_CONSUMER_SELECTION.lock"
  CANDIDATES_RELATIVE_PATH = "#{PHASE0_RELATIVE_PATH}/G0_GOVERNANCE_V2_CANDIDATES"

  OPERATIONS = %w[activate rollback disable recover].freeze
  PRIOR_STATES = %w[valid_pointer initial_state missing_pointer unreadable_pointer].freeze
  RECOVER_OUTCOMES = %w[held disabled].freeze
  POINTER_STATUSES = %w[active held disabled].freeze
  SELECTION_KINDS = %w[activation rollback_hold recovery_hold disabled].freeze
  SHA256_PATTERN = Core::SHA256_PATTERN

  EXIT_SUCCESS = 0
  EXIT_FAILURE = 1
  EXIT_USAGE = 2
  EXIT_CONFLICT = 3

  SUCCESS_REASON = 'consumer_selection_published'
  CONFLICT_REASON = 'selector_lock_conflict'
  FAILURE_REASON = 'consumer_selection_rejected'
  RESOLUTION_REASON_CODES = %w[
    pointer_missing pointer_unreadable pointer_recovery_required pointer_held
    pointer_disabled pointer_contract_invalid selection_contract_invalid
    bundle_contract_invalid contract_invalid adoption_invalid
    adoption_scope_invalid operation_decision_invalid
  ].freeze

  FAULT_POINTS = %w[
    after_selection_create
    after_selection_fsync
    after_selection_directory_fsync
    after_journal_create
    after_journal_fsync
    after_journal_directory_fsync
    after_pointer_candidate_create
    after_pointer_candidate_fsync
    after_pointer_intent_fsync
    after_pointer_rename
    after_pointer_directory_fsync
    after_pointer_readback
  ].freeze

  REFERENCE_KEYS = %w[path sha256].freeze
  PRIOR_STATE_KEYS = %w[
    prior_state_reason expected_prior_pointer_sha256
    observed_unreadable_pointer_sha256
  ].freeze
  ACTOR_KEYS = Core::CONSUMER_OPERATION_ACTOR_KEYS
  DECISION_ATTRIBUTION_KEYS = Core::CONSUMER_OPERATION_ATTRIBUTION_KEYS
  TECHNICAL_EVIDENCE_KEYS = Core::CONSUMER_OPERATION_TECHNICAL_EVIDENCE_KEYS
  OPERATION_DECISION_KEYS = Core::CONSUMER_OPERATION_DECISION_KEYS
  ENVIRONMENTS = %w[isolated_test_fixture local_canonical_checkout].freeze
  ATTRIBUTION_ENCODING = Core::CONSUMER_OPERATION_MESSAGE_ENCODING
  ATTRIBUTION_TIME_BASIS = Core::CONSUMER_OPERATION_ATTRIBUTION_METHOD
  SELECTION_KEYS = %w[
    artifact_type schema_version selection_id kind status profile created_at
    adoption_decision operation_decision operation_approval prior_state selected_bundle
    held_predecessor_selection previous_validated_selection validator_contract
    gate_effect
  ].freeze
  JOURNAL_KEYS = %w[
    artifact_type schema_version journal_id sequence operation created_at
    prior_journal_sha256 prior_pointer_sha256 prior_selection new_selection
    operation_decision operation_approval prior_state authority_effect
  ].freeze
  POINTER_KEYS = %w[
    artifact_type schema_version status profile revision
    predecessor_pointer_sha256 selection validator_contract activated_at
  ].freeze
  VALIDATOR_REFERENCE_KEYS = %w[name version path sha256].freeze
  RECEIPT_KEYS = %w[
    schema_version operation_id operation profile mode source status reason_code
    message actor started_at finished_at planning_baseline adoption_sha256
    activation_sha256 prior_pointer_sha256 selection_sha256 bundle_sha256
    validator_contract secret_scan_passed
  ].freeze
  RECOVERY_MARKER_KEYS = %w[
    artifact_type schema_version status authoritative_prior_pointer_sha256
    blocked_pointer_sha256s selection_sha256 prepared_at authority_effect
  ].freeze

  module PathGuard
    module_function

    def classify_root!(raw_root)
      raw = raw_root.to_s
      raise UsageError, 'root_invalid' if raw.empty? || raw.include?("\0")
      candidate = Pathname.new(raw).expand_path
      reject_symlink_components!(candidate, allow_missing_leaf: false)
      stat = candidate.lstat
      raise UsageError, 'root_invalid' unless stat.directory? && !stat.symlink?
      root = candidate.realpath
      return [root, 'local_canonical_checkout'] if root == CANONICAL_ROOT

      temporary = Pathname.new(Dir.tmpdir).realpath
      unless inside?(root, temporary) && root != temporary && (stat.mode & 0o777) == 0o700
        raise UsageError, 'root_classification_invalid'
      end
      [root, 'isolated_test_fixture']
    rescue ValidationFailure
      raise UsageError, 'root_invalid'
    rescue SystemCallError, ArgumentError
      raise UsageError, 'root_invalid'
    end

    def resolve_root!(raw_root, env:, mutation:)
      root, environment = classify_root!(raw_root)

      return root unless mutation

      raise UsageError, 'canonical_checkout_mutation_prohibited' if environment == 'local_canonical_checkout'
      raise UsageError, 'test_root_guard_required' unless env[TEST_ROOT_GUARD] == '1'
      raise UsageError, 'fault_injection_invalid' if env[TEST_FAULT_ENV] && !FAULT_POINTS.include?(env[TEST_FAULT_ENV])
      root
    end

    def authorize_mutation!(root, decision_environment:, env:)
      _resolved, classified = classify_root!(root)
      raise ValidationFailure, 'operation_decision_environment_mismatch' unless decision_environment == classified

      if classified == 'local_canonical_checkout'
        raise UsageError, 'canonical_test_controls_rejected' if env[TEST_ROOT_GUARD] || env[TEST_FAULT_ENV]
      else
        raise UsageError, 'test_root_guard_required' unless env[TEST_ROOT_GUARD] == '1'
        raise UsageError, 'fault_injection_invalid' if env[TEST_FAULT_ENV] && !FAULT_POINTS.include?(env[TEST_FAULT_ENV])
      end
      true
    end

    def exact_directory!(root, relative, reason = 'canonical_directory_invalid')
      path = root.join(relative)
      ensure_below!(path, root)
      reject_symlink_components!(path, allow_missing_leaf: false)
      stat = path.lstat
      raise ValidationFailure, reason unless stat.directory? && !stat.symlink?
      path.realpath
    rescue SystemCallError, ArgumentError
      raise ValidationFailure, reason
    end

    def exact_regular_file!(root, relative, reason = 'canonical_file_invalid')
      regular_file!(root.join(relative), root: root, reason: reason)
    end

    def direct_child_file!(directory, raw, root:, reason:)
      path = input_path(raw, root)
      raise ValidationFailure, reason unless path.parent == directory && path.extname == '.json'
      regular_file!(path, root: directory, reason: reason)
    end

    def regular_file!(raw, root:, reason:)
      path = Pathname.new(raw.to_s).expand_path
      ensure_below!(path, root)
      reject_symlink_components!(path, allow_missing_leaf: false)
      stat = path.lstat
      raise ValidationFailure, reason unless stat.file? && !stat.symlink?
      path
    rescue SystemCallError, ArgumentError
      raise ValidationFailure, reason
    end

    def secure_directory!(raw, root:, reason:)
      path = Pathname.new(raw.to_s).expand_path
      ensure_below!(path, root)
      reject_symlink_components!(path, allow_missing_leaf: false)
      stat = path.lstat
      raise ValidationFailure, reason unless stat.directory? && !stat.symlink?
      path.realpath
    rescue SystemCallError, ArgumentError
      raise ValidationFailure, reason
    end

    def new_file!(raw, root:, reason:)
      path = Pathname.new(raw.to_s).expand_path
      ensure_below!(path, root)
      raise ValidationFailure, reason if path == root
      reject_symlink_components!(path.parent, allow_missing_leaf: false)
      parent = path.parent.lstat
      raise ValidationFailure, reason unless parent.directory? && !parent.symlink?
      begin
        path.lstat
        raise ValidationFailure, reason
      rescue Errno::ENOENT
        path
      end
    rescue SystemCallError, ArgumentError
      raise ValidationFailure, reason
    end

    def reference_path!(root, reference, allowed_directory:, reason:)
      validate_reference_shape!(reference, reason)
      relative = reference.fetch('path')
      raise ValidationFailure, reason unless safe_relative?(relative)
      path = root.join(relative).expand_path
      directory = root.join(allowed_directory).expand_path
      raise ValidationFailure, reason unless path.parent == directory && canonical_json_basename?(path.basename.to_s)
      path = regular_file!(path, root: directory, reason: reason)
      raise ValidationFailure, reason unless Digest::SHA256.file(path).hexdigest == reference.fetch('sha256')
      path
    end

    def repository_reference_path!(root, reference, reason:)
      validate_reference_shape!(reference, reason)
      relative = reference.fetch('path')
      raise ValidationFailure, reason unless safe_relative?(relative)
      path = regular_file!(root.join(relative), root: root, reason: reason)
      raise ValidationFailure, reason unless Digest::SHA256.file(path).hexdigest == reference.fetch('sha256')
      path
    end

    def relative_path(root, path)
      relative = Pathname.new(path).realpath.relative_path_from(root.realpath).to_s
      raise ValidationFailure, 'path_reference_invalid' unless safe_relative?(relative)
      relative
    rescue SystemCallError, ArgumentError
      raise ValidationFailure, 'path_reference_invalid'
    end

    def input_path(raw, root)
      value = raw.to_s
      raise ValidationFailure, 'path_input_invalid' if value.empty? || value.include?("\0")
      path = Pathname.new(value)
      raise ValidationFailure, 'path_input_invalid' if path.each_filename.any? { |part| part == '..' }
      (path.absolute? ? path : root.join(path)).expand_path
    rescue ArgumentError
      raise ValidationFailure, 'path_input_invalid'
    end

    def ensure_below!(path, root)
      relative = Pathname.new(path).expand_path.relative_path_from(Pathname.new(root).expand_path)
      raise ValidationFailure, 'path_outside_root' if relative.each_filename.any? { |part| part == '..' }
      true
    rescue ArgumentError
      raise ValidationFailure, 'path_outside_root'
    end

    def inside?(path, root)
      relative = path.relative_path_from(root)
      !relative.each_filename.any? { |part| part == '..' }
    rescue ArgumentError
      false
    end

    def reject_symlink_components!(path, allow_missing_leaf:)
      components = []
      cursor = Pathname.new(path).expand_path
      until cursor.root?
        components << cursor
        cursor = cursor.parent
      end
      components.reverse_each.with_index do |component, index|
        component.lstat.tap { |stat| raise ValidationFailure, 'symlink_path_rejected' if stat.symlink? }
      rescue Errno::ENOENT
        next if allow_missing_leaf && index == components.length - 1
        raise
      end
      true
    end

    def validate_reference_shape!(reference, reason)
      Core.assert_closed_schema!(reference, required: REFERENCE_KEYS, label: '$.reference')
      raise ValidationFailure, reason unless reference.fetch('path').is_a?(String) &&
                                             reference.fetch('sha256').is_a?(String) &&
                                             SHA256_PATTERN.match?(reference.fetch('sha256'))
    rescue Core::Error
      raise ValidationFailure, reason
    end

    def safe_relative?(value)
      value.is_a?(String) && Core::SAFE_RELATIVE_PATH_PATTERN.match?(value)
    end

    def canonical_json_basename?(value)
      value.is_a?(String) && value.match?(/\A[A-Za-z0-9][A-Za-z0-9._-]*\.json\z/)
    end
  end

  module ArtifactIO
    module_function

    def parse_file!(path, reason)
      Core.parse_json_file(path, label: '$.artifact')
    rescue Core::Error, SystemCallError
      raise ValidationFailure, reason
    end

    def canonical_bytes(document)
      Core.canonical_json(document) + "\n"
    end

    def write_exclusive!(path, document, reason:, fault_after_create: nil, fault_after_fsync: nil,
                         fault_hook: nil, env: ENV, secret_scan_document: document,
                         cleanup_incomplete_on_failure: false)
      assert_secret_free!(secret_scan_document, reason)
      bytes = canonical_bytes(document)
      flags = File::WRONLY | File::CREAT | File::EXCL
      flags |= File::NOFOLLOW if File.const_defined?(:NOFOLLOW)
      write_path = if cleanup_incomplete_on_failure
                     path.parent.join(".#{path.basename}.#{SecureRandom.hex(12)}.tmp")
                   else
                     path
                   end
      created = false
      published = false
      File.open(write_path, flags, 0o600) do |file|
        created = true
        inject_fault!(fault_after_create, fault_hook, env) if fault_after_create
        file.binmode
        file.write(bytes)
        file.flush
        file.fsync
        inject_fault!(fault_after_fsync, fault_hook, env) if fault_after_fsync
      end
      stat = write_path.lstat
      raise DurabilityFailure, reason unless stat.file? && !stat.symlink? && File.binread(write_path) == bytes
      if cleanup_incomplete_on_failure
        File.link(write_path, path)
        published = true
        fsync_directory!(path.parent, reason)
        File.unlink(write_path)
        fsync_directory!(path.parent, reason)
      end
      Digest::SHA256.hexdigest(bytes)
    rescue Errno::EEXIST, Errno::ELOOP
      raise ConflictError, 'immutable_artifact_conflict'
    rescue Error
      raise
    rescue SystemCallError, IOError
      raise DurabilityFailure, reason
    ensure
      if cleanup_incomplete_on_failure && created && !published
        begin
          if write_path.exist? && write_path.file? && !write_path.symlink?
            File.unlink(write_path)
            fsync_directory!(write_path.parent, reason)
          end
        rescue Error, SystemCallError, IOError
          nil
        end
      end
    end

    def fsync_directory!(directory, reason)
      File.open(directory, File::RDONLY) { |handle| handle.fsync }
      true
    rescue SystemCallError, IOError
      raise DurabilityFailure, reason
    end

    def assert_secret_free!(document, reason)
      Core.assert_secret_free!(document, label: '$.artifact')
      true
    rescue Core::Error
      raise ValidationFailure, reason
    end

    def inject_fault!(point, hook, env)
      return unless point
      hook.call(point) if hook
      raise DurabilityFailure, "injected_fault_#{point}" if env[TEST_FAULT_ENV] == point
    end
  end

  module CapabilityProbe
    module_function

    def prove!(paths, env:, fault_hook: nil)
      directories = paths.values_at(:phase0, :selections, :decisions, :journal).uniq
      stats = directories.map(&:lstat)
      raise CapabilityFailure, 'filesystem_cross_device' unless stats.map(&:dev).uniq.length == 1

      phase0 = paths.fetch(:phase0)
      token = SecureRandom.hex(12)
      probe = phase0.join(".g0-selector-probe-#{token}")
      replacement = phase0.join(".g0-selector-probe-replacement-#{token}")
      lock_probe = phase0.join(".g0-selector-lock-probe-#{token}")
      link_probe = phase0.join(".g0-selector-link-probe-#{token}")
      bytes = "g0-selector-capability-probe\n"
      flags = File::WRONLY | File::CREAT | File::EXCL
      flags |= File::NOFOLLOW if File.const_defined?(:NOFOLLOW)

      File.open(probe, flags, 0o600) do |file|
        file.write(bytes)
        file.flush
        file.fsync
      end
      File.open(lock_probe, File::RDWR | File::CREAT | File::EXCL, 0o600) do |file|
        raise CapabilityFailure, 'filesystem_lock_unsupported' unless file.flock(File::LOCK_EX | File::LOCK_NB)
      end
      File.open(replacement, flags, 0o600) do |file|
        file.write(bytes)
        file.flush
        file.fsync
      end
      File.link(replacement, link_probe)
      raise CapabilityFailure, 'filesystem_exclusive_publish_unsupported' unless File.binread(link_probe) == bytes
      raise CapabilityFailure, 'filesystem_cross_device' unless probe.lstat.dev == phase0.lstat.dev
      File.rename(replacement, probe)
      ArtifactIO.fsync_directory!(phase0, 'filesystem_directory_fsync_unsupported')
      raise CapabilityFailure, 'filesystem_readback_failed' unless File.binread(probe) == bytes
      true
    rescue Error
      raise
    rescue SystemCallError, IOError, NotImplementedError
      raise CapabilityFailure, 'filesystem_capability_probe_failed'
    ensure
      [probe, replacement, lock_probe, link_probe].compact.each do |path|
        File.unlink(path) if path.exist? && path.file? && !path.symlink?
      rescue SystemCallError
        nil
      end
    end
  end

  module Validators
    module_function

    def contract!(root)
      path = PathGuard.exact_regular_file!(root, CONTRACT_RELATIVE_PATH, 'contract_invalid')
      contract = ArtifactIO.parse_file!(path, 'contract_invalid')
      Core.validate_contract!(contract, root: root.to_s)
      [contract, path]
    rescue Core::Error
      raise ValidationFailure, 'contract_invalid'
    end

    def canonical_trust_state!(contract, root_environment:)
      trust = contract.fetch('consumer_operation_decision_contract').fetch('canonical_trust_state')
      unless root_environment == trust.fetch('canonical_environment') &&
             trust.fetch('state') == 'unprovisioned_blocked_external_attestation_required' &&
             trust.fetch('canonical_operation_policy') == 'reject_before_lock_probe_marker_or_write' &&
             trust.fetch('repository_local_approval_artifacts_sufficient') == false &&
             trust.fetch('trust_anchor_status') == 'absent_not_approved_not_provisioned' &&
             trust.fetch('signature_implementation_authorized') == false
        raise ValidationFailure, 'canonical_trust_state_invalid'
      end
      raise ValidationFailure, 'canonical_external_attestation_unprovisioned'
    rescue KeyError
      raise ValidationFailure, 'canonical_trust_state_invalid'
    end

    def adoption!(root, contract)
      reference = contract.fetch('adopted_sources').fetch('adoption_decision')
      path = PathGuard.repository_reference_path!(root, reference, reason: 'adoption_invalid')
      adoption = ArtifactIO.parse_file!(path, 'adoption_invalid')
      unless adoption.fetch('artifact_type') == 'g0_governance_v2_adoption_decision' &&
             adoption.fetch('status') == 'approved_as_written' &&
             adoption.fetch('effect') == 'authorizes_local_governance_v2_implementation_only' &&
             adoption.fetch('data_boundary') == 'synthetic_only'
        raise ValidationFailure, 'adoption_invalid'
      end
      local = adoption.fetch('authorization')
      unless local.fetch('governance_v2_local_implementation') == true &&
             local.reject { |key, _| key == 'governance_v2_local_implementation' }.values.all?(false)
        raise ValidationFailure, 'adoption_scope_invalid'
      end
      [adoption, path, reference]
    rescue KeyError
      raise ValidationFailure, 'adoption_invalid'
    end

    def prior_state!(value, reason: 'prior_state_invalid')
      Core.assert_closed_schema!(value, required: PRIOR_STATE_KEYS, label: '$.prior_state')
      state = value.fetch('prior_state_reason')
      expected = value.fetch('expected_prior_pointer_sha256')
      unreadable = value.fetch('observed_unreadable_pointer_sha256')
      valid = case state
              when 'valid_pointer'
                expected.is_a?(String) && SHA256_PATTERN.match?(expected) && unreadable.nil?
              when 'initial_state', 'missing_pointer'
                expected.nil? && unreadable.nil?
              when 'unreadable_pointer'
                expected.nil? && unreadable.is_a?(String) && SHA256_PATTERN.match?(unreadable)
              else
                false
              end
      raise ValidationFailure, reason unless valid
      value
    rescue Core::Error, KeyError
      raise ValidationFailure, reason
    end

    def reference_or_nil!(value, reason)
      return nil if value.nil?
      PathGuard.validate_reference_shape!(value, reason)
      value
    end

    def operation_decision!(path, root:, root_environment:, options:, derived_prior:, contract:, contract_path:,
                            adoption_reference:, target_reference:, now:)
      decision = ArtifactIO.parse_file!(path, 'operation_decision_invalid')
      current = now.respond_to?(:call) ? now.call : now
      Core.validate_consumer_operation_decision!(
        decision, contract: contract, root: root.to_s, now: current,
        label: '$.operation_decision'
      )
      Core.assert_closed_schema!(decision, required: OPERATION_DECISION_KEYS, label: '$.operation_decision')
      ArtifactIO.assert_secret_free!(decision, 'operation_decision_secret_rejected')
      unless decision.fetch('artifact_type') == 'g0_governance_v2_consumer_operation_decision' &&
             decision.fetch('schema_version') == 1 && decision.fetch('status') == 'approved' &&
             decision.fetch('effect') == 'authorizes_one_consumer_selection_operation' &&
             decision.fetch('data_boundary') == 'synthetic_only' &&
             ENVIRONMENTS.include?(decision.fetch('environment')) &&
             decision.fetch('environment') == root_environment &&
             OPERATIONS.include?(decision.fetch('operation')) &&
             decision.fetch('operation') == options.fetch(:operation)
        raise ValidationFailure, 'operation_decision_invalid'
      end
      Core.assert_closed_schema!(decision.fetch('actor'), required: ACTOR_KEYS, label: '$.operation_decision.actor')
      unless decision.fetch('actor').values.all? { |value| value.is_a?(String) && !value.strip.empty? } &&
             decision.fetch('decision_id').is_a?(String) && !decision.fetch('decision_id').strip.empty? &&
             decision.fetch('conditions').is_a?(Array) &&
             decision.fetch('conditions').all? { |value| value.is_a?(String) && !value.strip.empty? }
        raise ValidationFailure, 'operation_decision_invalid'
      end
      decided_at = parse_time!(decision.fetch('decided_at'), 'operation_decision_time_invalid')
      expires_at = parse_time!(decision.fetch('expires_at'), 'operation_decision_time_invalid')
      raise ValidationFailure, 'operation_decision_expired' unless decided_at <= current && current < expires_at
      raise ValidationFailure, 'operation_decision_adoption_mismatch' unless decision.fetch('adoption_decision') == adoption_reference
      prior_state!(decision.fetch('prior_state'), reason: 'operation_decision_prior_state_invalid')
      raise ValidationFailure, 'operation_decision_prior_state_mismatch' unless decision.fetch('prior_state') == derived_prior

      candidate = reference_or_nil!(decision.fetch('candidate_bundle'), 'operation_decision_target_invalid')
      held = reference_or_nil!(decision.fetch('held_selection'), 'operation_decision_target_invalid')
      outcome = decision.fetch('recover_outcome')
      case options.fetch(:operation)
      when 'activate'
        raise ValidationFailure, 'operation_decision_target_invalid' unless candidate == target_reference && held.nil? && outcome.nil?
      when 'rollback'
        raise ValidationFailure, 'operation_decision_target_invalid' unless candidate.nil? && held == target_reference && outcome.nil?
      when 'disable'
        raise ValidationFailure, 'operation_decision_target_invalid' unless candidate.nil? && held.nil? && outcome.nil?
      when 'recover'
        if options.fetch(:recover_outcome) == 'held'
          raise ValidationFailure, 'operation_decision_target_invalid' unless candidate.nil? && held == target_reference && outcome == 'held'
        else
          raise ValidationFailure, 'operation_decision_target_invalid' unless candidate.nil? && held.nil? && outcome == 'disabled'
        end
      end
      decision
    rescue Core::Error, KeyError
      raise ValidationFailure, 'operation_decision_invalid'
    end

    def operation_decision_record!(decision, selection:, adoption_reference:, root: nil, root_environment: nil,
                                   contract_path: nil)
      if root && contract_path
        contract = Core.parse_json(File.binread(contract_path), label: '$.contract')
        Core.validate_consumer_operation_decision!(
          decision, contract: contract, root: root.to_s, now: nil,
          label: '$.operation_decision'
        )
      end
      Core.assert_closed_schema!(decision, required: OPERATION_DECISION_KEYS, label: '$.operation_decision')
      ArtifactIO.assert_secret_free!(decision, 'operation_decision_secret_rejected')
      operation = decision.fetch('operation')
      unless decision.fetch('artifact_type') == 'g0_governance_v2_consumer_operation_decision' &&
             decision.fetch('schema_version') == 1 && decision.fetch('status') == 'approved' &&
             decision.fetch('effect') == 'authorizes_one_consumer_selection_operation' &&
             decision.fetch('data_boundary') == 'synthetic_only' &&
             ENVIRONMENTS.include?(decision.fetch('environment')) &&
             (root_environment.nil? || decision.fetch('environment') == root_environment) &&
             OPERATIONS.include?(operation) &&
             decision.fetch('adoption_decision') == adoption_reference &&
             decision.fetch('approval_evidence') == selection.fetch('operation_approval') &&
             decision.fetch('prior_state') == selection.fetch('prior_state')
        raise ValidationFailure, 'operation_decision_invalid'
      end
      Core.assert_closed_schema!(decision.fetch('actor'), required: ACTOR_KEYS, label: '$.operation_decision.actor')
      unless decision.fetch('actor').values.all? { |value| value.is_a?(String) && !value.strip.empty? } &&
             decision.fetch('decision_id').is_a?(String) && !decision.fetch('decision_id').strip.empty? &&
             decision.fetch('conditions').is_a?(Array) &&
             decision.fetch('conditions').all? { |value| value.is_a?(String) && !value.strip.empty? }
        raise ValidationFailure, 'operation_decision_invalid'
      end
      prior_state!(decision.fetch('prior_state'), reason: 'operation_decision_invalid')
      decided_at = parse_time!(decision.fetch('decided_at'), 'operation_decision_invalid')
      expires_at = parse_time!(decision.fetch('expires_at'), 'operation_decision_invalid')
      raise ValidationFailure, 'operation_decision_invalid' unless decided_at < expires_at
      candidate = reference_or_nil!(decision.fetch('candidate_bundle'), 'operation_decision_invalid')
      held = reference_or_nil!(decision.fetch('held_selection'), 'operation_decision_invalid')
      expected_operation = {
        'activation' => 'activate', 'rollback_hold' => 'rollback',
        'recovery_hold' => 'recover', 'disabled' => nil
      }.fetch(selection.fetch('kind'))
      if selection.fetch('kind') == 'disabled'
        expected_operation = selection.fetch('prior_state').fetch('prior_state_reason') == 'valid_pointer' ? 'disable' : 'recover'
      end
      raise ValidationFailure, 'operation_decision_invalid' unless operation == expected_operation
      case selection.fetch('kind')
      when 'activation'
        valid = candidate == selection.fetch('selected_bundle') && held.nil? && decision.fetch('recover_outcome').nil?
      when 'rollback_hold'
        valid = candidate.nil? && held == selection.fetch('held_predecessor_selection') && decision.fetch('recover_outcome').nil?
      when 'recovery_hold'
        valid = candidate.nil? && held == selection.fetch('held_predecessor_selection') && decision.fetch('recover_outcome') == 'held'
      when 'disabled'
        expected_outcome = operation == 'recover' ? 'disabled' : nil
        valid = candidate.nil? && held.nil? && decision.fetch('recover_outcome') == expected_outcome
      end
      raise ValidationFailure, 'operation_decision_invalid' unless valid
      decision
    rescue Core::Error, KeyError
      raise ValidationFailure, 'operation_decision_invalid'
    end

    def attribution!(value, decided_at:)
      Core.assert_closed_schema!(value, required: DECISION_ATTRIBUTION_KEYS, label: '$.operation_decision.decision_attribution')
      message = value.fetch('decision_message')
      sha = value.fetch('decision_message_sha256')
      reference = value.fetch('decision_reference')
      unless reference.is_a?(String) && !reference.strip.empty? &&
             message.is_a?(String) && message.encoding == Encoding::UTF_8 && message.valid_encoding? &&
             !message.empty? &&
             value.fetch('decision_message_encoding') == ATTRIBUTION_ENCODING &&
             SHA256_PATTERN.match?(sha.to_s) && Digest::SHA256.hexdigest(message.b) == sha &&
             reference.end_with?("#decision-message-sha256:#{sha}")
        raise ValidationFailure, 'operation_decision_attribution_invalid'
      end

      recorded = parse_time!(value.fetch('recorded_at'), 'operation_decision_attribution_invalid')
      basis = value.fetch('recorded_at_basis')
      source = parse_time!(value.fetch('source_message_at'), 'operation_decision_attribution_invalid')
      honest = basis == ATTRIBUTION_TIME_BASIS && decided_at == source && source <= recorded
      raise ValidationFailure, 'operation_decision_attribution_invalid' unless honest
      value
    rescue Core::Error, KeyError
      raise ValidationFailure, 'operation_decision_attribution_invalid'
    end

    def technical_evidence!(value, root:, adoption_reference:, contract_path:, target_reference:, operation:)
      Core.assert_closed_schema!(value, required: TECHNICAL_EVIDENCE_KEYS, label: '$.operation_decision.technical_evidence')
      ArtifactIO.assert_secret_free!(value, 'operation_decision_evidence_invalid')
      raise ValidationFailure, 'operation_decision_evidence_invalid' unless value.fetch('gate_a_adoption') == adoption_reference

      PathGuard.repository_reference_path!(root, value.fetch('gate_a_adoption'), reason: 'operation_decision_evidence_invalid')
      %w[local_observation canonical_preflight].each do |key|
        PathGuard.repository_reference_path!(root, value.fetch(key), reason: 'operation_decision_evidence_invalid')
      end
      expected_contract = {
        'path' => PathGuard.relative_path(root, contract_path),
        'sha256' => Digest::SHA256.file(contract_path).hexdigest
      }
      unless value.fetch('validator_contract') == expected_contract
        raise ValidationFailure, 'operation_decision_evidence_invalid'
      end
      PathGuard.repository_reference_path!(root, value.fetch('validator_contract'), reason: 'operation_decision_evidence_invalid')

      candidate_evidence = value.fetch('candidate_bundle')
      PathGuard.validate_reference_shape!(candidate_evidence, 'operation_decision_evidence_invalid')
      candidate_directory = PathGuard.secure_directory!(
        root.join(candidate_evidence.fetch('path')), root: root,
        reason: 'operation_decision_evidence_invalid'
      )
      manifest = PathGuard.regular_file!(
        candidate_directory.join(Comparator::BUNDLE_FILE), root: candidate_directory,
        reason: 'operation_decision_evidence_invalid'
      )
      unless Digest::SHA256.file(manifest).hexdigest == candidate_evidence.fetch('sha256')
        raise ValidationFailure, 'operation_decision_evidence_invalid'
      end
      if operation == 'activate' && candidate_evidence != target_reference
        raise ValidationFailure, 'operation_decision_candidate_evidence_mismatch'
      end
      value
    rescue Core::Error, KeyError
      raise ValidationFailure, 'operation_decision_evidence_invalid'
    end

    def parse_time!(value, reason)
      raise ValidationFailure, reason unless value.is_a?(String)
      Time.iso8601(value).utc
    rescue ArgumentError
      raise ValidationFailure, reason
    end

    def bundle!(root, directory)
      raise ValidationFailure, 'bundle_cross_device' unless directory.lstat.dev == root.join(PHASE0_RELATIVE_PATH).lstat.dev
      result = Comparator.compare!(root: root.to_s, candidate_bundle: directory.to_s)
      unless result.fetch('status') == 'PASS' && result.values_at('authority_effect', 'activation_effect', 'gate_effect') == %w[none none none]
        raise ValidationFailure, 'bundle_invalid'
      end
      manifest = PathGuard.regular_file!(directory.join(Comparator::BUNDLE_FILE), root: directory, reason: 'bundle_invalid')
      [manifest, Digest::SHA256.file(manifest).hexdigest]
    rescue Comparator::Error, KeyError, SystemCallError
      raise ValidationFailure, 'bundle_invalid'
    end
  end

  # The only pointer -> selection -> bundle implementation used by mutation,
  # the dispatcher, and later ledger work.
  module ReadOnlyResolver
    module_function

    def resolve_active!(root:, env: ENV)
      resolved = resolve_pointer!(root: root, env: env)
      case resolved.fetch(:pointer).fetch('status')
      when 'active' then resolved
      when 'held' then raise ResolutionError, 'pointer_held'
      when 'disabled' then raise ResolutionError, 'pointer_disabled'
      else raise ResolutionError, 'pointer_contract_invalid'
      end
    end

    def resolve_pointer!(root:, env: ENV, allow_recovery_marker: false)
      root_path = PathGuard.resolve_root!(root, env: env, mutation: false)
      _classified_root, root_environment = PathGuard.classify_root!(root_path)
      paths = canonical_paths!(root_path)
      pointer_path = paths.fetch(:pointer)
      marker = read_recovery_marker(paths)
      pointer_sha_for_marker = begin
        pointer_path.lstat
        pointer_file = PathGuard.regular_file!(pointer_path, root: paths.fetch(:phase0), reason: 'pointer_recovery_required')
        Digest::SHA256.file(pointer_file).hexdigest
      rescue Errno::ENOENT
        nil
      rescue ValidationFailure
        raise ResolutionError, 'pointer_recovery_required' if marker && !allow_recovery_marker
        nil
      end
      if marker && !allow_recovery_marker
        blocked = marker.fetch('blocked_pointer_sha256s')
        authoritative = marker.fetch('authoritative_prior_pointer_sha256')
        safe_pre_rename = !blocked.include?(pointer_sha_for_marker) && pointer_sha_for_marker == authoritative
        raise ResolutionError, 'pointer_recovery_required' unless safe_pre_rename
      end
      begin
        pointer_path.lstat
      rescue Errno::ENOENT
        raise ResolutionError, 'pointer_missing'
      end
      pointer_path = PathGuard.regular_file!(pointer_path, root: paths.fetch(:phase0), reason: 'pointer_unreadable')
      pointer_sha = Digest::SHA256.file(pointer_path).hexdigest
      pointer = ArtifactIO.parse_file!(pointer_path, 'pointer_unreadable')
      validate_pointer!(pointer)

      selection_path = PathGuard.reference_path!(
        root_path, pointer.fetch('selection'), allowed_directory: SELECTIONS_RELATIVE_PATH,
        reason: 'selection_contract_invalid'
      )
      selection = ArtifactIO.parse_file!(selection_path, 'selection_contract_invalid')
      validate_selection!(selection, pointer: pointer)

      contract, contract_path = Validators.contract!(root_path)
      validator_reference = validator_reference(contract, contract_path, root_path)
      unless pointer.fetch('validator_contract') == validator_reference && selection.fetch('validator_contract') == validator_reference
        raise ResolutionError, 'pointer_contract_invalid'
      end
      _adoption, adoption_path, adoption_ref = Validators.adoption!(root_path, contract)
      raise ResolutionError, 'selection_contract_invalid' unless selection.fetch('adoption_decision') == adoption_ref

      operation_path = PathGuard.reference_path!(
        root_path, selection.fetch('operation_decision'), allowed_directory: DECISIONS_RELATIVE_PATH,
        reason: 'selection_contract_invalid'
      )
      operation_decision = ArtifactIO.parse_file!(operation_path, 'selection_contract_invalid')
      Validators.operation_decision_record!(
        operation_decision, selection: selection, adoption_reference: adoption_ref,
        root: root_path, root_environment: root_environment, contract_path: contract_path
      )

      bundle_path = nil
      bundle_sha = nil
      bundle_ref = selection.fetch('selected_bundle')
      if bundle_ref
        PathGuard.validate_reference_shape!(bundle_ref, 'bundle_contract_invalid')
        directory = root_path.join(bundle_ref.fetch('path')).expand_path
        directory = PathGuard.secure_directory!(directory, root: root_path, reason: 'bundle_contract_invalid')
        begin
          _manifest, bundle_sha = Validators.bundle!(root_path, directory)
        rescue ValidationFailure
          raise ResolutionError, 'bundle_contract_invalid'
        end
        raise ResolutionError, 'bundle_contract_invalid' unless bundle_sha == bundle_ref.fetch('sha256')
        bundle_path = directory
      elsif pointer.fetch('status') != 'disabled'
        raise ResolutionError, 'bundle_contract_invalid'
      end

      {
        root: root_path,
        pointer: pointer,
        pointer_path: pointer_path,
        pointer_sha256: pointer_sha,
        selection: selection,
        selection_path: selection_path,
        selection_sha256: pointer.fetch('selection').fetch('sha256'),
        bundle_path: bundle_path,
        bundle_manifest_path: bundle_path && bundle_path.join(Comparator::BUNDLE_FILE),
        bundle_sha256: bundle_sha,
        adoption_path: adoption_path,
        adoption_sha256: adoption_ref.fetch('sha256'),
        operation_decision_path: operation_path,
        operation_decision_sha256: selection.fetch('operation_decision').fetch('sha256'),
        operation_decision: operation_decision,
        validator_contract: validator_reference
      }
    rescue ResolutionError
      raise
    rescue ValidationFailure => e
      reason = RESOLUTION_REASON_CODES.include?(e.reason_code) ? e.reason_code : 'pointer_contract_invalid'
      raise ResolutionError, reason
    rescue Core::Error, KeyError, SystemCallError
      raise ResolutionError, 'pointer_contract_invalid'
    end

    def read_recovery_marker(paths)
      path = paths.fetch(:recovery_marker)
      begin
        path.lstat
      rescue Errno::ENOENT
        return nil
      end
      path = PathGuard.regular_file!(path, root: paths.fetch(:phase0), reason: 'pointer_recovery_required')
      marker = ArtifactIO.parse_file!(path, 'pointer_recovery_required')
      Core.assert_closed_schema!(marker, required: RECOVERY_MARKER_KEYS, label: '$.recovery_marker')
      prior = marker.fetch('authoritative_prior_pointer_sha256')
      blocked = marker.fetch('blocked_pointer_sha256s')
      unless marker.fetch('artifact_type') == 'g0_governance_consumer_pointer_recovery_required' &&
             marker.fetch('schema_version') == 1 && marker.fetch('status') == 'transaction_prepared_fail_closed' &&
             (prior.nil? || SHA256_PATTERN.match?(prior.to_s)) && blocked.is_a?(Array) && !blocked.empty? &&
             blocked.uniq == blocked && blocked.all? { |sha| SHA256_PATTERN.match?(sha.to_s) } &&
             SHA256_PATTERN.match?(marker.fetch('selection_sha256').to_s) &&
             marker.fetch('authority_effect') == 'none_consumers_must_fail_closed'
        raise ResolutionError, 'pointer_recovery_required'
      end
      Validators.parse_time!(marker.fetch('prepared_at'), 'pointer_recovery_required')
      marker
    rescue Core::Error, KeyError, ValidationFailure
      raise ResolutionError, 'pointer_recovery_required'
    end

    def canonical_paths!(root)
      phase0 = PathGuard.exact_directory!(root, PHASE0_RELATIVE_PATH, 'canonical_layout_invalid')
      {
        phase0: phase0,
        pointer: phase0.join(Pathname.new(POINTER_RELATIVE_PATH).basename),
        recovery_marker: phase0.join(Pathname.new(RECOVERY_MARKER_RELATIVE_PATH).basename),
        selections: PathGuard.exact_directory!(root, SELECTIONS_RELATIVE_PATH, 'canonical_layout_invalid'),
        decisions: PathGuard.exact_directory!(root, DECISIONS_RELATIVE_PATH, 'canonical_layout_invalid'),
        journal: PathGuard.exact_directory!(root, JOURNAL_RELATIVE_PATH, 'canonical_layout_invalid'),
        lock: phase0.join(Pathname.new(LOCK_RELATIVE_PATH).basename)
      }
    end

    def validate_pointer!(pointer)
      Core.assert_closed_schema!(pointer, required: POINTER_KEYS, label: '$.pointer')
      ArtifactIO.assert_secret_free!(pointer, 'pointer_contract_invalid')
      status = pointer.fetch('status')
      profile = pointer.fetch('profile')
      unless pointer.fetch('artifact_type') == 'g0_governance_consumer_pointer' && pointer.fetch('schema_version') == 1 &&
             POINTER_STATUSES.include?(status) && pointer.fetch('revision').is_a?(Integer) && pointer.fetch('revision').positive? &&
             ((status == 'disabled' && profile.nil?) || (%w[active held].include?(status) && profile == 'v2')) &&
             (pointer.fetch('predecessor_pointer_sha256').nil? || SHA256_PATTERN.match?(pointer.fetch('predecessor_pointer_sha256').to_s))
        raise ResolutionError, 'pointer_contract_invalid'
      end
      PathGuard.validate_reference_shape!(pointer.fetch('selection'), 'pointer_contract_invalid')
      validate_validator_reference!(pointer.fetch('validator_contract'))
      Validators.parse_time!(pointer.fetch('activated_at'), 'pointer_contract_invalid')
      true
    rescue Core::Error, KeyError, ValidationFailure
      raise ResolutionError, 'pointer_contract_invalid'
    end

    def validate_selection!(selection, pointer:)
      Core.assert_closed_schema!(selection, required: SELECTION_KEYS, label: '$.selection')
      ArtifactIO.assert_secret_free!(selection, 'selection_contract_invalid')
      status = selection.fetch('status')
      kind = selection.fetch('kind')
      expected_kind = { 'active' => 'activation', 'held' => %w[rollback_hold recovery_hold], 'disabled' => 'disabled' }.fetch(status)
      kind_valid = expected_kind.is_a?(Array) ? expected_kind.include?(kind) : expected_kind == kind
      unless selection.fetch('artifact_type') == 'g0_governance_consumer_selection' && selection.fetch('schema_version') == 1 &&
             SELECTION_KINDS.include?(kind) && kind_valid && status == pointer.fetch('status') &&
             selection.fetch('profile') == pointer.fetch('profile')
        raise ResolutionError, 'selection_contract_invalid'
      end
      %w[adoption_decision operation_decision operation_approval].each do |key|
        PathGuard.validate_reference_shape!(selection.fetch(key), 'selection_contract_invalid')
      end
      %w[selected_bundle held_predecessor_selection previous_validated_selection].each do |key|
        Validators.reference_or_nil!(selection.fetch(key), 'selection_contract_invalid')
      end
      Validators.prior_state!(selection.fetch('prior_state'), reason: 'selection_contract_invalid')
      case kind
      when 'activation'
        semantic = !selection.fetch('selected_bundle').nil? && selection.fetch('held_predecessor_selection').nil? &&
                   selection.fetch('gate_effect') == 'derive_from_validated_bundle'
      when 'rollback_hold', 'recovery_hold'
        semantic = !selection.fetch('selected_bundle').nil? && !selection.fetch('held_predecessor_selection').nil? &&
                   selection.fetch('gate_effect') == 'force_g0_g3_open_until_fresh_activation'
      when 'disabled'
        semantic = selection.fetch('selected_bundle').nil? && selection.fetch('held_predecessor_selection').nil? &&
                   selection.fetch('gate_effect') == 'force_g0_g3_open_until_fresh_activation'
      end
      raise ResolutionError, 'selection_contract_invalid' unless semantic
      predecessor = pointer.fetch('predecessor_pointer_sha256')
      expected = selection.fetch('prior_state').fetch('expected_prior_pointer_sha256')
      raise ResolutionError, 'selection_contract_invalid' unless predecessor == expected
      validate_validator_reference!(selection.fetch('validator_contract'))
      Validators.parse_time!(selection.fetch('created_at'), 'selection_contract_invalid')
      true
    rescue Core::Error, KeyError, ValidationFailure
      raise ResolutionError, 'selection_contract_invalid'
    end

    def validate_validator_reference!(value)
      Core.assert_closed_schema!(value, required: VALIDATOR_REFERENCE_KEYS, label: '$.validator_contract')
      unless value.fetch('name') == 'g0_proportional_governance_v2' && value.fetch('version') == '1.3.0' &&
             value.fetch('path') == CONTRACT_RELATIVE_PATH && SHA256_PATTERN.match?(value.fetch('sha256').to_s)
        raise ValidationFailure, 'validator_contract_invalid'
      end
    end

    def validator_reference(contract, path, root)
      {
        'name' => contract.fetch('validator').fetch('contract_name'),
        'version' => contract.fetch('validator').fetch('version'),
        'path' => PathGuard.relative_path(root, path),
        'sha256' => Digest::SHA256.file(path).hexdigest
      }
    end
  end

  module Journal
    module_function

    def load!(paths, root)
      names = paths.fetch(:journal).children.map(&:basename).map(&:to_s).select { |name| name.end_with?('.json') }.sort
      records = []
      prior_sha = nil
      names.each_with_index do |name, index|
        raise ValidationFailure, 'journal_contract_invalid' unless name.match?(/\A\d{8}-[a-z0-9-]+\.json\z/i)
        path = PathGuard.regular_file!(paths.fetch(:journal).join(name), root: paths.fetch(:journal), reason: 'journal_contract_invalid')
        record = ArtifactIO.parse_file!(path, 'journal_contract_invalid')
        validate_record!(record, index + 1, prior_sha)
        sha = Digest::SHA256.file(path).hexdigest
        records << { document: record, path: path, sha256: sha }
        prior_sha = sha
      end
      records
    end

    def validate_record!(record, sequence, prior_sha)
      Core.assert_closed_schema!(record, required: JOURNAL_KEYS, label: '$.journal')
      ArtifactIO.assert_secret_free!(record, 'journal_contract_invalid')
      unless record.fetch('artifact_type') == 'g0_governance_consumer_journal_record' &&
             record.fetch('schema_version') == 1 && record.fetch('sequence') == sequence &&
             OPERATIONS.include?(record.fetch('operation')) && record.fetch('prior_journal_sha256') == prior_sha &&
             record.fetch('authority_effect') == 'none_journal_does_not_confer_authority'
        raise ValidationFailure, 'journal_contract_invalid'
      end
      %w[prior_pointer_sha256].each do |key|
        value = record.fetch(key)
        raise ValidationFailure, 'journal_contract_invalid' unless value.nil? || SHA256_PATTERN.match?(value.to_s)
      end
      %w[prior_selection].each { |key| Validators.reference_or_nil!(record.fetch(key), 'journal_contract_invalid') }
      %w[new_selection operation_decision operation_approval].each do |key|
        PathGuard.validate_reference_shape!(record.fetch(key), 'journal_contract_invalid')
      end
      Validators.prior_state!(record.fetch('prior_state'), reason: 'journal_contract_invalid')
      Validators.parse_time!(record.fetch('created_at'), 'journal_contract_invalid')
      true
    rescue Core::Error, KeyError
      raise ValidationFailure, 'journal_contract_invalid'
    end

    def rollback_target!(history, current_selection_reference, root)
      entry = history.reverse.find { |row| row.fetch(:document).fetch('new_selection') == current_selection_reference }
      raise ValidationFailure, 'rollback_predecessor_unavailable' unless entry
      target = entry.fetch(:document).fetch('prior_selection')
      raise ValidationFailure, 'rollback_predecessor_unavailable' unless target
      path = PathGuard.reference_path!(root, target, allowed_directory: SELECTIONS_RELATIVE_PATH, reason: 'rollback_predecessor_invalid')
      selection = ArtifactIO.parse_file!(path, 'rollback_predecessor_invalid')
      raise ValidationFailure, 'rollback_predecessor_invalid' unless selection.fetch('kind') == 'activation' && selection.fetch('status') == 'active'
      [target, selection]
    rescue KeyError
      raise ValidationFailure, 'rollback_predecessor_invalid'
    end
  end

  module HistoryReplay
    module_function

    def assert_unused!(paths, history, decision_reference, approval_reference)
      decision_sha = decision_reference.fetch('sha256')
      approval_sha = approval_reference.fetch('sha256')
      history.each do |entry|
        record = entry.fetch(:document)
        reject_match!(record.fetch('operation_decision'), record.fetch('operation_approval'),
                      decision_sha, approval_sha)
      end
      paths.fetch(:selections).children.select { |path| path.basename.to_s.end_with?('.json') }.each do |path|
        selection_path = PathGuard.regular_file!(path, root: paths.fetch(:selections),
                                                 reason: 'operation_history_invalid')
        selection = ArtifactIO.parse_file!(selection_path, 'operation_history_invalid')
        Core.assert_closed_schema!(selection, required: SELECTION_KEYS, label: '$.selection_history')
        ArtifactIO.assert_secret_free!(selection, 'operation_history_invalid')
        %w[operation_decision operation_approval].each do |key|
          PathGuard.validate_reference_shape!(selection.fetch(key), 'operation_history_invalid')
        end
        reject_match!(selection.fetch('operation_decision'), selection.fetch('operation_approval'),
                      decision_sha, approval_sha)
      end
      true
    rescue Core::Error, KeyError
      raise ValidationFailure, 'operation_history_invalid'
    end

    def reject_match!(operation_reference, approval_reference, decision_sha, approval_sha)
      if operation_reference.fetch('sha256') == decision_sha || approval_reference.fetch('sha256') == approval_sha
        raise ValidationFailure, 'operation_decision_replay'
      end
    rescue KeyError
      raise ValidationFailure, 'operation_history_invalid'
    end
    private_class_method :reject_match!
  end

  module PriorState
    module_function

    def derive!(root, paths, env:)
      history = Journal.load!(paths, root)
      begin
        paths.fetch(:pointer).lstat
      rescue Errno::ENOENT
        selection_records = paths.fetch(:selections).children.count { |path| path.basename.to_s.end_with?('.json') }
        reason = history.empty? && selection_records.zero? ? 'initial_state' : 'missing_pointer'
        return [{
          'prior_state_reason' => reason,
          'expected_prior_pointer_sha256' => nil,
          'observed_unreadable_pointer_sha256' => nil
        }, nil, history]
      end

      begin
        resolution = ReadOnlyResolver.resolve_pointer!(root: root, env: env)
        state = {
          'prior_state_reason' => 'valid_pointer',
          'expected_prior_pointer_sha256' => resolution.fetch(:pointer_sha256),
          'observed_unreadable_pointer_sha256' => nil
        }
        [state, resolution, history]
      rescue ResolutionError
        pointer = PathGuard.regular_file!(paths.fetch(:pointer), root: paths.fetch(:phase0), reason: 'unreadable_pointer_path_invalid')
        raw_sha = Digest::SHA256.file(pointer).hexdigest
        state = {
          'prior_state_reason' => 'unreadable_pointer',
          'expected_prior_pointer_sha256' => nil,
          'observed_unreadable_pointer_sha256' => raw_sha
        }
        [state, nil, history]
      end
    rescue SystemCallError
      raise ValidationFailure, 'prior_state_derivation_failed'
    end
  end

  module_function

  def run_cli(argv, stdout: $stdout, stderr: $stderr, env: ENV, clock: -> { Time.now.utc })
    options = parse_options(argv)
    root = PathGuard.resolve_root!(options.fetch(:root), env: env, mutation: false)
    result = execute!(options, root: root, env: env, clock: clock)
    stdout.write(Core.canonical_json(result.fetch(:receipt)) + "\n")
    EXIT_SUCCESS
  rescue OptionParser::ParseError, UsageError
    stderr.puts('usage_error: invalid command-line usage')
    EXIT_USAGE
  rescue ConflictError => e
    stderr.puts("selection_conflict: #{e.reason_code}")
    EXIT_CONFLICT
  rescue Error => e
    stderr.puts("selection_failed: #{safe_reason(e.reason_code)}")
    EXIT_FAILURE
  rescue Core::Error, KeyError, SystemCallError, IOError
    stderr.puts("selection_failed: #{FAILURE_REASON}")
    EXIT_FAILURE
  end

  def parse_options(argv)
    options = { root: CANONICAL_ROOT.to_s }
    seen = {}
    parser = OptionParser.new do |opts|
      unique_option(opts, options, seen, :operation, '--operation OPERATION')
      unique_option(opts, options, seen, :activation_decision, '--activation-decision PATH')
      unique_option(opts, options, seen, :candidate_bundle, '--candidate-bundle PATH')
      unique_option(opts, options, seen, :prior_state, '--prior-state STATE')
      unique_option(opts, options, seen, :expected_pointer_sha256, '--expected-pointer-sha256 SHA')
      unique_option(opts, options, seen, :observed_unreadable_pointer_sha256, '--observed-unreadable-pointer-sha256 SHA')
      unique_option(opts, options, seen, :recover_outcome, '--recover-outcome OUTCOME')
      unique_option(opts, options, seen, :recover_selection, '--recover-selection PATH')
      unique_option(opts, options, seen, :recover_selection_sha256, '--recover-selection-sha256 SHA')
      unique_option(opts, options, seen, :root, '--root PATH')
      unique_option(opts, options, seen, :json_receipt, '--json-receipt PATH')
    end
    args = argv.dup
    parser.parse!(args)
    raise UsageError, 'unexpected_arguments' unless args.empty?
    validate_option_matrix!(options)
    options
  end

  def unique_option(parser, options, seen, key, declaration)
    parser.on(declaration) do |value|
      raise UsageError, 'duplicate_option' if seen[key]
      raise UsageError, 'empty_option' if value.nil? || value.strip.empty?
      seen[key] = true
      options[key] = value
    end
  end
  private_class_method :unique_option

  def validate_option_matrix!(options)
    %i[operation activation_decision prior_state json_receipt].each do |key|
      raise UsageError, 'required_option_missing' unless options.key?(key)
    end
    operation = options.fetch(:operation)
    state = options.fetch(:prior_state)
    raise UsageError, 'operation_invalid' unless OPERATIONS.include?(operation)
    raise UsageError, 'prior_state_invalid' unless PRIOR_STATES.include?(state)

    expected = options[:expected_pointer_sha256]
    unreadable = options[:observed_unreadable_pointer_sha256]
    raise UsageError, 'expected_pointer_hash_invalid' if expected && !SHA256_PATTERN.match?(expected)
    raise UsageError, 'unreadable_pointer_hash_invalid' if unreadable && !SHA256_PATTERN.match?(unreadable)
    if state == 'valid_pointer'
      raise UsageError, 'expected_pointer_hash_required' unless expected
      raise UsageError, 'unreadable_pointer_hash_irrelevant' if unreadable
    elsif state == 'unreadable_pointer'
      raise UsageError, 'expected_pointer_hash_irrelevant' if expected
      raise UsageError, 'unreadable_pointer_hash_required' unless unreadable
    elsif expected || unreadable
      raise UsageError, 'prior_state_hash_irrelevant'
    end

    case operation
    when 'activate'
      require_state!(state, %w[valid_pointer initial_state])
      require_keys!(options, :candidate_bundle)
      forbid_keys!(options, :recover_outcome, :recover_selection, :recover_selection_sha256)
    when 'rollback', 'disable'
      require_state!(state, %w[valid_pointer])
      forbid_keys!(options, :candidate_bundle, :recover_outcome, :recover_selection, :recover_selection_sha256)
    when 'recover'
      require_state!(state, %w[missing_pointer unreadable_pointer])
      forbid_keys!(options, :candidate_bundle)
      require_keys!(options, :recover_outcome)
      raise UsageError, 'recover_outcome_invalid' unless RECOVER_OUTCOMES.include?(options.fetch(:recover_outcome))
      if options.fetch(:recover_outcome) == 'held'
        require_keys!(options, :recover_selection, :recover_selection_sha256)
        raise UsageError, 'recover_selection_hash_invalid' unless SHA256_PATTERN.match?(options.fetch(:recover_selection_sha256))
      else
        forbid_keys!(options, :recover_selection, :recover_selection_sha256)
      end
    end
    true
  end

  def require_state!(state, allowed)
    raise UsageError, 'operation_prior_state_invalid' unless allowed.include?(state)
  end
  private_class_method :require_state!

  def require_keys!(options, *keys)
    raise UsageError, 'required_option_missing' unless keys.all? { |key| options.key?(key) }
  end
  private_class_method :require_keys!

  def forbid_keys!(options, *keys)
    raise UsageError, 'irrelevant_option_rejected' if keys.any? { |key| options.key?(key) }
  end
  private_class_method :forbid_keys!

  def execute!(options, root:, env: ENV, clock: -> { Time.now.utc }, fault_hook: nil)
    validate_option_matrix!(options)
    root_path, root_environment = PathGuard.classify_root!(root)
    if root_environment == 'local_canonical_checkout'
      canonical_contract, = Validators.contract!(root_path)
      Validators.canonical_trust_state!(canonical_contract, root_environment: root_environment)
    end
    paths = ReadOnlyResolver.canonical_paths!(root_path)
    receipt_path = receipt_target!(root_path, options.fetch(:json_receipt))
    decision_path = PathGuard.direct_child_file!(paths.fetch(:decisions), options.fetch(:activation_decision), root: root_path,
                                                 reason: 'operation_decision_path_invalid')
    started_at = timestamp(clock)

    # Gate-B invariant: a canonical operation must prove all static authority,
    # attribution, evidence and target bindings before touching the stable lock,
    # running a write-capability probe, or creating any authority artifact.
    contract, contract_path = Validators.contract!(root_path)
    _adoption, _adoption_path, adoption_reference = Validators.adoption!(root_path, contract)
    preflight_prior, preflight_resolution, preflight_history = PriorState.derive!(root_path, paths, env: env)
    declared_prior = declared_prior_state(options)
    raise ValidationFailure, 'declared_prior_state_mismatch' unless declared_prior == preflight_prior
    declared_decision = ArtifactIO.parse_file!(decision_path, 'operation_decision_invalid')
    declared_target = case options.fetch(:operation)
                      when 'activate' then declared_decision['candidate_bundle']
                      when 'rollback' then declared_decision['held_selection']
                      when 'recover'
                        options.fetch(:recover_outcome) == 'held' ? declared_decision['held_selection'] : nil
                      end
    # Reject malformed, pending, forged, expired or cross-environment authority
    # before the comparatively expensive bundle/history target validation.
    Validators.operation_decision!(
      decision_path, root: root_path, root_environment: root_environment,
      options: options, derived_prior: preflight_prior, contract: contract,
      contract_path: contract_path, adoption_reference: adoption_reference,
      target_reference: declared_target, now: clock
    )
    preflight_target, = target_for_operation!(
      options, root_path, paths, preflight_resolution, preflight_history,
      root_environment: root_environment
    )
    preflight_decision = Validators.operation_decision!(
      decision_path, root: root_path, root_environment: root_environment,
      options: options, derived_prior: preflight_prior, contract: contract,
      contract_path: contract_path, adoption_reference: adoption_reference,
      target_reference: preflight_target, now: clock
    )
    PathGuard.authorize_mutation!(
      root_path, decision_environment: preflight_decision.fetch('environment'), env: env
    )
    preflight_decision_reference = reference(root_path, decision_path)
    HistoryReplay.assert_unused!(paths, preflight_history, preflight_decision_reference,
                                 preflight_decision.fetch('approval_evidence'))

    with_exclusive_lock(paths.fetch(:lock)) do
      derived_prior, prior_resolution, history = PriorState.derive!(root_path, paths, env: env)
      declared_prior = declared_prior_state(options)
      raise ValidationFailure, 'declared_prior_state_mismatch' unless declared_prior == derived_prior

      contract, contract_path = Validators.contract!(root_path)
      adoption, _adoption_path, adoption_reference = Validators.adoption!(root_path, contract)
      validator_reference = ReadOnlyResolver.validator_reference(contract, contract_path, root_path)
      target_reference, target_selection, bundle_reference = target_for_operation!(
        options, root_path, paths, prior_resolution, history,
        root_environment: root_environment
      )
      decision = Validators.operation_decision!(
        decision_path, root: root_path, root_environment: root_environment,
        options: options, derived_prior: derived_prior,
        contract: contract, contract_path: contract_path,
        adoption_reference: adoption_reference,
        target_reference: target_reference, now: clock
      )
      decision_reference = reference(root_path, decision_path)
      HistoryReplay.assert_unused!(paths, history, decision_reference,
                                   decision.fetch('approval_evidence'))
      PathGuard.authorize_mutation!(
        root_path, decision_environment: decision.fetch('environment'), env: env
      )
      CapabilityProbe.prove!(paths, env: env, fault_hook: fault_hook)
      reconcile_recovery_marker!(paths, options)
      now_value = timestamp(clock)
      selection = build_selection(
        options, decision, decision_reference, adoption_reference, derived_prior,
        prior_resolution, target_reference, target_selection, bundle_reference,
        validator_reference, now_value
      )
      selection_path = paths.fetch(:selections).join("#{selection.fetch('selection_id').downcase}.json")
      selection_sha = ArtifactIO.write_exclusive!(
        selection_path, selection, reason: 'selection_write_failed',
        fault_after_create: 'after_selection_create', fault_after_fsync: 'after_selection_fsync',
        fault_hook: fault_hook, env: env, cleanup_incomplete_on_failure: true
      )
      ArtifactIO.fsync_directory!(paths.fetch(:selections), 'selection_directory_fsync_failed')
      ArtifactIO.inject_fault!('after_selection_directory_fsync', fault_hook, env)
      selection_reference = reference(root_path, selection_path, selection_sha)

      journal = build_journal(
        options, history, prior_resolution, selection_reference, decision_reference,
        decision.fetch('approval_evidence'), derived_prior, now_value
      )
      journal_path = paths.fetch(:journal).join(format('%08d-%s.json', journal.fetch('sequence'), journal.fetch('journal_id').downcase))
      ArtifactIO.write_exclusive!(
        journal_path, journal, reason: 'journal_write_failed',
        fault_after_create: 'after_journal_create', fault_after_fsync: 'after_journal_fsync',
        fault_hook: fault_hook, env: env, cleanup_incomplete_on_failure: true
      )
      ArtifactIO.fsync_directory!(paths.fetch(:journal), 'journal_directory_fsync_failed')
      ArtifactIO.inject_fault!('after_journal_directory_fsync', fault_hook, env)

      pointer = build_pointer(options, prior_resolution, selection_reference, validator_reference, now_value)
      pointer_candidate = paths.fetch(:phase0).join(".G0_GOVERNANCE_CONSUMER_POINTER.#{SecureRandom.hex(12)}.tmp")
      renamed = false
      pointer_candidate_sha = nil
      begin
        pointer_candidate_sha = ArtifactIO.write_exclusive!(
          pointer_candidate, pointer, reason: 'pointer_candidate_write_failed',
          fault_after_create: 'after_pointer_candidate_create', fault_after_fsync: 'after_pointer_candidate_fsync',
          fault_hook: fault_hook, env: env
        )
        raise DurabilityFailure, 'pointer_cross_device' unless pointer_candidate.lstat.dev == paths.fetch(:pointer).parent.lstat.dev
        publish_pointer_intent!(paths, prior_resolution, pointer_candidate_sha, selection_sha, timestamp(clock), env)
        ArtifactIO.inject_fault!('after_pointer_intent_fsync', fault_hook, env)
        File.rename(pointer_candidate, paths.fetch(:pointer))
        renamed = true
        ArtifactIO.inject_fault!('after_pointer_rename', fault_hook, env)
        ArtifactIO.fsync_directory!(paths.fetch(:phase0), 'pointer_directory_fsync_failed')
        ArtifactIO.inject_fault!('after_pointer_directory_fsync', fault_hook, env)
        readback = ReadOnlyResolver.resolve_pointer!(root: root_path, env: env, allow_recovery_marker: true)
        raise DurabilityFailure, 'pointer_readback_failed' unless readback.fetch(:selection_sha256) == selection_sha &&
                                                                  readback.fetch(:pointer) == pointer
        ArtifactIO.inject_fault!('after_pointer_readback', fault_hook, env)
      rescue Error, Core::Error, KeyError, SystemCallError, IOError
        raise
      ensure
        if !renamed && pointer_candidate.exist? && pointer_candidate.file? && !pointer_candidate.symlink?
          File.unlink(pointer_candidate)
        end
      end

      receipt = build_receipt(
        options, decision, adoption, adoption_reference, decision_reference,
        prior_resolution, selection_sha, bundle_reference, validator_reference,
        started_at, timestamp(clock)
      )
      begin
        clear_recovery_marker!(paths)
        ArtifactIO.write_exclusive!(
          receipt_path, receipt, reason: 'receipt_write_failed', env: env,
          secret_scan_document: receipt.reject { |key, _| key == 'secret_scan_passed' },
          cleanup_incomplete_on_failure: true
        )
        ArtifactIO.fsync_directory!(receipt_path.parent, 'receipt_directory_fsync_failed')
      rescue Error, Core::Error, KeyError, SystemCallError, IOError
        publish_pointer_intent!(paths, prior_resolution, pointer_candidate_sha, selection_sha, timestamp(clock), env) if renamed
        raise
      end
      { receipt: receipt, pointer: pointer, selection: selection, journal: journal }
    end
  end

  def publish_pointer_intent!(paths, prior_resolution, candidate_pointer_sha, selection_sha, prepared_at, env)
    marker_path = paths.fetch(:recovery_marker)
    existing = begin
      ReadOnlyResolver.read_recovery_marker(paths)
    rescue ResolutionError
      nil
    end
    blocked = Array(existing && existing.fetch('blocked_pointer_sha256s'))
    blocked << candidate_pointer_sha
    marker = {
      'artifact_type' => 'g0_governance_consumer_pointer_recovery_required',
      'schema_version' => 1,
      'status' => 'transaction_prepared_fail_closed',
      'authoritative_prior_pointer_sha256' => prior_resolution && prior_resolution.fetch(:pointer_sha256),
      'blocked_pointer_sha256s' => blocked.compact.uniq,
      'selection_sha256' => selection_sha,
      'prepared_at' => prepared_at,
      'authority_effect' => 'none_consumers_must_fail_closed'
    }
    Core.assert_closed_schema!(marker, required: RECOVERY_MARKER_KEYS, label: '$.recovery_marker')
    marker_candidate = paths.fetch(:phase0).join(".G0_GOVERNANCE_CONSUMER_POINTER_INTENT.#{SecureRandom.hex(12)}.tmp")
    ArtifactIO.write_exclusive!(marker_candidate, marker, reason: 'recovery_marker_write_failed', env: env)
    File.rename(marker_candidate, marker_path)
    ArtifactIO.fsync_directory!(paths.fetch(:phase0), 'recovery_marker_directory_fsync_failed')
    true
  ensure
    if defined?(marker_candidate) && marker_candidate && marker_candidate.exist? && marker_candidate.file? && !marker_candidate.symlink?
      File.unlink(marker_candidate) rescue nil
    end
  end
  private_class_method :publish_pointer_intent!

  def reconcile_recovery_marker!(paths, options)
    marker = begin
      ReadOnlyResolver.read_recovery_marker(paths)
    rescue ResolutionError
      raise ValidationFailure, 'recovery_marker_requires_recover' unless options.fetch(:operation) == 'recover'
      return true
    end
    return true unless marker

    pointer_sha = begin
      pointer = PathGuard.regular_file!(paths.fetch(:pointer), root: paths.fetch(:phase0), reason: 'recovery_marker_requires_recover')
      Digest::SHA256.file(pointer).hexdigest
    rescue ValidationFailure, Errno::ENOENT
      nil
    end
    safe_pre_rename = pointer_sha == marker.fetch('authoritative_prior_pointer_sha256') &&
                      !marker.fetch('blocked_pointer_sha256s').include?(pointer_sha)
    if safe_pre_rename
      clear_recovery_marker!(paths)
    elsif options.fetch(:operation) != 'recover'
      raise ValidationFailure, 'recovery_marker_requires_recover'
    end
    true
  end
  private_class_method :reconcile_recovery_marker!

  def clear_recovery_marker!(paths)
    marker = paths.fetch(:recovery_marker)
    return true unless marker.exist?
    PathGuard.regular_file!(marker, root: paths.fetch(:phase0), reason: 'recovery_marker_invalid')
    File.unlink(marker)
    ArtifactIO.fsync_directory!(paths.fetch(:phase0), 'recovery_marker_directory_fsync_failed')
    true
  rescue SystemCallError
    raise DurabilityFailure, 'recovery_marker_clear_failed'
  end
  private_class_method :clear_recovery_marker!

  def with_exclusive_lock(lock_path)
    base_flags = File::RDWR
    base_flags |= File::NOFOLLOW if File.const_defined?(:NOFOLLOW)
    lock = begin
      File.open(lock_path, base_flags | File::CREAT | File::EXCL, 0o600)
    rescue Errno::EEXIST
      validate_stable_lock!(lock_path)
      File.open(lock_path, base_flags, 0o600)
    end
    begin
      validate_open_lock!(lock_path, lock)
      raise ConflictError, CONFLICT_REASON unless lock.flock(File::LOCK_EX | File::LOCK_NB)
      yield
    ensure
      lock.flock(File::LOCK_UN) rescue nil
      lock.close rescue nil
    end
  rescue Errno::ELOOP
    raise ValidationFailure, 'lock_path_invalid'
  rescue ConflictError
    raise
  rescue SystemCallError, IOError
    raise ValidationFailure, 'lock_unavailable'
  end
  private_class_method :with_exclusive_lock

  def validate_stable_lock!(lock_path)
    stat = lock_path.lstat
    unless stat.file? && !stat.symlink? && stat.uid == Process.uid && stat.nlink == 1 &&
           (stat.mode & 0o777) == 0o600 && stat.size.zero?
      raise ValidationFailure, 'lock_path_invalid'
    end
    true
  rescue SystemCallError
    raise ValidationFailure, 'lock_path_invalid'
  end
  private_class_method :validate_stable_lock!

  def validate_open_lock!(lock_path, handle)
    path_stat = lock_path.lstat
    open_stat = handle.stat
    unless path_stat.ino == open_stat.ino && path_stat.dev == open_stat.dev &&
           open_stat.file? && open_stat.uid == Process.uid && open_stat.nlink == 1 &&
           (open_stat.mode & 0o777) == 0o600 && open_stat.size.zero?
      raise ValidationFailure, 'lock_path_invalid'
    end
    true
  rescue SystemCallError
    raise ValidationFailure, 'lock_path_invalid'
  end
  private_class_method :validate_open_lock!

  def declared_prior_state(options)
    {
      'prior_state_reason' => options.fetch(:prior_state),
      'expected_prior_pointer_sha256' => options[:expected_pointer_sha256],
      'observed_unreadable_pointer_sha256' => options[:observed_unreadable_pointer_sha256]
    }
  end
  private_class_method :declared_prior_state

  def target_for_operation!(options, root, paths, prior_resolution, history, root_environment:)
    case options.fetch(:operation)
    when 'activate'
      directory = PathGuard.input_path(options.fetch(:candidate_bundle), root)
      directory = PathGuard.secure_directory!(directory, root: root, reason: 'candidate_bundle_path_invalid')
      if root_environment == 'local_canonical_checkout'
        candidates = PathGuard.exact_directory!(root, CANDIDATES_RELATIVE_PATH, 'candidate_bundle_path_invalid')
        raise ValidationFailure, 'candidate_bundle_path_invalid' unless directory.parent == candidates
      end
      _manifest, sha = Validators.bundle!(root, directory)
      ref = { 'path' => PathGuard.relative_path(root, directory), 'sha256' => sha }
      [ref, nil, ref]
    when 'rollback'
      current_ref = prior_resolution.fetch(:pointer).fetch('selection')
      target_ref, selection = Journal.rollback_target!(history, current_ref, root)
      bundle = selection.fetch('selected_bundle')
      raise ValidationFailure, 'rollback_predecessor_invalid' unless bundle
      [target_ref, selection, bundle]
    when 'disable'
      [nil, nil, nil]
    when 'recover'
      return [nil, nil, nil] if options.fetch(:recover_outcome) == 'disabled'
      path = PathGuard.input_path(options.fetch(:recover_selection), root)
      path = PathGuard.regular_file!(path, root: paths.fetch(:selections), reason: 'recovery_selection_invalid')
      raise ValidationFailure, 'recovery_selection_invalid' unless path.parent == paths.fetch(:selections)
      sha = Digest::SHA256.file(path).hexdigest
      raise ValidationFailure, 'recovery_selection_invalid' unless sha == options.fetch(:recover_selection_sha256)
      selection = ArtifactIO.parse_file!(path, 'recovery_selection_invalid')
      raise ValidationFailure, 'recovery_selection_invalid' unless selection.fetch('kind') == 'activation' && selection.fetch('status') == 'active'
      bundle = selection.fetch('selected_bundle')
      raise ValidationFailure, 'recovery_selection_invalid' unless bundle
      bundle_directory = PathGuard.secure_directory!(root.join(bundle.fetch('path')), root: root, reason: 'recovery_selection_invalid')
      _manifest, bundle_sha = Validators.bundle!(root, bundle_directory)
      raise ValidationFailure, 'recovery_selection_invalid' unless bundle_sha == bundle.fetch('sha256')
      ref = reference(root, path, sha)
      [ref, selection, bundle]
    end
  rescue KeyError
    raise ValidationFailure, 'operation_target_invalid'
  end
  private_class_method :target_for_operation!

  def build_selection(options, decision, decision_reference, adoption_reference, prior_state,
                      prior_resolution, target_reference, _target_selection, bundle_reference,
                      validator_reference, created_at)
    kind, status, profile = case options.fetch(:operation)
                            when 'activate' then %w[activation active v2]
                            when 'rollback' then %w[rollback_hold held v2]
                            when 'disable' then ['disabled', 'disabled', nil]
                            when 'recover'
                              options.fetch(:recover_outcome) == 'held' ? %w[recovery_hold held v2] : ['disabled', 'disabled', nil]
                            end
    previous = prior_resolution && prior_resolution.fetch(:pointer).fetch('selection')
    identity = {
      'operation_decision' => decision_reference,
      'kind' => kind,
      'prior_state' => prior_state,
      'target' => target_reference,
      'created_at' => created_at
    }
    {
      'artifact_type' => 'g0_governance_consumer_selection',
      'schema_version' => 1,
      'selection_id' => "G0-V2-SELECTION-#{Core.canonical_sha256(identity)[0, 24].upcase}",
      'kind' => kind,
      'status' => status,
      'profile' => profile,
      'created_at' => created_at,
      'adoption_decision' => adoption_reference,
      'operation_decision' => decision_reference,
      'operation_approval' => decision.fetch('approval_evidence'),
      'prior_state' => prior_state,
      'selected_bundle' => bundle_reference,
      'held_predecessor_selection' => %w[rollback_hold recovery_hold].include?(kind) ? target_reference : nil,
      'previous_validated_selection' => previous,
      'validator_contract' => validator_reference,
      'gate_effect' => kind == 'activation' ? 'derive_from_validated_bundle' : 'force_g0_g3_open_until_fresh_activation'
    }
  end
  private_class_method :build_selection

  def build_journal(options, history, prior_resolution, selection_reference, decision_reference,
                    approval_reference, prior_state, created_at)
    sequence = history.length + 1
    prior_selection = prior_resolution && prior_resolution.fetch(:pointer).fetch('selection')
    identity = {
      'sequence' => sequence,
      'operation' => options.fetch(:operation),
      'new_selection' => selection_reference,
      'operation_decision' => decision_reference,
      'operation_approval' => approval_reference,
      'created_at' => created_at
    }
    {
      'artifact_type' => 'g0_governance_consumer_journal_record',
      'schema_version' => 1,
      'journal_id' => "G0-V2-JOURNAL-#{Core.canonical_sha256(identity)[0, 24].upcase}",
      'sequence' => sequence,
      'operation' => options.fetch(:operation),
      'created_at' => created_at,
      'prior_journal_sha256' => history.last && history.last.fetch(:sha256),
      'prior_pointer_sha256' => prior_resolution && prior_resolution.fetch(:pointer_sha256),
      'prior_selection' => prior_selection,
      'new_selection' => selection_reference,
      'operation_decision' => decision_reference,
      'operation_approval' => approval_reference,
      'prior_state' => prior_state,
      'authority_effect' => 'none_journal_does_not_confer_authority'
    }
  end
  private_class_method :build_journal

  def build_pointer(options, prior_resolution, selection_reference, validator_reference, activated_at)
    operation = options.fetch(:operation)
    status, profile = case operation
                      when 'activate' then %w[active v2]
                      when 'rollback' then %w[held v2]
                      when 'disable' then ['disabled', nil]
                      when 'recover'
                        options.fetch(:recover_outcome) == 'held' ? %w[held v2] : ['disabled', nil]
                      end
    {
      'artifact_type' => 'g0_governance_consumer_pointer',
      'schema_version' => 1,
      'status' => status,
      'profile' => profile,
      'revision' => prior_resolution ? prior_resolution.fetch(:pointer).fetch('revision') + 1 : 1,
      'predecessor_pointer_sha256' => prior_resolution && prior_resolution.fetch(:pointer_sha256),
      'selection' => selection_reference,
      'validator_contract' => validator_reference,
      'activated_at' => activated_at
    }
  end
  private_class_method :build_pointer

  def build_receipt(options, decision, adoption, adoption_reference, decision_reference,
                    prior_resolution, selection_sha, bundle_reference, validator_reference,
                    started_at, finished_at)
    operation = options.fetch(:operation)
    profile = operation == 'disable' || (operation == 'recover' && options.fetch(:recover_outcome) == 'disabled') ? nil : 'v2'
    core = {
      'schema_version' => 1,
      'operation' => operation,
      'profile' => profile,
      'mode' => 'selection',
      'source' => operation == 'activate' ? 'candidate' : 'active',
      'status' => 'PASS',
      'reason_code' => SUCCESS_REASON,
      'message' => 'Governance consumer selection transaction published and verified.',
      'actor' => decision.fetch('actor').fetch('identity'),
      'planning_baseline' => adoption.fetch('planning_head'),
      'adoption_sha256' => adoption_reference.fetch('sha256'),
      'activation_sha256' => decision_reference.fetch('sha256'),
      'prior_pointer_sha256' => prior_resolution && prior_resolution.fetch(:pointer_sha256),
      'selection_sha256' => selection_sha,
      'bundle_sha256' => bundle_reference && bundle_reference.fetch('sha256'),
      'validator_contract' => validator_reference,
      'secret_scan_passed' => true
    }
    receipt = core.merge(
      'operation_id' => "G0-V2-SELECT-#{Core.canonical_sha256(core)[0, 24].upcase}",
      'started_at' => started_at,
      'finished_at' => finished_at
    )
    Core.assert_closed_schema!(receipt, required: RECEIPT_KEYS, label: '$.receipt')
    ArtifactIO.assert_secret_free!(receipt.reject { |key, _| key == 'secret_scan_passed' }, 'receipt_secret_rejected')
    receipt
  rescue Core::Error, KeyError
    raise ReceiptError, 'receipt_contract_invalid'
  end
  private_class_method :build_receipt

  def receipt_target!(root, raw)
    path = PathGuard.input_path(raw, root)
    PathGuard.new_file!(path, root: root, reason: 'receipt_path_invalid')
  end
  private_class_method :receipt_target!

  def reference(root, path, sha = nil)
    {
      'path' => PathGuard.relative_path(root, path),
      'sha256' => sha || Digest::SHA256.file(path).hexdigest
    }
  end
  private_class_method :reference

  def timestamp(clock)
    value = clock.respond_to?(:call) ? clock.call : clock
    value.utc.iso8601(6)
  rescue NoMethodError, ArgumentError
    raise ValidationFailure, 'clock_invalid'
  end
  private_class_method :timestamp

  def safe_reason(reason)
    value = reason.to_s
    value.match?(/\A[a-z0-9_]+\z/) ? value : FAILURE_REASON
  end
  private_class_method :safe_reason
end

exit G0GovernanceConsumerSelector.run_cli(ARGV) if $PROGRAM_NAME == __FILE__
