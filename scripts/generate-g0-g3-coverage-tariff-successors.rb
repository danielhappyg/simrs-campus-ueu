#!/usr/bin/env ruby
# frozen_string_literal: true

require 'date'
require 'digest'
require 'json'
require 'optparse'
require 'pathname'
require 'securerandom'

require_relative 'g0-proportional-governance-v2'

# Publishes the append-only engineering-evidence and observation-ledger
# successors for the governed finance tariff/component master. This publisher
# deliberately copies governance from the immutable R4 observation; it has no
# selector, owner, approval, deployment, or gate mutation capability.
module G0G3CoverageTariffSuccessors
  class Error < StandardError; end
  class UsageError < Error; end

  Core = G0ProportionalGovernanceV2

  SNAPSHOT_DATE = '2026-09-02'
  GENERATOR_PATH = 'scripts/generate-g0-g3-coverage-tariff-successors.rb'
  SUPERSESSION_RELATIONSHIP = 'supersedes_without_rewriting_or_reinterpreting_predecessor'

  MAP_PREDECESSOR_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-08-29_R2.json'
  MAP_PREDECESSOR_SHA256 = '581c0d5eccdd42b818270b3d214e600e33ac12bf6d1895143e644be43783f27f'
  MAP_PREDECESSOR_ARTIFACT_ID = 'COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-08-29-R2'
  MAP_OUTPUT_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R3.json'
  MAP_ARTIFACT_ID = 'COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-02-R3'

  LEDGER_PREDECESSOR_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R4.json'
  LEDGER_PREDECESSOR_SHA256 = '473cba5aec79a09621dda979ebbf0b4ec4c456aaede5bac159291c58062cd8a4'
  LEDGER_PREDECESSOR_ARTIFACT_ID = 'G0-G3-COVERAGE-LEDGER-V2-2026-08-29-R4'
  LEDGER_OUTPUT_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R5.json'
  LEDGER_ARTIFACT_ID = 'G0-G3-COVERAGE-LEDGER-V2-2026-09-02-R5'

  TARGET_CAPABILITY_IDS = %w[PAR-ADM-011 PAR-ADM-018 PAR-ADM-019].freeze
  TARGET_WORKFLOW_ID = 'E2E-16'

  NEW_EVIDENCE_PATHS = %w[
    docs/new-simrs-rebuild/phase-1/GOVERNED_EFFECTIVE_DATED_FINANCE_TARIFF_COMPONENT_MASTER_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md
    docs/operations/T1_LOCAL_GOVERNED_FINANCE_TARIFF_COMPONENT_MASTER_EVIDENCE_2026-09-02.md
    scripts/rehearse-local-finance-tariff-component-master-portability.rb
    tests/Documentation/LocalFinanceTariffComponentMasterPortabilityHarnessContractTest.rb
    tests/Feature/Authorization/FinanceTariffAccessTest.php
    tests/Feature/Database/FinanceTariffSqlWriteGuardTest.php
    tests/Feature/Finance/FinanceTariffMasterCoreTest.php
    tests/Feature/Finance/FinanceTariffMasterHttpTest.php
    tests/Feature/Operations/FinanceTariffRecoverySnapshotTest.php
    tests/Feature/Simulation/FinanceTariffResetTest.php
    tests/Unit/Audit/FinanceTariffAuditIntegrationTest.php
  ].sort.freeze

  MAP_TOP_LEVEL_KEYS = %w[
    artifact_type schema_version artifact_id snapshot_date data_boundary
    superseded_evidence_map source_evidence_map canonical_order_source
    explicit_evidence_inputs capability_defaults workflow_observation_default
    capabilities workflows provenance
  ].freeze
  SOURCE_REFERENCE_KEYS = %w[path sha256].freeze
  SUPERSESSION_KEYS = %w[path sha256 artifact_id relationship].freeze
  ENGINEERING_KEYS = %w[
    runtime_availability automated_evidence database_engine_evidence hosted_uat
    reconciliation defect_status evidence_paths
  ].freeze
  DATABASE_KEYS = %w[sqlite postgresql_17 mysql_8_4 mysql_other].freeze
  WORKFLOW_OBSERVATION_KEYS = %w[status scenario_ids].freeze
  MAP_CAPABILITY_KEYS = %w[capability_id engineering_evidence workflow_observation].freeze
  MAP_WORKFLOW_KEYS = (%w[workflow_id] + ENGINEERING_KEYS).freeze
  MAP_PROVENANCE_KEYS = %w[
    generator source_byte_hash canonical_json capability_count
    historical_engineering_override_count workflow_count explicit_evidence_input_count
  ].freeze

  LEDGER_TOP_LEVEL_KEYS = %w[
    artifact_type schema_version artifact_id snapshot_date data_boundary
    authority_boundary governance_profile_binding sources capability_summary
    workflow_summary gate_summary capabilities workflows
  ].freeze
  LEDGER_SOURCE_KEYS = %w[
    governance_contract historical_hash_manifest canonical_capability_order
    engineering_evidence_map_v2 engineering_evidence_map_source
    source_register_count superseded_ledger
  ].freeze

  RUNTIME_VALUES = %w[NOT_IMPLEMENTED PARTIAL IMPLEMENTED].freeze
  AUTOMATED_VALUES = %w[NO_COVERAGE TESTS_PRESENT_NOT_CURRENTLY_RUN PARTIAL_PASS COMPLETE_PASS].freeze
  DATABASE_VALUES = %w[NOT_RUN PASS COMPATIBILITY_ONLY FAIL].freeze
  HOSTED_VALUES = %w[NOT_RUN HISTORICAL_PARTIAL PARTIAL_PASS PASS FAIL].freeze
  RECONCILIATION_VALUES = %w[NOT_STARTED PARTIAL COMPLETE FAILED].freeze
  DEFECT_VALUES = %w[PENDING NONE_RECORDED OPEN_P0 OPEN_P1 ACCEPTED_P1].freeze

  FORBIDDEN_MAP_KEY_ALIASES = %w[
    governance owner coowner productowner domainowner owneridentity identity appointment
    approve approval approver signoff decision decisionevent verdict disposition
    consequence consequenceflag tier riskclass risklevel pointer selector selection
    consumer consumerbinding bundle gate g0 g3 authority authorization accepted
    acceptance reviewer decider quorum vote signature activation deployment release
  ].freeze

  module_function

  def map_document(root: repository_root)
    root_path = secure_root(root)
    predecessor = load_exact_json(
      root_path,
      MAP_PREDECESSOR_PATH,
      MAP_PREDECESSOR_SHA256,
      label: '$.superseded_evidence_map'
    )
    validate_map_predecessor!(predecessor)
    document = build_map(root_path, predecessor)
    validate_map!(document, root: root_path, predecessor: predecessor)
    document
  rescue Core::Error, KeyError => e
    raise Error, safe_error(e)
  end

  def ledger_document(root: repository_root)
    root_path = secure_root(root)
    predecessor = load_exact_json(
      root_path,
      LEDGER_PREDECESSOR_PATH,
      LEDGER_PREDECESSOR_SHA256,
      label: '$.sources.superseded_ledger'
    )
    validate_ledger_predecessor!(predecessor)
    map_bytes = safe_read(root_path, MAP_OUTPUT_PATH, label: '$.sources.engineering_evidence_map_v2')
    map = Core.parse_json(map_bytes, label: MAP_OUTPUT_PATH)
    validate_map!(map, root: root_path)
    document = build_ledger(predecessor, map, Digest::SHA256.hexdigest(map_bytes))
    validate_ledger!(document, root: root_path, predecessor: predecessor, map: map,
                     map_sha256: Digest::SHA256.hexdigest(map_bytes))
    document
  rescue Core::Error, KeyError => e
    raise Error, safe_error(e)
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
    receipt('generated_engineering_evidence_map_successor', root_path, output, bytes)
  end

  def write_ledger!(root: repository_root, output: LEDGER_OUTPUT_PATH)
    root_path = secure_root(root)
    bytes = serialized_ledger(root: root_path)
    publish_create_only!(root_path, output, bytes, mode: 0o600) do |published|
      validate_ledger!(Core.parse_json(published, label: '$.published_ledger'), root: root_path)
    end
    receipt('generated_coverage_ledger_successor', root_path, output, bytes)
  end

  def check_map!(root: repository_root, output: MAP_OUTPUT_PATH)
    root_path = secure_root(root)
    actual = safe_read(root_path, output, label: '$.engineering_evidence_map_successor')
    raise Error, 'stale engineering evidence map successor' unless actual == serialized_map(root: root_path)

    true
  end

  def check_ledger!(root: repository_root, output: LEDGER_OUTPUT_PATH)
    root_path = secure_root(root)
    actual = safe_read(root_path, output, label: '$.coverage_ledger_successor')
    raise Error, 'stale coverage ledger successor' unless actual == serialized_ledger(root: root_path)

    true
  end

  def validate_map!(document, root: repository_root, predecessor: nil)
    root_path = secure_root(root)
    predecessor ||= load_exact_json(
      root_path,
      MAP_PREDECESSOR_PATH,
      MAP_PREDECESSOR_SHA256,
      label: '$.superseded_evidence_map'
    )
    validate_map_predecessor!(predecessor)
    validate_map_shape!(document)
    expected = build_map(root_path, predecessor)
    unless Core.canonical_json(document) == Core.canonical_json(expected)
      raise Error, '$.engineering_evidence_map_successor: document differs from closed tariff successor projection'
    end

    true
  rescue Core::Error, KeyError => e
    raise Error, safe_error(e)
  end

  def validate_ledger!(document, root: repository_root, predecessor: nil, map: nil, map_sha256: nil)
    root_path = secure_root(root)
    predecessor ||= load_exact_json(
      root_path,
      LEDGER_PREDECESSOR_PATH,
      LEDGER_PREDECESSOR_SHA256,
      label: '$.sources.superseded_ledger'
    )
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
      raise Error, '$.coverage_ledger_successor: document differs from closed observation-only successor projection'
    end

    true
  rescue Core::Error, KeyError => e
    raise Error, safe_error(e)
  end

  def build_map(root_path, predecessor)
    document = deep_copy(predecessor)
    document['artifact_id'] = MAP_ARTIFACT_ID
    document['snapshot_date'] = SNAPSHOT_DATE
    document['superseded_evidence_map'] = map_predecessor_reference

    carried_inputs = predecessor.fetch('explicit_evidence_inputs').map { |entry| deep_copy(entry) }
    carried_paths = carried_inputs.map { |entry| entry.fetch('path') }
    overlap = NEW_EVIDENCE_PATHS & carried_paths
    raise Error, "new evidence duplicates predecessor input: #{overlap.join(', ')}" unless overlap.empty?

    additions = NEW_EVIDENCE_PATHS.map do |relative|
      validate_evidence_date!(relative)
      bytes = safe_read(root_path, relative, label: "$.explicit_evidence_inputs[#{relative}]")
      { 'path' => relative, 'sha256' => Digest::SHA256.hexdigest(bytes) }
    end
    document['explicit_evidence_inputs'] = (carried_inputs + additions).sort_by { |entry| entry.fetch('path') }

    evidence = tariff_engineering_evidence
    document.fetch('capabilities').each do |row|
      next unless TARGET_CAPABILITY_IDS.include?(row.fetch('capability_id'))

      row['engineering_evidence'] = deep_copy(evidence)
      row['workflow_observation'] = {
        'status' => 'PROVISIONAL',
        'scenario_ids' => [TARGET_WORKFLOW_ID]
      }
    end

    workflow = document.fetch('workflows').find { |row| row.fetch('workflow_id') == TARGET_WORKFLOW_ID }
    raise Error, "missing #{TARGET_WORKFLOW_ID}" unless workflow

    workflow['reconciliation'] = 'PARTIAL'
    workflow['evidence_paths'] = (workflow.fetch('evidence_paths') + NEW_EVIDENCE_PATHS).uniq.sort

    generator_bytes = safe_read(root_path, GENERATOR_PATH, label: '$.provenance.generator')
    document.fetch('provenance')['generator'] = {
      'path' => GENERATOR_PATH,
      'sha256' => Digest::SHA256.hexdigest(generator_bytes)
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
      'path' => MAP_OUTPUT_PATH,
      'sha256' => map_sha256
    }

    map_capabilities = map.fetch('capabilities').to_h { |row| [row.fetch('capability_id'), row] }
    document.fetch('capabilities').each do |row|
      next unless TARGET_CAPABILITY_IDS.include?(row.fetch('capability_id'))

      map_row = map_capabilities.fetch(row.fetch('capability_id'))
      row['engineering_evidence'] = deep_copy(map_row.fetch('engineering_evidence'))
      row['workflow_observation'] = deep_copy(map_row.fetch('workflow_observation'))
    end
    workflow = document.fetch('workflows').find { |row| row.fetch('workflow_id') == TARGET_WORKFLOW_ID }
    map_workflow = map.fetch('workflows').find { |row| row.fetch('workflow_id') == TARGET_WORKFLOW_ID }
    raise Error, "missing #{TARGET_WORKFLOW_ID}" unless workflow && map_workflow

    workflow.replace(deep_copy(map_workflow))
    document
  end
  private_class_method :build_ledger

  def tariff_engineering_evidence
    {
      'runtime_availability' => 'IMPLEMENTED',
      'automated_evidence' => 'COMPLETE_PASS',
      'database_engine_evidence' => {
        'sqlite' => 'PASS',
        'postgresql_17' => 'PASS',
        'mysql_8_4' => 'PASS',
        'mysql_other' => 'NOT_RUN'
      },
      'hosted_uat' => 'NOT_RUN',
      'reconciliation' => 'PARTIAL',
      'defect_status' => 'PENDING',
      'evidence_paths' => NEW_EVIDENCE_PATHS
    }
  end
  private_class_method :tariff_engineering_evidence

  def validate_map_predecessor!(document)
    validate_map_shape!(document, predecessor: true)
    unless document.values_at('artifact_type', 'schema_version', 'artifact_id', 'snapshot_date', 'data_boundary') == [
      'g0_g3_coverage_evidence_map_v2', 2, MAP_PREDECESSOR_ARTIFACT_ID, '2026-08-29', 'synthetic_only'
    ]
      raise Error, '$.superseded_evidence_map: identity or boundary drift'
    end
    true
  end
  private_class_method :validate_map_predecessor!

  def validate_map_shape!(document, predecessor: false)
    assert_closed!(document, MAP_TOP_LEVEL_KEYS, '$.engineering_evidence_map_v2')
    expected_id = predecessor ? MAP_PREDECESSOR_ARTIFACT_ID : MAP_ARTIFACT_ID
    expected_date = predecessor ? '2026-08-29' : SNAPSHOT_DATE
    unless document.values_at('artifact_type', 'schema_version', 'artifact_id', 'snapshot_date', 'data_boundary') == [
      'g0_g3_coverage_evidence_map_v2', 2, expected_id, expected_date, 'synthetic_only'
    ]
      raise Error, '$.engineering_evidence_map_v2: identity or boundary drift'
    end
    assert_secret_free!(document, '$.engineering_evidence_map_v2')
    assert_no_forbidden_map_keys!(document)
    validate_supersession!(document.fetch('superseded_evidence_map'), predecessor: predecessor)
    validate_source_reference!(document.fetch('source_evidence_map'), '$.source_evidence_map')
    validate_source_reference!(document.fetch('canonical_order_source'), '$.canonical_order_source')

    inputs = document.fetch('explicit_evidence_inputs')
    raise Error, '$.explicit_evidence_inputs: expected array' unless inputs.is_a?(Array)
    inputs.each_with_index { |entry, index| validate_source_reference!(entry, "$.explicit_evidence_inputs[#{index}]") }
    paths = inputs.map { |entry| entry.fetch('path') }
    raise Error, '$.explicit_evidence_inputs: must be sorted and unique' unless paths == paths.sort && paths.uniq == paths

    validate_engineering!(document.fetch('capability_defaults'), '$.capability_defaults')
    validate_workflow_observation!(document.fetch('workflow_observation_default'), '$.workflow_observation_default')
    capabilities = document.fetch('capabilities')
    raise Error, '$.capabilities: expected 268 rows' unless capabilities.is_a?(Array) && capabilities.length == 268
    ids = capabilities.map.with_index do |row, index|
      label = "$.capabilities[#{index}]"
      assert_closed!(row, MAP_CAPABILITY_KEYS, label)
      validate_engineering!(row.fetch('engineering_evidence'), "#{label}.engineering_evidence")
      validate_workflow_observation!(row.fetch('workflow_observation'), "#{label}.workflow_observation")
      row.fetch('capability_id')
    end
    unless ids.uniq == ids && ids.all? { |id| id.is_a?(String) && id.match?(/\APAR-[A-Z0-9]+-\d{3}\z/) }
      raise Error, '$.capabilities: invalid or duplicate IDs'
    end

    workflows = document.fetch('workflows')
    raise Error, '$.workflows: expected 16 rows' unless workflows.is_a?(Array) && workflows.length == 16
    workflow_ids = workflows.map.with_index do |row, index|
      label = "$.workflows[#{index}]"
      assert_closed!(row, MAP_WORKFLOW_KEYS, label)
      validate_engineering!(row.reject { |key, _value| key == 'workflow_id' }, label)
      row.fetch('workflow_id')
    end
    unless workflow_ids == (1..16).map { |number| format('E2E-%02d', number) }
      raise Error, '$.workflows: IDs missing, duplicate, or out of order'
    end

    provenance = document.fetch('provenance')
    assert_closed!(provenance, MAP_PROVENANCE_KEYS, '$.provenance')
    validate_source_reference!(provenance.fetch('generator'), '$.provenance.generator')
    unless provenance.fetch('source_byte_hash') == 'sha256_raw_bytes' &&
           provenance.fetch('canonical_json') == 'utf8_sorted_object_keys_compact_single_lf'
      raise Error, '$.provenance: unsupported byte contract'
    end
    %w[capability_count historical_engineering_override_count workflow_count explicit_evidence_input_count].each do |key|
      value = provenance.fetch(key)
      raise Error, "$.provenance.#{key}: expected non-negative integer" unless value.is_a?(Integer) && value >= 0
    end
    true
  end
  private_class_method :validate_map_shape!

  def validate_ledger_predecessor!(document)
    validate_ledger_shape!(document, predecessor: true)
    true
  end
  private_class_method :validate_ledger_predecessor!

  def validate_ledger_shape!(document, predecessor: false)
    assert_closed!(document, LEDGER_TOP_LEVEL_KEYS, '$.coverage_ledger_v2')
    expected_id = predecessor ? LEDGER_PREDECESSOR_ARTIFACT_ID : LEDGER_ARTIFACT_ID
    expected_date = predecessor ? '2026-08-29' : SNAPSHOT_DATE
    unless document.values_at('artifact_type', 'schema_version', 'artifact_id', 'snapshot_date', 'data_boundary') == [
      'g0_g3_coverage_ledger_v2', 2, expected_id, expected_date, 'synthetic_only'
    ]
      raise Error, '$.coverage_ledger_v2: identity or boundary drift'
    end
    assert_secret_free!(document, '$.coverage_ledger_v2')
    assert_closed!(document.fetch('sources'), LEDGER_SOURCE_KEYS, '$.sources')
    document.fetch('sources').each do |key, value|
      next if key == 'source_register_count'
      next validate_supersession!(value, ledger: true, predecessor: predecessor) if key == 'superseded_ledger'

      validate_source_reference!(value, "$.sources.#{key}")
    end
    unless document.dig('governance_profile_binding', 'status') == 'unavailable' &&
           document.dig('governance_profile_binding', 'reason_code') == 'pointer_missing'
      raise Error, '$.governance_profile_binding: successor must remain pointer_missing and unavailable'
    end
    unless document.dig('gate_summary', 'g0', 'status') == 'OPEN' &&
           document.dig('gate_summary', 'g3', 'status') == 'OPEN'
      raise Error, '$.gate_summary: successor must remain OPEN'
    end
    capabilities = document.fetch('capabilities')
    raise Error, '$.capabilities: expected 268 rows' unless capabilities.is_a?(Array) && capabilities.length == 268
    unless capabilities.all? { |row| row.fetch('governance_decision_pointer').nil? }
      raise Error, '$.capabilities: governance decision pointers must remain null'
    end
    unless capabilities.all? { |row| row.dig('governance', 'implementation_authorized') == false }
      raise Error, '$.capabilities: implementation authorization must remain false'
    end
    workflows = document.fetch('workflows')
    raise Error, '$.workflows: expected 16 rows' unless workflows.is_a?(Array) && workflows.length == 16
    true
  end
  private_class_method :validate_ledger_shape!

  def validate_engineering!(entry, label)
    assert_closed!(entry, ENGINEERING_KEYS, label)
    validate_enum!(entry.fetch('runtime_availability'), RUNTIME_VALUES, "#{label}.runtime_availability")
    validate_enum!(entry.fetch('automated_evidence'), AUTOMATED_VALUES, "#{label}.automated_evidence")
    validate_enum!(entry.fetch('hosted_uat'), HOSTED_VALUES, "#{label}.hosted_uat")
    validate_enum!(entry.fetch('reconciliation'), RECONCILIATION_VALUES, "#{label}.reconciliation")
    validate_enum!(entry.fetch('defect_status'), DEFECT_VALUES, "#{label}.defect_status")
    database = entry.fetch('database_engine_evidence')
    assert_closed!(database, DATABASE_KEYS, "#{label}.database_engine_evidence")
    database.each_value { |value| validate_enum!(value, DATABASE_VALUES, "#{label}.database_engine_evidence") }
    validate_string_array!(entry.fetch('evidence_paths'), "#{label}.evidence_paths", safe_paths: true)
    true
  end
  private_class_method :validate_engineering!

  def validate_workflow_observation!(entry, label)
    assert_closed!(entry, WORKFLOW_OBSERVATION_KEYS, label)
    validate_enum!(entry.fetch('status'), %w[PENDING PROVISIONAL], "#{label}.status")
    validate_string_array!(entry.fetch('scenario_ids'), "#{label}.scenario_ids", pattern: /\AE2E-\d{2}\z/)
    true
  end
  private_class_method :validate_workflow_observation!

  def validate_supersession!(reference, ledger: false, predecessor: false)
    assert_closed!(reference, SUPERSESSION_KEYS, '$.supersession')
    return true if predecessor

    expected = ledger ? ledger_predecessor_reference : map_predecessor_reference
    raise Error, '$.supersession: predecessor binding drift' unless reference == expected
    true
  end
  private_class_method :validate_supersession!

  def validate_source_reference!(reference, label)
    assert_closed!(reference, SOURCE_REFERENCE_KEYS, label)
    raise Error, "#{label}.path: unsafe path" unless safe_relative_path?(reference.fetch('path'))
    unless reference.fetch('sha256').is_a?(String) && Core::SHA256_PATTERN.match?(reference.fetch('sha256'))
      raise Error, "#{label}.sha256: invalid SHA-256"
    end
    true
  end
  private_class_method :validate_source_reference!

  def map_predecessor_reference
    {
      'path' => MAP_PREDECESSOR_PATH,
      'sha256' => MAP_PREDECESSOR_SHA256,
      'artifact_id' => MAP_PREDECESSOR_ARTIFACT_ID,
      'relationship' => SUPERSESSION_RELATIONSHIP
    }
  end
  private_class_method :map_predecessor_reference

  def ledger_predecessor_reference
    {
      'path' => LEDGER_PREDECESSOR_PATH,
      'sha256' => LEDGER_PREDECESSOR_SHA256,
      'artifact_id' => LEDGER_PREDECESSOR_ARTIFACT_ID,
      'relationship' => SUPERSESSION_RELATIONSHIP
    }
  end
  private_class_method :ledger_predecessor_reference

  def load_exact_json(root_path, relative, expected_sha256, label:)
    bytes = safe_read(root_path, relative, label: label)
    actual = Digest::SHA256.hexdigest(bytes)
    raise Error, "#{label}.sha256: predecessor byte hash drift" unless actual == expected_sha256

    Core.parse_json(bytes, label: relative)
  end
  private_class_method :load_exact_json

  def publish_create_only!(root_path, output, bytes, mode:)
    target = new_output_path(root_path, output)
    parent = target.parent
    stage = parent.join(".#{target.basename}.stage-#{Process.pid}-#{SecureRandom.hex(8)}")
    created_identity = nil
    begin
      File.open(stage, File::WRONLY | File::CREAT | File::EXCL, mode) do |file|
        file.binmode
        file.write(bytes)
        file.flush
        file.fsync
      end
      staged = safe_read(root_path, repository_relative(root_path, stage), label: '$.staged_successor')
      raise Error, 'staged output byte mismatch' unless staged == bytes
      yield staged

      File.link(stage, target)
      created_identity = identity(target.lstat)
      File.unlink(stage)
      fsync_directory(parent)
      published = safe_read(root_path, repository_relative(root_path, target), label: '$.published_successor')
      current_identity = identity(target.lstat)
      unless published == bytes && current_identity == created_identity
        raise Error, 'published output residual conflict; manual recovery required'
      end
      yield published
      true
    rescue Errno::EEXIST
      raise UsageError, 'output already exists; refusing overwrite'
    rescue Error, Core::Error
      raise
    rescue SystemCallError, IOError => e
      raise Error, "create-only publication failed (#{e.class})"
    ensure
      File.unlink(stage) if stage&.exist?
      if $! && created_identity && target.exist?
        begin
          File.unlink(target) if identity(target.lstat) == created_identity
        rescue SystemCallError
          nil
        end
      end
    end
  end
  private_class_method :publish_create_only!

  def safe_read(root_path, relative, label:)
    raise Error, "#{label}.path: unsafe repository-relative path" unless safe_relative_path?(relative)
    path = root_path.join(relative)
    current = root_path
    Pathname.new(relative).each_filename.with_index do |component, index|
      current = current.join(component)
      stat = current.lstat
      raise Error, "#{label}: symlinked path component" if stat.symlink?
      final = index == Pathname.new(relative).each_filename.to_a.length - 1
      raise Error, "#{label}: unexpected path type" unless final ? stat.file? : stat.directory?
    rescue SystemCallError
      raise Error, "#{label}: source path unavailable"
    end
    raise Error, "#{label}: source resolves outside repository root" unless path.realpath == path

    flags = File::RDONLY
    flags |= File::NOFOLLOW if File.const_defined?(:NOFOLLOW)
    bytes = File.open(path, flags) do |file|
      before = file.stat
      validate_source_stat!(before, label)
      content = file.read
      after = file.stat
      raise Error, "#{label}: source changed while reading" unless stable_stat(before) == stable_stat(after)
      current_stat = path.lstat
      unless !current_stat.symlink? && stable_stat(current_stat) == stable_stat(after) && path.realpath == path
        raise Error, "#{label}: source identity changed while reading"
      end
      content
    end
    bytes.b
  rescue Error
    raise
  rescue SystemCallError, IOError
    raise Error, "#{label}: source read failed"
  end
  private_class_method :safe_read

  def validate_source_stat!(stat, label)
    unless stat.file? && stat.uid == Process.uid && stat.nlink == 1 && (stat.mode & 0o022).zero?
      raise Error, "#{label}: unsafe regular file"
    end
    true
  end
  private_class_method :validate_source_stat!

  def secure_root(root)
    path = Pathname.new(root.to_s).expand_path
    raise UsageError, 'repository root must be an existing directory' unless path.directory?
    raise UsageError, 'repository root must not be symlinked' unless path.realpath == path

    path.realpath
  rescue SystemCallError
    raise UsageError, 'repository root unavailable'
  end
  private_class_method :secure_root

  def repository_root
    File.expand_path('..', __dir__)
  end
  private_class_method :repository_root

  def new_output_path(root_path, output)
    raise UsageError, '--output is required' if output.nil? || output.to_s.strip.empty?
    supplied = Pathname.new(output.to_s)
    candidate = supplied.absolute? ? supplied.expand_path : root_path.join(supplied).cleanpath
    parent = candidate.parent
    raise UsageError, 'output parent must be an existing directory' unless parent.directory?
    raise UsageError, 'output parent must not be symlinked' unless parent.realpath == parent
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
    raise UsageError, 'output path unavailable'
  end
  private_class_method :new_output_path

  def safe_relative_path?(relative)
    return false unless relative.is_a?(String) && !relative.empty? && !relative.include?("\0") && !relative.include?('\\')

    path = Pathname.new(relative)
    !path.absolute? && path.each_filename.none? { |component| component == '.' || component == '..' }
  rescue ArgumentError
    false
  end
  private_class_method :safe_relative_path?

  def validate_evidence_date!(relative)
    relative.scan(/(?<!\d)(20\d{2})[-_]?([01]\d)[-_]?([0-3]\d)(?!\d)/).each do |year, month, day|
      date = Date.new(Integer(year, 10), Integer(month, 10), Integer(day, 10))
      raise Error, "evidence path date later than #{SNAPSHOT_DATE}" if date > Date.iso8601(SNAPSHOT_DATE)
    rescue ArgumentError
      raise Error, 'evidence path contains invalid date'
    end
  end
  private_class_method :validate_evidence_date!

  def assert_closed!(value, keys, label)
    raise Error, "#{label}: expected object" unless value.is_a?(Hash)
    raise Error, "#{label}: object keys must be strings" unless value.keys.all? { |key| key.is_a?(String) }
    raise Error, "#{label}: unknown fields" unless (value.keys - keys).empty?
    raise Error, "#{label}: missing fields" unless (keys - value.keys).empty?
    true
  end
  private_class_method :assert_closed!

  def assert_secret_free!(value, label)
    locations = Core.secret_locations(value)
    raise Error, "#{label}: secret-like content at #{locations.join(', ')}" unless locations.empty?
    true
  end
  private_class_method :assert_secret_free!

  def assert_no_forbidden_map_keys!(value, path = '$')
    case value
    when Hash
      value.each do |key, child|
        normalized = key.to_s.encode(Encoding::UTF_8).unicode_normalize(:nfkc).downcase.gsub(/[^a-z0-9]/, '')
        if normalized.empty? || FORBIDDEN_MAP_KEY_ALIASES.any? { |token| normalized.include?(token) }
          raise Error, "#{path}: forbidden governance-capable field"
        end
        assert_no_forbidden_map_keys!(child, "#{path}.*")
      rescue EncodingError
        raise Error, "#{path}: invalid object key"
      end
    when Array
      value.each_with_index { |child, index| assert_no_forbidden_map_keys!(child, "#{path}[#{index}]") }
    end
    true
  end
  private_class_method :assert_no_forbidden_map_keys!

  def validate_enum!(value, allowed, label)
    raise Error, "#{label}: invalid value" unless value.is_a?(String) && allowed.include?(value)
  end
  private_class_method :validate_enum!

  def validate_string_array!(value, label, pattern: nil, safe_paths: false)
    raise Error, "#{label}: expected array" unless value.is_a?(Array) && value.all? { |entry| entry.is_a?(String) }
    raise Error, "#{label}: duplicate values" unless value.uniq == value
    raise Error, "#{label}: invalid value" if pattern && !value.all? { |entry| pattern.match?(entry) }
    raise Error, "#{label}: unsafe path" if safe_paths && !value.all? { |entry| safe_relative_path?(entry) }
    true
  end
  private_class_method :validate_string_array!

  def receipt(status, root_path, output, bytes)
    {
      'status' => status,
      'output_path' => repository_relative(root_path, Pathname.new(output).absolute? ? Pathname.new(output) : root_path.join(output)),
      'sha256' => Digest::SHA256.hexdigest(bytes)
    }
  end
  private_class_method :receipt

  def repository_relative(root_path, path)
    Pathname.new(path).relative_path_from(root_path).to_s
  end
  private_class_method :repository_relative

  def identity(stat)
    [stat.dev, stat.ino, stat.mode, stat.uid, stat.gid, stat.size]
  end
  private_class_method :identity

  def stable_stat(stat)
    [stat.dev, stat.ino, stat.mode, stat.uid, stat.gid, stat.nlink, stat.size, stat.mtime.to_r, stat.ctime.to_r]
  end
  private_class_method :stable_stat

  def fsync_directory(path)
    File.open(path, File::RDONLY) { |directory| directory.fsync }
  rescue Errno::EINVAL, Errno::ENOTSUP
    true
  end
  private_class_method :fsync_directory

  def deep_copy(value)
    case value
    when Hash
      value.to_h { |key, child| [key, deep_copy(child)] }
    when Array
      value.map { |child| deep_copy(child) }
    when String
      value.dup
    else
      value
    end
  end
  private_class_method :deep_copy

  def safe_error(error)
    error.message.gsub(/(?:password|passwd|secret|token|key)\s*[:=]\s*\S+/i, '[redacted]')
  end
  private_class_method :safe_error
