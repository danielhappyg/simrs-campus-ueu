#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'json'
require 'optparse'
require 'pathname'
require 'time'

require_relative 'g0-proportional-governance-v2'

# Read-only migration comparator for the never-activated governance-v1
# evidence and a caller-supplied governance-v2 candidate bundle. It confers no
# authority, changes no consumer pointer, and never writes to either source.
module G0GovernanceV1V2Comparator
  class Error < StandardError; end
  class UsageError < Error; end
  class ComparisonError < Error; end

  Core = G0ProportionalGovernanceV2

  CONTRACT_PATH = 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_CONTRACT.json'
  V1_MANIFEST_PATH = 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V1_HISTORICAL_HASH_MANIFEST.json'
  SOURCE_MANIFEST_PATH = 'docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json'
  SOURCE_MANIFEST_SHA256 = '59b0f05b94e2a659b342016c5b9e5e9e011f3a8e4c0f0efd3dda12a7b65fa9ca'
  EVIDENCE_MAP_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_2026-08-27.json'
  EVIDENCE_MAP_SHA256 = '3cfcc9898f4b541cea85cd448638fc300cae48eb5172c3dffd598925d9244ea0'
  OWNER_POLICY_PATH = 'docs/new-simrs-rebuild/phase-0/G0_OWNER_AUTHORITY_POLICY_2026-08-25.json'
  REGISTER_PATHS = ('A'..'G').to_h do |batch|
    [batch, "docs/new-simrs-rebuild/phase-0/G0_BATCH_#{batch}_DECISION_REGISTER_2026-08-25.json"]
  end.freeze
  CANDIDATE_FILES = %w[
    G0_GOVERNANCE_V2_AUTHORITY_REGISTER.json
    G0_GOVERNANCE_V2_OWNER_REGISTER.json
    G0_GOVERNANCE_V2_DECISION_EVENT_REGISTER.json
    G0_GOVERNANCE_V2_EXPANDED_DECISION_REGISTER.json
    G0_GOVERNANCE_V2_GATE_REGISTER.json
    G0_GOVERNANCE_V2_BUNDLE_MANIFEST.json
  ].freeze
  EXPANDED_FILE = 'G0_GOVERNANCE_V2_EXPANDED_DECISION_REGISTER.json'
  BUNDLE_FILE = 'G0_GOVERNANCE_V2_BUNDLE_MANIFEST.json'
  AUTHORITY_FILE = 'G0_GOVERNANCE_V2_AUTHORITY_REGISTER.json'
  OWNER_FILE = 'G0_GOVERNANCE_V2_OWNER_REGISTER.json'
  EVENT_FILE = 'G0_GOVERNANCE_V2_DECISION_EVENT_REGISTER.json'
  GATE_FILE = 'G0_GOVERNANCE_V2_GATE_REGISTER.json'
  CANDIDATE_ROLES = {
    'authority_register' => AUTHORITY_FILE,
    'owner_register' => OWNER_FILE,
    'decision_event_register' => EVENT_FILE,
    'expanded_decision_register' => EXPANDED_FILE,
    'gate_register' => GATE_FILE,
    'bundle_manifest' => BUNDLE_FILE
  }.freeze
  EXPANDED_KEYS = %w[
    artifact_type schema_version register_id profile status effect data_boundary
    required_app_mode source_manifest source_evidence_map canonical_order entries
  ].freeze
  ROW_KEYS = %w[
    requirement_id batch source_decision_pointer legacy_menu synthetic_scenarios
    appointment_dependencies upstream_dependencies affected_domains downstream_effects
    source_pending_fragments boundary governance_state owner_state owner_assignment_status decision_status
    owner_record_id decision_event_id owner_outcome canonical_disposition target
    consequence_map derived_tier product_authority_id domain_authority_ids
    co_owner_authority_ids independent_review_ids evidence_references conditions
    predecessor_event_id predecessor_event_sha256 supersedes_event_id g0_terminal
    implementation_authorized provisional_engineering_binding
  ].freeze
  SOURCE_POINTER_KEYS = %w[
    path source_sha256 register_id entry_index_base entry_index entry_sha256
    entry_v2_canonical_sha256
  ].freeze
  SOURCE_PENDING_FRAGMENT_KEYS = %w[
    decision accountable_owner approval co_owners gate_authority_appointments
  ].freeze
  UPSTREAM_DEPENDENCY_KEYS = %w[
    upstream_requirement_dependencies dependency_gates intra_batch_dependencies
    source_dependencies
  ].freeze
  BOUNDARY_KEYS = %w[
    data_boundary required_app_mode real_patient_data_authorized
    live_integrations_authorized prohibited_live_integrations
    v2_decision_can_override_boundary
  ].freeze
  BINDING_KEYS = %w[
    status scenario_ids authority_reference comparison_dimension
    owner_authority_effect tier_effect approval_effect disposition_effect gate_effect
  ].freeze
  PROHIBITED_LIVE_INTEGRATIONS = %w[
    BPJS VClaim SATUSEHAT payment LIS PACS device other_production_integration
  ].freeze
  UPSTREAM_SOURCE_FIELDS = %w[
    upstream_requirement_dependencies dependency_gates intra_batch_dependencies
    source_dependencies
  ].freeze
  PENDING_NULL_FIELDS = %w[
    owner_record_id decision_event_id owner_outcome canonical_disposition target
    consequence_map derived_tier product_authority_id predecessor_event_id
    predecessor_event_sha256 supersedes_event_id
  ].freeze
  PENDING_EMPTY_ARRAY_FIELDS = %w[
    domain_authority_ids co_owner_authority_ids independent_review_ids
    evidence_references conditions
  ].freeze
  SIMPLE_REGISTER_KEYS = %w[
    artifact_type schema_version register_id profile status effect data_boundary
  ].freeze
  GATE_KEYS = %w[
    artifact_type schema_version register_id profile status effect data_boundary
    source_expanded_register project_g0 project_g3 counts derivation
  ].freeze
  GATE_COUNT_KEYS = %w[total terminal nonterminal authorized deferred retired excluded].freeze
  GATE_DERIVATION_KEYS = %w[
    all_rows_terminal all_required_owner_records_present
    all_required_decision_events_present all_required_approvals_present
    provisional_engineering_binding_effect
  ].freeze
  BUNDLE_KEYS = %w[
    artifact_type schema_version bundle_id profile status effect data_boundary
    validator adoption_decision source_manifest source_decision_registers
    source_evidence_map generated_artifacts capability_count
    provisional_engineering_binding_count canonical_order_sha256 publication
  ].freeze
  BUNDLE_VALIDATOR_KEYS = %w[path contract_path contract_sha256 version].freeze
  BUNDLE_SOURCE_REGISTER_KEYS = %w[batch path sha256 register_id entry_count].freeze
  BUNDLE_ARTIFACT_KEYS = %w[role path sha256].freeze
  BUNDLE_PUBLICATION_KEYS = %w[
    required_files manifest_published_last existing_output_policy partial_candidate_usable
  ].freeze

  module_function

  def compare!(root:, candidate_bundle:)
    root_path = secure_directory!(root, label: '$.root')
    contract = parse_repository_json(root_path, CONTRACT_PATH, '$.contract')
    v1_manifest = parse_repository_json(root_path, V1_MANIFEST_PATH, '$.v1_manifest')
    Core.validate_contract!(contract, root: root_path.to_s)
    Core.verify_v1_manifest!(v1_manifest, root: root_path.to_s)
    v1_before = working_v1_hashes(root_path, v1_manifest)

    candidate_path = secure_directory!(candidate_bundle, label: '$.candidate_bundle')
    candidate = load_closed_candidate!(candidate_path)

    source_manifest = parse_bound_source(root_path, SOURCE_MANIFEST_PATH, SOURCE_MANIFEST_SHA256, '$.source_manifest')
    evidence_map = parse_bound_source(root_path, EVIDENCE_MAP_PATH, EVIDENCE_MAP_SHA256, '$.source_evidence_map')
    owner_policy_sha = Core::V1_EXPECTED_INVENTORY.to_h.fetch(OWNER_POLICY_PATH)
    owner_policy = parse_bound_source(root_path, OWNER_POLICY_PATH, owner_policy_sha, '$.source_owner_policy')
    expected = expected_rows(root_path, contract, source_manifest, evidence_map, owner_policy)

    validate_expanded!(candidate.fetch(EXPANDED_FILE), expected, contract)
    validate_candidate_semantics!(root_path, candidate_path, candidate, expected, contract)
    v1_after = working_v1_hashes(root_path, v1_manifest)
    fail_comparison('$.v1_manifest.files: comparator changed historical bytes') unless v1_after == v1_before

    row = {
      'status' => 'PASS',
      'comparison' => 'read_only_v1_v2_migration_parity',
      'capability_count' => expected.length,
      'provisional_engineering_binding_count' => expected.count { |row| row.dig('provisional_engineering_binding', 'status') == 'PROVISIONAL' },
      'v1_historical_file_count' => v1_manifest.fetch('files').length,
      'authority_effect' => 'none',
      'activation_effect' => 'none',
      'gate_effect' => 'none'
    }
  rescue Core::Error => e
    raise ComparisonError, e.message
  end

  def expected_rows(root_path, contract, source_manifest, evidence_map, owner_policy)
    dependency_order = contract.fetch('universe').fetch('dependency_order')
    Core.assert_closed_schema!(source_manifest,
                               required: %w[schema_version purpose source_baseline dependency_order expected_counts batches],
                               label: '$.source_manifest')
    unless source_manifest.fetch('schema_version') == 1 && source_manifest.fetch('dependency_order') == dependency_order
      fail_comparison('$.source_manifest: schema or dependency order drift')
    end
    Core.assert_closed_schema!(source_manifest.fetch('expected_counts'), required: dependency_order,
                               label: '$.source_manifest.expected_counts')
    Core.assert_closed_schema!(source_manifest.fetch('batches'), required: dependency_order,
                               label: '$.source_manifest.batches')
    validate_evidence_map!(evidence_map)
    policy_rows = validate_owner_policy_source_hashes!(owner_policy)

    rows = []
    dependency_order.each do |batch|
      path = REGISTER_PATHS.fetch(batch)
      expected_sha = Core::V1_EXPECTED_INVENTORY.to_h.fetch(path)
      register = parse_bound_source(root_path, path, expected_sha, "$.source_registers.#{batch}")
      validate_source_register!(register, batch, source_manifest)

      register.fetch('entries').each_with_index do |entry, index|
        validate_v1_pending_entry!(entry, batch, index)
        rows << expected_row(entry, batch, index, path, expected_sha, register.fetch('register_id'), evidence_map,
                             policy_rows.fetch(entry.fetch('requirement_id')))
      end
    end

    canonical_ids = dependency_order.flat_map { |batch| source_manifest.fetch('batches').fetch(batch) }
    unless rows.length == 268 && rows.map { |row| row.fetch('requirement_id') } == canonical_ids && canonical_ids.uniq.length == 268
      fail_comparison('$.source_registers: canonical 268-row universe mismatch')
    end
    unless rows.count { |row| row.dig('provisional_engineering_binding', 'status') == 'PROVISIONAL' } == 14
      fail_comparison('$.source_evidence_map: expected exactly 14 provisional engineering bindings')
    end
    rows
  end

  def validate_expanded!(expanded, expected_rows, contract)
    Core.assert_closed_schema!(expanded, required: EXPANDED_KEYS, label: '$.candidate.expanded_register')
    assert_candidate_secret_free!(expanded)
    unless expanded.fetch('artifact_type') == 'g0_governance_v2_expanded_decision_register' &&
           expanded.fetch('schema_version') == 1 && expanded.fetch('profile') == 'v2' &&
           expanded.fetch('status') == 'pending_projection' &&
           expanded.fetch('effect') == 'none_no_capability_disposition_or_implementation_authority' &&
           expanded.fetch('data_boundary') == 'synthetic_only' && expanded.fetch('required_app_mode') == 'SIMULATION'
      fail_comparison('$.candidate.expanded_register: identity, status, effect, or boundary drift')
    end
    validate_source_reference!(expanded.fetch('source_manifest'), SOURCE_MANIFEST_PATH, SOURCE_MANIFEST_SHA256,
                               '$.candidate.expanded_register.source_manifest')
    validate_source_reference!(expanded.fetch('source_evidence_map'), EVIDENCE_MAP_PATH, EVIDENCE_MAP_SHA256,
                               '$.candidate.expanded_register.source_evidence_map')

    canonical_ids = expected_rows.map { |row| row.fetch('requirement_id') }
    unless expanded.fetch('canonical_order') == canonical_ids
      fail_comparison('$.candidate.expanded_register.canonical_order: missing, extra, duplicate, or order drift')
    end
    entries = expanded.fetch('entries')
    Core.assert_type!(entries, Array, label: '$.candidate.expanded_register.entries')
    unless entries.length == 268 && entries.map { |row| row.is_a?(Hash) ? row['requirement_id'] : nil } == canonical_ids
      fail_comparison('$.candidate.expanded_register.entries: expected exactly 268 rows in canonical order')
    end

    entries.zip(expected_rows).each_with_index do |(actual, expected), index|
      label = "$.candidate.expanded_register.entries[#{index}]"
      validate_candidate_row_schema!(actual, label, contract)
      expected.each do |field, expected_value|
        next if actual.fetch(field) == expected_value

        fail_comparison("#{label}.#{field}: migration parity drift")
      end
    end
    true
  end

  def validate_candidate_row_schema!(row, label, contract)
    Core.assert_closed_schema!(row, required: ROW_KEYS, label: label)
    Core.assert_closed_schema!(row.fetch('source_decision_pointer'), required: SOURCE_POINTER_KEYS,
                               label: "#{label}.source_decision_pointer")
    Core.assert_closed_schema!(row.fetch('upstream_dependencies'), required: UPSTREAM_DEPENDENCY_KEYS,
                               label: "#{label}.upstream_dependencies")
    Core.assert_closed_schema!(row.fetch('source_pending_fragments'), required: SOURCE_PENDING_FRAGMENT_KEYS,
                               label: "#{label}.source_pending_fragments")
    Core.assert_closed_schema!(row.fetch('boundary'), required: BOUNDARY_KEYS, label: "#{label}.boundary")
    Core.assert_closed_schema!(row.fetch('provisional_engineering_binding'), required: BINDING_KEYS,
                               label: "#{label}.provisional_engineering_binding")
    Core.assert_type!(row.fetch('g0_terminal'), :boolean, label: "#{label}.g0_terminal")
    Core.assert_type!(row.fetch('implementation_authorized'), :boolean, label: "#{label}.implementation_authorized")
    PENDING_EMPTY_ARRAY_FIELDS.each do |field|
      Core.assert_type!(row.fetch(field), Array, label: "#{label}.#{field}")
    end
    unless contract.fetch('closed_values').fetch('governance_states').include?(row.fetch('governance_state')) &&
           contract.fetch('closed_values').fetch('owner_states').include?(row.fetch('owner_state'))
      fail_comparison("#{label}: unknown governance or owner state")
    end
    true
  end

  def expected_row(entry, batch, index, source_path, source_sha, register_id, evidence_map, policy_row)
    historical_row_sha = Digest::SHA256.hexdigest(v1_owner_canonical_json(entry))
    unless policy_row.fetch('batch') == batch && policy_row.fetch('batch_register_id') == register_id &&
           policy_row.fetch('batch_register_sha256') == source_sha && policy_row.fetch('source_row_sha256') == historical_row_sha
      fail_comparison("$.source_owner_policy.requirement_policies.#{entry.fetch('requirement_id')}: historical row binding drift")
    end
    row = {
      'requirement_id' => entry.fetch('requirement_id'),
      'batch' => batch,
      'source_decision_pointer' => {
        'path' => source_path,
        'source_sha256' => source_sha,
        'register_id' => register_id,
        'entry_index_base' => 0,
        'entry_index' => index,
        # This is a governance-v1 source-row digest. Preserve the historical
        # owner canonicalization exactly; v2 canonical JSON is intentionally a
        # different contract and must not reinterpret these 268 hashes.
        'entry_sha256' => historical_row_sha,
        'entry_v2_canonical_sha256' => Core.canonical_sha256(entry)
      },
      'legacy_menu' => entry.fetch('legacy_menu'),
      'synthetic_scenarios' => entry.fetch('synthetic_scenarios'),
      'appointment_dependencies' => entry.fetch('appointment_dependencies'),
      'upstream_dependencies' => UPSTREAM_SOURCE_FIELDS.to_h { |field| [field, entry.fetch(field, [])] },
      'affected_domains' => entry.fetch('affected_domains'),
      'downstream_effects' => entry.fetch('downstream_impacts'),
      'source_pending_fragments' => {
        'decision' => entry.fetch('decision'),
        'accountable_owner' => entry.fetch('accountable_owner'),
        'approval' => entry.fetch('approval'),
        'co_owners' => entry.fetch('co_owners'),
        'gate_authority_appointments' => entry.fetch('gate_authority_appointments', [])
      },
      'boundary' => pending_boundary,
      'governance_state' => 'PENDING',
      'owner_state' => 'DRAFT',
      'owner_assignment_status' => 'pending',
      'decision_status' => 'pending',
      'g0_terminal' => false,
      'implementation_authorized' => false,
      'provisional_engineering_binding' => expected_binding(entry.fetch('requirement_id'), evidence_map)
    }
    PENDING_NULL_FIELDS.each { |field| row[field] = nil }
    PENDING_EMPTY_ARRAY_FIELDS.each { |field| row[field] = [] }
    row
  end

  def pending_boundary
    {
      'data_boundary' => 'synthetic_only',
      'required_app_mode' => 'SIMULATION',
      'real_patient_data_authorized' => false,
      'live_integrations_authorized' => false,
      'prohibited_live_integrations' => PROHIBITED_LIVE_INTEGRATIONS,
      'v2_decision_can_override_boundary' => false
    }
  end

  def expected_binding(requirement_id, evidence_map)
    override = evidence_map.fetch('capability_overrides').fetch(requirement_id, nil)
    source = override ? override.fetch('workflow_binding') : evidence_map.fetch('workflow_binding_default')
    {
      'status' => source.fetch('status'),
      'scenario_ids' => source.fetch('scenario_ids'),
      'authority_reference' => source.fetch('authority_reference'),
      'comparison_dimension' => 'engineering_only',
      'owner_authority_effect' => 'none',
      'tier_effect' => 'none',
      'approval_effect' => 'none',
      'disposition_effect' => 'none',
      'gate_effect' => 'none'
    }
  end

  def validate_source_register!(register, batch, source_manifest)
    Core.assert_type!(register, Hash, label: "$.source_registers.#{batch}")
    unless register.fetch('schema_version') == 1 && register.fetch('batch') == batch &&
           register.fetch('register_status') == 'pending' && register.fetch('data_boundary') == 'synthetic_only' &&
           register.fetch('external_integrations') == 'disabled' &&
           register.fetch('source_manifest') == SOURCE_MANIFEST_PATH
      fail_comparison("$.source_registers.#{batch}: identity, pending state, or boundary drift")
    end
    ids = source_manifest.fetch('batches').fetch(batch)
    entries = register.fetch('entries')
    unless entries.is_a?(Array) && entries.length == source_manifest.fetch('expected_counts').fetch(batch) &&
           entries.map { |entry| entry['requirement_id'] } == ids
      fail_comparison("$.source_registers.#{batch}.entries: manifest parity drift")
    end
  rescue KeyError => e
    fail_comparison("$.source_registers.#{batch}: missing source field #{e.key}")
  end

  def validate_v1_pending_entry!(entry, batch, index)
    label = "$.source_registers.#{batch}.entries[#{index}]"
    decision = entry.fetch('decision')
    owner = entry.fetch('accountable_owner')
    approval = entry.fetch('approval')
    dependencies = entry.fetch('appointment_dependencies')
    unless decision.fetch('status') == 'pending' && decision.fetch('canonical_disposition') == 'pending' &&
           decision.fetch('target') == { 'kind' => 'pending', 'reference' => nil, 'exclusions' => [] } &&
           decision.fetch('rationale').nil?
      fail_comparison("#{label}.decision: v1 pending decision drift")
    end
    unless owner.fetch('identity').nil? && owner.fetch('appointment_status') == 'pending' &&
           owner.fetch('appointed_scope').nil? && owner.fetch('appointment_date').nil? &&
           owner.fetch('appointment_reference').nil? && owner.fetch('artifact_sha256').nil?
      fail_comparison("#{label}.accountable_owner: v1 pending owner drift")
    end
    unless approval.fetch('status') == 'pending' && approval.values_at('identity', 'date', 'reference', 'artifact_sha256').all?(&:nil?) &&
           approval.fetch('conditions') == []
      fail_comparison("#{label}.approval: v1 pending approval drift")
    end
    unless dependencies.is_a?(Array) && dependencies.all? do |dependency|
      dependency.fetch('status') == 'pending' &&
        dependency.values_at('identity', 'date', 'reference', 'artifact_sha256').all?(&:nil?)
    end
      fail_comparison("#{label}.appointment_dependencies: v1 pending authority drift")
    end
  rescue KeyError => e
    fail_comparison("#{label}: missing v1 pending field #{e.key}")
  end

  def validate_evidence_map!(map)
    Core.assert_closed_schema!(map,
                               required: %w[schema_version artifact_id snapshot_date data_boundary capability_defaults workflow_binding_default capability_overrides workflows],
                               label: '$.source_evidence_map')
    unless map.fetch('schema_version') == 1 && map.fetch('artifact_id') == 'G0-G3-COVERAGE-EVIDENCE-MAP-2026-08-27' &&
           map.fetch('data_boundary') == 'synthetic_only'
      fail_comparison('$.source_evidence_map: identity or synthetic boundary drift')
    end
    default = map.fetch('workflow_binding_default')
    validate_historical_binding!(default, '$.source_evidence_map.workflow_binding_default')
    overrides = map.fetch('capability_overrides')
    Core.assert_type!(overrides, Hash, label: '$.source_evidence_map.capability_overrides')
    bindings = overrides.each_with_object({}) do |(id, value), result|
      result[id] = value['workflow_binding'] if value.is_a?(Hash) && value.key?('workflow_binding')
    end
    unless bindings.length == 14
      fail_comparison('$.source_evidence_map.capability_overrides: expected exactly 14 workflow bindings')
    end
    bindings.each { |id, binding| validate_historical_binding!(binding, "$.source_evidence_map.capability_overrides.#{id}.workflow_binding", expected_status: 'PROVISIONAL') }
  end

  def validate_owner_policy_source_hashes!(policy)
    Core.assert_type!(policy, Hash, label: '$.source_owner_policy')
    unless policy.fetch('schema_version') == 1 && policy.fetch('policy_status') == 'proposal' &&
           policy.fetch('data_boundary') == 'synthetic_only' && policy.dig('approval', 'status') == 'pending'
      fail_comparison('$.source_owner_policy: identity, pending state, or boundary drift')
    end
    rows = policy.fetch('requirement_policies')
    unless rows.is_a?(Array) && rows.length == 268 && rows.all? { |row| row.is_a?(Hash) }
      fail_comparison('$.source_owner_policy.requirement_policies: expected exact 268 policy rows')
    end
    indexed = rows.to_h { |row| [row.fetch('requirement_id'), row] }
    unless indexed.length == 268
      fail_comparison('$.source_owner_policy.requirement_policies: duplicate requirement ID')
    end
    indexed
  rescue KeyError => e
    fail_comparison("$.source_owner_policy: missing field #{e.key}")
  end

  def validate_historical_binding!(binding, label, expected_status: 'PENDING')
    Core.assert_closed_schema!(binding, required: %w[status scenario_ids authority_reference], label: label)
    unless binding.fetch('status') == expected_status && binding.fetch('scenario_ids').is_a?(Array) &&
           binding.fetch('scenario_ids').all? { |id| id.is_a?(String) } && binding.fetch('authority_reference').nil?
      fail_comparison("#{label}: invalid read-only engineering binding")
    end
  end

  def validate_source_reference!(reference, path, sha, label)
    Core.assert_closed_schema!(reference, required: %w[path sha256], label: label)
    fail_comparison("#{label}: source binding drift") unless reference == { 'path' => path, 'sha256' => sha }
  end

  def validate_candidate_semantics!(root_path, candidate_path, candidate, expected_rows, contract)
    validate_empty_register!(candidate.fetch(AUTHORITY_FILE),
                             artifact_type: 'g0_governance_v2_authority_register',
                             register_id: 'G0-GOVERNANCE-V2-AUTHORITY-REGISTER', collection: 'authorities')
    validate_empty_register!(candidate.fetch(OWNER_FILE),
                             artifact_type: 'g0_governance_v2_owner_register',
                             register_id: 'G0-GOVERNANCE-V2-OWNER-REGISTER', collection: 'owners')
    validate_empty_register!(candidate.fetch(EVENT_FILE),
                             artifact_type: 'g0_governance_v2_decision_event_register',
                             register_id: 'G0-GOVERNANCE-V2-DECISION-EVENT-REGISTER', collection: 'events')
    validate_gate!(candidate.fetch(GATE_FILE), candidate_path, expected_rows)
    validate_bundle_manifest!(root_path, candidate_path, candidate, expected_rows, contract)
  end

  def validate_empty_register!(register, artifact_type:, register_id:, collection:)
    label = "$.candidate.#{collection}"
    Core.assert_closed_schema!(register, required: SIMPLE_REGISTER_KEYS + [collection], label: label)
    unless register == {
      'schema_version' => 1,
      'profile' => 'v2',
      'status' => 'pending',
      'effect' => 'none_no_authority_or_activation',
      'data_boundary' => 'synthetic_only',
      'artifact_type' => artifact_type,
      'register_id' => register_id,
      collection => []
    }
      fail_comparison("#{label}: must remain exactly empty, pending, and non-authoritative")
    end
  end

  def validate_gate!(gate, candidate_path, expected_rows)
    Core.assert_closed_schema!(gate, required: GATE_KEYS, label: '$.candidate.gate_register')
    Core.assert_closed_schema!(gate.fetch('source_expanded_register'), required: %w[path sha256],
                               label: '$.candidate.gate_register.source_expanded_register')
    Core.assert_closed_schema!(gate.fetch('counts'), required: GATE_COUNT_KEYS,
                               label: '$.candidate.gate_register.counts')
    Core.assert_closed_schema!(gate.fetch('derivation'), required: GATE_DERIVATION_KEYS,
                               label: '$.candidate.gate_register.derivation')
    expanded_sha = Digest::SHA256.file(candidate_path.join(EXPANDED_FILE)).hexdigest
    expected = {
      'artifact_type' => 'g0_governance_v2_gate_register',
      'schema_version' => 1,
      'register_id' => 'G0-GOVERNANCE-V2-GATE-REGISTER',
      'profile' => 'v2',
      'status' => 'derived_pending',
      'effect' => 'none_no_activation_or_acceptance',
      'data_boundary' => 'synthetic_only',
      'source_expanded_register' => { 'path' => EXPANDED_FILE, 'sha256' => expanded_sha },
      'project_g0' => 'OPEN',
      'project_g3' => 'OPEN',
      'counts' => {
        'total' => expected_rows.length, 'terminal' => 0, 'nonterminal' => expected_rows.length,
        'authorized' => 0, 'deferred' => 0, 'retired' => 0, 'excluded' => 0
      },
      'derivation' => {
        'all_rows_terminal' => false,
        'all_required_owner_records_present' => false,
        'all_required_decision_events_present' => false,
        'all_required_approvals_present' => false,
        'provisional_engineering_binding_effect' => 'none'
      }
    }
    fail_comparison('$.candidate.gate_register: governance promotion or derivation drift') unless gate == expected
  end

  def validate_bundle_manifest!(root_path, candidate_path, candidate, expected_rows, contract)
    manifest = candidate.fetch(BUNDLE_FILE)
    Core.assert_closed_schema!(manifest, required: BUNDLE_KEYS, label: '$.candidate.bundle_manifest')
    assert_candidate_secret_free!(manifest)
    Core.assert_closed_schema!(manifest.fetch('validator'), required: BUNDLE_VALIDATOR_KEYS,
                               label: '$.candidate.bundle_manifest.validator')
    validate_source_reference!(manifest.fetch('adoption_decision'),
                               contract.dig('adopted_sources', 'adoption_decision', 'path'),
                               contract.dig('adopted_sources', 'adoption_decision', 'sha256'),
                               '$.candidate.bundle_manifest.adoption_decision')
    validate_source_reference!(manifest.fetch('source_manifest'), SOURCE_MANIFEST_PATH, SOURCE_MANIFEST_SHA256,
                               '$.candidate.bundle_manifest.source_manifest')
    validate_source_reference!(manifest.fetch('source_evidence_map'), EVIDENCE_MAP_PATH, EVIDENCE_MAP_SHA256,
                               '$.candidate.bundle_manifest.source_evidence_map')
    # Contract hash is independently resolved from the repository source, not
    # from the candidate. The accepted contract validator already bound it.
    contract_file = secure_regular_file!(root_path.join(CONTRACT_PATH), root_path,
                                         label: '$.candidate.bundle_manifest.validator.contract_path')
    expected_contract_sha = Digest::SHA256.file(contract_file).hexdigest
    expected_validator = {
      'path' => 'scripts/g0-proportional-governance-v2.rb',
      'contract_path' => CONTRACT_PATH,
      'contract_sha256' => expected_contract_sha,
      'version' => contract.dig('validator', 'version')
    }
    fail_comparison('$.candidate.bundle_manifest.validator: validator contract drift') unless manifest.fetch('validator') == expected_validator

    source_registers = manifest.fetch('source_decision_registers')
    unless source_registers.is_a?(Array) && source_registers.length == 7
      fail_comparison('$.candidate.bundle_manifest.source_decision_registers: expected exact A-G sources')
    end
    ('A'..'G').each_with_index do |batch, index|
      entry = source_registers.fetch(index)
      Core.assert_closed_schema!(entry, required: BUNDLE_SOURCE_REGISTER_KEYS,
                                 label: "$.candidate.bundle_manifest.source_decision_registers[#{index}]")
      source_row = expected_rows.find { |row| row.fetch('batch') == batch }
      count = expected_rows.count { |row| row.fetch('batch') == batch }
      pointer = source_row.fetch('source_decision_pointer')
      expected = {
        'batch' => batch, 'path' => pointer.fetch('path'), 'sha256' => pointer.fetch('source_sha256'),
        'register_id' => pointer.fetch('register_id'), 'entry_count' => count
      }
      fail_comparison("$.candidate.bundle_manifest.source_decision_registers[#{index}]: source drift") unless entry == expected
    end

    artifacts = manifest.fetch('generated_artifacts')
    expected_roles = CANDIDATE_ROLES.keys - ['bundle_manifest']
    actual_roles = artifacts.is_a?(Array) ? artifacts.map { |entry| entry.is_a?(Hash) ? entry['role'] : nil } : []
    unless actual_roles == expected_roles && actual_roles.uniq == actual_roles
      fail_comparison('$.candidate.bundle_manifest.generated_artifacts: partial, extra, duplicate, or order drift')
    end
    artifacts.each_with_index do |entry, index|
      label = "$.candidate.bundle_manifest.generated_artifacts[#{index}]"
      Core.assert_closed_schema!(entry, required: BUNDLE_ARTIFACT_KEYS, label: label)
      expected_path = CANDIDATE_ROLES.fetch(entry.fetch('role'))
      fail_comparison("#{label}.path: role/path drift") unless entry.fetch('path') == expected_path
      expected_sha = Digest::SHA256.file(candidate_path.join(entry.fetch('path'))).hexdigest
      fail_comparison("#{label}.sha256: stale candidate artifact hash") unless entry.fetch('sha256') == expected_sha
    end
    Core.assert_closed_schema!(manifest.fetch('publication'), required: BUNDLE_PUBLICATION_KEYS,
                               label: '$.candidate.bundle_manifest.publication')
    expected_publication = {
      'required_files' => CANDIDATE_ROLES.values,
      'manifest_published_last' => true,
      'existing_output_policy' => 'reject',
      'partial_candidate_usable' => false
    }
    fail_comparison('$.candidate.bundle_manifest.publication: publication safety drift') unless manifest.fetch('publication') == expected_publication
    identity_payload = {
      'generated_artifacts' => artifacts,
      'source_decision_registers' => source_registers,
      'source_manifest' => manifest.fetch('source_manifest'),
      'source_evidence_map' => manifest.fetch('source_evidence_map'),
      'validator' => {
        'contract_sha256' => expected_validator.fetch('contract_sha256'),
        'version' => expected_validator.fetch('version')
      },
      'adoption_decision' => manifest.fetch('adoption_decision')
    }
    expected_bundle_id = "G0-GOVERNANCE-V2-PENDING-#{Core.canonical_sha256(identity_payload)[0, 24]}"
    unless manifest.fetch('artifact_type') == 'g0_governance_v2_bundle_manifest' && manifest.fetch('schema_version') == 1 &&
           manifest.fetch('bundle_id') == expected_bundle_id &&
           manifest.fetch('profile') == 'v2' && manifest.fetch('status') == 'candidate_pending_not_active' &&
           manifest.fetch('effect') == 'none_no_activation_authority_disposition_deployment_or_acceptance' &&
           manifest.fetch('data_boundary') == 'synthetic_only' && manifest.fetch('capability_count') == 268 &&
           manifest.fetch('provisional_engineering_binding_count') == 14 &&
           manifest.fetch('canonical_order_sha256') == Core.canonical_sha256(expected_rows.map { |row| row.fetch('requirement_id') })
      fail_comparison('$.candidate.bundle_manifest: identity, non-authority effect, counts, or canonical order drift')
    end
  end

  def working_v1_hashes(root_path, manifest)
    manifest.fetch('files').to_h do |entry|
      path = entry.fetch('path')
      file = secure_regular_file!(root_path.join(path), root_path, label: "$.v1_manifest.files.#{entry.fetch('order')}")
      sha = Digest::SHA256.file(file).hexdigest
      fail_comparison("$.v1_manifest.files.#{entry.fetch('order')}: current v1 byte drift") unless sha == entry.fetch('sha256')
      [path, sha]
    end
  end

  def load_closed_candidate!(candidate_path)
    actual = candidate_path.children.map { |path| path.basename.to_s }.sort
    unless actual == CANDIDATE_FILES.sort
      fail_comparison('$.candidate_bundle: partial or unknown candidate artifact set')
    end
    CANDIDATE_FILES.to_h do |name|
      path = secure_regular_file!(candidate_path.join(name), candidate_path, label: "$.candidate_bundle.#{name}")
      begin
        parsed = Core.parse_json_file(path, label: "$.candidate_bundle.#{name}")
      rescue Core::ParseError
        raise ComparisonError, "$.candidate_bundle.#{name}: invalid JSON rejected"
      end
      assert_candidate_secret_free!(parsed)
      [name, parsed]
    end
  end

  def parse_repository_json(root_path, relative_path, label)
    path = secure_regular_file!(root_path.join(relative_path), root_path, label: label)
    Core.parse_json_file(path, label: label)
  rescue Core::ParseError
    raise ComparisonError, "#{label}: invalid JSON rejected"
  end

  def parse_bound_source(root_path, relative_path, expected_sha, label)
    path = secure_regular_file!(root_path.join(relative_path), root_path, label: label)
    fail_comparison("#{label}: source byte hash drift") unless Digest::SHA256.file(path).hexdigest == expected_sha
    Core.parse_json_file(path, label: label)
  end

  def secure_directory!(path, label:)
    candidate = Pathname.new(path.to_s).expand_path
    components = []
    cursor = candidate
    until cursor.root?
      components << cursor
      cursor = cursor.parent
    end
    components.reverse_each do |component|
      stat = component.lstat
      raise ComparisonError, "#{label}: symlinked path component" if stat.symlink?
    rescue SystemCallError
      raise ComparisonError, "#{label}: path unavailable"
    end
    raise ComparisonError, "#{label}: expected directory" unless candidate.directory?
    candidate
  end

  def secure_regular_file!(path, containing_root, label:)
    root = Pathname.new(containing_root).realpath
    candidate = Pathname.new(path).expand_path
    relative = candidate.relative_path_from(root)
    if relative.each_filename.any? { |part| part == '..' }
      raise ComparisonError, "#{label}: path escapes its allowed root"
    end
    current = root
    parts = relative.each_filename.to_a
    parts.each_with_index do |part, index|
      current = current.join(part)
      stat = current.lstat
      raise ComparisonError, "#{label}: symlinked path component" if stat.symlink?
      if index == parts.length - 1
        raise ComparisonError, "#{label}: expected regular file" unless stat.file?
      else
        raise ComparisonError, "#{label}: parent is not a directory" unless stat.directory?
      end
    rescue SystemCallError
      raise ComparisonError, "#{label}: path unavailable"
    end
    unless current.realpath.to_s.start_with?("#{root}#{File::SEPARATOR}")
      raise ComparisonError, "#{label}: path resolves outside its allowed root"
    end
    current
  rescue ArgumentError
    raise ComparisonError, "#{label}: path escapes its allowed root"
  end

  def fail_comparison(message)
    raise ComparisonError, message
  end

  # Candidate keys are untrusted. The shared scanner exposes safe logical
  # locations for normal validation, but a malicious secret embedded in a key
  # could otherwise become part of that location. Comparator diagnostics stay
  # deliberately generic and never echo candidate keys or values.
  def assert_candidate_secret_free!(value)
    return true if Core.secret_locations(value, path: '$.candidate').empty?

    raise ComparisonError, '$.candidate: secret-like content rejected'
  end

  def v1_owner_canonical_json(value)
    JSON.generate(v1_owner_canonical_value(value), ascii_only: true).encode(Encoding::UTF_8)
  rescue EncodingError, ArgumentError => e
    raise ComparisonError, "$.source_registers: historical row canonicalization failed (#{e.class})"
  end

  def v1_owner_canonical_value(value)
    case value
    when Hash
      unless value.keys.all? { |key| key.is_a?(String) && key.ascii_only? }
        raise ArgumentError, 'canonical JSON keys must be ASCII strings'
      end
      value.keys.sort.to_h { |key| [key, v1_owner_canonical_value(value.fetch(key))] }
    when Array
      value.map { |entry| v1_owner_canonical_value(entry) }
    when String
      normalized = value.encode(Encoding::UTF_8).unicode_normalize(:nfc)
      normalized.match?(/\A\d{4}-\d{2}-\d{2}T/) ? Time.iso8601(normalized).utc.iso8601 : normalized
    when Integer
      raise ArgumentError, 'canonical JSON integer outside int64' unless value.between?(-(2**63), (2**63) - 1)
      value
    when Float
      raise ArgumentError, 'canonical JSON forbids floats'
    when TrueClass, FalseClass, NilClass
      value
    else
      raise ArgumentError, 'unsupported canonical JSON type'
    end
  end

  def run_cli(argv, stdout: $stdout, stderr: $stderr)
    options = {}
    parser = OptionParser.new do |opts|
      opts.banner = 'Usage: compare-g0-governance-v1-v2.rb --candidate-bundle PATH [--root PATH]'
      opts.on('--candidate-bundle PATH') { |value| raise UsageError, '--candidate-bundle specified more than once' if options.key?(:candidate_bundle); options[:candidate_bundle] = value }
      opts.on('--root PATH') { |value| raise UsageError, '--root specified more than once' if options.key?(:root); options[:root] = value }
    end
    begin
      parser.parse!(argv)
      raise UsageError, 'unexpected positional arguments' unless argv.empty?
      raise UsageError, '--candidate-bundle is required' unless options[:candidate_bundle]
      options[:root] ||= File.expand_path('..', __dir__)
      receipt = compare!(root: options.fetch(:root), candidate_bundle: options.fetch(:candidate_bundle))
      stdout.puts(JSON.generate(receipt))
      0
    rescue OptionParser::ParseError
      stderr.puts('usage_error: invalid command-line usage')
      2
    rescue UsageError => e
      stderr.puts("usage_error: #{e.message}")
      2
    rescue ComparisonError => e
      stderr.puts("comparison_failed: #{e.message}")
      1
    end
  end
end

exit G0GovernanceV1V2Comparator.run_cli(ARGV) if $PROGRAM_NAME == __FILE__
