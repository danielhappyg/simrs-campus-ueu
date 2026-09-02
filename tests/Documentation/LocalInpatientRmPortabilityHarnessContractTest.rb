# frozen_string_literal: true

require 'minitest/autorun'
require 'rbconfig'
require 'tempfile'

require_relative '../../scripts/rehearse-local-inpatient-rm-portability'

class LocalInpatientRmPortabilityHarnessContractTest < Minitest::Test
  Harness = LocalInpatientRmPortabilityRehearsal

  def setup
    @source = File.read(File.join(Harness::ROOT, Harness::SCRIPT_PATH), encoding: Encoding::UTF_8)
    @worker = Harness::WORKER_SOURCE
  end

  def test_ruby_and_embedded_php_are_syntactically_valid
    assert system(RbConfig.ruby, '-c', File.join(Harness::ROOT, Harness::SCRIPT_PATH), out: File::NULL, err: File::NULL)
    worker = Tempfile.new(['inpatient-rmik-contract-', '.php'])
    worker.write(@worker)
    worker.flush
    assert system('/opt/homebrew/bin/php', '-l', worker.path, out: File::NULL, err: File::NULL)
    assert_operator @worker.bytesize, :>, 45_000
  ensure
    worker&.close!
  end

  def test_closed_twenty_one_scenario_catalogue_is_exact
    assert_equal %w[
      fresh-migration
      empty-down-reapply
      retained-business-row-down-refusal
      source-bound-draft-coding
      normalized-assignment-rows
      current-zero-blocker-review
      atomic-final-signoff-and-closure
      exact-idempotent-replay
      changed-payload-key-conflict
      stale-review-coding-source-denials
      missing-assignment-blocker-denial
      corrupt-receipt-binding-denial
      concurrent-coding-race
      concurrent-signoff-race
      audit-failure-atomic-rollback
      receipt-failure-atomic-rollback
      append-only-engine-refusal
      least-privilege-runtime
      bounded-synthetic-reset
      retained-audit-down-refusal
      invariant-verification
    ], Harness::SCENARIOS
    assert_equal Harness::SCENARIOS.uniq, Harness::SCENARIOS
    assert_equal %w[concurrent-coding-race concurrent-signoff-race], Harness::RACE_SCENARIOS
  end

  def test_exact_engines_identifiers_and_source_bindings_are_closed
    assert_equal %w[postgresql17 mysql8411], Harness::ENGINES
    assert_equal 'database/migrations/2026_08_31_000900_create_inpatient_rm_closure_tables.php', Harness::MIGRATION_PATH
    %w[INPATIENT_RM_MANUAL_CODING_V1 INPATIENT_RM_COMPLETENESS_V1 INPATIENT_RM_CODING_DRAFT_SAVE INPATIENT_RM_COMPLETENESS_REVIEW_SAVE INPATIENT_RM_EPISODE_SIGNOFF].each do |identifier|
      assert_includes @worker, identifier
    end
    binding = harness.current_rmik_bindings
    assert_equal Harness::SOURCE_PATHS.length, binding.fetch('files').length
    %w[aggregate_sha256 worker_source_sha256 scenario_catalog_sha256].each do |key|
      assert_match(/\A[0-9a-f]{64}\z/, binding.fetch(key))
    end
  end

  def test_normalized_assignment_rows_and_final_state_are_real_service_assertions
    assert_includes @worker, 'app(InpatientRmService::class)->saveCodingDraft('
    assert_includes @worker, "normalized_code === 'A09'"
    assert_includes @worker, "pluck('ordinal')->all() === range(1, count($assignments))"
    assert_includes @worker, 'InpatientRmCoding::STATE_DRAFT'
    assert_includes @worker, 'InpatientRmCoding::STATE_FINAL'
    assert_includes @worker, "review_state === InpatientRmCompletenessReview::STATE_SIGNED_OFF"
    assert_includes @worker, 'signoff review binds exact Final coding version'
    assert_includes @worker, 'READY_FOR_RM atomically closed'
  end

  def test_content_and_operation_digests_and_receipt_fks_are_independently_bound
    assert_includes @worker, 'Draft to Final content digest stable'
    assert_includes @worker, 'operation payload digest distinct from content digest'
    assert_includes @worker, 'coding receipt review FK null'
    assert_includes @worker, 'review receipt exact review FK'
    assert_includes @worker, 'signoff receipt exact review FK'
    assert_includes @worker, 'signoff receipt Final coding version'
    assert_includes @worker, 'signoff receipt stable content digest'
  end

  def test_denials_atomicity_and_same_key_races_use_real_database_processes
    %w[
      assignment_coverage_invalid source_binding_stale stale_coding_version
      stale_review_version review_not_current_complete checklist_incomplete
      idempotency_key_conflict receipt_binding_invalid
    ].each { |reason| assert_includes @worker, reason }
    assert_includes @worker, 'new class extends AuditRecorder'
    assert_includes @worker, 'installRmikReceiptFailureTrigger()'
    parent_source = File.read(File.join(Harness::ROOT, 'scripts/rehearse-local-inpatient-discharge-coding-source-portability.rb'), encoding: Encoding::UTF_8)
    assert_includes parent_source, 'Open3.popen3'
    assert_includes parent_source, 'observe_real_database_wait!'
    assert_includes parent_source, 'pg_blocking_pids'
    assert_includes parent_source, 'performance_schema.data_lock_waits'
    assert_includes @source, "'independent_application_processes' => 2"
    assert_includes @source, 'RACE_SCENARIOS'
    assert_includes @worker, "'rmik-coding-race-'.$token"
    assert_includes @worker, "'rmik-signoff-race-'.$token"
    assert_includes @source, '%w[APPLIED REPLAYED]'
  end

  def test_append_only_least_privilege_reset_and_both_down_refusals_are_closed
    Harness::IMMUTABLE_HISTORY_TABLES.each do |table|
      assert_includes @source, table
      assert_includes Harness::SOURCE_PATHS, 'database/migrations/2026_08_31_000900_create_inpatient_rm_closure_tables.php'
    end
    assert_includes @source, "IMMUTABLE_HISTORY_TABLES.include?(table) ? 'SELECT, INSERT'"
    assert_includes @worker, "app(SyntheticResetService::class)->reset(["
    assert_includes @source, "expect_rmik_rollback_refusal!('retained business evidence exists')"
    assert_includes @source, "expect_rmik_rollback_refusal!('correlated audit evidence remains')"
  end

  def test_evidence_is_0600_sanitized_bound_and_cleanup_is_mandatory
    assert_includes @source, "mode: 'wx', perm: 0o600"
    assert_includes @source, 'File.chmod(0o600, path)'
    assert_includes @source, 'sanitize_evidence!(evidence)'
    assert_includes @source, "'worker_source_sha256'"
    assert_includes @source, "'scenario_catalog_sha256'"
    assert_match(/ensure\n\s+terminate_workers!/, @source)
    assert_includes @source, "cleanup!(strict: true)"
    refute_match(/git\s+(?:add|commit|push)/, @source)
    refute_match(/(?:vercel|supabase)\s+(?:deploy|link|migration|db)/i, @source)
  end

  def test_external_connections_and_integrations_fail_closed
    error = assert_raises(Harness::CommandFailed) do
      Harness.new(engine: 'postgresql17', environment: confirmed_environment.merge('DB_PASSWORD' => 'refused')).assert_contract!
    end
    assert_match(/refuses inherited overrides: DB_PASSWORD/, error.message)
    %w[BPJS_INTEGRATION_ENABLED VCLAIM_ENABLED SATUSEHAT_ENABLED].each do |name|
      assert_includes @source, "'#{name}' => 'false'"
    end
  end

  private

  def confirmed_environment
    { Harness::CONFIRMATION_ENV => Harness::CONFIRMATION, 'DB_URL' => '' }
  end

  def harness
    Harness.new(engine: 'postgresql17', environment: confirmed_environment)
  end
end
