# frozen_string_literal: true

require 'minitest/autorun'

class ManagedInpatientWardBedMasterLocalEngineeringAuthorizationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  AUTHORIZATION_PATH = 'docs/new-simrs-rebuild/phase-1/MANAGED_INPATIENT_WARD_BED_MASTER_LOCAL_ENGINEERING_AUTHORIZATION_2026-08-30.md'

  def setup
    @authorization = File.read(File.join(ROOT, AUTHORIZATION_PATH), encoding: Encoding::UTF_8)
  end

  def test_simplified_authority_is_local_and_does_not_claim_acceptance
    assert_includes @authorization, '**LOCAL WORKING-TREE ENGINEERING IMPLEMENTATION AND DETERMINISTIC TESTING AUTHORIZED; DOMAIN AND PARITY ACCEPTANCE OPEN**'
    assert_includes @authorization, '`PAR-ADM-009` and the bed-selection dependency of `PAR-REG-001`'
    assert_includes @authorization, 'It needs no new ADR, exact-wording approval block, or proposal-byte hash'

    assert_match(/\| Create local application code.*\| `true` \|/, @authorization)
    assert_match(/\| Facility\/bed-management.*owner acceptance \| `false` \|/, @authorization)
    assert_match(/\| SIMRS Sahabat parity.*\| `false` \|/, @authorization)
    assert_match(/\| Commit, push, pull request, release, or publication \| `false` \|/, @authorization)
    assert_match(/\| Hosted migration or deployment \| `false` \|/, @authorization)
  end

  def test_master_identity_version_and_lifecycle_are_closed
    assert_includes @authorization, 'Ward codes and bed codes are trimmed, normalized uppercase codes'
    assert_includes @authorization, 'database unique constraint and immutable after creation'
    assert_includes @authorization, 'Codes are never recycled, including after retirement.'
    assert_includes @authorization, 'display name, service class, room label, and active state'
    assert_includes @authorization, 'increments by exactly one'
    assert_includes @authorization, 'Historical versions are immutable.'
    assert_includes @authorization, 'Master states are exactly `ACTIVE` and `RETIRED`.'
    assert_includes @authorization, '`RETIRED` is terminal'
    assert_includes @authorization, 'An occupied bed cannot retire.'
    assert_includes @authorization, 'all child beds already `RETIRED`'
  end

  def test_live_census_uses_encounter_claims_not_mutable_counts
    assert_includes @authorization, 'Live occupancy is a read-only projection'
    assert_includes @authorization, 'never a mutable cached count'
    assert_includes @authorization, 'has inpatient care setting'
    assert_includes @authorization, 'declared bed-occupying encounter states'
    assert_includes @authorization, 'is not `CANCELLED`'
    assert_includes @authorization, 'at query time'
    assert_includes @authorization, 'immediately ceases to contribute'
    assert_includes @authorization, '`inpatient_bed_claim_mutexes` rows are serialization locks only'
    assert_includes @authorization, 'not occupancy facts, capacity rows, or census counters'
  end

  def test_capabilities_and_roles_are_explicit_and_separate
    assert_includes @authorization, '`master.inpatient.ward-bed.manage`'
    assert_includes @authorization, 'assigned only to the `admin` role'
    assert_includes @authorization, 'explicitly required for both ordinary administrators and system administrators'
    assert_includes @authorization, '`inpatient.occupancy.view`'
    %w[registrar nurse physician rmik admin].each do |role|
      assert_includes @authorization, "`#{role}`"
    end
    assert_includes @authorization, 'census capability alone never expands clinical-record access'
    assert_includes @authorization, 'Wrong-role and missing-capability requests are denied before manual master lookup'
  end

  def test_replay_audit_and_race_contracts_are_fail_closed
    assert_includes @authorization, '`(actor_user_id, operation, idempotency_key)`'
    assert_includes @authorization, 'lowercase SHA-256 canonical request digest'
    assert_includes @authorization, '`idempotency_key_conflict`'
    assert_includes @authorization, 'without duplicate mutation or success audit'
    assert_includes @authorization, 'An audit-write failure rolls the whole operation back.'
    assert_includes @authorization, 'admission claim racing a bed retirement'
    assert_includes @authorization, 'either the claim wins and retirement is refused as occupied, or retirement wins and the claim is refused as retired'
  end

  def test_acceptance_retention_and_cross_engine_verification_are_required
    acceptance = @authorization.split('## Acceptance scenarios', 2).last.split('## Retention, rollback, and reset', 2).first
    assert_equal 9, acceptance.scan(/^\d+\./).length
    assert_includes acceptance, 'census counts an active inpatient encounter once'
    assert_includes acceptance, 'Synthetic reset removes the synthetic master and patient-domain chains'

    assert_includes @authorization, 'rollback/down must refuse to drop those populated structures'
    assert_includes @authorization, 'It is not an ordinary master deletion or code-reuse mechanism.'
    assert_includes @authorization, 'PostgreSQL 17 and MySQL 8.4 must both verify database constraints and real lock behavior'
    assert_includes @authorization, 'SQLite alone is insufficient concurrency evidence.'
  end

  def test_exclusions_preserve_downstream_and_external_boundaries
    exclusions = @authorization.split('## Explicit exclusions', 2).last

    %w[
      transfer
      discharge
      billing
      cashier
      claims
      BPJS/Aplicares
      pharmacy
      radiology
      laboratory
    ].each do |excluded|
      assert_includes exclusions, excluded
    end

    assert_includes exclusions, 'No secrets, real data, or live integration endpoints may be introduced.'
    assert_match(/\| Live BPJS, VClaim, E-Klaim, SATUSEHAT, Aplicares, LIS, PACS, payment, or device integration \| `false` \|/, @authorization)
  end

  def test_phase_readme_links_the_record_without_overclaim
    readme = File.read(File.join(ROOT, 'docs/new-simrs-rebuild/phase-1/README.md'), encoding: Encoding::UTF_8)

    assert_includes readme, 'MANAGED_INPATIENT_WARD_BED_MASTER_LOCAL_ENGINEERING_AUTHORIZATION_2026-08-30.md'
    assert_includes readme, 'Local engineering authorized; facility/registration and parity acceptance open'
  end
end
