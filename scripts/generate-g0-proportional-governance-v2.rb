#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'fileutils'
require 'json'
require 'optparse'
require 'pathname'
require 'time'

require_relative 'g0-proportional-governance-v2'

# Generates the initial governance-v2 candidate as a deterministic, pending-only
# projection of the immutable governance-v1 sources. It never selects a consumer,
# changes a gate, or turns engineering observations into owner authority.
module G0ProportionalGovernanceV2Generator
  class Error < StandardError; end
  class UsageError < Error; end

  Core = G0ProportionalGovernanceV2

  PHASE = 'docs/new-simrs-rebuild/phase-0'
  RETAINED_PARENT = "#{PHASE}/G0_GOVERNANCE_V2_CANDIDATES"
  SAFE_RETAINED_NAME_PATTERN = /\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/.freeze
  CONTRACT_PATH = "#{PHASE}/G0_GOVERNANCE_V2_CONTRACT.json"
  V1_MANIFEST_PATH = "#{PHASE}/G0_GOVERNANCE_V1_HISTORICAL_HASH_MANIFEST.json"
  V1_OWNER_POLICY_PATH = "#{PHASE}/G0_OWNER_AUTHORITY_POLICY_2026-08-25.json"
  EVIDENCE_MAP_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_2026-08-27.json'
  EVIDENCE_MAP_SHA256 = '3cfcc9898f4b541cea85cd448638fc300cae48eb5172c3dffd598925d9244ea0'

  FILES = {
    'authority_register' => 'G0_GOVERNANCE_V2_AUTHORITY_REGISTER.json',
    'owner_register' => 'G0_GOVERNANCE_V2_OWNER_REGISTER.json',
    'decision_event_register' => 'G0_GOVERNANCE_V2_DECISION_EVENT_REGISTER.json',
    'expanded_decision_register' => 'G0_GOVERNANCE_V2_EXPANDED_DECISION_REGISTER.json',
    'gate_register' => 'G0_GOVERNANCE_V2_GATE_REGISTER.json',
    'bundle_manifest' => 'G0_GOVERNANCE_V2_BUNDLE_MANIFEST.json'
  }.freeze
  PUBLICATION_ORDER = %w[
    authority_register owner_register decision_event_register
    expanded_decision_register gate_register bundle_manifest
  ].freeze

  REGISTER_PATHS = ('A'..'G').to_h do |batch|
    [batch, "#{PHASE}/G0_BATCH_#{batch}_DECISION_REGISTER_2026-08-25.json"]
  end.freeze

  COMMON_BOUNDARY = {
    'data_boundary' => 'synthetic_only',
    'required_app_mode' => 'SIMULATION',
    'real_patient_data_authorized' => false,
    'live_integrations_authorized' => false,
    'prohibited_live_integrations' => %w[
      BPJS VClaim SATUSEHAT payment LIS PACS device other_production_integration
    ],
    'v2_decision_can_override_boundary' => false
  }.freeze

  module_function

  def generate!(root:, output:, fault_after_publications: nil)
    root_path = canonical_root(root)
    output_path = new_output_path(output)
    validate_fault_point!(fault_after_publications)

    _sources, artifact_bytes = deterministic_candidate_bytes(root_path)

    publish_new_candidate!(
      output_path,
      artifact_bytes,
      fault_after_publications: fault_after_publications
    )

    {
      'status' => 'generated_pending_candidate',
      'candidate_directory' => output_path.to_s,
      'bundle_manifest_sha256' => Digest::SHA256.hexdigest(artifact_bytes.fetch('bundle_manifest'))
    }
  rescue G0ProportionalGovernanceV2::Error => e
    raise Error, e.message
  end

  # Retained candidates are deliberately narrower than generic --output
  # generation. The caller supplies only a safe leaf name; the repository root
  # determines the exact parent. Retention creates evidence, never authority.
  def generate_retained!(root:, retained_name:, fault_after_publications: nil)
    root_path = canonical_root(root)
    output_path = new_retained_path(root_path, retained_name)
    validate_fault_point!(fault_after_publications)
    _sources, artifact_bytes = deterministic_candidate_bytes(root_path)

    publish_new_candidate!(
      output_path,
      artifact_bytes,
      fault_after_publications: fault_after_publications
    )
    retained_receipt(root_path, output_path, operation: 'generate_retained_candidate')
  rescue G0ProportionalGovernanceV2::Error => e
    raise Error, e.message
  end

  # Read-only preflight for a previously retained candidate. Verification
  # rejects any path outside the exact retained parent and compares all six
  # files byte-for-byte with a fresh deterministic projection of pinned sources.
  def verify_retained!(root:, path:)
    root_path = canonical_root(root)
    candidate_path = existing_retained_path(root_path, path)
    raise Error, 'retained candidate is incomplete or invalid' unless complete_candidate?(candidate_path)

    _sources, expected_bytes = deterministic_candidate_bytes(root_path)
    PUBLICATION_ORDER.each do |role|
      artifact = candidate_path.join(FILES.fetch(role))
      raise Error, 'retained candidate differs from deterministic pending projection' unless File.binread(artifact) == expected_bytes.fetch(role)
    end

    retained_receipt(root_path, candidate_path, operation: 'verify_retained_candidate')
  rescue G0ProportionalGovernanceV2::Error => e
    raise Error, e.message
  rescue SystemCallError
    raise Error, 'retained candidate is unavailable'
  end

  def complete_candidate?(directory, allow_incomplete: false)
    path = Pathname.new(directory.to_s).expand_path
    return false unless path.directory? && !path.symlink? && path.realpath == path
    expected_children = FILES.values.dup
    expected_children << '.incomplete' if allow_incomplete
    return false unless path.children.map { |child| child.basename.to_s }.sort == expected_children.sort
    return false unless FILES.values.all? { |name| path.join(name).file? && !path.join(name).symlink? }

    manifest = Core.parse_json_file(path.join(FILES.fetch('bundle_manifest')), label: 'candidate bundle manifest')
    Core.assert_closed_schema!(manifest, required: %w[
      artifact_type schema_version bundle_id profile status effect data_boundary
      validator adoption_decision source_manifest source_decision_registers
      source_evidence_map generated_artifacts capability_count
      provisional_engineering_binding_count canonical_order_sha256 publication
    ], label: '$.candidate_bundle_manifest')
    return false unless manifest.fetch('artifact_type') == 'g0_governance_v2_bundle_manifest' &&
                        manifest.fetch('schema_version') == 1 && manifest.fetch('profile') == 'v2' &&
                        manifest.fetch('status') == 'candidate_pending_not_active' &&
                        manifest.fetch('effect') == 'none_no_activation_authority_disposition_deployment_or_acceptance' &&
                        manifest.fetch('data_boundary') == 'synthetic_only' &&
                        manifest.fetch('capability_count') == 268 &&
                        manifest.fetch('provisional_engineering_binding_count') == 14
    expected_bundle_id = "G0-GOVERNANCE-V2-PENDING-#{Core.canonical_sha256(bundle_identity_payload_from_manifest(manifest))[0, 24]}"
    return false unless manifest.fetch('bundle_id') == expected_bundle_id
    expected_names = manifest.dig('publication', 'required_files')
    return false unless expected_names == PUBLICATION_ORDER.map { |role| FILES.fetch(role) }
    publication = manifest.fetch('publication')
    Core.assert_closed_schema!(publication, required: %w[
      required_files manifest_published_last existing_output_policy partial_candidate_usable
    ], label: '$.candidate_bundle_manifest.publication')
    return false unless publication.fetch('manifest_published_last') == true &&
                        publication.fetch('existing_output_policy') == 'reject' &&
                        publication.fetch('partial_candidate_usable') == false

    expected_roles = PUBLICATION_ORDER.reject { |role| role == 'bundle_manifest' }
    generated = manifest.fetch('generated_artifacts')
    return false unless generated.is_a?(Array) && generated.length == expected_roles.length
    generated.each_with_index.all? do |entry, index|
      Core.assert_closed_schema!(entry, required: %w[role path sha256], label: "$.candidate_bundle_manifest.generated_artifacts[#{index}]")
      role = expected_roles.fetch(index)
      next false unless entry.fetch('role') == role && entry.fetch('path') == FILES.fetch(role) &&
                        Core::SHA256_PATTERN.match?(entry.fetch('sha256').to_s)
      artifact = path.join(entry.fetch('path'))
      artifact.file? && !artifact.symlink? && Digest::SHA256.file(artifact).hexdigest == entry.fetch('sha256')
    end
  rescue G0ProportionalGovernanceV2::Error, KeyError, SystemCallError
    false
  end

  def deterministic_candidate_bytes(root_path)
    sources = load_sources(root_path)
    artifacts = build_artifacts(sources)
    artifact_bytes = artifacts.to_h do |role, artifact|
      [role, Core.canonical_json(artifact) + "\n"]
    end
    artifact_bytes['bundle_manifest'] = Core.canonical_json(
      build_bundle_manifest(sources, artifact_bytes)
    ) + "\n"

    artifact_bytes.each do |role, bytes|
      parsed = Core.parse_json(bytes, label: "candidate #{role}")
      Core.assert_secret_free!(parsed, label: "candidate #{role}")
    end
    [sources, artifact_bytes]
  end
  private_class_method :deterministic_candidate_bytes

  def retained_receipt(root_path, output_path, operation:)
    manifest_path = output_path.join(FILES.fetch('bundle_manifest'))
    manifest = Core.parse_json_file(manifest_path, label: 'retained candidate bundle manifest')
    {
      'operation' => operation,
      'status' => 'retained_candidate_no_authority',
      'effect' => 'retained_candidate_no_authority',
      'candidate_directory' => output_path.relative_path_from(root_path).to_s,
      'bundle_manifest_sha256' => Digest::SHA256.file(manifest_path).hexdigest,
      'bundle_id' => manifest.fetch('bundle_id')
    }
  rescue KeyError, SystemCallError
    raise Error, 'retained candidate receipt unavailable'
  end
  private_class_method :retained_receipt

  def load_sources(root_path)
    contract = parse_source(root_path, CONTRACT_PATH)
    v1_manifest = parse_source(root_path, V1_MANIFEST_PATH)
    begin
      Core.validate_contract!(contract, root: root_path.to_s)
    rescue G0ProportionalGovernanceV2::Error
      raise Error, 'governance v2 contract validation failed'
    end
    begin
      Core.verify_v1_manifest!(v1_manifest, root: root_path.to_s)
    rescue G0ProportionalGovernanceV2::Error
      raise Error, 'historical v1 manifest validation failed'
    end

    manifest_sha_by_path = v1_manifest.fetch('files').to_h do |entry|
      [entry.fetch('path'), entry.fetch('sha256')]
    end
    batch_manifest_path = contract.dig('universe', 'canonical_order_source')
    batch_manifest = parse_source_exact(root_path, batch_manifest_path, manifest_sha_by_path.fetch(batch_manifest_path))
    canonical_ids = Core.canonical_requirement_ids(contract, root: root_path.to_s)
    batch_membership = batch_manifest.fetch('batches')
    unless ('A'..'G').flat_map { |batch| batch_membership.fetch(batch) } == canonical_ids
      raise Error, 'canonical batch membership differs from manifest order'
    end

    owner_policy = parse_source_exact(root_path, V1_OWNER_POLICY_PATH, manifest_sha_by_path.fetch(V1_OWNER_POLICY_PATH))
    owner_policy_hashes = owner_policy.fetch('requirement_policies').to_h do |policy|
      [policy.fetch('requirement_id'), policy.fetch('source_row_sha256')]
    end
    unless owner_policy_hashes.keys.sort == canonical_ids.sort
      raise Error, 'historical owner policy row-hash universe changed'
    end

    registers = REGISTER_PATHS.to_h do |batch, path|
      expected_sha = manifest_sha_by_path.fetch(path)
      register = parse_source_exact(root_path, path, expected_sha)
      validate_source_register!(register, batch, batch_membership.fetch(batch), owner_policy_hashes)
      [batch, { 'path' => path, 'sha256' => expected_sha, 'document' => register }]
    end

    evidence_map = parse_source_exact(root_path, EVIDENCE_MAP_PATH, EVIDENCE_MAP_SHA256)
    validate_evidence_map!(evidence_map, canonical_ids)

    {
      root: root_path,
      contract: contract,
      contract_sha256: Digest::SHA256.file(safe_source(root_path, CONTRACT_PATH)).hexdigest,
      v1_manifest: v1_manifest,
      batch_manifest: batch_manifest,
      batch_manifest_path: batch_manifest_path,
      batch_manifest_sha256: manifest_sha_by_path.fetch(batch_manifest_path),
      canonical_ids: canonical_ids,
      registers: registers,
      evidence_map: evidence_map
    }
  end
  private_class_method :load_sources

  def validate_source_register!(register, batch, expected_ids, owner_policy_hashes)
    required = %w[
      schema_version register_id batch register_status data_boundary
      external_integrations source_manifest evidence_directory purpose entries
    ]
    missing = required - register.keys
    raise Error, "source register #{batch}: missing fields #{missing.join(', ')}" unless missing.empty?
    unless register.fetch('schema_version') == 1 && register.fetch('batch') == batch &&
           register.fetch('register_status') == 'pending' &&
           register.fetch('data_boundary') == 'synthetic_only'
      raise Error, "source register #{batch}: is not the immutable pending synthetic source"
    end
    entries = register.fetch('entries')
    raise Error, "source register #{batch}: entries must be an array" unless entries.is_a?(Array)
    manifest_ids = entries.map { |entry| entry.fetch('requirement_id') }
    unless manifest_ids == expected_ids
      raise Error, "source register #{batch}: requirement IDs or order differ from canonical manifest"
    end
    entries.each_with_index do |entry, index|
      unless entry.fetch('batch') == batch && entry.dig('decision', 'status') == 'pending' &&
             entry.dig('decision', 'canonical_disposition') == 'pending' &&
             entry.dig('decision', 'target', 'kind') == 'pending' &&
             entry.dig('approval', 'status') == 'pending' && entry.dig('approval', 'identity').nil? &&
             entry.dig('accountable_owner', 'appointment_status') == 'pending' &&
             entry.dig('accountable_owner', 'identity').nil?
        raise Error, "source register #{batch} entry #{index}: owner or decision state is not pending"
      end
      unless entry.fetch('appointment_dependencies').all? { |dependency| dependency.fetch('status') == 'pending' }
        raise Error, "source register #{batch} entry #{index}: appointment dependency is not pending"
      end
      unless entry.fetch('gate_authority_appointments', []).all? { |dependency| dependency.fetch('status') == 'pending' }
        raise Error, "source register #{batch} entry #{index}: gate authority appointment is not pending"
      end
      historical_sha = owner_canonical_sha256(entry)
      unless historical_sha == owner_policy_hashes.fetch(entry.fetch('requirement_id'))
        raise Error, "source register #{batch} entry #{index}: historical v1 row hash mismatch"
      end
    end
  rescue KeyError => e
    raise Error, "source register #{batch}: malformed pending source (#{e.class})"
  end
  private_class_method :validate_source_register!

  def validate_evidence_map!(map, canonical_ids)
    required = %w[
      schema_version artifact_id snapshot_date data_boundary capability_defaults
      workflow_binding_default capability_overrides workflows
    ]
    Core.assert_closed_schema!(map, required: required, label: '$.historical_evidence_map')
    unless map.fetch('schema_version') == 1 && map.fetch('data_boundary') == 'synthetic_only'
      raise Error, 'historical engineering evidence map identity or boundary changed'
    end
    default = map.fetch('workflow_binding_default')
    Core.assert_closed_schema!(default, required: %w[status scenario_ids authority_reference], label: '$.historical_evidence_map.workflow_binding_default')
    unless default == { 'status' => 'PENDING', 'scenario_ids' => [], 'authority_reference' => nil }
      raise Error, 'historical engineering binding default changed'
    end
    overrides = map.fetch('capability_overrides')
    unless overrides.is_a?(Hash) && overrides.length == 14 && (overrides.keys - canonical_ids).empty?
      raise Error, 'historical engineering binding override universe changed'
    end
    overrides.each do |id, override|
      binding = override.fetch('workflow_binding')
      Core.assert_closed_schema!(binding, required: %w[status scenario_ids authority_reference], label: "$.historical_evidence_map.capability_overrides.#{id}.workflow_binding")
      unless binding.fetch('status') == 'PROVISIONAL' && binding.fetch('scenario_ids').is_a?(Array) &&
             !binding.fetch('scenario_ids').empty? && binding.fetch('authority_reference').nil?
        raise Error, "historical engineering binding #{id}: is not provisional and non-authoritative"
      end
    end
  rescue KeyError => e
    raise Error, "historical engineering evidence map is malformed (#{e.class})"
  end
  private_class_method :validate_evidence_map!

  def build_artifacts(sources)
    entries = build_expanded_rows(sources)

    common = {
      'schema_version' => 1,
      'profile' => 'v2',
      'status' => 'pending',
      'effect' => 'none_no_authority_or_activation',
      'data_boundary' => 'synthetic_only'
    }
    authority = common.merge(
      'artifact_type' => 'g0_governance_v2_authority_register',
      'register_id' => 'G0-GOVERNANCE-V2-AUTHORITY-REGISTER',
      'authorities' => []
    )
    owner = common.merge(
      'artifact_type' => 'g0_governance_v2_owner_register',
      'register_id' => 'G0-GOVERNANCE-V2-OWNER-REGISTER',
      'owners' => []
    )
    events = common.merge(
      'artifact_type' => 'g0_governance_v2_decision_event_register',
      'register_id' => 'G0-GOVERNANCE-V2-DECISION-EVENT-REGISTER',
      'events' => []
    )
    expanded = {
      'artifact_type' => 'g0_governance_v2_expanded_decision_register',
      'schema_version' => 1,
      'register_id' => 'G0-GOVERNANCE-V2-EXPANDED-DECISION-REGISTER',
      'profile' => 'v2',
      'status' => 'pending_projection',
      'effect' => 'none_no_capability_disposition_or_implementation_authority',
      'data_boundary' => 'synthetic_only',
      'required_app_mode' => 'SIMULATION',
      'source_manifest' => source_pointer(sources.fetch(:batch_manifest_path), sources.fetch(:batch_manifest_sha256)),
      'source_evidence_map' => source_pointer(EVIDENCE_MAP_PATH, EVIDENCE_MAP_SHA256),
      'canonical_order' => sources.fetch(:canonical_ids),
      'entries' => entries
    }
    expanded_bytes = Core.canonical_json(expanded) + "\n"
    gate = {
      'artifact_type' => 'g0_governance_v2_gate_register',
      'schema_version' => 1,
      'register_id' => 'G0-GOVERNANCE-V2-GATE-REGISTER',
      'profile' => 'v2',
      'status' => 'derived_pending',
      'effect' => 'none_no_activation_or_acceptance',
      'data_boundary' => 'synthetic_only',
      'source_expanded_register' => source_pointer(FILES.fetch('expanded_decision_register'), Digest::SHA256.hexdigest(expanded_bytes)),
      'project_g0' => 'OPEN',
      'project_g3' => 'OPEN',
      'counts' => {
        'total' => entries.length,
        'terminal' => 0,
        'nonterminal' => entries.length,
        'authorized' => 0,
        'deferred' => 0,
        'retired' => 0,
        'excluded' => 0
      },
      'derivation' => {
        'all_rows_terminal' => false,
        'all_required_owner_records_present' => false,
        'all_required_decision_events_present' => false,
        'all_required_approvals_present' => false,
        'provisional_engineering_binding_effect' => 'none'
      }
    }

    {
      'authority_register' => authority,
      'owner_register' => owner,
      'decision_event_register' => events,
      'expanded_decision_register' => expanded,
      'gate_register' => gate
    }
  end
  private_class_method :build_artifacts

  def build_expanded_rows(sources)
    by_id = {}
    sources.fetch(:registers).each do |batch, descriptor|
      register = descriptor.fetch('document')
      register.fetch('entries').each_with_index do |entry, index|
        id = entry.fetch('requirement_id')
        raise Error, "duplicate source requirement ID #{id}" if by_id.key?(id)
        by_id[id] = expanded_row(entry, index, descriptor, register, sources.fetch(:evidence_map))
      end
    end
    ordered = sources.fetch(:canonical_ids).map do |id|
      by_id.fetch(id) { raise Error, "missing source requirement ID #{id}" }
    end
    raise Error, 'expanded pending projection must contain exactly 268 rows' unless ordered.length == 268 && by_id.length == 268

    ordered
  end
  private_class_method :build_expanded_rows

  def expanded_row(entry, index, descriptor, register, evidence_map)
    binding = evidence_map.fetch('capability_overrides').fetch(
      entry.fetch('requirement_id'),
      { 'workflow_binding' => evidence_map.fetch('workflow_binding_default') }
    ).fetch('workflow_binding')

    {
      'requirement_id' => entry.fetch('requirement_id'),
      'batch' => entry.fetch('batch'),
      'source_decision_pointer' => {
        'path' => descriptor.fetch('path'),
        'source_sha256' => descriptor.fetch('sha256'),
        'register_id' => register.fetch('register_id'),
        'entry_index_base' => 0,
        'entry_index' => index,
        'entry_sha256' => owner_canonical_sha256(entry),
        'entry_v2_canonical_sha256' => Core.canonical_sha256(entry)
      },
      'legacy_menu' => entry.fetch('legacy_menu'),
      'synthetic_scenarios' => entry.fetch('synthetic_scenarios'),
      'appointment_dependencies' => entry.fetch('appointment_dependencies'),
      'upstream_dependencies' => {
        'upstream_requirement_dependencies' => entry.fetch('upstream_requirement_dependencies', []),
        'dependency_gates' => entry.fetch('dependency_gates', []),
        'intra_batch_dependencies' => entry.fetch('intra_batch_dependencies', []),
        'source_dependencies' => entry.fetch('source_dependencies', [])
      },
      'affected_domains' => entry.fetch('affected_domains'),
      'downstream_effects' => entry.fetch('downstream_impacts'),
      'source_pending_fragments' => {
        'decision' => entry.fetch('decision'),
        'accountable_owner' => entry.fetch('accountable_owner'),
        'approval' => entry.fetch('approval'),
        'co_owners' => entry.fetch('co_owners'),
        'gate_authority_appointments' => entry.fetch('gate_authority_appointments', [])
      },
      'boundary' => deep_copy(COMMON_BOUNDARY),
      'governance_state' => 'PENDING',
      'owner_state' => 'DRAFT',
      'owner_assignment_status' => 'pending',
      'decision_status' => 'pending',
      'owner_record_id' => nil,
      'decision_event_id' => nil,
      'owner_outcome' => nil,
      'canonical_disposition' => nil,
      'target' => nil,
      'consequence_map' => nil,
      'derived_tier' => nil,
      'product_authority_id' => nil,
      'domain_authority_ids' => [],
      'co_owner_authority_ids' => [],
      'independent_review_ids' => [],
      'evidence_references' => [],
      'conditions' => [],
      'predecessor_event_id' => nil,
      'predecessor_event_sha256' => nil,
      'supersedes_event_id' => nil,
      'g0_terminal' => false,
      'implementation_authorized' => false,
      'provisional_engineering_binding' => {
        'status' => binding.fetch('status'),
        'scenario_ids' => binding.fetch('scenario_ids'),
        'authority_reference' => binding.fetch('authority_reference'),
        'comparison_dimension' => 'engineering_only',
        'owner_authority_effect' => 'none',
        'tier_effect' => 'none',
        'approval_effect' => 'none',
        'disposition_effect' => 'none',
        'gate_effect' => 'none'
      }
    }
  end
  private_class_method :expanded_row

  def build_bundle_manifest(sources, artifact_bytes)
    generated = PUBLICATION_ORDER.reject { |role| role == 'bundle_manifest' }.map do |role|
      {
        'role' => role,
        'path' => FILES.fetch(role),
        'sha256' => Digest::SHA256.hexdigest(artifact_bytes.fetch(role))
      }
    end
    register_sources = ('A'..'G').map do |batch|
      descriptor = sources.fetch(:registers).fetch(batch)
      {
        'batch' => batch,
        'path' => descriptor.fetch('path'),
        'sha256' => descriptor.fetch('sha256'),
        'register_id' => descriptor.dig('document', 'register_id'),
        'entry_count' => descriptor.dig('document', 'entries').length
      }
    end
    validator_identity = {
      'contract_sha256' => sources.fetch(:contract_sha256),
      'version' => sources.dig(:contract, 'validator', 'version')
    }
    adoption_decision = sources.fetch(:contract).dig('adopted_sources', 'adoption_decision')
    identity_payload = {
      'generated_artifacts' => generated,
      'source_decision_registers' => register_sources,
      'source_manifest' => source_pointer(sources.fetch(:batch_manifest_path), sources.fetch(:batch_manifest_sha256)),
      'source_evidence_map' => source_pointer(EVIDENCE_MAP_PATH, EVIDENCE_MAP_SHA256),
      'validator' => validator_identity,
      'adoption_decision' => adoption_decision
    }
    provisional_count = sources.fetch(:evidence_map).fetch('capability_overrides').length

    {
      'artifact_type' => 'g0_governance_v2_bundle_manifest',
      'schema_version' => 1,
      'bundle_id' => "G0-GOVERNANCE-V2-PENDING-#{Core.canonical_sha256(identity_payload)[0, 24]}",
      'profile' => 'v2',
      'status' => 'candidate_pending_not_active',
      'effect' => 'none_no_activation_authority_disposition_deployment_or_acceptance',
      'data_boundary' => 'synthetic_only',
      'validator' => {
        'path' => 'scripts/g0-proportional-governance-v2.rb',
        'contract_path' => CONTRACT_PATH,
        'contract_sha256' => sources.fetch(:contract_sha256),
        'version' => sources.dig(:contract, 'validator', 'version')
      },
      'adoption_decision' => adoption_decision,
      'source_manifest' => source_pointer(sources.fetch(:batch_manifest_path), sources.fetch(:batch_manifest_sha256)),
      'source_decision_registers' => register_sources,
      'source_evidence_map' => source_pointer(EVIDENCE_MAP_PATH, EVIDENCE_MAP_SHA256),
      'generated_artifacts' => generated,
      'capability_count' => sources.fetch(:canonical_ids).length,
      'provisional_engineering_binding_count' => provisional_count,
      'canonical_order_sha256' => Core.canonical_sha256(sources.fetch(:canonical_ids)),
      'publication' => {
        'required_files' => PUBLICATION_ORDER.map { |role| FILES.fetch(role) },
        'manifest_published_last' => true,
        'existing_output_policy' => 'reject',
        'partial_candidate_usable' => false
      }
    }
  end
  private_class_method :build_bundle_manifest

  def bundle_identity_payload_from_manifest(manifest)
    validator = manifest.fetch('validator')
    {
      'generated_artifacts' => manifest.fetch('generated_artifacts'),
      'source_decision_registers' => manifest.fetch('source_decision_registers'),
      'source_manifest' => manifest.fetch('source_manifest'),
      'source_evidence_map' => manifest.fetch('source_evidence_map'),
      'validator' => {
        'contract_sha256' => validator.fetch('contract_sha256'),
        'version' => validator.fetch('version')
      },
      'adoption_decision' => manifest.fetch('adoption_decision')
    }
  end
  private_class_method :bundle_identity_payload_from_manifest

  def source_pointer(path, sha256)
    { 'path' => path, 'sha256' => sha256 }
  end
  private_class_method :source_pointer

  def publish_new_candidate!(output_path, artifact_bytes, fault_after_publications:)
    begin
      Dir.mkdir(output_path, 0o700)
    rescue Errno::EEXIST
      raise Error, 'output directory already exists; refusing overwrite'
    rescue SystemCallError => e
      raise Error, "cannot create new output directory (#{e.class})"
    end

    staging = output_path.join('.pending')
    incomplete = output_path.join('.incomplete')
    write_exclusive(incomplete, "G0 governance v2 candidate is incomplete and unusable.\n")
    Dir.mkdir(staging, 0o700)
    PUBLICATION_ORDER.each do |role|
      name = FILES.fetch(role)
      write_exclusive(staging.join(name), artifact_bytes.fetch(role))
    end
    fsync_directory(staging)

    published = 0
    PUBLICATION_ORDER.each do |role|
      name = FILES.fetch(role)
      File.rename(staging.join(name), output_path.join(name))
      published += 1
      if fault_after_publications && published == fault_after_publications
        raise Error, 'injected publication failure'
      end
    end
    Dir.rmdir(staging)
    fsync_directory(output_path)
    unless complete_candidate?(output_path, allow_incomplete: true)
      raise Error, 'published candidate failed completeness verification'
    end
    File.unlink(incomplete)
    fsync_directory(output_path, strict: false)
  rescue Error
    raise
  rescue SystemCallError => e
    raise Error, "candidate publication failed (#{e.class})"
  end
  private_class_method :publish_new_candidate!

  def write_exclusive(path, bytes)
    File.open(path, File::WRONLY | File::CREAT | File::EXCL, 0o600) do |file|
      file.binmode
      file.write(bytes)
      file.flush
      file.fsync
    end
  end
  private_class_method :write_exclusive

  def fsync_directory(path, strict: true)
    File.open(path, File::RDONLY) { |directory| directory.fsync }
  rescue Errno::EINVAL, Errno::ENOTSUP
    # Candidate generation is not consumer selection. The manifest-last rule
    # still prevents a partial directory from being accepted as a bundle.
    true
  rescue SystemCallError
    raise if strict

    # Once the complete candidate has been independently verified, failure to
    # persist removal of the incomplete sentinel can only leave it fail-closed.
    true
  end
  private_class_method :fsync_directory

  def canonical_root(root)
    path = Pathname.new(root.to_s).expand_path
    raise UsageError, 'repository root must be an existing directory' unless path.directory?
    raise UsageError, 'repository root must not contain symlink components' unless path.realpath == path

    path.realpath
  rescue SystemCallError
    raise UsageError, 'repository root is unavailable'
  end
  private_class_method :canonical_root

  def new_output_path(output)
    raise UsageError, '--output is required' if output.nil? || output.to_s.strip.empty?
    candidate = Pathname.new(output.to_s).expand_path
    leaf = candidate.basename.to_s
    raise UsageError, 'output directory leaf must be a safe name' if ['', '.', '..'].include?(leaf) || leaf.include?("\0")
    parent = candidate.parent
    raise UsageError, 'output parent must be an existing directory' unless parent.directory?
    raise UsageError, 'output parent must not contain symlink components' unless parent.realpath == parent

    canonical = parent.realpath.join(leaf)
    begin
      canonical.lstat
      raise UsageError, 'output directory already exists; refusing overwrite'
    rescue Errno::ENOENT
      canonical
    end
  rescue SystemCallError => e
    raise UsageError, "output path is unavailable (#{e.class})"
  end
  private_class_method :new_output_path

  def new_retained_path(root_path, retained_name)
    leaf = retained_leaf!(retained_name)
    parent = retained_parent_path(root_path, create: true)
    candidate = parent.join(leaf)
    begin
      candidate.lstat
      raise UsageError, 'retained candidate already exists; refusing overwrite'
    rescue Errno::ENOENT
      candidate
    end
  rescue SystemCallError => e
    raise UsageError, "retained candidate path is unavailable (#{e.class})"
  end
  private_class_method :new_retained_path

  def existing_retained_path(root_path, raw_path)
    value = raw_path.to_s
    raise UsageError, '--verify-retained requires a candidate path' if value.strip.empty?
    raise UsageError, 'retained candidate path is unsafe' if value.include?("\0")
    input = Pathname.new(value)
    raise UsageError, 'retained candidate path is unsafe' if input.each_filename.any? { |part| part == '..' }

    parent = retained_parent_path(root_path, create: false)
    candidate = (input.absolute? ? input : root_path.join(input)).expand_path
    retained_leaf!(candidate.basename.to_s)
    raise UsageError, 'retained candidate must be a direct child of the canonical retained parent' unless candidate.parent == parent
    stat = candidate.lstat
    unless stat.directory? && !stat.symlink? && candidate.realpath == candidate
      raise UsageError, 'retained candidate must be a real non-symlink directory'
    end
    unless same_device?(candidate, parent)
      raise UsageError, 'retained candidate must be on the phase-0 filesystem'
    end
    candidate
  rescue SystemCallError => e
    raise UsageError, "retained candidate path is unavailable (#{e.class})"
  end
  private_class_method :existing_retained_path

  def retained_parent_path(root_path, create:)
    phase0 = root_path.join(PHASE)
    phase_stat = phase0.lstat
    unless phase_stat.directory? && !phase_stat.symlink? && phase0.realpath == phase0
      raise UsageError, 'phase-0 directory must be a real non-symlink directory'
    end

    parent = root_path.join(RETAINED_PARENT)
    if create
      begin
        Dir.mkdir(parent, 0o700)
        fsync_directory(phase0, strict: false)
      rescue Errno::EEXIST
        # A concurrent creator is acceptable only if the canonical checks below
        # prove that the resulting object is the exact retained directory.
      end
    end
    parent_stat = parent.lstat
    unless parent_stat.directory? && !parent_stat.symlink? && parent.realpath == parent
      raise UsageError, 'canonical retained parent must be a real non-symlink directory'
    end
    unless same_device?(parent, phase0)
      raise UsageError, 'canonical retained parent must be on the phase-0 filesystem'
    end
    parent
  rescue Errno::ENOENT
    raise UsageError, 'canonical retained parent does not exist'
  rescue SystemCallError => e
    raise UsageError, "canonical retained parent is unavailable (#{e.class})"
  end
  private_class_method :retained_parent_path

  def retained_leaf!(value)
    unless value.is_a?(String) && SAFE_RETAINED_NAME_PATTERN.match?(value)
      raise UsageError, 'retained candidate name must be a safe direct-child leaf'
    end
    value
  end
  private_class_method :retained_leaf!

  def same_device?(left, right)
    Pathname.new(left).lstat.dev == Pathname.new(right).lstat.dev
  end
  private_class_method :same_device?

  def safe_source(root_path, relative)
    unless relative.is_a?(String) && Core::SAFE_RELATIVE_PATH_PATTERN.match?(relative)
      raise Error, 'unsafe repository-relative source path'
    end
    path = root_path.join(relative)
    begin
      stat = path.lstat
      raise Error, 'source path must be a regular non-symlink file' unless stat.file? && !stat.symlink?
      real = path.realpath
      raise Error, 'source path contains a symlinked component' unless real == path
      unless real.to_s.start_with?("#{root_path}#{File::SEPARATOR}")
        raise Error, 'source path resolves outside repository root'
      end
      real
    rescue SystemCallError
      raise Error, 'source path is unavailable'
    end
  end
  private_class_method :safe_source

  def parse_source(root_path, relative)
    Core.parse_json_file(safe_source(root_path, relative), label: relative)
  rescue G0ProportionalGovernanceV2::Error
    raise Error, "#{relative}: invalid JSON source"
  end
  private_class_method :parse_source

  def parse_source_exact(root_path, relative, expected_sha256)
    path = safe_source(root_path, relative)
    unless Digest::SHA256.file(path).hexdigest == expected_sha256
      raise Error, "#{relative}: historical byte hash mismatch"
    end
    parse_source(root_path, relative)
  end
  private_class_method :parse_source_exact

  def deep_copy(value)
    Marshal.load(Marshal.dump(value))
  end
  private_class_method :deep_copy

  def owner_canonical_sha256(value)
    Digest::SHA256.hexdigest(JSON.generate(owner_canonical_value(value), ascii_only: true).encode(Encoding::UTF_8))
  end
  private_class_method :owner_canonical_sha256

  def owner_canonical_value(value)
    case value
    when Hash
      value.keys.each do |key|
        raise Error, 'historical row canonicalization requires ASCII string keys' unless key.is_a?(String) && key.ascii_only?
      end
      value.keys.sort.each_with_object({}) do |key, result|
        result[key] = owner_canonical_value(value.fetch(key))
      end
    when Array
      value.map { |item| owner_canonical_value(item) }
    when String
      normalized = value.encode(Encoding::UTF_8).unicode_normalize(:nfc)
      normalized.match?(/\A\d{4}-\d{2}-\d{2}T/) ? Time.iso8601(normalized).utc.iso8601 : normalized
    when Integer
      raise Error, 'historical row canonicalization rejects out-of-range integer' unless value.between?(-(2**63), (2**63) - 1)
      value
    when Float
      raise Error, 'historical row canonicalization rejects floating-point values'
    when TrueClass, FalseClass, NilClass
      value
    else
      raise Error, 'historical row canonicalization rejects value type'
    end
  end
  private_class_method :owner_canonical_value

  def validate_fault_point!(fault_after)
    return if fault_after.nil?
    unless fault_after.is_a?(Integer) && fault_after.between?(1, PUBLICATION_ORDER.length - 1)
      raise ArgumentError, 'fault_after_publications must stop before bundle manifest publication'
    end
  end
  private_class_method :validate_fault_point!

  def cli(argv)
    options = { root: File.expand_path('..', __dir__) }
    seen = {}
    parser = OptionParser.new do |opts|
      opts.banner = 'Usage: generate-g0-proportional-governance-v2.rb (--output NEW_DIR | --retained-name SAFE_LEAF | --verify-retained PATH) [--root REPOSITORY_ROOT]'
      opts.on('--root PATH') do |value|
        raise OptionParser::InvalidOption, 'duplicate --root' if seen[:root]
        seen[:root] = true
        options[:root] = value
      end
      opts.on('--output PATH') do |value|
        raise OptionParser::InvalidOption, 'duplicate --output' if seen[:output]
        seen[:output] = true
        options[:output] = value
      end
      opts.on('--retained-name SAFE_LEAF') do |value|
        raise OptionParser::InvalidOption, 'duplicate --retained-name' if seen[:retained_name]
        seen[:retained_name] = true
        options[:retained_name] = value
      end
      opts.on('--verify-retained PATH') do |value|
        raise OptionParser::InvalidOption, 'duplicate --verify-retained' if seen[:verify_retained]
        seen[:verify_retained] = true
        options[:verify_retained] = value
      end
    end
    parser.parse!(argv)
    raise UsageError, 'unexpected positional arguments' unless argv.empty?
    selected_modes = %i[output retained_name verify_retained].count { |key| options.key?(key) }
    raise UsageError, '--output, --retained-name, and --verify-retained are mutually exclusive' if selected_modes > 1

    receipt = if options.key?(:retained_name)
                generate_retained!(root: options.fetch(:root), retained_name: options.fetch(:retained_name))
              elsif options.key?(:verify_retained)
                verify_retained!(root: options.fetch(:root), path: options.fetch(:verify_retained))
              else
                generate!(root: options.fetch(:root), output: options[:output])
              end
    $stdout.write(Core.canonical_json(receipt) + "\n")
    0
  rescue OptionParser::ParseError
    $stderr.puts('usage error: invalid or duplicate option')
    2
  rescue UsageError => e
    $stderr.puts("usage error: #{e.message}")
    2
  rescue Error => e
    $stderr.puts("generation failed: #{e.message}")
    1
  end
end

exit G0ProportionalGovernanceV2Generator.cli(ARGV) if $PROGRAM_NAME == __FILE__
