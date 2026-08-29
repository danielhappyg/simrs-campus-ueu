# frozen_string_literal: true

require 'minitest/autorun'
require 'json'
require 'digest'
require 'open3'
require_relative '../../scripts/generate-t1-local-milestone-manifest'

class LocalStructuredRjRmBrowserRehearsalTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  RECORD_PATH = File.join(
    ROOT,
    'docs/operations/T1_LOCAL_STRUCTURED_RJ_RM_BROWSER_REHEARSAL_2026-08-29.md'
  )
  RECEIPT_PATH = File.join(
    ROOT,
    'docs/operations/evidence/T1_LOCAL_STRUCTURED_RJ_RM_BROWSER_REHEARSAL_2026-08-29.json'
  )
  LEDGER_PATH = File.join(
    ROOT,
    'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R3.json'
  )
  RECEIPT_SHA256 = '97f41d8e41a569ff370a4e31f10bb0c2b5b3e78c5216abeda0a401fa9c8583eb'
  LEDGER_SHA256 = '0f312713000b0092de313baee2d3cc6d34695c2fefd717c620871268045ac58c'

  def git(*arguments)
    stdout, stderr, status = Open3.capture3('/usr/bin/git', '-C', ROOT, *arguments)
    raise "git #{arguments.join(' ')} failed: #{stderr.strip}" unless status.success?

    stdout
  end

  def commit_blob(commit, path)
    git('show', "#{commit}:#{path}")
  end

  def setup
    @record = File.read(RECORD_PATH)
    @receipt_bytes = File.binread(RECEIPT_PATH)
    @receipt = JSON.parse(@receipt_bytes)
    @ledger_bytes = File.binread(LEDGER_PATH)
    @ledger = JSON.parse(@ledger_bytes)
  end

  def test_record_is_bounded_local_evidence
    assert_includes @record, '**Status:** `LOCAL / PASS FOR BOUNDED SINGLE-ACCOUNT REHEARSAL`'
    assert_includes @record, 'not hosted UAT, role-separation evidence, domain-owner acceptance, G0 closure, or G3 acceptance'
    assert_includes @record, 'no push, pull request, release, deployment, hosted migration, or Production-alias change'
    assert_includes @record, 'it was not repeated from a clean checkout of that exact SHA'
    assert_includes @record, 'must not be promoted to hosted PASS, owner acceptance, deployment readiness, or G3 completion'
  end

  def test_receipt_hash_binds_source_snapshot_and_transient_evidence_limit
    assert_equal RECEIPT_SHA256, Digest::SHA256.hexdigest(@receipt_bytes)
    assert_includes @record, "SHA-256 `#{RECEIPT_SHA256}`"
    commit = @receipt.dig('source', 'commit_sha')
    assert_equal '8e9f8b44a4abfbfef6ed03cd3f72d1fd2661c214', commit
    assert_equal commit, git('rev-parse', "#{commit}^{commit}").strip
    assert_equal @receipt.dig('source', 'tree_sha'), git('rev-parse', "#{commit}^{tree}").strip
    assert_equal 'working_tree_bytes_subsequently_committed_not_clean_exact_sha_execution',
                 @receipt.dig('source', 'browser_source_state')

    @receipt.dig('source', 'files').each do |source|
      assert_equal source.fetch('sha256'), Digest::SHA256.hexdigest(commit_blob(commit, source.fetch('path')))
    end

    assert_raises(RuntimeError) { commit_blob(commit, 'resources/js/pages/rm/rawat-jalan/not-the-bound-source.tsx') }
    refute_equal @receipt.dig('source', 'files', 0, 'sha256'),
                 Digest::SHA256.hexdigest(commit_blob(commit, @receipt.dig('source', 'files', 1, 'path')))

    assert_equal 'transient_unretained_task_observation_not_external_attestation',
                 @receipt.dig('independent_review_observation', 'retention')
    assert_nil @receipt.dig('independent_review_observation', 'review_reference')
    assert_nil @receipt.dig('independent_review_observation', 'review_sha256')
  end

  def test_receipt_binds_retained_r3_and_current_fail_closed_stale_state
    assert_equal LEDGER_SHA256, Digest::SHA256.hexdigest(@ledger_bytes)
    assert_equal LEDGER_SHA256, @receipt.dig('gate_binding', 'ledger_sha256')
    assert_equal 'unavailable', @ledger.dig('governance_profile_binding', 'status')
    assert_equal 'pointer_missing', @ledger.dig('governance_profile_binding', 'reason_code')
    assert_equal 268, @ledger.fetch('capabilities').length
    assert_equal 0, @ledger.fetch('capabilities').count { |row| !row['governance_decision_pointer'].nil? }
    assert_equal 268, @ledger.fetch('capabilities').count { |row| row.dig('governance', 'governance_state') == 'PENDING' }
    assert_equal 0, @ledger.fetch('capabilities').count { |row| row.dig('governance', 'implementation_authorized') }
    assert_equal 'OPEN', @ledger.dig('gate_summary', 'g0', 'status')
    assert_equal 'OPEN', @ledger.dig('gate_summary', 'g3', 'status')

    gate = @receipt.fetch('gate_binding')
    assert_equal 'latest_retained_schema_v2_observation_not_current_after_engineering_projection_drift', gate.fetch('ledger_role')
    assert_equal 'FAIL_STALE_ENGINEERING_PROJECTION', gate.fetch('currentness_check_result')
    assert_equal '$.engineering_evidence_map_v2: generated document differs from exact engineering projection',
                 gate.fetch('currentness_check_error')
    assert_equal false, gate.fetch('canonical_pointer_present_at_check')
    assert_equal false, gate.fetch('recovery_marker_present_at_check')
    assert_equal 'unavailable', gate.fetch('retained_ledger_governance_profile_status')
    assert_equal 'pointer_missing', gate.fetch('retained_ledger_governance_reason_code')
    assert_equal 268, gate.fetch('capabilities_total')
    assert_equal 0, gate.fetch('retained_ledger_governance_pointers_present')
    assert_equal 268, gate.fetch('retained_ledger_governance_rows_pending')
    assert_equal 0, gate.fetch('retained_ledger_implementation_authorized_rows')
    assert_equal 'OPEN', gate.fetch('retained_ledger_g0_status')
    assert_equal 'OPEN', gate.fetch('retained_ledger_g3_status')
    assert_equal 'G0_AND_G3_OPEN_UNPROVEN_NO_CURRENT_LEDGER', gate.fetch('current_fail_closed_conclusion')
    assert_includes @record, 'R3 must therefore **not** be treated as a current exact-engineering ledger.'
  end

  def test_record_preserves_the_observed_structured_journey
    %w[
      clinical.nursing.draft.save
      clinical.nursing.finalize
      clinical.medical.draft.save
      clinical.medical.finalize
      clinical.lab.order.create
      clinical.lab.result.write
      rmik.completeness.review.save
      rmik.completeness.signoff
    ].each { |action| assert_includes @record, "`#{action}`" }

    assert_includes @record, '| Clinical document heads | 2 |'
    assert_includes @record, '| Clinical document versions | 4 |'
    assert_includes @record, '| RM completeness reviews | 2 |'
    assert_includes @record, '| Persisted checklist items | 14 |'
    assert_includes @record, '| Active laboratory orders | 0 |'
    assert_includes @record, 'review v2 `SIGNED_OFF`; encounter `CLOSED`; zero mutation controls'
  end

  def test_record_discloses_the_single_account_and_exact_sha_gaps
    assert_includes @record, 'one multi-role teaching account rather than four isolated dedicated accounts'
    assert_includes @record, 'The exact browser product/version was not captured.'
    assert_includes @record, 'no sign-off audit row because request validation rejected the stale version'
    assert_includes @record, 'does not prove role activation/revocation, separation of duties, wrong-role denial'
  end

  def test_record_and_receipt_are_secret_free_and_identifiers_are_minimized
    T1LocalMilestoneManifest.reject_secret_bytes!(@record, 'local rehearsal record')
    T1LocalMilestoneManifest.reject_secret_bytes!(@receipt_bytes, 'local rehearsal receipt')

    secret_assignment = /(?:password|passwd|passphrase|secret|api[_ -]?key|access[_ -]?token|refresh[_ -]?token|authorization|cookie|private[_ -]?key|connection[_ -]?string)\s*[:=]\s*[^\s,;}]+/i
    refute_match(secret_assignment, @record)
    refute_match(secret_assignment, @receipt_bytes)
    refute_includes @receipt.fetch('journey').keys, 'patient_name'
    assert_match(/\ASYNTH-[A-Z0-9-]+\z/, @receipt.dig('journey', 'medical_record_number'))
    assert_match(/\A[0-9A-Z]{26}\z/, @receipt.dig('journey', 'encounter_public_id'))
    @receipt.fetch('audit_events').each do |event|
      assert_match(/\A[0-9A-Z]{26}\z/, event.fetch('resource_public_id'))
    end

    assert_equal false, @receipt.dig('secret_handling', 'credentials_recorded')
    assert_equal false, @receipt.dig('secret_handling', 'tokens_recorded')
    assert_equal false, @receipt.dig('secret_handling', 'private_keys_recorded')
    assert_equal false, @receipt.dig('secret_handling', 'connection_strings_recorded')
    assert_equal false, @receipt.dig('secret_handling', 'real_patient_data_recorded')
    assert_equal false, @receipt.dig('authority', 'live_integrations_used')
    assert_includes @record, 'No credential, password, cookie, session identifier, connection string'
    assert_includes @record, 'real-patient suitability or any live BPJS/VClaim/SATUSEHAT'
  end
end
