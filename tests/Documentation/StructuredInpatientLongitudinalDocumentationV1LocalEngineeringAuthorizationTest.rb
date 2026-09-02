# frozen_string_literal: true

require 'minitest/autorun'

class StructuredInpatientLongitudinalDocumentationV1LocalEngineeringAuthorizationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  AUTHORIZATION_PATH = 'docs/new-simrs-rebuild/phase-1/STRUCTURED_INPATIENT_LONGITUDINAL_DOCUMENTATION_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-08-30.md'

  def setup
    @authorization = File.read(File.join(ROOT, AUTHORIZATION_PATH), encoding: Encoding::UTF_8)
  end

  def test_simplified_authority_is_local_and_does_not_claim_acceptance
    assert_includes @authorization, '**LOCAL WORKING-TREE ENGINEERING IMPLEMENTATION AND DETERMINISTIC TESTING AUTHORIZED; DOMAIN AND PARITY ACCEPTANCE OPEN**'
    assert_includes @authorization, 'Direct scope: `PAR-CLN-005`'
    assert_includes @authorization, '`PAR-REG-001` and managed ward/bed placement from `PAR-ADM-009`'
    assert_includes @authorization, 'It needs no new ADR, exact-wording approval block, proposal-byte hash, or separate approval ceremony'
    assert_includes @authorization, 'The laboratory-only `DEC-016` is not a dependency'

    assert_match(/\| Create local application code.*\| `true` \|/, @authorization)
    assert_match(/\| Clinical or Nursing owner acceptance \| `false` \|/, @authorization)
    assert_match(/\| SIMRS Sahabat parity.*\| `false` \|/, @authorization)
    assert_match(/\| Commit, push, pull request, release, or publication \| `false` \|/, @authorization)
    assert_match(/\| Hosted migration or deployment \| `false` \|/, @authorization)
  end

  def test_document_identity_daily_scope_and_states_are_exact
    assert_includes @authorization, 'Document types are exactly `NURSING_DAILY` and `MEDICAL_DAILY`.'
    assert_includes @authorization, 'Document states are exactly `DRAFT` and `FINAL`.'
    assert_includes @authorization, '`(encounter_id, document_type, service_date, author_user_id)`'
    assert_includes @authorization, 'using the Asia/Jakarta calendar date'
    assert_includes @authorization, 'cannot be supplied, backdated, or future-dated by the client'
    assert_includes @authorization, '`INPATIENT_LONGITUDINAL_DOCUMENTATION_V1`'
    assert_includes @authorization, 'Each successful draft save or finalization appends exactly one immutable version'
    assert_includes @authorization, 'A Final head and every historical version are immutable'
    assert_includes @authorization, 'There is no correction, amendment, co-signature, supervisor approval, withdrawal, reopening, or Final-to-Draft transition'
  end

  def test_bounded_fields_and_final_requirements_are_closed
    nursing = %w[nursing_observation nursing_intervention nursing_evaluation additional_notes]
    medical = %w[subjective objective assessment plan additional_notes]

    nursing_section = @authorization.split('`NURSING_DAILY` permits exactly:', 2).last.split('Final nursing documents', 2).first
    medical_section = @authorization.split('`MEDICAL_DAILY` permits exactly:', 2).last.split('Final medical documents', 2).first

    assert_equal nursing, nursing_section.scan(/^- `([^`]+)`$/).flatten
    assert_equal medical, medical_section.scan(/^- `([^`]+)`$/).flatten
    assert_includes @authorization, 'Final nursing documents require non-blank `nursing_observation`, `nursing_intervention`, and `nursing_evaluation`.'
    assert_includes @authorization, 'Final medical documents require non-blank `subjective`, `objective`, `assessment`, and `plan`.'
    assert_includes @authorization, '10,000 Unicode scalar values'
    assert_includes @authorization, 'a finalize request cannot replace content'
    assert_includes @authorization, 'does not claim an approved CPPT, diagnosis, procedure, medication, or discharge-summary standard'
  end

  def test_encounter_and_managed_placement_snapshot_are_fail_closed
    %w[REGISTERED IN_EXAMINATION READY_FOR_RM CANCELLED CLOSED].each do |state|
      assert_includes @authorization, "`#{state}`"
    end

    assert_includes @authorization, 'Every write locks and reloads the encounter before any document lookup.'
    assert_includes @authorization, 'missing-placement, stale-placement, and unmanaged-placement encounters fail closed'
    assert_includes @authorization, 'managed bed whose ward and bed remain `ACTIVE`'
    assert_includes @authorization, 'The server, never the client, derives and stores with every immutable version'
    assert_includes @authorization, 'managed ward public ID, immutable ward code, and display-name snapshot'
    assert_includes @authorization, 'managed bed public ID, immutable bed code, display-name snapshot, room-label snapshot, and service-class snapshot'
    assert_includes @authorization, 'Historical placement snapshots never change after a ward/bed rename.'
    assert_includes @authorization, 'does not create an occupancy interval, transfer history, class-change record, or mutable census count'
  end

  def test_roles_capabilities_and_lifecycle_effects_are_separate
    assert_includes @authorization, '`NURSING_DAILY` Draft/Final writes require both the `nurse` role and `clinical.nursing.write`.'
    assert_includes @authorization, '`MEDICAL_DAILY` Draft/Final writes require both the `physician` role and `clinical.medical.write`.'
    assert_includes @authorization, '`encounter.open` permits the existing read-only encounter/detail projection but never implies either write capability.'
    assert_includes @authorization, 'Administrator or system-administrator status grants no routine clinical-document write'
    assert_includes @authorization, 'Saving a Draft does not change encounter status.'
    assert_includes @authorization, 'The first successful Final daily document may move `REGISTERED` to `IN_EXAMINATION`'
    assert_includes @authorization, 'never sets `READY_FOR_RM`, `CLOSED`, or a discharge status and never releases a bed'
    assert_includes @authorization, 'Existing inpatient `clinical_entries` remain retained read-only history.'
  end

  def test_replay_audit_atomicity_and_races_are_frozen
    assert_includes @authorization, '`(actor_user_id, operation, idempotency_key)`'
    assert_includes @authorization, 'canonical lowercase idempotency key'
    assert_includes @authorization, 'lowercase SHA-256 canonical request digest'
    assert_includes @authorization, '`idempotency_key_conflict`'
    assert_includes @authorization, 'Creation requires `expected_version = 0`'
    assert_includes @authorization, '`stale_version`'
    assert_includes @authorization, 'Lock order is encounter, managed ward, managed bed, document head, then operation receipt.'
    assert_includes @authorization, 'An audit-write failure rolls back the entire success path.'
    assert_includes @authorization, 'Concurrent creates for the same document identity yield one head.'
    assert_includes @authorization, 'Identical replay races reconcile to one mutation and one success audit'
    assert_includes @authorization, 'must never contain patient identifiers, clinical field values, free text, secrets, connection strings, or live endpoint data'
  end

  def test_acceptance_retention_portability_and_exclusions_are_complete
    acceptance = @authorization.split('## Acceptance scenarios', 2).last.split('## Retention, rollback, and reset', 2).first

    assert_equal 11, acceptance.scan(/^\d+\./).length
    assert_includes acceptance, 'Wrong-role, missing-capability, administrator-only, system-administrator-only'
    assert_includes acceptance, 'no v1 operation sets `READY_FOR_RM`, closes/discharges the encounter, or releases the bed'
    assert_includes acceptance, 'independent PostgreSQL 17 and MySQL 8.4'

    assert_includes @authorization, 'rollback/down must refuse to drop the populated structures'
    assert_includes @authorization, 'Reset is not an ordinary document deletion, correction, Final reversal, or evidence-erasure route.'

    exclusions = @authorization.split('## Explicit exclusions', 2).last
    %w[transfer discharge laboratory radiology prescription pharmacy RMIK tariff charge cashier billing claim BPJS].each do |excluded|
      assert_includes exclusions, excluded
    end
    assert_includes exclusions, 'No secrets, real patient data, production endpoints, or live integration credentials may be introduced.'
  end
end
