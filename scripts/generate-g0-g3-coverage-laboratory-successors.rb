#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'json'
require 'optparse'

require_relative 'generate-g0-g3-coverage-radiology-successors'

# Append-only successor publisher for the bounded laboratory tariff/source
# evidence. R4/R6 are immutable inputs; this module can change engineering
# evidence only and deliberately has no selector, owner, gate or deployment API.
module G0G3CoverageLaboratorySuccessors
  Old = G0G3CoverageTariffSuccessors
  Core = G0ProportionalGovernanceV2
  Error = Old::Error
  UsageError = Old::UsageError

  SNAPSHOT_DATE = '2026-09-02'
  GENERATOR_PATH = 'scripts/generate-g0-g3-coverage-laboratory-successors.rb'
  SUPERSESSION_RELATIONSHIP = 'supersedes_without_rewriting_or_reinterpreting_predecessor'

  MAP_PREDECESSOR_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R4.json'
  MAP_PREDECESSOR_SHA256 = 'e040fb70399c78c2921dd69fe6236850bf5b9ee8ad0a3de19413a43a67f8158e'
  MAP_PREDECESSOR_ARTIFACT_ID = 'COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-02-R4'
  MAP_OUTPUT_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R5.json'
  MAP_ARTIFACT_ID = 'COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-02-R5'

  LEDGER_PREDECESSOR_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R6.json'
  LEDGER_PREDECESSOR_SHA256 = 'a43e151b0829bbd36c45f1f28ae07a20669165ceb83fe36fd8b01aae0ac05f50'
  LEDGER_PREDECESSOR_ARTIFACT_ID = 'G0-G3-COVERAGE-LEDGER-V2-2026-09-02-R6'
  LEDGER_OUTPUT_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R7.json'
  LEDGER_ARTIFACT_ID = 'G0-G3-COVERAGE-LEDGER-V2-2026-09-02-R7'

  TARGET_CAPABILITY_IDS = %w[PAR-CLN-006 PAR-ADM-011 PAR-ADM-018 PAR-ADM-019].freeze
  TARGET_WORKFLOW_ID = 'E2E-16'

  EVIDENCE_PATHS = %w[
    app/Http/Controllers/Finance/FinanceLaboratoryTariffController.php
    app/Models/FinanceLaboratorySourceEvent.php
    app/Models/FinanceLaboratoryTariffBinding.php
    app/Models/FinanceLaboratoryTariffBindingVersion.php
    app/Models/FinanceLaboratoryTariffOperationReceipt.php
    app/Support/Finance/FinanceLaboratorySourceAdapter.php
    app/Support/Finance/FinanceLaboratoryTariffActorPolicy.php
    app/Support/Finance/FinanceLaboratoryTariffAppendOnlyGuard.php
    app/Support/Finance/FinanceLaboratoryTariffBindingService.php
    app/Support/Finance/FinanceLaboratoryTariffContentDigest.php
    app/Support/Finance/FinanceLaboratoryTariffMutableHeadGuard.php
    app/Support/Finance/FinanceLaboratoryTariffMutationScope.php
    app/Support/Finance/FinanceLaboratoryTariffProjection.php
    app/Support/Finance/FinanceLaboratoryTariffSqlWriteGuard.php
    app/Support/Finance/FinanceBillService.php
    app/Support/Finance/FinanceSourceCoordinator.php
    app/Support/Operations/SyntheticRecoverySnapshot.php
    app/Support/Simulation/SyntheticResetService.php
    database/migrations/2026_09_02_000400_create_laboratory_verified_result_tariff_source.php
    docs/new-simrs-rebuild/phase-1/CROSS_SETTING_LABORATORY_VERIFIED_RESULT_TARIFF_SOURCE_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md
    docs/operations/T1_LOCAL_LABORATORY_TARIFF_SOURCE_EVIDENCE_2026-09-02.md
    storage/app/portability-rehearsals/20260902T052603Z-postgresql17-laboratory-tariff-source-fcfeacb4735a.json
    storage/app/portability-rehearsals/20260902T052935Z-mysql8411-laboratory-tariff-source-eab90648c5b1.json
    scripts/rehearse-local-laboratory-tariff-source-portability.rb
    tests/Documentation/CrossSettingLaboratoryVerifiedResultTariffSourceV1LocalEngineeringAuthorizationTest.rb
    tests/Documentation/LocalLaboratoryTariffSourceEvidenceRecordTest.rb
    tests/Documentation/LocalLaboratoryTariffSourcePortabilityHarnessContractTest.rb
    tests/Feature/Database/FinanceLaboratoryTariffGuardTest.php
    tests/Feature/Finance/FinanceLaboratorySourceAdapterTest.php
    tests/Feature/Finance/FinanceLaboratoryTariffBindingCoreTest.php
    tests/Feature/Finance/FinanceLaboratoryTariffHttpWorkflowTest.php
  ].sort.freeze

  module_function

  def map_document(root: repository_root)
    root_path = secure_root(root)
    predecessor = load_exact_json(root_path, MAP_PREDECESSOR_PATH, MAP_PREDECESSOR_SHA256,
                                  label: '$.superseded_evidence_map')
    validate_map_predecessor!(predecessor)
    document = build_map(root_path, predecessor)
    validate_map!(document, root: root_path, predecessor: predecessor)
    document
  end

  def ledger_document(root: repository_root)
    root_path = secure_root(root)
    predecessor = load_exact_json(root_path, LEDGER_PREDECESSOR_PATH, LEDGER_PREDECESSOR_SHA256,
                                  label: '$.sources.superseded_ledger')
    validate_ledger_predecessor!(predecessor)
    map_bytes = safe_read(root_path, MAP_OUTPUT_PATH, label: '$.sources.engineering_evidence_map_v2')
    map = Core.parse_json(map_bytes, label: MAP_OUTPUT_PATH)
    map_sha = Digest::SHA256.hexdigest(map_bytes)
    validate_map!(map, root: root_path)
    document = build_ledger(predecessor, map, map_sha)
    validate_ledger!(document, root: root_path, predecessor: predecessor, map: map, map_sha256: map_sha)
    document
  rescue Core::Error, KeyError => e
    raise Error, e.message
  end

  def serialized_map(root: repository_root)
    Core.canonical_json(map_document(root: root)) + "\n"
  end

  def serialized_ledger(root: repository_root)
    Core.canonical_json(ledger_document(root: root)) + "\n"
  end

  def write_map!(root: repository_root, output: MAP_OUTPUT_PATH)
    root_path = secure_root(root)
    bytes = serialized_map(root: root_path)
    publish_create_only!(root_path, output, bytes, mode: 0o644) do |published|
      validate_map!(Core.parse_json(published, label: '$.published_map'), root: root_path)
    end
    receipt('generated_laboratory_engineering_evidence_map_successor', root_path, output, bytes)
  end

  def write_ledger!(root: repository_root, output: LEDGER_OUTPUT_PATH)
    root_path = secure_root(root)
    bytes = serialized_ledger(root: root_path)
    publish_create_only!(root_path, output, bytes, mode: 0o600) do |published|
      validate_ledger!(Core.parse_json(published, label: '$.published_ledger'), root: root_path)
    end
    receipt('generated_laboratory_coverage_ledger_successor', root_path, output, bytes)
  end

  def check_map!(root: repository_root, output: MAP_OUTPUT_PATH)
    root_path = secure_root(root)
    actual = safe_read(root_path, output, label: '$.engineering_evidence_map_successor')
    raise Error, 'stale laboratory engineering-evidence map successor' unless actual == serialized_map(root: root_path)
    true
  end

  def check_ledger!(root: repository_root, output: LEDGER_OUTPUT_PATH)
    root_path = secure_root(root)
    actual = safe_read(root_path, output, label: '$.coverage_ledger_successor')
    raise Error, 'stale laboratory coverage-ledger successor' unless actual == serialized_ledger(root: root_path)
    true
  end

  def validate_map!(document, root: repository_root, predecessor: nil)
    root_path = secure_root(root)
    predecessor ||= load_exact_json(root_path, MAP_PREDECESSOR_PATH, MAP_PREDECESSOR_SHA256,
                                    label: '$.superseded_evidence_map')
    validate_map_predecessor!(predecessor)
    validate_map_shape!(document)
    expected = build_map(root_path, predecessor)
    unless Core.canonical_json(document) == Core.canonical_json(expected)
      raise Error, '$.engineering_evidence_map_successor: differs from closed laboratory projection'
    end
    true
  rescue Core::Error, KeyError => e
    raise Error, e.message
  end

  def validate_ledger!(document, root: repository_root, predecessor: nil, map: nil, map_sha256: nil)
    root_path = secure_root(root)
    predecessor ||= load_exact_json(root_path, LEDGER_PREDECESSOR_PATH, LEDGER_PREDECESSOR_SHA256,
                                    label: '$.sources.superseded_ledger')
    validate_ledger_predecessor!(predecessor)
    unless map
      map_bytes = safe_read(root_path, MAP_OUTPUT_PATH, label: '$.sources.engineering_evidence_map_v2')
      map = Core.parse_json(map_bytes, label: MAP_OUTPUT_PATH)
      map_sha256 = Digest::SHA256.hexdigest(map_bytes)
    end
    validate_map!(map, root: root_path)
    validate_ledger_shape!(document)
    expected = build_ledger(predecessor, map, map_sha256)
    unless Core.canonical_json(document) == Core.canonical_json(expected)
      raise Error, '$.coverage_ledger_successor: differs from closed observation-only laboratory projection'
    end
    true
  rescue Core::Error, KeyError => e
    raise Error, e.message
  end

  def build_map(root_path, predecessor)
    document = deep_copy(predecessor)
    document['artifact_id'] = MAP_ARTIFACT_ID
    document['snapshot_date'] = SNAPSHOT_DATE
    document['superseded_evidence_map'] = map_predecessor_reference

    inputs = predecessor.fetch('explicit_evidence_inputs').to_h do |entry|
      [entry.fetch('path'), deep_copy(entry)]
    end
    EVIDENCE_PATHS.each do |relative|
      bytes = safe_read(root_path, relative, label: "$.explicit_evidence_inputs[#{relative}]")
      inputs[relative] = { 'path' => relative, 'sha256' => Digest::SHA256.hexdigest(bytes) }
    end
    document['explicit_evidence_inputs'] = inputs.values.sort_by { |entry| entry.fetch('path') }

    document.fetch('capabilities').each do |row|
      next unless TARGET_CAPABILITY_IDS.include?(row.fetch('capability_id'))
      row.fetch('engineering_evidence')['evidence_paths'] =
        (row.dig('engineering_evidence', 'evidence_paths') + EVIDENCE_PATHS).uniq.sort
    end
    workflow = document.fetch('workflows').find { |row| row.fetch('workflow_id') == TARGET_WORKFLOW_ID }
    raise Error, "missing #{TARGET_WORKFLOW_ID}" unless workflow
    workflow['evidence_paths'] = (workflow.fetch('evidence_paths') + EVIDENCE_PATHS).uniq.sort

    generator_bytes = safe_read(root_path, GENERATOR_PATH, label: '$.provenance.generator')
    document.fetch('provenance')['generator'] = {
      'path' => GENERATOR_PATH, 'sha256' => Digest::SHA256.hexdigest(generator_bytes)
    }
    document.fetch('provenance')['explicit_evidence_input_count'] = document.fetch('explicit_evidence_inputs').length
    document
  end
  private_class_method :build_map

  def build_ledger(predecessor, map, map_sha256)
    document = deep_copy(predecessor)
    document['artifact_id'] = LEDGER_ARTIFACT_ID
    document['snapshot_date'] = SNAPSHOT_DATE
    document.fetch('sources')['superseded_ledger'] = ledger_predecessor_reference
    document.fetch('sources')['engineering_evidence_map_v2'] = {
      'path' => MAP_OUTPUT_PATH, 'sha256' => map_sha256
    }
    map_capabilities = map.fetch('capabilities').to_h { |row| [row.fetch('capability_id'), row] }
    document.fetch('capabilities').each do |row|
      next unless TARGET_CAPABILITY_IDS.include?(row.fetch('capability_id'))
      mapped = map_capabilities.fetch(row.fetch('capability_id'))
      row['engineering_evidence'] = deep_copy(mapped.fetch('engineering_evidence'))
      row['workflow_observation'] = deep_copy(mapped.fetch('workflow_observation'))
    end
    map_workflow = map.fetch('workflows').find { |row| row.fetch('workflow_id') == TARGET_WORKFLOW_ID }
    workflow = document.fetch('workflows').find { |row| row.fetch('workflow_id') == TARGET_WORKFLOW_ID }
    raise Error, "missing #{TARGET_WORKFLOW_ID}" unless workflow && map_workflow
    workflow.replace(deep_copy(map_workflow))
    document
  end
  private_class_method :build_ledger

  def validate_map_predecessor!(document)
    unless document.values_at('artifact_type', 'schema_version', 'artifact_id', 'snapshot_date', 'data_boundary') ==
           ['g0_g3_coverage_evidence_map_v2', 2, MAP_PREDECESSOR_ARTIFACT_ID, SNAPSHOT_DATE, 'synthetic_only']
      raise Error, '$.superseded_evidence_map: identity or boundary drift'
    end
    validate_map_common!(document)
  end
  private_class_method :validate_map_predecessor!

  def validate_map_shape!(document)
    unless document.values_at('artifact_type', 'schema_version', 'artifact_id', 'snapshot_date', 'data_boundary') ==
           ['g0_g3_coverage_evidence_map_v2', 2, MAP_ARTIFACT_ID, SNAPSHOT_DATE, 'synthetic_only']
      raise Error, '$.engineering_evidence_map_v2: identity or boundary drift'
    end
    validate_map_common!(document)
    raise Error, '$.superseded_evidence_map: predecessor drift' unless document.fetch('superseded_evidence_map') == map_predecessor_reference
  end
  private_class_method :validate_map_shape!

  def validate_map_common!(document)
    assert_closed!(document, Old::MAP_TOP_LEVEL_KEYS, '$.engineering_evidence_map_v2')
    assert_secret_free!(document, '$.engineering_evidence_map_v2')
    assert_no_forbidden_map_keys!(document)
    inputs = document.fetch('explicit_evidence_inputs')
    paths = inputs.map { |entry| entry.fetch('path') }
    raise Error, '$.explicit_evidence_inputs: unsorted or duplicate' unless paths == paths.sort && paths.uniq == paths
    inputs.each { |entry| validate_reference!(entry) }
    raise Error, '$.capabilities: expected 268 rows' unless document.fetch('capabilities').length == 268
    document.fetch('capabilities').each do |row|
      Old.send(:validate_engineering!, row.fetch('engineering_evidence'), '$.capabilities.engineering_evidence')
      Old.send(:validate_workflow_observation!, row.fetch('workflow_observation'), '$.capabilities.workflow_observation')
    end
    raise Error, '$.workflows: expected 16 rows' unless document.fetch('workflows').length == 16
    document.fetch('workflows').each do |row|
      Old.send(:validate_engineering!, row.reject { |key, _| key == 'workflow_id' }, '$.workflows.engineering_evidence')
    end
    true
  end
  private_class_method :validate_map_common!

  def validate_ledger_predecessor!(document)
    unless document.values_at('artifact_type', 'schema_version', 'artifact_id', 'snapshot_date', 'data_boundary') ==
           ['g0_g3_coverage_ledger_v2', 2, LEDGER_PREDECESSOR_ARTIFACT_ID, SNAPSHOT_DATE, 'synthetic_only']
      raise Error, '$.sources.superseded_ledger: identity or boundary drift'
    end
    validate_ledger_common!(document)
  end
  private_class_method :validate_ledger_predecessor!

  def validate_ledger_shape!(document)
    unless document.values_at('artifact_type', 'schema_version', 'artifact_id', 'snapshot_date', 'data_boundary') ==
           ['g0_g3_coverage_ledger_v2', 2, LEDGER_ARTIFACT_ID, SNAPSHOT_DATE, 'synthetic_only']
      raise Error, '$.coverage_ledger_v2: identity or boundary drift'
    end
    validate_ledger_common!(document)
    raise Error, '$.sources.superseded_ledger: predecessor drift' unless document.dig('sources', 'superseded_ledger') == ledger_predecessor_reference
  end
  private_class_method :validate_ledger_shape!

  def validate_ledger_common!(document)
    assert_closed!(document, Old::LEDGER_TOP_LEVEL_KEYS, '$.coverage_ledger_v2')
    assert_secret_free!(document, '$.coverage_ledger_v2')
    unless document.dig('governance_profile_binding', 'status') == 'unavailable' &&
           document.dig('governance_profile_binding', 'reason_code') == 'pointer_missing'
      raise Error, '$.governance_profile_binding: must remain pointer_missing'
    end
    unless document.dig('gate_summary', 'g0', 'status') == 'OPEN' && document.dig('gate_summary', 'g3', 'status') == 'OPEN'
      raise Error, '$.gate_summary: G0 and G3 must remain OPEN'
    end
    capabilities = document.fetch('capabilities')
    raise Error, '$.capabilities: expected 268 rows' unless capabilities.length == 268
    raise Error, '$.capabilities: governance pointers must remain null' unless capabilities.all? { |row| row.fetch('governance_decision_pointer').nil? }
    raise Error, '$.capabilities: implementation authority drift' unless capabilities.all? { |row| row.dig('governance', 'implementation_authorized') == false }
    raise Error, '$.workflows: expected 16 rows' unless document.fetch('workflows').length == 16
    true
  end
  private_class_method :validate_ledger_common!

  def map_predecessor_reference
    { 'path' => MAP_PREDECESSOR_PATH, 'sha256' => MAP_PREDECESSOR_SHA256,
      'artifact_id' => MAP_PREDECESSOR_ARTIFACT_ID, 'relationship' => SUPERSESSION_RELATIONSHIP }
  end
  private_class_method :map_predecessor_reference

  def ledger_predecessor_reference
    { 'path' => LEDGER_PREDECESSOR_PATH, 'sha256' => LEDGER_PREDECESSOR_SHA256,
      'artifact_id' => LEDGER_PREDECESSOR_ARTIFACT_ID, 'relationship' => SUPERSESSION_RELATIONSHIP }
  end
  private_class_method :ledger_predecessor_reference

  def validate_reference!(entry)
    Old.send(:validate_source_reference!, entry, '$.explicit_evidence_inputs')
  end
  private_class_method :validate_reference!

  def repository_root
    File.expand_path('..', __dir__)
  end
  private_class_method :repository_root

  def secure_root(root)
    Old.send(:secure_root, root)
  end

  def safe_read(root, relative, label:)
    Old.send(:safe_read, root, relative, label: label)
  end

  def load_exact_json(root, relative, sha, label:)
    Old.send(:load_exact_json, root, relative, sha, label: label)
  end

  def publish_create_only!(root, output, bytes, mode:, &block)
    Old.send(:publish_create_only!, root, output, bytes, mode: mode, &block)
  end

  def receipt(status, root, output, bytes)
    Old.send(:receipt, status, root, output, bytes)
  end

  def assert_closed!(value, keys, label)
    Old.send(:assert_closed!, value, keys, label)
  end

  def assert_secret_free!(value, label)
    Old.send(:assert_secret_free!, value, label)
  end

  def assert_no_forbidden_map_keys!(value)
    Old.send(:assert_no_forbidden_map_keys!, value)
  end

  def deep_copy(value)
    Old.send(:deep_copy, value)
  end
  private_class_method :secure_root, :safe_read, :load_exact_json, :publish_create_only!, :receipt,
                       :assert_closed!, :assert_secret_free!, :assert_no_forbidden_map_keys!, :deep_copy
