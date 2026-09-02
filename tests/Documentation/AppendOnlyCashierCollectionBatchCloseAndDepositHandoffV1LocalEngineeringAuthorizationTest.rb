# frozen_string_literal: true

require 'minitest/autorun'

class AppendOnlyCashierCollectionBatchCloseAndDepositHandoffV1LocalEngineeringAuthorizationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PATH = File.join(
    ROOT,
    'docs/new-simrs-rebuild/phase-1/APPEND_ONLY_CASHIER_COLLECTION_BATCH_CLOSE_AND_DEPOSIT_HANDOFF_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md'
  )

  def setup
    @authorization = File.binread(PATH)
  end

  def test_product_owner_authority_and_coverage_remain_bounded
    assert_includes @authorization, 'product-owner locally authorized for bounded implementation and engineering verification'
    assert_includes @authorization, '`PAR-FIN-012`'
    assert_includes @authorization, '`E2E-14`'
    assert_includes @authorization, 'does not decide or claim `PAR-FIN-015`'
    assert_includes @authorization, 'not cashier/revenue, finance-accounting, treasury, facility, domain, or parity acceptance'
    assert_includes @authorization, 'does not close G0 or G3'
    assert_includes @authorization, 'Commit, push, hosted migration, and deployment remain outside this authorization.'
  end

  def test_lifecycle_and_append_only_evidence_are_closed
    %w[OPEN CLOSE_REQUESTED RECOUNT_SUBMITTED CLOSE_VERIFIED DEPOSIT_HANDOFF_CREATED].each do |state|
      assert_includes @authorization, state
    end
    %w[OPEN RECOUNT_REQUIRED AWAITING_SUPERVISOR VERIFIED HANDED_OFF].each do |state|
      assert_includes @authorization, "`#{state}`"
    end
    assert_includes @authorization, 'No state may be backdated, overwritten, deleted, reopened, or skipped.'
    assert_includes @authorization, 'Pre-migration settlements remain truthful legacy evidence'
    assert_includes @authorization, "It must never be labelled `Setoran Diterima`, `Diterima Treasury`"
  end

  def test_roles_capabilities_and_authorization_separate_duties
    %w[cashier cashier_supervisor finance_steward admin].each do |role|
      assert_includes @authorization, "Exact `#{role}`"
    end
    %w[
      finance.cashier-collection.view
      finance.cashier-collection.open
      finance.cashier-collection.close-request
      finance.cashier-collection.recount
      finance.cashier-collection.verify
      finance.cash-deposit-handoff.view
      finance.cash-deposit-handoff.create
    ].each { |capability| assert_includes @authorization, "`#{capability}`" }
    assert_includes @authorization, 'may never verify it, even if the account later gains another role'
    assert_includes @authorization, 'Mixed-role accounts fail closed.'
    assert_includes @authorization, 'Authorization occurs before route-resource lookup'
  end

  def test_exact_equation_corrections_and_freeze_are_unambiguous
    assert_includes @authorization, 'expected batch net cash'
    assert_includes @authorization, 'sum(amount of all retained exact CASH settlements bound to the frozen batch)'
    assert_includes @authorization, 'sum(original settlement amounts in that batch whose correction reached REFUND_COMPLETED before freeze)'
    assert_includes @authorization, '`REFUND_APPROVED` does not reduce expected cash.'
    assert_includes @authorization, '`CORRECTION_REQUESTED` or `REFUND_APPROVED` case blocks close'
    assert_includes @authorization, 'A non-zero variance yields `RECOUNT_REQUIRED`'
    assert_includes @authorization, 'no late settlement or refund may enter the frozen batch'
    assert_includes @authorization, 'After handoff, they use reason `cash_handoff_exists`.'
  end

  def test_idempotency_audit_locking_and_real_races_are_required
    %w[
      FINANCE_CASHIER_COLLECTION_OPEN
      FINANCE_CASHIER_COLLECTION_CLOSE_REQUEST
      FINANCE_CASHIER_COLLECTION_RECOUNT
      FINANCE_CASHIER_COLLECTION_VERIFY
      FINANCE_CASH_DEPOSIT_HANDOFF_CREATE
    ].each { |operation| assert_includes @authorization, "`#{operation}`" }
    assert_includes @authorization, 'Same key and same payload returns the same retained result'
    assert_includes @authorization, 'audit failure rolls back every new row and coordination-slot change'
    assert_includes @authorization, 'cashier active slot / collection batch'
    assert_includes @authorization, 'settlement-versus-close race'
    assert_includes @authorization, 'refund-completion-versus-close race'
    assert_includes @authorization, 'never create two active slots'
  end

  def test_indonesian_ui_recovery_and_exact_engine_evidence_are_explicit
    %w[Batch\ Penerimaan\ Kas Buka\ Batch Ajukan\ Tutup\ Batch Catat\ Hitung\ Ulang Serahkan\ Setoran Verifikasi\ Tutup\ Batch Bukti\ Penyerahan\ Setoran].each do |label|
      assert_includes @authorization, label.tr('\\', '')
    end
    assert_includes @authorization, 'Menunggu penerimaan treasury'
    assert_includes @authorization, 'Synthetic reset removes collection operation receipts, handoffs, batch events, membership rows, active slots, and batch headers'
    assert_includes @authorization, 'Migration rollback refuses while any batch'
    assert_includes @authorization, 'Exact PostgreSQL 17 and MySQL 8.4 rehearsal'
    assert_includes @authorization, 'real settlement-versus-close database wait'
    assert_includes @authorization, 'real refund-versus-close database wait'
    assert_includes @authorization, 'third-connection membership and net-cash readback'
    assert_includes @authorization, 'One continuous local `E2E-14` journey'
  end

  def test_treasury_accounting_claim_and_delivery_exclusions_are_explicit
    %w[
      treasury\ acceptance bank\ reconciliation partial\ payment receivable post-handoff\ refund
      accounting\ journal revenue\ recognition claim BPJS SATUSEHAT real\ patient secrets
      commit push hosted\ migration deployment
    ].each { |excluded| assert_includes @authorization.downcase, excluded.tr('\\', '').downcase }
    assert_includes @authorization, 'must not be described as treasury-accepted, bank-reconciled, journal-posted, revenue-recognized'
  end
end
