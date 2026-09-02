# frozen_string_literal: true

require 'digest'
require 'json'
require 'minitest/autorun'

class LocalAppendOnlyCashSettlementCorrectionEvidenceRecordTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  RECORD_PATH = 'docs/operations/T1_LOCAL_APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_EVIDENCE_2026-09-02.md'
  TEMPLATE_PATH = 'docs/operations/T1_LOCAL_APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_EVIDENCE_TEMPLATE_2026-09-02.md'
  EVIDENCE = {
    'postgresql17' => [
      'storage/app/portability-rehearsals/20260902T155803Z-postgresql17-cash-settlement-correction-add99d552334.json',
      'dca440d71242b5bdd27093ae56f221220545bad79ac4faf6c0cafe08e823d3d2',
    ],
    'mysql8411' => [
      'storage/app/portability-rehearsals/20260902T155936Z-mysql8411-cash-settlement-correction-996d6f8df6f9.json',
      '3e518413ea7ddf5820655b9ba0ed75b0751b0d82c0ae2bb64291d27765432fec',
    ],
  }.freeze

  def setup
    @record = File.read(File.join(ROOT, RECORD_PATH), encoding: Encoding::UTF_8)
    @documents = EVIDENCE.transform_values do |path, expected_sha|
      full_path = File.join(ROOT, path)
      assert_equal expected_sha, Digest::SHA256.file(full_path).hexdigest
      assert_equal 0o600, File.stat(full_path).mode & 0o777
      JSON.parse(File.read(full_path, encoding: Encoding::UTF_8))
    end
  end

  def test_pair_is_complete_and_bound_to_identical_sources
    @documents.each_value do |document|
      assert_equal 'PASS', document.fetch('status')
      assert_equal 'SIMRS_LOCAL_APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_PORTABILITY', document.fetch('kind')
      assert_equal 22, document.fetch('scenarios').length
      assert document.fetch('scenarios').values.all? { |scenario| scenario.fetch('status') == 'PASS' }
      assert_equal 'PASS', document.fetch('sqlite_gate').fetch('status')
      assert_equal true, document.fetch('cleanup').fetch('strict_cleanup_verified')
    end
    bindings = @documents.values.map { |document| document.fetch('source_bindings') }
    %w[application_source_sha256 worker_source_sha256 scenario_catalog_sha256 runtime_grant_catalog_sha256 sqlite_gate_catalog_sha256].each do |key|
      assert_equal 1, bindings.map { |binding| binding.fetch(key) }.uniq.length, key
    end
  end

  def test_record_names_files_hashes_versions_and_short_inventory
    EVIDENCE.each_value do |path, sha|
      assert_includes @record, path
      assert_includes @record, sha
    end
    assert_includes @record, 'PostgreSQL 17.10'
    assert_includes @record, 'MySQL 8.4.11 / InnoDB'
    assert_includes @record, '22/22'
    assert_includes @record, 'fscor_public_id_uq'
    assert_includes @record, 'fscor_truncate_guard'
    assert_includes @record, 'fscor_immutable_delete'
  end

  def test_concurrency_net_cash_recovery_reset_and_refusals_are_bound
    %w[
      migration-failure-guard-reinstall failed-install-preserves-lifetime-uniqueness
      real-wait-single-durable-case double-review-refusal double-refund-refusal
      third-connection-net-cash-readback recovery-relevant-column-presence
      retained-evidence-rollback-refusal bounded-reset strict-cleanup
    ].each do |scenario|
      @documents.each_value { |document| assert_equal 'PASS', document.fetch('scenarios').fetch(scenario).fetch('status') }
    end
    @documents.each_value do |document|
      recovery = document.dig('scenarios', 'recovery-relevant-column-presence', 'recovery_audit')
      assert_equal 0, recovery.fetch('healthy_audit_mismatches')
      assert_equal 4, recovery.fetch('independent_delete_or_tamper_probes')
      assert_equal true, recovery.fetch('sibling_audit_cannot_mask_missing_or_tampered_binding')
      assert_equal true, document.dig('scenarios', 'exact-runtime-grants', 'worker', 'session_bypass_escalation_denied')
      assert_equal true, document.dig('scenarios', 'bounded-reset', 'worker', 'installer_owner_bypass_proven')
    end
  end

  def test_governance_and_external_boundaries_remain_open
    @documents.each_value do |document|
      assert_equal false, document.fetch('owner_acceptance_claim')
      assert_equal false, document.fetch('g0_claim')
      assert_equal false, document.fetch('g3_claim')
      assert_equal false, document.fetch('deployment_claim')
      assert_equal false, document.fetch('hosted_readiness_claim')
    end
    assert_includes @record, 'G0 and G3 remain **OPEN**'
    assert_includes @record, 'not facility or finance-owner acceptance'
  end

  def test_not_run_template_remains_a_separate_truthful_template
    template = File.read(File.join(ROOT, TEMPLATE_PATH), encoding: Encoding::UTF_8)
    assert_includes template, 'READY / NOT RUN'
    assert_includes template, 'This template is not execution evidence.'
    refute_equal Digest::SHA256.file(File.join(ROOT, TEMPLATE_PATH)).hexdigest,
                 Digest::SHA256.file(File.join(ROOT, RECORD_PATH)).hexdigest
  end
end
