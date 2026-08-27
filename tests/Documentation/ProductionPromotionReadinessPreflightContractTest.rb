# frozen_string_literal: true

require 'minitest/autorun'
require 'digest'
require 'json'

class ProductionPromotionReadinessPreflightContractTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  SQL_PATH = File.join(
    ROOT,
    'docs/operations/T1_PRODUCTION_PROMOTION_READINESS_PREFLIGHT_2026-08-27.sql'
  )
  RECORD_PATH = File.join(
    ROOT,
    'docs/operations/T1_PRODUCTION_PROMOTION_READINESS_2026-08-27.md'
  )
  RESULT_PATH = File.join(
    ROOT,
    'docs/operations/T1_PRODUCTION_PROMOTION_READINESS_RESULT_2026-08-27.json'
  )
  SUPERSEDED_RESULT_PATH = File.join(
    ROOT,
    'docs/operations/T1_PRODUCTION_PROMOTION_READINESS_RESULT_B3F9EB48_2026-08-27.json'
  )
  SUPERSEDED_SQL_PATH = File.join(
    ROOT,
    'docs/operations/T1_PRODUCTION_PROMOTION_READINESS_PREFLIGHT_B3F9EB48_2026-08-27.sql'
  )

  CANDIDATE_MIGRATIONS = %w[
    2026_08_21_000100_create_rebuild_foundation_tables
    2026_08_22_000800_add_patient_marital_status
    2026_08_22_001000_qualify_laravel_serial_sequence_defaults
    2026_08_25_000100_create_break_glass_record_tables
    2026_08_25_000200_create_security_ledger_tables
    2026_08_25_000300_expand_audit_actor_attribution
    2026_08_26_000100_add_operational_worklist_indexes
    2026_08_26_000200_create_daily_queue_allocator
    2026_08_27_000100_create_teaching_role_access_leases
  ].freeze
  FACT_KEYS = %w[
    postgres_17
    exact_predecessor_ledger
    all_candidate_migrations_pending
    exact_audit_columns
    exact_audit_indexes
    exact_audit_foreign_key
    exact_marital_status
    exact_sequence_predecessor
    exact_teaching_role_access_predecessor
    candidate_physical_state_absent
    synthetic_only
    all_encounters_have_registration_time
    data_api_roles_denied
  ].freeze
  ROLE_NAMES = %w[anon authenticated authenticator service_role].freeze
  RESULT_KEYS = %w[
    artifact_type
    schema_version
    project_ref
    query_path
    query_sha256
    candidate_state
    candidate_application_sha
    base_application_sha
    public_production_sha
    captured_at_utc
    status
    promotion_authorized
    supersedes_result_path
    superseded_result_sha256
    facts
    candidate_migrations
    data_api_role_privileges
  ].freeze

  def setup
    @sql = File.read(SQL_PATH)
    @record = File.read(RECORD_PATH)
    @result_bytes = File.binread(RESULT_PATH)
    @result = JSON.parse(@result_bytes)
  end

  def test_query_is_value_minimized_and_read_only
    executable = @sql.lines.reject { |line| line.lstrip.start_with?('--') }.join
    executable_without_literals = executable.gsub(/'(?:''|[^'])*'/m, "''")

    refute_match(
      /\b(?:insert|update|delete|alter|create|drop|truncate|grant|revoke|call|do)\b/i,
      executable_without_literals
    )
    refute_match(/\b(?:email|medical_record_number|phone|address|password|token)\b/i, executable)
    assert_match(/SELECT jsonb_build_object\(/, executable)
    assert_match(/'promotion_authorized', false/, executable)
  end

  def test_query_tracks_every_candidate_migration_and_required_safety_boundary
    CANDIDATE_MIGRATIONS.each do |migration|
      assert_includes @sql, migration
    end

    %w[
      PRE_MIGRATION_CONTRACT_MATCH
      NO_GO_SCHEMA_DRIFT
      synthetic_only
      data_api_roles_denied
      exact_predecessor_ledger
      exact_audit_columns
      exact_audit_indexes
      exact_audit_foreign_key
      exact_sequence_predecessor
      exact_teaching_role_access_predecessor
      candidate_physical_state_absent
      canonical_default
      has_no_predicate
      has_no_expressions
      confupdtype
      confmatchtype
      condeferrable
      teaching_access_epoch
      teaching_access_mutex
      teaching_access_roster_key
      teaching_access_lease_public_id
      teaching_access_expires_at_epoch
      teaching_role_access_leases_id_seq
      users_teaching_access_roster_key_uq
      users_teaching_access_expires_idx
      tral_user_fk
      tral_one_active_slot_uq
      tral_one_active_per_user_uq
      enforce_teaching_roster_identity_immutability
      users_teaching_roster_identity_immutable_trg
      users_teaching_access_roster_mapping_ck
      candidate_schema_objects_absent
      pg_class
      pg_proc
      pg_trigger
      pg_constraint
      reject_protected_fact_mutation
      protect_security_ledger_outbox_mutation
      security_ledger_outboxes_validate_insert
      security_ledger_outboxes_protect_update
      security_ledger_outboxes_no_delete
      break_glass_
      security_ledger_
      daily_queue_counters
      bgr_
      bgd_
      bga_
      bgrv_
      bgsb_
      bgsl_
      sle_
      slo_
    ].each do |contract|
      assert_includes @sql, contract
    end

    refute_includes @sql, 'POST_MIGRATION_SCHEMA_MATCH'
    assert_operator @sql.scan('FULL OUTER JOIN').length, :>=, 3
    assert_includes @sql, "(SELECT count(*) FROM actual_ledger) = (SELECT count(*) FROM expected_ledger)"
    assert_includes @sql, 'safe_to_qualify IS NOT TRUE'
    assert_includes @sql, 'canonical_default IS NOT TRUE'
    refute_match(/bool_and\s*\(safe_to_qualify\s+AND\s+canonical_default\)/, @sql)
    refute_includes @sql, 'qualified_default'
  end

  def test_retained_result_is_bound_to_query_and_remains_non_authorizing
    assert_equal RESULT_KEYS.sort, @result.keys.sort
    assert_equal 'production_promotion_readiness_predecessor_result', @result.fetch('artifact_type')
    assert_equal 2, @result.fetch('schema_version')
    assert_equal 'xbmsfvstcpngizcplqyg', @result.fetch('project_ref')
    assert_equal 'docs/operations/T1_PRODUCTION_PROMOTION_READINESS_PREFLIGHT_2026-08-27.sql',
                 @result.fetch('query_path')
    assert_equal Digest::SHA256.file(SQL_PATH).hexdigest, @result.fetch('query_sha256')
    assert_equal 'LOCAL_UNCOMMITTED_NOT_DEPLOYABLE', @result.fetch('candidate_state')
    assert_nil @result.fetch('candidate_application_sha')
    assert_match(/\A[0-9a-f]{40}\z/, @result.fetch('base_application_sha'))
    assert_match(/\A[0-9a-f]{40}\z/, @result.fetch('public_production_sha'))
    assert_match(/\A2026-08-27T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/, @result.fetch('captured_at_utc'))
    assert_equal 'PRE_MIGRATION_CONTRACT_MATCH', @result.fetch('status')
    assert_equal false, @result.fetch('promotion_authorized')
    assert_equal CANDIDATE_MIGRATIONS.sort, @result.fetch('candidate_migrations').sort
    assert_equal FACT_KEYS.sort, @result.fetch('facts').keys.sort
    assert_equal [true], @result.fetch('facts').values.uniq

    superseded_bytes = File.binread(SUPERSEDED_RESULT_PATH)
    superseded = JSON.parse(superseded_bytes)
    assert_equal 'docs/operations/T1_PRODUCTION_PROMOTION_READINESS_RESULT_B3F9EB48_2026-08-27.json',
                 @result.fetch('supersedes_result_path')
    assert_equal Digest::SHA256.hexdigest(superseded_bytes), @result.fetch('superseded_result_sha256')
    assert_equal 'b3f9eb48d195fa1b2be02170b2dbd33eee20ae76', superseded.fetch('candidate_application_sha')
    assert_equal 'dd353b49b50e4e1325843a8cf70c68373d2bd36f7aad4f26e005b674bb0300a5',
                 superseded.fetch('query_sha256')
    assert_equal Digest::SHA256.file(SUPERSEDED_SQL_PATH).hexdigest, superseded.fetch('query_sha256')
    assert_equal 'PRE_MIGRATION_CONTRACT_MATCH', superseded.fetch('status')
    assert_equal false, superseded.fetch('promotion_authorized')

    roles = @result.fetch('data_api_role_privileges')
    assert_equal ROLE_NAMES, roles.map { |role| role.fetch('role_name') }
    roles.each do |role|
      assert_equal %w[
        audit_select
        patient_insert
        patient_select
        role_name
        schema_create
        schema_usage
        users_select
      ], role.keys.sort
      assert_equal false, role.fetch('schema_usage')
      assert_equal false, role.fetch('schema_create')
      assert_equal false, role.fetch('patient_select')
      assert_equal false, role.fetch('patient_insert')
      assert_equal false, role.fetch('users_select')
      assert_equal false, role.fetch('audit_select')
    end

    assert_includes @record, Digest::SHA256.hexdigest(@result_bytes)
    %w[project_ref captured_at_utc query_sha256 base_application_sha public_production_sha].each do |field|
      assert_includes @record, @result.fetch(field)
    end
  end

  def test_record_keeps_promotion_and_product_scope_fail_closed
    assert_includes @record, '**Status:** `NO-GO`'
    assert_includes @record, 'authorizes no migration, Vercel promotion, role activation, or production integration'
    assert_includes @record, 'A manual insert into `laravel.migrations` is prohibited.'
    assert_includes @record, 'The query deliberately has no post-migration success state.'
    assert_includes @record, 'Any SQL error, missing single JSON result'
    assert_includes @record, 'Radiology runtime remains blocked by `PAR-CLN-007`'
    assert_includes @record, 'Klaim, BPJS, Apotek, LIS, PACS, payment'
  end
end
