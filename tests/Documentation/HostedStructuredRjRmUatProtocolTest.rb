# frozen_string_literal: true

require 'minitest/autorun'
require 'json'

class HostedStructuredRjRmUatProtocolTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PROTOCOL_PATH = File.join(
    ROOT,
    'docs/operations/T1_HOSTED_STRUCTURED_RJ_RM_UAT_PROTOCOL_2026-08-27.md'
  )
  TEMPLATE_PATH = File.join(
    ROOT,
    'docs/operations/T1_HOSTED_STRUCTURED_RJ_RM_UAT_TEMPLATE_2026-08-27.json'
  )

  ROLES = %w[registrar nurse physician rmik].freeze
  ACCOUNTS = %w[
    registrar.demo@example.invalid
    nurse.demo@example.invalid
    physician.demo@example.invalid
    rmik.demo@example.invalid
  ].freeze
  CHECKLIST_CODES = %w[
    IDENTITY_LINKED
    NURSING_FINAL
    NURSING_PROVENANCE
    MEDICAL_FINAL
    MEDICAL_REQUIRED_FIELDS
    MEDICAL_PROVENANCE
    NO_ACTIVE_LAB_ORDERS
  ].freeze

  def setup
    @protocol = File.read(PROTOCOL_PATH)
    @template = JSON.parse(File.read(TEMPLATE_PATH))
  end

  def test_protocol_is_explicitly_blocked_and_non_authorizing
    assert_includes @protocol, '**Status:** `BLOCKED_ACCESS_LIFECYCLE_NOT_DEPLOYED`'
    assert_includes @protocol, '**Execution:** `NOT_RUN`'
    assert_includes @protocol, 'does not authorize a migration, Vercel promotion, owner acceptance'
    assert_includes @protocol, 'Do not run this UAT until'
    assert_includes @protocol, 'Direct SQL, Tinker, `DemoActorsSeeder`, `simulation:reset`'
    assert_includes @protocol, 'does not itself grant Clinical or RMIK owner acceptance'

    assert_equal true, @template.fetch('template_only')
    assert_equal 'BLOCKED_ACCESS_LIFECYCLE_NOT_DEPLOYED', @template.fetch('execution_status')
    assert_equal false, @template.fetch('promotion_authorized')
    assert_equal false, @template.dig('access_lifecycle', 'command_deployed')
    assert_equal({ 'clinical' => 'OPEN', 'rmik' => 'OPEN' }, @template.fetch('owner_acceptance'))
  end

  def test_protocol_requires_exact_sequential_roles_and_safe_closeout
    assert_equal ROLES, @template.fetch('accounts').map { |account| account.fetch('role') }
    assert_equal ACCOUNTS, @template.fetch('accounts').map { |account| account.fetch('account') }

    ACCOUNTS.each { |account| assert_includes @protocol, account }
    assert_includes @protocol, 'Only one dedicated account may be active at any time.'
    assert_includes @protocol, 'T1_TEACHING_ROLE_ACCESS_RUNBOOK_2026-08-27.md'
    assert_includes @protocol, 'new access value and bounded expiry'
    assert_includes @protocol, 'prove password login fails'
    assert_includes @protocol, 'zero retained authentication artifacts'
    assert_equal true, @template.dig('access_lifecycle', 'one_account_at_a_time')
    assert_equal true, @template.dig('access_lifecycle', 'release_bound')
    assert_equal true, @template.dig('access_lifecycle', 'environment_bound')
    assert_equal true, @template.dig('access_lifecycle', 'deployment_bound')
    assert_equal true, @template.dig('access_lifecycle', 'canonical_host_bound')
    assert_equal true, @template.dig('access_lifecycle', 'request_host_enforced')
    assert_match(/\A2026-08-27T\d{2}:\d{2}:\d{2}\+07:00\z/,
                 @template.fetch('current_reference_captured_at_asia_jakarta'))
    assert_equal 30, @template.dig('access_lifecycle', 'maximum_ttl_minutes')
    assert_equal true, @template.dig('access_lifecycle', 'unique_access_value_per_window')
  end

  def test_protocol_covers_structured_journey_and_exact_evidence_counts
    CHECKLIST_CODES.each { |code| assert_includes @protocol, code }

    assert_equal %w[REGISTERED IN_EXAMINATION READY_FOR_RM CLOSED],
                 @template.fetch('encounter_status_sequence')
    assert_equal({
                   'document_heads' => 2,
                   'document_versions' => 4,
                   'reviews' => 2,
                   'checklist_items' => 14,
                   'active_lab_orders' => 0,
                   'denial_mutation_delta' => 0
                 }, @template.fetch('expected_counts'))
    assert_equal ['NOT_RUN'], @template.fetch('journey').values.uniq
    assert @template.fetch('observed_counts').values.all?(&:nil?)
  end

  def test_template_is_synthetic_secret_free_and_does_not_expand_scope
    serialized = JSON.generate(@template)

    assert_equal true, @template.fetch('synthetic_only')
    assert_equal false, @template.fetch('live_integrations_used')
    assert_equal false, @template.fetch('credentials_recorded')
    refute_match(/DEMO_ACCOUNT_PASSWORD|password_hash|cookie|session_id|database_url|token_value/i, serialized)
    refute_includes @protocol, 'Order Rad like Lab'
    assert_includes @protocol, 'no live integration'
    assert_includes @protocol, 'Lab is intentionally excluded'
  end
end
