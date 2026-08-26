#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'fileutils'
require 'json'
require 'optparse'
require 'tmpdir'

require_relative 'validate-parity-governance'

module G0OwnerGovernanceSnapshotGenerator
  MANIFEST_NAME = 'G0_OWNER_GOVERNANCE_CANDIDATE_MANIFEST.json'
  MANIFEST_KEYS = %w[schema_version data_boundary snapshot_plan_reference snapshot_plan_sha256 files].freeze
  MANIFEST_FILE_KEYS = %w[role reference sha256].freeze
  FILE_ROLES = %w[snapshot_plan owner_authority_policy owner_appointment_register decision_session_register].freeze
  VERIFICATION_CONTEXT_KEYS = %i[
    matrix baseline batch_manifest decision_register batch_b_decision_register
    batch_c_decision_register batch_d_decision_register batch_e_decision_register
    batch_f_decision_register batch_g_decision_register institutional_identity_key_registry
    trusted_identity_root_sha256 owner_evidence_root release_index
  ].freeze
  SHA_PATTERN = /\A[0-9a-f]{64}\z/.freeze
  SENSITIVE_KEY_PATTERN = /\A(?:password|passwd|secret|api[_-]?key|access[_-]?token|refresh[_-]?token|authorization|cookie|private[_-]?key)\z/i.freeze
  SECRET_PATTERN = /(?:-----BEGIN [A-Z ]*PRIVATE KEY-----|\b(?:password|passwd|secret|api[_-]?key|access[_-]?token|refresh[_-]?token|authorization|cookie)\b\s*[:=]\s*[^\s,;}]+)/i.freeze

  module_function

  def json_bytes(value)
    JSON.pretty_generate(value) + "\n"
  end

  def parse_json(path)
    JSON.parse(File.read(path), object_class: ParityGovernanceValidator::DuplicateKeyHash, allow_duplicate_key: false)
  end

  def closed_object?(value, keys)
    value.is_a?(Hash) && value.keys.sort == keys.sort
  end

  def contains_secret?(value)
    case value
    when Hash
      value.any? { |key, child| key.to_s.match?(SENSITIVE_KEY_PATTERN) || contains_secret?(child) }
    when Array
      value.any? { |child| contains_secret?(child) }
    when String
      value.match?(SECRET_PATTERN)
    else
      false
    end
  end

  def iso_time(value)
    return nil unless value.is_a?(String)

    Time.iso8601(value)
  rescue ArgumentError
    nil
  end

  def valid_snapshot_identity?(value, time_key)
    return false unless value.is_a?(Hash)

    revision = value['snapshot_revision']
    base = value['snapshot_id'].is_a?(String) && !value['snapshot_id'].empty? && revision.is_a?(Integer) && revision.positive? && iso_time(value[time_key])
    chain = if revision == 1
              value['prior_snapshot_reference'].nil? && value['prior_snapshot_sha256'].nil?
            elsif revision.is_a?(Integer) && revision > 1
              value['prior_snapshot_reference'].is_a?(String) && !value['prior_snapshot_reference'].empty? && value['prior_snapshot_sha256'].to_s.match?(SHA_PATTERN)
            else
              false
            end
    base && chain
  end

  def verify_bundle(bundle_directory, verification_options = nil)
    errors = []
    unless verification_options.is_a?(Hash) && (VERIFICATION_CONTEXT_KEYS - verification_options.keys).empty?
      return ['candidate bundle verification requires explicit authoritative matrix/baseline/A-G/identity/evidence context']
    end
    unless File.directory?(bundle_directory) && File.lstat(bundle_directory).directory?
      return ['candidate bundle must be a regular directory']
    end

    manifest_path = File.join(bundle_directory, MANIFEST_NAME)
    unless File.file?(manifest_path) && File.lstat(manifest_path).file?
      return ["candidate bundle is missing #{MANIFEST_NAME}"]
    end
    manifest = parse_json(manifest_path)
    errors << 'candidate manifest must use the exact closed schema' unless closed_object?(manifest, MANIFEST_KEYS)
    errors << 'candidate manifest must be schema_version 1 and synthetic_only' unless manifest['schema_version'] == 1 && manifest['data_boundary'] == 'synthetic_only'
    files = manifest['files']
    errors << 'candidate manifest files must be an array' unless files.is_a?(Array)
    roles = Array(files).map { |item| item['role'] if item.is_a?(Hash) }
    errors << 'candidate manifest must contain each required file role exactly once and in order' unless roles == FILE_ROLES
    references = Array(files).map { |item| item['reference'] if item.is_a?(Hash) }
    valid_references = references.length == FILE_ROLES.length && references.all? { |reference| reference.is_a?(String) && reference == File.basename(reference) && !reference.empty? }
    errors << 'candidate manifest file references must be unique' unless valid_references && references.uniq.length == references.length
    expected_inventory = [MANIFEST_NAME, *references].sort if valid_references && references.uniq.length == references.length
    actual_inventory = Dir.children(bundle_directory).sort
    errors << 'candidate bundle directory inventory must be exactly the manifest plus its four listed files' unless expected_inventory && actual_inventory == expected_inventory

    documents = {}
    document_paths = {}
    Array(files).each_with_index do |entry, index|
      label = "candidate manifest files[#{index}]"
      unless closed_object?(entry, MANIFEST_FILE_KEYS)
        errors << "#{label} must use the exact closed schema"
        next
      end
      reference = entry['reference']
      unless reference.is_a?(String) && reference == File.basename(reference) && !reference.empty?
        errors << "#{label} reference must be one basename"
        next
      end
      path = File.join(bundle_directory, reference)
      unless File.file?(path) && File.lstat(path).file?
        errors << "#{label} referenced file is missing or not regular"
        next
      end
      actual_sha = Digest::SHA256.file(path).hexdigest
      errors << "#{label} SHA-256 does not match candidate bytes" unless entry['sha256'].to_s.match?(SHA_PATTERN) && entry['sha256'] == actual_sha
      document_paths[entry['role']] = path
      begin
        documents[entry['role']] = parse_json(path)
      rescue JSON::ParserError, SystemCallError => e
        errors << "#{label} JSON is invalid: #{e.message}"
      end
    end

    plan = documents['snapshot_plan'] || {}
    policy = documents['owner_authority_policy'] || {}
    appointments = documents['owner_appointment_register'] || {}
    sessions = documents['decision_session_register'] || {}
    errors << 'candidate snapshot plan must use the exact closed top schema' unless closed_object?(plan, ParityGovernanceValidator::OWNER_SNAPSHOT_PLAN_KEYS)
    errors << 'candidate policy must use the exact closed schema' unless closed_object?(policy, ParityGovernanceValidator::OWNER_POLICY_KEYS)
    errors << 'candidate appointment register must use the exact closed schema' unless closed_object?(appointments, ParityGovernanceValidator::OWNER_APPOINTMENT_REGISTER_KEYS)
    errors << 'candidate decision session register must use the exact closed schema' unless closed_object?(sessions, ParityGovernanceValidator::OWNER_DECISION_SESSION_REGISTER_KEYS)
    errors << 'candidate documents must remain synthetic_only' unless [plan, policy, appointments, sessions].all? { |document| document['data_boundary'] == 'synthetic_only' }
    errors << 'candidate bundle must not contain credentials, secrets or private keys' if [manifest, plan, policy, appointments, sessions].any? { |document| contains_secret?(document) }

    plan_policy = plan['policy'] || {}
    errors << 'candidate snapshot-plan policy must use the exact closed schema and valid linear identity' unless closed_object?(plan_policy, ParityGovernanceValidator::OWNER_SNAPSHOT_PLAN_POLICY_KEYS) && valid_snapshot_identity?(plan_policy, 'snapshot_at')
    expected_batches = ('A'..'G').to_a
    plan_source_batches = Array(plan['sources']).map { |source| source['batch'] if source.is_a?(Hash) }
    errors << 'candidate snapshot-plan sources must contain exact ordered A-G rows' unless plan_source_batches == expected_batches
    Array(plan['sources']).each_with_index do |source, index|
      valid_source = closed_object?(source, ParityGovernanceValidator::OWNER_SNAPSHOT_PLAN_SOURCE_KEYS) && valid_snapshot_identity?(source, 'captured_at')
      captured_at = source.is_a?(Hash) ? iso_time(source['captured_at']) : nil
      cutoff_at = source.is_a?(Hash) ? iso_time(source['cutoff_at']) : nil
      valid_source &&= captured_at && cutoff_at && cutoff_at <= captured_at
      errors << "candidate snapshot-plan sources[#{index}] has invalid closed identity or source times" unless valid_source
    end
    policy_time = iso_time(plan_policy['snapshot_at'])
    causal_sources = policy_time && Array(plan['sources']).all? do |source|
      captured_at = source.is_a?(Hash) ? iso_time(source['captured_at']) : nil
      cutoff_at = source.is_a?(Hash) ? iso_time(source['cutoff_at']) : nil
      captured_at && cutoff_at && captured_at <= policy_time && cutoff_at <= policy_time
    end
    errors << 'candidate snapshot-plan source times must not be later than policy snapshot_at' unless causal_sources
    plan_policy_keys = %w[snapshot_id snapshot_revision snapshot_at prior_snapshot_reference prior_snapshot_sha256]
    errors << 'candidate policy snapshot metadata must exactly bind the copied plan' unless policy.slice(*plan_policy_keys) == plan_policy.slice(*plan_policy_keys)
    plan_sources = Array(plan['sources'])
    policy_sources = Array(policy['source_decision_registers'])
    planned_source_metadata = plan_sources.map { |source| source.slice(*ParityGovernanceValidator::OWNER_SNAPSHOT_PLAN_SOURCE_KEYS) }
    bound_source_metadata = policy_sources.map { |source| source.slice(*ParityGovernanceValidator::OWNER_SNAPSHOT_PLAN_SOURCE_KEYS) }
    errors << 'candidate policy A-G source metadata must exactly bind the copied plan' unless bound_source_metadata == planned_source_metadata
    begin
      canonicalizer = ParityGovernanceValidator.allocate
      control_payload = policy.to_h.reject { |key, _value| %w[policy_status approval control_root_sha256].include?(key) }
      expected_control_root = Digest::SHA256.hexdigest(canonicalizer.send(:owner_canonical_json, control_payload))
      errors << 'candidate policy control_root_sha256 must bind the exact control payload' unless policy['control_root_sha256'] == expected_control_root
    rescue ArgumentError => e
      errors << "candidate policy canonicalization failed closed: #{e.message}"
    end

    policy_entry = Array(files).find { |entry| entry.is_a?(Hash) && entry['role'] == 'owner_authority_policy' } || {}
    appointment_entry = Array(files).find { |entry| entry.is_a?(Hash) && entry['role'] == 'owner_appointment_register' } || {}
    plan_entry = Array(files).find { |entry| entry.is_a?(Hash) && entry['role'] == 'snapshot_plan' } || {}
    errors << 'candidate manifest snapshot-plan reference/SHA must bind its file entry' unless manifest['snapshot_plan_reference'] == plan_entry['reference'] && manifest['snapshot_plan_sha256'] == plan_entry['sha256']
    errors << 'candidate appointment header must bind the candidate policy bytes and exact policy source descriptors' unless appointments['policy_reference'] == policy_entry['reference'] && appointments['policy_sha256'] == policy_entry['sha256'] && appointments['source_decision_registers'] == policy_sources
    errors << 'candidate decision-session header must bind candidate policy/appointment bytes and exact policy source descriptors' unless sessions['policy_reference'] == policy_entry['reference'] && sessions['policy_sha256'] == policy_entry['sha256'] && sessions['appointment_register_reference'] == appointment_entry['reference'] && sessions['appointment_register_sha256'] == appointment_entry['sha256'] && sessions['source_decision_registers'] == policy_sources
    errors << 'candidate appointment arrays must be present' unless appointments['appointments'].is_a?(Array) && appointments['events'].is_a?(Array)
    errors << 'candidate session array must be present' unless sessions['sessions'].is_a?(Array)
    if FILE_ROLES.all? { |role| document_paths.key?(role) }
      core_validator = ParityGovernanceValidator.new(
        matrix_path: verification_options.fetch(:matrix),
        baseline_path: verification_options.fetch(:baseline),
        batch_manifest_path: verification_options.fetch(:batch_manifest),
        decision_register_path: verification_options.fetch(:decision_register),
        batch_b_decision_register_path: verification_options.fetch(:batch_b_decision_register),
        batch_c_decision_register_path: verification_options.fetch(:batch_c_decision_register),
        batch_d_decision_register_path: verification_options.fetch(:batch_d_decision_register),
        batch_e_decision_register_path: verification_options.fetch(:batch_e_decision_register),
        batch_f_decision_register_path: verification_options.fetch(:batch_f_decision_register),
        batch_g_decision_register_path: verification_options.fetch(:batch_g_decision_register),
        institutional_identity_key_registry_path: verification_options.fetch(:institutional_identity_key_registry),
        trusted_identity_root_sha256: verification_options.fetch(:trusted_identity_root_sha256),
        owner_snapshot_plan_path: document_paths.fetch('snapshot_plan'),
        owner_authority_policy_path: document_paths.fetch('owner_authority_policy'),
        owner_appointment_register_path: document_paths.fetch('owner_appointment_register'),
        decision_session_register_path: document_paths.fetch('decision_session_register'),
        owner_evidence_root_path: verification_options.fetch(:owner_evidence_root),
        release_index_path: verification_options.fetch(:release_index),
        mode: 'integrity'
      )
      core_documents = [manifest, plan, policy, appointments, sessions]
      errors << 'candidate bundle fails the core secret boundary' if core_documents.any? { |document| core_validator.send(:owner_contains_secret?, document) }
      unless core_validator.validate
        errors.concat(core_validator.errors.map { |error| "candidate core validation: #{error}" })
      end
    end
    errors
  rescue JSON::ParserError, SystemCallError => e
    ["candidate bundle cannot be verified: #{e.message}"]
  end

  def generate(options)
    output = File.expand_path(options.fetch(:output))
    raise ArgumentError, "output directory already exists: #{output}" if File.exist?(output) || File.symlink?(output)
    parent = File.dirname(output)
    raise ArgumentError, "output parent must be an existing regular directory: #{parent}" unless File.directory?(parent) && File.lstat(parent).directory?
    raise ArgumentError, 'output directory name is unsafe' if %w[. ..].include?(File.basename(output))

    validator = ParityGovernanceValidator.new(
      matrix_path: options.fetch(:matrix),
      baseline_path: options.fetch(:baseline),
      batch_manifest_path: options.fetch(:batch_manifest),
      decision_register_path: options.fetch(:decision_register),
      batch_b_decision_register_path: options.fetch(:batch_b_decision_register),
      batch_c_decision_register_path: options.fetch(:batch_c_decision_register),
      batch_d_decision_register_path: options.fetch(:batch_d_decision_register),
      batch_e_decision_register_path: options.fetch(:batch_e_decision_register),
      batch_f_decision_register_path: options.fetch(:batch_f_decision_register),
      batch_g_decision_register_path: options.fetch(:batch_g_decision_register),
      institutional_identity_key_registry_path: options.fetch(:institutional_identity_key_registry),
      trusted_identity_root_sha256: options[:trusted_identity_root_sha256],
      owner_snapshot_plan_path: options.fetch(:owner_snapshot_plan),
      owner_authority_policy_path: options.fetch(:owner_authority_policy),
      owner_appointment_register_path: options.fetch(:owner_appointment_register),
      decision_session_register_path: options.fetch(:decision_session_register),
      owner_evidence_root_path: options.fetch(:owner_evidence_root),
      release_index_path: options.fetch(:release_index),
      mode: 'integrity'
    )
    candidate = validator.build_owner_governance_snapshot_candidate
    raise ArgumentError, "candidate inputs failed closed:\n- #{validator.errors.join("\n- ")}" unless candidate && validator.errors.empty?

    temp_directory = Dir.mktmpdir('.g0-owner-snapshot-', File.realpath(parent))
    begin
      plan_reference = File.basename(options.fetch(:owner_snapshot_plan))
      policy_reference = File.basename(options.fetch(:owner_authority_policy))
      appointment_reference = File.basename(options.fetch(:owner_appointment_register))
      session_reference = File.basename(options.fetch(:decision_session_register))
      FileUtils.cp(options.fetch(:owner_snapshot_plan), File.join(temp_directory, plan_reference))
      File.write(File.join(temp_directory, policy_reference), json_bytes(candidate.fetch('policy')))
      File.write(File.join(temp_directory, appointment_reference), json_bytes(candidate.fetch('appointment_register')))
      File.write(File.join(temp_directory, session_reference), json_bytes(candidate.fetch('decision_session_register')))
      roles_and_references = [
        ['snapshot_plan', plan_reference],
        ['owner_authority_policy', policy_reference],
        ['owner_appointment_register', appointment_reference],
        ['decision_session_register', session_reference]
      ]
      files = roles_and_references.map do |role, reference|
        { 'role' => role, 'reference' => reference, 'sha256' => Digest::SHA256.file(File.join(temp_directory, reference)).hexdigest }
      end
      manifest = {
        'schema_version' => 1,
        'data_boundary' => 'synthetic_only',
        'snapshot_plan_reference' => plan_reference,
        'snapshot_plan_sha256' => files.first['sha256'],
        'files' => files
      }
      File.write(File.join(temp_directory, MANIFEST_NAME), json_bytes(manifest))
      verification_errors = verify_bundle(temp_directory, options)
      raise ArgumentError, "generated candidate bundle failed verification:\n- #{verification_errors.join("\n- ")}" unless verification_errors.empty?

      File.rename(temp_directory, output)
      temp_directory = nil
      manifest
    ensure
      FileUtils.remove_entry(temp_directory) if temp_directory && File.directory?(temp_directory)
    end
  end
