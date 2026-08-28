# frozen_string_literal: true

require 'digest'
require 'json'
require 'minitest/autorun'
require 'open3'
require 'pathname'

class G0GovernanceV2AdoptionDecisionDraftTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PHASE = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0')
  DRAFT_PATH = File.join(PHASE, 'G0_GOVERNANCE_V2_ADOPTION_DECISION_DRAFT_2026-08-28.json')
  PROPOSAL_PATH = File.join(PHASE, 'G0_PROPORTIONAL_GOVERNANCE_V2_PROPOSAL_2026-08-28.md')
  ADR_PATH = File.join(ROOT, 'docs/adr/ADR-018-PROPORTIONAL-G0-GOVERNANCE-PROFILE.md')

  TOP_LEVEL_KEYS = %w[
    artifact_type schema_version artifact_id status effect data_boundary planning_head
    proposal architecture required_decider requested_reply canonical_approval_scope
    authorization approval_record_rules secret_handling
  ].freeze

  REFERENCE_KEYS = %w[path sha256 recorded_status].freeze

  REQUIRED_DECIDER_KEYS = %w[
    identity authority_capacity decision_status selected_option allowed_options
    decision_reference decision_message_sha256 decided_at conditions
  ].freeze

  CANONICAL_APPROVAL_SCOPE = {
    'governance_v2_local_implementation' => 'requested',
    'consumer_activation' => 'separate_decision_required',
    'capability_disposition' => 'separate_owner_decisions_required',
    'slice_implementation' => 'separate_owner_decision_required',
    'deployment' => 'separate_decision_required',
    'hosted_migration' => 'separate_decision_required',
    'real_patient_data' => 'prohibited',
    'live_integration' => 'prohibited',
    'domain_acceptance' => 'separate_decision_required',
    'g3_acceptance' => 'separate_decision_required'
  }.freeze

  AUTHORIZATION_KEYS = %w[
    governance_v2_local_implementation consumer_activation capability_disposition
    slice_implementation deployment hosted_migration real_patient_data live_integration
    domain_acceptance g3_acceptance
  ].freeze

  APPROVAL_RECORD_RULES = [
    'Do not mutate this draft into an approval record.',
    'After an explicit product-owner decision, create G0_GOVERNANCE_V2_ADOPTION_DECISION.json as a new immutable artifact.',
    'Bind the canonical artifact to the exact proposal SHA, ADR SHA, selected option, decision message SHA, attributable reference, conditions, and decision time.',
    'Approval as written may authorize local governance-v2 implementation only; it cannot authorize activation, capability dispositions, deployment, real data, live integrations, domain acceptance, or G3.',
    'Any revision changes the proposal or ADR SHA and requires renewed review before implementation.'
  ].freeze

  SECRET_HANDLING = {
    'credentials_permitted' => false,
    'tokens_permitted' => false,
    'private_keys_permitted' => false,
    'connection_strings_permitted' => false
  }.freeze

  PLANNING_HEAD = '24bbd9e605d84ad756afcae9555b0f9f97198dbc'
  REQUESTED_REPLY = 'Approve proportional G0 governance v2 as written in `G0_PROPORTIONAL_GOVERNANCE_V2_PROPOSAL_2026-08-28.md`. Preserve all 268 capability identities and traceability; require attributable product and affected-domain approval; require independent review for material clinical, security, privacy, financial, integration, and recovery risk; preserve the synthetic-only and no-live-integration boundaries; retain v1 as historical never-activated evidence; and do not treat this approval as a capability disposition, workflow implementation approval, deployment approval, or G3 acceptance. I also accept ADR-018 at SHA-256 cd7834e7f5b0be99acee3f1b46f8af9fc81b77418541fddf7276fadc26edf962 as the proposed implementation architecture for local governance-v2 implementation only.'

  class DuplicateKeyRejectingHash < Hash
    def []=(key, value)
      raise JSON::ParserError, "duplicate JSON key: #{key}" if key?(key)

      super
    end
  end

  def setup
    @raw = File.read(DRAFT_PATH)
    @draft = parse_json(@raw)
  end

  def test_draft_is_closed_pending_and_has_no_effect
    assert_equal TOP_LEVEL_KEYS, @draft.keys
    assert_equal 'g0_governance_v2_adoption_decision_draft', @draft.fetch('artifact_type')
    assert_equal 1, @draft.fetch('schema_version')
    assert_equal 'G0-GOVERNANCE-V2-ADOPTION-DECISION-DRAFT-2026-08-28', @draft.fetch('artifact_id')
    assert_equal 'pending_product_owner_decision', @draft.fetch('status')
    assert_equal 'none', @draft.fetch('effect')
    assert_equal 'synthetic_only', @draft.fetch('data_boundary')
    assert_equal PLANNING_HEAD, @draft.fetch('planning_head')
  end

  def test_proposal_and_architecture_are_exactly_hash_bound_and_still_unapproved
    assert_equal REFERENCE_KEYS, @draft.fetch('proposal').keys
    assert_equal REFERENCE_KEYS, @draft.fetch('architecture').keys
    assert_equal Pathname.new(PROPOSAL_PATH).relative_path_from(Pathname.new(ROOT)).to_s, @draft.dig('proposal', 'path')
    assert_equal Pathname.new(ADR_PATH).relative_path_from(Pathname.new(ROOT)).to_s, @draft.dig('architecture', 'path')
    assert_equal Digest::SHA256.file(PROPOSAL_PATH).hexdigest, @draft.dig('proposal', 'sha256')
    assert_equal Digest::SHA256.file(ADR_PATH).hexdigest, @draft.dig('architecture', 'sha256')
    assert_equal 'proposal_not_approved_not_authoritative', @draft.dig('proposal', 'recorded_status')
    assert_equal 'proposed_not_approved_no_implementation_authority', @draft.dig('architecture', 'recorded_status')

    assert_includes File.read(PROPOSAL_PATH), '**Status:** `PROPOSAL / NOT APPROVED / NOT AUTHORITATIVE`'
    assert_includes File.read(ADR_PATH), 'Status: **Proposed / not approved / no implementation authority**'
  end

  def test_product_owner_decision_is_unselected_and_unattributed
    decider = @draft.fetch('required_decider')
    assert_equal REQUIRED_DECIDER_KEYS, decider.keys
    assert_equal 'Daniel Happy Putra', decider.fetch('identity')
    assert_equal 'product_owner', decider.fetch('authority_capacity')
    assert_equal 'pending', decider.fetch('decision_status')
    assert_nil decider.fetch('selected_option')
    assert_nil decider.fetch('decision_reference')
    assert_nil decider.fetch('decision_message_sha256')
    assert_nil decider.fetch('decided_at')
    assert_instance_of Array, decider.fetch('conditions')
    assert_equal [], decider.fetch('conditions')
    assert_equal %w[approve_as_written approve_with_revisions retain_v1 reject_or_defer], decider.fetch('allowed_options')
  end

  def test_every_authorization_remains_false
    authorizations = @draft.fetch('authorization')
    assert_equal AUTHORIZATION_KEYS, authorizations.keys
    assert authorizations.values.all? { |value| value == false }
    assert_equal CANONICAL_APPROVAL_SCOPE, @draft.fetch('canonical_approval_scope')
    assert_equal CANONICAL_APPROVAL_SCOPE.keys, authorizations.keys
  end

  def test_reply_and_canonical_record_rules_cannot_be_misread_as_current_approval
    assert_equal REQUESTED_REPLY, @draft.fetch('requested_reply')
    assert_equal APPROVAL_RECORD_RULES, @draft.fetch('approval_record_rules')
  end

  def test_secret_classes_are_forbidden_and_no_secret_pattern_is_present
    assert_equal SECRET_HANDLING, @draft.fetch('secret_handling')
    secret_pattern = /(?:-----BEGIN [A-Z ]*PRIVATE KEY-----|postgres(?:ql)?:\/\/[^\s:]+:[^\s@]+@|(?:password|passwd|secret|api[_-]?key|access[_-]?token|refresh[_-]?token)["']?\s*(?::|=)\s*["']?[^\s,;}"']+)/i
    refute_match secret_pattern, @raw
  end

  def test_duplicate_json_keys_are_rejected
    assert_raises(JSON::ParserError) { parse_json('{"status":"pending","status":"approved"}') }
  end

  def test_planning_head_is_reachable_and_owns_the_bound_source_bytes
    assert_git_success 'cat-file', '-e', "#{PLANNING_HEAD}^{commit}"
    assert_git_success 'merge-base', '--is-ancestor', PLANNING_HEAD, 'HEAD'

    {
      @draft.dig('proposal', 'path') => PROPOSAL_PATH,
      @draft.dig('architecture', 'path') => ADR_PATH
    }.each do |repository_path, current_path|
      source_bytes, status = Open3.capture2('git', 'show', "#{PLANNING_HEAD}:#{repository_path}", chdir: ROOT)
      assert status.success?, "planning head is missing #{repository_path}"
      assert_equal File.binread(current_path), source_bytes.b
    end
  end

  private

  def parse_json(raw)
    JSON.parse(raw, object_class: DuplicateKeyRejectingHash)
  end

  def assert_git_success(*arguments)
    _output, status = Open3.capture2e('git', *arguments, chdir: ROOT)
    assert status.success?, "git #{arguments.join(' ')} failed"
  end
end
