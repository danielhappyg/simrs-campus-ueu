# frozen_string_literal: true

require 'minitest/autorun'

class RoutineInpatientDischargeSummaryV1LocalEngineeringAuthorizationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  AUTHORIZATION_PATH = 'docs/new-simrs-rebuild/phase-1/ROUTINE_INPATIENT_DISCHARGE_SUMMARY_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-08-31.md'

  def setup
    @authorization = File.read(File.join(ROOT, AUTHORIZATION_PATH), encoding: Encoding::UTF_8)
  end

  def test_simplified_authority_is_local_and_unreleased
    assert_includes @authorization, '**LOCAL WORKING-TREE ENGINEERING IMPLEMENTATION AND DETERMINISTIC TESTING AUTHORIZED; CLINICAL, RMIK, AND PARITY ACCEPTANCE OPEN**'
    assert_includes @authorization, 'documentation dependency of `PAR-CLN-005`'
    assert_includes @authorization, 'need no new ADR, ADR-019 exact-wording approval, proposal-byte hash, or separate approval ceremony'

    assert_match(/\| Create local application code.*\| `true` \|/, @authorization)
    assert_match(/\| Clinical, RMIK, registration.*owner acceptance \| `false` \|/, @authorization)
    assert_match(/\| SIMRS Sahabat parity.*\| `false` \|/, @authorization)
    assert_match(/\| Commit, push, pull request, release, or publication \| `false` \|/, @authorization)
    assert_match(/\| Hosted migration or deployment \| `false` \|/, @authorization)
  end

  def test_one_episode_scoped_physician_authored_head_has_a_terminal_final
    assert_includes @authorization, '`ROUTINE_INPATIENT_DISCHARGE_SUMMARY_V1`'
    assert_includes @authorization, 'exactly one discharge-summary head per inpatient episode, uniquely identified by `encounter_id`'
    assert_includes @authorization, 'Document states are exactly `DRAFT` and `FINAL`.'
    assert_includes @authorization, 'Only that original author may append a Draft revision or finalize the summary'
    assert_includes @authorization, 'Creation requires `expected_version = 0` and creates version `1`.'
    assert_includes @authorization, 'Every successful Draft save or Final operation appends exactly one immutable version'
    assert_includes @authorization, 'A Draft may be partial'
    assert_includes @authorization, 'Finalization reuses the current stored Draft fields.'
    assert_includes @authorization, 'Exactly one terminal Final is permitted.'
    assert_includes @authorization, 'There is no Final-to-Draft transition, reopening, amendment, correction, withdrawal, co-signature, supervisor approval, second Final, or ordinary deletion'
  end

  def test_exact_fields_labels_and_final_completeness_are_closed
    expected = {
      'admission_reason' => 'Alasan Masuk',
      'significant_findings' => 'Temuan Penting',
      'care_and_treatment_summary' => 'Ringkasan Perawatan dan Pengobatan',
      'condition_at_discharge' => 'Kondisi Saat Pulang',
      'follow_up_plan' => 'Rencana Tindak Lanjut'
    }
    field_section = @authorization.split('## Exact fields and Indonesian UI labels', 2).last
      .split('## Exact actor and access separation', 2).first
    rows = field_section.scan(/^\| `([^`]+)` \| `([^`]+)` \|$/).to_h

    assert_equal expected, rows
    assert_includes field_section, 'Unknown keys, non-string values, invalid UTF-8, and any value longer than 10,000 Unicode scalar values fail validation.'
    assert_includes field_section, 'Drafts may be incomplete.'
    expected.each_key do |field|
      assert_includes field_section, "`#{field}`"
    end
    assert_includes field_section, '`condition_at_discharge` is documentation text only'
    assert_includes field_section, 'not a coded disposition, legal declaration, death record, AMA decision, referral instruction, encounter transition, or bed-release command'
  end

  def test_exact_role_and_dedicated_capability_do_not_leak_authority
    assert_includes @authorization, 'requires both the exact `physician` role and the dedicated server-side capability `clinical.inpatient.discharge-summary.write`.'
    assert_includes @authorization, 'Role alone, capability alone, `clinical.medical.write`, `encounter.open`, nurse status, registrar status, RMIK status, administrator status, or system-administrator status never implies'
    assert_includes @authorization, 'Authorization occurs before manual encounter or summary lookup.'
    assert_includes @authorization, 'Wrong-role and missing-capability requests expose no resource existence'
    assert_includes @authorization, 'This profile grants no broader patient-list, census, clinical-document, billing, claim, or medical-record access.'
  end

  def test_server_derived_episode_placement_and_location_snapshot_is_coherent
    %w[REGISTERED IN_EXAMINATION READY_FOR_RM CANCELLED CLOSED].each do |state|
      assert_includes @authorization, "`#{state}`"
    end
    assert_includes @authorization, 'Every write uses the canonical inpatient bed-operation lock coordinator.'
    assert_includes @authorization, "locked episode's `inpatient_bed_id` and immutable `bed_code` must identify the same managed bed"
    assert_includes @authorization, 'The server, never the client, derives one coherent episode/current-placement snapshot for every immutable version'
    assert_includes @authorization, 'managed ward public ID/code/display-name, managed bed public ID/code/display-name, room label, service class, and current location sequence'
    assert_includes @authorization, "snapshot also stores that event's public ID and event type and verifies that its destination equals the locked current managed placement"
    assert_includes @authorization, '`history_baseline = LEGACY_CURRENT_PLACEMENT`, and `history_complete = false`'
    assert_includes @authorization, 'either the complete pre-transfer snapshot or the complete post-transfer snapshot, never a hybrid'
    assert_includes @authorization, 'Placement and location data cannot be supplied or overridden by the client.'
    assert_includes @authorization, 'creates no location event and mutates no current placement'
  end

  def test_actor_scoped_idempotency_expected_version_and_races_are_exact
    assert_includes @authorization, '`(actor_user_id, operation, idempotency_key)`'
    assert_includes @authorization, '`DISCHARGE_SUMMARY_DRAFT_SAVE` and `DISCHARGE_SUMMARY_FINALIZE`'
    assert_includes @authorization, 'canonicalized lowercase'
    assert_includes @authorization, 'lowercase SHA-256 canonical request digest'
    assert_includes @authorization, 'returns the original result with `replayed = true`'
    assert_includes @authorization, '`idempotency_key_conflict`'
    assert_includes @authorization, 'receipt and its result resolution are actor-scoped'
    assert_includes @authorization, 'Creation requires `expected_version = 0`; every later Draft or Final operation requires the exact current version.'
    assert_includes @authorization, '`stale_version`'
    assert_includes @authorization, 'Concurrent creates for one episode yield one head.'
    assert_includes @authorization, 'Concurrent Final attempts yield exactly one terminal Final.'
    assert_includes @authorization, 'Identical replay races reconcile to one mutation and one success audit.'
  end

  def test_audit_metadata_is_sanitized_and_mutation_is_atomic
    assert_includes @authorization, 'head creation/update, immutable version append, operation receipt, and exactly one applicable success audit commit atomically'
    assert_includes @authorization, 'An audit-write or receipt-write failure rolls back the entire success path.'
    assert_includes @authorization, 'Denials create no head/version/receipt/success-audit mutation'
    assert_includes @authorization, 'Audit metadata, idempotency receipts, logs, URLs, and exception text must never contain'
    assert_includes @authorization, 'patient identifiers, names, medical-record numbers, any of the five clinical field values, clinical free text, secrets, credentials, connection strings, or live endpoint data'
  end

  def test_document_final_has_no_discharge_or_downstream_side_effect
    effects = @authorization.split('## Explicit non-effects and future workflow boundary', 2).last
      .split('## Acceptance scenarios', 2).first
    assert_includes effects, 'A Draft save and a Final operation cause no encounter-status transition.'
    assert_includes effects, 'They do not set `READY_FOR_RM` or `CLOSED`'
    assert_includes effects, 'release or retire a bed'
    assert_includes effects, 'create a charge or bill'
    assert_includes effects, 'submit a claim'
    assert_includes effects, 'notify BPJS'
    assert_includes effects, 'create a prescription'
    assert_includes effects, 'dispense or reconcile medication'
    assert_includes effects, 'accept/close a record in RMIK'
    assert_includes effects, 'later separately authorized atomic discharge workflow may require a terminal `ROUTINE_INPATIENT_DISCHARGE_SUMMARY_V1` Final'
    assert_includes effects, 'This authorization neither implements nor pre-approves it.'
  end

  def test_acceptance_retention_portability_and_exclusions_are_complete
    acceptance = @authorization.split('## Acceptance scenarios', 2).last
      .split('## Retention, rollback, reset, and portability', 2).first
    assert_equal 11, acceptance.scan(/^\d+\./).length
    assert_includes acceptance, 'second physician cannot edit, finalize, take over, co-sign, or create a second summary'
    assert_includes acceptance, 'transfer racing a Draft or Final operation records one coherent pre-transfer or post-transfer placement/location-sequence snapshot'
    assert_includes acceptance, 'leave encounter status, cancellation, placement, location sequence, bed occupancy, charges, billing, claims, medication/pharmacy, and RMIK state unchanged'
    assert_includes acceptance, 'independent PostgreSQL 17 and MySQL 8.4'

    assert_includes @authorization, 'migration down must refuse to discard it'
    assert_includes @authorization, 'Reset is not an ordinary summary deletion, correction, Final reversal, RMIK closure, discharge transition, or evidence-erasure route.'
    assert_includes @authorization, 'SQLite alone is insufficient concurrency evidence.'

    exclusions = @authorization.split('## Explicit exclusions', 2).last
    %w[encounter-status bed billing charge claim BPJS medication pharmacy RMIK AMA death referral integration].each do |excluded|
      assert_match(/#{Regexp.escape(excluded)}/i, exclusions)
    end
    assert_includes exclusions, 'No secrets, real patient data, production endpoints, live credentials, retroactive summary, or guessed downstream effects may be introduced.'
  end
end
