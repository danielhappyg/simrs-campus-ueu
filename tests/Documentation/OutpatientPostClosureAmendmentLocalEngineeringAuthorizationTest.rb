# frozen_string_literal: true

require 'digest'
require 'minitest/autorun'

class OutpatientPostClosureAmendmentLocalEngineeringAuthorizationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  AUTHORIZATION_PATH = 'docs/new-simrs-rebuild/phase-1/OUTPATIENT_POST_CLOSURE_AMENDMENT_LOCAL_ENGINEERING_AUTHORIZATION_2026-08-30.md'
  FR_PATH = 'docs/new-simrs-rebuild/phase-1/OUTPATIENT_POST_CLOSURE_AMENDMENT_FR_PACK.md'
  ADR_PATH = 'docs/operations/ADR_OUTPATIENT_POST_CLOSURE_AMENDMENT_2026-08-26.md'
  SOURCE_HASHES = {
    FR_PATH => '6c7174fafda1b5fb1ee76a9841349a639b5ce985904ba6ebec8fba6118c3723b',
    ADR_PATH => '62469954f9d4ece51ddb175a5a300edd71c05a09dcbb80020d172e819d4f3eff'
  }.freeze

  def setup
    @authorization = File.read(File.join(ROOT, AUTHORIZATION_PATH), encoding: Encoding::UTF_8)
  end

  def test_exact_proposal_bytes_are_bound_fail_closed
    SOURCE_HASHES.each do |relative_path, expected_sha|
      bytes = File.binread(File.join(ROOT, relative_path))

      assert_equal expected_sha, Digest::SHA256.hexdigest(bytes), relative_path
      assert_includes @authorization, "`#{expected_sha}`"
    end

    assert_includes @authorization, 'If either source hash changes, this authorization fails closed'
  end

  def test_status_is_local_engineering_only
    assert_includes @authorization, '**LOCAL WORKING-TREE ENGINEERING IMPLEMENTATION AND DETERMINISTIC TESTING AUTHORIZED; DOMAIN AND PARITY ACCEPTANCE OPEN**'
    assert_includes @authorization, '`DEC-016` remains **Proposed**'
    assert_includes @authorization, 'Blank owner-decision rows in the FR pack remain blank and are not consent.'

    %w[
      Clinical\ owner\ acceptance
      RMIK\ owner\ acceptance
      Sahabat\ parity\ acceptance
      Real\ patient\ data
      Hosted\ migration\ or\ deployment
    ].each do |escaped_label|
      label = escaped_label.tr('\\', ' ')
      assert_match(/\| #{Regexp.escape(label)}.*\| `false` \|/, @authorization)
    end

    assert_match(/\| Commit, push, pull request, release or publication \| `false` \|/, @authorization)
    assert_match(/\| Create local application code.*\| `true` \|/, @authorization)
  end

  def test_reason_and_actor_contract_is_closed
    reasons = %w[CLINICAL_CORRECTION MISSING_INFORMATION WRONG_ENTRY OTHER]
    reason_line = @authorization.lines.find { |line| line.include?('exact request reason codes') }
    listed_reasons = reason_line.scan(/`([A-Z_]+)`/).flatten

    assert_equal reasons, listed_reasons

    assert_includes @authorization, '1–500 Unicode scalar values'
    assert_includes @authorization, '`requested_by_user_id == author_user_id == finalized_by_user_id`'
    assert_includes @authorization, '`decision_by_user_id != requested_by_user_id`'
    assert_includes @authorization, 'The requester cannot decide; the decision actor cannot author or finalize'
    assert_includes @authorization, 'Administrator or break-glass status grants no routine'
  end

  def test_state_immutability_and_renewed_signoff_meaning_are_exact
    %w[SUBMITTED APPROVED DENIED CONSUMED].each do |state|
      assert_includes @authorization, "`#{state}`"
    end

    assert_includes @authorization, 'Addendum states are exactly `DRAFT` and `FINAL`'
    refute_includes @authorization, '`ADDENDUM_DRAFT`'
    refute_includes @authorization, '`ADDENDUM_FINAL`'
    assert_includes @authorization, 'no `WITHDRAWN` state or action is authorized'
    refute_match(/generic reopen is authorized/i, @authorization)
    assert_includes @authorization, 'every historical addendum version is append-only'
    assert_includes @authorization, 'the addendum-specific completeness snapshot was reviewed and passed at that source fingerprint'
    assert_includes @authorization, 'It never replaces, revises, erases, or re-labels the original completeness review or original sign-off.'
  end

  def test_fingerprint_replay_lifecycle_and_rollback_contracts_are_frozen
    assert_includes @authorization, '`outpatient-amendment-source-fingerprint-v1`'
    assert_includes @authorization, 'baseline signed review public identity, version and stored source fingerprint'
    assert_includes @authorization, 'every Final addendum public ID, version and canonical content digest'
    assert_includes @authorization, 'every currently `ACTIVE` lab-order public ID'
    assert_includes @authorization, 'Any active lab order blocks renewed RMIK sign-off.'

    assert_includes @authorization, '`(actor_user_id, operation, idempotency_key)`'
    assert_includes @authorization, '`idempotency_key_conflict`'
    assert_includes @authorization, 'without duplicate mutation or success audit'
    assert_includes @authorization, 'rollback/down must refuse to drop the populated structures'
  end

  def test_capabilities_are_exact_and_separate
    expected = %w[
      clinical.outpatient.amendment.request
      clinical.outpatient.amendment.approve
      clinical.outpatient.amendment.addendum.write
      clinical.outpatient.amendment.addendum.finalize
      rmik.completeness.review
      rmik.completeness.signoff
    ]

    listed = @authorization
      .split("## Replay, capabilities and audit", 2).last
      .split('### Retention, rollback and reset', 2).first
      .scan(/^- `([^`]+)`$/)
      .flatten

    assert_equal expected, listed
    assert_includes @authorization, 'It does not imply request, author, finalizer, RMIK, administrator or break-glass authority.'
  end

  def test_phase_readme_links_the_record_without_overclaim
    readme = File.read(File.join(ROOT, 'docs/new-simrs-rebuild/phase-1/README.md'))

    assert_includes readme, 'OUTPATIENT_POST_CLOSURE_AMENDMENT_LOCAL_ENGINEERING_AUTHORIZATION_2026-08-30.md'
    assert_includes readme, 'Local engineering authorized; Clinical/RMIK and parity acceptance open'
  end
end
