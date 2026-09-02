# frozen_string_literal: true

require 'minitest/autorun'
require 'rbconfig'
require 'tempfile'

require_relative '../../scripts/rehearse-local-inpatient-discharge-coding-source-portability'

class LocalInpatientDischargeCodingSourcePortabilityHarnessContractTest < Minitest::Test
  Harness = LocalInpatientDischargeCodingSourcePortabilityRehearsal

  def setup
    @source = File.read(File.join(Harness::ROOT, Harness::SCRIPT_PATH), encoding: Encoding::UTF_8)
    @worker = Harness::WORKER_SOURCE
  end

  def test_ruby_and_embedded_php_are_syntactically_valid
    assert system(RbConfig.ruby, '-c', File.join(Harness::ROOT, Harness::SCRIPT_PATH), out: File::NULL, err: File::NULL)
    worker = Tempfile.new(['coding-source-contract-', '.php'])
    worker.write(@worker)
    worker.flush
    assert system('/opt/homebrew/bin/php', '-l', worker.path, out: File::NULL, err: File::NULL)
    assert_operator @worker.bytesize, :>, 20_000
  ensure
    worker&.close!
  end

  def test_closed_nineteen_scenario_catalogue_is_exact
    assert_equal %w[
      fresh-migration
      empty-down-reapply
      draft-then-final
      validation-and-authority-denials
      terminal-final-refusal
      exact-idempotent-replay
      changed-payload-key-conflict
      corrupt-replay-binding-denial
      discharge-final-source-gate
      same-source-competing-finalization
      transfer-vs-source-source-first
      transfer-vs-source-transfer-first
      audit-failure-atomic-rollback
      receipt-failure-atomic-rollback
      append-only-engine-refusal
      least-privilege-runtime
      bounded-synthetic-reset
      populated-evidence-down-refusal
      invariant-verification
    ], Harness::SCENARIOS
    assert_equal Harness::SCENARIOS.uniq, Harness::SCENARIOS
    assert_equal %w[same-source-competing-finalization transfer-vs-source-source-first transfer-vs-source-transfer-first], Harness::RACE_SCENARIOS
  end

  def test_exact_identifiers_engines_and_source_binding_are_closed
    assert_equal %w[postgresql17 mysql8411], Harness::ENGINES
    assert_equal 'database/migrations/2026_08_31_000800_create_inpatient_discharge_coding_source_tables.php', Harness::MIGRATION_PATH
    assert_includes @worker, "InpatientDischargeCodingSource::DEFINITION_VERSION === 'INPATIENT_DISCHARGE_CODING_SOURCE_V1'"
    assert_includes @worker, 'DISCHARGE_CODING_SOURCE_DRAFT_SAVE'
    assert_includes @worker, 'DISCHARGE_CODING_SOURCE_FINALIZE'
    binding = harness.current_discharge_bindings
    assert_equal Harness::SOURCE_PATHS.length, binding.fetch('files').length
    %w[aggregate_sha256 worker_source_sha256 scenario_catalog_sha256].each { |key| assert_match(/\A[0-9a-f]{64}\z/, binding.fetch(key)) }
    %w[app/Support/Inpatient/InpatientDischargeCodingSourceService.php app/Support/Inpatient/InpatientDischargeService.php app/Support/Inpatient/InpatientBedTransferService.php database/migrations/2026_08_31_000800_create_inpatient_discharge_coding_source_tables.php tests/Feature/Inpatient/InpatientDischargeCodingSourceTest.php].each do |path|
      assert_includes Harness::SOURCE_PATHS, path
    end
  end

  def test_real_service_covers_validation_authority_replay_and_discharge_gate
    assert_includes @worker, 'app(InpatientDischargeCodingSourceService::class)->saveDraft('
    assert_includes @worker, 'app(InpatientDischargeCodingSourceService::class)->finalize('
    %w[procedure\ attestation\ validation\ failed\ closed RMIK\ cannot\ author\ physician\ coding\ source only\ assigned\ physician\ may\ author idempotency_key_conflict receipt_binding_invalid discharge_coding_source_not_final discharge\ binds\ exact\ coding\ source\ Final\ version discharge_coding_source_content_digest discharge_coding_source_provenance_digest].each do |needle|
      assert_includes @worker, needle.gsub('\\ ', ' ')
    end
  end

  def test_races_use_two_real_processes_native_wait_and_durable_verification
    %w[Open3.popen3 observe_real_database_wait! pg_blocking_pids performance_schema.data_lock_waits].each { |needle| assert_includes @source, needle }
    assert_includes @source, "'independent_application_processes' => 2"
    assert_includes @source, "'harness_bed_prelock' => false"
    assert_includes @source, "'durable_third_connection_assertions' => true"
    assert_includes @worker, "finalizeSource($fixture['source_first_encounter']"
    assert_includes @worker, "finalizeSource($fixture['transfer_first_encounter']"
    assert_includes @worker, 'terminal_transfer_denial'
    assert_includes @worker, 'InpatientLocationEvent::TYPE_ADMISSION'
    assert_includes @worker, 'InpatientLocationEvent::TYPE_TRANSFER'
    refute_includes @worker, 'GET_LOCK('
    refute_includes @worker, 'pg_advisory_lock'
  end

  def test_atomicity_append_only_privileges_and_reset_are_real
    %w[new\ class\ extends\ AuditRecorder installReceiptFailureTrigger\(\) idsor_fail_insert_trg failure_head_rows].each { |needle| assert_includes @worker, needle.gsub('\\ ', ' ').gsub('\\(', '(').gsub('\\)', ')') }
    assert_includes @worker, "SchemaQualifier::table('inpatient_discharge_coding_source_versions')"
    assert_includes @worker, "SchemaQualifier::table('inpatient_discharge_coding_source_operation_receipts')"
    assert_includes @worker, 'TRUNCATE TABLE '
    assert_includes @source, "IMMUTABLE_HISTORY_TABLES.include?(table) ? 'SELECT, INSERT'"
    assert_includes @worker, 'app(SyntheticResetService::class)->reset(['
    assert_includes @source, "expect_rollback_refusal!('correlated audit evidence remains')"
  end

  def test_evidence_is_mode_0600_sanitized_and_cleanup_is_mandatory
    assert_includes @source, "mode: 'wx', perm: 0o600"
    assert_includes @source, 'File.chmod(0o600, path)'
    assert_includes @source, 'sanitize_evidence!(evidence)'
    assert_includes @source, "'database_removed' => true"
    assert_includes @source, "'temporary_worker_removed' => true"
    assert_match(/ensure\n\s+terminate_workers!/, @source)
    refute_match(/git\s+(?:add|commit|push)/, @source)
    refute_match(/(?:vercel|supabase)\s+(?:deploy|link|migration|db)/i, @source)
  end

  def test_external_connections_and_integrations_fail_closed
    error = assert_raises(Harness::CommandFailed) { Harness.new(engine: 'postgresql17', environment: confirmed_environment.merge('DB_PASSWORD' => 'refused')).assert_contract! }
    assert_match(/refuses inherited overrides: DB_PASSWORD/, error.message)
    %w[BPJS_INTEGRATION_ENABLED VCLAIM_ENABLED SATUSEHAT_ENABLED].each { |name| assert_includes @source, "'#{name}' => 'false'" }
  end

  private

  def confirmed_environment
    { Harness::CONFIRMATION_ENV => Harness::CONFIRMATION, 'DB_URL' => '' }
  end

  def harness
    Harness.new(engine: 'postgresql17', environment: confirmed_environment)
  end
end
