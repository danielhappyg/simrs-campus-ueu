#!/usr/bin/env ruby
# frozen_string_literal: true

require 'date'
require 'digest'
require 'fiddle/import'
require 'json'
require 'optparse'
require 'pathname'
require 'tempfile'

require_relative 'compare-g0-governance-v1-v2'
require_relative 'generate-g0-g3-coverage-evidence-map-v2'
require_relative 'select-g0-governance-consumer'

class G0G3CoverageLedger
  class ContractError < StandardError; end

  class DuplicateKeyHash < Hash
    def []=(key, value)
      raise JSON::ParserError, "duplicate JSON object key #{key.inspect}" if key?(key)

      super
    end
  end

  ROOT = File.expand_path('..', __dir__).freeze
  EVIDENCE_MAP_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_2026-08-27.json'
  LEDGER_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_2026-08-27.json'
  MANIFEST_PATH = 'docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json'
  CATALOGUE_PATH = 'docs/new-simrs-rebuild/TESTING_AND_UAT_STRATEGY.md'
  RELEASE_INDEX_PATH = 'docs/new-simrs-rebuild/phase-0/RELEASE_EVIDENCE_INDEX.md'
  REGISTER_PATHS = ('A'..'G').to_h do |batch|
    [batch, "docs/new-simrs-rebuild/phase-0/G0_BATCH_#{batch}_DECISION_REGISTER_2026-08-25.json"]
  end.freeze
  GOVERNANCE_PATHS = {
    'identity_registry' => 'docs/new-simrs-rebuild/phase-0/G0_INSTITUTIONAL_IDENTITY_KEY_REGISTRY_2026-08-26.json',
    'authority_policy' => 'docs/new-simrs-rebuild/phase-0/G0_OWNER_AUTHORITY_POLICY_2026-08-25.json',
    'appointment_register' => 'docs/new-simrs-rebuild/phase-0/G0_OWNER_APPOINTMENT_REGISTER_2026-08-25.json',
    'decision_session_register' => 'docs/new-simrs-rebuild/phase-0/G0_DECISION_SESSION_REGISTER_2026-08-25.json'
  }.freeze
  SOURCE_PATHS = {
    'evidence_map' => EVIDENCE_MAP_PATH,
    'manifest' => MANIFEST_PATH
  }.merge(
    REGISTER_PATHS.transform_keys { |batch| "decision_register_#{batch}" },
    GOVERNANCE_PATHS,
    {
    'e2e_catalogue' => CATALOGUE_PATH,
    'release_index' => RELEASE_INDEX_PATH
    }
  ).freeze

  MAP_KEYS = %w[schema_version artifact_id snapshot_date data_boundary capability_defaults workflow_binding_default capability_overrides workflows].freeze
  EVIDENCE_KEYS = %w[runtime_availability automated_evidence database_engine_evidence hosted_uat reconciliation defect_status owner_acceptance evidence_paths].freeze
  OVERRIDE_KEYS = %w[engineering_evidence workflow_binding].freeze
  BINDING_KEYS = %w[status scenario_ids authority_reference].freeze
  DB_KEYS = %w[sqlite postgresql_17 mysql_8_4 mysql_other].freeze
  WORKFLOW_KEYS = %w[workflow_id runtime_availability automated_evidence database_engine_evidence hosted_uat reconciliation defect_status owner_acceptance evidence_paths note].freeze
  RUNTIME = %w[NOT_IMPLEMENTED PARTIAL IMPLEMENTED].freeze
  AUTOMATED = %w[NO_COVERAGE TESTS_PRESENT_NOT_CURRENTLY_RUN PARTIAL_PASS COMPLETE_PASS].freeze
  DB_EVIDENCE = %w[NOT_RUN PASS COMPATIBILITY_ONLY FAIL].freeze
  HOSTED = %w[NOT_RUN HISTORICAL_PARTIAL PARTIAL_PASS PASS FAIL].freeze
  RECONCILIATION = %w[NOT_STARTED PARTIAL COMPLETE FAILED].freeze
  DEFECT = %w[PENDING NONE_RECORDED OPEN_P0 OPEN_P1 ACCEPTED_P1].freeze
  ACCEPTANCE = %w[NOT_READY NOT_ACCEPTED CLARIFICATION FAIL PASS].freeze
  PRESENCE = %w[PENDING RECORDED].freeze
  BINDING = %w[PENDING PROVISIONAL AUTHORIZED].freeze
  E2E_IDS = (1..16).map { |number| format('E2E-%02d', number) }.freeze
  EXPECTED_COUNTS = { 'A' => 20, 'B' => 8, 'C' => 19, 'D' => 19, 'E' => 48, 'F' => 34, 'G' => 120 }.freeze
  SECRET_PATTERN = /(?:-----BEGIN [A-Z ]*PRIVATE KEY-----|\bBearer\s+[A-Za-z0-9._~+\/-]+=*|(?:client[_-]?secret|password|passwd|secret|api[_-]?key|access[_-]?token|refresh[_-]?token)["']?\s*(?::|=|%3d)\s*["']?[^\s&,;}"']+)/i.freeze
  FORBIDDEN_EVIDENCE_PATHS = [
    %r{\Adocs/operations/OUTPATIENT_CHECKPOINT_2},
    %r{\Adocs/product/OUTPATIENT_ACCEPTANCE_SCENARIOS\.md\z},
    %r{\Adocs/operations/OUTPATIENT_DOMAIN_SPINE_VALIDATION\.md\z},
    %r{\Adocs/operations/OUTPATIENT_LAB_ACCESS_CONTROL_VALIDATION_2026-07-24\.md\z}
  ].freeze

  attr_reader :snapshot_date

  def initialize(snapshot_date:)
    @snapshot_date = snapshot_date
    raise ContractError, 'snapshot date must be YYYY-MM-DD' unless valid_date?(snapshot_date)
  end

  def build(overlay: nil)
    overlay ||= parse_json(read_path(EVIDENCE_MAP_PATH), EVIDENCE_MAP_PATH)
    validate_overlay!(overlay)
    manifest = parse_json(read_path(MANIFEST_PATH), MANIFEST_PATH)
    registers = REGISTER_PATHS.to_h { |batch, path| [batch, parse_json(read_path(path), path)] }
    catalogue = parse_catalogue(read_path(CATALOGUE_PATH))
    capabilities = build_capabilities(manifest, registers, overlay)
    workflows = build_workflows(catalogue, capabilities, overlay)
    validate_bidirectional_links!(capabilities, workflows)
    sources = SOURCE_PATHS.map { |source_id, path| source_record(source_id, path) }
    ledger = {
      'schema_version' => 1,
      'artifact_id' => "G0-G3-COVERAGE-LEDGER-#{snapshot_date}",
      'snapshot_date' => snapshot_date,
      'data_boundary' => 'synthetic_only',
      'authoritative_boundary' => 'Engineering evidence inventory only. Owner decisions, dispositions, approvals, deployment facts and gate verdicts remain authoritative in their source registers.',
      'vocabularies' => vocabularies,
      'sources' => sources,
      'capability_summary' => capability_summary(capabilities),
      'workflow_summary' => workflow_summary(workflows),
      'gate_summary' => gate_summary(capabilities, workflows),
      'capabilities' => capabilities,
      'workflows' => workflows
    }
    reject_secrets!(ledger, 'generated ledger')
    ledger
  end

  def serialized(overlay: nil)
    JSON.pretty_generate(build(overlay: overlay))
      .gsub(/\[\n\s*\]/, '[]')
      .gsub(/\{\n\s*\}/, '{}') + "\n"
  end

  def check!
    check_serialized!(read_path(LEDGER_PATH))
  end

  def check_serialized!(actual)
    raise ContractError, "stale ledger: run --write --snapshot-date #{snapshot_date}" unless actual == serialized

    true
  end

  def write!
    bytes = serialized
    target = safe_path(LEDGER_PATH, must_exist: false)
    directory = File.dirname(target)
    Tempfile.create(['g0-g3-coverage-ledger', '.json'], directory) do |file|
      file.binmode
      file.write(bytes)
      file.flush
      file.fsync
      File.rename(file.path, target)
    end
    true
  end

  def parse_json(bytes, label)
    value = JSON.parse(bytes, object_class: DuplicateKeyHash, allow_duplicate_key: false)
    raise ContractError, "#{label}: expected one JSON object" unless value.is_a?(Hash)

    value
  rescue JSON::ParserError => e
    detail = if e.message.match?(/duplicate(?: JSON object)? key/i)
               'duplicate JSON object key'
             else
               e.message
             end
    raise ContractError, "#{label}: invalid JSON: #{detail}"
  end

  private

  def valid_date?(value)
    Date.iso8601(value).strftime('%Y-%m-%d') == value
  rescue Date::Error, TypeError
    false
  end

  def closed_object!(value, keys, label)
    raise ContractError, "#{label}: expected object" unless value.is_a?(Hash)
    actual = value.keys.sort
    expected = keys.sort
    raise ContractError, "#{label}: expected exact keys #{expected.join(', ')}; got #{actual.join(', ')}" unless actual == expected
  end

  def validate_overlay!(overlay)
    closed_object!(overlay, MAP_KEYS, 'evidence map')
    raise ContractError, 'evidence map: schema_version must be 1' unless overlay['schema_version'] == 1
    expected_artifact_id = "G0-G3-COVERAGE-EVIDENCE-MAP-#{snapshot_date}"
    raise ContractError, 'evidence map: artifact_id does not match explicit CLI date' unless overlay['artifact_id'] == expected_artifact_id
    raise ContractError, 'evidence map: snapshot_date does not match explicit CLI date' unless overlay['snapshot_date'] == snapshot_date
    raise ContractError, 'evidence map: data_boundary must be synthetic_only' unless overlay['data_boundary'] == 'synthetic_only'
    validate_evidence!(overlay['capability_defaults'], 'capability_defaults')
    validate_binding!(overlay['workflow_binding_default'], 'workflow_binding_default')
    overrides = overlay['capability_overrides']
    raise ContractError, 'capability_overrides must be an object' unless overrides.is_a?(Hash)
    overrides.each do |id, override|
      validate_requirement_id!(id, 'capability override')
      closed_object!(override, OVERRIDE_KEYS, "capability override #{id}")
      validate_evidence!(override['engineering_evidence'], "capability override #{id} engineering_evidence")
      validate_binding!(override['workflow_binding'], "capability override #{id} workflow_binding")
    end
    workflows = overlay['workflows']
    raise ContractError, 'workflows must be an array' unless workflows.is_a?(Array)
    ids = workflows.map { |row| row['workflow_id'] if row.is_a?(Hash) }
    raise ContractError, 'workflows must contain exact E2E-01 through E2E-16 once each' unless ids.sort == E2E_IDS && ids.uniq.length == 16
    workflows.each { |row| validate_workflow_overlay!(row) }
    reject_secrets!(overlay, 'evidence map')
  end

  def validate_evidence!(evidence, label)
    closed_object!(evidence, EVIDENCE_KEYS, label)
    validate_vocab!(evidence['runtime_availability'], RUNTIME, "#{label} runtime_availability")
    validate_vocab!(evidence['automated_evidence'], AUTOMATED, "#{label} automated_evidence")
    closed_object!(evidence['database_engine_evidence'], DB_KEYS, "#{label} database_engine_evidence")
    evidence['database_engine_evidence'].each { |engine, value| validate_vocab!(value, DB_EVIDENCE, "#{label} database #{engine}") }
    validate_vocab!(evidence['hosted_uat'], HOSTED, "#{label} hosted_uat")
    validate_vocab!(evidence['reconciliation'], RECONCILIATION, "#{label} reconciliation")
    validate_vocab!(evidence['defect_status'], DEFECT, "#{label} defect_status")
    validate_vocab!(evidence['owner_acceptance'], ACCEPTANCE, "#{label} owner_acceptance")
    validate_paths!(evidence['evidence_paths'], "#{label} evidence_paths")
  end

  def validate_binding!(binding, label)
    closed_object!(binding, BINDING_KEYS, label)
    validate_vocab!(binding['status'], BINDING, "#{label} status")
    ids = binding['scenario_ids']
    raise ContractError, "#{label}: scenario_ids must be a unique E2E-ID array" unless ids.is_a?(Array) && ids.uniq == ids && (ids - E2E_IDS).empty?
    case binding['status']
    when 'PENDING'
      raise ContractError, "#{label}: pending binding must have no scenarios or authority reference" unless ids.empty? && binding['authority_reference'].nil?
    when 'PROVISIONAL'
      raise ContractError, "#{label}: provisional binding requires scenarios and no authority reference" unless !ids.empty? && binding['authority_reference'].nil?
    when 'AUTHORIZED'
      raise ContractError, "#{label}: authorized binding requires scenarios and a repository authority reference" unless !ids.empty? && binding['authority_reference'].is_a?(String)
      safe_path(binding['authority_reference']) if binding['authority_reference'].is_a?(String)
    end
  end

  def validate_workflow_overlay!(row)
    closed_object!(row, WORKFLOW_KEYS, "workflow #{row['workflow_id']}")
    validate_vocab!(row['workflow_id'], E2E_IDS, 'workflow_id')
    validate_evidence!(row.to_h.reject { |key, _value| %w[workflow_id note].include?(key) }, "workflow #{row['workflow_id']}")
    raise ContractError, "workflow #{row['workflow_id']}: note must be substantive" unless row['note'].is_a?(String) && !row['note'].strip.empty?
  end

  def validate_vocab!(value, vocabulary, label)
    raise ContractError, "#{label}: invalid value #{value.inspect}" unless vocabulary.include?(value)
  end

  def validate_requirement_id!(value, label)
    raise ContractError, "#{label}: invalid requirement ID #{value.inspect}" unless value.is_a?(String) && value.match?(/\APAR-[A-Z0-9]+-\d{3}\z/)
  end

  def validate_paths!(paths, label)
    raise ContractError, "#{label}: expected unique path array" unless paths.is_a?(Array) && paths.uniq == paths
    paths.each do |path|
      validate_evidence_chronology!(path, label)
      safe_path(path)
    end
  end

  def validate_evidence_chronology!(path, label)
    return unless path.is_a?(String)

    dated_segments = path.scan(/(?<!\d)(\d{4}-\d{2}-\d{2})(?!\d)/).flatten
    dated_segments.each do |date|
      next unless valid_date?(date)
      next unless date > snapshot_date

      raise ContractError, "#{label}: evidence path date #{date} is later than snapshot #{snapshot_date}: #{path}"
    end
  end

  def safe_path(relative, must_exist: true)
    raise ContractError, "unsafe evidence path #{relative.inspect}" unless relative.is_a?(String) && !relative.empty?
    if FORBIDDEN_EVIDENCE_PATHS.any? { |pattern| pattern.match?(relative) }
      raise ContractError, "forbidden legacy/prototype evidence path: #{relative}"
    end
    path = Pathname.new(relative)
    raise ContractError, "unsafe evidence path #{relative.inspect}" if path.absolute? || path.each_filename.include?('..') || relative.include?("\0")
    expanded = File.expand_path(relative, ROOT)
    raise ContractError, "evidence path escapes repository: #{relative}" unless expanded.start_with?("#{ROOT}#{File::SEPARATOR}")
    if must_exist
      raise ContractError, "evidence path missing: #{relative}" unless File.file?(expanded) && File.lstat(expanded).file?
      cursor = ROOT
      path.each_filename do |part|
        cursor = File.join(cursor, part)
        raise ContractError, "evidence path uses symlink: #{relative}" if File.symlink?(cursor)
      end
      real = File.realpath(expanded)
      raise ContractError, "evidence path escapes repository: #{relative}" unless real.start_with?("#{File.realpath(ROOT)}#{File::SEPARATOR}")
    end
    expanded
  end

  def read_path(relative)
    File.binread(safe_path(relative))
  rescue SystemCallError => e
    raise ContractError, "cannot read #{relative}: #{e.message}"
  end

  def source_record(source_id, path)
    bytes = read_path(path)
    { 'source_id' => source_id, 'path' => path, 'sha256' => Digest::SHA256.hexdigest(bytes) }
  end

  def build_capabilities(manifest, registers, overlay)
    expected = manifest['expected_counts']
    batches = manifest['batches']
    raise ContractError, 'manifest expected_counts do not match closed A-G counts' unless expected == EXPECTED_COUNTS
    raise ContractError, 'manifest batches must contain exact A-G keys' unless batches.is_a?(Hash) && batches.keys.sort == EXPECTED_COUNTS.keys
    manifest_ids = batches.values.flatten
    raise ContractError, 'manifest must contain exactly 268 unique requirement IDs' unless manifest_ids.length == 268 && manifest_ids.uniq.length == 268
    manifest_ids.each { |id| validate_requirement_id!(id, 'manifest') }

    capabilities = []
    registers.each do |batch, register|
      entries = register['entries']
      raise ContractError, "Batch #{batch} register batch mismatch" unless register['batch'] == batch
      raise ContractError, "Batch #{batch} register must contain #{EXPECTED_COUNTS[batch]} entries" unless entries.is_a?(Array) && entries.length == EXPECTED_COUNTS[batch]
      ids = entries.map { |entry| entry['requirement_id'] if entry.is_a?(Hash) }
      raise ContractError, "Batch #{batch} register IDs must exactly match manifest" unless ids == batches[batch] && ids.uniq.length == ids.length
      source_path = REGISTER_PATHS.fetch(batch)
      source_sha = Digest::SHA256.hexdigest(read_path(source_path))
      entries.each_with_index do |entry, index|
        id = entry.fetch('requirement_id')
        override = overlay['capability_overrides'][id]
        evidence = deep_copy(overlay['capability_defaults'])
        evidence = deep_merge(evidence, override['engineering_evidence']) if override
        binding = deep_copy(overlay['workflow_binding_default'])
        binding = deep_copy(override['workflow_binding']) if override
        capabilities << {
          'requirement_id' => id,
          'batch' => batch,
          'decision_pointer' => {
            'source_id' => "decision_register_#{batch}", 'path' => source_path,
            'source_sha256' => source_sha, 'register_id' => register['register_id'],
            'entry_index' => index, 'entry_sha256' => Digest::SHA256.hexdigest(canonical_json(entry))
          },
          'reference_presence' => {
            'decision_object' => entry['decision'].is_a?(Hash) ? 'RECORDED' : 'PENDING',
            'accountable_owner_reference' => present_reference?(entry.dig('accountable_owner', 'reference')),
            'approval_reference' => present_reference?(entry.dig('approval', 'reference')),
            'release_evidence_reference' => 'PENDING'
          },
          'workflow_binding' => binding,
          'engineering_evidence' => evidence
        }
      end
    end
    unknown = overlay['capability_overrides'].keys - capabilities.map { |row| row['requirement_id'] }
    raise ContractError, "unknown capability override IDs: #{unknown.join(', ')}" unless unknown.empty?
    capabilities
  end

  def parse_catalogue(markdown)
    rows = markdown.lines.each_with_object([]) do |line, result|
      match = line.match(/^\|\s*(E2E-\d{2})\s*\|\s*([^|]+?)\s*\|\s*(.+?)\s*\|\s*$/)
      next unless match

      result << { 'workflow_id' => match[1], 'major_workflow' => match[2].strip, 'required_handoffs_and_assertions' => match[3].strip }
    end
    ids = rows.map { |row| row['workflow_id'] }
    raise ContractError, 'canonical catalogue must contain exact E2E-01 through E2E-16 once each' unless ids.sort == E2E_IDS && ids.uniq.length == 16
    rows
  end

  def build_workflows(catalogue, capabilities, overlay)
    evidence_by_id = overlay['workflows'].to_h { |row| [row['workflow_id'], row] }
    catalogue.map.with_index do |catalogue_row, index|
      id = catalogue_row['workflow_id']
      evidence = evidence_by_id.fetch(id)
      capability_ids = capabilities.select { |row| row.dig('workflow_binding', 'scenario_ids').include?(id) }.map { |row| row['requirement_id'] }
      catalogue_row.merge(
        'catalogue_pointer' => {
          'source_id' => 'e2e_catalogue', 'path' => CATALOGUE_PATH, 'row_index' => index,
          'row_sha256' => Digest::SHA256.hexdigest(canonical_json(catalogue_row))
        },
        'capability_ids' => capability_ids,
        'engineering_evidence' => evidence.to_h.reject { |key, _value| key == 'workflow_id' }
      )
    end
  end

  def validate_bidirectional_links!(capabilities, workflows)
    workflows_by_id = workflows.to_h { |row| [row['workflow_id'], row] }
    capabilities.each do |capability|
      capability.dig('workflow_binding', 'scenario_ids').each do |workflow_id|
        unless workflows_by_id.dig(workflow_id, 'capability_ids')&.include?(capability['requirement_id'])
          raise ContractError, "broken capability/workflow link #{capability['requirement_id']} -> #{workflow_id}"
        end
      end
    end
    workflows.each do |workflow|
      workflow['capability_ids'].each do |requirement_id|
        capability = capabilities.find { |row| row['requirement_id'] == requirement_id }
        raise ContractError, "broken workflow/capability link #{workflow['workflow_id']} -> #{requirement_id}" unless capability && capability.dig('workflow_binding', 'scenario_ids').include?(workflow['workflow_id'])
      end
    end
  end

  def capability_summary(capabilities)
    {
      'total' => capabilities.length,
      'unique_requirement_ids' => capabilities.map { |row| row['requirement_id'] }.uniq.length,
      'by_batch' => EXPECTED_COUNTS.keys.to_h { |batch| [batch, capabilities.count { |row| row['batch'] == batch }] },
      'owner_acceptance' => ACCEPTANCE.to_h { |status| [status, capabilities.count { |row| row.dig('engineering_evidence', 'owner_acceptance') == status }] }
    }
  end

  def workflow_summary(workflows)
    {
      'total' => workflows.length,
      'runtime_availability' => RUNTIME.to_h { |status| [status, workflows.count { |row| row.dig('engineering_evidence', 'runtime_availability') == status }] },
      'automated_evidence' => AUTOMATED.to_h { |status| [status, workflows.count { |row| row.dig('engineering_evidence', 'automated_evidence') == status }] },
      'hosted_uat' => HOSTED.to_h { |status| [status, workflows.count { |row| row.dig('engineering_evidence', 'hosted_uat') == status }] },
      'owner_acceptance' => ACCEPTANCE.to_h { |status| [status, workflows.count { |row| row.dig('engineering_evidence', 'owner_acceptance') == status }] }
    }
  end

  def gate_summary(capabilities, workflows)
    all_owner_accepted = capabilities.all? { |row| row.dig('engineering_evidence', 'owner_acceptance') == 'PASS' } && workflows.all? { |row| row.dig('engineering_evidence', 'owner_acceptance') == 'PASS' }
    complete_runtime = workflows.all? { |row| row.dig('engineering_evidence', 'runtime_availability') == 'IMPLEMENTED' }
    complete_automation = workflows.all? { |row| row.dig('engineering_evidence', 'automated_evidence') == 'COMPLETE_PASS' }
    hosted_pass = workflows.all? { |row| row.dig('engineering_evidence', 'hosted_uat') == 'PASS' }
    reconciled = workflows.all? { |row| row.dig('engineering_evidence', 'reconciliation') == 'COMPLETE' }
    {
      'status' => 'OPEN',
      'gate_reference_presence' => {
        'governance' => capabilities.all? { |row| row.dig('reference_presence', 'approval_reference') == 'RECORDED' } ? 'RECORDED' : 'PENDING',
        'automated_evidence' => complete_automation ? 'RECORDED' : 'PENDING',
        'hosted_uat' => hosted_pass ? 'RECORDED' : 'PENDING',
        'reconciliation' => reconciled ? 'RECORDED' : 'PENDING',
        'owner_acceptance' => all_owner_accepted ? 'RECORDED' : 'PENDING'
      },
      'reason_codes' => [
        'FORMAL_GOVERNANCE_NOT_VERIFIED',
        'FORMAL_GATE_DECISION_NOT_VERIFIED',
        ('OWNER_ACCEPTANCE_INCOMPLETE' unless all_owner_accepted),
        ('RUNTIME_INCOMPLETE' unless complete_runtime),
        ('AUTOMATED_EVIDENCE_INCOMPLETE' unless complete_automation),
        ('HOSTED_UAT_INCOMPLETE' unless hosted_pass),
        ('RECONCILIATION_INCOMPLETE' unless reconciled)
      ].compact
    }
  end

  def present_reference?(value)
    value.is_a?(String) && !value.strip.empty? ? 'RECORDED' : 'PENDING'
  end

  def deep_copy(value)
    Marshal.load(Marshal.dump(value))
  end

  def deep_merge(left, right)
    return left unless right
    left.merge(right) { |_key, old, new_value| old.is_a?(Hash) && new_value.is_a?(Hash) ? deep_merge(old, new_value) : deep_copy(new_value) }
  end

  def canonical_json(value)
    normalized = case value
                 when Hash then value.keys.sort.to_h { |key| [key, canonical_value(value[key])] }
                 else canonical_value(value)
                 end
    JSON.generate(normalized, ascii_only: true)
  end

  def canonical_value(value)
    case value
    when Hash then value.keys.sort.to_h { |key| [key, canonical_value(value[key])] }
    when Array then value.map { |item| canonical_value(item) }
    when String, Integer, TrueClass, FalseClass, NilClass then value
    else raise ContractError, "unsupported canonical JSON value #{value.class}"
    end
  end

  def reject_secrets!(value, label, trail = '$')
    case value
    when Hash
      value.each { |key, child| reject_secrets!(child, label, "#{trail}.#{key}") }
    when Array
      value.each_with_index { |child, index| reject_secrets!(child, label, "#{trail}[#{index}]") }
    when String
      raise ContractError, "#{label}: secret-looking value at #{trail}" if value.match?(SECRET_PATTERN)
    end
  end

  def vocabularies
    {
      'runtime_availability' => RUNTIME, 'automated_evidence' => AUTOMATED,
      'database_engine_evidence' => DB_EVIDENCE, 'hosted_uat' => HOSTED,
      'reconciliation' => RECONCILIATION, 'defect_status' => DEFECT,
      'owner_acceptance' => ACCEPTANCE, 'reference_presence' => PRESENCE,
      'workflow_binding' => BINDING
    }
  end
