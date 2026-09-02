# frozen_string_literal: true

require 'minitest/autorun'
require 'digest'
require 'json'

class TenMigrationCutoverContractTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  OPERATIONS = File.join(ROOT, 'docs/operations')
  PACKET_PATH = File.join(OPERATIONS, 'T1_TEN_MIGRATION_CUTOVER_EXECUTION_CONTROL_2026-08-28.md')
  PREFLIGHT_PATH = File.join(OPERATIONS, 'T1_TEN_MIGRATION_PRODUCTION_READINESS_PREFLIGHT_2026-08-28.sql')
  RESULT_PATH = File.join(OPERATIONS, 'T1_TEN_MIGRATION_PRODUCTION_READINESS_RESULT_2026-08-28.json')
  PRE_PATH = File.join(OPERATIONS, 'T1_TEN_MIGRATION_PREMIGRATION_PRESERVATION_2026-08-28.sql')
  POST_PATH = File.join(OPERATIONS, 'T1_TEN_MIGRATION_POSTMIGRATION_ACCEPTANCE_2026-08-28.sql')
  HISTORICAL_PACKET_PATH = File.join(OPERATIONS, 'T1_NINE_MIGRATION_CUTOVER_EXECUTION_CONTROL_2026-08-27.md')

  MIGRATIONS = {
    '2026_08_21_000100_create_rebuild_foundation_tables' => '2e8edda3e2af1f50719f6f6fe614e5f3dbccac576fd743b65cfa669f072b8cbe',
    '2026_08_22_000800_add_patient_marital_status' => '19c60bba317b9b136679302396c5943eaf0a91f16f071970e6d4f3a804c7183f',
    '2026_08_22_001000_qualify_laravel_serial_sequence_defaults' => '10f0cecb82fd9e62361071b19fc9ae54d9b033c9c7a9d7a4bb94188339b6b68e',
    '2026_08_25_000100_create_break_glass_record_tables' => '77d7c8478e956b254476c408911f3a01924984ff920407a2ad3e2ed76621c13d',
    '2026_08_25_000200_create_security_ledger_tables' => '55af5a04944290119fdb3511364148956cf667444d87a03e57e003975be9500b',
    '2026_08_25_000300_expand_audit_actor_attribution' => '060c64122e41f1bf974f092ff8a2bfc1baf34675f031b0ced2f2f162b10775f4',
    '2026_08_26_000100_add_operational_worklist_indexes' => 'e9077592ac53dd9ef704ac376e7bc7c45ecddc43040273597d7a5dcadb23f954',
    '2026_08_26_000200_create_daily_queue_allocator' => '5abf7d50ada14b6f5a8beb8e180dafe6959d999696dbd878469afc1e9578cded',
    '2026_08_27_000100_create_teaching_role_access_leases' => '242bc0864918fc4b132766ffb8a088ba6327abb76c4cdf53d275eec8051504d7',
    '2026_08_28_000100_create_inpatient_bed_claim_mutexes' => 'c2ba20c90dcc61e6b957306258bdad0ebc40dc82272d0d600482c68a78c4da36'
  }.freeze

  EXPECTED_FACTS = %w[
    postgres_17
    exact_predecessor_ledger
    all_candidate_migrations_pending
    exact_audit_columns
    exact_audit_indexes
    exact_audit_foreign_key
    exact_marital_status
    exact_sequence_predecessor
    exact_teaching_role_access_predecessor
    exact_inpatient_bed_claim_predecessor
    candidate_physical_state_absent
    synthetic_only
    all_encounters_have_registration_time
    data_api_roles_denied
  ].to_h { |key| [key, true] }.freeze

  EXPECTED_ROLE_PRIVILEGES = %w[anon authenticated authenticator service_role].map do |role_name|
    {
      'role_name' => role_name,
      'schema_usage' => false,
      'schema_create' => false,
      'patient_select' => false,
      'patient_insert' => false,
      'users_select' => false,
      'audit_select' => false
    }
  end.freeze

  EXPECTED_SECRET_HANDLING = {
    'database_password_used' => false,
    'database_url_read_or_recorded' => false,
    'environment_values_exported' => false,
    'row_level_patient_data_returned' => false
  }.freeze

  def setup
    @packet = File.read(PACKET_PATH)
    @preflight = File.read(PREFLIGHT_PATH)
    @result = JSON.parse(File.read(RESULT_PATH))
    @pre = File.read(PRE_PATH)
    @post = File.read(POST_PATH)
  end

  def test_hosted_predecessor_result_is_exactly_bound_and_cannot_authorize_promotion
    assert_includes @packet, File.basename(RESULT_PATH)
    assert_includes @packet, Digest::SHA256.file(RESULT_PATH).hexdigest

    assert_equal 'T1-TEN-MIGRATION-PRODUCTION-READINESS-RESULT-2026-08-28', @result.fetch('artifact_id')
    assert_equal 'PRE_MIGRATION_CONTRACT_MATCH', @result.fetch('status')
    assert_equal false, @result.fetch('promotion_authorized')
    assert_equal '2026-08-28T12:43:40.019340Z', @result.fetch('captured_at_utc')
    assert_equal 'xbmsfvstcpngizcplqyg', @result.fetch('project_id')
    assert_equal 'simrs-campus-ueu-demo', @result.fetch('project_name')
    assert_equal 'ap-southeast-1', @result.fetch('project_region')
    assert_equal 'PostgreSQL 17', @result.fetch('database_engine')
    assert_equal 'laravel', @result.fetch('schema')
    assert_equal 'Supabase authenticated project connector execute_sql', @result.fetch('execution_interface')
    assert_equal 'docs/operations/T1_TEN_MIGRATION_PRODUCTION_READINESS_PREFLIGHT_2026-08-28.sql',
                 @result.dig('query', 'path')
    assert_equal '17e2cf2a3715d8d5a3741f59023d113079dc2aaf3e700c2a092df2366cf104a5',
                 @result.dig('query', 'sha256')
    assert_equal Digest::SHA256.file(PREFLIGHT_PATH).hexdigest, @result.dig('query', 'sha256')
    assert_equal 'd346c885116f35ed11dfcff7e2deca7734099cf9', @result.dig('query', 'git_blob')
    assert_equal true, @result.dig('query', 'bytes_match_origin_main_release_carrier')
    assert_equal true, @result.dig('query', 'read_only')
    assert_equal '5f6a8b29a866e9e09c346808c3a00bcf76c3d6ef', @result.fetch('release_carrier_sha')
    pushed_base_row = @packet.lines.find { |line| line.start_with?('| Current pushed application base |') }
    preview_row = @packet.lines.find { |line| line.start_with?('| Current pushed-base Preview observation |') }
    refute_nil pushed_base_row
    refute_nil preview_row
    assert_includes pushed_base_row, @result.fetch('release_carrier_sha')
    assert_includes preview_row, @result.fetch('release_carrier_sha')
    assert_equal MIGRATIONS.keys, @result.fetch('candidate_migrations')
    assert_equal EXPECTED_FACTS, @result.fetch('facts')
    assert_equal EXPECTED_ROLE_PRIVILEGES, @result.fetch('data_api_role_privileges')
    assert_equal EXPECTED_SECRET_HANDLING, @result.fetch('secret_handling')
    assert_includes @result.fetch('interpretation'), 'does not authorize'
  end

  def test_historical_ten_migration_evidence_remains_exactly_bound
    assert_equal 10, MIGRATIONS.length

    MIGRATIONS.each do |name, sha256|
      path = File.join(ROOT, 'database/migrations', "#{name}.php")
      assert File.file?(path), "missing migration #{name}"
      assert_includes @packet, name
      assert_includes @packet, sha256
      assert_includes @preflight, name
      assert_includes @post, name
    end
  end

  def test_packet_binds_the_exact_sql_bytes_and_stays_fail_closed
    {
      PREFLIGHT_PATH => 'predecessor preflight',
      PRE_PATH => 'Pre-migration preservation SQL',
      POST_PATH => 'Post-migration acceptance SQL'
    }.each do |path, label|
      assert_includes @packet, File.basename(path)
      assert_includes @packet, Digest::SHA256.file(path).hexdigest, label
    end

    assert_includes @packet, '**Current decision:** `NO-GO / NOT EXECUTED`'
    assert_includes @packet, '| Final repository/release carrier | `PENDING` |'
    assert_includes @packet, '| Exact Git-backed Preview deployment and URL | `PENDING` |'
    refute_includes @packet, 'PENDING FINAL BYTE FREEZE'
  end

  def test_historical_packet_cannot_be_mistaken_for_current_checkpoint_manifest
    assert_includes @packet, 'superseded for the current single-checkpoint release and must not be executed'
    assert_includes @packet, '31 new migration files relative to the pushed application base'
    assert_includes @packet, 'neither “ten migrations” nor that observed “31” is an action-time manifest'
    assert_includes @packet, '`WAREHOUSE_SCHEMA_MIGRATION_ENABLED=false`'
    assert_includes @packet, 'warehouse exact PostgreSQL/MySQL rehearsal is `READY / NOT RUN`'
    assert_includes @packet, 'generic unfiltered `php artisan migrate --force` is therefore prohibited'
  end

  def test_predecessor_queries_are_read_only_and_require_new_objects_absent
    [@preflight, @pre].each do |sql|
      executable = sql.lines.reject { |line| line.lstrip.start_with?('--') }.join
      executable_without_literals = executable.gsub(/'(?:''|[^'])*'/m, "''")
      refute_match(/\b(?:insert|update|delete|alter|create|drop|truncate|grant|revoke|call)\b/i,
                   executable_without_literals)
    end

    %w[
      inpatient_bed_claim_mutexes
      inpatient_bed_claim_mutexes_pkey
      encounters_care_bed_status_idx
      exact_inpatient_bed_claim_predecessor
    ].each { |contract| assert_includes @preflight, contract }
    assert_includes @pre, "to_regclass('laravel.inpatient_bed_claim_mutexes') IS NOT NULL"
    assert_includes @pre, "to_regclass('laravel.inpatient_bed_claim_mutexes_pkey') IS NOT NULL"
    assert_includes @pre, "to_regclass('laravel.encounters_care_bed_status_idx') IS NOT NULL"
    assert_includes @pre, 'SELECT count(*) = 31'
  end

  def test_post_acceptance_has_the_exact_ten_migration_catalog_contract
    {
      'IF observed_count <> 42' => '42 base tables',
      'SELECT count(*) = 41' => '41 ledger rows',
      "'candidate_rows', 10" => 'ten candidate rows',
      "'candidate_new_tables', 11" => 'eleven candidate tables',
      "'required_indexes', 62" => 'required indexes',
      'SELECT count(*) = 55 INTO contract_ok\n    FROM pg_index' => 'candidate-table indexes',
      'SELECT count(*) = 56 INTO contract_ok\n    FROM pg_constraint' => 'candidate-table constraints',
      'SELECT count(*) = 39' => 'candidate primary/unique constraints',
      "'qualified_sequences', 22" => 'qualified sequences',
      "'restrictive_foreign_keys', 18" => 'restrictive foreign keys',
      "'candidate_triggers', 16" => 'candidate triggers',
      "<> 24" => 'preserved predecessor tables'
    }.each do |literal, label|
      assert_includes @post, literal.gsub('\\n', "\n"), label
    end

    assert_includes @post, "ARRAY['bed_code','created_at','updated_at']::text[]"
    assert_includes @post, "ARRAY['character varying(64)','timestamp(0) without time zone','timestamp(0) without time zone']::text[]"
    assert_includes @post, "('inpatient_bed_claim_mutexes_pkey','inpatient_bed_claim_mutexes',ARRAY['bed_code']::text[],true,true,NULL)"
    assert_includes @post, "('encounters_care_bed_status_idx','encounters',ARRAY['care_setting','bed_code','status']::text[],false,false,NULL)"
    assert_includes @post, "constraint_row.contype NOT IN ('p','u','f')"
    assert_includes @post, 'unexpected candidate CHECK/exclusion constraint is present'
    assert_includes @post, 'IF EXISTS (SELECT 1 FROM laravel.inpatient_bed_claim_mutexes)'
    assert_includes @post, ') AS ten_migration_postmigration_acceptance'
  end

  def test_historical_chain_remains_present_and_explicitly_superseded
    historical = File.read(HISTORICAL_PACKET_PATH)

    assert_includes historical, 'SUPERSEDED FOR THE LOCAL `28ab1d8` CANDIDATE — DO NOT EXECUTE'
    assert_includes @packet, 'Historical nine-migration packet — superseded, do not execute'
    refute_includes @packet, 'T1_NINE_MIGRATION_PREMIGRATION_PRESERVATION_2026-08-27.sql` |'
    refute_includes @packet, 'T1_NINE_MIGRATION_POSTMIGRATION_ACCEPTANCE_2026-08-27.sql` |'
  end
end
