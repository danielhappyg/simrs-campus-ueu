# frozen_string_literal: true

require 'digest'
require 'minitest/autorun'

class G0GovernanceV2IndependentReviewTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  REVIEW_PATH = File.join(ROOT, 'docs/operations/G0_GOVERNANCE_V2_INDEPENDENT_TECHNICAL_SECURITY_REVIEW_2026-08-29.md')
  ADR_PATH = File.join(ROOT, 'docs/adr/ADR-018-PROPORTIONAL-G0-GOVERNANCE-PROFILE.md')
  PROPOSAL_PATH = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_PROPORTIONAL_GOVERNANCE_V2_PROPOSAL_2026-08-28.md')

  ADR_SHA256 = 'cd7834e7f5b0be99acee3f1b46f8af9fc81b77418541fddf7276fadc26edf962'
  PROPOSAL_SHA256 = 'f5c635006f878a68c4b0775be5262115fe38e185562d3be558e02d8b28c38695'
  REVIEW_ID = 'G0-GOV-V2-INDEPENDENT-REVIEW-2026-08-29-CODEX-HUBBLE-2'
  REVIEW_TIME = '2026-08-29T04:06:17+07:00'

  def setup
    @review = File.read(REVIEW_PATH)
    @adr = File.read(ADR_PATH)
    @proposal = File.read(PROPOSAL_PATH)
  end

  def test_review_is_exactly_attributed_without_human_or_owner_overclaim
    assert_includes @review, "**Review ID:** `#{REVIEW_ID}`"
    assert_includes @review, "**Review time:** `#{REVIEW_TIME}`"
    assert_includes @review, '**Reviewer:** Independent Codex technical/security reviewer'
    assert_includes @review, '**Agent path:** `/root/ci_portability_review`'
    assert_includes @review, '**Agent name:** `Hubble the 2nd`'
    assert_includes @review, '**Reviewer nature:** AI agent; not a human, product owner, domain owner, institutional authority, deployment approver, or G3 acceptor.'
    assert_includes @review, '**Authority effect:** None. This review is technical/security evidence and does not itself confer adoption or implementation authority.'
  end

  def test_review_binds_exact_artifact_hashes_and_source_statuses
    assert_equal ADR_SHA256, Digest::SHA256.file(ADR_PATH).hexdigest
    assert_equal PROPOSAL_SHA256, Digest::SHA256.file(PROPOSAL_PATH).hexdigest
    assert_includes @review, "`#{ADR_SHA256}`"
    assert_includes @review, "`#{PROPOSAL_SHA256}`"
    assert_includes @adr, 'Status: **Proposed / not approved / no implementation authority**'
    assert_includes @proposal, '**Status:** `PROPOSAL / NOT APPROVED / NOT AUTHORITATIVE`'
    assert_includes @review, 'No canonical adoption-decision draft or record was used as review evidence.'
    assert_includes @review, 'A later revision to either artifact requires a new hash-bound independent review.'
  end

  def test_verdict_is_pass_for_gate_a_local_implementation_only
    assert_includes @review, '**Verdict:** `PASS`'
    assert_includes @review, '**Verdict scope:** Gate A local governance-v2 implementation readiness only'
    assert_includes @review, '**PASS — Gate A local governance-v2 implementation only.**'
    assert_includes @review, 'No P1 or P2 blocking finding was identified for Gate A local implementation only.'
    assert_includes @review, 'This PASS is review evidence only.'
  end

  def test_review_explicitly_preserves_every_authority_boundary
    required_boundaries = [
      'adopt governance v2 or substitute for an attributable product-owner adoption decision',
      'appoint me or any agent as a product, clinical, RMIK, nursing, laboratory, radiology, pharmacy, finance, security, privacy, or other domain authority',
      'authorize consumer activation or treat observation-only candidate validation as activation',
      'approve any capability disposition, family decision, slice, or application workflow implementation',
      'authorize hosted migration, deployment, real patient data, or a live integration',
      'close G0, establish owner acceptance, or establish G3 acceptance',
      'convert engineering evidence, tests, receipts, ledgers, or this review into owner authority'
    ]
    required_boundaries.each { |boundary| assert_includes @review, boundary }

    forbidden_claims = [
      '**Reviewer nature:** Human',
      '**Authority effect:** Adoption approved',
      '**Verdict:** `APPROVED`',
      '**Verdict scope:** Consumer activation',
      '**Verdict scope:** Capability approval',
      '**Verdict scope:** Deployment',
      '**Verdict scope:** G3 acceptance',
      '**Production readiness:** PASS'
    ]
    forbidden_claims.each { |claim| refute_includes @review, claim }
  end

  def test_review_covers_required_technical_and_security_dimensions
    required_sections = [
      '### 1. Fail-closed machine contracts — PASS',
      '### 2. Historical v1 preservation — PASS',
      '### 3. Canonicalization and provenance — PASS FOR IMPLEMENTATION',
      '### 4. Authority and state separation — PASS',
      '### 5. Canonical paths, symlinks, and atomic durability — PASS FOR IMPLEMENTATION',
      '### 6. Rollback, recovery, and concurrency — PASS FOR IMPLEMENTATION',
      '### 7. Ledger and evidence separation — PASS',
      '### 8. Secrets, synthetic data, and live-system boundaries — PASS',
      '### 9. CI and negative-test plan — PASS FOR IMPLEMENTATION',
      '### 10. Maintainability — PASS',
      '## Residual risks and mandatory follow-through'
    ]
    required_sections.each { |section| assert_includes @review, section }
  end

  def test_methods_and_completed_validation_are_recorded_without_overstatement
    assert_includes @review, 'Recomputed SHA-256 over the two reviewed working-tree files'
    assert_includes @review, 'Performed adversarial analysis of malformed and duplicate JSON'
    assert_includes @review, 'ADR contract (`12` runs, `481` assertions, no failures)'
    assert_includes @review, 'proposal contract (`7` runs, `183` assertions, no failures)'
    assert_includes @review, 'v1 integrity validator (`268` requirements, `268` batch assignments, `268` governed decisions; passed)'
    refute_includes @review, 'implementation tests passed'
    refute_includes @review, 'activation tests passed'
    refute_includes @review, 'deployment tests passed'
  end

  def test_review_is_secret_free_and_structurally_complete
    secret_pattern = /(?:-----BEGIN [A-Z ]*PRIVATE KEY-----|postgres(?:ql)?:\/\/[^\s:]+:[^\s@]+@|(?:password|passwd|secret|api[_-]?key|access[_-]?token|refresh[_-]?token)["']?\s*(?::|=)\s*["']?[^\s,;}"']+)/i
    refute_match secret_pattern, @review
    assert_predicate @review.scan(/^```/).length, :even?
    assert_includes @review, '# G0 governance v2 independent technical/security review — 2026-08-29'
    assert_includes @review, '## Findings'
    assert_includes @review, '## Verdict'
  end
end
