# frozen_string_literal: true

require 'digest'
require 'json'
require 'minitest/autorun'

class LocalExactCashSettlementEvidenceRecordTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  RECORD_PATH = 'docs/operations/T1_LOCAL_EXACT_CASH_SETTLEMENT_EVIDENCE_2026-09-02.md'
  TEMPLATE_PATH = 'docs/operations/T1_LOCAL_EXACT_CASH_SETTLEMENT_EVIDENCE_TEMPLATE_2026-09-02.md'
  EVIDENCE = {
    'postgresql17' => [
      'storage/app/portability-rehearsals/20260902T103254Z-postgresql17-exact-cash-settlement-bec9fabcd841.json',
      'bb3f4782ed0813d4092c5340a80c9df8d581936298d46a3e0dc85744999946af',
    ],
    'mysql8411' => [
      'storage/app/portability-rehearsals/20260902T103218Z-mysql8411-exact-cash-settlement-135d63c195dd.json',
      '4825d5f12e775ab5e4e26b04faf4f6802c8ebcc46a92b63eeb42e5469e466f79',
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

  def test_pair_is_passed_complete_and_bound_to_identical_sources
    @documents.each_value do |document|
      assert_equal 'PASS', document.fetch('status')
      assert_equal 'SIMRS_LOCAL_EXACT_CASH_SETTLEMENT_PORTABILITY', document.fetch('kind')
      assert_equal 17, document.fetch('scenarios').length
      assert document.fetch('scenarios').values.all? { |scenario| scenario.fetch('status') == 'PASS' }
      assert_equal 'PASS', document.fetch('sqlite_gate').fetch('status')
      assert_equal true, document.fetch('cleanup').fetch('strict_cleanup_verified')
    end
    bindings = @documents.values.map { |document| document.fetch('source_bindings') }
    %w[application_source_sha256 worker_source_sha256 scenario_catalog_sha256 runtime_grant_catalog_sha256 sqlite_gate_catalog_sha256].each do |key|
      assert_equal 1, bindings.map { |binding| binding.fetch(key) }.uniq.length, key
    end
  end

  def test_record_names_exact_files_hashes_and_versions
    EVIDENCE.each_value do |path, sha|
      assert_includes @record, path
      assert_includes @record, sha
    end
    assert_includes @record, 'PostgreSQL 17.10'
    assert_includes @record, 'MySQL 8.4.11 / InnoDB'
    assert_includes @record, '17/17'
  end

  def test_cumulative_outstanding_replay_and_reset_proofs_are_not_overstated
    %w[
      cumulative-version-outstanding-balance same-key-same-payload-concurrency
      third-connection-single-durable-settlement bounded-reset-recovery
      migration-guard-reinstall-after-failure
    ].each do |scenario|
      @documents.each_value { |document| assert_equal 'PASS', document.fetch('scenarios').fetch(scenario).fetch('status') }
    end
    assert_includes @record, 'Rp14.000 collects only its Rp7.000 outstanding balance'
    assert_includes @record, 'rather than double-charging the cumulative amount'
    assert_includes @record, 'exact bill-version public identifier and content digest'
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

  def test_not_run_template_is_still_a_separate_truthful_template
    template = File.read(File.join(ROOT, TEMPLATE_PATH), encoding: Encoding::UTF_8)
    assert_includes template, 'READY / NOT RUN'
    assert_includes template, 'This template is intentionally not execution evidence.'
    refute_equal Digest::SHA256.file(File.join(ROOT, TEMPLATE_PATH)).hexdigest,
                 Digest::SHA256.file(File.join(ROOT, RECORD_PATH)).hexdigest
  end
end