end

if $PROGRAM_NAME == __FILE__
  root = File.expand_path('..', __dir__)
  phase = File.join(root, 'docs/new-simrs-rebuild/phase-0')
  options = {
    matrix: File.join(root, 'docs/new-simrs-rebuild/PARITY_REQUIREMENTS_MATRIX.md'),
    baseline: File.join(phase, 'PARITY_MATRIX_BASELINE.json'),
    batch_manifest: File.join(phase, 'G0_PARITY_BATCH_MANIFEST.json'),
    decision_register: File.join(phase, 'G0_BATCH_A_DECISION_REGISTER_2026-08-25.json'),
    batch_b_decision_register: File.join(phase, 'G0_BATCH_B_DECISION_REGISTER_2026-08-25.json'),
    batch_c_decision_register: File.join(phase, 'G0_BATCH_C_DECISION_REGISTER_2026-08-25.json'),
    batch_d_decision_register: File.join(phase, 'G0_BATCH_D_DECISION_REGISTER_2026-08-25.json'),
    batch_e_decision_register: File.join(phase, 'G0_BATCH_E_DECISION_REGISTER_2026-08-25.json'),
    batch_f_decision_register: File.join(phase, 'G0_BATCH_F_DECISION_REGISTER_2026-08-25.json'),
    batch_g_decision_register: File.join(phase, 'G0_BATCH_G_DECISION_REGISTER_2026-08-25.json'),
    institutional_identity_key_registry: File.join(phase, 'G0_INSTITUTIONAL_IDENTITY_KEY_REGISTRY_2026-08-26.json'),
    trusted_identity_root_sha256: nil,
    owner_evidence_root: File.join(phase, ParityGovernanceValidator::OWNER_EVIDENCE_DIRECTORY),
    owner_authority_policy: File.join(phase, 'G0_OWNER_AUTHORITY_POLICY_2026-08-25.json'),
    owner_appointment_register: File.join(phase, 'G0_OWNER_APPOINTMENT_REGISTER_2026-08-25.json'),
    decision_session_register: File.join(phase, 'G0_DECISION_SESSION_REGISTER_2026-08-25.json'),
    release_index: File.join(phase, 'RELEASE_EVIDENCE_INDEX.md')
  }
  parser = OptionParser.new do |opts|
    opts.banner = 'Usage: ruby scripts/generate-g0-owner-governance-snapshot.rb --owner-snapshot-plan PATH --output PATH [input overrides]'
    opts.on('--owner-snapshot-plan PATH', 'required closed snapshot-plan JSON path') { |value| options[:owner_snapshot_plan] = value }
    opts.on('--output PATH', 'required new candidate-bundle directory') { |value| options[:output] = value }
    opts.on('--matrix PATH') { |value| options[:matrix] = value }
    opts.on('--baseline PATH') { |value| options[:baseline] = value }
    opts.on('--batch-manifest PATH') { |value| options[:batch_manifest] = value }
    opts.on('--decision-register PATH') { |value| options[:decision_register] = value }
    ('B'..'G').each do |batch|
      key = "batch_#{batch.downcase}_decision_register".to_sym
      opts.on("--batch-#{batch.downcase}-decision-register PATH") { |value| options[key] = value }
    end
    opts.on('--institutional-identity-key-registry PATH') { |value| options[:institutional_identity_key_registry] = value }
    opts.on('--trusted-identity-root-sha256 SHA') { |value| options[:trusted_identity_root_sha256] = value }
    opts.on('--owner-authority-policy PATH') { |value| options[:owner_authority_policy] = value }
    opts.on('--owner-appointment-register PATH') { |value| options[:owner_appointment_register] = value }
    opts.on('--decision-session-register PATH') { |value| options[:decision_session_register] = value }
    opts.on('--owner-evidence-root PATH') { |value| options[:owner_evidence_root] = value }
    opts.on('--release-index PATH') { |value| options[:release_index] = value }
    opts.on('--verify-bundle PATH', 'verify one existing candidate bundle without generating') { |value| options[:verify_bundle] = value }
  end

  begin
    parser.parse!
    if options[:verify_bundle]
      verification_errors = G0OwnerGovernanceSnapshotGenerator.verify_bundle(File.expand_path(options[:verify_bundle]), options)
      raise ArgumentError, verification_errors.join("\n") unless verification_errors.empty?
      puts 'G0 owner-governance candidate bundle verification passed'
      exit 0
    end
    raise OptionParser::MissingArgument, '--owner-snapshot-plan' unless options[:owner_snapshot_plan]
    raise OptionParser::MissingArgument, '--output' unless options[:output]
    manifest = G0OwnerGovernanceSnapshotGenerator.generate(options)
    puts "Generated verified synthetic-only G0 owner-governance candidate bundle: #{File.expand_path(options[:output])}"
    manifest['files'].each { |entry| puts "#{entry['role']}: #{entry['sha256']}  #{entry['reference']}" }
  rescue OptionParser::ParseError, ArgumentError, JSON::ParserError, SystemCallError => e
    warn e.message
    exit 1
  end
end