end

if $PROGRAM_NAME == __FILE__
  options = { check: false }
  parser = OptionParser.new do |opts|
    opts.banner = 'Usage: ruby scripts/generate-g0-g3-coverage-laboratory-successors.rb --kind map|ledger [--output PATH] [--check]'
    opts.on('--kind KIND', %w[map ledger]) { |value| options[:kind] = value }
    opts.on('--output PATH') { |value| options[:output] = value }
    opts.on('--check') { options[:check] = true }
  end
  begin
    parser.parse!(ARGV)
    raise G0G3CoverageLaboratorySuccessors::UsageError, 'unexpected positional arguments' unless ARGV.empty?
    raise G0G3CoverageLaboratorySuccessors::UsageError, '--kind is required' unless options[:kind]
    root = File.expand_path('..', __dir__)
    default = options[:kind] == 'map' ? G0G3CoverageLaboratorySuccessors::MAP_OUTPUT_PATH : G0G3CoverageLaboratorySuccessors::LEDGER_OUTPUT_PATH
    output = options[:output] || default
    result = if options[:check]
               options[:kind] == 'map' ? G0G3CoverageLaboratorySuccessors.check_map!(root: root, output: output) : G0G3CoverageLaboratorySuccessors.check_ledger!(root: root, output: output)
             elsif options[:kind] == 'map'
               G0G3CoverageLaboratorySuccessors.write_map!(root: root, output: output)
             else
               G0G3CoverageLaboratorySuccessors.write_ledger!(root: root, output: output)
             end
    puts G0ProportionalGovernanceV2.canonical_json(result.is_a?(Hash) ? result : { 'status' => 'ok' })
  rescue OptionParser::ParseError, G0G3CoverageLaboratorySuccessors::UsageError => e
    warn e.message
    warn parser.banner
    exit 2
  rescue G0G3CoverageLaboratorySuccessors::Error => e
    warn "coverage laboratory successor generation failed: #{e.message}"
    exit 1
  end
end
