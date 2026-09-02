# frozen_string_literal: true

require 'minitest/autorun'

class InpatientAccommodationOccupancyDayTariffSourceV1LocalEngineeringAuthorizationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PATH = File.join(
    ROOT,
    'docs/new-simrs-rebuild/phase-1/INPATIENT_ACCOMMODATION_OCCUPANCY_DAY_TARIFF_SOURCE_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md',
  )

  def setup
    @authorization = File.read(PATH, encoding: Encoding::UTF_8)
  end

  def test_authority_and_claim_boundaries_are_explicit
    assert_includes @authorization, 'application and exact-engine evidence not yet implemented'
    %w[PAR-REG-001 PAR-ADM-009 PAR-ADM-033 PAR-FIN-001 PAR-FIN-002 E2E-14 E2E-16].each do |reference|
      assert_includes @authorization, "`#{reference}`"
    end
    assert_includes @authorization, '`APP_MODE=SIMULATION`'
    assert_includes @authorization, '`APP_SYNTHETIC_ONLY=true`'
    assert_includes @authorization, 'Affected-owner and parity acceptance remain open.'
    assert_includes @authorization, 'no real data, live integration, commit, push, hosted migration, or deployment'
    assert_includes @authorization, 'This authorization is not implementation evidence.'
    assert_includes @authorization, 'remain unimplemented and `NOT RUN`'
  end

  def test_only_closed_immutable_intervals_are_charge_eligible
    assert_includes @authorization, 'Only a **closed immutable occupancy interval** may yield accommodation sources.'
    assert_includes @authorization, '`ADMISSION_LOCATION` event opens the first interval'
    assert_includes @authorization, '`BED_TRANSFER` closes the preceding interval'
    assert_includes @authorization, '`inpatient_discharges.discharged_at` fact closes the final interval'
    assert_includes @authorization, 'Every interval is half-open `[start_at, end_at)`.'
    assert_includes @authorization, 'Admission alone, the mutable current-bed claim, wall-clock passage, a census read, a bill synchronization request, a discharge summary, and a cashier action are not charge triggers.'
  end

  def test_calendar_day_allocation_and_transfer_rules_are_deterministic
    assert_includes @authorization, 'one whole `OCCUPANCY_DAY` unit per occupied application-timezone calendar date'
    assert_includes @authorization, 'It does not prorate by hour or minute.'
    assert_includes @authorization, 'the admission date has one anchor at the admission instant'
    assert_includes @authorization, 'every later date has one anchor at application-timezone local midnight'
    assert_includes @authorization, 'discharge exactly at midnight does not create a unit for the new date'
    assert_includes @authorization, 'admission and discharge on the same date still yield exactly one unit'
    assert_includes @authorization, 'transfer exactly at midnight assigns the new date to the destination interval'
    assert_includes @authorization, 'a same-day transfer never creates an extra day'
    assert_includes @authorization, 'does not claim that a real hospital uses this convention'
    assert_includes @authorization, 'Calendar-day allocation is the deterministic local teaching V1 policy.'
    assert_includes @authorization, 'not a claim of universally observed hospital practice or SIMRS Sahabat billing policy'
  end

  def test_prospective_exact_bed_version_evidence_is_mandatory
    assert_includes @authorization, 'do **not** retain the exact `inpatient_bed_versions` public ID, integer version, and `after_digest`'
    assert_includes @authorization, 'prospectively extend every new admission/transfer location snapshot'
    assert_includes @authorization, 'No historical version is inferred from labels, codes, timestamps, current heads, or coincidental content.'
    assert_includes @authorization, 'No backfill fabricates missing provenance.'
    assert_includes @authorization, '`RIWAYAT_LOKASI_TIDAK_LENGKAP`'
    assert_includes @authorization, '(inpatient_bed_version_public_id, inpatient_bed_content_digest)'
    assert_includes @authorization, 'No label/code inference is permitted.'
  end

  def test_cancellation_correction_and_reversal_boundaries_are_closed
    assert_includes @authorization, 'A retained preclinical `encounter_cancellations` fact creates no accommodation source.'
    assert_includes @authorization, 'A forward bed transfer is not a correction of prior occupancy.'
    assert_includes @authorization, 'V1 defines no retroactive location correction, accommodation void, reversal, refund, negative day, manual day override, or reallocation.'
    assert_includes @authorization, 'separately authorized append-only occupancy-correction or occupancy-void fact'
    assert_includes @authorization, 'negate the original snapshotted source amount, never re-resolve a current tariff'
  end

  def test_binding_source_and_money_contracts_never_invent_price
    assert_includes @authorization, '`finance_accommodation_tariff_bindings`'
    assert_includes @authorization, '`finance_accommodation_tariff_binding_versions`'
    assert_includes @authorization, '`finance_accommodation_tariff_operation_receipts`'
    assert_includes @authorization, '`finance_accommodation_source_events`'
    assert_includes @authorization, 'service domain `ACCOMMODATION`'
    assert_includes @authorization, 'care setting `INPATIENT` and pricing unit `OCCUPANCY_DAY`'
    assert_includes @authorization, 'positive integer-rupiah amount'
    assert_includes @authorization, 'Quantity is exactly `1`; signed amount equals unit amount.'
    assert_includes @authorization, 'Prices and bindings start empty and may be entered only by an exact finance steward'
    assert_includes @authorization, 'Tariff amounts and bindings remain deliberately blank until an exact finance steward configures them'
    assert_includes @authorization, 'unique `(encounter_id, service_date)` constraint'
    assert_includes @authorization, 'Exactly one pharmacy, radiology, laboratory, or accommodation foreign key is non-null'
  end

  def test_readiness_allows_partial_sync_but_issue_fails_closed
    %w[
      SIAP_DISINKRONKAN TERSINKRONISASI INTERVAL_MASIH_TERBUKA
      RIWAYAT_LOKASI_TIDAK_LENGKAP TARIF_BELUM_DIPETAKAN TARIF_TIDAK_EFEKTIF
      KONTEKS_TIDAK_COCOK BUKTI_TIDAK_KONSISTEN
    ].each { |state| assert_includes @authorization, "`#{state}`" }
    assert_includes @authorization, 'Synchronization may persist every individually valid closed service day.'
    assert_includes @authorization, 'Bill issuance fails closed while any occupancy day visible at the stable issue cutoff is unresolved or duplicated'
    assert_includes @authorization, 'while any interval is open'
    assert_includes @authorization, 'Client readiness is never trusted.'
    assert_includes @authorization, 'finance gap never blocks registration, bed assignment, transfer, discharge documentation, routine discharge, clinical care, or RMIK work'
  end

  def test_exact_roles_idempotency_concurrency_and_recovery_are_required
    %w[finance_steward cashier registrar physician rmik admin].each do |role|
      assert_includes @authorization, "Exact `#{role}`"
    end
    assert_includes @authorization, 'Exact facility/bed-master manager'
    assert_includes @authorization, '`finance.accommodation-tariff.view`'
    assert_includes @authorization, '`finance.accommodation-tariff.manage`'
    assert_includes @authorization, 'Same-key/same-payload replays the retained result.'
    assert_includes @authorization, 'idempotent by `(encounter_id, service_date)`'
    assert_includes @authorization, 'Canonical lock direction remains encounter-first'
    assert_includes @authorization, 'Clinical admission/transfer/discharge never locks finance tables.'
    assert_includes @authorization, 'independent processes, proves a real database wait, and performs a third-connection durable reconciliation'
    assert_includes @authorization, 'one-day/one-source/one-charge cardinality'
    assert_includes @authorization, 'while preserving required audit evidence'
  end

  def test_rejected_alternatives_and_exclusions_are_explicit
    alternatives = @authorization.split('## Options considered and rejected alternatives', 2).last
    [
      'Charge at admission',
      'Charge from the mutable current-bed claim or census',
      'Charge once per location interval',
      'Continuously accrue from wall-clock time',
      'Prorate every interval by seconds',
      'Infer historical bed version from labels, code, or current head',
      'Allow issue while the final interval is open',
      'Seed a default daily price or class mapping',
    ].each { |alternative| assert_includes alternatives, alternative }

    exclusions = @authorization.split('## Explicit exclusions', 2).last
    assert_includes exclusions, 'inferred, seeded, default, sample, or purportedly hospital-approved prices'
    assert_includes exclusions, 'historical location backfill or bed-version inference'
    assert_includes exclusions, 'hourly/minute proration'
    %w[BPJS VClaim E-Klaim SATUSEHAT Aplicares].each { |term| assert_includes exclusions, term }
    assert_includes exclusions, 'commit, push, hosted migration, or deployment'
  end
end
