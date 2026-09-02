# frozen_string_literal: true

require 'digest'
require 'json'
require 'minitest/autorun'

require_relative '../../scripts/rehearse-local-finance-tariff-component-master-portability'

class LocalFinanceTariffComponentMasterEvidenceTest < Minitest::Test
  Harness = LocalFinanceTariffComponentMasterPortabilityRehearsal
  EVIDENCE_PATH = 'docs/operations/T1_LOCAL_GOVERNED_FINANCE_TARIFF_COMPONENT_MASTER_EVIDENCE_2026-09-02.md'
  PHASE_PATH = 'docs/new-simrs-rebuild/phase-1/README.md'
  COMMAND_CATALOG_SHA256 = '796265db7816c11778ba9f06e050b5be3f09507ff034552c0fc7f00b5b1f1750'
  ENGINE_RECORDS = {
    'postgresql17' => {
      path: 'storage/app/portability-rehearsals/20260901T231657Z-postgresql17-finance-tariff-component-master-6dd35de99de9.json',
      sha256: 'd50846596ee2d4e1277cd32a966843c3446bc19ef570238407f8342f7e5999ae',
      version: '17.10'
    },
    'mysql8411' => {
      path: 'storage/app/portability-rehearsals/20260901T231741Z-mysql8411-finance-tariff-component-master-37b4ab3671ed.json',
      sha256: '99a067f3a4e4f84bf4de369e700f9df11d3f8edbdbb983844d0ed490c102f94a',
      version: '8.4.11'
    }
  }.freeze

  def setup
    @evidence = File.read(File.join(Harness::ROOT, EVIDENCE_PATH), encoding: Encoding::UTF_8)
    @phase = File.read(File.join(Harness::ROOT, PHASE_PATH), encoding: Encoding::UTF_8)
    @bindings = harness.current_tariff_bindings
  end

  def test_human_record_is_bound_to_the_current_verified_source
    assert_includes @evidence, 'Status: **PASS — LOCAL DISPOSABLE ENGINES ONLY**'
    assert_includes @evidence, @bindings.fetch('aggregate_sha256')
    assert_equal @bindings.fetch('aggregate_sha256'), @bindings.fetch('application_source_sha256')

    {
      'worker_source_sha256' => 'Embedded worker binding',
      'scenario_catalog_sha256' => 'Scenario catalogue binding',
      'runtime_grant_catalog_sha256' => 'Runtime-grant catalogue binding'
    }.each do |binding_key, label|
      assert_includes @evidence, "#{label}: `#{@bindings.fetch(binding_key)}`"
    end
    assert_includes @evidence, "Command catalogue binding: `#{COMMAND_CATALOG_SHA256}`"
  end

  def test_exact_engine_register_and_claim_boundary_are_explicit
    ENGINE_RECORDS.each_value do |record|
      assert_includes @evidence, record.fetch(:path)
      assert_includes @evidence, record.fetch(:sha256)
      assert_includes @evidence, record.fetch(:version)
    end

    assert_equal 2, @evidence.scan('| PASS | 20/20 |').length
    assert_includes @evidence, 'does not generate a charge automatically'
    assert_includes @evidence, 'does not prove hosted Supabase migration, deployment, browser UAT'
    assert_includes @evidence, 'Payment, accounting, claims, BPJS, VClaim, SATUSEHAT, LIS, PACS/RIS'
  end

  def test_retained_local_engine_records_match_the_published_register_when_present
    ENGINE_RECORDS.each do |engine, expected|
      absolute = File.join(Harness::ROOT, expected.fetch(:path))
      next unless File.file?(absolute)

      assert_equal expected.fetch(:sha256), Digest::SHA256.file(absolute).hexdigest

      record = JSON.parse(File.read(absolute, encoding: Encoding::UTF_8))
      assert_equal 'SIMRS_LOCAL_GOVERNED_FINANCE_TARIFF_COMPONENT_MASTER_PORTABILITY', record.fetch('kind')
      assert_equal 'PASS', record.fetch('status')
      assert_equal 20, record.fetch('scenarios').length
      assert record.fetch('scenarios').values.all? { |scenario| scenario.fetch('status') == 'PASS' }
      assert_equal @bindings.fetch('aggregate_sha256'), record.dig('source_bindings', 'aggregate_sha256')
      assert_equal COMMAND_CATALOG_SHA256, record.dig('source_bindings', 'command_catalog_sha256')
      assert_equal false, record.fetch('deployment_claim')
      assert_equal false, record.fetch('hosted_readiness_claim')
      assert_equal false, record.fetch('owner_acceptance_claim')
      assert record.fetch('cleanup').values.all?, "#{engine} cleanup must remain completely verified"
    end
  end

  def test_phase_roadmap_reports_local_completion_without_acceptance_or_release_claims
    assert_includes @phase, 'T1_LOCAL_GOVERNED_FINANCE_TARIFF_COMPONENT_MASTER_EVIDENCE_2026-09-02.md'
    assert_includes @phase, 'Local implementation and PostgreSQL 17.10/MySQL 8.4.11 exact-engine engineering verification complete'
    assert_includes @phase, 'finance/cashier, affected-domain, and parity acceptance open'
    assert_includes @phase, 'connect deliberately entered effective tariffs to diagnostic/accommodation charge-source generation'
    assert_includes @phase, 'without inventing prices or bypassing the existing versioned bill'
  end

  private

  def harness
    Harness.new(
      engine: 'postgresql17',
      environment: {
        Harness::CONFIRMATION_ENV => Harness::CONFIRMATION,
        'DB_URL' => ''
      }
    )
  end
end
