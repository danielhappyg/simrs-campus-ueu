# frozen_string_literal: true

require 'minitest/autorun'
require 'digest'

class TenMigrationCutoverContractTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  OPERATIONS = File.join(ROOT, 'docs/operations')
  PACKET_PATH = File.join(OPERATIONS, 'T1_TEN_MIGRATION_CUTOVER_EXECUTION_CONTROL_2026-08-28.md')
  PREFLIGHT_PATH = File.join(OPERATIONS, 'T1_TEN_MIGRATION_PRODUCTION_READINESS_PREFLIGHT_2026-08-28.sql')
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
    '2026_08_26_000200_create_daily_queue_allocator' => '5eca2d46ea0fba89cf4bfe26afb83a096e47aa249a08e89580937b508f0ac3e0',
    '2026_08_27_000100_create_teaching_role_access_leases' => '242bc0864918fc4b132766ffb8a088ba6327abb76c4cdf53d275eec8051504d7',
    '2026_08_28_000100_create_inpatient_bed_claim_mutexes' => 'c2ba20c90dcc61e6b957306258bdad0ebc40dc82272d0d600482c68a78c4da36'
  }.freeze

  def setup
    @packet = File.read(PACKET_PATH)
    @preflight = File.read(PREFLIGHT_PATH)
    @pre = File.read(PRE_PATH)
    @post = File.read(POST_PATH)
  end

  def test_all_ten_migrations_are_exactly_bound
    assert_equal 10, MIGRATIONS.length

    MIGRATIONS.each do |name, sha256|
      path = File.join(ROOT, 'database/migrations', "#{name}.php")
      assert File.file?(path), "missing migration #{name}"
      assert_equal sha256, Digest::SHA256.file(path).hexdigest
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
