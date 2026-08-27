#!/usr/bin/env ruby
# frozen_string_literal: true

require 'date'
require 'digest'
require 'json'
require 'optparse'
require 'pathname'
require 'tempfile'

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
    JSON.pretty_generate(build(overlay: overlay)) + "\n"
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
    raise ContractError, "#{label}: invalid JSON: #{e.message}"
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

if $PROGRAM_NAME == __FILE__
  options = { action: nil, snapshot_date: nil }
  parser = OptionParser.new do |opts|
    opts.banner = 'Usage: ruby scripts/generate-g0-g3-coverage-ledger.rb (--check|--write) --snapshot-date YYYY-MM-DD'
    opts.on('--check', 'fail unless the committed ledger is byte-for-byte current') { options[:action] = :check }
    opts.on('--write', 'atomically write the deterministic ledger') { options[:action] = :write }
    opts.on('--snapshot-date DATE', 'explicit evidence snapshot date') { |value| options[:snapshot_date] = value }
  end
  begin
    parser.parse!
    raise OptionParser::MissingArgument, '--check or --write' unless options[:action]
    raise OptionParser::MissingArgument, '--snapshot-date' unless options[:snapshot_date]
    raise OptionParser::InvalidArgument, 'unexpected positional arguments' unless ARGV.empty?
    ledger = G0G3CoverageLedger.new(snapshot_date: options[:snapshot_date])
    options[:action] == :check ? ledger.check! : ledger.write!
    puts "G0-G3 coverage ledger #{options[:action]} passed for #{options[:snapshot_date]}"
  rescue OptionParser::ParseError, G0G3CoverageLedger::ContractError => e
    warn e.message
    exit 1
  end
end
