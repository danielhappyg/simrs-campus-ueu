# frozen_string_literal: true

require 'minitest/autorun'
require 'pathname'

class HistoricalWorkflowArtifactBoundaryTest < Minitest::Test
  ROOT = Pathname.new(File.expand_path('../..', __dir__))
  BANNER = 'HISTORICAL REFERENCE — NOT CURRENT CLEAN-SLATE RUNTIME EVIDENCE'

  DOCUMENTS = %w[
    docs/product/COMPUTER_ASSISTED_CODING_SPEC.md
    docs/operations/COMPUTER_ASSISTED_CODING_VALIDATION.md
    docs/product/ECLAIM_BPJS_SIMULATION_SPEC.md
    docs/operations/ECLAIM_SIMULATION_RUNBOOK.md
    docs/SIMRS_CAMPUS_MASTER_PLAN.md
    docs/adr/ADR-013-ECLAIM-EDUCATIONAL-ADAPTER.md
    docs/research/CODING_REFERENCE_REGISTER.md
    docs/product/OUTPATIENT_TRACEABILITY_MATRIX.md
    docs/operations/OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md
  ].freeze

  CURRENT_STATUS_TARGETS = %w[
    routes/web.php
    docs/operations/T1_LOCAL_MILESTONE_APPROVAL_PACK_2026-08-26.md
    docs/operations/T1_PRODUCTION_PROMOTION_READINESS_2026-08-27.md
    docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_README.md
  ].freeze

  STALE_PRESENT_TENSE_CLAIMS = {
    'docs/product/OUTPATIENT_TRACEABILITY_MATRIX.md' => [
      /bounded application code and automated evidence now exist/i,
      /^## 3\. Current implementation evidence$/i,
      /the codebase now provides automated evidence/i
    ],
    'docs/product/COMPUTER_ASSISTED_CODING_SPEC.md' => [
      /(?:are|is) implemented(?:\.|,| and)/i,
      /current evaluated boundary/i
    ],
    'docs/operations/COMPUTER_ASSISTED_CODING_VALIDATION.md' => [
      /reference implementation creates/i,
      /evaluator now binds/i,
      /are implemented and automated/i,
      /baseline are implemented/i,
      /now create one append-only/i
    ],
    'docs/operations/OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md' => [
      /automated regression now guards/i,
      /guard now has bounded native-browser evidence/i,
      /selector has backend, React interaction/i
    ],
    'docs/operations/ECLAIM_SIMULATION_RUNBOOK.md' => [
      /^## Verification commands$/i
    ]
  }.freeze

  def documents
    @documents ||= DOCUMENTS.to_h do |relative_path|
      [relative_path, File.read(ROOT.join(relative_path))]
    end
  end

  def test_every_historical_document_opens_with_the_conspicuous_boundary
    documents.each do |relative_path, content|
      assert_match(
        /\A# [^\n]+\n\n> \[!CAUTION\]\n> \*\*#{Regexp.escape(BANNER)}\*\*/,
        content,
        relative_path
      )
      assert_match(/current `HEAD` does \*\*not\*\* contain/i, content, relative_path)
    end
  end

  def test_historical_documents_link_to_current_routes_evidence_and_readiness
    documents.each do |relative_path, content|
      linked_targets = content.scan(/\[[^\]]+\]\(([^)#]+)(?:#[^)]+)?\)/).flatten
      resolved_targets = linked_targets.each_with_object([]) do |target, resolved|
        next if target.match?(%r{\A(?:https?://|mailto:)})

        resolved << ROOT.join(relative_path).dirname.join(target).cleanpath.relative_path_from(ROOT).to_s
      end

      CURRENT_STATUS_TARGETS.each do |target|
        assert_includes resolved_targets, target, "#{relative_path} must link to #{target}"
        assert ROOT.join(target).file?, "missing current-status target: #{target}"
      end
    end
  end

  def test_stale_present_tense_implementation_claim_is_not_retained
    documents.each do |relative_path, content|
      refute_match(/\bis now implemented\b/i, content, relative_path)
    end

    STALE_PRESENT_TENSE_CLAIMS.each do |relative_path, patterns|
      content = documents.fetch(relative_path)
      patterns.each { |pattern| refute_match(pattern, content, "#{relative_path}: #{pattern.inspect}") }
    end
  end

  def test_historical_runbook_is_explicitly_non_executable
    runbook = documents.fetch('docs/operations/ECLAIM_SIMULATION_RUNBOOK.md')

    assert_includes runbook, 'DO NOT EXECUTE THIS RUNBOOK AGAINST CURRENT `HEAD` OR ANY HOSTED ENVIRONMENT.'
    assert_operator runbook.index('DO NOT EXECUTE'), :<, runbook.index('## Purpose')
    assert_includes runbook, '## Historical verification commands'
  end

  def test_current_route_surface_has_no_historical_coding_or_eclaim_routes
    routes = File.read(ROOT.join('routes/web.php'))

    refute_match(/e[-_]?claims?|coding|terminology/i, routes)
  end
end
