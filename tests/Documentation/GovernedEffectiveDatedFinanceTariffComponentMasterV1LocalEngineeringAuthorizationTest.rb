# frozen_string_literal: true

require 'minitest/autorun'

class GovernedEffectiveDatedFinanceTariffComponentMasterV1LocalEngineeringAuthorizationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PATH = 'docs/new-simrs-rebuild/phase-1/GOVERNED_EFFECTIVE_DATED_FINANCE_TARIFF_COMPONENT_MASTER_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md'
  EVIDENCE_TEMPLATE_PATH = 'docs/operations/T1_LOCAL_GOVERNED_FINANCE_TARIFF_COMPONENT_MASTER_EVIDENCE_TEMPLATE_2026-09-02.md'

  def setup
    @authorization = File.read(File.join(ROOT, PATH), encoding: Encoding::UTF_8)
  end

  def test_scope_and_graph_boundary_are_exact
    %w[PAR-ADM-011 PAR-ADM-018 PAR-ADM-019 E2E-16 E2E-05 E2E-06 E2E-14].each do |id|
      assert_includes @authorization, "`#{id}`"
    end

    assert_includes @authorization, 'It starts empty.'
    assert_includes @authorization, 'creates no `finance_charge_events`'
    assert_includes @authorization, 'No commit, push, hosted migration, or deployment is authorized'
  end

  def test_financial_and_effective_date_rules_fail_closed
    assert_includes @authorization, 'amount must be a positive integer rupiah value'
    assert_includes @authorization, 'Floating-point money is prohibited.'
    assert_includes @authorization, 'Overlapping or ambiguous effective periods are forbidden'
    assert_includes @authorization, 'half-open interval `[effective_from, next_effective_from)`'
    assert_includes @authorization, 'head records the latest authored version, which may be future-dated'
    assert_includes @authorization, 'makes the tariff unselectable from that instant while preserving every earlier interval'
    assert_includes @authorization, 'Tariff selection is based on the clinical/service event date, never the bill-issue date.'
    assert_includes @authorization, 'historical service dates continue resolving to the retained historical version'
  end

  def test_upstream_retirement_and_future_activation_fail_closed
    assert_includes @authorization, 'requires an `ACTIVE` catalogue, component, and component group'
    assert_includes @authorization, 'Group retirement is denied while any active component depends on it.'
    assert_includes @authorization, 'Component retirement is denied while any current or future tariff item depends on it.'
    assert_includes @authorization, 'Catalogue retirement is denied while any current or future tariff item depends on it.'
    assert_includes @authorization, 'No future tariff activation may occur at or after an upstream retirement.'
    assert_includes @authorization, 'Historical version references remain valid and readable.'
  end

  def test_exact_roles_do_not_create_admin_or_mixed_role_bypass
    assert_includes @authorization, 'Exact `finance_steward` (`Pengelola Tarif`)'
    assert_includes @authorization, 'Exact `cashier` may view'
    assert_includes @authorization, 'Exact `admin` may provision or revoke the role'
    assert_includes @authorization, 'mixed-role account does not bypass the exact-role boundary'
    assert_includes @authorization, 'checks capability before route-resource lookup'
    assert_includes @authorization, 'Unauthorized actors receive no master, group, component, catalogue, or tariff existence disclosure.'
  end

  def test_history_recovery_and_verification_are_required
    assert_includes @authorization, 'versions, code reservations, and operation receipts are append-only'
    assert_includes @authorization, 'Required events cover create, revise, future activation, retirement, replay, stale denial, authorization denial, and integrity refusal'
    assert_includes @authorization, 'two-process PostgreSQL/MySQL concurrency tests'
    assert_includes @authorization, 'disposable PostgreSQL 17 and MySQL 8.4 evidence'
    assert_includes @authorization, 'strict cleanup and exact source bindings'
  end

  def test_ui_and_exclusions_are_hospital_like_but_honest
    assert_includes @authorization, '`Manajemen Data > Tarif & Komponen Biaya`'
    assert_includes @authorization, 'no user-facing simulation wording'
    assert_includes @authorization, 'supplies no sample amount'

    exclusions = @authorization.split('## Explicit exclusions', 2).last
    %w[BPJS VClaim SATUSEHAT LIS PACS/RIS payment claims].each do |item|
      assert_includes exclusions, item
    end
    assert_includes exclusions, 'seeded/default/inferred prices'
    assert_includes exclusions, 'commit; push; deployment; or hosted migration'
  end

  def test_exact_engine_evidence_starts_truthfully_not_run
    template = File.read(File.join(ROOT, EVIDENCE_TEMPLATE_PATH), encoding: Encoding::UTF_8)

    assert_includes template, 'Status: **NOT RUN — TEMPLATE ONLY**'
    assert_includes template, 'The harness is **NOT YET READY**.'
    assert_includes template, '| PostgreSQL | 17.10 | NOT RUN |'
    assert_includes template, '| MySQL | 8.4.11 | NOT RUN |'
    assert_includes template, 'Fresh migration starts with zero catalogues, groups, components, and tariffs'
    assert_includes template, 'Future-authored version leaves today’s version effective'
    assert_includes template, 'Upstream group/component/catalogue dependency retirement refusal'
    assert_includes template, 'It will not prove that any amount is a real or approved hospital price'
  end
end
