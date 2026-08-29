#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'date'
require 'json'
require 'optparse'
require 'pathname'
require 'securerandom'

require_relative 'g0-proportional-governance-v2'

# Produces a deterministic, engineering-only projection of the historical
# coverage evidence map. The output is deliberately incapable of recording
# owner or gate facts and is never an activation, disposition, or acceptance
# source.
module G0G3CoverageEvidenceMapV2
  class Error < StandardError; end
  ValidationError = Error
  class UsageError < Error; end

  Core = G0ProportionalGovernanceV2

  HISTORICAL_MAP_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_2026-08-27.json'
  HISTORICAL_MAP_SHA256 = '3cfcc9898f4b541cea85cd448638fc300cae48eb5172c3dffd598925d9244ea0'
  CANONICAL_ORDER_PATH = 'docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json'
  CANONICAL_ORDER_SHA256 = '59b0f05b94e2a659b342016c5b9e5e9e011f3a8e4c0f0efd3dda12a7b65fa9ca'
  GENERATOR_PATH = 'scripts/generate-g0-g3-coverage-evidence-map-v2.rb'
  SNAPSHOT_DATE = '2026-08-29'

  TOP_LEVEL_KEYS = %w[
    artifact_type schema_version artifact_id snapshot_date data_boundary
    source_evidence_map canonical_order_source explicit_evidence_inputs
    capability_defaults workflow_observation_default capabilities workflows
    provenance
  ].freeze
  SOURCE_KEYS = %w[path sha256].freeze
  ENGINEERING_KEYS = %w[
    runtime_availability automated_evidence database_engine_evidence hosted_uat
    reconciliation defect_status evidence_paths
  ].freeze
  DATABASE_KEYS = %w[sqlite postgresql_17 mysql_8_4 mysql_other].freeze
  WORKFLOW_OBSERVATION_KEYS = %w[status scenario_ids].freeze
  CAPABILITY_KEYS = %w[capability_id engineering_evidence workflow_observation].freeze
  WORKFLOW_KEYS = %w[
    workflow_id runtime_availability automated_evidence database_engine_evidence
    hosted_uat reconciliation defect_status evidence_paths
  ].freeze
  PROVENANCE_KEYS = %w[
    generator source_byte_hash canonical_json capability_count
    historical_engineering_override_count workflow_count explicit_evidence_input_count
  ].freeze

  SOURCE_MAP_KEYS = %w[
    schema_version artifact_id snapshot_date data_boundary capability_defaults
    workflow_binding_default capability_overrides workflows
  ].freeze
  SOURCE_ENGINEERING_KEYS = (ENGINEERING_KEYS + ['owner_acceptance']).freeze
  SOURCE_BINDING_KEYS = %w[status scenario_ids authority_reference].freeze
  SOURCE_WORKFLOW_KEYS = (WORKFLOW_KEYS + %w[owner_acceptance note]).freeze
  MANIFEST_KEYS = %w[
    schema_version purpose source_baseline dependency_order expected_counts batches
  ].freeze

  # These normalized aliases identify fields capable of carrying governance or
  # identity semantics. Generated documents are rejected recursively before
  # publication, including case, punctuation, and separator variants.
  FORBIDDEN_KEY_ALIASES = %w[
    governance owner coowner productowner domainowner owneridentity identity appointment
    approve approval approver signoff decision decisionevent verdict disposition
    outcome consequence consequenceflag tier riskclass risklevel pointer selector
    selection consumer consumerbinding bundle gate g0 g3 authority controlauthority
    authorization authorized acceptance accepted reviewer decider actor quorum vote
    signature activation deployment release role capacity
  ].freeze

  module_function

  def generate!(root:, output:, current_evidence_paths: [], fault_after: nil, source_read_hook: nil)
    validate_fault_after!(fault_after)
    root_path = canonical_root(root)
    output_path = new_output_path(root_path, output)
    sources = load_sources(root_path, source_read_hook: source_read_hook)
    historical_paths = historical_evidence_paths(sources.fetch(:historical_map))
    extras = normalize_extra_paths(current_evidence_paths, historical_paths)
    document = build_document(
      root_path,
      sources,
      explicit_paths: (historical_paths + extras).sort,
      source_read_hook: source_read_hook
    )
    validate_generated_document!(document, root: root_path, source_read_hook: source_read_hook)
    bytes = Core.canonical_json(document) + "\n"

    publish_exclusive!(
      root_path,
      output_path,
      bytes,
      fault_after: fault_after,
      source_read_hook: source_read_hook
    )

    {
      'status' => 'generated_engineering_evidence_map',
      'output_path' => repository_relative(root_path, output_path),
      'sha256' => Digest::SHA256.hexdigest(bytes)
    }
  rescue Core::Error => e
    raise Error, e.message
  end

  def validate_generated_document!(document, root:, source_read_hook: nil)
    root_path = canonical_root(root)
    assert_secret_free!(document, label: '$.engineering_evidence_map_v2')
    assert_no_forbidden_keys!(document)
    validate_document_shape!(document)

    sources = load_sources(root_path, source_read_hook: source_read_hook)
    historical_paths = historical_evidence_paths(sources.fetch(:historical_map))
    explicit_paths = document.fetch('explicit_evidence_inputs').map { |entry| entry.fetch('path') }
    extras = explicit_paths - historical_paths
    unless explicit_paths.sort == explicit_paths && explicit_paths.uniq == explicit_paths &&
           (historical_paths - explicit_paths).empty?
      raise Error, '$.explicit_evidence_inputs: paths must be unique, sorted, and include every historical evidence path'
    end

    expected = build_document(
      root_path,
      sources,
      explicit_paths: (historical_paths + extras).sort,
      source_read_hook: source_read_hook
    )
    unless Core.canonical_json(document) == Core.canonical_json(expected)
      raise Error, '$.engineering_evidence_map_v2: generated document differs from exact engineering projection'
    end

    true
  rescue Core::Error => e
    raise Error, e.message
  end

  def build_document(root_path, sources, explicit_paths:, source_read_hook: nil)
    historical = sources.fetch(:historical_map)
    ids = sources.fetch(:capability_ids)
    overrides = historical.fetch('capability_overrides')
    explicit_inputs = explicit_paths.map.with_index do |relative, index|
      validate_evidence_date!(relative, label: "$.explicit_evidence_inputs[#{index}].path")
      bytes = safe_read(root_path, relative, label: "$.explicit_evidence_inputs[#{index}]", hook: source_read_hook)
      { 'path' => relative, 'sha256' => Digest::SHA256.hexdigest(bytes) }
    end

    default_engineering = project_engineering(historical.fetch('capability_defaults'))
    default_workflow = project_workflow_observation(historical.fetch('workflow_binding_default'))
    capabilities = ids.map do |capability_id|
      source = overrides[capability_id]
      {
        'capability_id' => capability_id,
        'engineering_evidence' => source ? project_engineering(source.fetch('engineering_evidence')) : deep_copy(default_engineering),
        'workflow_observation' => source ? project_workflow_observation(source.fetch('workflow_binding')) : deep_copy(default_workflow)
      }
    end
    workflows = historical.fetch('workflows').map { |workflow| project_workflow(workflow) }
    generator_bytes = safe_read(root_path, GENERATOR_PATH, label: '$.provenance.generator', hook: source_read_hook)

    {
      'artifact_type' => 'g0_g3_coverage_evidence_map_v2',
      'schema_version' => 2,
      'artifact_id' => 'COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-08-29',
      'snapshot_date' => SNAPSHOT_DATE,
      'data_boundary' => 'synthetic_only',
      'source_evidence_map' => {
        'path' => HISTORICAL_MAP_PATH,
        'sha256' => HISTORICAL_MAP_SHA256
      },
      'canonical_order_source' => {
        'path' => CANONICAL_ORDER_PATH,
        'sha256' => CANONICAL_ORDER_SHA256
      },
      'explicit_evidence_inputs' => explicit_inputs,
      'capability_defaults' => default_engineering,
      'workflow_observation_default' => default_workflow,
      'capabilities' => capabilities,
      'workflows' => workflows,
      'provenance' => {
        'generator' => {
          'path' => GENERATOR_PATH,
          'sha256' => Digest::SHA256.hexdigest(generator_bytes)
        },
        'source_byte_hash' => 'sha256_raw_bytes',
        'canonical_json' => 'utf8_sorted_object_keys_compact_single_lf',
        'capability_count' => capabilities.length,
        'historical_engineering_override_count' => overrides.length,
        'workflow_count' => workflows.length,
        'explicit_evidence_input_count' => explicit_inputs.length
      }
    }
  end
  private_class_method :build_document

  def load_sources(root_path, source_read_hook: nil)
    historical_bytes = safe_read(root_path, HISTORICAL_MAP_PATH, label: '$.source_evidence_map', hook: source_read_hook)
    unless Digest::SHA256.hexdigest(historical_bytes) == HISTORICAL_MAP_SHA256
      raise Error, '$.source_evidence_map.sha256: historical source byte hash drift'
    end
    manifest_bytes = safe_read(root_path, CANONICAL_ORDER_PATH, label: '$.canonical_order_source', hook: source_read_hook)
    unless Digest::SHA256.hexdigest(manifest_bytes) == CANONICAL_ORDER_SHA256
      raise Error, '$.canonical_order_source.sha256: canonical source byte hash drift'
    end

    historical = Core.parse_json(historical_bytes, label: HISTORICAL_MAP_PATH)
    manifest = Core.parse_json(manifest_bytes, label: CANONICAL_ORDER_PATH)
    validate_historical_map!(historical)
    capability_ids = validate_manifest!(manifest)
    unless historical.fetch('capability_overrides').keys.all? { |id| capability_ids.include?(id) }
      raise Error, '$.source_evidence_map.capability_overrides: unknown capability ID'
    end

    { historical_map: historical, manifest: manifest, capability_ids: capability_ids }
  end
  private_class_method :load_sources

  def validate_historical_map!(map)
    assert_closed_schema!(map, required: SOURCE_MAP_KEYS, label: '$.source_evidence_map')
    raise Error, '$.source_evidence_map.schema_version: expected 1' unless map.fetch('schema_version') == 1
    raise Error, '$.source_evidence_map.snapshot_date: unexpected value' unless map.fetch('snapshot_date') == '2026-08-27'
    raise Error, '$.source_evidence_map.data_boundary: unexpected value' unless map.fetch('data_boundary') == 'synthetic_only'
    assert_secret_free!(map, label: '$.source_evidence_map')

    validate_source_engineering!(map.fetch('capability_defaults'), '$.source_evidence_map.capability_defaults')
    validate_source_binding!(map.fetch('workflow_binding_default'), '$.source_evidence_map.workflow_binding_default')
    overrides = map.fetch('capability_overrides')
    raise Error, '$.source_evidence_map.capability_overrides: expected object' unless overrides.is_a?(Hash)
    raise Error, '$.source_evidence_map.capability_overrides: expected 14 entries' unless overrides.length == 14
    overrides.each do |capability_id, entry|
      raise Error, '$.source_evidence_map.capability_overrides: invalid capability ID' unless capability_id.match?(/\APAR-[A-Z0-9]+-\d{3}\z/)
      assert_closed_schema!(entry, required: %w[engineering_evidence workflow_binding], label: '$.source_evidence_map.capability_overrides.entry')
      validate_source_engineering!(entry.fetch('engineering_evidence'), '$.source_evidence_map.capability_overrides.entry.engineering_evidence')
      validate_source_binding!(entry.fetch('workflow_binding'), '$.source_evidence_map.capability_overrides.entry.workflow_binding')
    end

    workflows = map.fetch('workflows')
    raise Error, '$.source_evidence_map.workflows: expected 16 entries' unless workflows.is_a?(Array) && workflows.length == 16
    ids = workflows.map.with_index do |workflow, index|
      label = "$.source_evidence_map.workflows[#{index}]"
      assert_closed_schema!(workflow, required: SOURCE_WORKFLOW_KEYS, label: label)
      validate_source_workflow!(workflow, label)
      workflow.fetch('workflow_id')
    end
    unless ids.uniq == ids && ids == (1..16).map { |number| format('E2E-%02d', number) }
      raise Error, '$.source_evidence_map.workflows: workflow IDs are missing, duplicate, or out of order'
    end
    true
  end
  private_class_method :validate_historical_map!

  def validate_source_engineering!(entry, label)
    assert_closed_schema!(entry, required: SOURCE_ENGINEERING_KEYS, label: label)
    validate_engineering_types!(entry, label, allow_owner_field: true)
    raise Error, "#{label}.owner_acceptance: expected string" unless entry.fetch('owner_acceptance').is_a?(String)
  end
  private_class_method :validate_source_engineering!

  def validate_source_workflow!(entry, label)
    validate_engineering_types!(entry, label, allow_owner_field: true, workflow: true)
    raise Error, "#{label}.workflow_id: invalid value" unless entry.fetch('workflow_id').match?(/\AE2E-\d{2}\z/)
    raise Error, "#{label}.owner_acceptance: expected string" unless entry.fetch('owner_acceptance').is_a?(String)
    raise Error, "#{label}.note: expected string" unless entry.fetch('note').is_a?(String)
  end
  private_class_method :validate_source_workflow!

  def validate_source_binding!(entry, label)
    assert_closed_schema!(entry, required: SOURCE_BINDING_KEYS, label: label)
    raise Error, "#{label}.status: expected string" unless entry.fetch('status').is_a?(String)
    validate_string_array!(entry.fetch('scenario_ids'), "#{label}.scenario_ids", pattern: /\AE2E-\d{2}\z/)
    raise Error, "#{label}.authority_reference: historical source must remain empty" unless entry.fetch('authority_reference').nil?
  end
  private_class_method :validate_source_binding!

  def validate_engineering_types!(entry, label, allow_owner_field: false, workflow: false)
    keys = workflow ? SOURCE_WORKFLOW_KEYS : (allow_owner_field ? SOURCE_ENGINEERING_KEYS : ENGINEERING_KEYS)
    assert_closed_schema!(entry, required: keys, label: label) unless workflow
    %w[runtime_availability automated_evidence hosted_uat reconciliation defect_status].each do |key|
      raise Error, "#{label}.#{key}: expected string" unless entry.fetch(key).is_a?(String)
    end
    database = entry.fetch('database_engine_evidence')
    assert_closed_schema!(database, required: DATABASE_KEYS, label: "#{label}.database_engine_evidence")
    unless database.values.all? { |value| value.is_a?(String) }
      raise Error, "#{label}.database_engine_evidence: values must be strings"
    end
    validate_string_array!(entry.fetch('evidence_paths'), "#{label}.evidence_paths")
    true
  end
  private_class_method :validate_engineering_types!

  def validate_manifest!(manifest)
    assert_closed_schema!(manifest, required: MANIFEST_KEYS, label: '$.canonical_order_source')
    raise Error, '$.canonical_order_source.schema_version: expected 1' unless manifest.fetch('schema_version') == 1
    order = manifest.fetch('dependency_order')
    raise Error, '$.canonical_order_source.dependency_order: unexpected value' unless order == ('A'..'G').to_a
    batches = manifest.fetch('batches')
    counts = manifest.fetch('expected_counts')
    assert_closed_schema!(batches, required: order, label: '$.canonical_order_source.batches')
    assert_closed_schema!(counts, required: order, label: '$.canonical_order_source.expected_counts')
    ids = order.flat_map do |batch|
      batch_ids = batches.fetch(batch)
      validate_string_array!(batch_ids, "$.canonical_order_source.batches.#{batch}", pattern: /\APAR-[A-Z0-9]+-\d{3}\z/)
      unless counts.fetch(batch) == batch_ids.length
        raise Error, "$.canonical_order_source.expected_counts.#{batch}: count mismatch"
      end
      batch_ids
    end
    raise Error, '$.canonical_order_source.batches: expected 268 unique IDs' unless ids.length == 268 && ids.uniq == ids
    ids
  end
  private_class_method :validate_manifest!

  def validate_document_shape!(document)
    assert_closed_schema!(document, required: TOP_LEVEL_KEYS, label: '$.engineering_evidence_map_v2')
    raise Error, '$.artifact_type: unexpected value' unless document.fetch('artifact_type') == 'g0_g3_coverage_evidence_map_v2'
    raise Error, '$.schema_version: expected 2' unless document.fetch('schema_version') == 2
    raise Error, '$.artifact_id: unexpected value' unless document.fetch('artifact_id') == 'COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-08-29'
    raise Error, '$.snapshot_date: unexpected value' unless document.fetch('snapshot_date') == SNAPSHOT_DATE
    raise Error, '$.data_boundary: unexpected value' unless document.fetch('data_boundary') == 'synthetic_only'
    validate_exact_source!(document.fetch('source_evidence_map'), HISTORICAL_MAP_PATH, HISTORICAL_MAP_SHA256, '$.source_evidence_map')
    validate_exact_source!(document.fetch('canonical_order_source'), CANONICAL_ORDER_PATH, CANONICAL_ORDER_SHA256, '$.canonical_order_source')

    inputs = document.fetch('explicit_evidence_inputs')
    raise Error, '$.explicit_evidence_inputs: expected array' unless inputs.is_a?(Array)
    inputs.each_with_index { |entry, index| validate_source_record!(entry, "$.explicit_evidence_inputs[#{index}]") }
    validate_engineering_output!(document.fetch('capability_defaults'), '$.capability_defaults')
    validate_workflow_observation_output!(document.fetch('workflow_observation_default'), '$.workflow_observation_default')

    capabilities = document.fetch('capabilities')
    raise Error, '$.capabilities: expected 268 entries' unless capabilities.is_a?(Array) && capabilities.length == 268
    capabilities.each_with_index do |entry, index|
      label = "$.capabilities[#{index}]"
      assert_closed_schema!(entry, required: CAPABILITY_KEYS, label: label)
      raise Error, "#{label}.capability_id: invalid value" unless entry.fetch('capability_id').match?(/\APAR-[A-Z0-9]+-\d{3}\z/)
      validate_engineering_output!(entry.fetch('engineering_evidence'), "#{label}.engineering_evidence")
      validate_workflow_observation_output!(entry.fetch('workflow_observation'), "#{label}.workflow_observation")
    end

    workflows = document.fetch('workflows')
    raise Error, '$.workflows: expected 16 entries' unless workflows.is_a?(Array) && workflows.length == 16
    workflows.each_with_index do |entry, index|
      label = "$.workflows[#{index}]"
      assert_closed_schema!(entry, required: WORKFLOW_KEYS, label: label)
      raise Error, "#{label}.workflow_id: invalid value" unless entry.fetch('workflow_id').match?(/\AE2E-\d{2}\z/)
      validate_engineering_types!(entry, label, workflow: true)
    end

    provenance = document.fetch('provenance')
    assert_closed_schema!(provenance, required: PROVENANCE_KEYS, label: '$.provenance')
    validate_source_record!(provenance.fetch('generator'), '$.provenance.generator')
    raise Error, '$.provenance.source_byte_hash: unexpected value' unless provenance.fetch('source_byte_hash') == 'sha256_raw_bytes'
    unless provenance.fetch('canonical_json') == 'utf8_sorted_object_keys_compact_single_lf'
      raise Error, '$.provenance.canonical_json: unexpected value'
    end
    %w[capability_count historical_engineering_override_count workflow_count explicit_evidence_input_count].each do |key|
      raise Error, "$.provenance.#{key}: expected non-negative integer" unless provenance.fetch(key).is_a?(Integer) && provenance.fetch(key) >= 0
    end
    true
  end
  private_class_method :validate_document_shape!

  def validate_engineering_output!(entry, label)
    assert_closed_schema!(entry, required: ENGINEERING_KEYS, label: label)
    validate_engineering_types!(entry, label)
  end
  private_class_method :validate_engineering_output!

  def validate_workflow_observation_output!(entry, label)
    assert_closed_schema!(entry, required: WORKFLOW_OBSERVATION_KEYS, label: label)
    raise Error, "#{label}.status: expected string" unless entry.fetch('status').is_a?(String)
    validate_string_array!(entry.fetch('scenario_ids'), "#{label}.scenario_ids", pattern: /\AE2E-\d{2}\z/)
  end
  private_class_method :validate_workflow_observation_output!

  def validate_exact_source!(entry, path, sha256, label)
    validate_source_record!(entry, label)
    raise Error, "#{label}.path: unexpected source" unless entry.fetch('path') == path
    raise Error, "#{label}.sha256: unexpected source hash" unless entry.fetch('sha256') == sha256
  end
  private_class_method :validate_exact_source!

  def validate_source_record!(entry, label)
    assert_closed_schema!(entry, required: SOURCE_KEYS, label: label)
    raise Error, "#{label}.path: expected safe repository-relative path" unless safe_relative_path?(entry.fetch('path'))
    raise Error, "#{label}.sha256: expected lowercase SHA-256" unless entry.fetch('sha256').is_a?(String) && Core::SHA256_PATTERN.match?(entry.fetch('sha256'))
  end
  private_class_method :validate_source_record!

  def project_engineering(source)
    ENGINEERING_KEYS.to_h { |key| [key, deep_copy(source.fetch(key))] }
  end
  private_class_method :project_engineering

  def project_workflow_observation(source)
    WORKFLOW_OBSERVATION_KEYS.to_h { |key| [key, deep_copy(source.fetch(key))] }
  end
  private_class_method :project_workflow_observation

  def project_workflow(source)
    WORKFLOW_KEYS.to_h { |key| [key, deep_copy(source.fetch(key))] }
  end
  private_class_method :project_workflow

  def historical_evidence_paths(map)
    paths = []
    map.fetch('capability_overrides').each_value do |entry|
      paths.concat(entry.fetch('engineering_evidence').fetch('evidence_paths'))
    end
    map.fetch('workflows').each { |entry| paths.concat(entry.fetch('evidence_paths')) }
    paths.uniq.sort
  end
  private_class_method :historical_evidence_paths

  def normalize_extra_paths(current_paths, historical_paths)
    raise UsageError, 'evidence inputs must be an array' unless current_paths.is_a?(Array)
    unless current_paths.all? { |path| safe_relative_path?(path) }
      raise UsageError, 'evidence input paths must be safe repository-relative paths'
    end
    raise UsageError, 'duplicate evidence input path' unless current_paths.uniq == current_paths
    unless (current_paths & historical_paths).empty?
      raise UsageError, 'current evidence input duplicates a historical evidence path'
    end
    current_paths.sort
  end
  private_class_method :normalize_extra_paths

  def assert_no_forbidden_keys!(value, path = '$')
    case value
    when Hash
      value.each do |key, child|
        normalized = normalize_semantic_key(key)
        if forbidden_semantic_key?(normalized)
          raise Error, "#{path}: forbidden governance-capable field"
        end
        assert_no_forbidden_keys!(child, "#{path}.*")
      end
    when Array
      value.each_with_index { |child, index| assert_no_forbidden_keys!(child, "#{path}[#{index}]") }
    end
    true
  end
  private_class_method :assert_no_forbidden_keys!

  def normalize_semantic_key(key)
    key.to_s.encode(Encoding::UTF_8).unicode_normalize(:nfkc).downcase.gsub(/[^a-z0-9]/, '')
  rescue EncodingError
    ''
  end
  private_class_method :normalize_semantic_key

  def forbidden_semantic_key?(normalized)
    return true if normalized.empty?
    return true if %w[g0 g3].include?(normalized)

    FORBIDDEN_KEY_ALIASES.any? { |token| normalized.include?(token) }
  end
  private_class_method :forbidden_semantic_key?

  def assert_closed_schema!(value, required:, label:)
    raise Error, "#{label}: expected object" unless value.is_a?(Hash)
    unless value.keys.all? { |key| key.is_a?(String) }
      raise Error, "#{label}: object keys must be strings"
    end
    raise Error, "#{label}: unknown fields" unless (value.keys - required).empty?
    raise Error, "#{label}: missing fields" unless (required - value.keys).empty?

    true
  end
  private_class_method :assert_closed_schema!

  # This scanner never places an untrusted object key in a diagnostic path.
  # It therefore protects even a malicious secret embedded in the key itself.
  def assert_secret_free!(value, label: '$')
    locations = []
    scan_secret_locations(value, label, locations)
    return true if locations.empty?

    raise Error, "#{label}: secret-like content at #{locations.uniq.join(', ')}"
  end
  private_class_method :assert_secret_free!

  def scan_secret_locations(value, path, locations)
    case value
    when Hash
      value.each do |key, child|
        key_text = key.to_s
        normalized = normalize_semantic_key(key_text)
        key_sensitive = normalized.match?(/(?:password|passwd|pwd|secret|accesstoken|refreshtoken|apikey|privatekey|clientsecret|connectionstring|databaseurl|recoverykey)/)
        key_secret = secret_like_string?(key_text)
        locations << "#{path}.*" if key_secret || (key_sensitive && !empty_secret_value?(child))
        scan_secret_locations(child, "#{path}.*", locations)
      end
    when Array
      value.each_with_index { |child, index| scan_secret_locations(child, "#{path}[#{index}]", locations) }
    when String
      locations << path if secret_like_string?(value)
    end
  end
  private_class_method :scan_secret_locations

  def empty_secret_value?(value)
    value.nil? || value == false || (value.respond_to?(:empty?) && value.empty?) ||
      %w[reject reject_without_echoing_value prohibited not_permitted none].include?(value)
  end
  private_class_method :empty_secret_value?

  def secret_like_string?(value)
    value.match?(/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/) ||
      value.match?(%r{\A[a-z][a-z0-9+.-]*://[^/@\s:]+:[^/@\s]+@}i) ||
      value.match?(/\b(?:password|passwd|pwd|client_secret|api_key|access_token|refresh_token)\s*[:=]\s*[^\s,;]+/i) ||
      value.match?(/\bAKIA[0-9A-Z]{16}\b/) ||
      value.match?(/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/)
  end
  private_class_method :secret_like_string?

  def validate_string_array!(value, label, pattern: nil)
    raise Error, "#{label}: expected array" unless value.is_a?(Array)
    raise Error, "#{label}: values must be strings" unless value.all? { |entry| entry.is_a?(String) }
    raise Error, "#{label}: duplicate values" unless value.uniq == value
    if pattern && !value.all? { |entry| pattern.match?(entry) }
      raise Error, "#{label}: invalid value"
    end
    true
  end
  private_class_method :validate_string_array!

  def validate_evidence_date!(relative, label:)
    dates = relative.scan(/(?<!\d)(20\d{2})[-_]?([01]\d)[-_]?([0-3]\d)(?!\d)/).map do |year, month, day|
      begin
        Date.new(Integer(year, 10), Integer(month, 10), Integer(day, 10))
      rescue ArgumentError
        raise Error, "#{label}: evidence path contains an invalid calendar date"
      end
    end
    if dates.any? { |date| date > Date.iso8601(SNAPSHOT_DATE) }
      raise Error, "#{label}: evidence path date is later than snapshot"
    end
  end
  private_class_method :validate_evidence_date!

  def safe_relative_path?(relative)
    return false unless relative.is_a?(String) && !relative.empty? && !relative.include?("\0") && !relative.include?('\\')

    path = Pathname.new(relative)
    !path.absolute? && path.each_filename.none? { |component| component == '.' || component == '..' }
  rescue ArgumentError
    false
  end
  private_class_method :safe_relative_path?

  def safe_read(root_path, relative, label:, hook: nil)
    raise Error, "#{label}.path: unsafe repository-relative path" unless safe_relative_path?(relative)
    path = root_path.join(relative)
    components = Pathname.new(relative).each_filename.to_a
    current = root_path
    components.each_with_index do |component, index|
      current = current.join(component)
      stat = current.lstat
      raise Error, "#{label}: symlinked path component" if stat.symlink?
      if index == components.length - 1
        raise Error, "#{label}: source is not a regular file" unless stat.file?
      else
        raise Error, "#{label}: parent component is not a directory" unless stat.directory?
      end
    rescue SystemCallError
      raise Error, "#{label}: source path is unavailable"
    end
    raise Error, "#{label}: source resolves outside repository root" unless path.realpath == path

    hook&.call(relative)
    flags = File::RDONLY
    flags |= File::NOFOLLOW if File.const_defined?(:NOFOLLOW)
    bytes = nil
    File.open(path, flags) do |file|
      before = file.stat
      raise Error, "#{label}: source is not a regular file" unless before.file?
      bytes = file.read
      after = file.stat
      unless stable_stat(before) == stable_stat(after)
        raise Error, "#{label}: source changed while reading"
      end
      final = path.lstat
      unless final.file? && !final.symlink? && final.dev == after.dev && final.ino == after.ino &&
             stable_stat(final) == stable_stat(after) && path.realpath == path
        raise Error, "#{label}: source identity changed while reading"
      end
    end
    bytes.b
  rescue Error
    raise
  rescue SystemCallError
    raise Error, "#{label}: source read failed"
  end
  private_class_method :safe_read

  def stable_stat(stat)
    [stat.dev, stat.ino, stat.mode, stat.size, stat.mtime.to_r, stat.ctime.to_r]
  end
  private_class_method :stable_stat

  def canonical_root(root)
    path = Pathname.new(root.to_s).expand_path
    raise UsageError, 'repository root must be an existing directory' unless path.directory?
    raise UsageError, 'repository root must not contain symlink components' unless path.realpath == path

    path.realpath
  rescue SystemCallError
    raise UsageError, 'repository root is unavailable'
  end
  private_class_method :canonical_root

  def new_output_path(root_path, output)
    raise UsageError, '--output is required' if output.nil? || output.to_s.strip.empty?
    supplied = Pathname.new(output.to_s)
    candidate = supplied.absolute? ? supplied.expand_path : root_path.join(supplied).cleanpath
    leaf = candidate.basename.to_s
    raise UsageError, 'output filename is unsafe' if ['', '.', '..'].include?(leaf) || leaf.include?("\0")
    parent = candidate.parent
    raise UsageError, 'output parent must be an existing directory' unless parent.directory?
    raise UsageError, 'output parent must not contain symlink components' unless parent.realpath == parent
    unless parent.realpath == root_path || parent.realpath.to_s.start_with?("#{root_path}#{File::SEPARATOR}")
      raise UsageError, 'output must remain inside repository root'
    end
    begin
      candidate.lstat
      raise UsageError, 'output already exists; refusing overwrite'
    rescue Errno::ENOENT
      candidate
    end
  rescue SystemCallError
    raise UsageError, 'output path is unavailable'
  end
  private_class_method :new_output_path

  def publish_exclusive!(root_path, output_path, bytes, fault_after:, source_read_hook:)
    parent = output_path.parent
    stage = parent.join(".#{output_path.basename}.stage-#{Process.pid}-#{SecureRandom.hex(8)}")
    published = false
    begin
      File.open(stage, File::WRONLY | File::CREAT | File::EXCL, 0o644) do |file|
        file.binmode
        file.write(bytes)
        file.flush
        file.fsync
      end
      raise Error, 'injected failure after staged write' if fault_after == :after_stage_write

      staged_bytes = File.binread(stage)
      raise Error, 'staged output byte mismatch' unless staged_bytes == bytes
      staged_document = Core.parse_json(staged_bytes, label: '$.staged_engineering_evidence_map_v2')
      validate_generated_document!(staged_document, root: root_path, source_read_hook: source_read_hook)
      raise Error, 'injected failure after staged validation' if fault_after == :after_stage_validation

      File.link(stage, output_path)
      published = true
      fsync_directory(parent)
      unless File.binread(output_path) == bytes
        raise Error, 'published output byte mismatch'
      end
    rescue Errno::EEXIST
      raise UsageError, 'output already exists; refusing overwrite'
    rescue Error, Core::Error
      raise
    rescue SystemCallError => e
      raise Error, "output publication failed (#{e.class})"
    ensure
      begin
        File.unlink(stage) if stage.exist?
      rescue SystemCallError
        raise Error, 'staged output cleanup failed' unless published
      end
    end
    true
  end
  private_class_method :publish_exclusive!

  def fsync_directory(path)
    File.open(path, File::RDONLY) { |directory| directory.fsync }
  rescue Errno::EINVAL, Errno::ENOTSUP
    true
  end
  private_class_method :fsync_directory

  def repository_relative(root_path, path)
    path.relative_path_from(root_path).to_s
  end
  private_class_method :repository_relative

  def validate_fault_after!(fault_after)
    allowed = [nil, :after_stage_write, :after_stage_validation]
    raise UsageError, 'unknown fault injection point' unless allowed.include?(fault_after)
  end
  private_class_method :validate_fault_after!

  def deep_copy(value)
    Marshal.load(Marshal.dump(value))
  end
  private_class_method :deep_copy
end

if $PROGRAM_NAME == __FILE__
  options = { evidence: [] }
  parser = OptionParser.new do |opts|
    opts.banner = 'Usage: ruby scripts/generate-g0-g3-coverage-evidence-map-v2.rb --output PATH [--evidence PATH]'
    opts.on('--output PATH') do |value|
      raise OptionParser::InvalidOption, '--output may be supplied only once' if options.key?(:output)

      options[:output] = value
    end
    opts.on('--evidence PATH') { |value| options[:evidence] << value }
  end

  begin
    parser.parse!(ARGV)
    raise G0G3CoverageEvidenceMapV2::UsageError, 'unexpected positional arguments' unless ARGV.empty?
    root = File.expand_path('..', __dir__)
    receipt = G0G3CoverageEvidenceMapV2.generate!(
      root: root,
      output: options[:output],
      current_evidence_paths: options[:evidence]
    )
    $stdout.write(G0ProportionalGovernanceV2.canonical_json(receipt) + "\n")
    exit 0
  rescue OptionParser::ParseError, G0G3CoverageEvidenceMapV2::UsageError
    warn parser.banner
    exit 2
  rescue G0G3CoverageEvidenceMapV2::Error => e
    warn "coverage engineering evidence map generation failed: #{e.message}"
    exit 1
  end
end
