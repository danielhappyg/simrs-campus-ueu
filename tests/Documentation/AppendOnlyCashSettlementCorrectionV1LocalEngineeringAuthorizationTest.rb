# frozen_string_literal: true

require 'minitest/autorun'

class AppendOnlyCashSettlementCorrectionV1LocalEngineeringAuthorizationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PATH = File.join(
    ROOT,
    'docs/new-simrs-rebuild/phase-1/APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md'
  )

  def setup
    @authorization = File.binread(PATH)
  end

  def test_scope_and_authority_remain_bounded
    assert_includes @authorization, 'locally authorized for bounded implementation and engineering verification'
    %w[PAR-FIN-001 PAR-FIN-002 E2E-14].each { |id| assert_includes @authorization, "`#{id}`" }
    assert_includes @authorization, 'teaching-system safety decision, not a claim'
    assert_includes @authorization, 'does not close G0 or G3'
    assert_includes @authorization, 'commit, push, hosted migration, or deployment'
  end

  def test_original_evidence_is_never_mutated
    assert_includes @authorization, 'immutable correction case plus an append-only event stream'
    assert_includes @authorization, 'never updated, deleted, hidden, renumbered, or overwritten'
    %w[REVIEW_REJECTED REFUND_APPROVED REFUND_COMPLETED].each do |event|
      assert_includes @authorization, "`#{event}`"
    end
    assert_includes @authorization, 'No actor enters an amount.'
    assert_includes @authorization, 'A new correction attempt for the same original settlement is refused.'
  end

  def test_roles_enforce_separation_of_duties
    %w[cashier cashier_supervisor finance_steward admin].each do |role|
      assert_includes @authorization, "Exact `#{role}`"
    end
    %w[
      finance.settlement-correction.view
      finance.settlement-correction.request
      finance.settlement-correction.review
      finance.settlement-refund.complete
      finance.settlement-correction.receipt.view
    ].each { |capability| assert_includes @authorization, "`#{capability}`" }
    assert_includes @authorization, 'may never review, approve, reject, or complete their own case'
    assert_includes @authorization, 'Authorization occurs before route-resource lookup'
  end

  def test_net_cash_and_replacement_are_unambiguous
    assert_includes @authorization, 'net collected for a bill version'
    assert_includes @authorization, 'sum(all retained exact cash settlements)'
    assert_includes @authorization, 'sum(original amounts whose correction case reached REFUND_COMPLETED)'
    assert_includes @authorization, '`REFUND_APPROVED` alone does not reduce net collected cash'
    assert_includes @authorization, 'becomes eligible only after `REFUND_COMPLETED`'
    assert_includes @authorization, 'must be replaced by guarded concurrency plus exact recovery invariants'
  end

  def test_failure_recovery_and_exclusions_are_explicit
    assert_includes @authorization, 'Same key and same canonical payload replays the same retained result'
    assert_includes @authorization, 'audit failure rolls back every new row'
    assert_includes @authorization, 'Migration rollback refuses while any correction case'
    assert_includes @authorization, 'PostgreSQL 17 and MySQL 8.4 rehearsal'
    %w[partial refund split tender receivable cashier shift closing treasury deposit claim BPJS SATUSEHAT].each do |excluded|
      assert_includes @authorization, excluded
    end
  end
end
