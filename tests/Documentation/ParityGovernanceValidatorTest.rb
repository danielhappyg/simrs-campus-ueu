# frozen_string_literal: true

require 'fileutils'
require 'digest'
require 'minitest/autorun'
require 'tmpdir'

require_relative '../../scripts/validate-parity-governance'

class ParityGovernanceValidatorTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  SOURCE_MATRIX = File.join(ROOT, 'docs/new-simrs-rebuild/PARITY_REQUIREMENTS_MATRIX.md')
  SOURCE_BASELINE = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/PARITY_MATRIX_BASELINE.json')
  SOURCE_BATCH_MANIFEST = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json')
  SOURCE_BATCH_A_DECISION_REGISTER = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_BATCH_A_DECISION_REGISTER_2026-08-25.json')
  SOURCE_BATCH_B_DECISION_REGISTER = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_BATCH_B_DECISION_REGISTER_2026-08-25.json')
  SOURCE_RELEASE_INDEX = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/RELEASE_EVIDENCE_INDEX.md')

  def setup
    @tmpdir = Dir.mktmpdir('parity-governance')
    @matrix = File.join(@tmpdir, 'matrix.md')
    @baseline = File.join(@tmpdir, 'baseline.json')
    @batch_manifest = File.join(@tmpdir, 'batch-manifest.json')
    @decision_register = File.join(@tmpdir, 'batch-a-decision-register.json')
    @batch_b_decision_register = File.join(@tmpdir, 'batch-b-decision-register.json')
    @release_index = File.join(@tmpdir, 'release-index.md')
    FileUtils.cp(SOURCE_MATRIX, @matrix)
    FileUtils.cp(SOURCE_BASELINE, @baseline)
    FileUtils.cp(SOURCE_BATCH_MANIFEST, @batch_manifest)
    FileUtils.cp(SOURCE_BATCH_A_DECISION_REGISTER, @decision_register)
    FileUtils.cp(SOURCE_BATCH_B_DECISION_REGISTER, @batch_b_decision_register)
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
    assert_equal 28, validator.decision_entries.length
    assert_equal 20, validator.decision_entries_by_batch.fetch('A').length
    assert_equal 8, validator.decision_entries_by_batch.fetch('B').length
  end

  def test_batch_a_decision_register_rejects_missing_row
    mutate_decision_register do |register|
      register['entries'].reject! { |entry| entry['requirement_id'] == 'PAR-ADM-001' }
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'missing Batch A requirement IDs: PAR-ADM-001'
    assert_error validator, 'expected exactly 20 entries, got 19'
  end

  def test_batch_a_decision_register_rejects_duplicate_row
    mutate_decision_register do |register|
      duplicate = Marshal.load(Marshal.dump(register['entries'].first))
      register['entries'] << duplicate
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'duplicate requirement IDs: PAR-ADM-001'
    assert_error validator, 'expected exactly 20 entries, got 21'
  end

  def test_batch_a_decision_register_rejects_unknown_row
    mutate_decision_register do |register|
      register['entries'].first['requirement_id'] = 'PAR-REG-001'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'missing Batch A requirement IDs: PAR-ADM-001'
    assert_error validator, 'unknown Batch A requirement IDs: PAR-REG-001'
  end

  def test_batch_a_decision_register_rejects_invalid_enum
    mutate_decision_register do |register|
      register['entries'].first['decision']['status'] = 'approved'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'invalid decision status "approved"'
  end

  def test_batch_a_decision_register_rejects_false_approval_claim
    mutate_decision_register do |register|
      register['entries'].first['approval']['status'] = 'recorded'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'recorded approval cannot accompany a pending decision'
    assert_error validator, 'approval identity must be a non-empty string'
    assert_error validator, 'approval must reference an existing signed artifact'
  end

  def test_g0_rejects_incomplete_approved_batch_a_entry
    mutate_decision_register do |register|
      entry = register['entries'].first
      entry['decision'] = {
        'status' => 'approve',
        'canonical_disposition' => 'replace',
        'target' => { 'kind' => 'capability', 'reference' => 'Canonical synthetic group administration', 'exclusions' => [] },
        'rationale' => 'Fixture-only proposed approval without the required evidence or authority records.'
      }
    end

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    assert_error validator, 'PAR-ADM-001: G0-approved/deferred entry requires non-pending evidence'
    assert_error validator, 'PAR-ADM-001: G0-approved/deferred entry requires an appointed accountable owner'
    assert_error validator, 'PAR-ADM-001: G0-approved/deferred entry requires all appointment dependencies'
    assert_error validator, 'PAR-ADM-001: G0-approved/deferred entry requires a recorded approval'
    assert_error validator, 'PAR-ADM-001: G0-approved/deferred entry requires ready normal and denial/correction scenarios'
  end

  def test_integrity_rejects_false_complete_register_without_g0_evidence_and_appointments
    approval_artifact = File.join(@tmpdir, 'fixture-approval.txt')
    File.write(approval_artifact, "fixture-only approval evidence\n")
    approval_digest = Digest::SHA256.file(approval_artifact).hexdigest

    mutate_decision_register do |register|
      register['register_status'] = 'complete'
      register['entries'].each do |entry|
        entry['decision'] = {
          'status' => 'defer',
          'canonical_disposition' => 'exclude',
          'target' => {
            'kind' => 'exclusion',
            'reference' => 'Fixture-only teaching deferral',
            'exclusions' => ['Fixture-only exclusion pending real authority.']
          },
          'rationale' => 'Fixture-only decision used to prove that a complete register still requires full G0 evidence.'
        }
        entry['approval'] = {
          'status' => 'recorded',
          'identity' => 'Fixture Owner',
          'date' => '2026-08-25',
          'reference' => 'fixture-approval.txt',
          'artifact_sha256' => approval_digest,
          'conditions' => []
        }
      end
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'G0-approved/deferred entry requires non-pending evidence'
    assert_error validator, 'G0-approved/deferred entry requires an appointed accountable owner'
    assert_error validator, 'G0-approved/deferred entry requires all appointment dependencies'
    assert_error validator, 'G0-approved/deferred entry requires ready normal and denial/correction scenarios'
  end

  def test_signed_artifact_reference_cannot_escape_decision_register_directory
    mutate_decision_register do |register|
      entry = register['entries'].first
      entry['decision'] = {
        'status' => 'defer',
        'canonical_disposition' => 'exclude',
        'target' => {
          'kind' => 'exclusion',
          'reference' => 'Fixture-only teaching deferral',
          'exclusions' => ['Fixture-only exclusion pending real authority.']
        },
        'rationale' => 'Fixture-only decision used to exercise artifact confinement.'
      }
      entry['approval'] = {
        'status' => 'recorded',
        'identity' => 'Fixture Owner',
        'date' => '2026-08-25',
        'reference' => '../outside-approval.txt',
        'artifact_sha256' => '0' * 64,
        'conditions' => []
      }
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'signed artifact must remain inside the decision-register evidence directory'
  end

  def test_structured_approval_artifact_accepts_exact_bound_reviewed_json
    artifact = valid_approval_artifact
    reference, digest = write_json_artifact('approval-valid.json', artifact)
    prepare_recorded_defer(reference, digest)

    validator = fixture_validator

    assert validator.validate, validator.errors.join("\n")
  end

  def test_structured_artifact_rejects_unrelated_json_and_unknown_fields
    reference, digest = write_json_artifact('approval-unrelated.json', { 'unrelated' => true })
    prepare_recorded_defer(reference, digest)

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'approval missing fields:'
    assert_error validator, 'approval unknown fields: unrelated'
  end

  def test_structured_artifact_rejects_malformed_json
    reference, digest = write_raw_artifact('approval-malformed.json', "{not-json\n")
    prepare_recorded_defer(reference, digest)

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'approval contains invalid JSON'
  end

  def test_structured_artifact_rejects_symlink_in_evidence_directory
    outside = File.join(@tmpdir, 'outside-approval.json')
    File.write(outside, JSON.pretty_generate(valid_approval_artifact) + "\n")
    directory_name = ParityGovernanceValidator::BATCH_A_EVIDENCE_DIRECTORY
    directory = File.join(@tmpdir, directory_name)
    FileUtils.mkdir_p(directory)
    link = File.join(directory, 'approval-link.json')
    File.symlink(outside, link)
    reference = File.join(directory_name, 'approval-link.json')
    prepare_recorded_defer(reference, Digest::SHA256.file(outside).hexdigest)

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'signed artifact must be a regular JSON file'
  end

  def test_structured_approval_artifact_rejects_mismatched_par_identity_and_decision
    artifact = valid_approval_artifact.merge(
      'requirement_id' => 'PAR-ADM-002',
      'identity' => 'Different Fixture Owner',
      'decision_status' => 'approve',
      'canonical_disposition' => 'replace'
    )
    reference, digest = write_json_artifact('approval-mismatch.json', artifact)
    prepare_recorded_defer(reference, digest)

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'approval requirement_id does not match PAR-ADM-001'
    assert_error validator, 'approval identity does not match the register'
    assert_error validator, 'approval decision_status does not match the register decision'
    assert_error validator, 'approval canonical_disposition does not match the register decision'
  end

  def test_structured_approval_artifact_requires_independent_reviewer
    artifact = valid_approval_artifact
    artifact['reviewer']['identity'] = artifact['identity']
    reference, digest = write_json_artifact('approval-self-reviewed.json', artifact)
    prepare_recorded_defer(reference, digest)

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'reviewer identity must be distinct from the subject'
  end

  def test_structured_approval_artifact_requires_closed_verification_metadata
    artifact = valid_approval_artifact
    artifact['reviewer'].delete('verification_reference')
    artifact['reviewer']['verification_method'] = 'manual'
    reference, digest = write_json_artifact('approval-bad-review.json', artifact)
    prepare_recorded_defer(reference, digest)

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'reviewer missing fields: verification_reference'
    assert_error validator, 'reviewer verification_method must be one of'
    assert_error validator, 'reviewer verification_reference must be a non-empty string'
  end

  def test_structured_accountable_owner_artifact_rejects_mismatched_identity
    artifact = {
      'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_A_REGISTER_ID,
      'requirement_id' => 'PAR-ADM-001',
      'subject' => 'accountable_owner',
      'identity' => 'Different Appointee',
      'scope' => 'Fixture appointed scope',
      'date' => '2026-08-25',
      'reviewer' => valid_reviewer
    }
    reference, digest = write_json_artifact('owner-mismatch.json', artifact)
    mutate_decision_register do |register|
      register['entries'].first['accountable_owner'] = {
        'identity' => 'Fixture Appointee',
        'required_scope' => 'Approve the fixture boundary.',
        'appointed_scope' => 'Fixture appointed scope',
        'appointment_status' => 'appointed',
        'appointment_date' => '2026-08-25',
        'appointment_reference' => reference,
        'artifact_sha256' => digest
      }
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'accountable owner appointment identity does not match the register'
  end

  def test_structured_dependency_artifact_rejects_mismatched_authority_domain
    artifact = {
      'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_A_REGISTER_ID,
      'requirement_id' => 'PAR-ADM-001',
      'subject' => 'appointment_dependency',
      'identity' => 'Fixture Security Owner',
      'authority_domain' => 'wrong_domain',
      'scope' => 'Canonical disposition and migration scope.',
      'date' => '2026-08-25',
      'reviewer' => valid_reviewer
    }
    reference, digest = write_json_artifact('dependency-mismatch.json', artifact)
    mutate_decision_register do |register|
      dependency = register['entries'].first['appointment_dependencies'].first
      dependency['status'] = 'appointed'
      dependency['identity'] = 'Fixture Security Owner'
      dependency['date'] = '2026-08-25'
      dependency['reference'] = reference
      dependency['artifact_sha256'] = digest
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'appointment authority_domain does not match the register'
  end

  def test_structured_evidence_artifact_rejects_mismatched_par_and_evidence_fields
    artifact = {
      'artifact_type' => ParityGovernanceValidator::EVIDENCE_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_A_REGISTER_ID,
      'requirement_id' => 'PAR-ADM-002',
      'evidence_class' => 'M',
      'date' => '2026-08-25',
      'source' => 'Fixture source',
      'reference' => 'FIX-EVIDENCE-001',
      'interpreter' => 'Fixture Interpreter',
      'confidence' => 'high',
      'reviewer' => valid_reviewer
    }
    reference, digest = write_json_artifact('evidence-mismatch.json', artifact)
    mutate_decision_register do |register|
      register['entries'].first['evidence'] = [{
        'evidence_class' => 'O',
        'date' => '2026-08-25',
        'source' => 'Fixture source',
        'reference' => 'FIX-EVIDENCE-001',
        'interpreter' => 'Fixture Interpreter',
        'confidence' => 'high',
        'note' => 'Fixture evidence.',
        'artifact_reference' => reference,
        'artifact_sha256' => digest
      }]
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'evidence[0] requirement_id does not match PAR-ADM-001'
    assert_error validator, 'evidence[0] evidence_class does not match the register'
  end

  def test_structured_evidence_artifact_accepts_exact_bound_reviewed_json
    evidence = {
      'evidence_class' => 'O',
      'date' => '2026-08-25',
      'source' => 'Fixture source',
      'reference' => 'FIX-EVIDENCE-002',
      'interpreter' => 'Fixture Interpreter',
      'confidence' => 'high'
    }
    artifact = {
      'artifact_type' => ParityGovernanceValidator::EVIDENCE_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_A_REGISTER_ID,
      'requirement_id' => 'PAR-ADM-001',
      'evidence_class' => evidence['evidence_class'],
      'date' => evidence['date'],
      'source' => evidence['source'],
      'reference' => evidence['reference'],
      'interpreter' => evidence['interpreter'],
      'confidence' => evidence['confidence'],
      'reviewer' => valid_reviewer
    }
    reference, digest = write_json_artifact('evidence-valid.json', artifact)
    evidence['note'] = 'Fixture evidence with independent structured review.'
    evidence['artifact_reference'] = reference
    evidence['artifact_sha256'] = digest
    mutate_decision_register do |register|
      register['entries'].first['evidence'] = [evidence]
    end

    validator = fixture_validator

    assert validator.validate, validator.errors.join("\n")
  end

  def test_appointment_dependencies_must_exactly_cover_unique_co_owners
    mutate_decision_register do |register|
      entry = register['entries'].first
      entry['appointment_dependencies'].pop
      duplicate = Marshal.load(Marshal.dump(entry['appointment_dependencies'].first))
      entry['appointment_dependencies'] << duplicate
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'appointment dependency authority_domain values must be unique: product_delivery'
    assert_error validator, 'appointment dependencies must exactly cover co_owners; missing ["affected_role_owner"]'
  end

  def test_batch_a_decision_register_rejects_two_row_consolidation_cycle
    mutate_decision_register do |register|
      first = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-ADM-001' }
      second = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-ADM-002' }
      first['decision'] = approved_consolidation_decision('PAR-ADM-002')
      second['decision'] = approved_consolidation_decision('PAR-ADM-001')
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'consolidation cycle detected: PAR-ADM-001 -> PAR-ADM-002 -> PAR-ADM-001'
  end

  def test_batch_b_decision_register_rejects_missing_row
    mutate_batch_b_decision_register do |register|
      register['entries'].reject! { |entry| entry['requirement_id'] == 'PAR-ADM-009' }
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'missing Batch B requirement IDs: PAR-ADM-009'
    assert_error validator, 'expected exactly 8 entries, got 7'
  end

  def test_batch_b_decision_register_rejects_duplicate_row
    mutate_batch_b_decision_register do |register|
      register['entries'] << Marshal.load(Marshal.dump(register['entries'].first))
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'duplicate requirement IDs: PAR-ADM-009'
    assert_error validator, 'expected exactly 8 entries, got 9'
  end

  def test_batch_b_decision_register_rejects_unknown_row
    mutate_batch_b_decision_register do |register|
      register['entries'].first['requirement_id'] = 'PAR-CLN-001'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'missing Batch B requirement IDs: PAR-ADM-009'
    assert_error validator, 'unknown Batch B requirement IDs: PAR-CLN-001'
  end

  def test_batch_b_decision_register_rejects_false_complete_state
    mutate_batch_b_decision_register { |register| register['register_status'] = 'complete' }

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'Batch B decision register: register_status complete requires every entry to be approved or deferred'
    assert_error validator, 'Batch B decision register PAR-ADM-009: G0 remains open until decision status is approve or defer'
  end

  def test_batch_b_decision_register_rejects_omitted_co_owner_dependency
    mutate_batch_b_decision_register do |register|
      entry = register['entries'].first
      entry['appointment_dependencies'].reject! { |dependency| dependency['authority_domain'] == 'security_privacy_data' }
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'appointment dependencies must exactly cover co_owners; missing ["security_privacy_data"]'
  end

  def test_batch_b_structured_approval_artifact_rejects_mismatched_binding
    artifact = {
      'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_A_REGISTER_ID,
      'requirement_id' => 'PAR-REG-001',
      'subject' => 'approval',
      'identity' => 'Different Batch B Fixture Owner',
      'scope' => 'Approve the Batch B fixture-only deferral.',
      'date' => '2026-08-25',
      'decision_status' => 'approve',
      'canonical_disposition' => 'replace',
      'conditions' => [],
      'reviewer' => valid_reviewer
    }
    reference, digest = write_json_artifact('batch-b-approval-mismatch.json', artifact, batch: 'B')
    prepare_batch_b_recorded_defer(reference, digest)

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'Batch B decision register PAR-ADM-009: approval register_id does not match the decision register'
    assert_error validator, 'approval requirement_id does not match PAR-ADM-009'
    assert_error validator, 'approval identity does not match the register'
    assert_error validator, 'approval decision_status does not match the register decision'
    assert_error validator, 'approval canonical_disposition does not match the register decision'
  end

  def test_decision_registers_reject_cross_batch_consolidation_cycle
    mutate_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-ADM-001' }
      entry['decision'] = approved_consolidation_decision('PAR-REG-001')
    end
    mutate_batch_b_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-REG-001' }
      entry['decision'] = approved_consolidation_decision('PAR-ADM-001')
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'consolidation cycle detected: PAR-ADM-001 -> PAR-REG-001 -> PAR-ADM-001'
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

  def test_batch_manifest_enforces_batch_b_exact_set_even_when_counts_and_coverage_match
    mutate_manifest do |manifest|
      manifest['batches']['B'].delete('PAR-REG-001')
      manifest['batches']['B'] << 'PAR-ADM-001'
      manifest['batches']['A'].delete('PAR-ADM-001')
      manifest['batches']['A'] << 'PAR-REG-001'
      manifest['batches']['A'].sort!
      manifest['batches']['B'].sort!
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'Batch B exact set mismatch'
    assert_error validator, 'missing ["PAR-REG-001"]'
    assert_error validator, 'unexpected ["PAR-ADM-001"]'
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
    assert_error validator, 'G0 remains open until decision status is approve or defer'
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
      decision_register_path: SOURCE_BATCH_A_DECISION_REGISTER,
      batch_b_decision_register_path: SOURCE_BATCH_B_DECISION_REGISTER,
      release_index_path: SOURCE_RELEASE_INDEX,
      mode: 'integrity'
    )
  end

  def fixture_validator(mode: 'integrity')
    ParityGovernanceValidator.new(
      matrix_path: @matrix,
      baseline_path: @baseline,
      batch_manifest_path: @batch_manifest,
      decision_register_path: @decision_register,
      batch_b_decision_register_path: @batch_b_decision_register,
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

  def mutate_decision_register
    register = JSON.parse(File.read(@decision_register))
    yield register
    File.write(@decision_register, JSON.pretty_generate(register) + "\n")
  end

  def mutate_batch_b_decision_register
    register = JSON.parse(File.read(@batch_b_decision_register))
    yield register
    File.write(@batch_b_decision_register, JSON.pretty_generate(register) + "\n")
  end

  def prepare_recorded_defer(reference, digest)
    mutate_decision_register do |register|
      entry = register['entries'].first
      entry['decision'] = {
        'status' => 'defer',
        'canonical_disposition' => 'exclude',
        'target' => {
          'kind' => 'exclusion',
          'reference' => 'Fixture-only teaching deferral',
          'exclusions' => ['Fixture-only exclusion.']
        },
        'rationale' => 'Fixture-only structured approval validation.'
      }
      entry['approval'] = {
        'status' => 'recorded',
        'identity' => 'Fixture Owner',
        'scope' => 'Approve the fixture-only teaching deferral.',
        'date' => '2026-08-25',
        'reference' => reference,
        'artifact_sha256' => digest,
        'conditions' => []
      }
    end
  end

  def prepare_batch_b_recorded_defer(reference, digest)
    mutate_batch_b_decision_register do |register|
      entry = register['entries'].first
      entry['decision'] = {
        'status' => 'defer',
        'canonical_disposition' => 'exclude',
        'target' => {
          'kind' => 'exclusion',
          'reference' => 'Fixture-only Batch B teaching deferral',
          'exclusions' => ['Fixture-only exclusion.']
        },
        'rationale' => 'Fixture-only structured Batch B approval validation.'
      }
      entry['approval'] = {
        'status' => 'recorded',
        'identity' => 'Batch B Fixture Owner',
        'scope' => 'Approve the Batch B fixture-only deferral.',
        'date' => '2026-08-25',
        'reference' => reference,
        'artifact_sha256' => digest,
        'conditions' => []
      }
    end
  end

  def valid_approval_artifact
    {
      'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_A_REGISTER_ID,
      'requirement_id' => 'PAR-ADM-001',
      'subject' => 'approval',
      'identity' => 'Fixture Owner',
      'scope' => 'Approve the fixture-only teaching deferral.',
      'date' => '2026-08-25',
      'decision_status' => 'defer',
      'canonical_disposition' => 'exclude',
      'conditions' => [],
      'reviewer' => valid_reviewer
    }
  end

  def valid_reviewer
    {
      'identity' => 'Independent Fixture Reviewer',
      'verification_method' => 'signed_document_review',
      'verification_reference' => 'FIX-REVIEW-20260825-01'
    }
  end

  def write_json_artifact(filename, artifact, batch: 'A')
    write_raw_artifact(filename, JSON.pretty_generate(artifact) + "\n", batch: batch)
  end

  def write_raw_artifact(filename, content, batch: 'A')
    directory_name = if batch == 'A'
                       ParityGovernanceValidator::BATCH_A_EVIDENCE_DIRECTORY
                     else
                       ParityGovernanceValidator::BATCH_B_EVIDENCE_DIRECTORY
                     end
    directory = File.join(@tmpdir, directory_name)
    FileUtils.mkdir_p(directory)
    path = File.join(directory, filename)
    File.write(path, content)
    [File.join(directory_name, filename), Digest::SHA256.file(path).hexdigest]
  end

  def approved_consolidation_decision(target)
    {
      'status' => 'approve',
      'canonical_disposition' => 'consolidate',
      'target' => { 'kind' => 'consolidation_target', 'reference' => target, 'exclusions' => [] },
      'rationale' => 'Fixture-only consolidation used for cycle detection.'
    }
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
