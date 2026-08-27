# frozen_string_literal: true

require 'minitest/autorun'

class TeachingRoleAccessRunbookTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PATH = File.join(ROOT, 'docs/operations/T1_TEACHING_ROLE_ACCESS_RUNBOOK_2026-08-27.md')

  ACCOUNTS = %w[
    registrar.demo@example.invalid
    nurse.demo@example.invalid
    physician.demo@example.invalid
    rmik.demo@example.invalid
  ].freeze

  def setup
    @runbook = File.read(PATH)
  end

  def test_runbook_is_local_synthetic_and_non_authorizing
    assert_includes @runbook, '**Status:** `LOCAL_IMPLEMENTATION_IN_REVIEW_NOT_DEPLOYED`'
    assert_includes @runbook, 'synthetic teaching simulation only'
    assert_includes @runbook, 'does not authorize a database migration, Vercel promotion'
    assert_includes @runbook, 'one account active at a time'
  end

  def test_runbook_names_exact_roster_and_command_contract
    ACCOUNTS.each { |account| assert_includes @runbook, account }
    %w[status activate revoke].each do |action|
      assert_includes @runbook, "teaching:role-access #{action}"
      assert_includes @runbook, "SIMRS CAMPUS UEU #{action.upcase}"
    end

    assert_includes @runbook, '--expected-environment='
    assert_includes @runbook, '--expected-release-sha='
    assert_includes @runbook, '--expected-deployment-url='
    assert_includes @runbook, '--expected-canonical-host='
    assert_includes @runbook, '--ttl-minutes='
  end

  def test_runbook_requires_expiry_unique_access_and_database_sessions
    assert_includes @runbook, 'SESSION_DRIVER=database'
    assert_includes @runbook, 'new high-entropy `TEACHING_ROLE_ACCESS_PASSWORD`'
    assert_includes @runbook, 'cannot be reused'
    assert_includes @runbook, 'from 5 minutes through the configured maximum'
    assert_includes @runbook, 'do not wait for lease expiry'
  end

  def test_runbook_keeps_secrets_and_unsafe_substitutes_out_of_evidence
    assert_includes @runbook, 'Never place the access value or commitment key in a command argument.'
    assert_includes @runbook, 'Do not record either secret'
    assert_includes @runbook, 'direct SQL, Tinker, a seeder, reset command'
    refute_match(/TEACHING_ROLE_ACCESS_PASSWORD\s*=\s*[^\s`]+/, @runbook)
    refute_match(/TEACHING_ROLE_ACCESS_COMMITMENT_KEY\s*=\s*[^\s`]+/, @runbook)
  end

  def test_runbook_documents_web_fencing_and_post_use_rollback_boundary
    assert_includes @runbook, 'forces remember-me off'
    assert_includes @runbook, 'password reset issuance/consumption, passkey login/management'
    assert_includes @runbook, 'failed post-transaction readback invokes a bounded compensation transaction'
    assert_includes @runbook, 'the migration `down()` path refuses to remove access-lifecycle evidence'
    assert_includes @runbook, 'even if a privileged error has already removed the lease row'
    assert_includes @runbook, 'reload the account before and after controller execution'
    assert_includes @runbook, '`revoke` is a containment operation'
  end
end
