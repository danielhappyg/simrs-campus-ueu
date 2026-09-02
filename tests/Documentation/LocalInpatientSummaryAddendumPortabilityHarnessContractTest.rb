# frozen_string_literal: true

require 'minitest/autorun'
require 'rbconfig'
require 'tempfile'

require_relative '../../scripts/rehearse-local-inpatient-summary-addendum-portability'

class LocalInpatientSummaryAddendumPortabilityHarnessContractTest < Minitest::Test
  Harness = LocalInpatientSummaryAddendumPortabilityRehearsal

  def setup
    @source = File.read(File.join(Harness::ROOT, Harness::SCRIPT_PATH), encoding: Encoding::UTF_8)
    @worker = Harness::WORKER_SOURCE
  end

  def test_ruby_and_embedded_php_are_syntactically_valid
    assert system(RbConfig.ruby, '-c', File.join(Harness::ROOT, Harness::SCRIPT_PATH), out: File::NULL, err: File::NULL)
    worker = Tempfile.new(['inpatient-summary-addendum-contract-', '.php'])
    worker.write(@worker)
    worker.flush
    assert system('/opt/homebrew/bin/php', '-l', worker.path, out: File::NULL, err: File::NULL)
    assert_operator @worker.bytesize, :>, 55_000
  ensure
    worker&.close!
  end

  def test_closed_thirteen_scenario_catalogue_is_exact
    assert_equal %w[
      fresh-migration
      empty-down-reapply
      request-different-physician-approval
      draft-final-addendum
      rmik-draft-signed-off
      closed-baseline-immutability
      exact-idempotent-replay
      changed-payload-key-conflict
      append-only-and-head-guard-refusal
      least-privilege-runtime
      bounded-reset-audit-preservation
      retained-evidence-rollback-refusal
      invariant-verification
    ], Harness::SCENARIOS
    assert_equal Harness::SCENARIOS.uniq, Harness::SCENARIOS
  end

  def test_exact_engines_migration_and_source_bindings_are_closed
    assert_equal %w[postgresql17 mysql8411], Harness::ENGINES
    assert_equal 'database/migrations/2026_08_31_001000_create_inpatient_summary_addendum_tables.php', Harness::MIGRATION_PATH
    assert_equal 'SIMRS_LOCAL_INPATIENT_SUMMARY_ADDENDUM_PORTABILITY', Harness::EVIDENCE_KIND
    binding = harness.current_addendum_bindings
    assert_equal Harness::SOURCE_PATHS.length, binding.fetch('files').length
    %w[aggregate_sha256 worker_source_sha256 scenario_catalog_sha256].each do |key|
      assert_match(/\A[0-9a-f]{64}\z/, binding.fetch(key))
    end
  end

  def test_request_approval_addendum_and_rmik_terminal_flow_uses_real_services
    assert_includes @worker, 'app(InpatientSummaryAddendumService::class)'
    assert_includes @worker, 'self_decision_forbidden'
    assert_includes @worker, 'different physician Approved'
    assert_includes @worker, 'InpatientSummaryAddendum::STATE_DRAFT'
    assert_includes @worker, 'InpatientSummaryAddendum::STATE_FINAL'
    assert_includes @worker, 'InpatientSummaryAddendumReview::STATE_DRAFT'
    assert_includes @worker, 'InpatientSummaryAddendumReview::STATE_SIGNED_OFF'
    assert_includes @worker, 'InpatientSummaryCorrectionRequest::STATE_CONSUMED'
    assert_includes @worker, 'request exact replay'
    assert_includes @worker, 'changed payload same key conflict'
  end

  def test_closed_encounter_and_exact_baseline_are_asserted_immutable
    assert_includes @worker, 'baselineTuple('
    assert_includes @worker, "'discharge_public_id'"
    assert_includes @worker, "'summary_public_id'"
    assert_includes @worker, "'source_public_id'"
    assert_includes @worker, "'coding_public_id'"
    assert_includes @worker, "'location_count'"
    assert_includes @worker, 'CLOSED and baseline clinical tuple immutable'
    assert_includes @worker, 'encounter remains CLOSED'
  end

  def test_append_only_head_guards_and_least_privilege_are_exact
    Harness::IMMUTABLE_HISTORY_TABLES.each do |table|
      assert_includes @source, table
      assert_includes @worker, table
    end
    Harness::HEAD_TABLES.each { |table| assert_includes @source, table }
    Harness::RUNTIME_READ_TABLES.each { |table| assert_equal 'SELECT', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    Harness::HEAD_TABLES.each { |table| assert_equal 'SELECT, INSERT, UPDATE', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    Harness::RUNTIME_APPEND_TABLES.each { |table| assert_equal 'SELECT, INSERT', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    assert_includes @worker, 'request_head_invalid_transition'
    assert_includes @worker, 'final_addendum_head_mutation'
    assert_includes @worker, 'runSummaryAddendumPrivilegeGuards'
    assert_includes @worker, 'unrelated_encounter_update'
    assert_includes @worker, 'audit_delete'
    assert_includes @worker, 'node_head_delete'
    assert_includes @source, "'read_prerequisites' => 'SELECT_ONLY'"
    assert_includes @source, "'node_heads' => 'SELECT_INSERT_UPDATE_NO_DELETE'"
    assert_includes @source, "'immutable_history_and_audit' => 'SELECT_INSERT_ONLY'"
    assert_includes @source, 'runtime grant table missing'
    assert_includes @source, 'grants outside the closed map'
    assert_includes @source, 'schema wildcard grant'
  end

  def test_sequential_harness_does_not_overclaim_concurrent_races
    assert_includes @source, 'sequential replay and conflict handling only'
    assert_includes @source, 'simultaneous-worker race behavior is not claimed'
  end

  def test_reset_preserves_audit_and_rollback_refusal_is_required
    assert_includes @worker, 'app(SyntheticResetService::class)->reset(['
    assert_includes @worker, 'clinical addendum audit preserved'
    assert_includes @worker, 'RMIK addendum audit preserved'
    assert_includes @worker, 'reset audit preserved'
    assert_includes @source, "expect_addendum_rollback_refusal!('correlated audit remains')"
  end

  def test_evidence_is_sanitized_bound_0600_and_cleanup_is_mandatory
    assert_includes @source, "mode: 'wx', perm: 0o600"
    assert_includes @source, 'File.chmod(0o600, path)'
    assert_includes @source, 'sanitize_evidence!(evidence)'
    assert_includes @source, "'worker_source_sha256'"
    assert_includes @source, "'SIMRS_DISCHARGE_SCENARIO' => scenario"
    assert_includes @source, "'SIMRS_DISCHARGE_WORKER' => worker"
    assert_includes @source, "cleanup!(strict: true)"
    assert_match(/ensure\n\s+terminate_workers!/, @source)
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
