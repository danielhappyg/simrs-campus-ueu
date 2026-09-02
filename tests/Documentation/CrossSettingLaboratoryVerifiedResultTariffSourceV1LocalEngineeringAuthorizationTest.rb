# frozen_string_literal: true

require 'minitest/autorun'

class CrossSettingLaboratoryVerifiedResultTariffSourceV1LocalEngineeringAuthorizationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PATH = File.join(
    ROOT,
    'docs/new-simrs-rebuild/phase-1/CROSS_SETTING_LABORATORY_VERIFIED_RESULT_TARIFF_SOURCE_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md',
  )

  def setup
    @authorization = File.read(PATH, encoding: Encoding::UTF_8)
  end

  def test_authority_and_claim_boundaries_are_explicit
    assert_includes @authorization, 'application and exact-engine evidence not yet implemented'
    %w[PAR-CLN-006 PAR-FIN-001 PAR-FIN-002 PAR-ADM-011 PAR-ADM-018 PAR-ADM-019].each do |capability|
      assert_includes @authorization, "`#{capability}`"
    end
    assert_includes @authorization, '`APP_MODE=SIMULATION`'
    assert_includes @authorization, '`APP_SYNTHETIC_ONLY=true`'
    assert_includes @authorization, 'does not confer laboratory-owner, finance-owner, cashier, clinical, RMIK, or parity acceptance'
    assert_includes @authorization, 'It authorizes no commit, push, hosted migration, or deployment.'
    assert_includes @authorization, 'G0/G3 closure'
    assert_includes @authorization, 'This authorization is not implementation evidence.'
  end

  def test_only_original_verified_result_is_the_completed_service_trigger
    assert_includes @authorization, 'state is `VERIFIED` and whose `base_verified_version_id` is `NULL`'
    assert_includes @authorization, 'Its `verified_at` timestamp supplies the service date.'
    assert_includes @authorization, 'Specimen collection, receipt, acceptance, rejection, recollection, result `DRAFT`, `AMENDED_VERIFIED`, critical communication, acknowledgement, and pre-verification cancellation are not charge triggers.'
    assert_includes @authorization, 'one positive source, tied to the original verified result'
    assert_includes @authorization, 'V1 has no reversal.'
  end

  def test_binding_and_effective_date_contract_is_closed
    assert_includes @authorization, '(laboratory_master_version_public_id, laboratory_master_content_digest, care_setting)'
    assert_includes @authorization, 'Free-text, code, label, specimen name, component name, result value, or reference-range matching is never a binding.'
    assert_includes @authorization, 'half-open interval `[effective_from, next_effective_from)`'
    assert_includes @authorization, 'service domain `LABORATORY`'
    assert_includes @authorization, 'Current-tariff substitution is forbidden.'
  end

  def test_typed_source_is_unique_and_excludes_clinical_payload
    assert_includes @authorization, '`finance_laboratory_source_events`'
    assert_includes @authorization, 'Both the original verified-result foreign key and laboratory-order foreign key are unique'
    assert_includes @authorization, 'quantity `1`'
    assert_includes @authorization, 'positive integer-rupiah'
    assert_includes @authorization, 'must not copy result values, reference ranges, critical flags, clinical questions, communication notes'
    assert_includes @authorization, 'Exactly one typed source foreign key must be non-null'
    assert_includes @authorization, 'Laboratory cannot masquerade as pharmacy or radiology.'
  end

  def test_readiness_and_cutoff_fail_closed_without_blocking_clinical_work
    %w[SIAP_DISINKRONKAN TERSINKRONISASI TARIF_BELUM_DIPETAKAN TARIF_TIDAK_EFEKTIF KONTEKS_TIDAK_COCOK BUKTI_TIDAK_KONSISTEN].each do |state|
      assert_includes @authorization, "`#{state}`"
    end
    assert_includes @authorization, 'never block ordering, specimen work, result verification, amendment, critical communication, acknowledgement, encounter care, or RMIK closure'
    assert_includes @authorization, 'Bill issuance must refuse while any original verified result visible at the cutoff lacks exactly one reconcilable source and charge.'
    assert_includes @authorization, 'A verification committed after the stable cutoff is excluded from that version'
  end

  def test_exact_roles_and_non_bypass_capabilities_are_explicit
    assert_includes @authorization, 'Exact `finance_steward` (`Pengelola Tarif`)'
    assert_includes @authorization, 'Exact `cashier`'
    assert_includes @authorization, 'Exact `laboratory_technologist`'
    assert_includes @authorization, 'Exact `physician`, `nurse`, `rmik`, and `admin`'
    assert_includes @authorization, 'mixed-role identity never bypasses an exact-role boundary'
    assert_includes @authorization, '`finance.laboratory-tariff.view`'
    assert_includes @authorization, '`finance.laboratory-tariff.manage`'
  end

  def test_three_real_races_and_lock_direction_are_required
    assert_includes @authorization, 'The lock direction is encounter-first.'
    assert_includes @authorization, 'one `MATERIALIZED` and one `RECONCILED` outcome'
    assert_includes @authorization, 'one `APPLIED` result and one closed stale/concurrent `DENIED` result'
    assert_includes @authorization, 'Verification racing with synchronization'
    assert_includes @authorization, 'observe a real database wait using independent application processes'
    assert_includes @authorization, 'third-connection durable reconciliation'
  end

  def test_integrity_reset_recovery_and_evidence_gate_are_closed
    assert_includes @authorization, 'Required audit failure rolls back its business mutation.'
    assert_includes @authorization, 'one-result/one-source/one-charge cardinality'
    assert_includes @authorization, 'Migration rollback refuses while retained source, charge, binding, receipt, or audit evidence exists.'
    assert_includes @authorization, 'Exact-engine evidence still starts as `NOT RUN`'
    assert_includes @authorization, 'Source hashes are captured immediately before execution, checked again before publication'
    assert_includes @authorization, 'exactly 22 scenarios'
  end

  def test_exclusions_remain_explicit
    exclusions = @authorization.split('## Explicit exclusions', 2).last
    %w[LIS analyser BPJS VClaim SATUSEHAT].each { |term| assert_includes exclusions, term }
    assert_includes exclusions, 'real patient, laboratory, or financial data'
    assert_includes exclusions, 'inferred, seeded, default, or purportedly hospital-approved prices'
    assert_includes exclusions, 'hosted migration, browser UAT, owner/domain/parity acceptance, G0/G3 closure, commit, push, or deployment'
  end
end
