# frozen_string_literal: true

require 'digest'
require 'json'
require 'minitest/autorun'

class LocalInpatientAccommodationTariffSourceEvidenceRecordTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  RECORD = 'docs/operations/T1_LOCAL_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_EVIDENCE_2026-09-02.md'
  TEMPLATE = 'docs/operations/T1_LOCAL_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_EVIDENCE_TEMPLATE_2026-09-02.md'
  README = 'docs/new-simrs-rebuild/phase-1/README.md'
  RECORD_SHA = 'ff2ea1bd851a9c3741c0a3c91b57d32f9088c41cc74ac083bc8ba4602cdc0b40'
  SOURCE_SHA = '3a2efebaf98d6911722eb995be7ef5cc0870bc188fa09f26e0b7d8e0fe02717f'
  WORKER_SHA = '67e537f205c7a5aae18fc99b5d6a85189a15f2e428c7f10dcbd507f96e2c8855'
  SCENARIO_SHA = 'daed4abe034738ff45a7a8c65db7023b9c404301b671b4fc677a428fbb21cc75'
  SQLITE_SHA = 'fb624094c805d1d1bc5eb23ad2aaa457cbfe3693825382ce732f3f2b7de45bae'
  GRANT_SHA = '4684fe0394cb17bb12d0b38237fedefca2dfb20c2a90fccccb9668a17df1363d'
  TEMPLATE_SHA = '08f563898f6b625238bdb62f3fbcea3a698a4fde37427347e997adc68993334e'
  ARTIFACTS = {
    'storage/app/portability-rehearsals/20260902T085041Z-postgresql17-inpatient-accommodation-tariff-source-c142b60f25f8.json' =>
      '34de88da216fa8ab40b6116039a94a85348ca8147102ecb1ae965eaf0ddfa824',
    'storage/app/portability-rehearsals/20260902T085713Z-mysql8411-inpatient-accommodation-tariff-source-5b8728694230.json' =>
      'cb425544f6231693efce9b9687ba98c1cf7a58dcb66078dfb8c914bc43ec117c',
  }.freeze

  def setup
    @record = File.binread(File.join(ROOT, RECORD))
    @template = File.binread(File.join(ROOT, TEMPLATE))
    @readme = File.binread(File.join(ROOT, README))
  end

  def test_record_binds_both_exact_immutable_artifacts_and_identical_catalogues
    ARTIFACTS.each do |relative, sha|
      path = File.join(ROOT, relative)
      assert File.file?(path), relative
      assert_equal 0o600, File.stat(path).mode & 0o777, relative
      assert_equal sha, Digest::SHA256.file(path).hexdigest, relative
      assert_includes @record, relative
      assert_includes @record, sha

      document = JSON.parse(File.binread(path))
      assert_equal 'PASS', document.fetch('status')
      assert_equal 28, document.fetch('scenarios').length
      assert document.fetch('scenarios').values.all? { |row| row.fetch('status') == 'PASS' }
      assert_equal SOURCE_SHA, document.dig('source_bindings', 'application_source_sha256')
      assert_equal WORKER_SHA, document.dig('source_bindings', 'worker_source_sha256')
      assert_equal SCENARIO_SHA, document.dig('source_bindings', 'scenario_catalog_sha256')
      assert_equal SQLITE_SHA, document.dig('source_bindings', 'sqlite_gate_catalog_sha256')
      assert_equal GRANT_SHA, document.dig('source_bindings', 'runtime_grant_catalog_sha256')
      assert_equal 'PASS', document.dig('sqlite_gate', 'status')
      assert_equal true, document.dig('cleanup', 'strict_cleanup_verified')
      %w[owner_acceptance_claim deployment_claim g0_claim g3_claim].each do |claim|
        assert_equal false, document.fetch(claim)
      end
    end
  end

  def test_versions_scenarios_cleanup_and_open_boundary_are_exact
    assert_includes @record, '`170010` (17.10)'
    assert_includes @record, '`8.4.11` (InnoDB)'
    assert_includes @record, '28/28 PASS'
    assert_equal 28, @record.scan(/^\| `[^`]+` \| PASS \| PASS \|$/).length
    assert_includes @record, 'mode `0600`'
    assert_includes @record, '`pointer_missing`'
    assert_includes @record, 'G0 and G3 remain `OPEN`'
    assert_includes @record, 'intentionally not claimed as an input to those executions'
  end

  def test_pre_run_template_remains_the_distinct_bound_not_run_input
    assert_equal TEMPLATE_SHA, Digest::SHA256.hexdigest(@template)
    refute_equal Digest::SHA256.hexdigest(@record), TEMPLATE_SHA
    assert_includes @record, TEMPLATE
    assert_includes @template, 'READY, NOT RUN'
    assert_includes @template, '| PostgreSQL | 17.10 | NOT RUN |'
    assert_includes @template, '| MySQL | 8.4.11 | NOT RUN |'
  end

  def test_final_record_contains_no_secret_or_authority_smuggling
    refute_match(/(?:password|secret|token)\s*[:=]\s*\S+/i, @record)
    refute_match(%r{(?:postgres(?:ql)?|mysql)://}i, @record)
    %w[owner_acceptance_claim deployment_claim g0_claim g3_claim].each do |claim|
      refute_includes @record, "#{claim}=true"
    end
  end

  def test_phase_readme_links_the_exact_final_record_without_closing_acceptance
    assert_equal RECORD_SHA, Digest::SHA256.hexdigest(@record)
    assert_includes @readme, '../../operations/T1_LOCAL_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_EVIDENCE_2026-09-02.md'
    assert_includes @readme, "exact SHA-256 `#{RECORD_SHA}`"
    assert_includes @readme, 'PostgreSQL 17.10/MySQL 8.4.11 exact-engine engineering verification complete'
    assert_includes @readme, 'named affected-domain, facility, registration, clinical, finance/revenue/cashier, RMIK, day-count-policy, and parity acceptance remain open'
    assert_includes @readme, 'G0/G3 remain open'
    assert_includes @readme, 'commit, push, hosted migration, deployment, and any production-data inference remain outside this local milestone'
    refute_includes @readme, 'application and exact-engine evidence have not started'
  end
end
