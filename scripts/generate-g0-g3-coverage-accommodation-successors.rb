#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'json'
require 'optparse'

require_relative 'generate-g0-g3-coverage-radiology-successors'

# Append-only successor publisher for the bounded accommodation tariff/source
# evidence. R5/R7 are immutable inputs; this module can change engineering
# evidence only and deliberately has no selector, owner, gate or deployment API.
module G0G3CoverageAccommodationSuccessors
  Old = G0G3CoverageTariffSuccessors
  Core = G0ProportionalGovernanceV2
  Error = Old::Error
  UsageError = Old::UsageError

  SNAPSHOT_DATE = '2026-09-02'
  GENERATOR_PATH = 'scripts/generate-g0-g3-coverage-accommodation-successors.rb'
  SUPERSESSION_RELATIONSHIP = 'supersedes_without_rewriting_or_reinterpreting_predecessor'

  MAP_PREDECESSOR_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R5.json'
  MAP_PREDECESSOR_SHA256 = '07bef2b234f31336eb730dce4d035b830b91dc7995c2f979f2ba59be0558a921'
  MAP_PREDECESSOR_ARTIFACT_ID = 'COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-02-R5'
  MAP_OUTPUT_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R6.json'
  MAP_ARTIFACT_ID = 'COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-02-R6'

  LEDGER_PREDECESSOR_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R7.json'
  LEDGER_PREDECESSOR_SHA256 = '3163e1054d83c17b6938149e6a5af934bfc0bdc333226697fed8bde178dcad23'
  LEDGER_PREDECESSOR_ARTIFACT_ID = 'G0-G3-COVERAGE-LEDGER-V2-2026-09-02-R7'
  LEDGER_OUTPUT_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R8.json'
  LEDGER_ARTIFACT_ID = 'G0-G3-COVERAGE-LEDGER-V2-2026-09-02-R8'

  TARGET_CAPABILITY_IDS = %w[PAR-REG-001 PAR-ADM-009 PAR-ADM-033 PAR-FIN-001 PAR-FIN-002].freeze
  TARGET_WORKFLOW_IDS = %w[E2E-14 E2E-16].freeze

  # Immutable create-only final record and successful exact-engine records.
  FINAL_EVIDENCE_PATH = 'docs/operations/T1_LOCAL_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_EVIDENCE_2026-09-02.md'
  FINAL_EVIDENCE_SHA256 = 'ff2ea1bd851a9c3741c0a3c91b57d32f9088c41cc74ac083bc8ba4602cdc0b40'
  POSTGRES_ARTIFACT_PATH = 'storage/app/portability-rehearsals/20260902T085041Z-postgresql17-inpatient-accommodation-tariff-source-c142b60f25f8.json'
  POSTGRES_ARTIFACT_SHA256 = '34de88da216fa8ab40b6116039a94a85348ca8147102ecb1ae965eaf0ddfa824'
  MYSQL_ARTIFACT_PATH = 'storage/app/portability-rehearsals/20260902T085713Z-mysql8411-inpatient-accommodation-tariff-source-5b8728694230.json'
  MYSQL_ARTIFACT_SHA256 = 'cb425544f6231693efce9b9687ba98c1cf7a58dcb66078dfb8c914bc43ec117c'
  EXACT_APPLICATION_SOURCE_SHA256 = '3a2efebaf98d6911722eb995be7ef5cc0870bc188fa09f26e0b7d8e0fe02717f'

  BASE_EVIDENCE_PATHS = %w[
    app/Http/Controllers/Finance/FinanceAccommodationTariffController.php
    app/Models/FinanceBillVersion.php
    app/Models/FinanceChargeEvent.php
    app/Models/FinanceAccommodationSourceEvent.php
    app/Models/FinanceAccommodationTariffBinding.php
    app/Models/FinanceAccommodationTariffBindingVersion.php
    app/Models/FinanceAccommodationTariffOperationReceipt.php
    app/Models/InpatientLocationEvent.php
    app/Support/Finance/FinanceAccommodationOccupancyDay.php
    app/Support/Finance/FinanceAccommodationOccupancyInterval.php
    app/Support/Finance/FinanceAccommodationOccupancyPlan.php
    app/Support/Finance/FinanceAccommodationSourceAdapter.php
    app/Support/Finance/FinanceAccommodationTariffActorPolicy.php
    app/Support/Finance/FinanceAccommodationTariffAppendOnlyGuard.php
    app/Support/Finance/FinanceAccommodationTariffBindingService.php
    app/Support/Finance/FinanceAccommodationTariffContentDigest.php
    app/Support/Finance/FinanceAccommodationTariffMutableHeadGuard.php
    app/Support/Finance/FinanceAccommodationTariffMutationScope.php
    app/Support/Finance/FinanceAccommodationTariffMutationResult.php
    app/Support/Finance/FinanceAccommodationTariffOperation.php
    app/Support/Finance/FinanceAccommodationTariffProjection.php
    app/Support/Finance/FinanceAccommodationTariffResolution.php
    app/Support/Finance/FinanceAccommodationTariffSqlWriteGuard.php
    app/Support/Finance/FinanceAccommodationOccupancyDayAllocator.php
    app/Support/Finance/FinanceBillService.php
    app/Support/Finance/FinanceProjection.php
    app/Support/Finance/FinanceSourceCoordinator.php
    app/Support/Finance/FinanceSourceReadinessProjection.php
    app/Support/Operations/SyntheticRecoverySnapshot.php
    app/Support/Simulation/SyntheticResetService.php
    database/migrations/2026_09_02_000500_add_bed_version_provenance_to_inpatient_location_events.php
    database/migrations/2026_09_02_000600_create_inpatient_accommodation_tariff_source.php
    docs/new-simrs-rebuild/phase-1/INPATIENT_ACCOMMODATION_OCCUPANCY_DAY_TARIFF_SOURCE_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md
    scripts/rehearse-local-inpatient-accommodation-tariff-source-portability.rb
    tests/Documentation/InpatientAccommodationOccupancyDayTariffSourceV1LocalEngineeringAuthorizationTest.rb
    tests/Documentation/LocalInpatientAccommodationTariffSourceEvidenceRecordTest.rb
    tests/Documentation/LocalInpatientAccommodationTariffSourcePortabilityHarnessContractTest.rb
    tests/Feature/Authorization/FinanceTariffAccessTest.php
    tests/Feature/Database/FinanceTariffSqlWriteGuardTest.php
    tests/Feature/Finance/FinanceAccommodationOccupancyEvidenceTest.php
    tests/Feature/Finance/FinanceAccommodationSourceMaterializationTest.php
    tests/Feature/Finance/FinanceAccommodationTariffCoreTest.php
    tests/Feature/Finance/FinanceAccommodationTariffHttpWorkflowTest.php
    tests/Feature/Inpatient/InpatientLocationBedVersionProvenanceTest.php
    tests/Feature/Operations/FinanceAccommodationRecoverySnapshotTest.php
    tests/Feature/Simulation/FinanceTariffResetTest.php
    tests/Unit/Finance/FinanceAccommodationOccupancyDayAllocatorTest.php
    tests/Unit/Finance/FinanceAccommodationSourceAdapterTest.php
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
    receipt('generated_accommodation_engineering_evidence_map_successor', root_path, output, bytes)
  end

  def write_ledger!(root: repository_root, output: LEDGER_OUTPUT_PATH)
    root_path = secure_root(root)
    bytes = serialized_ledger(root: root_path)
    publish_create_only!(root_path, output, bytes, mode: 0o600) do |published|
      validate_ledger!(Core.parse_json(published, label: '$.published_ledger'), root: root_path)
    end
    receipt('generated_accommodation_coverage_ledger_successor', root_path, output, bytes)
  end

  def check_map!(root: repository_root, output: MAP_OUTPUT_PATH)
    root_path = secure_root(root)
    actual = safe_read(root_path, output, label: '$.engineering_evidence_map_successor')
    raise Error, 'stale accommodation engineering-evidence map successor' unless actual == serialized_map(root: root_path)
    true
  end

  def check_ledger!(root: repository_root, output: LEDGER_OUTPUT_PATH)
    root_path = secure_root(root)
    actual = safe_read(root_path, output, label: '$.coverage_ledger_successor')
    raise Error, 'stale accommodation coverage-ledger successor' unless actual == serialized_ledger(root: root_path)
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
      raise Error, '$.engineering_evidence_map_successor: differs from closed accommodation projection'
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
      raise Error, '$.coverage_ledger_successor: differs from closed observation-only accommodation projection'
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
    TARGET_WORKFLOW_IDS.each do |workflow_id|
      map_workflow = map.fetch('workflows').find { |row| row.fetch('workflow_id') == workflow_id }
      workflow = document.fetch('workflows').find { |row| row.fetch('workflow_id') == workflow_id }
      raise Error, "missing #{workflow_id}" unless workflow && map_workflow

      workflow.replace(deep_copy(map_workflow))
    end
    document
  end
  private_class_method :build_ledger

  def exact_evidence_paths!(root_path)
    prerequisites = publication_prerequisites
    missing = prerequisites.each_with_object([]) do |(label, binding), rows|
      rows << "#{label}.path" unless binding.fetch('path').is_a?(String) && !binding.fetch('path').empty?
      rows << "#{label}.sha256" unless binding.fetch('sha256').is_a?(String) && binding.fetch('sha256').match?(/\A[0-9a-f]{64}\z/)
    end
    unless missing.empty?
      raise Error, "accommodation successor publication prerequisites unresolved: #{missing.join(', ')}"
    end

    prerequisites.each do |label, binding|
      relative = binding.fetch('path')
      bytes = safe_read(root_path, relative, label: "$.publication_prerequisites[#{relative}]")
      actual = Digest::SHA256.hexdigest(bytes)
      unless actual == binding.fetch('sha256')
        raise Error, "accommodation successor exact evidence hash mismatch: #{relative}"
      end
      expected_mode = label == 'final_evidence' ? 0o644 : 0o600
      unless (root_path.join(relative).stat.mode & 0o777) == expected_mode
        raise Error, "accommodation successor exact evidence mode mismatch: #{relative}"
      end
      validate_exact_artifact!(bytes, label) unless label == 'final_evidence'
    end
    prerequisites.values.map { |binding| binding.fetch('path') }
  end
  private_class_method :exact_evidence_paths!

  def validate_exact_artifact!(bytes, label)
    artifact = Core.parse_json(bytes, label: "$.publication_prerequisites.#{label}")
    expected_engine = label == 'postgresql17_artifact' ? 'postgresql' : 'mysql'
    unless artifact.values_at('kind', 'status') ==
           ['SIMRS_LOCAL_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_PORTABILITY', 'PASS'] &&
           artifact.dig('engine', 'engine') == expected_engine &&
           artifact.dig('source_bindings', 'application_source_sha256') == EXACT_APPLICATION_SOURCE_SHA256 &&
           artifact.fetch('scenarios').length == 28 &&
           artifact.fetch('scenarios').values.all? { |row| row.fetch('status') == 'PASS' } &&
           artifact.dig('cleanup', 'strict_cleanup_verified') == true &&
           %w[owner_acceptance_claim deployment_claim g0_claim g3_claim].all? { |key| artifact.fetch(key) == false }
      raise Error, "accommodation successor exact artifact contract mismatch: #{label}"
    end
  rescue Core::Error, KeyError
    raise Error, "accommodation successor exact artifact contract mismatch: #{label}"
  end
  private_class_method :validate_exact_artifact!

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
    opts.banner = 'Usage: ruby scripts/generate-g0-g3-coverage-accommodation-successors.rb --kind map|ledger [--output PATH] [--check]'
    opts.on('--kind KIND', %w[map ledger]) { |value| options[:kind] = value }
    opts.on('--output PATH') { |value| options[:output] = value }
    opts.on('--check') { options[:check] = true }
  end
  begin
    parser.parse!(ARGV)
    raise G0G3CoverageAccommodationSuccessors::UsageError, 'unexpected positional arguments' unless ARGV.empty?
    raise G0G3CoverageAccommodationSuccessors::UsageError, '--kind is required' unless options[:kind]
    root = File.expand_path('..', __dir__)
    default = options[:kind] == 'map' ? G0G3CoverageAccommodationSuccessors::MAP_OUTPUT_PATH : G0G3CoverageAccommodationSuccessors::LEDGER_OUTPUT_PATH
    output = options[:output] || default
    result = if options[:check]
               options[:kind] == 'map' ? G0G3CoverageAccommodationSuccessors.check_map!(root: root, output: output) : G0G3CoverageAccommodationSuccessors.check_ledger!(root: root, output: output)
             elsif options[:kind] == 'map'
               G0G3CoverageAccommodationSuccessors.write_map!(root: root, output: output)
             else
               G0G3CoverageAccommodationSuccessors.write_ledger!(root: root, output: output)
             end
    puts G0ProportionalGovernanceV2.canonical_json(result.is_a?(Hash) ? result : { 'status' => 'ok' })
  rescue OptionParser::ParseError, G0G3CoverageAccommodationSuccessors::UsageError => e
    warn e.message
    warn parser.banner
    exit 2
  rescue G0G3CoverageAccommodationSuccessors::Error => e
    warn "coverage accommodation successor generation failed: #{e.message}"
    exit 1
  end
end
