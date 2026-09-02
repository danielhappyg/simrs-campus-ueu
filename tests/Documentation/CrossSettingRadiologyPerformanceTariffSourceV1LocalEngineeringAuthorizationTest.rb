# frozen_string_literal: true

require 'minitest/autorun'

class CrossSettingRadiologyPerformanceTariffSourceV1LocalEngineeringAuthorizationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  AUTHORIZATION_PATH = 'docs/new-simrs-rebuild/phase-1/CROSS_SETTING_RADIOLOGY_PERFORMANCE_TARIFF_SOURCE_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md'

  def setup
    @authorization = File.read(File.join(ROOT, AUTHORIZATION_PATH), encoding: Encoding::UTF_8)
  end

  def test_scope_uses_performance_as_the_only_charge_trigger
    %w[PAR-FIN-001 PAR-FIN-002 PAR-ADM-015 PAR-ADM-011 PAR-ADM-018 PAR-ADM-019 E2E-14].each do |reference|
      assert_includes @authorization, "`#{reference}`"
    end

    assert_includes @authorization, 'The sole V1 charge trigger is the immutable `radiology_performances.performed_at` fact.'
    assert_includes @authorization, 'Ordering, report Draft, report verification, verified amendment, and acknowledgement are not charge triggers.'
    assert_includes @authorization, 'One performance may produce at most one positive charge source.'
    assert_includes @authorization, 'Start empty.'
    assert_includes @authorization, 'No commit, push, hosted migration, or deployment is authorized'
    assert_includes @authorization, 'Owner and parity acceptance remain open.'
  end

  def test_clinical_workflow_is_never_blocked_by_finance_gaps
    assert_includes @authorization, 'Clinical safety and continuity take precedence over financial configuration.'
    assert_includes @authorization, 'never blocks radiology order creation, performance recording, report work, amendment, acknowledgement, encounter care, or clinical/RMIK closure'
    assert_includes @authorization, 'No radiology controller or clinical route writes finance rows.'
    assert_includes @authorization, '`radiology.orders.perform`'
    assert_includes @authorization, 'remain clinically available without any finance-steward binding or active tariff'
  end

  def test_binding_is_explicit_effective_dated_and_never_inferred
    assert_includes @authorization, '(radiology_master_version_public_id, radiology_master_content_digest, care_setting)'
    assert_includes @authorization, '`finance_radiology_tariff_bindings`'
    assert_includes @authorization, '`finance_radiology_tariff_binding_versions`'
    assert_includes @authorization, '`finance_radiology_tariff_operation_receipts`'
    assert_includes @authorization, 'No label/code inference is permitted.'
    assert_includes @authorization, 'half-open interval `[effective_from, next_effective_from)`'
    assert_includes @authorization, 'The head records the latest authored binding version, which may be future-dated.'
    assert_includes @authorization, 'It does not inherit the old binding implicitly.'
    assert_includes @authorization, 'service domain `RADIOLOGY`'
  end

  def test_service_date_and_historical_tariff_semantics_are_closed
    assert_includes @authorization, 'The service date is the application-timezone calendar date of `performed_at`.'
    assert_includes @authorization, 'never order date, synchronization date, report date, bill-issue date, current date, or latest-authored head state'
    assert_includes @authorization, 'Retiring or revising a tariff, catalogue, component, group, radiology master, or binding never rewrites an already materialized source event or issued bill version.'
    assert_includes @authorization, 'Historical version references remain readable.'
    assert_includes @authorization, 'current-tariff substitution'
  end

  def test_source_snapshot_is_immutable_typed_and_unique_per_performance
    assert_includes @authorization, '`finance_radiology_source_events`'
    assert_includes @authorization, 'unique so one performance creates at most one source'
    assert_includes @authorization, 'the selected `finance_radiology_tariff_binding_versions` row'
    assert_includes @authorization, 'the selected `finance_tariff_item_versions` row'
    assert_includes @authorization, 'quantity `1`'
    assert_includes @authorization, 'positive integer-rupiah unit amount'
    assert_includes @authorization, 'The source snapshot is immutable after creation.'
    assert_includes @authorization, 'requires exactly one typed source foreign key'
    assert_includes @authorization, 'Radiology cannot masquerade as pharmacy.'
  end

  def test_readiness_allows_valid_sync_but_issue_fails_closed
    assert_includes @authorization, '`FinanceSourceReadinessProjection`'
    assert_includes @authorization, '`Kesiapan Sumber Biaya`'
    %w[SIAP_DISINKRONKAN TERSINKRONISASI TARIF_BELUM_DIPETAKAN TARIF_TIDAK_EFEKTIF KONTEKS_TIDAK_COCOK BUKTI_TIDAK_KONSISTEN].each do |state|
      assert_includes @authorization, "`#{state}`"
    end

    assert_includes @authorization, 'may persist every individually valid source visible in its transaction snapshot'
    assert_includes @authorization, 'must refuse issuance while any performed radiology fact visible at the issue cutoff is unresolved'
    assert_includes @authorization, 'A performance committed after the snapshot is excluded from that version and becomes a later pending source'
    assert_includes @authorization, 'client state is never trusted'
  end

  def test_report_changes_never_duplicate_or_reverse_a_charge
    assert_includes @authorization, 'Report Drafts, verification, verified amendments, and acknowledgements do not create, replace, update, reverse, or duplicate a financial source.'
    assert_includes @authorization, 'V1 has no reversal.'
    assert_includes @authorization, 'separately authorized, append-only radiology performance-void or performance-correction fact'
    assert_includes @authorization, 'reverse the original snapshotted amount rather than re-resolving a current tariff'
  end

  def test_exact_roles_and_least_privilege_do_not_bypass_domains
    assert_includes @authorization, 'Exact `finance_steward` (`Pengelola Tarif`)'
    assert_includes @authorization, 'Exact `cashier`'
    assert_includes @authorization, 'Exact `radiology_technologist`'
    assert_includes @authorization, 'Exact `physician` and exact `radiologist`'
    assert_includes @authorization, 'Exact `rmik`'
    assert_includes @authorization, 'Exact `admin`'
    assert_includes @authorization, 'mixed-role account does not bypass any exact-role boundary'
    assert_includes @authorization, 'Controllers check capability before route-resource lookup.'
    assert_includes @authorization, 'least privileges required by governed services'
  end

  def test_idempotency_concurrency_audit_reset_and_recovery_are_required
    assert_includes @authorization, 'Same-key/same-payload returns the retained result.'
    assert_includes @authorization, 'unique performance foreign key and exact canonical source digest'
    assert_includes @authorization, 'one durable source and one replay/reconciliation result'
    assert_includes @authorization, 'never mixes identities or amounts'
    assert_includes @authorization, 'Required success-audit failure rolls back its business mutation.'
    assert_includes @authorization, 'Ordinary update/delete/truncate and direct SQL writes are refused.'
    assert_includes @authorization, 'while preserving required audit evidence'
    assert_includes @authorization, 'one-performance/one-source reconciliation'
    assert_includes @authorization, 'issue version with an unresolved in-cutoff performance'
  end

  def test_cross_engine_and_frontend_evidence_are_explicit
    assert_includes @authorization, 'two-process PostgreSQL/MySQL tests'
    assert_includes @authorization, 'disposable PostgreSQL 17 and MySQL 8.4 evidence'
    assert_includes @authorization, 'least-privilege runtime grants'
    assert_includes @authorization, 'Exact-engine evidence must start truthfully as not run.'
    assert_includes @authorization, 'SQLite feature results do not substitute'
    assert_includes @authorization, 'It does not prove that any entered tariff is a real or approved hospital price.'
    assert_includes @authorization, 'error summaries receive focus'
    assert_includes @authorization, 'accessible live region'
    assert_includes @authorization, 'at least 44-pixel targets'
  end

  def test_rejected_alternatives_and_exclusions_remain_honest
    alternatives = @authorization.split('## Options considered and rejected alternatives', 2).last
    assert_includes alternatives, 'Match radiology and tariff codes or labels'
    assert_includes alternatives, 'Charge when the physician orders'
    assert_includes alternatives, 'Charge when a report is verified or acknowledged'
    assert_includes alternatives, 'Write the charge inside the technologist performance transaction'
    assert_includes alternatives, 'Let an incomplete bill issue with an unresolved radiology gap'
    assert_includes alternatives, 'Store only an untyped polymorphic source reference'
    assert_includes alternatives, 'Seed plausible prices or a default binding'

    exclusions = @authorization.split('## Explicit exclusions', 2).last
    %w[PACS RIS DICOM LIS BPJS VClaim SATUSEHAT].each do |excluded|
      assert_includes exclusions, excluded
    end
    assert_includes exclusions, 'seeded/default/inferred prices'
    assert_includes exclusions, 'performance void, reversal'
    assert_includes exclusions, 'laboratory charge admission'
    assert_includes exclusions, 'accommodation/bed-day calculation'
    assert_includes exclusions, 'commit; push; deployment; or hosted migration'
  end
end
