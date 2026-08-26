# frozen_string_literal: true

require 'json'
require 'minitest/autorun'
require 'open3'
require 'digest'

require_relative '../../scripts/generate-t1-local-milestone-manifest'

class T1LocalMilestoneManifestTest < Minitest::Test
  ROOT = T1LocalMilestoneManifest::ROOT

  def portability_documents
    T1LocalMilestoneManifest::LOCAL_EVIDENCE.each_with_object({}) do |source, documents|
      next unless T1LocalMilestoneManifest::PORTABILITY_EVIDENCE_IDS.key?(source.fetch('evidence_id'))

      path = File.join(ROOT, source.fetch('path'))
      documents[source.fetch('evidence_id')] = T1LocalMilestoneManifest.parse_json_object!(File.binread(path), source.fetch('path'))
    end
  end

  def copied(value)
    Marshal.load(Marshal.dump(value))
  end

  def evidence_bindings(documents = portability_documents)
    reference = documents.fetch(T1LocalMilestoneManifest::PORTABILITY_EVIDENCE_IDS.keys.first)
    %w[backend_execution_source_set migration_set harness_sha256 workflow_test_catalog_sha256]
      .to_h { |key| [key, copied(reference.fetch(key))] }
  end

  def test_build_is_local_not_deployed_and_binds_fixed_repository_state
    manifest = T1LocalMilestoneManifest.build_inventory

    assert_equal 'LOCAL', manifest.fetch('classification')
    assert_equal 'NOT_DEPLOYED', manifest.fetch('deployment_status')
    assert_equal T1LocalMilestoneManifest::CURRENT_ARTIFACT_ID, manifest.fetch('artifact_id')
    assert_equal T1LocalMilestoneManifest::CURRENT_SNAPSHOT_DATE, manifest.fetch('snapshot_date')
    assert_equal T1LocalMilestoneManifest::REQUIRED_SHA, manifest.dig('repository', 'head')
    assert_equal T1LocalMilestoneManifest::REQUIRED_SHA, manifest.dig('repository', 'origin_main')
    assert_equal true, manifest.dig('repository', 'staging_empty')
    assert manifest.fetch('actions_performed').values.none?
  end

  def test_candidate_inventory_is_closed_complete_and_self_hash_free
    manifest = T1LocalMilestoneManifest.build_inventory
    candidates = manifest.fetch('candidate_files')
    paths = candidates.map { |record| record.fetch('path') }
    state_counts = manifest.dig('scope', 'state_counts')

    assert_equal paths.sort, paths
    assert_equal paths.uniq, paths
    assert_equal candidates.length, manifest.dig('scope', 'candidate_count')
    assert_equal candidates.length, state_counts.values.sum
    assert_equal %w[deleted modified untracked], state_counts.keys.sort
    assert_includes paths, T1LocalMilestoneManifest::GENERATOR_PATH
    assert_includes paths, T1LocalMilestoneManifest::TEST_PATH
    refute_includes paths, T1LocalMilestoneManifest::MANIFEST_PATH
    candidates.each do |record|
      assert_match(/\A[0-9a-f]{64}\z/, record.fetch('sha256'))
      assert_includes %w[modified untracked deleted], record.fetch('state')
      refute T1LocalMilestoneManifest.protected_path?(record.fetch('path'))
    end
  end

  def test_deleted_candidates_bind_head_bytes_without_requiring_a_live_deletion
    raw = " D scripts/generate-t1-local-milestone-manifest.rb\0".b

    records, excluded_count = T1LocalMilestoneManifest.candidate_inventory(raw)

    assert_equal 0, excluded_count
    assert_equal 1, records.length
    assert_equal 'deleted', records.first.fetch('state')
    assert_equal 'head_bytes', records.first.fetch('sha256_basis')
    assert_equal Digest::SHA256.hexdigest(
      T1LocalMilestoneManifest.git('show', 'HEAD:scripts/generate-t1-local-milestone-manifest.rb')
    ), records.first.fetch('sha256')
  end

  def test_protected_status_entries_are_excluded_before_filename_or_file_inspection
    raw = "?? docs/legacy-visual-field-capture/do not inspect.pem\0".b

    records, excluded_count = T1LocalMilestoneManifest.candidate_inventory(raw)

    assert_empty records
    assert_equal 1, excluded_count
  end

  def test_status_parser_is_nul_safe_and_rejects_staging_or_unsupported_states
    rows = T1LocalMilestoneManifest.parse_status(" M app/Models/Encounter.php\0?? safe.txt\0".b)
    assert_equal [[' M', 'app/Models/Encounter.php'], ['??', 'safe.txt']], rows

    assert_raises(T1LocalMilestoneManifest::ContractError) do
      T1LocalMilestoneManifest.parse_status("M  staged.txt\0".b)
    end
    assert_raises(T1LocalMilestoneManifest::ContractError) do
      T1LocalMilestoneManifest.parse_status("?? unsafe name.txt\0".b).each { |_status, path| T1LocalMilestoneManifest.validate_relative_path!(path) }
    end
    assert_raises(T1LocalMilestoneManifest::ContractError) do
      T1LocalMilestoneManifest.parse_status("?? invalid-\xFF.txt\0".b).each { |_status, path| T1LocalMilestoneManifest.validate_relative_path!(path) }
    end
  end

  def test_secret_and_secret_filename_guards_fail_closed
    assert_raises(T1LocalMilestoneManifest::ContractError) do
      T1LocalMilestoneManifest.validate_relative_path!('.env.local')
    end
    assert_raises(T1LocalMilestoneManifest::ContractError) do
      token = %w[abcdefghijklm nopqrstuvwxyz123456].join
      T1LocalMilestoneManifest.reject_secret_bytes!("Authorization: Bearer #{token}", 'fixture')
    end
  end

  def test_exact_ignored_local_evidence_is_hash_bound_without_embedded_content
    evidence = T1LocalMilestoneManifest.build_inventory.fetch('ignored_local_evidence')
    expected = {
      'postgresql_17_daily_queue_concurrency' => '2d49a0a502bf8645492705e1b46c12b44681fe50d00806ab186ed205b7ceac38',
      'postgresql_17_query_plans' => 'bdb08b98f6cc3f9e96ca911cc7c44855922da961faa9070612df8a97e0560e70',
      'postgresql_17_recovery' => '68bc689461d336b467afa85cf43d36130a0494e163c90a5408a16d2fa2fddad6'
    }
    T1LocalMilestoneManifest::LOCAL_EVIDENCE.each do |source|
      next unless T1LocalMilestoneManifest::PORTABILITY_EVIDENCE_IDS.key?(source.fetch('evidence_id'))

      expected[source.fetch('evidence_id')] = Digest::SHA256.file(File.join(ROOT, source.fetch('path'))).hexdigest
    end

    assert_equal expected.keys, evidence.map { |record| record.fetch('evidence_id') }
    evidence.each do |record|
      assert_equal expected.fetch(record.fetch('evidence_id')), record.fetch('sha256')
      assert_equal true, record.fetch('ignored')
      assert_equal false, record.fetch('content_in_manifest')
      refute record.key?('content')
    end
  end

  def test_open_boundaries_remain_explicit
    boundaries = T1LocalMilestoneManifest.build_inventory.fetch('open_boundaries')

    assert_equal %w[G0 G3 exact_engine_portability hosted_uat owner_acceptance security_findings], boundaries.keys.sort
    assert_match(/OPEN/, boundaries.fetch('G0'))
    assert_match(/OPEN/, boundaries.fetch('G3'))
    assert_match(/NOT_RUN/, boundaries.fetch('hosted_uat'))
    assert_match(/NOT_ACCEPTED/, boundaries.fetch('owner_acceptance'))
    assert_match(/UNVERIFIED_FOR_BOOTSTRAP/, boundaries.fetch('exact_engine_portability'))
    refute_match(/PASS locally/, boundaries.fetch('exact_engine_portability'))
  end

  def test_configured_portability_records_pass_closed_semantic_contract_without_currentness_claim
    assert T1LocalMilestoneManifest.validate_portability_evidence_documents!(
      portability_documents,
      enforce_currentness: false
    )
  end

  def test_current_portability_evidence_matches_the_published_base_and_artifact_binding
    portability_documents.each_value do |document|
      assert_equal T1LocalMilestoneManifest::REQUIRED_SHA, document.fetch('baseline_git_sha')
      assert_equal T1LocalMilestoneManifest::CURRENT_ARTIFACT_ID,
                   document.dig('local_manifest', 'artifact_id')
    end
  end

  def test_current_portability_binding_is_explicitly_pass_or_stale
    documents = portability_documents
    begin
      T1LocalMilestoneManifest.validate_portability_evidence_documents!(documents)
    rescue T1LocalMilestoneManifest::ContractError => error
      assert_match(/stale portability evidence/, error.message)
      build_error = assert_raises(T1LocalMilestoneManifest::ContractError) { T1LocalMilestoneManifest.build }
      assert_match(/stale portability evidence/, build_error.message)
    else
      manifest = T1LocalMilestoneManifest.build
      assert_match(/PASS locally/, manifest.dig('open_boundaries', 'exact_engine_portability'))
    end
  end

  def test_stale_execution_binding_fails_closed
    live = evidence_bindings
    live.fetch('backend_execution_source_set')['sha256'] = '0' * 64

    error = assert_raises(T1LocalMilestoneManifest::ContractError) do
      T1LocalMilestoneManifest.validate_portability_evidence_documents!(portability_documents, live_bindings: live)
    end
    assert_match(/stale portability evidence: backend_execution_source_set/, error.message)
  end

  def test_legacy_loopback_postgresql_record_cannot_satisfy_currentness
    documents = copied(portability_documents)
    postgresql = documents.fetch('postgresql_17_current_manifest_portability')
    postgresql['engine'] = {
      'engine' => 'postgresql',
      'engine_version' => '170010',
      'engine_major' => 17,
      'application_schema' => 'laravel',
      'disposable_database' => true,
      'loopback_only' => true
    }
    postgresql.fetch('cleanup')['temporary_server_removed'] = false
    live = evidence_bindings(documents)

    error = assert_raises(T1LocalMilestoneManifest::ContractError) do
      T1LocalMilestoneManifest.validate_portability_evidence_documents!(documents, live_bindings: live)
    end
    assert_match(/isolated Unix-socket cluster/, error.message)
  end

  def test_engine_and_shared_binding_mismatches_fail_closed
    wrong_engine = copied(portability_documents)
    postgresql_id = 'postgresql_17_current_manifest_portability'
    wrong_engine.fetch(postgresql_id).fetch('engine')['engine_major'] = 16
    assert_raises(T1LocalMilestoneManifest::ContractError) do
      T1LocalMilestoneManifest.validate_portability_evidence_documents!(wrong_engine, enforce_currentness: false)
    end

    wrong_mysql = copied(portability_documents)
    wrong_mysql.fetch('mysql_8_4_current_manifest_portability').fetch('engine')['engine_version'] = '8.4.10'
    assert_raises(T1LocalMilestoneManifest::ContractError) do
      T1LocalMilestoneManifest.validate_portability_evidence_documents!(wrong_mysql, enforce_currentness: false)
    end

    disagreement = copied(portability_documents)
    disagreement.fetch('mysql_8_4_current_manifest_portability')['workflow_test_catalog_sha256'] = 'f' * 64
    error = assert_raises(T1LocalMilestoneManifest::ContractError) do
      T1LocalMilestoneManifest.validate_portability_evidence_documents!(disagreement, enforce_currentness: false)
    end
    assert_match(/shared binding workflow_test_catalog_sha256 disagrees/, error.message)

    manifest_disagreement = copied(portability_documents)
    manifest_disagreement.fetch('mysql_8_4_current_manifest_portability').fetch('local_manifest')['sha256'] = 'a' * 64
    error = assert_raises(T1LocalMilestoneManifest::ContractError) do
      T1LocalMilestoneManifest.validate_portability_evidence_documents!(manifest_disagreement, enforce_currentness: false)
    end
    assert_match(/shared binding local_manifest disagrees/, error.message)
  end

  def test_malformed_and_duplicate_json_fail_closed
    assert_raises(T1LocalMilestoneManifest::ContractError) do
      T1LocalMilestoneManifest.parse_json_object!('{', 'fixture')
    end
    error = assert_raises(T1LocalMilestoneManifest::ContractError) do
      T1LocalMilestoneManifest.parse_json_object!('{"status":"PASS","status":"FAIL"}', 'fixture')
    end
    assert_match(/duplicate JSON object key "status"/, error.message)
  end

  def test_claim_inflation_fails_including_inventory_bootstrap_mode
    %w[hosted_readiness_claim deployment_claim owner_acceptance_claim].each do |claim|
      inflated = copied(portability_documents)
      inflated.fetch('postgresql_17_current_manifest_portability')[claim] = true
      error = assert_raises(T1LocalMilestoneManifest::ContractError) do
        T1LocalMilestoneManifest.validate_portability_evidence_documents!(inflated, enforce_currentness: false)
      end
      assert_match(/#{claim} must equal false/, error.message)
    end
  end

  def test_inventory_bootstrap_bytes_are_closed_and_drift_is_rejected
    bytes = T1LocalMilestoneManifest.serialized(enforce_portability_currentness: false, inventory_only: true)
    assert T1LocalMilestoneManifest.verify_inventory_bytes!(bytes)
    error = assert_raises(T1LocalMilestoneManifest::ContractError) do
      T1LocalMilestoneManifest.verify_inventory_bytes!(bytes.sub('NOT_DEPLOYED', 'DEPLOYED'))
    end
    assert_match(/stale inventory-bootstrap manifest/, error.message)
  end

  def test_publication_claim_cannot_bypass_currentness_gate
    error = assert_raises(T1LocalMilestoneManifest::ContractError) do
      T1LocalMilestoneManifest.build(enforce_portability_currentness: false, inventory_only: false)
    end
    assert_match(/either current publication or non-claiming inventory bootstrap/, error.message)
  end

  def test_cli_accepts_only_one_closed_action
    script = File.join(ROOT, T1LocalMilestoneManifest::GENERATOR_PATH)
    _stdout, _stderr, status = Open3.capture3(RbConfig.ruby, script)
    refute status.success?
    _stdout, _stderr, status = Open3.capture3(RbConfig.ruby, script, '--check', '--write')
    refute status.success?
    _stdout, _stderr, status = Open3.capture3(RbConfig.ruby, script, '--check-inventory', '--write-inventory')
    refute status.success?
  end
end
