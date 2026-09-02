#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'json'
require 'optparse'

require_relative 'generate-g0-g3-coverage-settlement-successors'

# Create-only cash-settlement-correction evidence successor. It may append
# evidence paths to PAR-FIN-001, PAR-FIN-002 and E2E-14 only and has no
# governance-selector or gate mutation path.
module G0G3CoverageCashCorrectionSuccessors
  Parent = G0G3CoverageSettlementSuccessors
  Old = G0G3CoverageTariffSuccessors
  Core = G0ProportionalGovernanceV2
  Error = Old::Error
  UsageError = Old::UsageError

  SNAPSHOT_DATE = '2026-09-02'
  GENERATOR_PATH = 'scripts/generate-g0-g3-coverage-cash-correction-successors.rb'
  SUPERSESSION_RELATIONSHIP = 'supersedes_without_rewriting_or_reinterpreting_predecessor'

  MAP_PREDECESSOR_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R7.json'
  MAP_PREDECESSOR_SHA256 = '45194af7aaabfda54069e4c22a420340f7b4940751d75485a56f34787e94c7fc'
  MAP_PREDECESSOR_ARTIFACT_ID = 'COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-02-R7'
  MAP_OUTPUT_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R8.json'
  MAP_ARTIFACT_ID = 'COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-02-R8'

  LEDGER_PREDECESSOR_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R9.json'
  LEDGER_PREDECESSOR_SHA256 = '290f60ab0ab060a6fd58920fd53313bade83b0c0584a5dd6de4a24a64d951654'
  LEDGER_PREDECESSOR_ARTIFACT_ID = 'G0-G3-COVERAGE-LEDGER-V2-2026-09-02-R9'
  LEDGER_OUTPUT_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R10.json'
  LEDGER_ARTIFACT_ID = 'G0-G3-COVERAGE-LEDGER-V2-2026-09-02-R10'

  TARGET_CAPABILITY_IDS = %w[PAR-FIN-001 PAR-FIN-002].freeze
  TARGET_WORKFLOW_IDS = %w[E2E-14].freeze

  FINAL_EVIDENCE_PATH = 'docs/operations/T1_LOCAL_APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_EVIDENCE_2026-09-02.md'
  FINAL_EVIDENCE_SHA256 = 'f5e6ce1c9e94e26388caafc09e60ca1378cdcceef0a45f479c71012238b2d965'
  POSTGRES_ARTIFACT_PATH = 'storage/app/portability-rehearsals/20260902T155803Z-postgresql17-cash-settlement-correction-add99d552334.json'
  POSTGRES_ARTIFACT_SHA256 = 'dca440d71242b5bdd27093ae56f221220545bad79ac4faf6c0cafe08e823d3d2'
  MYSQL_ARTIFACT_PATH = 'storage/app/portability-rehearsals/20260902T155936Z-mysql8411-cash-settlement-correction-996d6f8df6f9.json'
  MYSQL_ARTIFACT_SHA256 = '3e518413ea7ddf5820655b9ba0ed75b0751b0d82c0ae2bb64291d27765432fec'
  EXACT_APPLICATION_SOURCE_SHA256 = 'ad4489db9c5192601b810d3e631febc1d1a10225695ccda417e87dee69068419'

  BASE_EVIDENCE_PATHS = %w[
    app/Models/FinanceCashSettlement.php
    app/Models/FinanceSettlementCorrectionCase.php
    app/Models/FinanceSettlementCorrectionEvent.php
    app/Models/FinanceSettlementCorrectionOperationReceipt.php
    app/Support/Finance/FinanceAppendOnlyGuard.php
    app/Support/Finance/FinanceCashSettlementCorrectionActorPolicy.php
    app/Support/Finance/FinanceCashSettlementCorrectionFingerprint.php
    app/Support/Finance/FinanceCashSettlementCorrectionProjection.php
    app/Support/Finance/FinanceCashSettlementCorrectionService.php
    app/Support/Finance/FinanceCashSettlementFingerprint.php
    app/Support/Finance/FinanceCashSettlementNetPolicy.php
    app/Support/Finance/FinanceCashSettlementProjection.php
    app/Support/Finance/FinanceCashSettlementService.php
    app/Support/Finance/FinanceSqlWriteGuard.php
    database/migrations/2026_09_02_000700_create_exact_cash_settlement_and_receipt_tables.php
    database/migrations/2026_09_02_000800_create_append_only_cash_settlement_correction_tables.php
    docs/new-simrs-rebuild/phase-1/APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md
    docs/operations/T1_LOCAL_APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_EVIDENCE_TEMPLATE_2026-09-02.md
    scripts/rehearse-local-append-only-cash-settlement-correction-portability.rb
    scripts/rehearse-local-exact-cash-settlement-portability.rb
    tests/Documentation/AppendOnlyCashSettlementCorrectionV1LocalEngineeringAuthorizationTest.rb
    tests/Documentation/LocalAppendOnlyCashSettlementCorrectionPortabilityHarnessContractTest.rb
    tests/Feature/Authorization/CashierSupervisorAccessTest.php
    tests/Feature/Database/CashSettlementCorrectionMigrationTest.php
    tests/Feature/Finance/FinanceCashSettlementCoreTest.php
  ].sort.freeze

  module_function

  def publication_prerequisites
    {
      'final_evidence' => { 'path' => FINAL_EVIDENCE_PATH, 'sha256' => FINAL_EVIDENCE_SHA256 },
      'postgresql17_artifact' => { 'path' => POSTGRES_ARTIFACT_PATH, 'sha256' => POSTGRES_ARTIFACT_SHA256 },
      'mysql8411_artifact' => { 'path' => MYSQL_ARTIFACT_PATH, 'sha256' => MYSQL_ARTIFACT_SHA256 },
    }
  end

  def publication_ready?(root: repository_root)
    exact_evidence_paths!(secure_root(root))
    true
  rescue Error
    false
  end

  def map_document(root: repository_root)
    root_path = secure_root(root)
    predecessor = load_exact_json(root_path, MAP_PREDECESSOR_PATH, MAP_PREDECESSOR_SHA256,
                                  label: '$.superseded_evidence_map')
    validate_map_predecessor!(predecessor, root_path)
    document = build_map(root_path, predecessor)
    validate_map!(document, root: root_path, predecessor: predecessor)
    document
  end

  def ledger_document(root: repository_root)
    root_path = secure_root(root)
    predecessor = load_exact_json(root_path, LEDGER_PREDECESSOR_PATH, LEDGER_PREDECESSOR_SHA256,
                                  label: '$.sources.superseded_ledger')
    validate_ledger_predecessor!(predecessor, root_path)
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
    receipt('generated_cash_correction_engineering_evidence_map_successor', root_path, output, bytes)
  end

  def write_ledger!(root: repository_root, output: LEDGER_OUTPUT_PATH)
    root_path = secure_root(root)
    bytes = serialized_ledger(root: root_path)
    publish_create_only!(root_path, output, bytes, mode: 0o600) do |published|
      validate_ledger!(Core.parse_json(published, label: '$.published_ledger'), root: root_path)
    end
    receipt('generated_cash_correction_coverage_ledger_successor', root_path, output, bytes)
  end

  def check_map!(root: repository_root, output: MAP_OUTPUT_PATH)
    root_path = secure_root(root)
    actual = safe_read(root_path, output, label: '$.engineering_evidence_map_successor')
    raise Error, 'stale cash-correction engineering-evidence map successor' unless actual == serialized_map(root: root_path)
    true
  end

  def check_ledger!(root: repository_root, output: LEDGER_OUTPUT_PATH)
    root_path = secure_root(root)
    actual = safe_read(root_path, output, label: '$.coverage_ledger_successor')
    raise Error, 'stale cash-correction coverage-ledger successor' unless actual == serialized_ledger(root: root_path)
    true
  end

  def validate_map!(document, root: repository_root, predecessor: nil)
    root_path = secure_root(root)
    predecessor ||= load_exact_json(root_path, MAP_PREDECESSOR_PATH, MAP_PREDECESSOR_SHA256,
                                    label: '$.superseded_evidence_map')
    validate_map_predecessor!(predecessor, root_path)
    validate_map_shape!(document)
    expected = build_map(root_path, predecessor)
    unless Core.canonical_json(document) == Core.canonical_json(expected)
      raise Error, '$.engineering_evidence_map_successor: differs from closed cash-correction projection'
    end
    true
  rescue Core::Error, KeyError => e
    raise Error, e.message
  end

  def validate_ledger!(document, root: repository_root, predecessor: nil, map: nil, map_sha256: nil)
    root_path = secure_root(root)
    predecessor ||= load_exact_json(root_path, LEDGER_PREDECESSOR_PATH, LEDGER_PREDECESSOR_SHA256,
                                    label: '$.sources.superseded_ledger')
    validate_ledger_predecessor!(predecessor, root_path)
    unless map
      map_bytes = safe_read(root_path, MAP_OUTPUT_PATH, label: '$.sources.engineering_evidence_map_v2')
      map = Core.parse_json(map_bytes, label: MAP_OUTPUT_PATH)
      map_sha256 = Digest::SHA256.hexdigest(map_bytes)
    end
    validate_map!(map, root: root_path)
    validate_ledger_shape!(document)
    expected = build_ledger(predecessor, map, map_sha256)
    unless Core.canonical_json(document) == Core.canonical_json(expected)
      raise Error, '$.coverage_ledger_successor: differs from closed cash-correction projection'
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
    inputs = predecessor.fetch('explicit_evidence_inputs').to_h { |entry| [entry.fetch('path'), deep_copy(entry)] }
    evidence_paths = (BASE_EVIDENCE_PATHS + exact_evidence_paths!(root_path)).uniq.sort
    evidence_paths.each do |relative|
      bytes = safe_read(root_path, relative, label: "$.explicit_evidence_inputs[#{relative}]")
      inputs[relative] = { 'path' => relative, 'sha256' => Digest::SHA256.hexdigest(bytes) }
    end
    document['explicit_evidence_inputs'] = inputs.values.sort_by { |entry| entry.fetch('path') }
    document.fetch('capabilities').each do |row|
      next unless TARGET_CAPABILITY_IDS.include?(row.fetch('capability_id'))
      row.fetch('engineering_evidence')['evidence_paths'] =
        (row.dig('engineering_evidence', 'evidence_paths') + evidence_paths).uniq.sort
    end
    TARGET_WORKFLOW_IDS.each do |workflow_id|
      workflow = document.fetch('workflows').find { |row| row.fetch('workflow_id') == workflow_id }
      raise Error, "missing #{workflow_id}" unless workflow
      workflow['evidence_paths'] = (workflow.fetch('evidence_paths') + evidence_paths).uniq.sort
    end
    generator_bytes = safe_read(root_path, GENERATOR_PATH, label: '$.provenance.generator')
    document.fetch('provenance')['generator'] = {
      'path' => GENERATOR_PATH, 'sha256' => Digest::SHA256.hexdigest(generator_bytes)
    }
    document.fetch('provenance')['explicit_evidence_input_count'] = document.fetch('explicit_evidence_inputs').length
    document
  end
  private_class_method :build_map

  def build_ledger(predecessor, map, map_sha)
    document = deep_copy(predecessor)
    document['artifact_id'] = LEDGER_ARTIFACT_ID
    document['snapshot_date'] = SNAPSHOT_DATE
    document.fetch('sources')['superseded_ledger'] = ledger_predecessor_reference
    document.fetch('sources')['engineering_evidence_map_v2'] = { 'path' => MAP_OUTPUT_PATH, 'sha256' => map_sha }
    mapped_capabilities = map.fetch('capabilities').to_h { |row| [row.fetch('capability_id'), row] }
    document.fetch('capabilities').each do |row|
      next unless TARGET_CAPABILITY_IDS.include?(row.fetch('capability_id'))
      mapped = mapped_capabilities.fetch(row.fetch('capability_id'))
      row['engineering_evidence'] = deep_copy(mapped.fetch('engineering_evidence'))
      row['workflow_observation'] = deep_copy(mapped.fetch('workflow_observation'))
    end
    TARGET_WORKFLOW_IDS.each do |workflow_id|
      mapped = map.fetch('workflows').find { |row| row.fetch('workflow_id') == workflow_id }
      workflow = document.fetch('workflows').find { |row| row.fetch('workflow_id') == workflow_id }
      raise Error, "missing #{workflow_id}" unless mapped && workflow
      workflow.replace(deep_copy(mapped))
    end
    document
  end
  private_class_method :build_ledger

  def exact_evidence_paths!(root_path)
    publication_prerequisites.each do |label, binding|
      relative = binding.fetch('path')
      bytes = safe_read(root_path, relative, label: "$.publication_prerequisites[#{relative}]")
      unless Digest::SHA256.hexdigest(bytes) == binding.fetch('sha256')
        raise Error, "cash-correction successor exact evidence hash mismatch: #{relative}"
      end
      expected_mode = label == 'final_evidence' ? 0o644 : 0o600
      unless (root_path.join(relative).stat.mode & 0o777) == expected_mode
        raise Error, "cash-correction successor exact evidence mode mismatch: #{relative}"
      end
      validate_exact_artifact!(bytes, label) unless label == 'final_evidence'
    end
    publication_prerequisites.values.map { |binding| binding.fetch('path') }
  end
  private_class_method :exact_evidence_paths!

  def validate_exact_artifact!(bytes, label)
    artifact = Core.parse_json(bytes, label: "$.publication_prerequisites.#{label}")
    expected_engine = label == 'postgresql17_artifact' ? 'postgresql' : 'mysql'
    valid = artifact.values_at('kind', 'status') == ['SIMRS_LOCAL_APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_PORTABILITY', 'PASS'] &&
            artifact.dig('engine', 'engine') == expected_engine &&
            artifact.dig('source_bindings', 'application_source_sha256') == EXACT_APPLICATION_SOURCE_SHA256 &&
            artifact.fetch('scenarios').length == 22 &&
            artifact.fetch('scenarios').values.all? { |row| row.fetch('status') == 'PASS' } &&
            artifact.dig('sqlite_gate', 'status') == 'PASS' &&
            artifact.dig('cleanup', 'strict_cleanup_verified') == true &&
            %w[owner_acceptance_claim deployment_claim hosted_readiness_claim g0_claim g3_claim].all? do |key|
              artifact.fetch(key) == false
            end
    raise Error, "cash-correction successor exact artifact contract mismatch: #{label}" unless valid
  rescue Core::Error, KeyError
    raise Error, "cash-correction successor exact artifact contract mismatch: #{label}"
  end
  private_class_method :validate_exact_artifact!

  def validate_map_predecessor!(document, root)
    secure_root(root)
    identity = ['g0_g3_coverage_evidence_map_v2', 2, MAP_PREDECESSOR_ARTIFACT_ID, SNAPSHOT_DATE, 'synthetic_only']
    unless document.values_at('artifact_type', 'schema_version', 'artifact_id', 'snapshot_date', 'data_boundary') == identity
      raise Error, '$.superseded_evidence_map: identity or boundary drift'
    end
    assert_closed!(document, Old::MAP_TOP_LEVEL_KEYS, '$.superseded_evidence_map')
    assert_secret_free!(document, '$.superseded_evidence_map')
    assert_no_forbidden_map_keys!(document)
    raise Error, '$.superseded_evidence_map.capabilities: expected 268 rows' unless document.fetch('capabilities').length == 268
    raise Error, '$.superseded_evidence_map.workflows: expected 16 rows' unless document.fetch('workflows').length == 16
  end
  private_class_method :validate_map_predecessor!

  def validate_ledger_predecessor!(document, root)
    secure_root(root)
    identity = ['g0_g3_coverage_ledger_v2', 2, LEDGER_PREDECESSOR_ARTIFACT_ID, SNAPSHOT_DATE, 'synthetic_only']
    unless document.values_at('artifact_type', 'schema_version', 'artifact_id', 'snapshot_date', 'data_boundary') == identity
      raise Error, '$.sources.superseded_ledger: identity or boundary drift'
    end
    assert_closed!(document, Old::LEDGER_TOP_LEVEL_KEYS, '$.sources.superseded_ledger')
    assert_secret_free!(document, '$.sources.superseded_ledger')
    unless document.dig('gate_summary', 'g0', 'status') == 'OPEN' &&
           document.dig('gate_summary', 'g3', 'status') == 'OPEN'
      raise Error, '$.sources.superseded_ledger: G0/G3 drift'
    end
    unless document.dig('governance_profile_binding', 'reason_code') == 'pointer_missing'
      raise Error, '$.sources.superseded_ledger: pointer drift'
    end
    raise Error, '$.sources.superseded_ledger.capabilities: expected 268 rows' unless document.fetch('capabilities').length == 268
    raise Error, '$.sources.superseded_ledger.workflows: expected 16 rows' unless document.fetch('workflows').length == 16
  end
  private_class_method :validate_ledger_predecessor!

  def validate_map_shape!(document)
    identity = ['g0_g3_coverage_evidence_map_v2', 2, MAP_ARTIFACT_ID, SNAPSHOT_DATE, 'synthetic_only']
    raise Error, '$.engineering_evidence_map_v2: identity or boundary drift' unless document.values_at('artifact_type', 'schema_version', 'artifact_id', 'snapshot_date', 'data_boundary') == identity
    assert_closed!(document, Old::MAP_TOP_LEVEL_KEYS, '$.engineering_evidence_map_v2')
    assert_secret_free!(document, '$.engineering_evidence_map_v2')
    assert_no_forbidden_map_keys!(document)
    raise Error, '$.capabilities: expected 268 rows' unless document.fetch('capabilities').length == 268
    raise Error, '$.workflows: expected 16 rows' unless document.fetch('workflows').length == 16
    raise Error, '$.superseded_evidence_map: predecessor drift' unless document.fetch('superseded_evidence_map') == map_predecessor_reference
  end
  private_class_method :validate_map_shape!

  def validate_ledger_shape!(document)
    identity = ['g0_g3_coverage_ledger_v2', 2, LEDGER_ARTIFACT_ID, SNAPSHOT_DATE, 'synthetic_only']
    raise Error, '$.coverage_ledger_v2: identity or boundary drift' unless document.values_at('artifact_type', 'schema_version', 'artifact_id', 'snapshot_date', 'data_boundary') == identity
    assert_closed!(document, Old::LEDGER_TOP_LEVEL_KEYS, '$.coverage_ledger_v2')
    assert_secret_free!(document, '$.coverage_ledger_v2')
    raise Error, '$.governance_profile_binding: must remain pointer_missing' unless document.dig('governance_profile_binding', 'reason_code') == 'pointer_missing'
    unless document.dig('gate_summary', 'g0', 'status') == 'OPEN' && document.dig('gate_summary', 'g3', 'status') == 'OPEN'
      raise Error, '$.gate_summary: G0 and G3 must remain OPEN'
    end
    raise Error, '$.capabilities: expected 268 rows' unless document.fetch('capabilities').length == 268
    unless document.fetch('capabilities').all? do |row|
      row.fetch('governance_decision_pointer').nil? && row.dig('governance', 'implementation_authorized') == false
    end
      raise Error, '$.capabilities: governance drift'
    end
    raise Error, '$.workflows: expected 16 rows' unless document.fetch('workflows').length == 16
    raise Error, '$.sources.superseded_ledger: predecessor drift' unless document.dig('sources', 'superseded_ledger') == ledger_predecessor_reference
  end
  private_class_method :validate_ledger_shape!

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

  def repository_root
    File.expand_path('..', __dir__)
  end
  private_class_method :repository_root

  def secure_root(root)
    Parent.send(:secure_root, root)
  end

  def safe_read(root, relative, label:)
    Parent.send(:safe_read, root, relative, label: label)
  end

  def load_exact_json(root, relative, sha, label:)
    Parent.send(:load_exact_json, root, relative, sha, label: label)
  end

  def publish_create_only!(root, output, bytes, mode:, &block)
    Parent.send(:publish_create_only!, root, output, bytes, mode: mode, &block)
  end

  def receipt(status, root, output, bytes)
    Parent.send(:receipt, status, root, output, bytes)
  end

  def assert_closed!(value, keys, label)
    Parent.send(:assert_closed!, value, keys, label)
  end

  def assert_secret_free!(value, label)
    Parent.send(:assert_secret_free!, value, label)
  end

  def assert_no_forbidden_map_keys!(value)
    Parent.send(:assert_no_forbidden_map_keys!, value)
  end

  def deep_copy(value)
    Parent.send(:deep_copy, value)
  end
  private_class_method :secure_root, :safe_read, :load_exact_json, :publish_create_only!, :receipt,
                       :assert_closed!, :assert_secret_free!, :assert_no_forbidden_map_keys!, :deep_copy
