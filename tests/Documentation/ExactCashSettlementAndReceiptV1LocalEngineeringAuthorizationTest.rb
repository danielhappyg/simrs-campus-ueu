# frozen_string_literal: true

require 'minitest/autorun'

class ExactCashSettlementAndReceiptV1LocalEngineeringAuthorizationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PATH = File.join(
    ROOT,
    'docs/new-simrs-rebuild/phase-1/EXACT_CASH_SETTLEMENT_AND_RECEIPT_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md'
  )

  def setup
    @authorization = File.read(PATH, encoding: 'UTF-8')
  end

  def test_bounded_local_authorization_and_truthful_status_are_explicit
    assert_includes @authorization, 'locally authorized for bounded implementation and engineering verification'
    assert_includes @authorization, '`APP_MODE=SIMULATION`'
    assert_includes @authorization, '`APP_SYNTHETIC_ONLY=true`'
    assert_includes @authorization, 'does not close G0 or G3'
    assert_includes @authorization, 'ordinary cashier screens should use normal Indonesian hospital workflow language'
    assert_includes @authorization, 'does not confer facility or finance-owner acceptance'
  end

  def test_exact_amount_and_eligibility_are_closed
    assert_includes @authorization, 'amount is never entered by the cashier'
    assert_includes @authorization, 'exact locked outstanding balance in integer rupiah'
    assert_includes @authorization, 'current cumulative bill-version net amount minus every earlier retained settlement'
    assert_includes @authorization, 'only eligible V2 settlement is Rp5,000'
    assert_includes @authorization, '`refund_required`'
    %w[ISSUED_CURRENT OPEN_NO_VERSION NEW_SOURCE_PENDING].each do |state|
      assert_includes @authorization, "`#{state}`"
    end
    assert_includes @authorization, 'source synchronization finds no new, unresolved, duplicated, or corrupt source'
    assert_includes @authorization, 'no retained settlement already owns that bill version'
    assert_includes @authorization, 'fails atomically without a settlement or receipt'
  end

  def test_business_receipt_is_distinct_from_replay_receipt
    assert_includes @authorization, '`finance_cash_settlements` is append-only business evidence'
    assert_includes @authorization, '`finance_settlement_operation_receipts` is separate technical replay evidence'
    assert_includes @authorization, 'It is not the patient-facing receipt number.'
    assert_includes @authorization, 'never reads a mutable current tariff to reconstruct the amount'
    assert_includes @authorization, 'never reads a mutable current tariff or current user display name'
    assert_includes @authorization, 'Missing or corrupt linkage fails closed'
    assert_includes @authorization, 'Audit failure rolls back all business mutation.'
  end

  def test_roles_idempotency_concurrency_and_recovery_are_required
    %w[cashier rmik finance_steward admin].each do |role|
      assert_includes @authorization, "Exact `#{role}`"
    end
    %w[finance.settlement.view finance.settlement.create finance.receipt.view].each do |capability|
      assert_includes @authorization, "`#{capability}`"
    end
    assert_includes @authorization, 'Same-key/same-payload replay returns the same settlement and receipt'
    assert_includes @authorization, 'Same-key/different-payload fails as a conflict'
    assert_includes @authorization, 'never two settlements'
    assert_includes @authorization, 'third connection must prove the durable single-row result'
    assert_includes @authorization, 'Synthetic reset deletes settlement technical receipts and settlements before dependent bill versions'
    assert_includes @authorization, '`current_version_net - prior_settlements = exact_settlement_amount`'
  end

  def test_explicit_exclusions_prevent_scope_creep
    exclusions = @authorization.split('## Explicit V1 exclusions', 2).last
    %w[partial overpayment card refund void BPJS VClaim E-Klaim SATUSEHAT ERP].each do |term|
      assert_includes exclusions, term
    end
    assert_includes exclusions, 'real patient data'
    assert_includes exclusions, 'commit, push, hosted migration, or deployment'
    assert_includes @authorization, 'separate append-only compensation and receivable decisions'
  end
end
