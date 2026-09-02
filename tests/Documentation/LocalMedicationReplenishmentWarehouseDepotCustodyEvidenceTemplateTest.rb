# frozen_string_literal: true

require 'minitest/autorun'

class LocalMedicationReplenishmentWarehouseDepotCustodyEvidenceTemplateTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  TEMPLATE_PATH = File.join(
    ROOT,
    'docs/operations/T1_LOCAL_MEDICATION_REPLENISHMENT_WAREHOUSE_DEPOT_CUSTODY_EVIDENCE_TEMPLATE_2026-09-03.md'
  )

  SCENARIOS = %w[
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

  def setup
    @template = File.binread(TEMPLATE_PATH)
  end

  def test_template_is_ready_not_run_and_not_execution_evidence
    assert_includes @template, 'Status: **READY / NOT RUN**'
    assert_includes @template, 'This template is not execution evidence.'
    assert_includes @template, 'Final paired status: **READY / NOT RUN**'
    refute_includes @template, 'Final paired status: **PASS**'
  end

  def test_exact_engines_artifacts_and_file_modes_are_bound
    assert_includes @template, '| PostgreSQL | `17.10` | `NOT RUN`'
    assert_includes @template, '| MySQL | `8.4.11` | `NOT RUN`'
    assert_includes @template, 'two artifact paths must be distinct'
    assert_includes @template, 'mode `0700`'
    assert_includes @template, 'mode `0600`'
    assert_includes @template, 'Verify every hash from disk after strict cleanup.'
  end

  def test_authorization_harness_and_placeholder_bindings_are_explicit
    %w[
      MEDICATION_REPLENISHMENT_AND_WAREHOUSE_DEPOT_CUSTODY_V1
      MEDICATION_REPLENISHMENT_WAREHOUSE_DEPOT_PORTABILITY_CONFIRM
      YES_DISPOSABLE_LOCAL_MEDICATION_REPLENISHMENT_WAREHOUSE_DEPOT
      application_source_sha256 worker_source_sha256 scenario_catalog_sha256
      runtime_grant_catalog_sha256 sqlite_gate_catalog_sha256 command_catalog_sha256
    ].each { |value| assert_includes @template, value }
    assert_includes @template, 'TO BE FROZEN AND BOUND BY FUTURE HARNESS'
    assert_includes @template, 'SOURCE_PATHS'
    assert_includes @template, 'Any source change after the first engine run invalidates both artifacts'
  end

  def test_existing_local_source_does_not_become_exact_engine_or_hosted_evidence
    assert_includes @template, 'warehouse migration, service/model, authorization, guard, audit, and focused SQLite test sources now exist'
    assert_includes @template, '`READY / NOT RUN`'
    assert_includes @template, '`WAREHOUSE_SCHEMA_MIGRATION_ENABLED=false`'
    assert_includes @template, 'excludes both `2026_09_03` warehouse migrations from its hosted migration allowlist'
    assert_includes @template, 'IMPLEMENTED LOCALLY; TO BE FROZEN AND BOUND BY FUTURE HARNESS'
  end

  def test_closed_29_scenario_catalogue_is_present_once_and_not_run
    assert_equal 29, SCENARIOS.length
    assert_equal SCENARIOS.uniq, SCENARIOS
    SCENARIOS.each do |scenario|
      assert_equal 1, @template.scan("`#{scenario}`").length, "scenario must appear exactly once: #{scenario}"
    end
    assert_includes @template, 'Each engine artifact must contain this exact inventory in this order.'
    assert_includes @template, 'Every row begins `NOT RUN`'
    assert_includes @template, 'Sequential simulation is not acceptable.'
    assert_includes @template, 'third-connection scenario must independently reconcile'
  end

  def test_authorization_journey_and_ui_gate_are_bound
    %w[E2E-11 E2E-10 E2E-02 E2E-03 E2E-04 E2E-14 E2E-16 E2E-09].each do |id|
      assert_includes @template, "`#{id}`"
    end
    %w[Indonesian semantic keyboard focus announcements 44-pixel].each do |term|
      assert_includes @template.downcase, term.downcase
    end
    assert_includes @template, 'Focused warehouse/pharmacy feature and unit tests'
  end

  def test_role_separation_and_authorization_order_are_bound
    %w[
      procurement_officer procurement_approver warehouse_receiver warehouse_inventory_controller
      warehouse_inventory_supervisor pharmacy_inventory_controller pharmacist pharmacy_technician admin
      purchase-order create submit review receipt record transfer dispatch accept
      supplier/unit return correction request stock-card
    ].each { |term| assert_includes @template, term }
    assert_includes @template, 'Purchase-order creator and approver'
    assert_includes @template, 'source dispatcher and destination acceptor'
    assert_includes @template, 'correction requester and approver'
    assert_includes @template, 'before route-resource lookup'
    assert_includes @template, 'no resource-existence disclosure'
  end

  def test_cleanup_secrecy_and_nonclaims_are_explicit
    %w[
      password token API\ key connection\ string cookie secret raw\ SQL environment\ dump
      BPJS VClaim E-Klaim SATUSEHAT LIS PACS banking treasury
      hosted\ Supabase/Vercel hosted\ migration real\ patient real\ stock
      AP/accounts-payable accounting\ journal patient\ charge
      product-owner procurement pharmacy warehouse finance-accounting
    ].each { |term| assert_includes @template.downcase, term.downcase.gsub('\\', '') }
    %w[acceptance parity G0 G3 deployment].each { |term| assert_includes @template, term }
    assert_includes @template, 'Strict cleanup must be completed after success and failure paths'
    assert_includes @template, 'Any secret exposure, external access'
    assert_includes @template, 'future local engineering portability evidence only; it is not completed evidence'
  end
end