end

if $PROGRAM_NAME == __FILE__
  options = { check: false }
  parser = OptionParser.new do |opts|
    opts.banner = 'Usage: ruby scripts/generate-g0-g3-coverage-tariff-successors.rb --kind map|ledger [--output PATH] [--check]'
    opts.on('--kind KIND', %w[map ledger]) { |value| options[:kind] = value }
    opts.on('--output PATH') { |value| options[:output] = value }
    opts.on('--check') { options[:check] = true }
  end

  begin
    parser.parse!(ARGV)
    raise G0G3CoverageTariffSuccessors::UsageError, 'unexpected positional arguments' unless ARGV.empty?
    raise G0G3CoverageTariffSuccessors::UsageError, '--kind is required' unless options[:kind]
    root = File.expand_path('..', __dir__)
    default_output = options[:kind] == 'map' ? G0G3CoverageTariffSuccessors::MAP_OUTPUT_PATH : G0G3CoverageTariffSuccessors::LEDGER_OUTPUT_PATH
    output = options[:output] || default_output
    result = if options[:check]
               options[:kind] == 'map' ?
                 G0G3CoverageTariffSuccessors.check_map!(root: root, output: output) :
                 G0G3CoverageTariffSuccessors.check_ledger!(root: root, output: output)
             elsif options[:kind] == 'map'
               G0G3CoverageTariffSuccessors.write_map!(root: root, output: output)
             else
               G0G3CoverageTariffSuccessors.write_ledger!(root: root, output: output)
             end
    $stdout.write(G0ProportionalGovernanceV2.canonical_json(result.is_a?(Hash) ? result : { 'status' => 'ok' }) + "\n")
  rescue OptionParser::ParseError, G0G3CoverageTariffSuccessors::UsageError => e
    warn e.message
    warn parser.banner
    exit 2
  rescue G0G3CoverageTariffSuccessors::Error => e
    warn "coverage tariff successor generation failed: #{e.message}"
    exit 1
  end
end
