#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'json'
require 'securerandom'
require 'time'

require_relative 'rehearse-local-portability-full-suite'

# Fail-closed exact-engine harness scaffold for the authorized synthetic
# medication-replenishment and warehouse-to-depot custody slice. Local domain,
# migration, guard, and focused SQLite test sources exist, but their final
# security-corrected inventory, runtime grants, and exact-engine worker are not
# frozen or bound here. This harness can therefore emit only an immutable
# READY_NOT_RUN readiness record; it cannot execute or claim a scenario PASS.
class LocalMedicationReplenishmentWarehouseDepotCustodyPortabilityRehearsal < LocalPortabilityFullSuiteRehearsal
  CONFIRMATION_ENV = 'SIMRS_MEDICATION_REPLENISHMENT_WAREHOUSE_DEPOT_PORTABILITY_CONFIRM'
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_MEDICATION_REPLENISHMENT_WAREHOUSE_DEPOT'
  SCRIPT_PATH = 'scripts/rehearse-local-medication-replenishment-warehouse-depot-custody-portability.rb'
  CONTRACT_PATH = 'tests/Documentation/LocalMedicationReplenishmentWarehouseDepotCustodyPortabilityHarnessContractTest.rb'
  TEMPLATE_CONTRACT_PATH = 'tests/Documentation/LocalMedicationReplenishmentWarehouseDepotCustodyEvidenceTemplateTest.rb'
  AUTHORIZATION_CONTRACT_PATH = 'tests/Documentation/MedicationReplenishmentAndWarehouseDepotCustodyV1LocalEngineeringAuthorizationTest.rb'
  AUTHORIZATION_PATH = 'docs/new-simrs-rebuild/phase-1/MEDICATION_REPLENISHMENT_AND_WAREHOUSE_DEPOT_CUSTODY_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md'
  EVIDENCE_TEMPLATE_PATH = 'docs/operations/T1_LOCAL_MEDICATION_REPLENISHMENT_WAREHOUSE_DEPOT_CUSTODY_EVIDENCE_TEMPLATE_2026-09-03.md'
  BASE_HARNESS_PATH = 'scripts/rehearse-local-portability-full-suite.rb'
  EVIDENCE_KIND = 'SIMRS_LOCAL_MEDICATION_REPLENISHMENT_WAREHOUSE_DEPOT_CUSTODY_PORTABILITY'
  EXECUTION_STATE = 'READY_NOT_RUN'
  POSTGRES_VERSION = '17.10'
  MYSQL_VERSION = '8.4.11'

  SCENARIOS = %w[
    fresh-migration
    failed-install-guard-reapply
    empty-down-reapply
    constraints-and-append-only-triggers
    shortened-cross-engine-identifier-inventory
    exact-least-privilege-runtime-grants
    runtime-reset-bypass-denial
    supplier-medicine-depot-version-binding-and-retirement
    purchase-order-lifecycle-and-independent-approval
    purchase-order-role-separation-and-route-denial
    approved-po-exact-receipt-binding
    partial-full-receipt-and-variance-quarantine
    duplicate-receipt-reference-and-over-receipt-refusal
    paired-fefo-dispatch-and-transit-conservation
    destination-accept-reject-full-transfer
    existing-pharmacy-consumption-after-accepted-stock
    supplier-return-linked-retained-receipt
    unit-return-linked-accepted-transfer
    append-only-correction-independent-review
    stock-card-and-custody-conservation-reconciliation
    idempotency-replay-and-conflict
    competing-receipts-real-database-wait
    dispatch-versus-pharmacy-handover-real-database-wait
    accept-versus-reject-real-race
    third-connection-custody-control-total-readback
    audit-failure-atomic-rollback
    tamper-recovery-and-bounded-reset
    populated-migration-rollback-refusal
    strict-cleanup
  ].freeze
  SCENARIO_CATALOG_SHA256 = 'ff9ee46bf8ed03380d1ace70a69c9ec332c186b5c93d49e68bea2a567aee23c5'

  # This list is closed and deliberately contains only sources exercised by the
  # current readiness scaffold. Future exact-engine enablement must add each
  # frozen migration, domain, reset/recovery, guard, projection, worker, and
  # focused test path individually; broad directory globs are prohibited.
  SOURCE_PATHS = [
    SCRIPT_PATH,
    BASE_HARNESS_PATH,
    CONTRACT_PATH,
    TEMPLATE_CONTRACT_PATH,
    AUTHORIZATION_CONTRACT_PATH,
    AUTHORIZATION_PATH,
    EVIDENCE_TEMPLATE_PATH
  ].freeze

  # Empty groups are executable blockers, not permission to omit sources. They
  # preserve the template placeholders without prematurely freezing paths from
  # the still-changing local security-correction set.
  EXECUTION_SOURCE_GROUPS = {
    'migration_paths' => [].freeze,
    'domain_implementation_paths' => [].freeze,
    'guard_projection_reset_recovery_paths' => [].freeze,
    'focused_feature_unit_test_paths' => [].freeze,
    'worker_paths' => [].freeze
  }.freeze
  RUNTIME_GRANT_CATALOG = {}.freeze
  SQLITE_GATE_CATALOG = [].freeze
  COMMAND_CATALOG = [].freeze

  FORBIDDEN_ENVIRONMENT = %w[
    SIMRS_PORTABILITY_REHEARSAL_CONFIRM
    DB_URL DATABASE_URL DATABASE_DSN DATABASE_CONNECTION_STRING
    DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_SOCKET DB_SCHEMA
    PGHOST PGPORT PGUSER PGPASSWORD PGSERVICE PGSERVICEFILE PGDATABASE
    MYSQL_HOST MYSQL_TCP_PORT MYSQL_USER MYSQL_PWD MYSQL_DATABASE
    POSTGRES17_BIN MYSQL84_BIN PHP_BINARY GIT_BINARY
    APP_ENV APP_URL APP_KEY APP_MODE APP_SYNTHETIC_ONLY
    SUPABASE_URL SUPABASE_DB_URL SUPABASE_DATABASE_URL SUPABASE_ANON_KEY SUPABASE_SERVICE_ROLE_KEY
    VERCEL_URL VERCEL_ENV VERCEL_PROJECT_ID VERCEL_ORG_ID VERCEL_TOKEN
    BPJS_BASE_URL BPJS_CONSUMER_ID BPJS_CONSUMER_SECRET BPJS_USER_KEY BPJS_INTEGRATION_ENABLED
    VCLAIM_BASE_URL VCLAIM_CONSUMER_ID VCLAIM_CONSUMER_SECRET VCLAIM_USER_KEY VCLAIM_ENABLED
    EKLAIM_BASE_URL EKLAIM_KEY E_KLAIM_BASE_URL E_KLAIM_KEY
    SATUSEHAT_BASE_URL SATUSEHAT_CLIENT_ID SATUSEHAT_CLIENT_SECRET SATUSEHAT_ENABLED
    LIS_BASE_URL LIS_API_KEY LIS_INTEGRATION_ENABLED
    PACS_BASE_URL PACS_API_KEY PACS_INTEGRATION_ENABLED
    MAIL_URL MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_ENCRYPTION MAIL_FROM_ADDRESS
    POSTMARK_API_KEY RESEND_API_KEY AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN
    REDIS_URL REDIS_HOST REDIS_PORT REDIS_USERNAME REDIS_PASSWORD
    HTTP_PROXY HTTPS_PROXY ALL_PROXY
  ].freeze
  SECRET_ENVIRONMENT_NAME = /(?:PASSWORD|SECRET|SECRET_KEY|TOKEN|API_KEY|PRIVATE_KEY|ACCESS_KEY_ID|HMAC_KEY|CLIENT_SECRET|USER_KEY|COOKIE|CREDENTIAL|CONNECTION_STRING|DATABASE_URL|DB_URL|DSN)\z/i
  EXTERNAL_ENVIRONMENT_PREFIX = /\A(?:DB_|DATABASE_|PG(?:HOST|PORT|USER|PASSWORD|SERVICE|SERVICEFILE|DATABASE)|MYSQL_|POSTGRES|SUPABASE_|VERCEL_|BPJS_|VCLAIM_|E_?KLAIM_|SATUSEHAT_|LIS_|PACS_|MAIL_|AWS_|AZURE_|GCP_|GOOGLE_|REDIS_|SENTRY_|STRIPE_)/i

  def initialize(engine:, environment: ENV.to_h, runner: Runner.new, monotonic_clock: nil)
    @operator_environment = environment.dup
    super(
      engine: engine,
      environment: environment.merge(
        'SIMRS_PORTABILITY_REHEARSAL_CONFIRM' => LocalPortabilityFullSuiteRehearsal::CONFIRMATION
      ),
      runner: runner,
      monotonic_clock: monotonic_clock
    )
  end

  def run!
    assert_contract!
    bindings = current_source_bindings
    blockers = execution_blockers
    raise CommandFailed, 'Warehouse custody scaffold unexpectedly became executable without a scenario executor.' if blockers.empty?

    assert_unchanged_binding!('Warehouse custody readiness bindings', bindings, current_source_bindings)

    # No engine, database, identity, credential, worker, or child process has
    # been created. The inherited strict cleanup is still mandatory before the
    # exclusive readiness record is written.
    cleanup!(strict: true)
    evidence_path = write_ready_not_run_evidence!(bindings: bindings, blockers: blockers)

    {
      'status' => EXECUTION_STATE,
      'claim' => 'LOCAL_WAREHOUSE_CUSTODY_PORTABILITY_READINESS_ONLY',
      'engine' => @engine,
      'scenario_count' => SCENARIOS.length,
      'evidence_path' => evidence_path,
      'evidence_sha256' => Digest::SHA256.file(evidence_path).hexdigest,
      'blocking_reasons' => blockers
    }
  ensure
    cleanup!
  end

  def assert_contract!
    unless @operator_environment[CONFIRMATION_ENV] == CONFIRMATION
      raise CommandFailed, "Set #{CONFIRMATION_ENV}=#{CONFIRMATION} to authorize the disposable local rehearsal."
    end

    rejected = rejected_environment_names
    unless rejected.empty?
      raise CommandFailed, "Warehouse custody rehearsal refuses inherited database, secret, or external overrides: #{rejected.join(', ')}."
    end

    super
    raise CommandFailed, 'Warehouse custody SOURCE_PATHS must be closed and duplicate-free.' unless SOURCE_PATHS.uniq == SOURCE_PATHS
    SOURCE_PATHS.each { |path| safe_source_path(path) }
    EXECUTION_SOURCE_GROUPS.each_value do |paths|
      raise CommandFailed, 'Warehouse custody execution source group contains a duplicate path.' unless paths.uniq == paths
      paths.each { |path| safe_source_path(path) }
    end
    unless SCENARIOS.length == 29 && SCENARIOS.uniq.length == 29 &&
           Digest::SHA256.hexdigest(JSON.generate(SCENARIOS)) == SCENARIO_CATALOG_SHA256
      raise CommandFailed, 'Warehouse custody scenario catalogue drifted from the exact closed 29-scenario contract.'
    end
  end

  def current_source_bindings
    files = SOURCE_PATHS.to_h do |path|
      [path, Digest::SHA256.file(safe_source_path(path)).hexdigest]
    end
    aggregate = Digest::SHA256.hexdigest(JSON.generate(files.sort.to_h))

    {
      'binding_state' => 'INCOMPLETE_READY_NOT_RUN',
      'files' => files,
      'aggregate_sha256' => aggregate,
      'application_source_sha256' => aggregate,
      'worker_source_sha256' => 'NOT_BOUND',
      'scenario_catalog_sha256' => SCENARIO_CATALOG_SHA256,
      'runtime_grant_catalog_sha256' => 'NOT_BOUND',
      'sqlite_gate_catalog_sha256' => 'NOT_BOUND',
      'command_catalog_sha256' => 'NOT_BOUND'
    }
  end

  def execution_blockers
    group_blockers = EXECUTION_SOURCE_GROUPS.each_with_object([]) do |(group, paths), blockers|
      blockers << "#{group}_not_bound" if paths.empty?
    end
    catalogue_blockers = []
    catalogue_blockers << 'runtime_grant_catalog_not_bound' if RUNTIME_GRANT_CATALOG.empty?
    catalogue_blockers << 'sqlite_gate_catalog_not_bound' if SQLITE_GATE_CATALOG.empty?
    catalogue_blockers << 'command_catalog_not_bound' if COMMAND_CATALOG.empty?

    (group_blockers + catalogue_blockers + ['exact_29_scenario_executor_not_implemented']).freeze
  end

  private

  def rejected_environment_names
    @operator_environment.each_with_object([]) do |(name, value), rejected|
      next if name == CONFIRMATION_ENV || value.to_s.strip.empty?
      next unless FORBIDDEN_ENVIRONMENT.include?(name) ||
                  name.match?(SECRET_ENVIRONMENT_NAME) ||
                  name.match?(EXTERNAL_ENVIRONMENT_PREFIX)

      rejected << name
    end.uniq.sort
  end

  def write_ready_not_run_evidence!(bindings:, blockers:)
    assert_evidence_directory!
    timestamp = Time.now.utc.strftime('%Y%m%dT%H%M%SZ')
    path = File.join(
      EVIDENCE_DIRECTORY,
      "#{timestamp}-#{@engine}-medication-replenishment-warehouse-depot-custody-ready-not-run-#{SecureRandom.hex(6)}.json"
    )
    evidence = {
      'schema_version' => 1,
      'kind' => EVIDENCE_KIND,
      'status' => EXECUTION_STATE,
      'execution_state' => EXECUTION_STATE,
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_WAREHOUSE_CUSTODY_PORTABILITY_READINESS_ONLY',
      'owner_acceptance_claim' => false,
      'g0_claim' => false,
      'g3_claim' => false,
      'deployment_claim' => false,
      'source_bindings' => bindings,
      'engine' => {
        'requested' => @engine,
        'required_exact_version' => @engine == 'postgresql17' ? POSTGRES_VERSION : MYSQL_VERSION,
        'version_readback' => 'NOT_RUN'
      },
      'scenario_catalog' => SCENARIOS.map { |scenario| { 'scenario' => scenario, 'status' => 'NOT_RUN' } },
      'scenario_summary' => { 'total' => 29, 'passed' => 0, 'not_run' => 29 },
      'blocking_reasons' => blockers,
      'boundary' => {
        'application_mode_required' => 'SIMULATION',
        'synthetic_only_required' => true,
        'external_database_configuration_accepted' => false,
        'live_integrations_enabled' => false,
        'engine_or_database_started' => false,
        'worker_started' => false
      },
      'cleanup' => {
        'strict_cleanup_verified' => true,
        'database_removed' => true,
        'temporary_server_removed' => true,
        'temporary_user_state_removed' => true,
        'temporary_worker_removed' => true,
        'no_disposable_state_was_created' => true
      },
      'open_boundaries' => [
        'READY_NOT_RUN is a readiness record, not exact-engine execution evidence or a PASS.',
        'Warehouse local source exists, but the final migration/domain/guard/test inventory, worker, runtime grants, and all 29 scenario proofs remain unbound.',
        'This record is not owner, procurement, pharmacy, warehouse, clinical, finance-accounting, security, recovery, parity, G0, G3, hosted-UAT, deployment, or production acceptance.'
      ]
    }

    sanitize_evidence!(evidence)
    File.write(path, JSON.pretty_generate(evidence) + "\n", mode: 'wx', perm: 0o600)
    File.chmod(0o600, path)
    stat = File.lstat(path)
    unless stat.file? && !stat.symlink? && (stat.mode & 0o777) == 0o600
      raise CommandFailed, 'Warehouse custody readiness artifact must be a non-symlink mode-0600 regular file.'
    end
    path
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    unless ARGV.length == 1 && LocalMedicationReplenishmentWarehouseDepotCustodyPortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    result = LocalMedicationReplenishmentWarehouseDepotCustodyPortabilityRehearsal.new(engine: ARGV.first).run!
    puts JSON.generate(result)
  rescue LocalMedicationReplenishmentWarehouseDepotCustodyPortabilityRehearsal::CommandFailed => e
    warn "warehouse custody portability rehearsal failed: #{e.message}"
    exit 1
  end
end
