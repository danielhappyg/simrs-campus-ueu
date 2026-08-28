# frozen_string_literal: true

require 'digest'
require 'json'
require 'minitest/autorun'
require 'open3'
require 'time'

class G0GovernanceV2AdoptionDecisionTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PHASE = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0')
  DECISION_PATH = File.join(PHASE, 'G0_GOVERNANCE_V2_ADOPTION_DECISION.json')
  DRAFT_PATH = File.join(PHASE, 'G0_GOVERNANCE_V2_ADOPTION_DECISION_DRAFT_2026-08-28.json')
  PROPOSAL_PATH = File.join(PHASE, 'G0_PROPORTIONAL_GOVERNANCE_V2_PROPOSAL_2026-08-28.md')
  ADR_PATH = File.join(ROOT, 'docs/adr/ADR-018-PROPORTIONAL-G0-GOVERNANCE-PROFILE.md')
  INDEPENDENT_REVIEW_PATH = File.join(ROOT, 'docs/operations/G0_GOVERNANCE_V2_INDEPENDENT_TECHNICAL_SECURITY_REVIEW_2026-08-29.md')
  PLANNING_HEAD = 'e2d933c8929906bd15f52c8c5a6283d45b3fbf86'
  OWNER_APPROVAL_RECORDED_TIME = '2026-08-29T03:58:45+07:00'
  REVIEW_TIME = '2026-08-29T04:06:17+07:00'
  EFFECTIVE_TIME = '2026-08-29T04:14:52+07:00'
  DECISION_MESSAGE = "I approve everything, the system remains synthetic but ther are no need to said it like that on fron of the system, this will be used like a reala system like what sahabat built.\nfor the other thing, I approve the governance-v2 proposal and ADR-018 exactly as stated in the approval block. Proceed with local implementation under those boundaries."
  TOP_LEVEL_KEYS = %w[
    artifact_type schema_version artifact_id status effect data_boundary planning_head
    proposal architecture adoption_draft independent_review effectiveness decider approved_scope authorization
    presentation_direction immutability secret_handling
  ].freeze
  REFERENCE_KEYS = %w[path sha256 decision_effect].freeze
  DRAFT_REFERENCE_KEYS = %w[path sha256 recorded_status].freeze
  INDEPENDENT_REVIEW_KEYS = %w[
    path sha256 review_id reviewer_identity reviewer_kind reviewer_agent_path
    reviewed_proposal_sha256 reviewed_architecture_sha256 verdict verdict_scope
    reviewed_at authority_effect
  ].freeze
  EFFECTIVENESS_KEYS = %w[
    prior_status effective_status required_prerequisites owner_approval_observed_at
    independent_review_completed_at effective_at effective_at_basis transition_basis
  ].freeze
  DECIDER_KEYS = %w[
    identity authority_capacity approval_status_when_observed selected_option decision_reference
    decision_message decision_message_encoding decision_message_sha256 source_message_at
    recorded_at recorded_at_basis conditions
  ].freeze
  CONDITIONS = [
    "The phrase 'I approve everything' is constrained by the same-message synthetic boundary and the exact approval-block reference; it does not expand authority beyond approved_scope.",
    'The operational UI should present as a realistic hospital SIMRS without a persistent front-of-screen simulation banner.',
    'Synthetic-data controls, a compact global teaching status, report/export watermarks, honest stubs, and no-live-integration safeguards remain mandatory.',
    'Like Sahabat means comparable hospital-workflow familiarity only; UEU identity is retained and no vendor branding, code, assets, or data may be copied.'
  ].freeze
  SCOPE = {
    'governance_v2_local_implementation' => 'authorized',
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
  PRESENTATION_DIRECTION = {
    'hospital_realism_required' => true,
    'persistent_simulation_banner_required' => false,
    'compact_global_teaching_status_required' => true,
    'compact_global_teaching_status_placement' => 'authenticated_shell_and_patient_context',
    'synthetic_data_guardrails_required' => true,
    'teaching_context_disclosure_required' => true,
    'full_teaching_context_disclosure_placement' => 'login_help_about_and_operations_surfaces',
    'report_export_simulation_watermark_required' => true,
    'honest_stub_labels_required' => true,
    'vendor_visual_clone_prohibited' => true,
    'effect_on_data_boundary' => 'none',
    'ui_preference_implementation_authority' => 'requires_separate_capability_slice_decision'
  }.freeze
  IMMUTABILITY = {
    'record_mutable' => false,
    'correction_method' => 'append_new_superseding_decision_bound_to_this_artifact_sha256',
    'draft_mutated' => false
  }.freeze
  SECRET_HANDLING = {
    'credentials_permitted' => false,
    'tokens_permitted' => false,
    'private_keys_permitted' => false,
    'connection_strings_permitted' => false
  }.freeze

  class DuplicateKeyRejectingHash < Hash
    def []=(key, value)
      raise JSON::ParserError, "duplicate JSON key: #{key}" if key?(key)

      super
    end
  end

  def setup
    @raw = File.read(DECISION_PATH)
    @decision = parse_json(@raw)
  end

  def test_decision_is_closed_attributable_and_authorizes_only_local_implementation
    assert_equal TOP_LEVEL_KEYS, @decision.keys
    assert_equal 'g0_governance_v2_adoption_decision', @decision.fetch('artifact_type')
    assert_equal 1, @decision.fetch('schema_version')
    assert_equal 'G0-GOVERNANCE-V2-ADOPTION-DECISION-2026-08-29', @decision.fetch('artifact_id')
    assert_equal 'approved_as_written', @decision.fetch('status')
    assert_equal 'authorizes_local_governance_v2_implementation_only', @decision.fetch('effect')
    assert_equal 'synthetic_only', @decision.fetch('data_boundary')
    assert_equal PLANNING_HEAD, @decision.fetch('planning_head')

    decider = @decision.fetch('decider')
    assert_equal DECIDER_KEYS, decider.keys
    assert_equal 'Daniel Happy Putra', decider.fetch('identity')
    assert_equal 'product_owner', decider.fetch('authority_capacity')
    assert_equal 'approved_pending_required_review', decider.fetch('approval_status_when_observed')
    assert_equal 'approve_as_written', decider.fetch('selected_option')
    assert_equal 'codex_thread:01a02b58-641d-7090-aad4-00871c7ddf47#decision-message-sha256:a1305f42adefd687116d677cc6600ae138a8dc267194b7b2b87762984bc0501e', decider.fetch('decision_reference')
    assert_equal DECISION_MESSAGE, decider.fetch('decision_message')
    assert_equal 'exact_utf8_with_one_lf_and_no_trailing_newline', decider.fetch('decision_message_encoding')
    assert_equal 347, decider.fetch('decision_message').bytesize
    assert_equal 1, decider.fetch('decision_message').count("\n")
    refute decider.fetch('decision_message').end_with?("\n")
    assert_equal Digest::SHA256.hexdigest(decider.fetch('decision_message')), decider.fetch('decision_message_sha256')
    assert_equal 'a1305f42adefd687116d677cc6600ae138a8dc267194b7b2b87762984bc0501e', decider.fetch('decision_message_sha256')
    assert_nil decider.fetch('source_message_at')
    assert_equal OWNER_APPROVAL_RECORDED_TIME, decider.fetch('recorded_at')
    assert Time.iso8601(decider.fetch('recorded_at'))
    assert_equal 'first_local_record_after_attributable_product_owner_thread_message_source_timestamp_unavailable', decider.fetch('recorded_at_basis')
    assert_equal CONDITIONS, decider.fetch('conditions')
  end

  def test_required_independent_review_is_exactly_bound_and_has_no_authority_effect
    review = @decision.fetch('independent_review')
    assert_equal INDEPENDENT_REVIEW_KEYS, review.keys
    assert_equal 'docs/operations/G0_GOVERNANCE_V2_INDEPENDENT_TECHNICAL_SECURITY_REVIEW_2026-08-29.md', review.fetch('path')
    assert_equal Digest::SHA256.file(INDEPENDENT_REVIEW_PATH).hexdigest, review.fetch('sha256')
    assert_equal 'G0-GOV-V2-INDEPENDENT-REVIEW-2026-08-29-CODEX-HUBBLE-2', review.fetch('review_id')
    assert_equal 'Independent Codex technical/security reviewer (Hubble the 2nd)', review.fetch('reviewer_identity')
    assert_equal 'ai_agent_not_human_or_owner_authority', review.fetch('reviewer_kind')
    assert_equal '/root/ci_portability_review', review.fetch('reviewer_agent_path')
    assert_equal @decision.dig('proposal', 'sha256'), review.fetch('reviewed_proposal_sha256')
    assert_equal @decision.dig('architecture', 'sha256'), review.fetch('reviewed_architecture_sha256')
    assert_equal 'PASS', review.fetch('verdict')
    assert_equal 'gate_a_local_governance_v2_implementation_readiness_only', review.fetch('verdict_scope')
    assert_equal REVIEW_TIME, review.fetch('reviewed_at')
    assert Time.iso8601(review.fetch('reviewed_at'))
    assert_equal 'none_review_evidence_only', review.fetch('authority_effect')

    review_text = File.read(INDEPENDENT_REVIEW_PATH)
    assert_includes review_text, "**Review ID:** `#{review.fetch('review_id')}`"
    assert_includes review_text, "**Review time:** `#{REVIEW_TIME}`"
    assert_includes review_text, "**Verdict:** `#{review.fetch('verdict')}`"
    assert_includes review_text, '**Authority effect:** None.'
  end

  def test_acceptance_became_effective_only_after_both_prerequisites_were_satisfied
    effectiveness = @decision.fetch('effectiveness')
    assert_equal EFFECTIVENESS_KEYS, effectiveness.keys
    assert_equal 'approved_pending_required_review', effectiveness.fetch('prior_status')
    assert_equal 'approved_as_written', effectiveness.fetch('effective_status')
    assert_equal %w[
      attributable_product_owner_approve_as_written
      independent_technical_security_review_pass
    ], effectiveness.fetch('required_prerequisites')
    assert_equal OWNER_APPROVAL_RECORDED_TIME, effectiveness.fetch('owner_approval_observed_at')
    assert_equal REVIEW_TIME, effectiveness.fetch('independent_review_completed_at')
    assert_equal EFFECTIVE_TIME, effectiveness.fetch('effective_at')
    assert_operator Time.iso8601(effectiveness.fetch('independent_review_completed_at')), :>, Time.iso8601(effectiveness.fetch('owner_approval_observed_at'))
    assert_operator Time.iso8601(effectiveness.fetch('effective_at')), :>, Time.iso8601(effectiveness.fetch('independent_review_completed_at'))
    assert_equal 'first_local_record_after_both_prerequisites_were_satisfied', effectiveness.fetch('effective_at_basis')
    assert_equal 'owner_approve_as_written_became_effective_after_required_review_passed_without_changes_or_blockers', effectiveness.fetch('transition_basis')
  end

  def test_exact_proposal_architecture_and_historical_draft_bytes_are_bound
    assert_equal REFERENCE_KEYS, @decision.fetch('proposal').keys
    assert_equal REFERENCE_KEYS, @decision.fetch('architecture').keys
    assert_equal DRAFT_REFERENCE_KEYS, @decision.fetch('adoption_draft').keys
    assert_equal Digest::SHA256.file(PROPOSAL_PATH).hexdigest, @decision.dig('proposal', 'sha256')
    assert_equal Digest::SHA256.file(ADR_PATH).hexdigest, @decision.dig('architecture', 'sha256')
    assert_equal Digest::SHA256.file(DRAFT_PATH).hexdigest, @decision.dig('adoption_draft', 'sha256')
    assert_equal 'adopted_as_written', @decision.dig('proposal', 'decision_effect')
    assert_equal 'accepted_for_local_governance_v2_implementation', @decision.dig('architecture', 'decision_effect')
    assert_equal 'historical_pending_no_effect', @decision.dig('adoption_draft', 'recorded_status')

    draft = parse_json(File.read(DRAFT_PATH))
    assert_equal 'pending_product_owner_decision', draft.fetch('status')
    assert_equal 'none', draft.fetch('effect')
    assert draft.fetch('authorization').values.all? { |value| value == false }
  end

  def test_scope_and_authorization_are_exact_and_fail_closed_beyond_gate_a
    assert_equal SCOPE, @decision.fetch('approved_scope')
    authorization = @decision.fetch('authorization')
    assert_equal SCOPE.keys, authorization.keys
    assert_equal true, authorization.fetch('governance_v2_local_implementation')
    assert authorization.reject { |key, _value| key == 'governance_v2_local_implementation' }.values.all? { |value| value == false }
  end

  def test_realistic_presentation_does_not_weaken_data_or_integration_controls
    assert_equal PRESENTATION_DIRECTION, @decision.fetch('presentation_direction')
    assert_equal 'synthetic_only', @decision.fetch('data_boundary')
    assert_equal 'prohibited', @decision.dig('approved_scope', 'real_patient_data')
    assert_equal 'prohibited', @decision.dig('approved_scope', 'live_integration')
    assert_equal false, @decision.dig('authorization', 'real_patient_data')
    assert_equal false, @decision.dig('authorization', 'live_integration')
    assert_includes @decision.dig('decider', 'conditions').join(' '), "does not expand authority beyond approved_scope"
  end

  def test_record_is_immutable_secret_free_and_duplicate_keys_are_rejected
    assert_equal IMMUTABILITY, @decision.fetch('immutability')
    assert_equal SECRET_HANDLING, @decision.fetch('secret_handling')
    assert_raises(JSON::ParserError) { parse_json('{"status":"pending","status":"approved"}') }
    secret_pattern = /(?:-----BEGIN [A-Z ]*PRIVATE KEY-----|postgres(?:ql)?:\/\/[^\s:]+:[^\s@]+@|(?:password|passwd|secret|api[_-]?key|access[_-]?token|refresh[_-]?token)["']?\s*(?::|=)\s*["']?[^\s,;}"']+)/i
    refute_match secret_pattern, @raw
  end

  def test_planning_head_is_reachable_and_owns_every_adopted_input
    assert_git_success 'cat-file', '-e', "#{PLANNING_HEAD}^{commit}"
    assert_git_success 'merge-base', '--is-ancestor', PLANNING_HEAD, 'HEAD'
    {
      @decision.dig('proposal', 'path') => PROPOSAL_PATH,
      @decision.dig('architecture', 'path') => ADR_PATH,
      @decision.dig('adoption_draft', 'path') => DRAFT_PATH
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
