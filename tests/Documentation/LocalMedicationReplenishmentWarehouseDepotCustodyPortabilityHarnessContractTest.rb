# frozen_string_literal: true

require 'minitest/autorun'
require 'rbconfig'

class LocalMedicationReplenishmentWarehouseDepotCustodyPortabilityHarnessContractTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  SCRIPT_PATH = 'scripts/rehearse-local-medication-replenishment-warehouse-depot-custody-portability.rb'
  SCRIPT = File.join(ROOT, SCRIPT_PATH)
  EVIDENCE_KIND = 'SIMRS_LOCAL_MEDICATION_REPLENISHMENT_WAREHOUSE_DEPOT_CUSTODY_PORTABILITY'
  EXECUTION_STATE = 'READY_NOT_RUN'
  ENGINES = %w[postgresql17 mysql8411].freeze
  POSTGRES_VERSION = '17.10'
  MYSQL_VERSION = '8.4.11'
  CONFIRMATION_ENV = 'SIMRS_MEDICATION_REPLENISHMENT_WAREHOUSE_DEPOT_PORTABILITY_CONFIRM'
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_MEDICATION_REPLENISHMENT_WAREHOUSE_DEPOT'

  EXPECTED_SCENARIOS = %w[
    fresh-migration failed-install-guard-reapply empty-down-reapply
    constraints-and-append-only-triggers shortened-cross-engine-identifier-inventory
    exact-least-privilege-runtime-grants runtime-reset-bypass-denial
    supplier-medicine-depot-version-binding-and-retirement
    purchase-order-lifecycle-and-independent-approval purchase-order-role-separation-and-route-denial
    approved-po-exact-receipt-binding partial-full-receipt-and-variance-quarantine
    duplicate-receipt-reference-and-over-receipt-refusal paired-fefo-dispatch-and-transit-conservation
    destination-accept-reject-full-transfer existing-pharmacy-consumption-after-accepted-stock
    supplier-return-linked-retained-receipt unit-return-linked-accepted-transfer
    append-only-correction-independent-review stock-card-and-custody-conservation-reconciliation
    idempotency-replay-and-conflict competing-receipts-real-database-wait
    dispatch-versus-pharmacy-handover-real-database-wait accept-versus-reject-real-race
    third-connection-custody-control-total-readback audit-failure-atomic-rollback
    tamper-recovery-and-bounded-reset populated-migration-rollback-refusal strict-cleanup
  ].freeze

  def test_future_harness_exists_and_is_ruby_syntax_valid
    assert File.file?(SCRIPT), "readiness harness is missing: #{SCRIPT_PATH}"
    assert system(RbConfig.ruby, '-c', SCRIPT, out: File::NULL, err: File::NULL)
  end

  def test_future_harness_declares_exact_engine_and_not_run_contract
    source = File.read(SCRIPT, encoding: Encoding::UTF_8)
    assert_includes source, EVIDENCE_KIND
    assert_includes source, EXECUTION_STATE
    assert_includes source, POSTGRES_VERSION
    assert_includes source, MYSQL_VERSION
    assert_includes source, CONFIRMATION_ENV
    assert_includes source, CONFIRMATION
    assert_includes source, 'Local domain,'
    assert_includes source, 'sources exist'
    assert_includes source, 'security-corrected inventory, runtime grants, and exact-engine worker are not'
    assert_includes source, 'frozen or bound here'
    EXPECTED_SCENARIOS.each { |scenario| assert_includes source, scenario }
  end

  def test_future_harness_must_bind_template_authorization_and_strict_cleanup
    source = File.read(SCRIPT, encoding: Encoding::UTF_8)
    %w[
      application_source_sha256 worker_source_sha256 scenario_catalog_sha256
      runtime_grant_catalog_sha256 sqlite_gate_catalog_sha256 command_catalog_sha256
      mode: 'wx' perm: 0o600 cleanup! strict
      owner_acceptance_claim g0_claim g3_claim
    ].each { |binding| assert_includes source, binding }
    assert_includes source, 'T1_LOCAL_MEDICATION_REPLENISHMENT_WAREHOUSE_DEPOT_CUSTODY_EVIDENCE_TEMPLATE_2026-09-03.md'
    assert_includes source, 'MEDICATION_REPLENISHMENT_AND_WAREHOUSE_DEPOT_CUSTODY_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md'
    refute_match(/git\s+(?:add|commit|push)/, source)
    refute_match(/(?:vercel|supabase)\s+(?:deploy|link|migration|db)/i, source)
  end
end
