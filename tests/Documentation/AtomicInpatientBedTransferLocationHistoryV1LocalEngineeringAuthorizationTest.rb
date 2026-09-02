# frozen_string_literal: true

require 'minitest/autorun'

class AtomicInpatientBedTransferLocationHistoryV1LocalEngineeringAuthorizationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  AUTHORIZATION_PATH = 'docs/new-simrs-rebuild/phase-1/ATOMIC_INPATIENT_BED_TRANSFER_LOCATION_HISTORY_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-08-30.md'

  def setup
    @authorization = File.read(File.join(ROOT, AUTHORIZATION_PATH), encoding: Encoding::UTF_8)
  end

  def test_authority_is_local_and_explicitly_unreleased
    assert_includes @authorization, '**LOCAL WORKING-TREE ENGINEERING IMPLEMENTATION AND DETERMINISTIC TESTING AUTHORIZED; DOMAIN AND PARITY ACCEPTANCE OPEN**'
    assert_includes @authorization, 'bounded transfer dependency of `PAR-REG-001`, `PAR-CLN-005`, and `PAR-ADM-009`'
    assert_includes @authorization, 'It needs no new ADR, exact-wording approval block, proposal-byte hash, or separate approval ceremony'

    assert_match(/\| Create local application code, bounded lock-order refactor.*\| `true` \|/, @authorization)
    assert_match(/\| Registration, facility\/bed-management.*owner acceptance \| `false` \|/, @authorization)
    assert_match(/\| SIMRS Sahabat parity.*\| `false` \|/, @authorization)
    assert_match(/\| Commit, push, pull request, release, or publication \| `false` \|/, @authorization)
    assert_match(/\| Hosted migration or deployment \| `false` \|/, @authorization)
  end

  def test_actor_and_encounter_preconditions_are_exact
    assert_includes @authorization, 'both the `registrar` role and the new server-side capability `inpatient.bed.transfer`'
    assert_includes @authorization, 'administrator status, or system-administrator status never implies transfer authority'
    assert_includes @authorization, 'Authorization occurs before manual encounter or bed lookup.'
    %w[REGISTERED IN_EXAMINATION READY_FOR_RM CANCELLED CLOSED].each do |state|
      assert_includes @authorization, "`#{state}`"
    end
    assert_includes @authorization, "current `inpatient_bed_id` and immutable `bed_code` must identify the same managed source bed"
    assert_includes @authorization, 'source bed and its ward must both remain `ACTIVE`'
    assert_includes @authorization, 'target bed and its ward must both remain `ACTIVE`'
    assert_includes @authorization, '`same_bed`'
    assert_includes @authorization, '`target_occupied`'
    assert_includes @authorization, '`service_class_change_not_authorized`'
  end

  def test_one_canonical_lock_coordinator_is_a_prerequisite
    assert_includes @authorization, 'one bounded canonical bed-operation lock coordinator'
    assert_includes @authorization, 'managed admission claim, occupied-bed retirement, occupied whole-ward retirement, inpatient-document placement snapshot write, and this transfer path'
    assert_includes @authorization, 'No transfer route may be enabled while those participants retain incompatible lock orders.'

    locking = @authorization.split('For every participating transaction the canonical order is:', 2).last
      .split('Admission has no pre-existing encounter', 2).first
    assert_equal 6, locking.scan(/^\d+\./).length
    assert_includes locking, 'lock all involved mutex rows in normalized bed-code byte order'
    assert_includes locking, 'lock all affected existing encounters in ascending database ID when the operation has any'
    assert_includes locking, 'lock all involved ward rows in ascending database ID, then all involved bed rows in ascending database ID'
    assert_includes @authorization, 'either the complete pre-transfer placement or the complete post-transfer placement, never a hybrid'
    assert_includes @authorization, 'Opposite-direction transfers sort the same two mutex, encounter, ward, and bed sets identically.'
    assert_includes @authorization, 'Whole-ward retirement must resolve the current child-bed set non-authoritatively'
    assert_includes @authorization, 'lock every child-bed mutex in normalized bed-code byte order'
    assert_includes @authorization, 'lock every currently occupying affected encounter in ascending database ID'
    assert_includes @authorization, 'then lock the ward and every child bed in ascending database ID'
    assert_includes @authorization, 'If a concurrent child-bed create or remap changed the resolved set, the attempt aborts or retries from the beginning'
    assert_includes @authorization, 'must never acquire an additional mutex out of order'
    assert_includes @authorization, 'replaces the existing ward-to-beds-to-encounters order'
  end

  def test_history_is_immutable_sequenced_and_honest_about_baseline
    assert_includes @authorization, 'Event types are exactly `ADMISSION_LOCATION` and `BED_TRANSFER`.'
    assert_includes @authorization, 'per-encounter integer `sequence` beginning at `1`, increasing by exactly one'
    assert_includes @authorization, 'database unique constraint on `(encounter_id, sequence)`'
    assert_includes @authorization, 'Every future managed inpatient registration created after this migration appends `ADMISSION_LOCATION` sequence `1`'
    assert_includes @authorization, 'No historical event is fabricated or backfilled.'
    assert_includes @authorization, '`history_baseline = LEGACY_CURRENT_PLACEMENT` and `history_complete = false`'
    assert_includes @authorization, 'first real transfer appends `BED_TRANSFER` sequence `1`'

    %w[
      ward_public_ID
      ward_code
      ward_display_name
      bed_public_ID
      bed_code
      bed_display_name
      room_label
      service_class
    ].each do |concept|
      words = concept.tr('_', ' ')
      assert_match(/#{Regexp.escape(words)}/i, @authorization)
    end
    assert_includes @authorization, 'between 5 and 500 Unicode scalar values'
    assert_includes @authorization, 'never copied into audit metadata, denial metadata, logs, URLs, or idempotency receipts'
  end

  def test_optimistic_identity_and_idempotency_are_closed
    %w[
      expected_location_sequence
      expected_source_bed_public_id
      target_bed_public_id
      idempotency_key
    ].each do |field|
      assert_includes @authorization, "`#{field}`"
    end
    assert_includes @authorization, 'expected sequence is `0`'
    assert_includes @authorization, '`stale_location` or `source_bed_changed`'
    assert_includes @authorization, '`(actor_user_id, operation, idempotency_key)`'
    assert_includes @authorization, '`INPATIENT_BED_TRANSFER`'
    assert_includes @authorization, 'canonicalized lowercase'
    assert_includes @authorization, 'lowercase SHA-256 digest'
    assert_includes @authorization, 'returns the original event and sequence with `replayed = true`, even when observed after a later successful transfer'
    assert_includes @authorization, '`idempotency_key_conflict`'
    assert_includes @authorization, 'receipt and result resolution are actor-scoped'
  end

  def test_atomic_audit_denial_and_races_fail_closed
    assert_includes @authorization, 'exactly one `inpatient.bed.transfer` success audit commit atomically'
    assert_includes @authorization, 'An audit-write or receipt-write failure rolls the whole success path back.'
    assert_includes @authorization, 'contains no patient identifiers, free-text reason, clinical content, secrets, connection strings, or live endpoint data'
    assert_includes @authorization, 'Denials preserve the prior encounter placement and create no location event, success receipt, or success audit.'

    races = @authorization.split('Required deterministic outcomes are:', 2).last.split('## Read projection', 2).first
    assert_includes races, 'two commands for one encounter from the same expected source/sequence produce at most one next event'
    assert_includes races, 'two encounters racing for one target produce one transfer and one `target_occupied` denial'
    assert_includes races, 'managed admission racing for the target'
    assert_includes races, 'target retirement racing the transfer'
    assert_includes races, 'whole-ward retirement racing transfer or documentation follows the all-child-bed mutex and affected-encounter order'
    assert_includes races, 'opposite-direction swaps cannot bypass the target-unoccupied rule and cannot deadlock'
    assert_includes races, 'documentation write racing transfer records one coherent placement snapshot'
  end

  def test_projection_reset_down_and_cross_engine_evidence_are_required
    assert_includes @authorization, 'encounter detail location projection requires existing `encounter.open`'
    assert_includes @authorization, 'exposes `history_baseline` and `history_complete`'
    assert_includes @authorization, 'Location events, receipts, and mutex rows are history/evidence or locks, never current occupancy facts or mutable counts.'
    assert_includes @authorization, 'Synthetic reset may remove synthetic encounter, location-event, and receipt chains'
    assert_includes @authorization, 'migration down must refuse to discard it'
    assert_includes @authorization, 'PostgreSQL 17 and MySQL 8.4 evidence must record exact engine versions and source/catalog digests'
    assert_includes @authorization, 'independent processes plus a durable third-connection readback'
    assert_includes @authorization, 'whole-ward retirement coordination'
    assert_includes @authorization, 'observed lock waiting, no deadlock'
    assert_includes @authorization, 'SQLite alone is insufficient concurrency evidence.'
  end

  def test_acceptance_and_exclusions_do_not_invent_downstream_rules
    acceptance = @authorization.split('## Acceptance scenarios', 2).last.split('## Retention, rollback, reset, and portability', 2).first
    assert_equal 11, acceptance.scan(/^\d+\./).length
    assert_includes acceptance, 'administrator-only, and system-administrator-only requests are denied'
    assert_includes acceptance, 'existing encounter receives no invented event'
    assert_includes acceptance, 'Direct event update/delete, direct encounter placement mutation outside the bounded service, and direct receipt insertion are refused'

    exclusions = @authorization.split('## Explicit exclusions', 2).last
    %w[
      acceptance
      clinical
      waitlist
      reservation
      class
      tariff
      billing
      claim
      discharge
      RMIK
      pharmacy
      diagnostics
      integration
    ].each do |excluded|
      assert_match(/#{Regexp.escape(excluded)}/i, exclusions)
    end
    assert_includes exclusions, 'No secrets, real patient data, production endpoints, live credentials, retroactive location history, or guessed downstream effects may be introduced.'
  end
end
