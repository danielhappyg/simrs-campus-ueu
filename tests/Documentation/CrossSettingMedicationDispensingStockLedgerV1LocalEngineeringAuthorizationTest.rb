# frozen_string_literal: true

require 'minitest/autorun'

class CrossSettingMedicationDispensingStockLedgerV1LocalEngineeringAuthorizationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  AUTHORIZATION_PATH = 'docs/new-simrs-rebuild/phase-1/CROSS_SETTING_MEDICATION_DISPENSING_STOCK_LEDGER_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-01.md'
  EVIDENCE_TEMPLATE_PATH = 'docs/operations/T1_LOCAL_CROSS_SETTING_PHARMACY_STOCK_EVIDENCE_TEMPLATE_2026-09-01.md'

  def setup
    @authorization = File.read(File.join(ROOT, AUTHORIZATION_PATH), encoding: Encoding::UTF_8)
  end

  def test_scope_is_cross_setting_and_local_only
    %w[PAR-PHA-001 PAR-PHA-002 PAR-PHA-003 PAR-PHA-015 PAR-PHA-020 PAR-PWH-001].each do |requirement|
      assert_includes @authorization, "`#{requirement}`"
    end

    assert_includes @authorization, 'outpatient, emergency, and inpatient encounters'
    assert_includes @authorization, 'No commit, push, hosted migration, or deployment is authorized'
    assert_includes @authorization, 'does not confer pharmacy, clinical, warehouse, finance, RMIK, or parity acceptance'
  end

  def test_actor_roles_do_not_create_admin_or_mixed_role_bypass
    %w[physician pharmacist pharmacy_technician pharmacy_inventory_controller nurse rmik admin].each do |role|
      assert_includes @authorization, "`#{role}`"
    end

    assert_includes @authorization, 'system-administrator flag does not bypass the exact-role boundary'
    assert_includes @authorization, 'checks capability before route-resource lookup'
    assert_includes @authorization, 'Unauthorized actors receive no resource-existence disclosure.'
  end

  def test_stock_and_money_contracts_are_reconcilable
    assert_match(/floating-point money is prohibited/i, @authorization)
    assert_includes @authorization, 'quantities are positive whole base units'
    assert_includes @authorization, 'negative available or quarantined balance is forbidden'
    assert_includes @authorization, 'expiry-null-last, expiry date, received time, lot code, row ID'
    assert_includes @authorization, 'atomic conditional decrements'
    assert_includes @authorization, 'equal-and-opposite reversal event'
    assert_includes @authorization, 'stock card is derived solely from immutable movement rows'
    assert_includes @authorization, 'A discrepancy is a hard integrity failure'
  end

  def test_lifecycle_has_verification_preparation_handover_partial_and_return
    assert_match(/DRAFT -> ORDERED -> VERIFIED -> PREPARED -> HANDED_OVER/, @authorization)
    assert_includes @authorization, 'pharmacist confirms identity/context'
    assert_includes @authorization, 'Preparation records the proposed actual lots and quantities but does not reduce stock.'
    assert_includes @authorization, 'Partial dispensing is allowed only when the pharmacist explicitly records the unfilled quantity and reason.'
    assert_includes @authorization, '`RETURN_TO_STOCK`'
    assert_includes @authorization, '`QUARANTINE`'
    assert_includes @authorization, '`DESTROYED_OR_NOT_RETURNABLE`'
  end

  def test_encounter_and_location_dependencies_fail_closed
    assert_includes @authorization, 'Pre-clinical encounter cancellation is denied after any medication Draft'
    assert_includes @authorization, 'RJ clinical/RMIK closure, IGD disposition completion, and RI discharge fail closed'
    assert_includes @authorization, 'IGD-to-RI handoff never reparents a prescription.'
    assert_includes @authorization, 'Prepared prescriptions block transfer'
    assert_includes @authorization, 'Closed or cancelled encounters reject every new medication mutation.'
  end

  def test_verification_and_explicit_exclusions_preserve_safety_boundary
    assert_includes @authorization, 'two-process concurrency tests'
    assert_includes @authorization, 'PostgreSQL 17 and MySQL 8.4 evidence'

    exclusions = @authorization.split('## Explicit exclusions', 2).last
    %w[BPJS/VClaim/SATUSEHAT procurement payment claim compounding chemotherapy narcotic medication administration invoice].each do |exclusion|
      assert_includes exclusions, exclusion
    end
    assert_includes exclusions, 'real patient data'
    assert_includes exclusions, 'commit, push, deployment, or hosted migration'
  end

  def test_phase_readme_tracks_verified_complete_engineering_without_overclaim
    readme = File.read(File.join(ROOT, 'docs/new-simrs-rebuild/phase-1/README.md'), encoding: Encoding::UTF_8)

    assert_includes readme, File.basename(AUTHORIZATION_PATH)
    assert_includes readme, 'Local implementation and PostgreSQL 17.10/MySQL 8.4.11 exact-engine engineering verification complete'
    assert_includes readme, 'T1_LOCAL_CROSS_SETTING_PHARMACY_STOCK_EVIDENCE_2026-09-01.md'
    assert_includes readme, 'pharmacy, clinical, warehouse, finance, RMIK, and parity acceptance open'
    assert_includes readme, 'no live integration, commit, hosted migration, or deployment authority'
  end

  def test_exact_engine_evidence_starts_truthfully_not_run
    template = File.read(File.join(ROOT, EVIDENCE_TEMPLATE_PATH), encoding: Encoding::UTF_8)

    assert_includes template, 'Status: **NOT RUN — TEMPLATE ONLY**'
    assert_includes template, '| PostgreSQL | 17.10 | NOT RUN |'
    assert_includes template, '| MySQL | 8.4.11 | NOT RUN |'
    assert_includes template, '`database/migrations/2026_09_01_000600_create_cross_setting_pharmacy_tables.php`'
    assert_includes template, 'Two-process competing handover and no-negative-stock proof'
    assert_includes template, 'Stock movement, lot balance, and charge-source reconciliation'
    assert_includes template, 'The harness is `READY_NOT_RUN`'
    assert_includes template, 'Do not execute or treat these commands as evidence until the focused backend and full suite are green'
    assert_includes template, 'It must not contact suppliers, payment services, BPJS, VClaim, SATUSEHAT'
  end
end