end

if $PROGRAM_NAME == __FILE__
  options = { check: false }
  parser = OptionParser.new do |opts|
    opts.banner = 'Usage: ruby scripts/generate-g0-g3-coverage-cash-correction-successors.rb --kind map|ledger [--output PATH] [--check]'
    opts.on('--kind KIND', %w[map ledger]) { |value| options[:kind] = value }
    opts.on('--output PATH') { |value| options[:output] = value }
    opts.on('--check') { options[:check] = true }
  end
  begin
    parser.parse!(ARGV)
    raise G0G3CoverageCashCorrectionSuccessors::UsageError, 'unexpected positional arguments' unless ARGV.empty?
    raise G0G3CoverageCashCorrectionSuccessors::UsageError, '--kind is required' unless options[:kind]
    root = File.expand_path('..', __dir__)
    default = options[:kind] == 'map' ? G0G3CoverageCashCorrectionSuccessors::MAP_OUTPUT_PATH : G0G3CoverageCashCorrectionSuccessors::LEDGER_OUTPUT_PATH
    output = options[:output] || default
    result = if options[:check]
               options[:kind] == 'map' ? G0G3CoverageCashCorrectionSuccessors.check_map!(root: root, output: output) : G0G3CoverageCashCorrectionSuccessors.check_ledger!(root: root, output: output)
             elsif options[:kind] == 'map'
               G0G3CoverageCashCorrectionSuccessors.write_map!(root: root, output: output)
             else
               G0G3CoverageCashCorrectionSuccessors.write_ledger!(root: root, output: output)
             end
    puts G0ProportionalGovernanceV2.canonical_json(result.is_a?(Hash) ? result : { 'status' => 'ok' })
  rescue OptionParser::ParseError, G0G3CoverageCashCorrectionSuccessors::UsageError => e
    warn e.message
    warn parser.banner
    exit 2
  rescue G0G3CoverageCashCorrectionSuccessors::Error => e
    warn "coverage cash-correction successor generation failed: #{e.message}"
    exit 1
  end
end
