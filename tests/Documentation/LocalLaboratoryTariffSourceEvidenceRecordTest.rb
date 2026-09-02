# frozen_string_literal: true

require 'digest'
require 'json'
require 'minitest/autorun'

class LocalLaboratoryTariffSourceEvidenceRecordTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  RECORD = 'docs/operations/T1_LOCAL_LABORATORY_TARIFF_SOURCE_EVIDENCE_2026-09-02.md'
  TEMPLATE = 'docs/operations/T1_LOCAL_LABORATORY_TARIFF_SOURCE_EVIDENCE_TEMPLATE_2026-09-02.md'
  SOURCE_SHA = '7ac7e49b2a1577ad307fa0a37ae68beadb6b2c39962df66ffaf5ae5f0aacc74e'
  WORKER_SHA = 'b29447189b58731bb26a49ddcbce8d1a8d18701d14ac8f9ace51a0a87733f33b'
  SCENARIO_SHA = 'bc8e4417f234cc80cc355cb1ed7f25e76b6eff8c5cd24921a2ba37da6f85c5dd'
  SQLITE_SHA = '6386c172fd36d7de79de263b111b209fe21b51d9518642b398cf3a7ad6278e46'
  ARTIFACTS = {
    'storage/app/portability-rehearsals/20260902T052603Z-postgresql17-laboratory-tariff-source-fcfeacb4735a.json' =>
      'b7403d60e0e667e61d5d53ff1575e3bbdca8717ac86d90379573235b80c71efa',
    'storage/app/portability-rehearsals/20260902T052935Z-mysql8411-laboratory-tariff-source-eab90648c5b1.json' =>
      '720a838f46555d20db554404935ccebabd43c310c724ad53ae92c1e960bf0c64',
  }.freeze

  def setup
    @record = File.binread(File.join(ROOT, RECORD))
    @template_sha = Digest::SHA256.file(File.join(ROOT, TEMPLATE)).hexdigest
  end

  def test_record_binds_both_exact_immutable_artifacts_and_identical_source_catalogues
    ARTIFACTS.each do |relative, sha|
      path = File.join(ROOT, relative)
      assert File.file?(path), relative
      assert_equal 0o600, File.stat(path).mode & 0o777, relative
      assert_equal sha, Digest::SHA256.file(path).hexdigest, relative
      assert_includes @record, relative
      assert_includes @record, sha

      document = JSON.parse(File.binread(path))
      assert_equal 'PASS', document.fetch('status')
      assert_equal 22, document.fetch('scenarios').length
      assert document.fetch('scenarios').values.all? { |row| row.fetch('status') == 'PASS' }
      assert_equal SOURCE_SHA, document.dig('source_bindings', 'application_source_sha256')
      assert_equal WORKER_SHA, document.dig('source_bindings', 'worker_source_sha256')
      assert_equal SCENARIO_SHA, document.dig('source_bindings', 'scenario_catalog_sha256')
      assert_equal SQLITE_SHA, document.dig('source_bindings', 'sqlite_gate_catalog_sha256')
      assert_equal 'PASS', document.dig('sqlite_gate', 'status')
      assert_equal true, document.dig('cleanup', 'strict_cleanup_verified')
      assert_equal false, document.fetch('owner_acceptance_claim')
      assert_equal false, document.fetch('deployment_claim')
      assert_equal false, document.fetch('g0_claim')
      assert_equal false, document.fetch('g3_claim')
    end
  end

  def test_versions_scenario_count_cleanup_and_open_gate_boundary_are_exact
    assert_includes @record, '`170010` (17.10)'
    assert_includes @record, '`8.4.11`'
    assert_includes @record, '22/22 PASS'
    assert_equal 22, @record.scan(/^\| .+ \| PASS \| PASS \|$/).length
    assert_includes @record, 'mode `0600`'
    assert_includes @record, '`pointer_missing`'
    assert_includes @record, 'G0 and G3 remain `OPEN`'
    assert_includes @record, 'intentionally not claimed as an input to those executions'
  end

  def test_pre_run_template_is_retained_as_a_distinct_immutable_binding_input
    refute_equal Digest::SHA256.hexdigest(@record), @template_sha
    assert_includes @record, TEMPLATE
    template = File.binread(File.join(ROOT, TEMPLATE))
    assert_includes template, 'READY, NOT RUN'
    assert_includes template, '| PostgreSQL | 17.10 | NOT RUN |'
    assert_includes template, '| MySQL | 8.4.11 | NOT RUN |'
  end

  def test_final_record_contains_no_secret_or_authority_smuggling
    refute_match(/(?:password|secret|token)\s*[:=]\s*\S+/i, @record)
    refute_match(%r{(?:postgres(?:ql)?|mysql)://}i, @record)
    refute_includes @record, 'owner_acceptance_claim=true'
    refute_includes @record, 'deployment_claim=true'
    refute_includes @record, 'g0_claim=true'
    refute_includes @record, 'g3_claim=true'
  end
end