end

# Schema-v2 is a separate bridge. The historical G0G3CoverageLedger class and
# its default CLI path above remain the byte-for-byte schema-v1 contract.
module G0G3CoverageLedgerV2
  class Error < StandardError; end
  class UsageError < Error; end

  Core = G0ProportionalGovernanceV2
  Comparator = G0GovernanceV1V2Comparator
  EvidenceMap = G0G3CoverageEvidenceMapV2
  Selector = G0GovernanceConsumerSelector

  SNAPSHOT_DATE = '2026-08-29'
  ORIGINAL_OUTPUT_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-08-29.json'
  ORIGINAL_ARTIFACT_ID = 'G0-G3-COVERAGE-LEDGER-V2-2026-08-29'
  ORIGINAL_SHA256 = '690becdf75a08d17b992d8dad754313f33d0f9c8ea2c692b9b12b8b837f43ac5'
  ORIGINAL_CONTRACT_SHA256 = 'eb2918e85beb9cd70f39ff391826ba896ea16139fbd9a1fb81f1535855a846ca'
  ORIGINAL_CONTRACT_VERSION = '1.0.0'
  R2_OUTPUT_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R2.json'
  R2_ARTIFACT_ID = 'G0-G3-COVERAGE-LEDGER-V2-2026-08-29-R2'
  R2_SHA256 = '0b89705741bb052629593b9023f1c8d19c7827489a7e8e6ce6e46af1efd5f1c6'
  R2_CONTRACT_SHA256 = '2c9cac70cdeef4f85a0fd3d31d0830af4d2dcafa9c500b43a286b4726f5786e8'
  R2_CONTRACT_VERSION = '1.2.0'
  PREDECESSOR_OUTPUT_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R3.json'
  PREDECESSOR_ARTIFACT_ID = 'G0-G3-COVERAGE-LEDGER-V2-2026-08-29-R3'
  PREDECESSOR_SHA256 = '0f312713000b0092de313baee2d3cc6d34695c2fefd717c620871268045ac58c'
  PREDECESSOR_CONTRACT_SHA256 = 'b74fd990cb0a12692dc78bb808f688bab99924bf8f8ae80b109e72d9ba4d9bba'
  PREDECESSOR_CONTRACT_VERSION = '1.3.0'
  OUTPUT_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R4.json'
  ARTIFACT_ID = 'G0-G3-COVERAGE-LEDGER-V2-2026-08-29-R4'
  SUPERSESSION_RELATIONSHIP = 'supersedes_without_rewriting_or_reinterpreting_predecessor'
  EVIDENCE_MAP_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-08-29_R2.json'
  CONTRACT_PATH = Comparator::CONTRACT_PATH
  V1_MANIFEST_PATH = Comparator::V1_MANIFEST_PATH
  SOURCE_MANIFEST_PATH = Comparator::SOURCE_MANIFEST_PATH
  SOURCE_EVIDENCE_MAP_PATH = Comparator::EVIDENCE_MAP_PATH
  OWNER_POLICY_PATH = Comparator::OWNER_POLICY_PATH
  EXPANDED_FILE = Comparator::EXPANDED_FILE
  GATE_FILE = Comparator::GATE_FILE
  BUNDLE_FILE = Comparator::BUNDLE_FILE
  EVENT_FILE = Comparator::EVENT_FILE

  TOP_LEVEL_KEYS = %w[
    artifact_type schema_version artifact_id snapshot_date data_boundary
    authority_boundary governance_profile_binding sources capability_summary
    workflow_summary gate_summary capabilities workflows
  ].freeze
  SOURCE_KEYS = %w[
    governance_contract historical_hash_manifest canonical_capability_order
    engineering_evidence_map_v2 engineering_evidence_map_source
    source_register_count superseded_ledger
  ].freeze
  ORIGINAL_SOURCE_KEYS = SOURCE_KEYS.reject { |key| key == 'superseded_ledger' }.freeze
  SUPERSEDED_LEDGER_KEYS = %w[path sha256 artifact_id relationship].freeze
  SOURCE_REFERENCE_KEYS = %w[path sha256].freeze
  PROFILE_BINDING_KEYS = %w[
    status reason_code pointer_revision pointer_sha256 selection_path
    selection_sha256 bundle_manifest_path bundle_manifest_sha256
    expanded_register_path expanded_register_sha256 gate_register_path
    gate_register_sha256 validator_contract_path validator_contract_sha256
    validator_contract_version
  ].freeze
  CAPABILITY_KEYS = %w[
    capability_id batch source_decision_pointer governance_decision_pointer
    governance engineering_evidence workflow_observation
  ].freeze
  GOVERNANCE_POINTER_KEYS = %w[
    expanded_register_path expanded_register_sha256 entry_index_base entry_index
    entry_sha256 decision_event_id decision_event_sha256 selection_sha256
    bundle_manifest_sha256
  ].freeze
  GOVERNANCE_KEYS = %w[
    governance_state owner_state owner_assignment_status decision_status
    owner_record_id decision_event_id canonical_disposition derived_tier
    g0_terminal implementation_authorized
  ].freeze
  G0_CRITERIA_KEYS = %w[
    exact_268_unique_rows_in_canonical_order all_rows_terminal
    all_required_owner_records_present all_required_decision_events_present
    all_required_approvals_present all_required_evidence_references_hash_valid
    all_declared_tiers_match_derivation all_required_independent_reviews_valid
    all_source_and_artifact_hashes_current gate_register_complete_match
  ].freeze
  G3_CRITERIA_KEYS = %w[
    current_exact_sha_engineering_map hosted_role_based_uat reconciliation
    recovery security accessibility performance defect_closure owner_acceptance
  ].freeze
  GATE_SUMMARY_KEYS = %w[g0 g3].freeze
  G0_KEYS = %w[status derivation_source gate_register_claim criteria reason_codes].freeze
  G3_KEYS = %w[status requires_g0_pass evidence_authority_boundary criteria reason_codes].freeze
  G3_CRITERION_KEYS = %w[status deployed_commit_sha evidence_pointer authority_pointer].freeze
  G3_EVIDENCE_RECORD_KEYS = %w[
    artifact_type schema_version criterion covered_criteria status snapshot_date
    data_boundary deployed_commit_sha evidence_references authority_effect
  ].freeze
  G3_OWNER_ACCEPTANCE_KEYS = %w[
    artifact_type schema_version decision_id status snapshot_date data_boundary
    deployed_commit_sha institutional_id capacity accepted_criteria
    evidence_reference authority_effect
  ].freeze
  GATE_STATUSES = %w[OPEN PASS].freeze
  RESOLUTION_STATUSES = %w[active unavailable].freeze

  module NativeFs
    extend Fiddle::Importer

    dlload Fiddle.dlopen(nil)
    extern 'int openat(int, const char*, int, int)'
    extern 'int unlinkat(int, const char*, int)'
  end

  module_function

  def build(root: G0G3CoverageLedger::ROOT, env: ENV,
            resolver: Selector::ReadOnlyResolver.method(:resolve_active!), g3_evidence: {})
    root_path = secure_root(root)
    sources = load_authoritative_sources(root_path)
    source_rows = build_source_rows(root_path, sources)
    engineering = load_engineering_map(root_path)
    engineering_by_id = engineering.fetch('capabilities').to_h { |row| [row.fetch('capability_id'), row] }
    canonical_ids = source_rows.map { |row| row.fetch('requirement_id') }
    unless engineering_by_id.keys.sort == canonical_ids.sort && engineering_by_id.length == 268
      raise Error, 'engineering evidence map capability universe drift'
    end

    resolution, resolution_reason = resolve_governance(root_path, env, resolver)
    active = resolution && load_active_governance(root_path, resolution, source_rows)
    if resolution
      confirmed, confirmed_reason = resolve_governance(root_path, env, resolver)
      unless confirmed && confirmed_reason.nil? && stable_resolution?(resolution, confirmed)
        raise Error, 'active snapshot changed during ledger generation'
      end
    end

    capabilities = source_rows.each_with_index.map do |source_row, index|
      capability_id = source_row.fetch('requirement_id')
      evidence = engineering_by_id.fetch(capability_id)
      active_row = active && active.fetch(:expanded).fetch('entries').fetch(index)
      {
        'capability_id' => capability_id,
        'batch' => source_row.fetch('batch'),
        'source_decision_pointer' => deep_copy(source_row.fetch('source_decision_pointer')),
        'governance_decision_pointer' => active && governance_pointer(active, resolution, index, active_row),
        'governance' => active_row ? governance_projection(active_row) : empty_governance,
        'engineering_evidence' => deep_copy(evidence.fetch('engineering_evidence')),
        'workflow_observation' => deep_copy(evidence.fetch('workflow_observation'))
      }
    end

    g0 = derive_g0(root_path, capabilities, active, sources.fetch(:contract))
    g3 = derive_g3(root_path, g0, engineering, g3_evidence)
    document = {
      'artifact_type' => 'g0_g3_coverage_ledger_v2',
      'schema_version' => 2,
      'artifact_id' => ARTIFACT_ID,
      'snapshot_date' => SNAPSHOT_DATE,
      'data_boundary' => 'synthetic_only',
      'authority_boundary' => 'Engineering evidence never confers owner identity, approval, disposition, tier, authorization, G0, or G3. Governance is consumed only through the shared hash-valid active selector chain.',
      'governance_profile_binding' => profile_binding(root_path, resolution, active, resolution_reason),
      'sources' => source_records(root_path, sources, engineering),
      'capability_summary' => capability_summary(capabilities),
      'workflow_summary' => workflow_summary(engineering.fetch('workflows')),
      'gate_summary' => { 'g0' => g0, 'g3' => g3 },
      'capabilities' => capabilities,
      'workflows' => deep_copy(engineering.fetch('workflows'))
    }
    validate_document!(document)
    document
  rescue Comparator::Error, Core::Error, EvidenceMap::Error, Selector::Error => e
    raise Error, safe_error(e)
  end

  def serialized(**keywords)
    Core.canonical_json(build(**keywords)) + "\n"
  end

  def check!(root: G0G3CoverageLedger::ROOT, output: OUTPUT_PATH, **keywords)
    root_path = secure_root(root)
    actual = safe_read(root_path, output, label: 'schema-v2 ledger')
    expected = serialized(root: root_path.to_s, **keywords)
    raise Error, 'stale schema-v2 ledger' unless actual == expected

    true
  end

  # Publication is create-only and lock-protected. It cannot overwrite either
  # a historical ledger or an already-published schema-v2 observation.
  def write!(root: G0G3CoverageLedger::ROOT, output: OUTPUT_PATH, before_publish: nil, **keywords)
    root_path = secure_root(root)
    with_stable_lock(root_path, "#{output}.lock", File::LOCK_EX,
                     'schema-v2 ledger writer lock conflict', create: true) do |revalidate_writer_lock|
      # Selector mutation takes this same inode exclusively. Holding it shared
      # closes the resolve/serialize/publication race without mutating a pointer.
      with_stable_lock(root_path, Selector::LOCK_RELATIVE_PATH, File::LOCK_SH,
                       'governance selector lock conflict', create: false) do |revalidate_selector_lock|
        bytes = serialized(root: root_path.to_s, **keywords)
        before_publish.call if before_publish
        revalidate_writer_lock.call
        revalidate_selector_lock.call
        confirmation = serialized(root: root_path.to_s, **keywords)
        raise Error, 'active snapshot changed before ledger publication' unless confirmation == bytes
        revalidate = lambda do
          revalidate_writer_lock.call
          revalidate_selector_lock.call
        end
        publish_create_only!(root_path, output, bytes, revalidate: revalidate)
      end
    end
    true
  rescue Errno::EEXIST
    raise Error, 'schema-v2 ledger already exists'
  rescue SystemCallError, IOError
    raise Error, 'schema-v2 ledger publication failed'
  end

  def with_stable_lock(root, relative, mode, conflict_message, create:)
    with_parent_directory(root, relative, error_message: 'ledger lock path is unsafe') do |parent, leaf, revalidate_parent|
      begin
        file = openat_io(parent, leaf, File::RDWR | nofollow_flag, 0, operation: 'open ledger lock')
      rescue Errno::ENOENT
        raise unless create

        file = openat_io(parent, leaf, File::RDWR | File::CREAT | File::EXCL | nofollow_flag,
                         0o600, operation: 'create ledger lock')
        file.chmod(0o600)
      end
      stat = file.stat
      validate_private_lock!(stat)
      verify_entry_identity!(parent, leaf, stat, flags: File::RDWR, label: 'ledger lock path')
      raise Error, conflict_message unless file.flock(mode | File::LOCK_NB)

      revalidate = lambda do
        revalidate_parent.call
        current = verify_entry_identity!(parent, leaf, stat, flags: File::RDWR, label: 'ledger lock path')
        validate_private_lock!(current)
        true
      end
      revalidate.call
      yield revalidate
      revalidate.call
    ensure
      file&.flock(File::LOCK_UN) rescue nil
      file&.close unless file&.closed?
    end
  rescue Errno::ENOENT
    raise Error, create ? 'ledger lock path is unsafe' : 'governance selector lock is unavailable'
  rescue Errno::ELOOP
    raise Error, 'ledger lock path is unsafe'
  end
  private_class_method :with_stable_lock

  def validate_private_lock!(stat)
    unless stat.file? && stat.nlink == 1 && stat.uid == Process.uid && stat.size.zero? && (stat.mode & 0o777) == 0o600
      raise Error, 'ledger lock path is not a private regular file'
    end
    true
  end
  private_class_method :validate_private_lock!

  def validate_document!(document)
    Core.assert_closed_schema!(document, required: TOP_LEVEL_KEYS, label: '$.ledger_v2')
    Core.assert_secret_free!(document, label: '$.ledger_v2')
    unless document.fetch('artifact_type') == 'g0_g3_coverage_ledger_v2' &&
           document.fetch('schema_version') == 2 && document.fetch('artifact_id') == ARTIFACT_ID &&
           document.fetch('snapshot_date') == SNAPSHOT_DATE &&
           document.fetch('data_boundary') == 'synthetic_only'
      raise Error, 'schema-v2 ledger identity or boundary drift'
    end
    sources = document.fetch('sources')
    Core.assert_closed_schema!(sources, required: SOURCE_KEYS, label: '$.ledger_v2.sources')
    validate_superseded_ledger_reference!(sources.fetch('superseded_ledger'))
    binding = document.fetch('governance_profile_binding')
    Core.assert_closed_schema!(binding, required: PROFILE_BINDING_KEYS, label: '$.ledger_v2.governance_profile_binding')
    expected_contract_reference = {
      'path' => CONTRACT_PATH,
      'sha256' => PREDECESSOR_CONTRACT_SHA256
    }
    unless sources.fetch('governance_contract') == expected_contract_reference &&
           binding.values_at('validator_contract_path', 'validator_contract_sha256', 'validator_contract_version') == [
             CONTRACT_PATH, PREDECESSOR_CONTRACT_SHA256, PREDECESSOR_CONTRACT_VERSION
           ]
      raise Error, 'schema-v2 ledger live contract binding drift'
    end
    raise Error, 'unknown governance profile binding status' unless RESOLUTION_STATUSES.include?(binding.fetch('status'))
    operational_binding_keys = PROFILE_BINDING_KEYS - %w[status reason_code validator_contract_path validator_contract_sha256 validator_contract_version]
    if binding.fetch('status') == 'unavailable'
      unless present?(binding.fetch('reason_code')) && operational_binding_keys.all? { |key| binding.fetch(key).nil? }
        raise Error, 'unavailable governance profile binding must be explicitly reasoned and authority-empty'
      end
    elsif !binding.fetch('reason_code').nil? || operational_binding_keys.any? { |key| binding.fetch(key).nil? }
      raise Error, 'active governance profile binding is incomplete'
    end
    unless present?(binding.fetch('validator_contract_path')) &&
           binding.fetch('validator_contract_sha256').to_s.match?(/\A[0-9a-f]{64}\z/) &&
           present?(binding.fetch('validator_contract_version'))
      raise Error, 'governance profile validator binding invalid'
    end
    rows = document.fetch('capabilities')
    unless rows.is_a?(Array) && rows.length == 268 && rows.map { |row| row['capability_id'] }.uniq.length == 268
      raise Error, 'schema-v2 ledger must contain exactly 268 unique capabilities'
    end
    rows.each_with_index do |row, index|
      Core.assert_closed_schema!(row, required: CAPABILITY_KEYS, label: "$.ledger_v2.capabilities[#{index}]")
      Core.assert_closed_schema!(row.fetch('source_decision_pointer'), required: Comparator::SOURCE_POINTER_KEYS,
                                 label: "$.ledger_v2.capabilities[#{index}].source_decision_pointer")
      Core.assert_closed_schema!(row.fetch('governance'), required: GOVERNANCE_KEYS,
                                 label: "$.ledger_v2.capabilities[#{index}].governance")
      pointer = row.fetch('governance_decision_pointer')
      Core.assert_closed_schema!(pointer, required: GOVERNANCE_POINTER_KEYS,
                                 label: "$.ledger_v2.capabilities[#{index}].governance_decision_pointer") if pointer
      if binding.fetch('status') == 'active'
        raise Error, 'active governance resolution requires every governance pointer' unless pointer
        event_id = pointer.fetch('decision_event_id')
        event_sha = pointer.fetch('decision_event_sha256')
        unless (event_id.nil? && event_sha.nil?) || (present?(event_id) && event_sha.to_s.match?(/\A[0-9a-f]{64}\z/))
          raise Error, 'governance decision event ID/SHA pair invalid'
        end
      elsif pointer || unavailable_governance_drift?(row.fetch('governance'))
        raise Error, 'non-active governance resolution can populate only closed pending governance presence'
      end
    end
    gate_summary = document.fetch('gate_summary')
    Core.assert_closed_schema!(gate_summary, required: GATE_SUMMARY_KEYS, label: '$.ledger_v2.gate_summary')
    %w[g0 g3].each do |gate|
      raise Error, "#{gate} status vocabulary drift" unless GATE_STATUSES.include?(gate_summary.dig(gate, 'status'))
    end
    Core.assert_closed_schema!(gate_summary.fetch('g0'), required: G0_KEYS, label: '$.ledger_v2.gate_summary.g0')
    Core.assert_closed_schema!(gate_summary.fetch('g3'), required: G3_KEYS, label: '$.ledger_v2.gate_summary.g3')
    Core.assert_closed_schema!(gate_summary.dig('g0', 'criteria'), required: G0_CRITERIA_KEYS, label: '$.ledger_v2.gate_summary.g0.criteria')
    Core.assert_closed_schema!(gate_summary.dig('g3', 'criteria'), required: G3_CRITERIA_KEYS, label: '$.ledger_v2.gate_summary.g3.criteria')
    gate_summary.dig('g3', 'criteria').each do |criterion, value|
      Core.assert_closed_schema!(value, required: G3_CRITERION_KEYS,
                                 label: "$.ledger_v2.gate_summary.g3.criteria.#{criterion}")
      raise Error, "G3 #{criterion} status vocabulary drift" unless GATE_STATUSES.include?(value.fetch('status'))
      deployed = value.fetch('deployed_commit_sha')
      if value.fetch('status') == 'PASS'
        unless deployed.to_s.match?(/\A[0-9a-f]{40}\z/) && value.fetch('evidence_pointer').is_a?(Hash)
          raise Error, "G3 #{criterion} PASS lacks exact deployed-SHA evidence"
        end
        if criterion == 'owner_acceptance'
          raise Error, 'G3 owner acceptance PASS lacks authority pointer' unless value.fetch('authority_pointer').is_a?(Hash)
        elsif value.fetch('authority_pointer')
          raise Error, "G3 #{criterion} cannot carry owner authority"
        end
      elsif !deployed.nil? || !value.fetch('evidence_pointer').nil? || !value.fetch('authority_pointer').nil?
        raise Error, "G3 #{criterion} OPEN must not retain operative evidence"
      end
    end
    g0 = gate_summary.fetch('g0')
    unless g0.fetch('criteria').values.all? { |value| value == true || value == false }
      raise Error, 'G0 criteria must be booleans'
    end
    expected_g0 = g0.fetch('criteria').values.all? ? 'PASS' : 'OPEN'
    expected_g0_reasons = g0.fetch('criteria').select { |_key, value| !value }.keys.map(&:upcase)
    unless g0.fetch('status') == expected_g0 && g0.fetch('reason_codes') == expected_g0_reasons
      raise Error, 'G0 summary is not its complete deterministic derivation'
    end
    g3 = gate_summary.fetch('g3')
    deployed_shas = g3.fetch('criteria').values.map { |row| row.fetch('deployed_commit_sha') }.compact.uniq
    raise Error, 'G3 criteria bind inconsistent deployed commit SHAs' if deployed_shas.length > 1
    expected_g3 = g0.fetch('status') == 'PASS' && g3.fetch('criteria').values.all? { |row| row.fetch('status') == 'PASS' } ? 'PASS' : 'OPEN'
    expected_g3_reasons = ([('G0_OPEN' unless g0.fetch('status') == 'PASS')] +
      g3.fetch('criteria').select { |_key, row| row.fetch('status') != 'PASS' }.keys.map(&:upcase)).compact
    unless g3.fetch('status') == expected_g3 && g3.fetch('reason_codes') == expected_g3_reasons
      raise Error, 'G3 summary is not its complete deterministic derivation'
    end
    true
  rescue Core::Error, KeyError => e
    raise Error, safe_error(e)
  end

  def load_authoritative_sources(root)
    contract = parse_repository_json_safely(root, CONTRACT_PATH, '$.contract')
    v1_manifest = parse_repository_json_safely(root, V1_MANIFEST_PATH, '$.v1_manifest')
    source_manifest = parse_repository_json_safely(root, SOURCE_MANIFEST_PATH, '$.source_manifest')
    source_evidence_map = parse_repository_json_safely(root, SOURCE_EVIDENCE_MAP_PATH, '$.source_evidence_map')
    owner_policy = parse_repository_json_safely(root, OWNER_POLICY_PATH, '$.owner_policy')
    Core.validate_contract!(contract, root: root.to_s)
    Core.verify_v1_manifest!(v1_manifest, root: root.to_s)
    {
      contract: contract, v1_manifest: v1_manifest, source_manifest: source_manifest,
      source_evidence_map: source_evidence_map, owner_policy: owner_policy
    }
  end
  private_class_method :load_authoritative_sources

  def parse_repository_json_safely(root, relative, label)
    Core.parse_json(safe_read(root, relative, label: label), label: label)
  end
  private_class_method :parse_repository_json_safely

  def build_source_rows(root, sources)
    rows = Comparator.expected_rows(
      root, sources.fetch(:contract), sources.fetch(:source_manifest),
      sources.fetch(:source_evidence_map), sources.fetch(:owner_policy)
    )
    raise Error, 'source capability universe drift' unless rows.length == 268 && rows.map { |row| row.fetch('requirement_id') }.uniq.length == 268
    rows
  end
  private_class_method :build_source_rows

  def load_engineering_map(root)
    document = Core.parse_json(safe_read(root, EVIDENCE_MAP_PATH, label: 'engineering evidence map v2'), label: EVIDENCE_MAP_PATH)
    EvidenceMap.validate_generated_document!(document, root: root.to_s)
    document
  end
  private_class_method :load_engineering_map

  def resolve_governance(root, env, resolver)
    [resolver.call(root: root.to_s, env: env), nil]
  rescue Selector::ResolutionError => e
    pointer = root.join(Selector::POINTER_RELATIVE_PATH)
    reason = (!pointer.exist? && !pointer.symlink?) ? 'pointer_missing' : e.reason_code
    [nil, reason]
  rescue Selector::Error
    pointer = root.join(Selector::POINTER_RELATIVE_PATH)
    reason = (!pointer.exist? && !pointer.symlink?) ? 'pointer_missing' : 'pointer_contract_invalid'
    [nil, reason]
  end
  private_class_method :resolve_governance

  def load_active_governance(root, resolution, source_rows)
    bundle = resolution.fetch(:bundle_path)
    bundle_relative = repository_relative(root, bundle)
    expanded_relative = File.join(bundle_relative, EXPANDED_FILE)
    gate_relative = File.join(bundle_relative, GATE_FILE)
    manifest_relative = File.join(bundle_relative, BUNDLE_FILE)
    event_relative = File.join(bundle_relative, EVENT_FILE)
    expanded_bytes = safe_read(root, expanded_relative, label: '$.active.expanded_register')
    gate_bytes = safe_read(root, gate_relative, label: '$.active.gate_register')
    manifest_bytes = safe_read(root, manifest_relative, label: '$.active.bundle_manifest')
    event_bytes = safe_read(root, event_relative, label: '$.active.decision_event_register')
    expanded = Core.parse_json(expanded_bytes, label: '$.active.expanded_register')
    gate = Core.parse_json(gate_bytes, label: '$.active.gate_register')
    manifest = Core.parse_json(manifest_bytes, label: '$.active.bundle_manifest')
    event_register = Core.parse_json(event_bytes, label: '$.active.decision_event_register')
    Core.assert_secret_free!(expanded, label: '$.active.expanded_register')
    Core.assert_secret_free!(gate, label: '$.active.gate_register')
    ids = source_rows.map { |row| row.fetch('requirement_id') }
    entries = expanded.fetch('entries')
    unless entries.is_a?(Array) && entries.length == 268 && entries.map { |row| row['requirement_id'] } == ids
      raise Error, 'active expanded register capability universe drift'
    end
    entries.zip(source_rows).each do |row, source|
      unless row.fetch('source_decision_pointer') == source.fetch('source_decision_pointer')
        raise Error, 'active expanded register source pointer drift'
      end
    end
    expanded_sha256 = Digest::SHA256.hexdigest(expanded_bytes)
    expected_gate = recompute_gate_register(expanded, expanded_sha256)
    raise Error, 'active gate register does not match independent 268-row derivation' unless gate == expected_gate
    events = event_register.fetch('events')
    raise Error, 'active decision event register schema drift' unless events.is_a?(Array)
    events_by_id = events.to_h { |event| [event.fetch('decision_event_id'), event] }
    raise Error, 'active decision event register contains duplicate IDs' unless events_by_id.length == events.length
    {
      expanded: expanded, expanded_path: root.join(expanded_relative), expanded_sha256: expanded_sha256,
      gate: gate, gate_path: root.join(gate_relative), gate_sha256: Digest::SHA256.hexdigest(gate_bytes),
      manifest: manifest, manifest_path: root.join(manifest_relative), manifest_sha256: Digest::SHA256.hexdigest(manifest_bytes),
      events_by_id: events_by_id
    }
  rescue Core::Error, KeyError, SystemCallError => e
    raise Error, safe_error(e)
  end
  private_class_method :load_active_governance

  def recompute_gate_register(expanded, expanded_sha256)
    rows = expanded.fetch('entries')
    terminal = rows.count { |row| row.fetch('g0_terminal') == true }
    authorized = rows.count { |row| row.fetch('governance_state') == 'AUTHORIZED_FOR_SYNTHETIC_BUILD' }
    deferred = rows.count { |row| row.fetch('governance_state') == 'DEFERRED' }
    retired = rows.count { |row| row.fetch('governance_state') == 'RETIRED' }
    excluded = rows.count { |row| row.fetch('governance_state') == 'EXCLUDED' }
    owners = rows.all? { |row| present?(row['owner_record_id']) && present?(row['product_authority_id']) && !Array(row['domain_authority_ids']).empty? }
    decisions = rows.all? { |row| present?(row['decision_event_id']) }
    approvals = rows.all? { |row| row['owner_state'] == 'APPROVED' && row['decision_status'] == 'approved' }
    project_g0 = terminal == 268 && owners && decisions && approvals ? 'PASS' : 'OPEN'
    {
      'artifact_type' => 'g0_governance_v2_gate_register',
      'schema_version' => 1,
      'register_id' => 'G0-GOVERNANCE-V2-GATE-REGISTER',
      'profile' => 'v2',
      'status' => project_g0 == 'PASS' ? 'derived_pass' : 'derived_pending',
      'effect' => 'none_no_activation_or_acceptance',
      'data_boundary' => 'synthetic_only',
      'source_expanded_register' => { 'path' => EXPANDED_FILE, 'sha256' => expanded_sha256 },
      'project_g0' => project_g0,
      'project_g3' => 'OPEN',
      'counts' => {
        'total' => rows.length, 'terminal' => terminal, 'nonterminal' => rows.length - terminal,
        'authorized' => authorized, 'deferred' => deferred, 'retired' => retired, 'excluded' => excluded
      },
      'derivation' => {
        'all_rows_terminal' => terminal == rows.length,
        'all_required_owner_records_present' => owners,
        'all_required_decision_events_present' => decisions,
        'all_required_approvals_present' => approvals,
        'provisional_engineering_binding_effect' => 'none'
      }
    }
  end
  private_class_method :recompute_gate_register

  def derive_g0(root, capabilities, active, contract)
    criteria = G0_CRITERIA_KEYS.to_h { |key| [key, false] }
    if active
      rows = active.fetch(:expanded).fetch('entries')
      criteria['exact_268_unique_rows_in_canonical_order'] = rows.length == 268 && rows.map { |row| row['requirement_id'] }.uniq.length == 268
      criteria['all_rows_terminal'] = rows.all? { |row| row['g0_terminal'] == true }
      criteria['all_required_owner_records_present'] = rows.all? { |row| present?(row['owner_record_id']) && present?(row['product_authority_id']) && !Array(row['domain_authority_ids']).empty? }
      criteria['all_required_decision_events_present'] = rows.all? { |row| present?(row['decision_event_id']) }
      criteria['all_required_approvals_present'] = rows.all? { |row| row['owner_state'] == 'APPROVED' && row['decision_status'] == 'approved' }
      criteria['all_required_evidence_references_hash_valid'] = rows.all? do |row|
        references = Array(row['evidence_references'])
        !references.empty? && references.each_with_index.all? do |reference, index|
          begin
            validated_reference(root, reference, "$.active.rows.#{row['requirement_id']}.evidence_references[#{index}]")
            true
          rescue Error
            false
          end
        end
      end
      criteria['all_declared_tiers_match_derivation'] = rows.all? do |row|
        row['consequence_map'].is_a?(Hash) && row['derived_tier'] == Core.derived_tier(row['consequence_map'], contract)
      rescue Core::Error, KeyError
        false
      end
      criteria['all_required_independent_reviews_valid'] = rows.all? do |row|
        required = row['consequence_map'].is_a?(Hash) && Core.independent_review_required?(row['consequence_map'], contract)
        !required || !Array(row['independent_review_ids']).empty?
      rescue Core::Error, KeyError
        false
      end
      criteria['all_source_and_artifact_hashes_current'] = true
      independently_derived = criteria.reject { |key, _value| key == 'gate_register_complete_match' }.values.all? ? 'PASS' : 'OPEN'
      criteria['gate_register_complete_match'] = active.dig(:gate, 'project_g0') == independently_derived
      raise Error, 'active gate register G0 claim differs from complete independent derivation' unless criteria['gate_register_complete_match']
    end
    status = criteria.values.all? ? 'PASS' : 'OPEN'
    {
      'status' => status,
      'derivation_source' => 'all_268_schema_v2_capability_rows',
      'gate_register_claim' => active && active.dig(:gate, 'project_g0'),
      'criteria' => criteria,
      'reason_codes' => criteria.select { |_key, value| !value }.keys.map(&:upcase)
    }
  end
  private_class_method :derive_g0

  def derive_g3(root, g0, engineering, evidence_inputs)
    raise Error, 'G3 evidence inputs must be an object' unless evidence_inputs.is_a?(Hash)
    allowed_inputs = G3_CRITERIA_KEYS
    unknown = evidence_inputs.keys - allowed_inputs
    raise Error, 'G3 evidence inputs contain unknown criteria' unless unknown.empty?

    raise Error, 'engineering evidence map capability universe drift' unless engineering.fetch('capabilities').length == 268
    criteria = {}
    G3_CRITERIA_KEYS.each do |criterion|
      input = evidence_inputs[criterion]
      criteria[criterion] = input ? validated_g3_criterion(root, criterion, input) : {
        'status' => 'OPEN', 'deployed_commit_sha' => nil,
        'evidence_pointer' => nil, 'authority_pointer' => nil
      }
    end
    deployed_shas = criteria.values.map { |value| value.fetch('deployed_commit_sha') }.compact.uniq
    raise Error, 'G3 evidence records do not bind one exact deployed commit SHA' if deployed_shas.length > 1
    all_pass = criteria.values.all? { |criterion| criterion.fetch('status') == 'PASS' }
    status = g0.fetch('status') == 'PASS' && all_pass ? 'PASS' : 'OPEN'
    {
      'status' => status,
      'requires_g0_pass' => true,
      'evidence_authority_boundary' => 'Hosted, reconciliation, recovery, security, accessibility, performance, defect, and owner-acceptance evidence must be independently hash-bound; engineering observations cannot satisfy owner acceptance.',
      'criteria' => criteria,
      'reason_codes' => ([('G0_OPEN' unless g0.fetch('status') == 'PASS')] + criteria.select { |_key, value| value.fetch('status') != 'PASS' }.keys.map(&:upcase)).compact
    }
  end
  private_class_method :derive_g3

  def validated_g3_criterion(root, criterion, input)
    Core.assert_closed_schema!(input, required: %w[evidence_pointer authority_pointer],
                               label: "$.g3_evidence.#{criterion}")
    evidence = validated_reference(root, input.fetch('evidence_pointer'), "$.g3_evidence.#{criterion}.evidence_pointer")
    evidence_record = Core.parse_json(
      safe_read(root, evidence.fetch('path'), label: "G3 #{criterion} evidence record"),
      label: "$.g3_evidence.#{criterion}.record"
    )
    Core.assert_closed_schema!(evidence_record, required: G3_EVIDENCE_RECORD_KEYS,
                               label: "$.g3_evidence.#{criterion}.record")
    Core.assert_secret_free!(evidence_record, label: "$.g3_evidence.#{criterion}.record")
    covered = evidence_record.fetch('covered_criteria')
    deployed_sha = evidence_record.fetch('deployed_commit_sha')
    primary_criterion = evidence_record.fetch('criterion')
    unless evidence_record.fetch('artifact_type') == 'g3_release_evidence_record' &&
           evidence_record.fetch('schema_version') == 1 && G3_CRITERIA_KEYS.include?(primary_criterion) &&
           covered.is_a?(Array) && covered.uniq == covered && covered.include?(criterion) &&
           covered.include?(primary_criterion) &&
           (covered - G3_CRITERIA_KEYS).empty? && evidence_record.fetch('status') == 'PASS' &&
           evidence_record.fetch('snapshot_date') == SNAPSHOT_DATE &&
           evidence_record.fetch('data_boundary') == 'synthetic_only' &&
           deployed_sha.is_a?(String) && deployed_sha.match?(/\A[0-9a-f]{40}\z/) &&
           evidence_record.fetch('authority_effect') == 'none_engineering_or_assurance_evidence_only'
      raise Error, "G3 #{criterion} evidence record contract invalid"
    end
    evidence_references = evidence_record.fetch('evidence_references')
    unless evidence_references.is_a?(Array) && !evidence_references.empty? && evidence_references.uniq == evidence_references
      raise Error, "G3 #{criterion} evidence references missing"
    end
    evidence_references.each_with_index do |reference, index|
      validated_reference(root, reference, "$.g3_evidence.#{criterion}.record.evidence_references[#{index}]")
    end
    if criterion == 'current_exact_sha_engineering_map'
      map_reference = source_reference(root, EVIDENCE_MAP_PATH)
      raise Error, 'G3 exact-SHA engineering evidence does not bind the current engineering map' unless evidence_references.include?(map_reference)
    end
    authority_value = input.fetch('authority_pointer')
    authority = authority_value && validated_reference(root, authority_value, "$.g3_evidence.#{criterion}.authority_pointer")
    if criterion == 'owner_acceptance'
      raise Error, 'G3 owner acceptance requires an independent authority pointer' unless authority
      if [EVIDENCE_MAP_PATH, SOURCE_EVIDENCE_MAP_PATH].include?(authority.fetch('path'))
        raise Error, 'engineering evidence cannot confer owner acceptance'
      end
      acceptance = Core.parse_json(
        safe_read(root, authority.fetch('path'), label: 'G3 owner acceptance authority record'),
        label: '$.g3_evidence.owner_acceptance.authority_record'
      )
      Core.assert_closed_schema!(acceptance, required: G3_OWNER_ACCEPTANCE_KEYS,
                                 label: '$.g3_evidence.owner_acceptance.authority_record')
      Core.assert_secret_free!(acceptance, label: '$.g3_evidence.owner_acceptance.authority_record')
      identity = acceptance.fetch('institutional_id')
      accepted = acceptance.fetch('accepted_criteria')
      unless acceptance.fetch('artifact_type') == 'g3_owner_acceptance_decision' &&
             acceptance.fetch('schema_version') == 1 && acceptance.fetch('status') == 'approved' &&
             acceptance.fetch('snapshot_date') == SNAPSHOT_DATE && acceptance.fetch('data_boundary') == 'synthetic_only' &&
             acceptance.fetch('deployed_commit_sha') == deployed_sha && identity.is_a?(String) && !identity.strip.empty? &&
             acceptance.fetch('capacity') == 'product_authority' && accepted == G3_CRITERIA_KEYS &&
             acceptance.fetch('evidence_reference') == evidence &&
             acceptance.fetch('authority_effect') == 'confers_g3_owner_acceptance_only'
        raise Error, 'G3 owner acceptance authority record contract invalid'
      end
    elsif authority
      raise Error, "G3 #{criterion} forbids an owner-authority pointer"
    end
    {
      'status' => 'PASS', 'deployed_commit_sha' => deployed_sha,
      'evidence_pointer' => evidence, 'authority_pointer' => authority
    }
  rescue Core::Error, KeyError => e
    raise Error, safe_error(e)
  end
  private_class_method :validated_g3_criterion

  def validated_reference(root, value, label)
    Core.assert_closed_schema!(value, required: SOURCE_REFERENCE_KEYS, label: label)
    expected = source_reference(root, value.fetch('path'))
    raise Error, "#{label}: stale SHA-256" unless value == expected
    expected
  end
  private_class_method :validated_reference

  def governance_projection(row)
    {
      'governance_state' => row.fetch('governance_state'),
      'owner_state' => row.fetch('owner_state'),
      'owner_assignment_status' => row.fetch('owner_assignment_status'),
      'decision_status' => row.fetch('decision_status'),
      'owner_record_id' => row.fetch('owner_record_id'),
      'decision_event_id' => row.fetch('decision_event_id'),
      'canonical_disposition' => row.fetch('canonical_disposition'),
      'derived_tier' => row.fetch('derived_tier'),
      'g0_terminal' => row.fetch('g0_terminal'),
      'implementation_authorized' => row.fetch('implementation_authorized')
    }
  end
  private_class_method :governance_projection

  def empty_governance
    {
      'governance_state' => 'PENDING',
      'owner_state' => 'DRAFT',
      'owner_assignment_status' => 'pending',
      'decision_status' => 'pending',
      'owner_record_id' => nil,
      'decision_event_id' => nil,
      'canonical_disposition' => nil,
      'derived_tier' => nil,
      'g0_terminal' => false,
      'implementation_authorized' => false
    }
  end
  private_class_method :empty_governance

  def unavailable_governance_drift?(value)
    value != empty_governance
  end
  private_class_method :unavailable_governance_drift?

  def governance_pointer(active, resolution, index, row)
    event_id = row.fetch('decision_event_id')
    event = event_id && active.fetch(:events_by_id).fetch(event_id) { raise Error, 'active decision event reference missing' }
    {
      'expanded_register_path' => repository_relative(resolution.fetch(:root), active.fetch(:expanded_path)),
      'expanded_register_sha256' => active.fetch(:expanded_sha256),
      'entry_index_base' => 0,
      'entry_index' => index,
      'entry_sha256' => Core.canonical_sha256(row),
      'decision_event_id' => event_id,
      'decision_event_sha256' => event && Core.canonical_sha256(event),
      'selection_sha256' => resolution.fetch(:selection_sha256),
      'bundle_manifest_sha256' => active.fetch(:manifest_sha256)
    }
  end
  private_class_method :governance_pointer

  def profile_binding(root, resolution, active, reason)
    contract_bytes = safe_read(root, CONTRACT_PATH, label: '$.contract')
    contract = Core.parse_json(contract_bytes, label: '$.contract')
    return {
      'status' => 'unavailable', 'reason_code' => reason || 'pointer_contract_invalid',
      'pointer_revision' => nil, 'pointer_sha256' => nil,
      'selection_path' => nil, 'selection_sha256' => nil,
      'bundle_manifest_path' => nil, 'bundle_manifest_sha256' => nil,
      'expanded_register_path' => nil, 'expanded_register_sha256' => nil,
      'gate_register_path' => nil, 'gate_register_sha256' => nil,
      'validator_contract_path' => CONTRACT_PATH,
      'validator_contract_sha256' => Digest::SHA256.hexdigest(contract_bytes),
      'validator_contract_version' => contract.dig('validator', 'version')
    } unless resolution && active

    {
      'status' => 'active', 'reason_code' => nil,
      'pointer_revision' => resolution.fetch(:pointer).fetch('revision'),
      'pointer_sha256' => resolution.fetch(:pointer_sha256),
      'selection_path' => repository_relative(root, resolution.fetch(:selection_path)),
      'selection_sha256' => resolution.fetch(:selection_sha256),
      'bundle_manifest_path' => repository_relative(root, active.fetch(:manifest_path)),
      'bundle_manifest_sha256' => active.fetch(:manifest_sha256),
      'expanded_register_path' => repository_relative(root, active.fetch(:expanded_path)),
      'expanded_register_sha256' => active.fetch(:expanded_sha256),
      'gate_register_path' => repository_relative(root, active.fetch(:gate_path)),
      'gate_register_sha256' => active.fetch(:gate_sha256),
      'validator_contract_path' => resolution.dig(:validator_contract, 'path'),
      'validator_contract_sha256' => resolution.dig(:validator_contract, 'sha256'),
      'validator_contract_version' => resolution.dig(:validator_contract, 'version')
    }
  end
  private_class_method :profile_binding

  def source_records(root, sources, engineering)
    {
      'governance_contract' => source_reference(root, CONTRACT_PATH),
      'historical_hash_manifest' => source_reference(root, V1_MANIFEST_PATH),
      'canonical_capability_order' => source_reference(root, SOURCE_MANIFEST_PATH),
      'engineering_evidence_map_v2' => source_reference(root, EVIDENCE_MAP_PATH),
      'engineering_evidence_map_source' => deep_copy(engineering.fetch('source_evidence_map')),
      'source_register_count' => sources.fetch(:source_manifest).fetch('batches').length,
      'superseded_ledger' => verified_superseded_ledger(root)
    }
  end
  private_class_method :source_records

  def verified_superseded_ledger(root)
    verified_r2_ledger(root)
    bytes = safe_read(root, PREDECESSOR_OUTPUT_PATH, label: 'superseded schema-v2 ledger')
    unless Digest::SHA256.hexdigest(bytes) == PREDECESSOR_SHA256
      raise Error, 'superseded schema-v2 ledger byte hash drift'
    end
    predecessor = Core.parse_json(bytes, label: '$.superseded_ledger')
    Core.assert_closed_schema!(predecessor, required: TOP_LEVEL_KEYS, label: '$.superseded_ledger')
    unless predecessor.values_at('artifact_type', 'schema_version', 'artifact_id', 'snapshot_date', 'data_boundary') == [
      'g0_g3_coverage_ledger_v2', 2, PREDECESSOR_ARTIFACT_ID, SNAPSHOT_DATE, 'synthetic_only'
    ]
      raise Error, 'superseded schema-v2 ledger identity or boundary drift'
    end
    predecessor_sources = predecessor.fetch('sources')
    Core.assert_closed_schema!(predecessor_sources, required: SOURCE_KEYS,
                               label: '$.superseded_ledger.sources')
    validate_r2_ledger_reference!(predecessor_sources.fetch('superseded_ledger'))
    predecessor_binding = predecessor.fetch('governance_profile_binding')
    Core.assert_closed_schema!(predecessor_binding, required: PROFILE_BINDING_KEYS,
                               label: '$.superseded_ledger.governance_profile_binding')
    unless predecessor_sources.dig('governance_contract', 'sha256') == PREDECESSOR_CONTRACT_SHA256 &&
           predecessor_binding.fetch('validator_contract_sha256') == PREDECESSOR_CONTRACT_SHA256 &&
           predecessor_binding.fetch('validator_contract_version') == PREDECESSOR_CONTRACT_VERSION
      raise Error, 'superseded schema-v2 ledger contract binding drift'
    end
    superseded_ledger_reference
  rescue Core::Error, KeyError => e
    raise Error, safe_error(e)
  end
  private_class_method :verified_superseded_ledger

  def verified_r2_ledger(root)
    verified_original_ledger(root)
    bytes = safe_read(root, R2_OUTPUT_PATH, label: 'R2 schema-v2 ledger')
    unless Digest::SHA256.hexdigest(bytes) == R2_SHA256
      raise Error, 'R2 schema-v2 ledger byte hash drift'
    end
    r2 = Core.parse_json(bytes, label: '$.r2_ledger')
    Core.assert_closed_schema!(r2, required: TOP_LEVEL_KEYS, label: '$.r2_ledger')
    unless r2.values_at('artifact_type', 'schema_version', 'artifact_id', 'snapshot_date', 'data_boundary') == [
      'g0_g3_coverage_ledger_v2', 2, R2_ARTIFACT_ID, SNAPSHOT_DATE, 'synthetic_only'
    ]
      raise Error, 'R2 schema-v2 ledger identity or boundary drift'
    end
    r2_sources = r2.fetch('sources')
    Core.assert_closed_schema!(r2_sources, required: SOURCE_KEYS, label: '$.r2_ledger.sources')
    validate_original_ledger_reference!(r2_sources.fetch('superseded_ledger'))
    r2_binding = r2.fetch('governance_profile_binding')
    Core.assert_closed_schema!(r2_binding, required: PROFILE_BINDING_KEYS,
                               label: '$.r2_ledger.governance_profile_binding')
    unless r2_sources.dig('governance_contract', 'sha256') == R2_CONTRACT_SHA256 &&
           r2_binding.fetch('validator_contract_sha256') == R2_CONTRACT_SHA256 &&
           r2_binding.fetch('validator_contract_version') == R2_CONTRACT_VERSION
      raise Error, 'R2 schema-v2 ledger contract binding drift'
    end
    r2_ledger_reference
  rescue Core::Error, KeyError => e
    raise Error, safe_error(e)
  end
  private_class_method :verified_r2_ledger

  def verified_original_ledger(root)
    bytes = safe_read(root, ORIGINAL_OUTPUT_PATH, label: 'original schema-v2 ledger')
    unless Digest::SHA256.hexdigest(bytes) == ORIGINAL_SHA256
      raise Error, 'original schema-v2 ledger byte hash drift'
    end
    original = Core.parse_json(bytes, label: '$.original_ledger')
    Core.assert_closed_schema!(original, required: TOP_LEVEL_KEYS, label: '$.original_ledger')
    unless original.values_at('artifact_type', 'schema_version', 'artifact_id', 'snapshot_date', 'data_boundary') == [
      'g0_g3_coverage_ledger_v2', 2, ORIGINAL_ARTIFACT_ID, SNAPSHOT_DATE, 'synthetic_only'
    ]
      raise Error, 'original schema-v2 ledger identity or boundary drift'
    end
    original_sources = original.fetch('sources')
    Core.assert_closed_schema!(original_sources, required: ORIGINAL_SOURCE_KEYS,
                               label: '$.original_ledger.sources')
    original_binding = original.fetch('governance_profile_binding')
    Core.assert_closed_schema!(original_binding, required: PROFILE_BINDING_KEYS,
                               label: '$.original_ledger.governance_profile_binding')
    unless original_sources.dig('governance_contract', 'sha256') == ORIGINAL_CONTRACT_SHA256 &&
           original_binding.fetch('validator_contract_sha256') == ORIGINAL_CONTRACT_SHA256 &&
           original_binding.fetch('validator_contract_version') == ORIGINAL_CONTRACT_VERSION
      raise Error, 'original schema-v2 ledger contract binding drift'
    end
    original_ledger_reference
  rescue Core::Error, KeyError => e
    raise Error, safe_error(e)
  end
  private_class_method :verified_original_ledger

  def original_ledger_reference
    {
      'path' => ORIGINAL_OUTPUT_PATH,
      'sha256' => ORIGINAL_SHA256,
      'artifact_id' => ORIGINAL_ARTIFACT_ID,
      'relationship' => SUPERSESSION_RELATIONSHIP
    }
  end
  private_class_method :original_ledger_reference

  def validate_original_ledger_reference!(reference)
    Core.assert_closed_schema!(reference, required: SUPERSEDED_LEDGER_KEYS,
                               label: '$.superseded_ledger.sources.superseded_ledger')
    unless reference == original_ledger_reference
      raise Error, 'schema-v2 predecessor chain binding drift'
    end
    true
  rescue Core::Error, KeyError => e
    raise Error, safe_error(e)
  end
  private_class_method :validate_original_ledger_reference!

  def superseded_ledger_reference
    {
      'path' => PREDECESSOR_OUTPUT_PATH,
      'sha256' => PREDECESSOR_SHA256,
      'artifact_id' => PREDECESSOR_ARTIFACT_ID,
      'relationship' => SUPERSESSION_RELATIONSHIP
    }
  end
  private_class_method :superseded_ledger_reference

  def r2_ledger_reference
    {
      'path' => R2_OUTPUT_PATH,
      'sha256' => R2_SHA256,
      'artifact_id' => R2_ARTIFACT_ID,
      'relationship' => SUPERSESSION_RELATIONSHIP
    }
  end
  private_class_method :r2_ledger_reference

  def validate_r2_ledger_reference!(reference)
    Core.assert_closed_schema!(reference, required: SUPERSEDED_LEDGER_KEYS,
                               label: '$.superseded_ledger.sources.superseded_ledger')
    unless reference == r2_ledger_reference
      raise Error, 'schema-v2 R2 predecessor chain binding drift'
    end
    true
  rescue Core::Error, KeyError => e
    raise Error, safe_error(e)
  end
  private_class_method :validate_r2_ledger_reference!

  def validate_superseded_ledger_reference!(reference)
    Core.assert_closed_schema!(reference, required: SUPERSEDED_LEDGER_KEYS,
                               label: '$.ledger_v2.sources.superseded_ledger')
    unless reference == superseded_ledger_reference
      raise Error, 'schema-v2 ledger supersession binding drift'
    end
    true
  rescue Core::Error, KeyError => e
    raise Error, safe_error(e)
  end
  private_class_method :validate_superseded_ledger_reference!

  def capability_summary(rows)
    {
      'total' => rows.length,
      'unique_capability_ids' => rows.map { |row| row.fetch('capability_id') }.uniq.length,
      'by_batch' => ('A'..'G').to_h { |batch| [batch, rows.count { |row| row.fetch('batch') == batch }] },
      'governance_pointer_present' => rows.count { |row| !row.fetch('governance_decision_pointer').nil? }
    }
  end
  private_class_method :capability_summary

  def workflow_summary(rows)
    { 'total' => rows.length, 'workflow_ids' => rows.map { |row| row.fetch('workflow_id') } }
  end
  private_class_method :workflow_summary

  def stable_resolution?(first, second)
    %i[pointer_sha256 selection_sha256 bundle_sha256 adoption_sha256].all? { |key| first[key] == second[key] }
  end
  private_class_method :stable_resolution?

  def source_reference(root, relative)
    bytes = safe_read(root, relative, label: relative)
    { 'path' => relative, 'sha256' => Digest::SHA256.hexdigest(bytes) }
  end
  private_class_method :source_reference

  def secure_root(root)
    Comparator.secure_directory!(root, label: '$.root').realpath
  rescue Comparator::Error => e
    raise Error, safe_error(e)
  end
  private_class_method :secure_root

  def safe_read(root, relative, label:)
    with_parent_directory(root, relative, error_message: "#{label}: unsafe path") do |parent, leaf, revalidate_parent|
      file = openat_io(parent, leaf, File::RDONLY | nofollow_flag, 0, operation: "open #{label}")
      initial = file.stat
      validate_source_file!(initial, label)
      bytes = file.read
      final = file.stat
      unless stable_stat_signature(initial) == stable_stat_signature(final)
        raise Error, "#{label}: changed while being read"
      end
      current = verify_entry_identity!(parent, leaf, initial, flags: File::RDONLY, label: label)
      unless stable_stat_signature(initial) == stable_stat_signature(current)
        raise Error, "#{label}: changed while being read"
      end
      revalidate_parent.call
      bytes
    ensure
      file&.close unless file&.closed?
    end
  rescue Errno::ELOOP, Errno::EMLINK
    raise Error, "#{label}: unsafe path"
  rescue SystemCallError, IOError
    raise Error, "#{label}: unavailable"
  end
  private_class_method :safe_read

  def validate_source_file!(stat, label)
    unless stat.file? && stat.uid == Process.uid && stat.nlink == 1 && (stat.mode & 0o022).zero?
      raise Error, "#{label}: unsafe regular file"
    end
    true
  end
  private_class_method :validate_source_file!

  def relative_segments(relative, error_message:)
    value = relative.to_s
    segments = value.split(File::SEPARATOR, -1)
    if value.empty? || value.include?("\0") || Pathname.new(value).absolute? ||
       segments.empty? || segments.any? { |segment| segment.empty? || segment == '.' || segment == '..' }
      raise Error, error_message
    end
    segments
  end
  private_class_method :relative_segments

  def with_parent_directory(root, relative, error_message:)
    segments = relative_segments(relative, error_message: error_message)
    handles = []
    root_io = File.open(root.to_s, File::RDONLY | nofollow_flag)
    handles << root_io
    validate_directory_handle!(root_io, error_message)
    identities = [[nil, stable_identity(root_io.stat)]]
    current = root_io
    segments[0...-1].each do |segment|
      child = openat_io(current, segment, File::RDONLY | nofollow_flag, 0, operation: 'open parent directory')
      validate_directory_handle!(child, error_message)
      handles << child
      identities << [segment, stable_identity(child.stat)]
      current = child
    end

    revalidate = lambda do
      probe_handles = []
      probe = File.open(root.to_s, File::RDONLY | nofollow_flag)
      probe_handles << probe
      validate_directory_handle!(probe, error_message)
      raise Error, error_message unless stable_identity(probe.stat) == identities.first.last
      identities.drop(1).each do |segment, expected|
        next_probe = openat_io(probe, segment, File::RDONLY | nofollow_flag, 0, operation: 'reopen parent directory')
        validate_directory_handle!(next_probe, error_message)
        raise Error, error_message unless stable_identity(next_probe.stat) == expected
        probe_handles << next_probe
        probe = next_probe
      end
      true
    ensure
      probe_handles&.reverse_each { |handle| handle.close unless handle.closed? }
    end

    yield current, segments.last, revalidate
  rescue Errno::ELOOP, Errno::ENOTDIR
    raise Error, error_message
  ensure
    handles&.reverse_each { |handle| handle.close unless handle.closed? }
  end
  private_class_method :with_parent_directory

  def validate_directory_handle!(handle, error_message)
    stat = handle.stat
    unless stat.directory? && stat.uid == Process.uid && (stat.mode & 0o022).zero?
      raise Error, error_message
    end
    true
  end
  private_class_method :validate_directory_handle!

  def nofollow_flag
    defined?(File::NOFOLLOW) ? File::NOFOLLOW : 0
  end
  private_class_method :nofollow_flag

  def openat_io(parent, leaf, flags, permissions, operation:)
    descriptor = NativeFs.openat(parent.fileno, leaf, flags, permissions)
    raise SystemCallError.new(operation, Fiddle.last_error) if descriptor.negative?

    access_mode = case flags & 0o3
                  when File::WRONLY then 'w'
                  when File::RDWR then 'r+'
                  else 'r'
                  end
    io = File.for_fd(descriptor, access_mode, autoclose: true)
    io.close_on_exec = true
    io
  end
  private_class_method :openat_io

  def stable_identity(stat)
    [stat.dev, stat.ino]
  end
  private_class_method :stable_identity

  def stable_stat_signature(stat)
    [stat.dev, stat.ino, stat.ftype, stat.nlink, stat.uid, stat.gid, stat.mode & 0o7777,
     stat.size, stat.mtime.to_i, stat.mtime.nsec, stat.ctime.to_i, stat.ctime.nsec]
  end
  private_class_method :stable_stat_signature

  def verify_entry_identity!(parent, leaf, expected_stat, flags:, label:)
    probe = openat_io(parent, leaf, flags | nofollow_flag, 0, operation: "reopen #{label}")
    actual = probe.stat
    raise Error, "#{label}: entry identity changed" unless stable_identity(actual) == stable_identity(expected_stat)

    actual
  ensure
    probe&.close unless probe&.closed?
  end
  private_class_method :verify_entry_identity!

  def secure_bundle_file(bundle, name)
    Comparator.secure_regular_file!(Pathname.new(bundle).join(name), bundle, label: "$.active.#{name}")
  end
  private_class_method :secure_bundle_file

  def publish_create_only!(root, relative, bytes, revalidate: nil)
    with_parent_directory(root, relative, error_message: 'unsafe schema-v2 output path') do |parent, target, revalidate_parent|
      created = false
      created_stat = nil
      begin
        revalidate_parent.call
        revalidate&.call
        file = openat_io(parent, target,
                         File::RDWR | File::CREAT | File::EXCL | nofollow_flag,
                         0o600, operation: 'create schema-v2 ledger')
        created = true
        file.chmod(0o600)
        file.binmode
        created_stat = file.stat
        unless created_stat.file? && created_stat.uid == Process.uid && created_stat.nlink == 1 &&
               (created_stat.mode & 0o777) == 0o600 && created_stat.size.zero?
          raise Error, 'schema-v2 output is unsafe'
        end

        revalidate_parent.call
        revalidate&.call
        file.write(bytes)
        file.flush
        file.fsync
        written_stat = file.stat
        unless stable_identity(written_stat) == stable_identity(created_stat) && written_stat.nlink == 1 &&
               written_stat.size == bytes.bytesize
          raise Error, 'schema-v2 ledger write verification failed'
        end
        revalidate_parent.call
        revalidate&.call
        parent.fsync

        file.rewind
        unless file.read == bytes
          raise Error, 'schema-v2 ledger descriptor readback mismatch'
        end
        current = verify_entry_identity!(parent, target, created_stat, flags: File::RDONLY,
                                         label: 'schema-v2 ledger')
        unless current.nlink == 1 && current.uid == Process.uid && (current.mode & 0o777) == 0o600 &&
               current.size == bytes.bytesize
          raise Error, 'schema-v2 ledger final readback mismatch'
        end
        revalidate_parent.call
        revalidate&.call
      rescue StandardError
        rollback_created_output!(parent, target, created_stat) if created && created_stat
        raise
      ensure
        file&.close unless file&.closed?
      end
    end
  rescue Errno::EEXIST
    raise Error, 'schema-v2 ledger already exists'
  end
  private_class_method :publish_create_only!

  def rollback_created_output!(parent, target, created_stat)
    current = openat_io(parent, target, File::RDONLY | nofollow_flag, 0,
                        operation: 'inspect failed schema-v2 publication')
    unless stable_identity(current.stat) == stable_identity(created_stat)
      raise Error, 'schema-v2 publication residual conflict; manual recovery required'
    end
    current.close
    unlinkat!(parent, target, operation: 'rollback schema-v2 publication')
    parent.fsync
    true
  rescue Errno::ENOENT
    true
  ensure
    current&.close unless current&.closed?
  end
  private_class_method :rollback_created_output!

  def unlinkat!(parent, leaf, operation:)
    result = NativeFs.unlinkat(parent.fileno, leaf, 0)
    raise SystemCallError.new(operation, Fiddle.last_error) if result.negative?

    true
  end
  private_class_method :unlinkat!

  def repository_relative(root, path)
    Pathname.new(path).realpath.relative_path_from(Pathname.new(root).realpath).to_s
  rescue ArgumentError, SystemCallError
    raise Error, 'resolved governance path escapes repository'
  end
  private_class_method :repository_relative

  def deep_copy(value)
    Marshal.load(Marshal.dump(value))
  end
  private_class_method :deep_copy

  def present?(value)
    value.is_a?(String) && !value.strip.empty?
  end
  private_class_method :present?

  def safe_error(error)
    message = error.message.to_s
    message.match?(G0G3CoverageLedger::SECRET_PATTERN) ? 'secret-like content rejected' : message
  end
  private_class_method :safe_error
