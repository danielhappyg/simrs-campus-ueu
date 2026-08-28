# frozen_string_literal: true

require 'json'
require 'minitest/autorun'

class G0ProportionalGovernanceV2ProposalTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PHASE = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0')
  PROPOSAL_PATH = File.join(PHASE, 'G0_PROPORTIONAL_GOVERNANCE_V2_PROPOSAL_2026-08-28.md')
  LEDGER_PATH = File.join(ROOT, 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_2026-08-27.json')

  def setup
    @proposal = File.read(PROPOSAL_PATH)
  end

  def test_proposal_is_explicitly_non_authoritative_and_contains_no_selected_decision
    assert_includes @proposal, '**Status:** `PROPOSAL / NOT APPROVED / NOT AUTHORITATIVE`'
    assert_includes @proposal, 'does not appoint an owner, approve a disposition, authorize implementation'
    assert_includes @proposal, 'This does not approve any capability disposition.'
    refute_match(/^\s*(?:[-*+]\s+)?\[[xX]\]/, @proposal)
  end

  def test_verified_current_state_matches_the_live_pending_registers
    entries = ('A'..'G').flat_map do |batch|
      path = File.join(PHASE, "G0_BATCH_#{batch}_DECISION_REGISTER_2026-08-25.json")
      JSON.parse(File.read(path)).fetch('entries')
    end
    appointments = JSON.parse(File.read(File.join(PHASE, 'G0_OWNER_APPOINTMENT_REGISTER_2026-08-25.json')))
    sessions = JSON.parse(File.read(File.join(PHASE, 'G0_DECISION_SESSION_REGISTER_2026-08-25.json')))
    ledger = JSON.parse(File.read(LEDGER_PATH))
    capabilities = ledger.fetch('capabilities')

    assert_equal 268, entries.length
    assert_equal 268, entries.map { |entry| entry.fetch('requirement_id') }.uniq.length
    assert entries.all? { |entry| entry.dig('decision', 'status') == 'pending' }
    assert entries.all? { |entry| entry.dig('decision', 'canonical_disposition') == 'pending' }
    assert entries.all? { |entry| entry.dig('accountable_owner', 'appointment_status') == 'pending' }
    assert entries.all? { |entry| entry.dig('approval', 'status') == 'pending' }
    authorized_deferrals = entries.count do |entry|
      entry.dig('decision', 'status') == 'defer' && entry.dig('approval', 'status') == 'recorded'
    end
    assert_equal 0, authorized_deferrals
    assert_empty appointments.fetch('appointments')
    assert_empty appointments.fetch('events')
    assert_empty sessions.fetch('sessions')
    assert_equal 0, ledger.dig('workflow_summary', 'runtime_availability', 'IMPLEMENTED')
    assert_equal 0, ledger.dig('workflow_summary', 'hosted_uat', 'PASS')
    assert_equal 0, ledger.dig('workflow_summary', 'owner_acceptance', 'PASS')
    assert_equal 0, capabilities.count { |capability| capability.dig('workflow_binding', 'status') == 'AUTHORIZED' }
    assert_equal 14, capabilities.count { |capability| capability.dig('workflow_binding', 'status') == 'PROVISIONAL' }
    assert_equal 268, capabilities.count { |capability| capability.dig('reference_presence', 'accountable_owner_reference') == 'PENDING' }
    assert_equal 268, capabilities.count { |capability| capability.dig('reference_presence', 'approval_reference') == 'PENDING' }
    assert_equal 268, capabilities.count { |capability| capability.dig('reference_presence', 'release_evidence_reference') == 'PENDING' }

    assert_includes @proposal, '| Canonical capabilities | 268 unique IDs |'
    assert_includes @proposal, '| Decision rows | 268 recorded |'
    assert_includes @proposal, '| Terminal owner-approved dispositions | 0 |'
    assert_includes @proposal, '| Explicit authorized deferrals | 0 |'
    assert_includes @proposal, '| Accountable-owner appointments | 0 |'
    assert_includes @proposal, '| Decision sessions | 0 |'
    assert_includes @proposal, '| Authorized workflow bindings | 0 |'
    assert_includes @proposal, '| Engineering-supported provisional capability mappings | 14 |'
    assert_includes @proposal, '| Fully implemented E2E workflows in the coverage ledger | 0 |'
    assert_includes @proposal, '| Workflow hosted-UAT PASS | 0 |'
    assert_includes @proposal, '| Workflow owner-acceptance PASS | 0 |'
    assert_includes @proposal, 'All 268 decision-register rows remain `pending`; all 268 owner, approval, and release-evidence references remain pending.'
  end

  def test_proportional_model_preserves_owner_and_independent_control_boundaries
    %w[T1_STANDARD T2_DOMAIN_CRITICAL T3_INDEPENDENT_CONTROL].each do |tier|
      assert_includes @proposal, "`#{tier}`"
    end

    assert_includes @proposal, 'product and affected-domain accountability'
    assert_includes @proposal, 'product approval alone is insufficient'
    assert_includes @proposal, 'An agent, implementation author, passing test, deployment, or technical reviewer cannot substitute'
    assert_includes @proposal, 'must remain a distinct person'
    assert_includes @proposal, 'After expansion, the machine register must still contain 268 explicit terminal rows'
    assert_includes @proposal, 'any true flag in the second group requires `T3_INDEPENDENT_CONTROL`'
    assert_includes @proposal, 'otherwise any true flag in the first group requires at least `T2_DOMAIN_CRITICAL`'
    assert_includes @proposal, 'only an all-false consequence map may use `T1_STANDARD`'
    assert_includes @proposal, 'An unknown flag, missing flag, non-boolean value, declared tier below the derived tier, or conflicting consequence declaration fails closed.'
    assert_includes @proposal, 'Every triggered T2 or T3 independent reviewer must be a distinct identity from the implementation executor and all decision authors and approvers for that event'
    assert_includes @proposal, 'T3 additionally requires the independent-control authority capacity.'

    expected_flags = %w[
      clinical_or_rm_lifecycle diagnostic medication inventory_without_valuation
      tariff_or_charge_without_money_movement claims_simulation report_formula
      correction_or_amendment denial_behavior cross_domain_control implementer_is_approver
      privileged_access_or_security privacy_or_export money_movement_or_stock_valuation
      clinical_safety_override external_integration migration_restore_or_retained_write_recovery
      statutory_output
    ]
    expected_flags.each { |flag| assert_includes @proposal, "`#{flag}`" }

    assert_includes @proposal, 'all members share the same owners, disposition, target pattern, evidence boundary, conditions, acceptance contract, complete consequence map, derived tier, downstream effects, and independent-review requirement'
    assert_includes @proposal, 'Every expanded row retains its complete consequence map and independently derived tier'
    assert_includes @proposal, 'the family tier must equal the maximum derived member tier'
    assert_includes @proposal, 'any mixed-risk member becomes a per-row exception with its own owner record'
  end

  def test_safety_release_and_historical_boundaries_remain_fail_closed
    assert_includes @proposal, '`synthetic_only` and no-live-integration boundaries'
    assert_includes @proposal, 'V2 cannot authorize real patient data or a live BPJS, VClaim, SATUSEHAT'
    assert_includes @proposal, 'No v2 decision can override this boundary.'
    assert_includes @proposal, 'requires a separate governance instrument outside v2 plus explicit user authorization'
    assert_includes @proposal, 'G3 still requires exact-SHA automated evidence, hosted role-based UAT, reconciliation, backup/restore'
    assert_includes @proposal, 'Preserve the current identity registry, policy, appointment register, session register, A–G registers, tests, and historical hashes unchanged'
    assert_includes @proposal, 'Keep G0 `OPEN` until all 268 rows are terminal'
    assert_includes @proposal, 'Radiology, Pharmacy, Claims/BPJS, inventory, and billing must not be selected as implementation shortcuts'
  end

  def test_slice_authorization_is_bounded_and_does_not_close_project_g0
    assert_includes @proposal, 'PROPOSED -> AUTHORIZED_FOR_SYNTHETIC_BUILD | DEFERRED | RETIRED | EXCLUDED'
    assert_includes @proposal, 'PROPOSED -> REVISION_REQUIRED | PROPOSAL_REJECTED'
    assert_includes @proposal, '`revise` | none | `REVISION_REQUIRED` | No'
    assert_includes @proposal, '`reject` | none until an explicit `defer`, `retire`, or `exclude` decision is approved | `PROPOSAL_REJECTED` | No'
    assert_includes @proposal, 'Neither a revision request nor a rejected proposal satisfies the 268-row terminal-decision requirement.'
    assert_includes @proposal, 'It authorizes only the bounded synthetic build described by that slice.'
    assert_includes @proposal, 'It does not authorize a deployment, hosted migration, real-data use, live integration, domain acceptance, or G3.'
    assert_includes @proposal, 'The project-level G0 gate remains `OPEN` until all 268 expanded rows have terminal owner decisions'
    assert_includes @proposal, 'A completed slice is useful progress, not a substitute for the remaining 267 or fewer decisions.'
  end

  def test_v2_migration_dual_runs_and_preserves_v1_rollback
    assert_includes @proposal, 'Dual-run v1 integrity validation and v2 validation'
    assert_includes @proposal, 'prove exact 268-ID, batch, source-hash, pending-state, owner-acceptance, and provisional-binding parity'
    assert_includes @proposal, 'before any v2 consumer can affect a formal gate'
    assert_includes @proposal, 'one versioned, atomic pointer bound to a validated artifact hash'
    assert_includes @proposal, 'restores that last-known-good v2 consumer, or disables governance consumption when no validated predecessor exists'
    assert_includes @proposal, 'Rollback forces G0 and G3 to `OPEN`'
    assert_includes @proposal, 'makes every authorization issued only by the rolled-back v2 version non-operative'
    assert_includes @proposal, 'requires revalidation before those authorizations can be consumed again'
  end

  def test_v2_approval_statement_cannot_be_misread_as_workflow_or_release_approval
    statement = @proposal.split('### Exact approval statement', 2).fetch(1)

    assert_includes statement, 'do not treat this approval as a capability disposition'
    assert_includes statement, 'workflow implementation approval'
    assert_includes statement, 'deployment approval'
    assert_includes statement, 'G3 acceptance'
  end
end
