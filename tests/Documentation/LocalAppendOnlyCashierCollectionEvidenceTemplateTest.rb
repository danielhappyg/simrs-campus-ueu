# frozen_string_literal: true

require 'minitest/autorun'

require_relative '../../scripts/rehearse-local-append-only-cashier-collection-portability'

class LocalAppendOnlyCashierCollectionEvidenceTemplateTest < Minitest::Test
  Harness = LocalAppendOnlyCashierCollectionPortabilityRehearsal
  PATH = File.join(Harness::ROOT, Harness::EVIDENCE_TEMPLATE_PATH)

  def setup
    @template = File.binread(PATH)
  end

  def test_template_is_explicitly_ready_but_not_execution_evidence
    assert_includes @template, 'Status: **READY / NOT RUN**'
    assert_includes @template, 'This template is not execution evidence.'
    assert_includes @template, 'Final paired status: **READY / NOT RUN**'
    refute_includes @template, 'Final paired status: **PASS**'
  end

  def test_two_immutable_exact_engine_artifacts_and_versions_are_required
    assert_includes @template, '| PostgreSQL | `17.10` | `NOT RUN`'
    assert_includes @template, '| MySQL | `8.4.11` | `NOT RUN`'
    assert_includes @template, 'The two paths must be distinct.'
    assert_includes @template, 'mode-`0600` JSON artifact'
    assert_includes @template, 'immutable after its SHA-256 is recorded'
    assert_includes @template, 'Verify each SHA-256 from disk after strict cleanup.'
  end

  def test_source_and_catalogue_hashes_must_match_between_engines
    %w[
      application_source_sha256 worker_source_sha256 scenario_catalog_sha256
      runtime_grant_catalog_sha256 sqlite_gate_catalog_sha256
      command_catalog_sha256
    ].each { |binding| assert_includes @template, "`#{binding}`" }
    assert_includes @template, 'Every row must match exactly between engines'
    assert_includes @template, 'Required engine-specific outcome bindings'
    assert_includes @template, '`sqlite_gate.result_catalog_sha256`'
    assert_includes @template, '`source_bindings.result_catalog_sha256`'
    assert_includes @template, 'must not be equal between engines'
    assert_includes @template, 'An engine-specific result hash difference is expected'
    assert_includes @template, 'Any source change after the first engine run invalidates that artifact pair'
    assert_includes Harness::SOURCE_PATHS, Harness::EVIDENCE_TEMPLATE_PATH
    assert_includes Harness::SOURCE_PATHS, 'tests/Documentation/LocalAppendOnlyCashierCollectionEvidenceTemplateTest.rb'
  end

  def test_exact_closed_22_scenario_inventory_is_present_once
    assert_equal 22, Harness::SCENARIOS.length
    Harness::SCENARIOS.each do |scenario|
      assert_equal 1, @template.scan("`#{scenario}`").length, "scenario must appear exactly once: #{scenario}"
    end
    assert_includes @template, 'two independent application processes'
    assert_includes @template, 'observed database waiting'
    assert_includes @template, 'Sequential simulation is not acceptable.'
    assert_includes @template, 'independently reconcile exact membership, gross cash, completed refunds, net cash'
  end

  def test_database_check_evidence_requires_real_sanitized_native_rejections
    assert_includes @template, 'must not rely on catalogue presence alone'
    assert_includes @template, '`CLOSE_VERIFIED` event with non-zero variance'
    assert_includes @template, 'native rejection specifically by `fcce_values_ck`'
    assert_includes @template, '`OPEN` / `EVENT` / null-event-FK operation-receipt shape'
    assert_includes @template, 'native rejection specifically by `fccor_result_ck`'
    assert_includes @template, 'constraint name, SQLSTATE, driver code, and a diagnostic fingerprint'
    assert_includes @template, 'never SQL text or credentials'
    assert_includes @template, 'Both failed inserts must roll back'
    assert_includes @template, 'healthy collection recovery mismatch count at zero'
  end

  def test_refund_close_race_requires_exact_reconciled_final_state
    assert_includes @template, '`CLOSED`, stale-fingerprint refusal, and batch-not-open refusal'
    assert_includes @template, 'The contender must submit counted cash `0`.'
    assert_includes @template, 'close the batch if it remains open or reconcile its retained close event if already frozen'
    assert_includes @template, 'exactly one member and one close event'
    assert_includes @template, 'gross cash `9000`, completed refund `9000`, expected net cash `0`'
    assert_includes @template, 'counted cash `0`, variance `0`'
    assert_includes @template, 'valid durable membership/event fingerprints'
  end

  def test_full_backend_frontend_static_and_build_gates_are_required
    %w[
      Full\ backend\ suite Focused\ authorization/documentation\ contracts PHPStan
      Pint\ /\ formatting Diff\ check Frontend\ tests Frontend\ lint
      Frontend\ format\ check Frontend\ type\ check Frontend\ production\ build
    ].each { |gate| assert_includes @template, gate.tr('\\', '') }
    assert_includes @template, 'error count `0`'
    assert_includes @template, 'A prior green run against a different source aggregate is not acceptable evidence.'
  end

  def test_strict_cleanup_no_secrets_and_no_live_or_hosted_systems_are_required
    %w[
      disposable\ database/cluster generated\ runtime\ role temporary\ worker temporary\ credentials
      password token connection\ string cookie secret raw\ SQL\ query\ text environment\ dump
      BPJS VClaim E-Klaim SATUSEHAT LIS PACS banking treasury
      hosted\ Supabase/Vercel hosted\ migration real\ patient real\ payment
    ].each { |requirement| assert_includes @template, requirement.tr('\\', '') }
    assert_includes @template, 'strict cleanup completed after both success and failure paths'
    assert_includes @template, 'Any incomplete cleanup, mismatched hash, unexpected engine version, secret exposure'
  end

  def test_governance_and_product_boundaries_remain_open
    %w[
      G0\ closure G3\ closure product-owner\ acceptance cashier/revenue\ domain\ acceptance
      finance-accounting\ acceptance treasury\ acceptance facility\ acceptance UAT hosted\ migration
      deployment production\ readiness SIMRS\ Sahabat\ parity\ completion
    ].each { |boundary| assert_includes @template, boundary.tr('\\', '') }
    assert_includes @template, 'This record is local engineering portability evidence only.'
    assert_includes @template, 'not** G0 closure'
  end
end
