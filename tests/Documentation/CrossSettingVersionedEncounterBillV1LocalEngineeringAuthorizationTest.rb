# frozen_string_literal: true

require 'minitest/autorun'

class CrossSettingVersionedEncounterBillV1LocalEngineeringAuthorizationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  AUTHORIZATION_PATH = 'docs/new-simrs-rebuild/phase-1/CROSS_SETTING_VERSIONED_ENCOUNTER_BILL_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-01.md'

  def setup
    @authorization = File.read(File.join(ROOT, AUTHORIZATION_PATH), encoding: Encoding::UTF_8)
  end

  def test_scope_is_versioned_bills_without_payment_or_claim_overreach
    %w[PAR-FIN-001 PAR-FIN-002 E2E-14].each do |reference|
      assert_includes @authorization, "`#{reference}`"
    end

    assert_includes @authorization, 'bill assembly only'
    assert_includes @authorization, 'A bill is an immutable encounter charge statement.'
    assert_includes @authorization, 'not a tax invoice, payment receipt, claim, accounting posting'
    assert_includes @authorization, 'No commit, push, hosted migration, or deployment is authorized'
  end

  def test_only_existing_pharmacy_monetary_facts_are_admitted
    assert_includes @authorization, '`pharmacy_financial_source_events`'
    assert_includes @authorization, 'currently provide no authorized tariff or monetary source event'
    assert_includes @authorization, 'must not invent prices'
    assert_includes @authorization, '`CHARGE`'
    assert_includes @authorization, '`REVERSAL`'
    assert_includes @authorization, 'never updates, deletes, acknowledges, or otherwise writes back to pharmacy evidence'
  end

  def test_bill_versions_are_immutable_reconcilable_and_idempotent
    assert_match(/OPEN_NO_VERSION -> ISSUED_CURRENT -> NEW_SOURCE_PENDING/, @authorization)
    assert_includes @authorization, 'A new source event never edits an issued bill or line.'
    assert_includes @authorization, 'Floating-point money is prohibited.'
    assert_includes @authorization, '`gross + reversals = net`'
    assert_includes @authorization, 'Same retained source-set digest and same idempotency key replays the original issue result.'
    assert_includes @authorization, 'Issuing an identical new version without source-set change is denied.'
    assert_includes @authorization, 'A cancelled encounter or an encounter without at least one valid valued source cannot issue a bill.'
  end

  def test_exact_cashier_role_has_no_admin_or_mixed_role_bypass
    assert_includes @authorization, 'Exact `cashier`'
    assert_includes @authorization, 'Exact `admin` can provision the cashier role'
    assert_includes @authorization, 'cannot act as cashier'
    assert_includes @authorization, 'system-administrator flag or mixed-role account does not bypass'
    assert_includes @authorization, 'Capability checks happen before route-resource lookup'
    assert_includes @authorization, 'Unauthorized actors receive no bill, patient, encounter, or source existence disclosure.'
  end

  def test_concurrency_and_recovery_fail_closed
    assert_includes @authorization, 'one `APPLIED` and one `REPLAYED` outcome'
    assert_includes @authorization, 'source event committed after the finance snapshot'
    assert_includes @authorization, 'reset/recovery competition produces no partial version'
    assert_includes @authorization, 'Database guards refuse ordinary update/delete/truncate'
    assert_includes @authorization, 'Required success audit failure rolls back the whole operation.'
    assert_includes @authorization, 'Migration rollback refuses while business rows or correlated audit facts remain.'
  end

  def test_ui_is_indonesian_accessible_and_role_scoped
    %w[Daftar\ Tagihan Detail\ Tagihan\ Pasien Sumber\ Biaya Terbitkan\ Versi\ Tagihan Riwayat\ Versi].each do |label|
      assert_includes @authorization, "`#{label.tr('\\\\', ' ')}`"
    end

    assert_includes @authorization, 'error summaries receive focus'
    assert_includes @authorization, 'accessible live region'
    assert_includes @authorization, 'at least 44-pixel targets'
    assert_includes @authorization, 'No user-facing simulation or synthetic-data wording is added.'
  end

  def test_exclusions_keep_unresolved_finance_and_live_integrations_out
    exclusions = @authorization.split('## Explicit exclusions', 2).last

    %w[real\ patient\ data real\ prices tariff manual\ charges payment receivables settlement general\ ledger claim BPJS/VClaim/SATUSEHAT].each do |excluded|
      assert_includes exclusions, excluded.tr('\\\\', ' ')
    end
    assert_includes exclusions, 'commit; push; deployment; or hosted migration'
  end
end