end

if $PROGRAM_NAME == __FILE__
  options = { action: nil, snapshot_date: nil, schema_version: 1 }
  parser = OptionParser.new do |opts|
    opts.banner = 'Usage: ruby scripts/generate-g0-g3-coverage-ledger.rb (--check|--write) --snapshot-date YYYY-MM-DD [--schema-version 1|2]'
    opts.on('--check', 'fail unless the committed ledger is byte-for-byte current') { options[:action] = :check }
    opts.on('--write', 'atomically write the deterministic ledger') { options[:action] = :write }
    opts.on('--snapshot-date DATE', 'explicit evidence snapshot date') { |value| options[:snapshot_date] = value }
    opts.on('--schema-version VERSION', Integer, 'explicit ledger schema version (1 or 2)') { |value| options[:schema_version] = value }
  end
  begin
    parser.parse!
    raise OptionParser::MissingArgument, '--check or --write' unless options[:action]
    raise OptionParser::MissingArgument, '--snapshot-date' unless options[:snapshot_date]
    raise OptionParser::InvalidArgument, 'unexpected positional arguments' unless ARGV.empty?
    raise OptionParser::InvalidArgument, 'schema version must be 1 or 2' unless [1, 2].include?(options[:schema_version])
    if options[:schema_version] == 1
      ledger = G0G3CoverageLedger.new(snapshot_date: options[:snapshot_date])
      options[:action] == :check ? ledger.check! : ledger.write!
    else
      raise OptionParser::InvalidArgument, "schema-v2 snapshot date must be #{G0G3CoverageLedgerV2::SNAPSHOT_DATE}" unless options[:snapshot_date] == G0G3CoverageLedgerV2::SNAPSHOT_DATE
      options[:action] == :check ? G0G3CoverageLedgerV2.check! : G0G3CoverageLedgerV2.write!
    end
    puts "G0-G3 coverage ledger #{options[:action]} passed for #{options[:snapshot_date]}"
  rescue OptionParser::ParseError, G0G3CoverageLedger::ContractError, G0G3CoverageLedgerV2::Error => e
    message = e.message.to_s.lines.first.to_s.strip
    if e.is_a?(G0G3CoverageLedgerV2::Error) && message.match?(G0G3CoverageLedger::SECRET_PATTERN)
      message = 'schema-v2 ledger validation failed'
    end
    warn message
    exit 1
  end
end
