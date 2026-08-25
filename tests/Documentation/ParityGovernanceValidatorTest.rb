# frozen_string_literal: true

require 'fileutils'
require 'minitest/autorun'
require 'tmpdir'

require_relative '../../scripts/validate-parity-governance'

class ParityGovernanceValidatorTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  SOURCE_MATRIX = File.join(ROOT, 'docs/new-simrs-rebuild/PARITY_REQUIREMENTS_MATRIX.md')
  SOURCE_BASELINE = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/PARITY_MATRIX_BASELINE.json')
  SOURCE_BATCH_MANIFEST = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json')
  SOURCE_RELEASE_INDEX = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/RELEASE_EVIDENCE_INDEX.md')

  def setup
    @tmpdir = Dir.mktmpdir('parity-governance')
    @matrix = File.join(@tmpdir, 'matrix.md')
    @baseline = File.join(@tmpdir, 'baseline.json')
    @batch_manifest = File.join(@tmpdir, 'batch-manifest.json')
    @release_index = File.join(@tmpdir, 'release-index.md')
    FileUtils.cp(SOURCE_MATRIX, @matrix)
    FileUtils.cp(SOURCE_BASELINE, @baseline)
    FileUtils.cp(SOURCE_BATCH_MANIFEST, @batch_manifest)
    FileUtils.cp(SOURCE_RELEASE_INDEX, @release_index)
  end

  def teardown
    FileUtils.remove_entry(@tmpdir)
  end

  def test_current_repository_matrix_passes_integrity
    validator = repository_validator

    assert validator.validate, validator.errors.join("\n")
    assert_equal 268, validator.rows.length
    assert_equal 268, validator.batch_assignments.length
  end

  def test_batch_manifest_rejects_missing_and_duplicate_assignments
    mutate_manifest do |manifest|
      manifest['batches']['B'].delete('PAR-REG-001')
      manifest['batches']['B'] << 'PAR-REG-002'
      manifest['batches']['B'].sort!
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'requirement IDs assigned more than once: PAR-REG-002 (B, B)'
    assert_error validator, 'missing baseline requirement IDs: PAR-REG-001'
  end

  def test_batch_manifest_rejects_invalid_batch_and_wrong_count
    mutate_manifest do |manifest|
      manifest['batches']['H'] = manifest['batches'].delete('G')
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'missing batches: G'
    assert_error validator, 'invalid batches: H'
  end

  def test_batch_manifest_rejects_wrong_exact_count
    mutate_manifest do |manifest|
      manifest['batches']['G'].pop
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'batch G must contain exactly 120 IDs, got 119'
    assert_error validator, 'expected exactly 268 assignments, got 267'
  end

  def test_batch_manifest_enforces_batch_a_exact_set_even_when_counts_and_coverage_match
    mutate_manifest do |manifest|
      manifest['batches']['A'].delete('PAR-ADM-001')
      manifest['batches']['A'] << 'PAR-REG-001'
      manifest['batches']['B'].delete('PAR-REG-001')
      manifest['batches']['B'] << 'PAR-ADM-001'
      manifest['batches']['A'].sort!
      manifest['batches']['B'].sort!
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'Batch A exact set mismatch'
    assert_error validator, 'missing ["PAR-ADM-001"]'
    assert_error validator, 'unexpected ["PAR-REG-001"]'
  end

  def test_exact_id_set_rejects_missing_unexpected_and_duplicate_ids
    mutate_row('PAR-REG-001') { |cells| cells[0] = 'PAR-REG-999' }
    mutate_row('PAR-REG-002') { |cells| cells[0] = 'PAR-REG-003' }

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'missing baseline requirement IDs: PAR-REG-001, PAR-REG-002'
    assert_error validator, 'unexpected requirement IDs: PAR-REG-999'
    assert_error validator, 'duplicate requirement IDs: PAR-REG-003'
  end

  def test_rejects_noncanonical_shape_and_empty_cells
    mutate_row('PAR-REG-001') { |cells| cells.pop }
    mutate_row('PAR-REG-002') { |cells| cells[4] = '' }

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'expected 10 cells, got 9'
    assert_error validator, 'Evidence must not be empty'
  end

  def test_rejects_category_mismatch
    mutate_row('PAR-REG-001') { |cells| cells[1] = 'Pemeriksaan' }

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'category "Pemeriksaan" must be "Pendaftaran"'
  end

  def test_rejects_invalid_disposition_and_status_vocabularies
    mutate_row('PAR-REG-001') do |cells|
      cells[3] = 'Build it'
      cells[5] = 'Done'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'invalid disposition "Build it"'
    assert_error validator, 'invalid parity status "Done"'
  end

  def test_integrity_rejects_placeholder_only_owner_on_promoted_row
    mutate_row('PAR-REG-001') { |cells| cells[6] = 'Clinical owner TBD' }

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'requires a non-placeholder accountable owner'
  end

  def test_integrity_allows_mixed_placeholder_and_named_interim_owner
    mutate_row('PAR-REG-001') { |cells| cells[6] = 'Clinical TBD / Daniel interim' }

    validator = fixture_validator

    assert validator.validate, validator.errors.join("\n")
  end

  def test_g0_rejects_placeholder_tokens_in_any_owner
    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    assert_error validator, 'g0 forbids placeholder owner'
    assert validator.errors.all? { |error| error.include?('g0 forbids placeholder owner') }, validator.errors.join("\n")
  end

  def test_rejects_missing_unknown_and_self_consolidation_targets
    mutate_row('PAR-REG-003') { |cells| cells[3] = 'Consolidate' }
    mutate_row('PAR-REG-004') { |cells| cells[3] = 'Consolidate → PAR-REG-999' }
    mutate_row('PAR-REG-005') { |cells| cells[3] = 'Consolidate → PAR-REG-005' }

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'Consolidate requires at least one canonical PAR target'
    assert_error validator, 'consolidation target PAR-REG-999 does not exist'
    assert_error validator, 'consolidation cannot target itself'
  end

  def test_rejects_consolidation_cycles
    mutate_row('PAR-REG-003') { |cells| cells[3] = 'Consolidate → PAR-REG-005' }

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'consolidation cycle detected: PAR-REG-003 -> PAR-REG-005 -> PAR-REG-003'
  end

  def test_release_register_rejects_duplicate_and_invalid_rel_ids
    register = File.read(@release_index)
    duplicate = register.lines.find { |line| line.start_with?('| REL-20260821-01 |') }
    register = register.sub('REL-20260821-02', 'REL-20261399-02')
    register = register.sub("\n## State vocabulary", "\n#{duplicate}\n## State vocabulary")
    File.write(@release_index, register)

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'duplicate evidence IDs: REL-20260821-01'
    assert_error validator, 'release evidence ID has invalid date "REL-20261399-02"'
  end

  def test_accepted_row_passes_with_matching_artifacts_frontmatter_and_release
    prepare_valid_accepted_fixture

    validator = fixture_validator

    assert validator.validate, validator.errors.join("\n")
  end

  def test_accepted_row_rejects_missing_artifact_and_frontmatter_mismatch
    prepare_valid_accepted_fixture
    FileUtils.rm(File.join(@tmpdir, 'detail.md'))
    decision_path = File.join(@tmpdir, 'decision.md')
    decision = File.read(decision_path).sub('parity_requirement_id: PAR-REG-001', 'parity_requirement_id: PAR-REG-002')
    File.write(decision_path, decision)

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'linked artifact does not exist: detail.md'
    assert_error validator, 'no acceptance artifact has matching Accepted frontmatter'
  end

  def test_accepted_row_rejects_placeholder_owner_and_weak_release_evidence
    prepare_valid_accepted_fixture
    decision_path = File.join(@tmpdir, 'decision.md')
    decision = File.read(decision_path).sub('domain_owner: RMIK Department', 'domain_owner: TBD')
    File.write(decision_path, decision)
    release = File.read(@release_index)
      .sub('`abcdef1234567890` on `main`', 'pending commit')
      .sub('`origin/main` at `abcdef1234567890`', 'not pushed')
      .sub('Vercel production https://demo.example.test deployment `dpl_fixture`', 'not deployed')
      .sub('G3 PASS', 'G3 PARTIAL')
    File.write(@release_index, release)

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'acceptance artifact domain_owner must not contain a placeholder'
    assert_error validator, 'must record a concrete commit SHA'
    assert_error validator, 'must record a concrete pushed remote ref'
    assert_error validator, 'must record a concrete deployment environment'
    assert_error validator, 'gate must record Gx PASS'
  end

  private

  def repository_validator
    ParityGovernanceValidator.new(
      matrix_path: SOURCE_MATRIX,
      baseline_path: SOURCE_BASELINE,
      batch_manifest_path: SOURCE_BATCH_MANIFEST,
      release_index_path: SOURCE_RELEASE_INDEX,
      mode: 'integrity'
    )
  end

  def fixture_validator(mode: 'integrity')
    ParityGovernanceValidator.new(
      matrix_path: @matrix,
      baseline_path: @baseline,
      batch_manifest_path: @batch_manifest,
      release_index_path: @release_index,
      mode: mode
    )
  end

  def mutate_row(id)
    lines = File.readlines(@matrix)
    matrix_start = lines.index { |line| line.strip == '## Matrix' }
    raise 'fixture matrix section not found' unless matrix_start

    index = ((matrix_start + 1)...lines.length).find { |line_number| lines[line_number].start_with?("| #{id} |") }
    raise "fixture row not found: #{id}" unless index

    cells = lines[index].strip[1...-1].split('|', -1).map(&:strip)
    yield cells
    lines[index] = "| #{cells.join(' | ')} |\n"
    File.write(@matrix, lines.join)
  end

  def mutate_manifest
    manifest = JSON.parse(File.read(@batch_manifest))
    yield manifest
    File.write(@batch_manifest, JSON.pretty_generate(manifest) + "\n")
  end

  def prepare_valid_accepted_fixture
    mutate_row('PAR-REG-001') do |cells|
      cells[5] = 'Accepted'
      cells[6] = 'Daniel Happy Putra + RMIK Department'
      cells[7] = 'Canonical inpatient registration'
      cells[8] = '[FR](detail.md)'
      cells[9] = '[owner acceptance](decision.md); REL-20260825-99'
    end

    File.write(File.join(@tmpdir, 'detail.md'), "# Detailed requirement\n")
    File.write(File.join(@tmpdir, 'reconciliation.md'), "# Reconciliation evidence\n")
    File.write(File.join(@tmpdir, 'decision.md'), <<~MARKDOWN)
      ---
      parity_requirement_id: PAR-REG-001
      decision: Accepted
      business_owner: Daniel Happy Putra
      domain_owner: RMIK Department
      decision_date: 2026-08-25
      release_evidence_id: REL-20260825-99
      ---

      # Acceptance decision
    MARKDOWN

    row = '| REL-20260825-99 | 2026-08-25 | PAR-REG-001 accepted fixture | `ruby tests` passed | `abcdef1234567890` on `main` | `origin/main` at `abcdef1234567890` | Vercel production https://demo.example.test deployment `dpl_fixture` | [reconciliation](reconciliation.md) | G3 PASS | Promote deployment `dpl_previous` and restore reviewed backup |'
    register = File.read(@release_index).sub("\n## State vocabulary", "\n#{row}\n\n## State vocabulary")
    File.write(@release_index, register)
  end

  def assert_error(validator, fragment)
    assert validator.errors.any? { |error| error.include?(fragment) }, <<~MESSAGE
      Expected an error containing #{fragment.inspect}.
      Actual errors:
      #{validator.errors.join("\n")}
    MESSAGE
  end
end
