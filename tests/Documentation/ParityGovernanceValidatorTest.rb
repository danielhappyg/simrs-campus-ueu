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
  SOURCE_BATCH_C_DECISION_REGISTER = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_BATCH_C_DECISION_REGISTER_2026-08-25.json')
  SOURCE_BATCH_D_DECISION_REGISTER = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_BATCH_D_DECISION_REGISTER_2026-08-25.json')
  SOURCE_BATCH_E_DECISION_REGISTER = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_BATCH_E_DECISION_REGISTER_2026-08-25.json')
  SOURCE_BATCH_F_DECISION_REGISTER = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_BATCH_F_DECISION_REGISTER_2026-08-25.json')
  SOURCE_BATCH_G_DECISION_REGISTER = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_BATCH_G_DECISION_REGISTER_2026-08-25.json')
  SOURCE_RELEASE_INDEX = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/RELEASE_EVIDENCE_INDEX.md')

  def setup
    @tmpdir = Dir.mktmpdir('parity-governance')
    @matrix = File.join(@tmpdir, 'matrix.md')
    @baseline = File.join(@tmpdir, 'baseline.json')
    @batch_manifest = File.join(@tmpdir, 'batch-manifest.json')
    @decision_register = File.join(@tmpdir, 'batch-a-decision-register.json')
    @batch_b_decision_register = File.join(@tmpdir, 'batch-b-decision-register.json')
    @batch_c_decision_register = File.join(@tmpdir, 'batch-c-decision-register.json')
    @batch_d_decision_register = File.join(@tmpdir, 'batch-d-decision-register.json')
    @batch_e_decision_register = File.join(@tmpdir, 'batch-e-decision-register.json')
    @batch_f_decision_register = File.join(@tmpdir, 'batch-f-decision-register.json')
    @batch_g_decision_register = File.join(@tmpdir, 'batch-g-decision-register.json')
    @release_index = File.join(@tmpdir, 'release-index.md')
    FileUtils.cp(SOURCE_MATRIX, @matrix)
    FileUtils.cp(SOURCE_BASELINE, @baseline)
    FileUtils.cp(SOURCE_BATCH_MANIFEST, @batch_manifest)
    FileUtils.cp(SOURCE_BATCH_A_DECISION_REGISTER, @decision_register)
    FileUtils.cp(SOURCE_BATCH_B_DECISION_REGISTER, @batch_b_decision_register)
    FileUtils.cp(SOURCE_BATCH_C_DECISION_REGISTER, @batch_c_decision_register)
    FileUtils.cp(SOURCE_BATCH_D_DECISION_REGISTER, @batch_d_decision_register)
    FileUtils.cp(SOURCE_BATCH_E_DECISION_REGISTER, @batch_e_decision_register)
    FileUtils.cp(SOURCE_BATCH_F_DECISION_REGISTER, @batch_f_decision_register)
    FileUtils.cp(SOURCE_BATCH_G_DECISION_REGISTER, @batch_g_decision_register)
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
    assert_equal 268, validator.decision_entries.length
    assert_equal 20, validator.decision_entries_by_batch.fetch('A').length
    assert_equal 8, validator.decision_entries_by_batch.fetch('B').length
    assert_equal 19, validator.decision_entries_by_batch.fetch('C').length
    assert_equal 19, validator.decision_entries_by_batch.fetch('D').length
    assert_equal 48, validator.decision_entries_by_batch.fetch('E').length
    assert_equal 34, validator.decision_entries_by_batch.fetch('F').length
    assert_equal 120, validator.decision_entries_by_batch.fetch('G').length
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
      'authority_domain' => 'fixture_governance',
      'scope' => 'Fixture appointed scope',
      'date' => '2026-08-25',
      'reviewer' => valid_reviewer
    }
    reference, digest = write_json_artifact('owner-mismatch.json', artifact)
    mutate_decision_register do |register|
      register['entries'].first['accountable_owner'] = {
        'identity' => 'Fixture Appointee',
        'authority_domain' => 'fixture_governance',
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

  def test_batch_c_decision_register_rejects_missing_duplicate_and_unknown_rows
    mutate_batch_c_decision_register do |register|
      register['entries'].reject! { |entry| entry['requirement_id'] == 'PAR-ADM-004' }
      register['entries'] << Marshal.load(Marshal.dump(register['entries'].first))
      register['entries'][1]['requirement_id'] = 'PAR-CLN-020'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'missing Batch C requirement IDs: PAR-ADM-004, PAR-ADM-026'
    assert_error validator, 'duplicate requirement IDs: PAR-ADM-021'
    assert_error validator, 'unknown Batch C requirement IDs: PAR-CLN-020'
  end

  def test_batch_c_decision_register_rejects_false_complete_state
    mutate_batch_c_decision_register { |register| register['register_status'] = 'complete' }

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'Batch C decision register: register_status complete requires every entry to be approved or deferred'
    assert_error validator, 'Batch C decision register PAR-ADM-004: G0 remains open until decision status is approve or defer'
  end

  def test_shared_registry_fails_closed_when_batch_c_register_is_omitted
    FileUtils.rm(@batch_c_decision_register)

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'Batch C decision register: file not found'
    assert_error validator, 'Batch C decision register: expected exactly 19 entries, got 0'
  end

  def test_batch_c_rejects_product_only_authority_even_when_dependency_equality_holds
    mutate_batch_c_decision_register do |register|
      entry = register['entries'].first
      entry['co_owners'] = ['product_delivery']
      entry['appointment_dependencies'] = [entry['appointment_dependencies'].first]
      entry['lead_authority_domain'] = 'product_delivery'
      entry['accountable_owner']['authority_domain'] = 'product_delivery'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'co_owners must exactly match required authorities'
    assert_error validator, 'lead_authority_domain must be clinical_governance'
    assert_error validator, 'accountable owner authority_domain must match lead authority clinical_governance'
  end

  def test_batch_c_rejects_missing_failure_dimension
    mutate_batch_c_decision_register do |register|
      register['entries'].first['synthetic_scenarios'].delete('dependency_outage')
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'synthetic_scenarios must contain exactly normal, denial, correction_or_amendment, dependency_outage'
    assert_error validator, 'synthetic scenario dependency_outage must be an object'
  end

  def test_g0_requires_all_four_batch_c_scenarios_ready
    mutate_batch_c_decision_register do |register|
      entry = register['entries'].first
      entry['decision'] = {
        'status' => 'approve',
        'canonical_disposition' => 'replace',
        'target' => { 'kind' => 'capability', 'reference' => 'Fixture clinical-pathway capability', 'exclusions' => [] },
        'rationale' => 'Fixture-only approval used to verify the four-dimension readiness gate.'
      }
    end

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    assert_error validator, 'G0-approved/deferred entry requires ready normal, denial, correction_or_amendment, dependency_outage scenarios'
  end

  def test_batch_c_accountable_owner_artifact_binds_lead_authority
    entry = JSON.parse(File.read(@batch_c_decision_register))['entries'].first
    scope = entry.dig('accountable_owner', 'required_scope')
    artifact = {
      'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_C_REGISTER_ID,
      'requirement_id' => 'PAR-ADM-004',
      'subject' => 'accountable_owner',
      'identity' => 'Fixture Clinical Lead',
      'authority_domain' => 'product_delivery',
      'scope' => scope,
      'date' => '2026-08-25',
      'reviewer' => valid_reviewer
    }
    reference, digest = write_json_artifact('batch-c-owner-wrong-authority.json', artifact, batch: 'C')
    mutate_batch_c_decision_register do |register|
      owner = register['entries'].first['accountable_owner']
      owner['identity'] = 'Fixture Clinical Lead'
      owner['appointed_scope'] = owner['required_scope']
      owner['appointment_status'] = 'appointed'
      owner['appointment_date'] = '2026-08-25'
      owner['appointment_reference'] = reference
      owner['artifact_sha256'] = digest
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'accountable owner appointment authority_domain does not match the register'
  end

  def test_batch_c_rejects_product_domain_recorded_approval
    prepare_fully_resolved_batch_c_register
    mutate_batch_c_decision_register do |register|
      register['entries'].first['approval']['authority_domain'] = 'product_delivery'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'approval authority_domain must match lead authority clinical_governance'
    assert_error validator, 'approval authority_domain does not match the register'
  end

  def test_batch_c_pending_approval_requires_null_authority_domain
    mutate_batch_c_decision_register do |register|
      register['entries'].first['approval']['authority_domain'] = 'clinical_governance'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'pending approval authority_domain must be null'
  end

  def test_batch_c_rejects_recorded_approval_from_wrong_identity
    prepare_fully_resolved_batch_c_register
    mutate_batch_c_decision_register do |register|
      register['entries'].first['approval']['identity'] = 'Different Fixture Approver'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'approval identity must exactly match the appointed accountable owner'
    assert_error validator, 'approval identity does not match the register'
  end

  def test_fully_resolved_batch_c_fixture_passes_integrity_and_has_no_batch_c_g0_errors
    prepare_fully_resolved_batch_c_register

    integrity_validator = fixture_validator
    assert integrity_validator.validate, integrity_validator.errors.join("\n")

    g0_validator = fixture_validator(mode: 'g0')
    refute g0_validator.validate
    refute g0_validator.errors.any? { |error| error.start_with?('Batch C decision register') }, g0_validator.errors.join("\n")
  end

  def test_decision_registers_reject_batch_b_to_c_cycle
    mutate_batch_b_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-REG-003' }
      entry['decision'] = approved_consolidation_decision('PAR-CLN-001')
    end
    mutate_batch_c_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-CLN-001' }
      entry['decision'] = approved_consolidation_decision('PAR-REG-003')
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'consolidation cycle detected: PAR-REG-003 -> PAR-CLN-001 -> PAR-REG-003'
  end

  def test_batch_c_register_edge_rejects_cycle_through_matrix_target
    mutate_batch_c_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-CLN-004' }
      entry['decision'] = approved_consolidation_decision('PAR-CLN-001')
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'consolidation cycle detected: PAR-CLN-001 -> PAR-CLN-004 -> PAR-CLN-001'
  end

  def test_batch_d_decision_register_rejects_missing_duplicate_and_unknown_rows
    mutate_batch_d_decision_register do |register|
      register['entries'].reject! { |entry| entry['requirement_id'] == 'PAR-ADM-014' }
      duplicate = Marshal.load(Marshal.dump(register['entries'].first))
      register['entries'] << duplicate
      register['entries'].last['requirement_id'] = 'PAR-CLN-019'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'missing Batch D requirement IDs: PAR-ADM-014'
    assert_error validator, 'unknown Batch D requirement IDs: PAR-CLN-019'
  end

  def test_batch_d_decision_register_rejects_false_complete_state
    mutate_batch_d_decision_register { |register| register['register_status'] = 'complete' }

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'register_status complete requires every entry to be approved or deferred'
    assert_error validator, 'G0 remains open until decision status is approve or defer'
  end

  def test_batch_d_rejects_changed_authority_set_lead_and_capability_kind
    mutate_batch_d_decision_register do |register|
      entry = register['entries'].first
      entry['co_owners'].reverse!
      entry['lead_authority_domain'] = 'product_delivery'
      entry['accountable_owner']['authority_domain'] = 'product_delivery'
      entry['capability_kind'] = 'menu'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'co_owners must exactly match required authorities'
    assert_error validator, 'lead_authority_domain must be laboratory'
    assert_error validator, 'capability_kind must be diagnostic_master'
  end

  def test_batch_d_rejects_missing_scenario_and_free_text_in_place_of_upstream_dependency
    mutate_batch_d_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-CLN-006' }
      entry['synthetic_scenarios'].delete('dependency_outage')
      entry['upstream_requirement_dependencies'] = []
      entry['downstream_impacts'] << 'Batch A, B and C are ready according to prose.'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'synthetic_scenarios must contain exactly normal, denial, correction_or_amendment, dependency_outage'
    assert_error validator, 'upstream_requirement_dependencies must be a non-empty array'
  end

  def test_batch_d_rejects_generic_ready_scenarios_without_frozen_behavior_coverage
    mutate_batch_d_decision_register do |register|
      register['entries'].first['synthetic_scenarios'].each_value do |scenario|
        scenario['status'] = 'ready'
        scenario['description'] = 'x'
        scenario['expected_results'] = ['x']
        scenario['required_behaviors'] = ['x']
      end
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'synthetic scenario normal required_behaviors must exactly match'
    assert_error validator, 'synthetic scenario denial required_behaviors must exactly match'
    assert_error validator, 'synthetic scenario correction_or_amendment required_behaviors must exactly match'
    assert_error validator, 'synthetic scenario dependency_outage required_behaviors must exactly match'
  end

  def test_batch_d_rejects_x_prose_even_when_per_id_behavior_tokens_are_preserved
    mutate_batch_d_decision_register do |register|
      register['entries'].first['synthetic_scenarios'].each_value do |scenario|
        scenario['status'] = 'ready'
        scenario['description'] = 'x'
        scenario['expected_results'] = ['x']
      end
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'ready synthetic scenario normal description must contain at least 40 characters'
    assert_error validator, 'ready synthetic scenario normal requires at least two substantive expected results'
  end

  def test_batch_d_rejects_generic_structured_assertions_even_with_long_prose
    mutate_batch_d_decision_register do |register|
      scenario = register['entries'].first['synthetic_scenarios']['normal']
      scenario['status'] = 'ready'
      scenario['description'] = 'A long but generic fixture description that contains no executable domain semantics.'
      scenario['expected_results'] = ['A long generic expected result that says nothing.', 'Another generic expected result that says nothing.']
      scenario['assertions'] = ['x']
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'synthetic scenario normal assertions must exactly match the frozen per-ID contract'
  end

  def test_batch_d_rejects_omitted_shared_a_b_c_foundation_and_co_owner_appointment
    mutate_batch_d_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-CLN-006' }
      entry['upstream_requirement_dependencies'].reject! { |dependency| %w[A B C].include?(dependency['reference']) }
      entry['appointment_dependencies'].reject! { |dependency| dependency['authority_domain'] == 'laboratory' }
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'upstream dependencies must exactly match the frozen Batch D dependency policy'
    assert_error validator, 'appointment dependencies must exactly cover co_owners; missing ["laboratory"]'
  end

  def test_batch_d_rejects_live_integration_boundary
    mutate_batch_d_decision_register do |register|
      boundary = register['entries'].first['integration_boundary']
      boundary['mode'] = 'live_adapter'
      boundary['outbound_network'] = true
      boundary['live_endpoints'] = true
      boundary['credentials'] = true
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'integration_boundary invalid mode "live_adapter"'
    assert_error validator, 'integration_boundary outbound_network must be false'
    assert_error validator, 'integration_boundary live_endpoints must be false'
    assert_error validator, 'integration_boundary credentials must be false'
  end

  def test_batch_d_diagnostic_approval_rejects_u_only_route_evidence_and_missing_lifecycle_mapping
    mutate_batch_d_decision_register do |register|
      register['register_status'] = 'complete'
      entry = register['entries'].first
      entry['decision'] = {
        'status' => 'approve', 'canonical_disposition' => 'reproduce',
        'target' => { 'kind' => 'capability', 'reference' => 'Synthetic laboratory master', 'exclusions' => [] },
        'rationale' => 'Fixture-only invalid approval.'
      }
      entry['evidence'].first['evidence_class'] = 'U'
      entry['evidence'].first['evidence_basis'] = 'route_or_menu'
      entry['evidence'].first['confidence'] = 'low'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'diagnostic/procedure approval or consolidation requires complete lifecycle and mapping artifacts'
    assert_error validator, 'diagnostic/procedure approval requires O/M/I evidence beyond route or menu observation'
  end

  def test_batch_d_rejects_unapproved_consolidation_and_unresolved_allowed_terminal_target
    mutate_batch_d_decision_register do |register|
      register['entries'].find { |entry| entry['requirement_id'] == 'PAR-CLN-006' }['decision'] = approved_consolidation_decision('PAR-ADM-014')
      register['entries'].find { |entry| entry['requirement_id'] == 'PAR-CLN-010' }['decision'] = approved_consolidation_decision('PAR-CLN-009')
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'consolidation is allowed only for PAR-CLN-010 -> PAR-CLN-009 or PAR-CLN-012 -> PAR-CLN-011'
    assert_error validator, 'PAR-CLN-010: terminal target PAR-CLN-009 must have a resolved non-consolidation decision'
  end

  def test_batch_d_consolidation_cycle_is_detected_even_when_an_edge_is_unsupported
    mutate_batch_d_decision_register do |register|
      register['entries'].find { |entry| entry['requirement_id'] == 'PAR-CLN-009' }['decision'] = approved_consolidation_decision('PAR-CLN-010')
      register['entries'].find { |entry| entry['requirement_id'] == 'PAR-CLN-010' }['decision'] = approved_consolidation_decision('PAR-CLN-009')
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'consolidation cycle detected: PAR-CLN-009 -> PAR-CLN-010 -> PAR-CLN-009'
  end

  def test_batch_d_orp_batch_e_gate_cannot_claim_resolution_or_stock_readiness
    mutate_batch_d_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-ORP-001' }
      gate = entry['upstream_requirement_dependencies'].find { |dependency| dependency['reference'] == 'E' }
      gate['status'] = 'resolved'
      gate['resolution'] = 'Menu label observed.'
      entry['decision'] = {
        'status' => 'defer', 'canonical_disposition' => 'exclude',
        'target' => { 'kind' => 'exclusion', 'reference' => 'Fixture', 'exclusions' => ['No production use.'] },
        'rationale' => 'Fixture-only invalid stock readiness claim.'
      }
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'Batch E stock semantics cannot be marked resolved before Batch E governance is registered'
  end

  def test_batch_d_orp_authority_deferral_requires_pharmacy_and_explicit_semantic_exclusions
    mutate_batch_d_decision_register do |register|
      register['register_status'] = 'complete'
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-ORP-001' }
      entry['upstream_requirement_dependencies'].each do |dependency|
        dependency['status'] = 'deferred'
        dependency['resolution'] = 'Fixture-only dependency deferral.'
        dependency['defer_authority_domain'] = dependency['reference'] == 'E' ? 'warehouse_stock' : 'pharmacy'
      end
      entry['decision'] = {
        'status' => 'defer', 'canonical_disposition' => 'exclude',
        'target' => { 'kind' => 'exclusion', 'reference' => 'Fixture', 'exclusions' => ['No stock readiness.'] },
        'rationale' => 'Fixture-only invalid Batch E deferral.'
      }
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'Batch E stock-semantics deferral requires pharmacy authority'
    assert_error validator, 'Batch E deferral must explicitly exclude stock, issue, return, lot and charge semantics'
  end

  def test_batch_d_orp_accepts_signed_manifest_bound_batch_e_deferral_without_claiming_e_ready
    prepare_resolved_batch_d_entry('PAR-ORP-001')
    mutate_batch_d_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-ORP-001' }
      gate = entry['upstream_requirement_dependencies'].find { |dependency| dependency['reference'] == 'E' }
      gate['status'] = 'deferred'
      gate['resolution'] = 'Batch E stock semantics are explicitly excluded from this fixture-only decision.'
      gate['defer_authority_domain'] = 'pharmacy'
      artifact = {
        'artifact_type' => ParityGovernanceValidator::UPSTREAM_RESOLUTION_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_D_REGISTER_ID,
        'requirement_id' => 'PAR-ORP-001',
        'subject' => 'upstream_requirement_dependency',
        'dependency_kind' => 'batch_gate', 'dependency_reference' => 'E', 'scope' => gate['scope'],
        'status' => 'deferred', 'resolution' => gate['resolution'],
        'identity' => entry.dig('accountable_owner', 'identity'), 'authority_domain' => 'pharmacy',
        'upstream_batch' => 'E', 'upstream_source_id' => 'G0_PARITY_BATCH_MANIFEST.json#batch-E',
        'upstream_source_sha256' => Digest::SHA256.file(@batch_manifest).hexdigest,
        'upstream_decision_status' => nil, 'upstream_approval_sha256' => nil,
        'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }
      reference, digest = write_json_artifact('par_orp_001_batch_e_deferral.json', artifact, batch: 'D')
      gate['resolution_reference'] = reference
      gate['resolution_artifact_sha256'] = digest
    end

    validator = fixture_validator

    assert validator.validate, validator.errors.join("\n")
    g0_validator = fixture_validator(mode: 'g0')
    refute g0_validator.validate
    matching = g0_validator.errors.select { |error| error.start_with?('Batch D decision register PAR-ORP-001') }
    assert_equal 1, matching.length, matching.join("\n")
    assert_includes matching.first, 'requires every structured upstream dependency resolved or authority-deferred'
  end

  def test_batch_d_requirement_dependency_accepts_source_lead_signed_deferral_without_claiming_target_ready
    prepare_resolved_batch_d_entry('PAR-CLN-006')
    mutate_batch_d_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-CLN-006' }
      entry['upstream_requirement_dependencies'].select { |dependency| dependency['dependency_kind'] == 'requirement' }.each_with_index do |dependency, index|
        dependency['status'] = 'deferred'
        dependency['resolution'] = 'Fixture-only source-lead deferral; the referenced master is not claimed ready.'
        dependency['defer_authority_domain'] = 'laboratory'
        artifact = {
          'artifact_type' => ParityGovernanceValidator::UPSTREAM_RESOLUTION_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_D_REGISTER_ID,
          'requirement_id' => 'PAR-CLN-006', 'subject' => 'upstream_requirement_dependency',
          'dependency_kind' => 'requirement', 'dependency_reference' => dependency['reference'],
          'scope' => dependency['scope'], 'status' => 'deferred', 'resolution' => dependency['resolution'],
          'identity' => entry.dig('accountable_owner', 'identity'), 'authority_domain' => 'laboratory',
          'upstream_batch' => 'D', 'upstream_source_id' => entry.dig('approval', 'reference'),
          'upstream_source_sha256' => entry.dig('approval', 'artifact_sha256'),
          'upstream_decision_status' => 'defer', 'upstream_approval_sha256' => entry.dig('approval', 'artifact_sha256'),
          'date' => '2026-08-25', 'reviewer' => valid_reviewer
        }
        reference, digest = write_json_artifact("par_cln_006_requirement_deferral_#{index}.json", artifact, batch: 'D')
        dependency['resolution_reference'] = reference
        dependency['resolution_artifact_sha256'] = digest
      end
    end

    validator = fixture_validator

    assert validator.validate, validator.errors.join("\n")
    g0_validator = fixture_validator(mode: 'g0')
    refute g0_validator.validate
    matching = g0_validator.errors.select { |error| error.start_with?('Batch D decision register PAR-CLN-006') }
    assert_equal 1, matching.length, matching.join("\n")
    assert_includes matching.first, 'requires every structured upstream dependency resolved or authority-deferred'
  end

  def test_batch_d_entry_passes_closed_artifact_and_authority_contract_but_g0_keeps_upstream_open
    prepare_resolved_batch_d_entry

    integrity_validator = fixture_validator
    assert integrity_validator.validate, integrity_validator.errors.join("\n")

    g0_validator = fixture_validator(mode: 'g0')
    refute g0_validator.validate
    matching = g0_validator.errors.select { |error| error.start_with?('Batch D decision register PAR-ADM-014') }
    assert_equal 1, matching.length, matching.join("\n")
    assert_includes matching.first, 'requires every structured upstream dependency resolved or authority-deferred'
  end

  def test_batch_d_evidence_artifact_binds_evidence_basis
    prepare_resolved_batch_d_entry
    mutate_batch_d_decision_register do |register|
      register['entries'].first['evidence'].first['evidence_basis'] = 'user_interview'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'evidence_basis does not match the register'
  end

  def test_batch_d_recorded_approval_binds_owner_identity_and_lead_authority
    prepare_resolved_batch_d_entry
    mutate_batch_d_decision_register do |register|
      approval = register['entries'].first['approval']
      approval['identity'] = 'Different Fixture Approver'
      approval['authority_domain'] = 'product_delivery'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'approval authority_domain must match lead authority laboratory'
    assert_error validator, 'approval identity must exactly match the appointed accountable owner'
    assert_error validator, 'approval identity does not match the register'
    assert_error validator, 'approval authority_domain does not match the register'
  end

  def test_batch_d_lifecycle_and_mapping_reject_unrelated_structured_artifact
    prepare_resolved_batch_d_entry
    mutate_batch_d_decision_register do |register|
      entry = register['entries'].first
      evidence = entry['evidence'].first
      entry['decision'] = {
        'status' => 'approve', 'canonical_disposition' => 'reproduce',
        'target' => { 'kind' => 'capability', 'reference' => 'Synthetic laboratory master', 'exclusions' => [] },
        'rationale' => 'Fixture-only artifact-binding test.'
      }
      entry['lifecycle_mapping'] = {
        'status' => 'complete',
        'lifecycle_artifact_reference' => evidence['artifact_reference'],
        'lifecycle_artifact_sha256' => evidence['artifact_sha256'],
        'mapping_artifact_reference' => evidence['artifact_reference'],
        'mapping_artifact_sha256' => evidence['artifact_sha256'],
        'terminal_target_requirement_id' => 'PAR-ADM-015'
      }
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'lifecycle artifact artifact_type must be g0_batch_d_lifecycle'
    assert_error validator, 'mapping artifact artifact_type must be g0_batch_d_mapping'
    assert_error validator, 'terminal_target_requirement_id must be PAR-ADM-014'
  end

  def test_batch_d_lifecycle_and_mapping_reject_semantically_empty_artifacts
    prepare_resolved_batch_d_entry
    lifecycle_artifact = {
      'artifact_type' => ParityGovernanceValidator::LIFECYCLE_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_D_REGISTER_ID,
      'requirement_id' => 'PAR-ADM-014',
      'lifecycle_states' => ['x'], 'lifecycle_transitions' => ['x'], 'correction_rules' => ['x'],
      'date' => '2026-08-25', 'author_identity' => 'Fixture Lifecycle Author', 'reviewer' => valid_reviewer
    }
    mapping_artifact = {
      'artifact_type' => ParityGovernanceValidator::MAPPING_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_D_REGISTER_ID,
      'requirement_id' => 'PAR-ADM-014', 'target_reference' => 'Synthetic laboratory master',
      'coverage_status' => 'complete', 'mapped_fields' => ['x'], 'mapped_states' => ['x'],
      'unmapped_items' => [], 'exclusions' => [], 'date' => '2026-08-25',
      'author_identity' => 'Fixture Mapping Author', 'reviewer' => valid_reviewer
    }
    lifecycle_reference, lifecycle_digest = write_json_artifact('batch_d_empty_lifecycle.json', lifecycle_artifact, batch: 'D')
    mapping_reference, mapping_digest = write_json_artifact('batch_d_empty_mapping.json', mapping_artifact, batch: 'D')
    mutate_batch_d_decision_register do |register|
      entry = register['entries'].first
      entry['decision'] = {
        'status' => 'approve', 'canonical_disposition' => 'reproduce',
        'target' => { 'kind' => 'capability', 'reference' => 'Synthetic laboratory master', 'exclusions' => [] },
        'rationale' => 'Fixture-only semantic coverage test.'
      }
      entry['lifecycle_mapping'] = {
        'status' => 'complete', 'lifecycle_artifact_reference' => lifecycle_reference,
        'lifecycle_artifact_sha256' => lifecycle_digest, 'mapping_artifact_reference' => mapping_reference,
        'mapping_artifact_sha256' => mapping_digest, 'terminal_target_requirement_id' => 'PAR-ADM-014'
      }
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'lifecycle artifact missing capability lifecycle states'
    assert_error validator, 'lifecycle artifact missing capability lifecycle transitions'
    assert_error validator, 'lifecycle artifact missing correction safeguards'
    assert_error validator, 'mapping artifact missing required mapping fields'
    assert_error validator, 'mapping artifact missing required mapped states'
  end

  def test_batch_e_decision_register_rejects_missing_duplicate_unknown_and_orp_rows
    mutate_batch_e_decision_register do |register|
      register['entries'].first['requirement_id'] = 'PAR-ORP-001'
      register['entries'].last['requirement_id'] = 'PAR-PHA-001'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'missing Batch E requirement IDs: PAR-ADM-017, PAR-PWH-023'
    assert_error validator, 'unknown Batch E requirement IDs: PAR-ORP-001'
    assert_error validator, 'duplicate requirement IDs: PAR-PHA-001'
  end

  def test_shared_registry_fails_closed_when_batch_e_register_is_omitted
    FileUtils.rm(@batch_e_decision_register)

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'Batch E decision register: file not found'
    assert_error validator, 'Batch E decision register: expected exactly 48 entries, got 0'
  end

  def test_batch_e_rejects_changed_family_partition_and_blood_stock_authority
    mutate_batch_e_decision_register do |register|
      register['family_policies'].first['members'].delete('PAR-ADM-017')
      register['source_revision'] = 'wrong-revision'
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-PWH-022' }
      entry['lead_authority_domain'] = 'pharmacy'
      entry['accountable_owner']['authority_domain'] = 'pharmacy'
      entry['co_owners'][1] = 'pharmacy'
      entry['appointment_dependencies'].reject! { |dependency| dependency['authority_domain'] == 'blood_bank' }
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'family_policies[0] must exactly match the frozen family policy'
    assert_error validator, 'source_revision must be 36c309cd734f78716ae8ee146a08c2129beedbd9'
    assert_error validator, 'family_policies must partition the exact 48 Batch E IDs without duplicates'
    assert_error validator, 'PAR-PWH-022: lead_authority_domain must be blood_bank'
    assert_error validator, 'PAR-PWH-022: co_owners must exactly match required authorities'
    assert_error validator, 'appointment dependencies must exactly cover co_owners'
  end

  def test_batch_e_rejects_false_complete_and_false_approval_identity_domain
    mutate_batch_e_decision_register do |register|
      register['register_status'] = 'complete'
      entry = register['entries'].first
      entry['approval']['status'] = 'recorded'
      entry['approval']['identity'] = 'Wrong Product Approver'
      entry['approval']['authority_domain'] = 'product_delivery'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'register_status complete requires every entry to be approved or deferred'
    assert_error validator, 'recorded approval cannot accompany a pending decision'
    assert_error validator, 'approval authority_domain must match lead authority pharmacy_master_data'
    assert_error validator, 'approval identity must exactly match the appointed accountable owner'
  end

  def test_batch_e_rejects_missing_or_interchanged_family_and_row_scenario_contract
    mutate_batch_e_decision_register do |register|
      first = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-PHA-001' }
      other = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-PWH-022' }
      first['synthetic_scenarios'].delete('dependency_outage')
      first['synthetic_scenarios']['normal'] = Marshal.load(Marshal.dump(other['synthetic_scenarios']['normal']))
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'synthetic_scenarios must contain exactly normal, denial, correction_or_amendment, dependency_outage'
    assert_error validator, 'synthetic scenario normal row_hazard_assertions must exactly match the frozen family and row contract'
    assert_error validator, 'synthetic scenario normal assertions must exactly match the frozen family and row contract'
  end

  def test_batch_e_rejects_same_family_scenario_interchange
    mutate_batch_e_decision_register do |register|
      dispensing = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-PHA-001' }
      history = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-PHA-015' }
      dispensing['synthetic_scenarios']['normal'] = Marshal.load(Marshal.dump(history['synthetic_scenarios']['normal']))
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'PAR-PHA-001: synthetic scenario normal actions must exactly match the frozen family and row contract'
    assert_error validator, 'PAR-PHA-001: synthetic scenario normal preconditions must exactly match the frozen family and row contract'
  end

  def test_batch_e_rejects_live_endpoint_credentials_outbound_and_delivery
    mutate_batch_e_decision_register do |register|
      boundary = register['entries'].first['integration_boundary']
      boundary['mode'] = 'live_adapter'
      boundary['endpoint'] = 'https://live.example.invalid'
      boundary['credential_state'] = 'present'
      boundary['outbound_network'] = true
      boundary['delivery_state'] = 'SENT'
      boundary['prohibited_targets'].delete('SATUSEHAT')
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'integration_boundary mode must be synthetic-only'
    assert_error validator, 'integration_boundary endpoint must be null'
    assert_error validator, 'integration_boundary credential_state must be absent'
    assert_error validator, 'integration_boundary outbound_network must be false'
    assert_error validator, 'integration_boundary delivery_state must be NOT_SENT'
    assert_error validator, 'prohibited_targets must exactly name every forbidden live boundary'
  end

  def test_batch_e_rejects_weakened_ledger_movement_and_reconciliation_contracts
    mutate_batch_e_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-PWH-014' }
      entry['ledger_invariants'].delete('no_edit_or_delete_history')
      entry['movement_contract']['idempotency'] = false
      entry['reconciliation_contract']['control_totals'] = ['screen_row_count']
      entry['row_hazards'].delete('append_only_correction_no_mutation')
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'ledger_invariants must exactly match the immutable Batch E ledger policy'
    assert_error validator, 'movement_contract must exactly match family E8'
    assert_error validator, 'control_totals must exactly match family E8'
    assert_error validator, 'Edit Transaksi must remain an append-only correction/reversal candidate'
  end

  def test_batch_e_pwh_014_cannot_approve_reproduced_in_place_edit_semantics
    mutate_batch_e_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-PWH-014' }
      entry['decision'] = {
        'status' => 'approve', 'canonical_disposition' => 'reproduce',
        'target' => { 'kind' => 'capability', 'reference' => 'Edit Transaksi', 'exclusions' => [] },
        'rationale' => 'Fixture attempts to reproduce destructive legacy edit semantics.'
      }
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'approval may only replace Edit Transaksi with append_only_stock_correction_reversal'
    assert_error validator, 'approval must explicitly exclude edit, delete, overwrite, and in-place mutation of history'
  end

  def test_batch_e_rejects_omitted_c_d_f_g_gate_and_invalid_forward_deferral
    mutate_batch_e_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-PWH-022' }
      entry['dependency_gates'].reject! { |gate| gate['batch'] == 'C' }
      gate = entry['dependency_gates'].find { |candidate| candidate['batch'] == 'F' }
      gate['status'] = 'deferred'
      gate['resolution'] = 'Fixture attempts an unsigned forward deferral.'
      gate['defer_authority_domain'] = 'pharmacy'
      gate['resolution_reference'] = nil
      gate['resolution_artifact_sha256'] = nil
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'dependency_gates must exactly match the frozen C/D/F/G policy'
    assert_error validator, 'defer_authority_domain must be finance_claims'
    assert_error validator, 'authority deferral requires explicit decision exclusions'
    assert_error validator, 'resolution must reference an existing signed artifact'
  end

  def test_batch_e_gate_artifact_rejects_mismatched_requirement_batch_and_authority
    artifact = {
      'artifact_type' => ParityGovernanceValidator::BATCH_E_GATE_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_E_REGISTER_ID,
      'requirement_id' => 'PAR-ADM-020', 'subject' => 'dependency_gate',
      'direction' => 'forward', 'batch' => 'G', 'scope' => ParityGovernanceValidator::BATCH_E_GATE_SCOPES.fetch('F'),
      'status' => 'deferred', 'resolution' => 'Fixture-only finance gate deferral.',
      'identity' => 'Fixture Pharmacy Approver', 'authority_domain' => 'pharmacy',
      'upstream_source_id' => 'G0_PARITY_BATCH_MANIFEST.json#batch-F',
      'upstream_source_sha256' => Digest::SHA256.file(@batch_manifest).hexdigest,
      'exclusions' => ParityGovernanceValidator::BATCH_E_GATE_SCOPES.fetch('F'),
      'date' => '2026-08-25', 'reviewer' => valid_reviewer
    }
    reference, digest = write_json_artifact('batch_e_bad_gate.json', artifact, batch: 'E')
    mutate_batch_e_decision_register do |register|
      entry = register['entries'].first
      entry['decision'] = {
        'status' => 'defer', 'canonical_disposition' => 'exclude',
        'target' => {
          'kind' => 'exclusion', 'reference' => 'Fixture-only finance exclusion',
          'exclusions' => ParityGovernanceValidator::BATCH_E_GATE_SCOPES.fetch('F').map { |term| "Exclude #{term}." }
        },
        'rationale' => 'Fixture-only artifact binding test.'
      }
      gate = entry['dependency_gates'].find { |candidate| candidate['batch'] == 'F' }
      gate['status'] = 'deferred'
      gate['resolution'] = artifact['resolution']
      gate['defer_authority_domain'] = 'finance_claims'
      gate['resolution_reference'] = reference
      gate['resolution_artifact_sha256'] = digest
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'resolution requirement_id does not match PAR-ADM-017'
    assert_error validator, 'resolution batch does not match the register'
    assert_error validator, 'resolution authority_domain must be finance_claims'
    assert_error validator, 'forward-gate deferral requires an appointed finance_claims authority'
  end

  def test_batch_e_g0_rejects_structural_capture_and_incomplete_reconciliation
    mutate_batch_e_decision_register do |register|
      entry = register['entries'].first
      entry['decision'] = {
        'status' => 'approve', 'canonical_disposition' => 'reproduce',
        'target' => { 'kind' => 'capability', 'reference' => 'Synthetic pharmacy master', 'exclusions' => [] },
        'rationale' => 'Fixture-only invalid approval from structural evidence.'
      }
      entry['evidence'].first.merge!(
        'evidence_class' => 'O', 'evidence_basis' => 'structural_capture', 'date' => '2026-08-25',
        'source' => 'Fixture screenshot', 'reference' => 'FIX-E-SCREEN', 'interpreter' => 'Fixture Interpreter',
        'confidence' => 'high', 'artifact_reference' => nil, 'artifact_sha256' => nil
      )
    end

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    assert_error validator, 'G0 requires behavioral or reconciled O/M/I evidence; structural capture alone cannot prove behavior'
    assert_error validator, 'G0 requires every C/D/F/G dependency gate resolved or validly authority-deferred'
    assert_error validator, 'approval requires a complete synthetic cross-ledger reconciliation receipt'
  end

  def test_batch_e_reconciliation_artifact_rejects_mismatched_family_and_control_totals
    artifact = {
      'artifact_type' => ParityGovernanceValidator::BATCH_E_RECONCILIATION_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_E_REGISTER_ID,
      'requirement_id' => 'PAR-PWH-022', 'family_id' => 'E1', 'synthetic_only' => true,
      'control_totals' => ['screen_row_count'], 'source_ledger_total' => 1, 'destination_ledger_total' => 1,
      'finance_total' => 1, 'reporting_total' => 1, 'differences' => { 'screen_row_count' => 0 },
      'idempotency_key' => 'FIX-E9-001', 'date' => '2026-08-25',
      'author_identity' => 'Fixture Reconciliation Author', 'reviewer' => valid_reviewer
    }
    reference, digest = write_json_artifact('batch_e_bad_reconciliation.json', artifact, batch: 'E')
    mutate_batch_e_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-PWH-022' }
      entry['reconciliation_contract']['status'] = 'complete'
      entry['reconciliation_contract']['receipt_reference'] = reference
      entry['reconciliation_contract']['receipt_artifact_sha256'] = digest
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'reconciliation artifact family_id must be E9'
    assert_error validator, 'reconciliation artifact control_totals do not match the register'
    assert_error validator, 'differences must be a closed all-zero equation result object'
  end

  def test_batch_e_rejects_consolidation_outside_candidate_and_missing_member_mapping
    mutate_batch_e_decision_register do |register|
      source = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-PHA-001' }
      source['decision'] = approved_consolidation_decision('PAR-PWH-022')
      source['consolidation_mapping']['status'] = 'complete'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'consolidation is allowed only within its frozen multi-member audit candidate'
    assert_error validator, 'consolidation artifact must reference an existing signed artifact'
  end

  def test_batch_e_consolidation_artifact_rejects_semantically_empty_mapping
    members = ParityGovernanceValidator::BATCH_E_CONSOLIDATION_GROUPS.fetch('E-C01')
    artifact = {
      'artifact_type' => ParityGovernanceValidator::BATCH_E_CONSOLIDATION_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_E_REGISTER_ID,
      'candidate_id' => 'E-C01', 'members' => members, 'target_requirement_id' => 'PAR-PHA-002',
      'member_impacts' => members.to_h { |id| [id, ["Retain impact traceability for #{id}."]] },
      'mapped_fields' => ['x'], 'mapped_states' => ['x'], 'exclusions' => ['No production use.'],
      'date' => '2026-08-25', 'author_identity' => 'Fixture Mapping Author', 'reviewer' => valid_reviewer
    }
    reference, digest = write_json_artifact('batch_e_empty_mapping.json', artifact, batch: 'E')
    mutate_batch_e_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-PHA-001' }
      entry['decision'] = approved_consolidation_decision('PAR-PHA-002')
      entry['consolidation_mapping']['status'] = 'complete'
      entry['consolidation_mapping']['artifact_reference'] = reference
      entry['consolidation_mapping']['artifact_sha256'] = digest
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'mapped_fields must exactly match the frozen candidate contract'
    assert_error validator, 'mapped_states must exactly match the frozen candidate contract'
  end

  def test_batch_e_rejects_conflicting_terminal_targets_within_one_candidate
    mutate_batch_e_decision_register do |register|
      register['entries'].find { |entry| entry['requirement_id'] == 'PAR-PHA-001' }['decision'] = approved_consolidation_decision('PAR-PHA-002')
      register['entries'].find { |entry| entry['requirement_id'] == 'PAR-PHA-003' }['decision'] = approved_consolidation_decision('PAR-PHA-004')
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'candidate E-C01 cannot consolidate to conflicting terminal targets'
  end

  def test_batch_e_accepts_one_candidate_wide_target_and_shared_mapping_artifact
    prepare_coherent_batch_e_candidate

    validator = fixture_validator

    assert validator.validate, validator.errors.join("\n")
  end

  def test_fully_resolved_batch_e_entry_passes_all_closed_contracts
    prepare_fully_resolved_batch_c_register
    prepare_fully_resolved_batch_e_entry

    validator = fixture_validator
    assert validator.validate, validator.errors.join("\n")

    g0_validator = fixture_validator(mode: 'g0')
    refute g0_validator.validate
    matching = g0_validator.errors.select { |error| error.start_with?('Batch E decision register PAR-ADM-017') }
    assert_empty matching, matching.join("\n")
  end

  def test_batch_e_resolved_c_gate_rejects_identity_not_bound_to_upstream_owner
    prepare_fully_resolved_batch_c_register
    prepare_fully_resolved_batch_e_entry
    mutate_batch_e_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-ADM-017' }
      gate = entry['dependency_gates'].find { |candidate| candidate['batch'] == 'C' }
      path = File.join(@tmpdir, gate['resolution_reference'])
      artifact = JSON.parse(File.read(path))
      artifact['identity'] = 'Fabricated Clinical Authority'
      File.write(path, JSON.pretty_generate(artifact) + "\n")
      gate['resolution_artifact_sha256'] = Digest::SHA256.file(path).hexdigest
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'resolution identity must match the upstream accountable owner'
  end

  def test_batch_e_resolved_c_gate_rejects_deferred_or_excluded_upstream_decision
    prepare_fully_resolved_batch_c_register
    prepare_fully_resolved_batch_e_entry
    mutate_batch_c_decision_register do |register|
      upstream = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-ADM-004' }
      upstream['decision'] = {
        'status' => 'defer', 'canonical_disposition' => 'exclude',
        'target' => { 'kind' => 'exclusion', 'reference' => 'Fixture exclusion', 'exclusions' => ['No dependent readiness.'] },
        'rationale' => 'Fixture proves that an excluded upstream row cannot resolve a Batch E gate.'
      }
    end
    mutate_batch_e_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-ADM-017' }
      gate = entry['dependency_gates'].find { |candidate| candidate['batch'] == 'C' }
      artifact_path = File.join(@tmpdir, gate['resolution_reference'])
      artifact = JSON.parse(File.read(artifact_path))
      artifact['upstream_source_sha256'] = Digest::SHA256.file(@batch_c_decision_register).hexdigest
      File.write(artifact_path, JSON.pretty_generate(artifact) + "\n")
      gate['resolution_artifact_sha256'] = Digest::SHA256.file(artifact_path).hexdigest
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'must bind an approved, non-excluded upstream requirement led by appointed clinical_governance authority'
  end

  def test_batch_e_reconciliation_rejects_values_that_violate_frozen_equations
    prepare_fully_resolved_batch_c_register
    prepare_fully_resolved_batch_e_entry
    mutate_batch_e_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-ADM-017' }
      path = File.join(@tmpdir, entry.dig('reconciliation_contract', 'receipt_reference'))
      artifact = JSON.parse(File.read(path))
      artifact['control_values'] = { 'active_master_count' => 2, 'version_count' => 1 }
      File.write(path, JSON.pretty_generate(artifact) + "\n")
      entry['reconciliation_contract']['receipt_artifact_sha256'] = Digest::SHA256.file(path).hexdigest
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'control_values do not satisfy the frozen family reconciliation equations'
  end

  def test_batch_f_decision_register_rejects_missing_duplicate_unknown_rows
    mutate_batch_f_decision_register do |register|
      register['entries'].reject! { |entry| entry['requirement_id'] == 'PAR-ADM-011' }
      duplicate = Marshal.load(Marshal.dump(register['entries'].find { |entry| entry['requirement_id'] == 'PAR-ADM-016' }))
      register['entries'] << duplicate
      register['entries'].find { |entry| entry['requirement_id'] == 'PAR-ADM-018' }['requirement_id'] = 'PAR-ORP-001'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'duplicate requirement IDs: PAR-ADM-016'
    assert_error validator, 'missing Batch F requirement IDs: PAR-ADM-011, PAR-ADM-018'
    assert_error validator, 'unknown Batch F requirement IDs: PAR-ORP-001'
  end

  def test_shared_registry_fails_closed_when_batch_f_register_is_omitted
    FileUtils.rm(@batch_f_decision_register)

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'Batch F decision register: file not found:'
    assert_error validator, 'expected exactly 34 entries, got 0'
  end

  def test_batch_f_rejects_family_authority_lifecycle_and_scenario_drift
    mutate_batch_f_decision_register do |register|
      register['family_policies'].first['lead_authority_domain'] = 'product_delivery'
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-ADM-011' }
      entry['co_owners'].delete('security_privacy_data')
      entry['lifecycle_contract']['transition'] = 'generic_update'
      entry['synthetic_scenarios']['normal']['contract_ref'] = 'generic'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'must exactly match frozen family F1'
    assert_error validator, 'co_owners must exactly match required authorities'
    assert_error validator, 'lifecycle_contract must exactly match the substantive per-ID state transition'
    assert_error validator, 'contract_ref must exactly match the frozen per-ID lifecycle and hazard contract'
  end

  def test_batch_f_rejects_false_complete_and_unbound_approval
    mutate_batch_f_decision_register do |register|
      register['register_status'] = 'complete'
      entry = register['entries'].first
      entry['approval'].merge!(
        'status' => 'recorded', 'identity' => 'False Approver', 'authority_domain' => 'product_delivery',
        'scope' => 'False approval.', 'date' => '2026-08-25', 'reference' => 'missing.json',
        'artifact_sha256' => '0' * 64
      )
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'register_status complete requires every entry to be approved or deferred'
    assert_error validator, 'recorded approval cannot accompany a pending decision'
    assert_error validator, 'approval authority_domain must match lead authority finance_master'
    assert_error validator, 'approval identity must exactly match the appointed accountable owner'
  end

  def test_batch_f_rejects_live_boundary_and_projection_writeback
    mutate_batch_f_decision_register do |register|
      boundary = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-BPJS-001' }['integration_boundary']
      boundary['endpoint'] = 'https://example.invalid/vclaim'
      boundary['credential_state'] = 'present'
      boundary['outbound_network'] = true
      boundary['delivery_state'] = 'SENT'
      projection = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-FIN-006' }
      projection['write_contract']['owned_ledgers'] = ['reporting_projection']
      projection['write_contract']['projection_write_access'] = true
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'endpoint must be null'
    assert_error validator, 'credential_state must be absent'
    assert_error validator, 'outbound_network must be false'
    assert_error validator, 'delivery_state must be NOT_SENT'
    assert_error validator, 'projection-only capability cannot own or write any source ledger'
  end

  def test_batch_f_rejects_non_applicable_reconciliation_profile
    mutate_batch_f_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-FIN-011' }
      entry['reconciliation_contract']['profile_id'] = 'bill_version'
      entry['reconciliation_contract']['control_totals'] = ParityGovernanceValidator::BATCH_F_RECONCILIATION_PROFILES.fetch('bill_version').fetch(:control_totals)
      entry['reconciliation_contract']['equations'] = ParityGovernanceValidator::BATCH_F_RECONCILIATION_PROFILES.fetch('bill_version').fetch(:equations)
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'PAR-FIN-011: reconciliation_contract profile_id must be receivable'
    assert_error validator, 'control_totals must exactly match the applicable per-ID totals'
  end

  def test_batch_f_reconciliation_rejects_self_declared_zero_differences
    artifact = {
      'artifact_type' => ParityGovernanceValidator::BATCH_F_RECONCILIATION_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
      'requirement_id' => 'PAR-FIN-012', 'family_id' => 'F3', 'profile_id' => 'settlement',
      'synthetic_only' => true, 'currency' => 'IDR', 'minor_unit' => 1,
      'period_start' => '2026-08-01', 'period_end' => '2026-08-25', 'period_timezone' => 'Asia/Jakarta',
      'cutoff_at' => '2026-08-25T23:59:59+07:00',
      'late_posting_policy' => 'append_to_open_period_with_prior_period_reference', 'event_count' => 1,
      'ledger_receipts' => [],
      'control_values' => { 'receipt_total' => 100, 'refund_total' => 0, 'reversed_receipt_total' => 0, 'net_settlement_total' => 50, 'accepted_deposit_total' => 50 },
      'equations' => ParityGovernanceValidator::BATCH_F_RECONCILIATION_PROFILES.fetch('settlement').fetch(:equations),
      'differences' => { 'settlement_difference' => 0, 'deposit_difference' => 0 },
      'idempotency_key' => 'FIX-FIN-012-01', 'date' => '2026-08-25', 'author_identity' => 'Fixture Finance Author', 'reviewer' => valid_reviewer
    }
    reference, digest = write_json_artifact('fin_012_bad_reconciliation.json', artifact, batch: 'F')
    mutate_batch_f_decision_register do |register|
      control = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-FIN-012' }['reconciliation_contract']
      control['status'] = 'complete'
      control['receipt_reference'] = reference
      control['receipt_artifact_sha256'] = digest
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'differences must exactly bind every frozen equation and all equal zero'
    assert_error validator, 'ledger_receipts must contain exactly the applicable settlement ledgers'
  end

  def test_batch_f_gate_resolution_rejects_incomplete_register_and_missing_source_bindings
    artifact = {
      'artifact_type' => ParityGovernanceValidator::BATCH_F_GATE_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
      'requirement_id' => 'PAR-ADM-011', 'subject' => 'dependency_gate', 'direction' => 'upstream', 'batch' => 'A',
      'scope' => ParityGovernanceValidator::BATCH_F_GATE_SCOPES.fetch('A'), 'status' => 'resolved',
      'resolution' => 'Fixture false resolution.', 'identity' => 'Fixture Gate Author', 'authority_domain' => 'product_delivery',
      'source_register_id' => ParityGovernanceValidator::BATCH_A_REGISTER_ID,
      'source_register_sha256' => Digest::SHA256.file(@decision_register).hexdigest,
      'source_bindings' => [], 'deferral_bindings' => [], 'exclusions' => [],
      'date' => '2026-08-25', 'reviewer' => valid_reviewer
    }
    reference, digest = write_json_artifact('adm_011_bad_gate.json', artifact, batch: 'F')
    mutate_batch_f_decision_register do |register|
      gate = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-ADM-011' }['dependency_gates'].first
      gate['status'] = 'resolved'
      gate['resolution'] = artifact['resolution']
      gate['resolution_reference'] = reference
      gate['resolution_artifact_sha256'] = digest
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'cannot resolve while Batch A register_status is not complete'
    assert_error validator, 'source_bindings must exactly follow the frozen dependency ID order'
  end

  def test_batch_f_rejects_intra_dependency_and_consolidation_cycle
    mutate_batch_f_decision_register do |register|
      adm = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-ADM-011' }
      adm['intra_batch_dependencies'] = [{
        'requirement_id' => 'PAR-FIN-001',
        'scope' => ['outpatient_bill_version_issued', 'tariff_version_draft'],
        'status' => 'pending', 'resolution_reference' => nil, 'resolution_artifact_sha256' => nil
      }]
      fin_one = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-FIN-001' }
      fin_two = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-FIN-002' }
      fin_one['decision'] = approved_consolidation_decision('PAR-FIN-002')
      fin_two['decision'] = approved_consolidation_decision('PAR-FIN-001')
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'dependency/consolidation cycle detected:'
    assert_error validator, 'consolidation cycle detected:'
  end

  def test_batch_f_consolidation_totals_are_candidate_applicability_union
    expected = ParityGovernanceValidator::BATCH_F_RECONCILIATION_PROFILES.fetch('settlement').fetch(:control_totals)
    actual = ParityGovernanceValidator::BATCH_F_CONSOLIDATION_MAPPING_CONTRACTS.fetch('F-C06').fetch(:control_totals)

    assert_equal expected, actual
    refute_includes actual, 'submitted_claim_total'
    refute_includes actual, 'journal_debit_total'
  end

  def test_batch_f_g0_remains_open_on_pending_proposal
    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    assert_error validator, 'Batch F decision register PAR-ADM-011: G0 remains open until decision status is approve or defer'
    assert_error validator, 'Batch F decision register PAR-RMIK-005: G0 remains open until decision status is approve or defer'
  end

  def test_batch_f_dependency_outage_contract_closes_idempotency_and_ambiguous_ack_paths
    register = JSON.parse(File.read(@batch_f_decision_register))

    register['entries'].each do |entry|
      results = entry.dig('synthetic_scenarios', 'dependency_outage', 'expected_results')
      assert_includes results, 'The same idempotency key with the same payload returns the same synthetic outcome without a second event.'
      assert_includes results, 'The same idempotency key with a different payload is rejected as a conflict before any mutation.'
      assert_includes results, 'An ambiguous acknowledgement is quarantined until reconciliation proves the prior attempt outcome.'
    end
  end

  def test_batch_f_rejects_unbound_or_under_scoped_batch_e_deferral
    artifact = {
      'artifact_type' => ParityGovernanceValidator::BATCH_F_GATE_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
      'requirement_id' => 'PAR-FIN-001', 'subject' => 'dependency_gate', 'direction' => 'upstream', 'batch' => 'E',
      'scope' => ParityGovernanceValidator::BATCH_F_GATE_SCOPES.fetch('E'), 'status' => 'deferred',
      'resolution' => 'Unsafe fixture deferral without pharmacy and finance authority.',
      'identity' => 'Fixture Product Author', 'authority_domain' => 'product_delivery',
      'source_register_id' => ParityGovernanceValidator::BATCH_E_REGISTER_ID,
      'source_register_sha256' => Digest::SHA256.file(@batch_e_decision_register).hexdigest,
      'source_bindings' => [], 'deferral_bindings' => [], 'exclusions' => ['no_live_delivery'],
      'date' => '2026-08-25', 'reviewer' => valid_reviewer
    }
    reference, digest = write_json_artifact('fin_001_bad_e_deferral.json', artifact, batch: 'F')
    mutate_batch_f_decision_register do |register|
      gate = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-FIN-001' }['dependency_gates'].find { |candidate| candidate['batch'] == 'E' }
      gate.merge!(
        'status' => 'deferred', 'resolution' => artifact['resolution'],
        'defer_authority_domain' => 'pharmacy_gf_and_finance_accounting',
        'resolution_reference' => reference, 'resolution_artifact_sha256' => digest
      )
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'deferral_bindings must exactly follow appointed authorities ["pharmacy_gf", "finance_accounting"]'
    assert_error validator, 'deferred E gate identity/domain must bind pharmacy_gf first approval'
    assert_error validator, 'deferred E exclusions must exactly exclude medication charges, stock valuation, pharmacy claim completeness, and live delivery'
    assert_error validator, 'requires matching decision target exclusions for medication, stock valuation, charge-credit, and claim completeness'
  end

  def test_batch_f_accepts_authority_bound_scoped_batch_e_deferral_in_integrity_mode
    prepare_batch_f_scoped_e_deferral('PAR-FIN-001')

    validator = fixture_validator

    assert validator.validate, validator.errors.join("\n")
  end

  def test_batch_f_scoped_batch_e_deferral_requires_each_signed_domain_approval
    prepare_batch_f_scoped_e_deferral('PAR-FIN-001')
    register = JSON.parse(File.read(@batch_f_decision_register))
    gate = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-FIN-001' }['dependency_gates'].find { |candidate| candidate['batch'] == 'E' }
    path = File.join(@tmpdir, gate['resolution_reference'])
    artifact = JSON.parse(File.read(path))
    artifact['deferral_bindings'].last.delete('approval_reference')
    File.write(path, JSON.pretty_generate(artifact) + "\n")
    gate['resolution_artifact_sha256'] = Digest::SHA256.file(path).hexdigest
    File.write(@batch_f_decision_register, JSON.pretty_generate(register) + "\n")

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'deferral_bindings[1] missing fields: approval_reference'
  end

  def test_batch_f_projection_or_monitor_cannot_approve_while_batch_g_is_deferred
    prepare_batch_f_approved_entry('PAR-FIN-004', disposition: 'reproduce', target: 'fixture_cashier_projection')
    mutate_batch_f_decision_register do |register|
      gate = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-FIN-004' }['dependency_gates'].find { |candidate| candidate['batch'] == 'G' }
      gate.merge!(
        'status' => 'deferred', 'resolution' => 'Unsafe projection approval while G is excluded.',
        'defer_authority_domain' => 'reporting', 'resolution_reference' => 'missing.json',
        'resolution_artifact_sha256' => '0' * 64
      )
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'projection/monitor capability must remain defer/exclude with the exact G no-readiness exclusions while Batch G is deferred'
  end

  def test_batch_f_fully_resolved_entry_passes_every_closed_g0_contract
    prepare_fully_resolved_batch_f_entry('PAR-ADM-016')

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    resolved_entry_errors = validator.errors.select { |error| error.include?('Batch F decision register PAR-ADM-016') }
    assert_empty resolved_entry_errors, resolved_entry_errors.join("\n")
    assert_error validator, 'Batch F decision register PAR-ADM-011: G0 remains open until decision status is approve or defer'
  end

  def test_batch_f_complex_e_intra_and_multiledger_entry_passes_closed_g0_contract
    prepare_complex_batch_f_g0_entry

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    complex_entry_errors = validator.errors.select { |error| error.include?('Batch F decision register PAR-FIN-001') }
    assert_empty complex_entry_errors, complex_entry_errors.join("\n")
  end

  def test_batch_f_accepts_closed_intra_dependency_artifact_in_integrity_mode
    prepare_batch_f_approved_entry('PAR-ADM-018', disposition: 'reproduce', target: 'fixture_component_group_capability')
    target = JSON.parse(File.read(@batch_f_decision_register))['entries'].find { |entry| entry['requirement_id'] == 'PAR-ADM-018' }
    dependency_artifact = {
      'artifact_type' => ParityGovernanceValidator::BATCH_F_INTRA_DEPENDENCY_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
      'source_requirement_id' => 'PAR-ADM-019', 'target_requirement_id' => 'PAR-ADM-018',
      'scope' => ParityGovernanceValidator::BATCH_F_INTRA_BATCH_DEPENDENCIES.fetch('PAR-ADM-019').map { |id| id == 'PAR-ADM-018' ? ['component_group_effective', 'cost_component_draft'] : nil }.compact.first,
      'status' => 'resolved', 'identity' => target.dig('accountable_owner', 'identity'),
      'authority_domain' => target['lead_authority_domain'], 'target_decision_status' => 'approve',
      'target_disposition' => 'reproduce', 'target_approval_reference' => target.dig('approval', 'reference'),
      'target_approval_sha256' => target.dig('approval', 'artifact_sha256'),
      'date' => '2026-08-25', 'reviewer' => valid_reviewer
    }
    reference, digest = write_json_artifact('adm_019_valid_intra_dependency.json', dependency_artifact, batch: 'F')
    mutate_batch_f_decision_register do |register|
      dependency = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-ADM-019' }['intra_batch_dependencies'].first
      dependency.merge!('status' => 'resolved', 'resolution_reference' => reference, 'resolution_artifact_sha256' => digest)
    end

    validator = fixture_validator

    assert validator.validate, validator.errors.join("\n")
  end

  def test_batch_f_accepts_one_shared_candidate_mapping_in_integrity_mode
    prepare_batch_f_coherent_consolidation_candidate_f_c01

    validator = fixture_validator

    assert validator.validate, validator.errors.join("\n")
  end

  def test_batch_f_consolidation_rejects_deferred_or_excluded_terminal_target
    prepare_batch_f_approved_entry('PAR-FIN-001', disposition: 'consolidate', target: 'PAR-FIN-002')
    prepare_batch_f_approved_entry(
      'PAR-FIN-002', status: 'defer', disposition: 'exclude',
      target: 'Excluded billing surface', exclusions: ['Fixture target is excluded.']
    )

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'terminal target PAR-FIN-002 must have an approved reproduce/replace decision'
  end

  def test_batch_g_decision_register_rejects_missing_duplicate_and_unknown_rows
    mutate_batch_g_decision_register do |register|
      register['entries'].reject! { |entry| entry['requirement_id'] == 'PAR-ADM-007' }
      register['entries'] << Marshal.load(Marshal.dump(register['entries'].find { |entry| entry['requirement_id'] == 'PAR-RPT-001' }))
      register['entries'].find { |entry| entry['requirement_id'] == 'PAR-RPT-002' }['requirement_id'] = 'PAR-RPT-118'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'duplicate requirement IDs: PAR-RPT-001'
    assert_error validator, 'missing Batch G requirement IDs: PAR-ADM-007, PAR-RPT-002'
    assert_error validator, 'unknown Batch G requirement IDs: PAR-RPT-118'
  end

  def test_shared_registry_fails_closed_when_batch_g_register_is_omitted
    FileUtils.rm(@batch_g_decision_register)

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'Batch G decision register: file not found:'
    assert_error validator, 'expected exactly 120 entries, got 0'
  end

  def test_batch_g_rejects_family_kind_authority_source_and_scenario_drift
    mutate_batch_g_decision_register do |register|
      register['family_policies'].first['lead_authority_domain'] = 'product_delivery'
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-RPT-012' }
      entry['family_id'] = 'G1'
      entry['capability_kind'] = 'operational_projection'
      entry['co_owners'].delete('laboratory')
      entry['source_dependencies'].delete_at(0)
      entry['synthetic_scenarios']['normal']['contract_ref'] = 'generic'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'must exactly match the frozen G1 authority and membership policy'
    assert_error validator, 'PAR-RPT-012: family_id must be G2'
    assert_error validator, 'PAR-RPT-012: capability_kind must be read_only_laboratory_register_projection'
    assert_error validator, 'co_owners must exactly match required authorities'
    assert_error validator, 'source_dependencies must exactly bind the applicability-specific approved A-F source lineage'
    assert_error validator, 'contract_ref must exactly match the per-ID definition, access, reconciliation and failure contract'
  end

  def test_batch_g_freezes_exact_semantics_for_all_families_and_three_master_kinds
    register = JSON.parse(File.read(@batch_g_decision_register))
    by_id = register['entries'].to_h { |entry| [entry['requirement_id'], entry] }

    assert_equal 'not_applicable', by_id['PAR-ADM-007'].dig('reconciliation_contract', 'status')
    assert_equal 'target_definition', by_id['PAR-ADM-007'].dig('definition_contract', 'dimension')
    assert_equal 'infectious_disease_reference', by_id['PAR-ADM-031'].dig('definition_contract', 'dimension')
    assert_equal 'unresolved_owner_definition', by_id['PAR-ADM-035'].dig('definition_contract', 'semantic_status')
    assert_equal ['PAR-CLN-006'], by_id['PAR-RPT-012']['source_dependencies'].select { |source| source['batch'] == 'D' }.map { |source| source['requirement_id'] }
    assert_equal ['PAR-CLN-007'], by_id['PAR-RPT-014']['source_dependencies'].select { |source| source['batch'] == 'D' }.map { |source| source['requirement_id'] }
    assert_equal ['PAR-CLN-009'], by_id['PAR-RPT-018']['source_dependencies'].select { |source| source['batch'] == 'D' }.map { |source| source['requirement_id'] }
    assert_equal 'coded_cancer_case_registry', by_id['PAR-RPT-015'].dig('definition_contract', 'capability_focus')
    assert_equal 'coded_cancer_case', by_id['PAR-RPT-015'].dig('definition_contract', 'dimension')
    assert_equal({ 'cancer_cohort' => 'approved_coded_cancer_case', 'cancer_code_set_version' => 'authority_approved_required' }, by_id['PAR-RPT-015'].dig('definition_contract', 'fixed_parameter_values'))
    assert_equal %w[B C], by_id['PAR-RPT-015']['source_dependencies'].map { |source| source['batch'] }.uniq.select { |batch| %w[B C].include?(batch) }
    assert_equal 'one_row_per_inpatient_transfer_event_version', by_id['PAR-RPT-024'].dig('definition_contract', 'grain')
    assert_includes by_id['PAR-RPT-024'].dig('definition_contract', 'distinct_key'), 'source_ward_id+target_ward_id+transfer_state'
    assert_equal 'source_ward+target_ward+transfer_state', by_id['PAR-RPT-024'].dig('definition_contract', 'fixed_parameter_values', 'required_transfer_fields')
    assert_equal 'one_row_per_active_admission_ward_room_assignment_as_of', by_id['PAR-RPT-107'].dig('definition_contract', 'grain')
    assert_equal 'ward_id+room_id', by_id['PAR-RPT-107'].dig('definition_contract', 'fixed_parameter_values', 'required_dimensions')
    assert_equal 'one_row_per_askes_death_episode_and_disposition_version', by_id['PAR-RPT-041'].dig('definition_contract', 'grain')
    assert_equal 'ASKES', by_id['PAR-RPT-041'].dig('definition_contract', 'fixed_parameter_values', 'payer_class')
    assert by_id['PAR-RPT-041']['source_dependencies'].any? { |source| source['source_entity'] == 'payer_class_version' }
    assert_equal 'unresolved_owner_definition', by_id['PAR-RPT-115'].dig('definition_contract', 'semantic_status')
    assert_equal 'duplicate_mortality_route_scope_unresolved', by_id['PAR-RPT-115'].dig('definition_contract', 'dimension')
    assert_empty by_id['PAR-RPT-115']['source_dependencies'].select { |source| source['batch'] == 'F' }
    assert_equal 'active_admission_bed_as_of', by_id['PAR-RPT-116'].dig('definition_contract', 'dimension')
    assert_empty by_id['PAR-RPT-116']['source_dependencies'].select { |source| source['batch'] == 'F' }
    assert_empty by_id['PAR-RPT-045']['source_dependencies'].select { |source| source['batch'] == 'F' }
    assert by_id['PAR-RPT-045']['source_dependencies'].any? { |source| source['source_entity'] == 'payer_class_version' }
    assert_equal 'record_completeness_checklist_item', by_id['PAR-RPT-022'].dig('definition_contract', 'dimension')
    assert_equal 'record_completeness_checklist_item', by_id['PAR-RPT-030'].dig('definition_contract', 'dimension')
    assert_equal 'record_completeness_summary', by_id['PAR-RPT-031'].dig('definition_contract', 'dimension')
    assert_equal 'record_completeness_summary', by_id['PAR-RPT-032'].dig('definition_contract', 'dimension')
    assert_equal 'coded_ispa_case', by_id['PAR-RPT-054'].dig('definition_contract', 'dimension')
    assert_empty by_id['PAR-RPT-054']['source_dependencies'].select { |source| source['batch'] == 'D' }
    assert_equal 'provider_activity', by_id['PAR-RPT-057'].dig('definition_contract', 'dimension')
    assert_equal 'outpatient', by_id['PAR-RPT-057'].dig('definition_contract', 'care_setting')
    assert_equal 'provider_activity', by_id['PAR-RPT-060'].dig('definition_contract', 'dimension')
    assert_equal 'inpatient', by_id['PAR-RPT-060'].dig('definition_contract', 'care_setting')
    assert_equal 'outgoing_referral_disposition', by_id['PAR-RPT-102'].dig('definition_contract', 'dimension')
    assert_equal 'against_medical_advice_discharge_by_provider', by_id['PAR-RPT-111'].dig('definition_contract', 'dimension')
    assert_equal 'discharge_indication_by_room_class', by_id['PAR-RPT-112'].dig('definition_contract', 'dimension')
    assert_equal 'discharge_indication_by_room', by_id['PAR-RPT-113'].dig('definition_contract', 'dimension')
    assert_equal 'against_medical_advice_discharge_reason', by_id['PAR-RPT-114'].dig('definition_contract', 'dimension')
    semantic_digests = register['entries'].map { |entry| entry.dig('definition_contract', 'semantic_digest') }
    assert_equal 120, semantic_digests.uniq.length

    drift_ids = %w[PAR-ADM-007 PAR-ADM-031 PAR-ADM-035 PAR-RPT-005 PAR-RPT-012 PAR-RPT-062 PAR-RPT-115]
    mutate_batch_g_decision_register do |decision_register|
      drift_ids.each do |requirement_id|
        entry = decision_register['entries'].find { |candidate| candidate['requirement_id'] == requirement_id }
        entry['definition_contract']['semantic_focus'] = 'generic family report'
      end
    end
    validator = fixture_validator

    refute validator.validate
    drift_ids.each do |requirement_id|
      assert validator.errors.any? { |error| error.include?(requirement_id) && error.include?('definition_contract must exactly match the frozen per-ID report/master semantics') }, requirement_id
    end
  end

  def test_batch_g_exact_semantic_mapping_has_no_generic_fallback_and_rejects_representative_drift
    expected_ids = ParityGovernanceValidator.const_get(:EXPECTED_BATCH_G_IDS)
    mapping = ParityGovernanceValidator::BATCH_G_ROW_SEMANTIC_MAPPING
    specs = ParityGovernanceValidator::BATCH_G_ROW_SEMANTIC_SPECS

    assert_equal expected_ids, mapping.keys
    assert_equal expected_ids, specs.keys
    specs.each do |requirement_id, spec|
      refute_match(/generic|placeholder|unspecified/i, spec.fetch(:capability_focus), requirement_id)
      assert ParityGovernanceValidator::BATCH_G_SEMANTIC_TEMPLATES.key?(spec.fetch(:semantic_type)), requirement_id
      assert_equal spec.fetch(:fixed_parameter_values).keys, spec.fetch(:fixed_parameter_values).keys & spec.fetch(:parameters), requirement_id
      assert_equal spec.fetch(:sources).length, spec.fetch(:source_roles).length, requirement_id
    end

    mutate_batch_g_decision_register do |register|
      by_id = register['entries'].to_h { |entry| [entry['requirement_id'], entry] }
      by_id['PAR-RPT-015']['definition_contract']['numerator'] = 'generic_cancer_count'
      by_id['PAR-RPT-024']['definition_contract']['grain'] = 'one_row_per_encounter'
      by_id['PAR-RPT-107']['definition_contract']['fixed_parameter_values']['required_dimensions'] = 'ward_only'
      by_id['PAR-RPT-041']['definition_contract']['source_roles'].reject! { |role| role['source_key'] == 'B_PAYER' }
    end

    validator = fixture_validator

    refute validator.validate
    %w[PAR-RPT-015 PAR-RPT-024 PAR-RPT-107 PAR-RPT-041].each do |requirement_id|
      assert validator.errors.any? { |error| error.include?(requirement_id) && error.include?('definition_contract must exactly match the frozen per-ID report/master semantics') }, requirement_id
    end
  end

  def test_batch_g_rejects_unavailable_source_reported_as_zero_and_complete
    prepare_fully_resolved_batch_g_entry('PAR-RPT-005')
    register = JSON.parse(File.read(@batch_g_decision_register))
    entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-RPT-005' }
    reconciliation_path = File.join(@tmpdir, entry.dig('reconciliation_contract', 'receipt_reference'))
    reconciliation = JSON.parse(File.read(reconciliation_path))
    descriptor = reconciliation['source_exports'].find { |candidate| candidate['control_role'] == 'authoritative_measure' }
    source_path = File.join(@tmpdir, descriptor['reference'])
    source = JSON.parse(File.read(source_path))
    source['rows'].first.merge!('value' => 0, 'state' => 'unavailable')
    source['source_root_sha256'] = Digest::SHA256.hexdigest(JSON.generate(source['rows']))
    File.write(source_path, JSON.pretty_generate(source) + "\n")
    descriptor['sha256'] = Digest::SHA256.file(source_path).hexdigest
    File.write(reconciliation_path, JSON.pretty_generate(reconciliation) + "\n")
    entry['reconciliation_contract']['receipt_artifact_sha256'] = Digest::SHA256.file(reconciliation_path).hexdigest
    File.write(@batch_g_decision_register, JSON.pretty_generate(register) + "\n")
    refresh_batch_g_control_approval('PAR-RPT-005')

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    assert_error validator, 'totals/counts must be independently recomputed from canonical source and output rows'
    assert_error validator, 'unavailable/unknown/not-collected/not-applicable/suppressed rows force partial or blocked non-exportable output'
  end

  def test_batch_g_rejects_suppressed_output_reported_as_complete_and_exportable
    prepare_fully_resolved_batch_g_entry('PAR-RPT-005')
    register = JSON.parse(File.read(@batch_g_decision_register))
    entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-RPT-005' }
    reconciliation_path = File.join(@tmpdir, entry.dig('reconciliation_contract', 'receipt_reference'))
    reconciliation = JSON.parse(File.read(reconciliation_path))
    output_path = File.join(@tmpdir, reconciliation.dig('report_output', 'reference'))
    output = JSON.parse(File.read(output_path))
    output['rows'].first.merge!('value' => 0, 'state' => 'suppressed')
    output['output_sha256'] = Digest::SHA256.hexdigest(JSON.generate(output['rows']))
    File.write(output_path, JSON.pretty_generate(output) + "\n")
    reconciliation['report_output']['sha256'] = Digest::SHA256.file(output_path).hexdigest
    File.write(reconciliation_path, JSON.pretty_generate(reconciliation) + "\n")
    entry['reconciliation_contract']['receipt_artifact_sha256'] = Digest::SHA256.file(reconciliation_path).hexdigest
    File.write(@batch_g_decision_register, JSON.pretty_generate(register) + "\n")
    refresh_batch_g_control_approval('PAR-RPT-005')

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    assert_error validator, 'unavailable/unknown/not-collected/not-applicable/suppressed rows force partial or blocked non-exportable output'
  end

  def test_batch_g_rejects_unbound_parameter_rerun_access_and_audit_receipts
    prepare_fully_resolved_batch_g_entry('PAR-RPT-005')
    register = JSON.parse(File.read(@batch_g_decision_register))
    entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-RPT-005' }
    reconciliation_path = File.join(@tmpdir, entry.dig('reconciliation_contract', 'receipt_reference'))
    reconciliation = JSON.parse(File.read(reconciliation_path))

    parameter_path = File.join(@tmpdir, reconciliation.dig('parameter_descriptor', 'reference'))
    parameter = JSON.parse(File.read(parameter_path))
    parameter['parameter_values'][parameter['parameter_values'].keys.first] = 'format-valid-but-unbound'
    File.write(parameter_path, JSON.pretty_generate(parameter) + "\n")
    reconciliation['parameter_descriptor']['sha256'] = Digest::SHA256.file(parameter_path).hexdigest

    rerun_path = File.join(@tmpdir, reconciliation.dig('rerun_receipt', 'reference'))
    rerun = JSON.parse(File.read(rerun_path))
    rerun['source_roots'] = []
    File.write(rerun_path, JSON.pretty_generate(rerun) + "\n")
    reconciliation['rerun_receipt']['sha256'] = Digest::SHA256.file(rerun_path).hexdigest

    access_path = File.join(@tmpdir, reconciliation.dig('access_receipt', 'reference'))
    access = JSON.parse(File.read(access_path))
    access['identity'] = 'Fixture Unappointed Exporter'
    File.write(access_path, JSON.pretty_generate(access) + "\n")
    reconciliation['access_receipt']['sha256'] = Digest::SHA256.file(access_path).hexdigest

    audit_path = File.join(@tmpdir, reconciliation.dig('audit_receipt', 'reference'))
    audit = JSON.parse(File.read(audit_path))
    audit['events'].first['event_id'] = 'ARBITRARY-UNROOTED-AUDIT-ID'
    audit['audit_root_sha256'] = Digest::SHA256.hexdigest(JSON.generate(audit['events']))
    File.write(audit_path, JSON.pretty_generate(audit) + "\n")
    reconciliation['audit_receipt']['sha256'] = Digest::SHA256.file(audit_path).hexdigest

    File.write(reconciliation_path, JSON.pretty_generate(reconciliation) + "\n")
    entry['reconciliation_contract']['receipt_artifact_sha256'] = Digest::SHA256.file(reconciliation_path).hexdigest
    File.write(@batch_g_decision_register, JSON.pretty_generate(register) + "\n")
    refresh_batch_g_control_approval('PAR-RPT-005')

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    assert_error validator, 'canonical_parameter_sha256 must be recomputable from the exact parameter payload'
    assert_error validator, 'must bind the same definition, parameter payload, snapshot, source roots and cutoff'
    assert_error validator, 'identity must bind the appointed security/privacy/export authority'
    assert_error validator, 'event IDs must exactly match the output receipt'
  end

  def test_batch_g_rejects_rehashed_parameter_payload_that_changes_a_frozen_semantic_dimension
    prepare_fully_resolved_batch_g_entry('PAR-RPT-005')
    register = JSON.parse(File.read(@batch_g_decision_register))
    entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-RPT-005' }
    reconciliation_path = File.join(@tmpdir, entry.dig('reconciliation_contract', 'receipt_reference'))
    reconciliation = JSON.parse(File.read(reconciliation_path))
    parameter_path = File.join(@tmpdir, reconciliation.dig('parameter_descriptor', 'reference'))
    parameter = JSON.parse(File.read(parameter_path))
    parameter['parameter_values']['patient_cohort'] = 'generic_all_patients'
    parameter['canonical_parameter_sha256'] = Digest::SHA256.hexdigest(JSON.generate(parameter['parameter_values']))
    File.write(parameter_path, JSON.pretty_generate(parameter) + "\n")
    reconciliation['parameter_descriptor']['sha256'] = Digest::SHA256.file(parameter_path).hexdigest
    File.write(reconciliation_path, JSON.pretty_generate(reconciliation) + "\n")
    entry['reconciliation_contract']['receipt_artifact_sha256'] = Digest::SHA256.file(reconciliation_path).hexdigest
    File.write(@batch_g_decision_register, JSON.pretty_generate(register) + "\n")
    refresh_batch_g_control_approval('PAR-RPT-005')

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    assert_error validator, 'parameter_values.patient_cohort must bind frozen semantic value registered_synthetic_patients'
  end

  def test_batch_g_rejects_missing_authority_signature_and_mismatched_control_digest
    prepare_fully_resolved_batch_g_entry('PAR-RPT-012')
    register = JSON.parse(File.read(@batch_g_decision_register))
    entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-RPT-012' }
    approval_path = File.join(@tmpdir, entry.dig('approval', 'reference'))
    approval = JSON.parse(File.read(approval_path))
    approval['definition_sha256'] = '0' * 64
    product_binding = approval['authority_bindings'].find { |binding| binding['authority_domain'] == 'product_delivery' }
    source_binding = approval['authority_bindings'].find { |binding| binding['authority_domain'] == 'laboratory' }
    source_binding.merge!(
      'identity' => product_binding['identity'],
      'appointment_reference' => product_binding['appointment_reference'],
      'appointment_sha256' => product_binding['appointment_sha256']
    )
    approval['authority_bindings'].delete(product_binding)
    File.write(approval_path, JSON.pretty_generate(approval) + "\n")
    entry['approval']['artifact_sha256'] = Digest::SHA256.file(approval_path).hexdigest
    File.write(@batch_g_decision_register, JSON.pretty_generate(register) + "\n")

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    assert_error validator, 'definition_sha256 must exactly match the approved control manifest'
    assert_error validator, 'authority_bindings must exactly cover product/business decision authority, the report/formula lead, every source domain and security/privacy/export authority; product cannot substitute for another domain'
    assert_error validator, 'must bind the exact appointed authority identity and appointment SHA'
  end

  def test_batch_g_rejects_false_complete_and_unbound_product_approval
    mutate_batch_g_decision_register do |register|
      register['register_status'] = 'complete'
      entry = register['entries'].first
      entry['approval'].merge!(
        'status' => 'recorded', 'identity' => 'Fixture Product Approver', 'authority_domain' => 'product_delivery',
        'scope' => 'False Batch G approval.', 'date' => '2026-08-25', 'reference' => 'missing.json',
        'artifact_sha256' => '0' * 64
      )
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'register_status complete requires every entry to be approved or deferred'
    assert_error validator, 'recorded approval cannot accompany a pending decision'
    assert_error validator, 'approval authority_domain must match lead authority management_reporting'
    assert_error validator, 'approval identity must exactly match the appointed accountable owner'
  end

  def test_batch_g_rejects_report_writeback_target_as_actual_and_live_transmission
    mutate_batch_g_decision_register do |register|
      report = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-RPT-001' }
      report['definition_contract']['write_semantics'] = 'write_source_facts'
      report['output_boundary'].merge!(
        'endpoint' => 'https://example.invalid/sirs', 'credential_state' => 'present',
        'outbound_network' => true, 'delivery_state' => 'SENT', 'watermark' => nil
      )
      target = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-ADM-007' }
      target['definition_contract']['write_semantics'] = 'overwrite_actual_outcome'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'definition_contract must exactly match the frozen per-ID report/master semantics'
    assert_error validator, 'report projection must never write source facts'
    assert_error validator, 'management target master must never be represented as actual outcome'
    assert_error validator, 'must exactly match the synthetic local NOT_SENT boundary'
    assert_error validator, 'endpoint must be null, credentials absent, outbound false, delivery NOT_SENT'
  end

  def test_batch_g_rejects_join_multiplication_null_to_zero_and_period_overwrite
    mutate_batch_g_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-RPT-026' }
      definition = entry['definition_contract']
      definition['join_contract']['unbounded_many_to_many'] = true
      definition['join_contract']['dedup_rule'] = 'count source lines after fan-out'
      definition['null_policy'] = ['numeric_zero']
      definition['period_close_policy'] = 'overwrite_closed_output'
      definition['correction_restatement_policy'] = 'edit_prior_output'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'definition_contract must exactly match the frozen per-ID report/master semantics'
  end

  def test_batch_g_rejects_stale_statutory_definition_and_compliance_claim
    mutate_batch_g_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-RPT-062' }
      entry['statutory_definition'].merge!(
        'status' => 'complete', 'standard_identifier' => 'RL-LEGACY', 'standard_version' => 'unknown',
        'effective_date' => 'not-a-date', 'definition_source' => 'legacy menu label',
        'authority_reference' => 'missing.json', 'authority_artifact_sha256' => '0' * 64
      )
      entry['output_boundary']['transmission_claim'] = 'CURRENT_COMPLIANT_SUBMITTED_ACCEPTED'
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'must exactly match the synthetic local NOT_SENT boundary'
    assert_error validator, 'statutory_definition authority signed artifact must remain inside the decision-register evidence directory'
  end

  def test_batch_g_rejects_self_attested_unrooted_reconciliation
    artifact = {
      'artifact_type' => ParityGovernanceValidator::BATCH_G_RECONCILIATION_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
      'requirement_id' => 'PAR-RPT-005',
      'definition_sha256' => Digest::SHA256.hexdigest('unrelated-definition'),
      'parameter_sha256' => Digest::SHA256.hexdigest('parameters'),
      'snapshot_id' => 'SYN-G-SNAPSHOT-UNROOTED', 'cutoff_at' => '2026-08-25T23:59:59+07:00',
      'period_state' => 'closed', 'source_exports' => [], 'report_output' => {},
      'control_values' => ParityGovernanceValidator::BATCH_G_CONTROL_TOTALS.to_h { |key| [key, 0] },
      'equation' => ParityGovernanceValidator::BATCH_G_RECONCILIATION_EQUATION,
      'difference' => 0, 'sampled_lineage' => [],
      'deterministic_rerun_output_sha256' => Digest::SHA256.hexdigest('arbitrary-output'),
      'late_event_policy' => 'overwrite', 'author_identity' => 'Self Attesting Author',
      'reviewer' => {
        'identity' => 'Self Attesting Author', 'verification_method' => 'signed_document_review',
        'verification_reference' => 'SELF-REVIEW'
      }
    }
    reference, digest = write_json_artifact('rpt_005_unrooted_reconciliation.json', artifact, batch: 'G')
    mutate_batch_g_decision_register do |register|
      control = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-RPT-005' }['reconciliation_contract']
      control.merge!('status' => 'complete', 'receipt_reference' => reference, 'receipt_artifact_sha256' => digest)
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'definition_sha256 must bind the frozen per-ID definition'
    assert_error validator, 'source_exports must bind every applicability-specific source exactly once'
    assert_error validator, 'sampled lineage must positively trace rooted source keys to rooted report keys'
    assert_error validator, 'reviewer identity must be distinct from the subject'
  end

  def test_batch_g_rejects_dependency_and_consolidation_cycle
    mutate_batch_g_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-RPT-033' }
      entry['decision'] = approved_consolidation_decision('PAR-RPT-034')
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'Batch G decision register dependency/consolidation cycle detected:'
  end

  def test_batch_g_source_resolution_rejects_mismatched_par_and_unapproved_source
    dependency = JSON.parse(File.read(@batch_g_decision_register))['entries']
      .find { |entry| entry['requirement_id'] == 'PAR-RPT-005' }['source_dependencies'].first
    artifact = {
      'artifact_type' => ParityGovernanceValidator::BATCH_G_SOURCE_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
      'requirement_id' => 'PAR-RPT-005', 'subject' => 'source_dependency',
      'source_batch' => dependency['batch'], 'source_requirement_id' => 'PAR-ADM-037',
      'source_entity' => dependency['source_entity'], 'source_owner_authority' => dependency['source_owner_authority'],
      'source_register_id' => ParityGovernanceValidator::BATCH_A_REGISTER_ID,
      'source_register_sha256' => Digest::SHA256.file(@decision_register).hexdigest,
      'source_owner_identity' => 'Fixture Product Substitute',
      'source_approval_reference' => nil, 'source_approval_sha256' => nil,
      'status' => 'resolved', 'date' => '2026-08-25', 'reviewer' => valid_reviewer
    }
    reference, digest = write_json_artifact('rpt_005_mismatched_source.json', artifact, batch: 'G')
    mutate_batch_g_decision_register do |register|
      source = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-RPT-005' }['source_dependencies'].first
      source.merge!('status' => 'resolved', 'resolution_reference' => reference, 'resolution_artifact_sha256' => digest)
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'source_requirement_id does not match the register dependency'
    assert_error validator, 'cannot resolve while Batch A register_status is not complete'
    assert_error validator, 'source requirement must be approved reproduce/replace; defer/exclude cannot support a complete report'
    assert_error validator, 'source_owner_identity must bind the appointed exact source-domain authority; product or another domain cannot substitute'
  end

  def test_batch_g_accepts_one_shared_coherent_candidate_mapping
    prepare_batch_g_coherent_candidate_g_c01

    validator = fixture_validator

    refute validator.validate
    mapping_errors = validator.errors.select do |error|
      error.include?('consolidation_mapping') || error.include?('candidate G-C01') || error.include?('terminal target')
    end
    assert_empty mapping_errors, mapping_errors.join("\n")
  end

  def test_batch_g_rejects_consolidation_without_exact_care_setting_and_member_lineage
    prepare_batch_g_coherent_candidate_g_c01
    register = JSON.parse(File.read(@batch_g_decision_register))
    member = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-RPT-001' }
    artifact_path = File.join(@tmpdir, member.dig('consolidation_mapping', 'artifact_reference'))
    artifact = JSON.parse(File.read(artifact_path))
    artifact['declared_parameters'] = { 'generic_mode' => %w[a b] }
    artifact['member_mappings']['PAR-RPT-002']['source_requirement_ids'] = []
    File.write(artifact_path, JSON.pretty_generate(artifact) + "\n")
    digest = Digest::SHA256.file(artifact_path).hexdigest
    register['entries'].each do |entry|
      next unless %w[PAR-RPT-001 PAR-RPT-002].include?(entry['requirement_id'])

      entry['consolidation_mapping']['artifact_sha256'] = digest
    end
    File.write(@batch_g_decision_register, JSON.pretty_generate(register) + "\n")

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'declared_parameters must enumerate every differing cohort, care-setting, dimension or version'
    assert_error validator, 'member_mappings[PAR-RPT-002] must exactly bind semantic parameters, definition, source lineage, controls, authorities and recorded member approval'
  end

  def test_batch_g_consolidation_rejects_deferred_excluded_terminal_target
    prepare_batch_g_governed_entry(
      'PAR-RPT-001', status: 'approve', disposition: 'consolidate', target_kind: 'consolidation_target',
      target_reference: 'PAR-RPT-002'
    )
    prepare_batch_g_governed_entry(
      'PAR-RPT-002', status: 'defer', disposition: 'exclude', target_kind: 'exclusion',
      target_reference: 'fixture excluded terminal', exclusions: ['fixture excluded terminal']
    )

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'terminal target PAR-RPT-002 must be approved reproduce/replace'
  end

  def test_batch_g_g0_remains_open_on_honest_pending_proposal
    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    assert_error validator, 'Batch G decision register PAR-ADM-007: G0 remains open until decision status is approve or defer'
    assert_error validator, 'Batch G decision register PAR-RPT-117: G0 remains open until decision status is approve or defer'
  end

  def test_batch_g_fully_resolved_operational_report_passes_closed_g0_contract
    prepare_fully_resolved_batch_g_entry('PAR-RPT-005')

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    row_errors = validator.errors.select { |error| error.include?('Batch G decision register PAR-RPT-005') }
    assert_empty row_errors, row_errors.join("\n")
  end

  def test_batch_g_fully_resolved_clinical_multisource_report_passes_closed_g0_contract
    prepare_fully_resolved_batch_g_entry('PAR-RPT-012')

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    row_errors = validator.errors.select { |error| error.include?('Batch G decision register PAR-RPT-012') }
    assert_empty row_errors, row_errors.join("\n")
  end

  def test_batch_g_statutory_legacy_only_defer_path_passes_without_compliance_claim
    prepare_batch_g_deferred_statutory_entry('PAR-RPT-062')

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    row_errors = validator.errors.select { |error| error.include?('Batch G decision register PAR-RPT-062') }
    assert_empty row_errors, row_errors.join("\n")
  end

  def test_batch_g_current_statutory_resolution_requires_and_accepts_independent_domain_signatures
    prepare_fully_resolved_batch_g_entry('PAR-RPT-041')
    complete_batch_g_statutory_definition('PAR-RPT-041')

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    row_errors = validator.errors.select { |error| error.include?('Batch G decision register PAR-RPT-041') }
    assert_empty row_errors, row_errors.join("\n")
  end

  def test_batch_g_rejects_current_statutory_metadata_without_signed_formula_and_sources
    prepare_fully_resolved_batch_g_entry('PAR-RPT-041')
    complete_batch_g_statutory_definition('PAR-RPT-041')
    register = JSON.parse(File.read(@batch_g_decision_register))
    entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-RPT-041' }
    statutory_path = File.join(@tmpdir, entry.dig('statutory_definition', 'authority_reference'))
    statutory = JSON.parse(File.read(statutory_path))
    statutory.delete('report_definition_reference')
    statutory.delete('report_definition_sha256')
    File.write(statutory_path, JSON.pretty_generate(statutory) + "\n")
    entry['statutory_definition']['authority_artifact_sha256'] = Digest::SHA256.file(statutory_path).hexdigest
    File.write(@batch_g_decision_register, JSON.pretty_generate(register) + "\n")
    refresh_batch_g_control_approval('PAR-RPT-041')

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    assert_error validator, 'statutory_definition authority missing fields: report_definition_reference, report_definition_sha256'
    assert_error validator, 'statutory_definition authority report_definition must reference an existing signed artifact'
  end

  def test_batch_g_unresolved_statutory_indicator_cannot_be_approved_from_legacy_label
    prepare_batch_g_governed_entry(
      'PAR-RPT-062', status: 'approve', disposition: 'reproduce', target_kind: 'capability',
      target_reference: 'generic_rl_3_1_projection'
    )

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    assert_error validator, 'unresolved legacy/variant/statutory semantics cannot be approved reproduce/replace until the frozen per-ID semantic spec itself is authority-resolved'
  end

  def test_batch_g_hai_count_contract_keeps_unresolved_denominator_fail_closed
    register = JSON.parse(File.read(@batch_g_decision_register))
    %w[PAR-RPT-046 PAR-RPT-049].each do |requirement_id|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == requirement_id }
      assert_equal 'defined_count_denominator_unresolved', entry.dig('definition_contract', 'semantic_status')
      assert_equal 'unresolved_until_approved_exposure_population_definition', entry.dig('definition_contract', 'denominator')
    end
    prepare_batch_g_governed_entry(
      'PAR-RPT-046', status: 'approve', disposition: 'reproduce', target_kind: 'capability',
      target_reference: 'hai_count_with_unresolved_denominator'
    )

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    assert_error validator, 'defined count with unresolved denominator cannot be approved reproduce/replace until the denominator population and rate semantics are authority-resolved'
  end

  def test_batch_g_management_target_contract_keeps_target_distinct_from_actual
    register = JSON.parse(File.read(@batch_g_decision_register))
    target = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-ADM-007' }

    assert_equal 'effective_dated_target_master', target['capability_kind']
    assert_equal 'append_versioned_target_only_never_actual', target.dig('definition_contract', 'write_semantics')
    assert_includes target.dig('definition_contract', 'distinct_key'), 'effective_period'
  end

  def test_batch_g_fully_resolved_target_master_passes_without_report_reconciliation
    prepare_fully_resolved_batch_g_entry('PAR-ADM-007')

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    row_errors = validator.errors.select { |error| error.include?('Batch G decision register PAR-ADM-007') }
    assert_empty row_errors, row_errors.join("\n")
  end

  def test_batch_g_unresolved_legacy_variant_cannot_be_approved_with_generic_definition
    prepare_batch_g_governed_entry(
      'PAR-RPT-020', status: 'approve', disposition: 'reproduce', target_kind: 'capability',
      target_reference: 'generic_jhp_projection'
    )

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    assert_error validator, 'unresolved legacy/variant/statutory semantics cannot be approved reproduce/replace until the frozen per-ID semantic spec itself is authority-resolved'
  end

  def test_batch_g_accepts_append_only_late_event_restatement_with_prior_output
    prepare_fully_resolved_batch_g_entry('PAR-RPT-005')
    register = JSON.parse(File.read(@batch_g_decision_register))
    entry = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-RPT-005' }
    reconciliation_path = File.join(@tmpdir, entry.dig('reconciliation_contract', 'receipt_reference'))
    reconciliation = JSON.parse(File.read(reconciliation_path))
    current_output_path = File.join(@tmpdir, reconciliation.dig('report_output', 'reference'))
    prior_output = JSON.parse(File.read(current_output_path))
    prior_output['snapshot_id'] = 'SYN-G-SNAPSHOT-PAR-RPT-005-PRIOR'
    prior_output['rows'].first['value'] -= 1
    prior_output['output_sha256'] = Digest::SHA256.hexdigest(JSON.generate(prior_output['rows']))
    prior_reference, prior_digest = write_json_artifact('par_rpt_005_prior_output.json', prior_output, batch: 'G')
    reconciliation.merge!(
      'period_state' => 'restated', 'prior_output_reference' => prior_reference,
      'prior_output_sha256' => prior_digest,
      'restatement_reason' => 'Late synthetic encounter event appended after the prior period output was closed.'
    )
    File.write(reconciliation_path, JSON.pretty_generate(reconciliation) + "\n")
    entry['reconciliation_contract']['receipt_artifact_sha256'] = Digest::SHA256.file(reconciliation_path).hexdigest
    File.write(@batch_g_decision_register, JSON.pretty_generate(register) + "\n")
    refresh_batch_g_control_approval('PAR-RPT-005')

    validator = fixture_validator(mode: 'g0')

    refute validator.validate
    row_errors = validator.errors.select { |error| error.include?('Batch G decision register PAR-RPT-005') }
    assert_empty row_errors, row_errors.join("\n")
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

  def test_batch_manifest_enforces_batch_c_exact_set_even_when_counts_and_coverage_match
    mutate_manifest do |manifest|
      manifest['batches']['C'].delete('PAR-ADM-004')
      manifest['batches']['C'] << 'PAR-ADM-014'
      manifest['batches']['D'].delete('PAR-ADM-014')
      manifest['batches']['D'] << 'PAR-ADM-004'
      manifest['batches']['C'].sort!
      manifest['batches']['D'].sort!
    end

    validator = fixture_validator

    refute validator.validate
    assert_error validator, 'Batch C exact set mismatch'
    assert_error validator, 'missing ["PAR-ADM-004"]'
    assert_error validator, 'unexpected ["PAR-ADM-014"]'
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
      batch_c_decision_register_path: SOURCE_BATCH_C_DECISION_REGISTER,
      batch_d_decision_register_path: SOURCE_BATCH_D_DECISION_REGISTER,
      batch_e_decision_register_path: SOURCE_BATCH_E_DECISION_REGISTER,
      batch_f_decision_register_path: SOURCE_BATCH_F_DECISION_REGISTER,
      batch_g_decision_register_path: SOURCE_BATCH_G_DECISION_REGISTER,
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
      batch_c_decision_register_path: @batch_c_decision_register,
      batch_d_decision_register_path: @batch_d_decision_register,
      batch_e_decision_register_path: @batch_e_decision_register,
      batch_f_decision_register_path: @batch_f_decision_register,
      batch_g_decision_register_path: @batch_g_decision_register,
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

  def mutate_batch_c_decision_register
    register = JSON.parse(File.read(@batch_c_decision_register))
    yield register
    File.write(@batch_c_decision_register, JSON.pretty_generate(register) + "\n")
  end

  def mutate_batch_d_decision_register
    register = JSON.parse(File.read(@batch_d_decision_register))
    yield register
    File.write(@batch_d_decision_register, JSON.pretty_generate(register) + "\n")
  end

  def mutate_batch_e_decision_register
    register = JSON.parse(File.read(@batch_e_decision_register))
    yield register
    File.write(@batch_e_decision_register, JSON.pretty_generate(register) + "\n")
  end

  def mutate_batch_f_decision_register
    register = JSON.parse(File.read(@batch_f_decision_register))
    yield register
    File.write(@batch_f_decision_register, JSON.pretty_generate(register) + "\n")
  end

  def mutate_batch_g_decision_register
    register = JSON.parse(File.read(@batch_g_decision_register))
    yield register
    File.write(@batch_g_decision_register, JSON.pretty_generate(register) + "\n")
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

  def prepare_fully_resolved_batch_c_register
    mutate_batch_c_decision_register do |register|
      register['register_status'] = 'complete'
      register['entries'].each do |entry|
        requirement_id = entry['requirement_id']
        filename_prefix = requirement_id.downcase.tr('-', '_')
        lead_authority = entry['lead_authority_domain']
        owner_identity = "Fixture #{requirement_id} accountable owner"
        owner_scope = entry.dig('accountable_owner', 'required_scope')

        evidence = {
          'evidence_class' => 'O',
          'date' => '2026-08-25',
          'source' => 'Synthetic fixture observation',
          'reference' => "FIX-EVIDENCE-#{requirement_id}",
          'interpreter' => "Fixture #{requirement_id} evidence interpreter",
          'confidence' => 'high'
        }
        evidence_artifact = {
          'artifact_type' => ParityGovernanceValidator::EVIDENCE_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_C_REGISTER_ID,
          'requirement_id' => requirement_id,
          'evidence_class' => evidence['evidence_class'],
          'date' => evidence['date'],
          'source' => evidence['source'],
          'reference' => evidence['reference'],
          'interpreter' => evidence['interpreter'],
          'confidence' => evidence['confidence'],
          'reviewer' => valid_reviewer
        }
        evidence_reference, evidence_digest = write_json_artifact("#{filename_prefix}_evidence.json", evidence_artifact, batch: 'C')
        evidence['note'] = 'Synthetic fixture evidence used only to exercise the validator contract.'
        evidence['artifact_reference'] = evidence_reference
        evidence['artifact_sha256'] = evidence_digest
        entry['evidence'] = [evidence]

        entry['decision'] = {
          'status' => 'defer',
          'canonical_disposition' => 'exclude',
          'target' => {
            'kind' => 'exclusion',
            'reference' => "Fixture-only deferral for #{requirement_id}",
            'exclusions' => ['Fixture excludes production and real-patient operation.']
          },
          'rationale' => 'Synthetic fixture decision used only to prove complete-state validation.'
        }
        entry['synthetic_scenarios'].each_value { |scenario| scenario['status'] = 'ready' }

        owner_artifact = {
          'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_C_REGISTER_ID,
          'requirement_id' => requirement_id,
          'subject' => 'accountable_owner',
          'identity' => owner_identity,
          'authority_domain' => lead_authority,
          'scope' => owner_scope,
          'date' => '2026-08-25',
          'reviewer' => valid_reviewer
        }
        owner_reference, owner_digest = write_json_artifact("#{filename_prefix}_owner.json", owner_artifact, batch: 'C')
        entry['accountable_owner'] = {
          'identity' => owner_identity,
          'authority_domain' => lead_authority,
          'required_scope' => owner_scope,
          'appointed_scope' => owner_scope,
          'appointment_status' => 'appointed',
          'appointment_date' => '2026-08-25',
          'appointment_reference' => owner_reference,
          'artifact_sha256' => owner_digest
        }

        entry['appointment_dependencies'].each_with_index do |dependency, index|
          dependency_identity = "Fixture #{requirement_id} #{dependency['authority_domain']} owner"
          dependency_artifact = {
            'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
            'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
            'register_id' => ParityGovernanceValidator::BATCH_C_REGISTER_ID,
            'requirement_id' => requirement_id,
            'subject' => 'appointment_dependency',
            'identity' => dependency_identity,
            'authority_domain' => dependency['authority_domain'],
            'scope' => dependency['required_scope'],
            'date' => '2026-08-25',
            'reviewer' => valid_reviewer
          }
          dependency_reference, dependency_digest = write_json_artifact(
            "#{filename_prefix}_dependency_#{index}.json",
            dependency_artifact,
            batch: 'C'
          )
          dependency['status'] = 'appointed'
          dependency['identity'] = dependency_identity
          dependency['date'] = '2026-08-25'
          dependency['reference'] = dependency_reference
          dependency['artifact_sha256'] = dependency_digest
        end

        approval_scope = "Approve fixture-only deferral for #{requirement_id}."
        approval_artifact = {
          'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_C_REGISTER_ID,
          'requirement_id' => requirement_id,
          'subject' => 'approval',
          'identity' => owner_identity,
          'authority_domain' => lead_authority,
          'scope' => approval_scope,
          'date' => '2026-08-25',
          'decision_status' => 'defer',
          'canonical_disposition' => 'exclude',
          'conditions' => [],
          'reviewer' => valid_reviewer
        }
        approval_reference, approval_digest = write_json_artifact("#{filename_prefix}_approval.json", approval_artifact, batch: 'C')
        entry['approval'] = {
          'status' => 'recorded',
          'identity' => owner_identity,
          'authority_domain' => lead_authority,
          'scope' => approval_scope,
          'date' => '2026-08-25',
          'reference' => approval_reference,
          'artifact_sha256' => approval_digest,
          'conditions' => []
        }
      end
    end
  end

  def prepare_resolved_batch_d_entry(requirement_id = 'PAR-ADM-014')
    mutate_batch_d_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == requirement_id }
      filename_prefix = requirement_id.downcase.tr('-', '_')
      lead_authority = entry['lead_authority_domain']
      owner_identity = "Fixture Batch D #{requirement_id} Owner"
      owner_scope = entry.dig('accountable_owner', 'required_scope')

      evidence = {
        'evidence_class' => 'O',
        'evidence_basis' => 'observed_behavior',
        'date' => '2026-08-25',
        'source' => 'Synthetic fixture observation',
        'reference' => "FIX-D-EVIDENCE-#{requirement_id}",
        'interpreter' => 'Fixture Batch D Evidence Interpreter',
        'confidence' => 'high'
      }
      evidence_artifact = {
        'artifact_type' => ParityGovernanceValidator::EVIDENCE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_D_REGISTER_ID,
        'requirement_id' => requirement_id,
        'evidence_class' => evidence['evidence_class'],
        'evidence_basis' => evidence['evidence_basis'],
        'date' => evidence['date'],
        'source' => evidence['source'],
        'reference' => evidence['reference'],
        'interpreter' => evidence['interpreter'],
        'confidence' => evidence['confidence'],
        'reviewer' => valid_reviewer
      }
      evidence_reference, evidence_digest = write_json_artifact("#{filename_prefix}_evidence.json", evidence_artifact, batch: 'D')
      evidence['note'] = 'Synthetic fixture evidence used only to exercise the Batch D contract.'
      evidence['artifact_reference'] = evidence_reference
      evidence['artifact_sha256'] = evidence_digest
      entry['evidence'] = [evidence]

      entry['decision'] = {
        'status' => 'defer',
        'canonical_disposition' => 'exclude',
        'target' => {
          'kind' => 'exclusion',
          'reference' => 'Fixture-only Batch D deferral',
          'exclusions' => ['Production and real-patient use remain excluded; stock, issue, return, lot and charge semantics are excluded.']
        },
        'rationale' => 'Synthetic fixture decision used only to prove the Batch D validator contract.'
      }
      entry['synthetic_scenarios'].each_value { |scenario| scenario['status'] = 'ready' }

      owner_artifact = {
        'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_D_REGISTER_ID,
        'requirement_id' => requirement_id,
        'subject' => 'accountable_owner',
        'identity' => owner_identity,
        'authority_domain' => lead_authority,
        'scope' => owner_scope,
        'date' => '2026-08-25',
        'reviewer' => valid_reviewer
      }
      owner_reference, owner_digest = write_json_artifact("#{filename_prefix}_owner.json", owner_artifact, batch: 'D')
      entry['accountable_owner'] = {
        'identity' => owner_identity,
        'authority_domain' => lead_authority,
        'required_scope' => owner_scope,
        'appointed_scope' => owner_scope,
        'appointment_status' => 'appointed',
        'appointment_date' => '2026-08-25',
        'appointment_reference' => owner_reference,
        'artifact_sha256' => owner_digest
      }

      entry['appointment_dependencies'].each_with_index do |dependency, index|
        identity = "Fixture #{dependency['authority_domain']} owner"
        artifact = {
          'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_D_REGISTER_ID,
          'requirement_id' => requirement_id,
          'subject' => 'appointment_dependency',
          'identity' => identity,
          'authority_domain' => dependency['authority_domain'],
          'scope' => dependency['required_scope'],
          'date' => '2026-08-25',
          'reviewer' => valid_reviewer
        }
        reference, digest = write_json_artifact("#{filename_prefix}_dependency_#{index}.json", artifact, batch: 'D')
        dependency['status'] = 'appointed'
        dependency['identity'] = identity
        dependency['date'] = '2026-08-25'
        dependency['reference'] = reference
        dependency['artifact_sha256'] = digest
      end

      approval_scope = "Approve fixture-only Batch D deferral for #{requirement_id}."
      approval_artifact = {
        'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_D_REGISTER_ID,
        'requirement_id' => requirement_id,
        'subject' => 'approval',
        'identity' => owner_identity,
        'authority_domain' => lead_authority,
        'scope' => approval_scope,
        'date' => '2026-08-25',
        'decision_status' => 'defer',
        'canonical_disposition' => 'exclude',
        'conditions' => [],
        'reviewer' => valid_reviewer
      }
      approval_reference, approval_digest = write_json_artifact("#{filename_prefix}_approval.json", approval_artifact, batch: 'D')
      entry['approval'] = {
        'status' => 'recorded',
        'identity' => owner_identity,
        'authority_domain' => lead_authority,
        'scope' => approval_scope,
        'date' => '2026-08-25',
        'reference' => approval_reference,
        'artifact_sha256' => approval_digest,
        'conditions' => []
      }
    end
  end

  def prepare_fully_resolved_batch_e_entry(requirement_id = 'PAR-ADM-017')
    mutate_batch_c_decision_register do |register|
      upstream = register['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-ADM-004' }
      upstream['decision'] = {
        'status' => 'approve', 'canonical_disposition' => 'reproduce',
        'target' => { 'kind' => 'capability', 'reference' => 'synthetic_clinical_governance_foundation', 'exclusions' => [] },
        'rationale' => 'Fixture-only approved clinical governance foundation for a resolved Batch E dependency.'
      }
      owner_identity = upstream.dig('accountable_owner', 'identity')
      scope = 'Approve fixture-only synthetic clinical governance foundation.'
      artifact = {
        'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_C_REGISTER_ID,
        'requirement_id' => 'PAR-ADM-004', 'subject' => 'approval',
        'identity' => owner_identity, 'authority_domain' => 'clinical_governance', 'scope' => scope,
        'date' => '2026-08-25', 'decision_status' => 'approve', 'canonical_disposition' => 'reproduce',
        'conditions' => [], 'reviewer' => valid_reviewer
      }
      reference, digest = write_json_artifact('par_adm_004_upstream_approval.json', artifact, batch: 'C')
      upstream['approval'].merge!(
        'status' => 'recorded', 'identity' => owner_identity, 'authority_domain' => 'clinical_governance',
        'scope' => scope, 'date' => '2026-08-25', 'reference' => reference,
        'artifact_sha256' => digest, 'conditions' => []
      )
    end

    mutate_batch_e_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == requirement_id }
      lead = entry['lead_authority_domain']
      owner_identity = "Fixture Batch E #{requirement_id} Owner"
      owner_scope = entry.dig('accountable_owner', 'required_scope')
      prefix = requirement_id.downcase.tr('-', '_')

      evidence = {
        'evidence_class' => 'O', 'evidence_basis' => 'behavioral_execution', 'date' => '2026-08-25',
        'source' => 'Synthetic fixture execution', 'reference' => "FIX-E-EVIDENCE-#{requirement_id}",
        'interpreter' => 'Fixture Batch E Evidence Interpreter', 'confidence' => 'high'
      }
      evidence_artifact = {
        'artifact_type' => ParityGovernanceValidator::EVIDENCE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_E_REGISTER_ID,
        'requirement_id' => requirement_id, 'evidence_class' => evidence['evidence_class'],
        'evidence_basis' => evidence['evidence_basis'], 'date' => evidence['date'],
        'source' => evidence['source'], 'reference' => evidence['reference'],
        'interpreter' => evidence['interpreter'], 'confidence' => evidence['confidence'],
        'reviewer' => valid_reviewer
      }
      evidence_reference, evidence_digest = write_json_artifact("#{prefix}_evidence.json", evidence_artifact, batch: 'E')
      evidence['artifact_reference'] = evidence_reference
      evidence['artifact_sha256'] = evidence_digest
      evidence['note'] = 'Synthetic behavioral fixture; never production or real-patient evidence.'
      entry['evidence'] = [evidence]

      exclusions = [*ParityGovernanceValidator::BATCH_E_GATE_SCOPES.fetch('F'), *ParityGovernanceValidator::BATCH_E_GATE_SCOPES.fetch('G')].map do |term|
        "Exclude #{term} until its forward batch is authority-approved."
      end
      entry['decision'] = {
        'status' => 'approve', 'canonical_disposition' => 'reproduce',
        'target' => { 'kind' => 'capability', 'reference' => 'synthetic_supplier_master', 'exclusions' => exclusions },
        'rationale' => 'Fixture-only approval proving the closed Batch E contract; no production readiness is claimed.'
      }
      entry['synthetic_scenarios'].each_value { |scenario| scenario['status'] = 'ready' }

      owner_artifact = {
        'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_E_REGISTER_ID,
        'requirement_id' => requirement_id, 'subject' => 'accountable_owner',
        'identity' => owner_identity, 'authority_domain' => lead, 'scope' => owner_scope,
        'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }
      owner_reference, owner_digest = write_json_artifact("#{prefix}_owner.json", owner_artifact, batch: 'E')
      entry['accountable_owner'].merge!(
        'appointment_status' => 'appointed', 'identity' => owner_identity,
        'appointed_scope' => owner_scope, 'appointment_date' => '2026-08-25',
        'appointment_reference' => owner_reference, 'artifact_sha256' => owner_digest
      )

      [*entry['appointment_dependencies'], *entry['gate_authority_appointments']].each_with_index do |appointment, index|
        identity = "Fixture E #{requirement_id} #{appointment['authority_domain']} Authority"
        artifact = {
          'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_E_REGISTER_ID,
          'requirement_id' => requirement_id, 'subject' => 'appointment_dependency',
          'identity' => identity, 'authority_domain' => appointment['authority_domain'],
          'scope' => appointment['required_scope'], 'date' => '2026-08-25', 'reviewer' => valid_reviewer
        }
        reference, digest = write_json_artifact("#{prefix}_appointment_#{index}.json", artifact, batch: 'E')
        appointment.merge!('status' => 'appointed', 'identity' => identity, 'date' => '2026-08-25', 'reference' => reference, 'artifact_sha256' => digest)
      end

      c_entry = JSON.parse(File.read(@batch_c_decision_register))['entries'].find { |candidate| candidate['requirement_id'] == 'PAR-ADM-004' }
      entry['dependency_gates'].each do |gate|
        if gate['batch'] == 'C'
          gate['status'] = 'resolved'
          gate['resolution'] = 'Fixture binds the completed Batch C register and its appointed clinical governance approval.'
          authority_identity = c_entry.dig('accountable_owner', 'identity')
          artifact = {
            'artifact_type' => ParityGovernanceValidator::BATCH_E_GATE_ARTIFACT_TYPE,
            'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
            'register_id' => ParityGovernanceValidator::BATCH_E_REGISTER_ID,
            'requirement_id' => requirement_id, 'subject' => 'dependency_gate',
            'direction' => gate['direction'], 'batch' => gate['batch'], 'scope' => gate['scope'],
            'status' => gate['status'], 'resolution' => gate['resolution'],
            'identity' => authority_identity, 'authority_domain' => 'clinical_governance',
            'upstream_requirement_id' => c_entry['requirement_id'],
            'upstream_approval_reference' => c_entry.dig('approval', 'reference'),
            'upstream_approval_sha256' => c_entry.dig('approval', 'artifact_sha256'),
            'upstream_source_id' => ParityGovernanceValidator::BATCH_C_REGISTER_ID,
            'upstream_source_sha256' => Digest::SHA256.file(@batch_c_decision_register).hexdigest,
            'exclusions' => [], 'date' => '2026-08-25', 'reviewer' => valid_reviewer
          }
        else
          gate['status'] = 'deferred'
          gate['resolution'] = "Fixture authority-defers forward Batch #{gate['batch']} without claiming it ready."
          authority = ParityGovernanceValidator::BATCH_E_GATE_AUTHORITIES.fetch(gate['batch'])
          gate['defer_authority_domain'] = authority
          authority_record = [*entry['appointment_dependencies'], *entry['gate_authority_appointments']].find { |record| record['authority_domain'] == authority }
          artifact = {
            'artifact_type' => ParityGovernanceValidator::BATCH_E_GATE_ARTIFACT_TYPE,
            'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
            'register_id' => ParityGovernanceValidator::BATCH_E_REGISTER_ID,
            'requirement_id' => requirement_id, 'subject' => 'dependency_gate',
            'direction' => gate['direction'], 'batch' => gate['batch'], 'scope' => gate['scope'],
            'status' => gate['status'], 'resolution' => gate['resolution'],
            'identity' => authority_record['identity'], 'authority_domain' => authority,
            'upstream_requirement_id' => nil, 'upstream_approval_reference' => nil, 'upstream_approval_sha256' => nil,
            'upstream_source_id' => "G0_PARITY_BATCH_MANIFEST.json#batch-#{gate['batch']}",
            'upstream_source_sha256' => Digest::SHA256.file(@batch_manifest).hexdigest,
            'exclusions' => gate['scope'], 'date' => '2026-08-25', 'reviewer' => valid_reviewer
          }
        end
        reference, digest = write_json_artifact("#{prefix}_gate_#{gate['batch'].downcase}.json", artifact, batch: 'E')
        gate['resolution_reference'] = reference
        gate['resolution_artifact_sha256'] = digest
      end

      period_start = '2026-08-25'
      period_end = '2026-08-25'
      cutoff_at = '2026-08-25T12:00:00+07:00'
      control_values = { 'active_master_count' => 1, 'version_count' => 1 }
      ledger_receipts = ParityGovernanceValidator::BATCH_E_LEDGER_KINDS.each_with_index.map do |kind, index|
        receipt = {
          'artifact_type' => ParityGovernanceValidator::BATCH_E_LEDGER_RECEIPT_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_E_REGISTER_ID,
          'requirement_id' => requirement_id, 'ledger_kind' => kind, 'synthetic_only' => true,
          'period_start' => period_start, 'period_end' => period_end, 'cutoff_at' => cutoff_at,
          'event_count' => 1, 'control_values' => control_values,
          'ledger_digest' => Digest::SHA256.hexdigest("#{requirement_id}:#{kind}:#{index}"),
          'idempotency_key' => "FIX-E-#{requirement_id}-001", 'date' => '2026-08-25',
          'author_identity' => "Fixture #{kind} Author", 'reviewer' => valid_reviewer
        }
        reference, digest = write_json_artifact("#{prefix}_#{kind}.json", receipt, batch: 'E')
        { 'ledger_kind' => kind, 'reference' => reference, 'sha256' => digest }
      end
      reconciliation = {
        'artifact_type' => ParityGovernanceValidator::BATCH_E_RECONCILIATION_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_E_REGISTER_ID,
        'requirement_id' => requirement_id, 'family_id' => 'E1', 'synthetic_only' => true,
        'period_start' => period_start, 'period_end' => period_end, 'cutoff_at' => cutoff_at,
        'event_count' => 1, 'ledger_receipts' => ledger_receipts,
        'control_totals' => ParityGovernanceValidator::BATCH_E_FAMILY_CONTROL_TOTALS.fetch('E1'),
        'control_values' => control_values,
        'equations' => ParityGovernanceValidator::BATCH_E_FAMILY_RECONCILIATION_EQUATIONS.fetch('E1'),
        'differences' => { 'active_master_lte_version_count' => 0 },
        'idempotency_key' => "FIX-E-#{requirement_id}-001", 'date' => '2026-08-25',
        'author_identity' => 'Fixture E Reconciliation Author', 'reviewer' => valid_reviewer
      }
      reconciliation_reference, reconciliation_digest = write_json_artifact("#{prefix}_reconciliation.json", reconciliation, batch: 'E')
      entry['reconciliation_contract'].merge!(
        'status' => 'complete', 'receipt_reference' => reconciliation_reference,
        'receipt_artifact_sha256' => reconciliation_digest
      )

      approval_scope = "Approve fixture-only synthetic Batch E behavior for #{requirement_id}."
      approval_artifact = {
        'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_E_REGISTER_ID,
        'requirement_id' => requirement_id, 'subject' => 'approval',
        'identity' => owner_identity, 'authority_domain' => lead, 'scope' => approval_scope,
        'date' => '2026-08-25', 'decision_status' => 'approve', 'canonical_disposition' => 'reproduce',
        'conditions' => [], 'reviewer' => valid_reviewer
      }
      approval_reference, approval_digest = write_json_artifact("#{prefix}_approval.json", approval_artifact, batch: 'E')
      entry['approval'].merge!(
        'status' => 'recorded', 'identity' => owner_identity, 'authority_domain' => lead,
        'scope' => approval_scope, 'date' => '2026-08-25', 'reference' => approval_reference,
        'artifact_sha256' => approval_digest, 'conditions' => []
      )
    end
  end

  def prepare_coherent_batch_e_candidate
    candidate = 'E-C01'
    members = ParityGovernanceValidator::BATCH_E_CONSOLIDATION_GROUPS.fetch(candidate)
    target = 'PAR-PHA-002'
    contract = ParityGovernanceValidator::BATCH_E_CONSOLIDATION_MAPPING_CONTRACTS.fetch(candidate)
    mapping_artifact = {
      'artifact_type' => ParityGovernanceValidator::BATCH_E_CONSOLIDATION_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_E_REGISTER_ID,
      'candidate_id' => candidate, 'members' => members, 'target_requirement_id' => target,
      'member_impacts' => members.to_h { |member| [member, ["Preserve #{member} setting, encounter context, audit identity, and downstream impact."]] },
      'mapped_fields' => contract.fetch(:fields), 'mapped_states' => contract.fetch(:states),
      'exclusions' => ['No production activation or real-patient dispensing.'],
      'date' => '2026-08-25', 'author_identity' => 'Fixture E Candidate Mapping Author',
      'reviewer' => valid_reviewer
    }
    mapping_reference, mapping_digest = write_json_artifact('batch_e_c01_shared_mapping.json', mapping_artifact, batch: 'E')

    mutate_batch_e_decision_register do |register|
      members.each do |requirement_id|
        entry = register['entries'].find { |candidate_entry| candidate_entry['requirement_id'] == requirement_id }
        entry['decision'] = if requirement_id == target
                              {
                                'status' => 'approve', 'canonical_disposition' => 'reproduce',
                                'target' => { 'kind' => 'capability', 'reference' => 'setting_parameterized_dispensing_workflow', 'exclusions' => [] },
                                'rationale' => 'Fixture terminal decision for the coherent E-C01 consolidation candidate.'
                              }
                            else
                              approved_consolidation_decision(target)
                            end
        entry['consolidation_mapping'].merge!(
          'status' => 'complete', 'terminal_target_requirement_id' => target,
          'artifact_reference' => mapping_reference, 'artifact_sha256' => mapping_digest
        )

        owner_identity = "Fixture #{requirement_id} Candidate Owner"
        owner_scope = entry.dig('accountable_owner', 'required_scope')
        owner_artifact = {
          'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_E_REGISTER_ID,
          'requirement_id' => requirement_id, 'subject' => 'accountable_owner',
          'identity' => owner_identity, 'authority_domain' => 'pharmacy', 'scope' => owner_scope,
          'date' => '2026-08-25', 'reviewer' => valid_reviewer
        }
        prefix = requirement_id.downcase.tr('-', '_')
        owner_reference, owner_digest = write_json_artifact("#{prefix}_candidate_owner.json", owner_artifact, batch: 'E')
        entry['accountable_owner'].merge!(
          'appointment_status' => 'appointed', 'identity' => owner_identity,
          'appointed_scope' => owner_scope, 'appointment_date' => '2026-08-25',
          'appointment_reference' => owner_reference, 'artifact_sha256' => owner_digest
        )

        entry['appointment_dependencies'].each_with_index do |appointment, index|
          identity = "Fixture #{requirement_id} #{appointment['authority_domain']} Candidate Authority"
          artifact = {
            'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
            'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
            'register_id' => ParityGovernanceValidator::BATCH_E_REGISTER_ID,
            'requirement_id' => requirement_id, 'subject' => 'appointment_dependency',
            'identity' => identity, 'authority_domain' => appointment['authority_domain'],
            'scope' => appointment['required_scope'], 'date' => '2026-08-25', 'reviewer' => valid_reviewer
          }
          reference, digest = write_json_artifact("#{prefix}_candidate_appointment_#{index}.json", artifact, batch: 'E')
          appointment.merge!('status' => 'appointed', 'identity' => identity, 'date' => '2026-08-25', 'reference' => reference, 'artifact_sha256' => digest)
        end

        approval_scope = "Approve fixture candidate decision for #{requirement_id}."
        approval_artifact = {
          'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_E_REGISTER_ID,
          'requirement_id' => requirement_id, 'subject' => 'approval',
          'identity' => owner_identity, 'authority_domain' => 'pharmacy', 'scope' => approval_scope,
          'date' => '2026-08-25', 'decision_status' => 'approve',
          'canonical_disposition' => entry.dig('decision', 'canonical_disposition'),
          'conditions' => [], 'reviewer' => valid_reviewer
        }
        approval_reference, approval_digest = write_json_artifact("#{prefix}_candidate_approval.json", approval_artifact, batch: 'E')
        entry['approval'].merge!(
          'status' => 'recorded', 'identity' => owner_identity, 'authority_domain' => 'pharmacy',
          'scope' => approval_scope, 'date' => '2026-08-25', 'reference' => approval_reference,
          'artifact_sha256' => approval_digest, 'conditions' => []
        )
      end
    end
  end

  def prepare_batch_f_approved_entry(requirement_id, status: 'approve', disposition:, target:, exclusions: [])
    mutate_batch_f_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == requirement_id }
      lead = entry.fetch('lead_authority_domain')
      prefix = requirement_id.downcase.tr('-', '_')
      owner_identity = "Fixture Batch F #{requirement_id} Owner"
      owner_scope = entry.dig('accountable_owner', 'required_scope')

      target_kind = case disposition
                    when 'reproduce', 'replace' then 'capability'
                    when 'consolidate' then 'consolidation_target'
                    else 'exclusion'
                    end
      entry['decision'] = {
        'status' => status, 'canonical_disposition' => disposition,
        'target' => { 'kind' => target_kind, 'reference' => target, 'exclusions' => exclusions },
        'rationale' => "Fixture-only #{status} decision proving the closed Batch F artifact contract."
      }

      owner_artifact = {
        'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
        'requirement_id' => requirement_id, 'subject' => 'accountable_owner',
        'identity' => owner_identity, 'authority_domain' => lead, 'scope' => owner_scope,
        'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }
      owner_reference, owner_digest = write_json_artifact("#{prefix}_f_owner.json", owner_artifact, batch: 'F')
      entry['accountable_owner'].merge!(
        'appointment_status' => 'appointed', 'identity' => owner_identity, 'appointed_scope' => owner_scope,
        'appointment_date' => '2026-08-25', 'appointment_reference' => owner_reference,
        'artifact_sha256' => owner_digest
      )

      approval_scope = "Approve fixture-only Batch F #{status} decision for #{requirement_id}."
      approval_artifact = {
        'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
        'requirement_id' => requirement_id, 'subject' => 'approval',
        'identity' => owner_identity, 'authority_domain' => lead, 'scope' => approval_scope,
        'date' => '2026-08-25', 'decision_status' => status,
        'canonical_disposition' => disposition, 'conditions' => [], 'reviewer' => valid_reviewer
      }
      approval_reference, approval_digest = write_json_artifact("#{prefix}_f_approval.json", approval_artifact, batch: 'F')
      entry['approval'].merge!(
        'status' => 'recorded', 'identity' => owner_identity, 'authority_domain' => lead,
        'scope' => approval_scope, 'date' => '2026-08-25', 'reference' => approval_reference,
        'artifact_sha256' => approval_digest, 'conditions' => []
      )
    end
  end

  def prepare_batch_f_scoped_e_deferral(requirement_id)
    exclusions = [
      'Medication readiness remains excluded.',
      'Stock valuation readiness remains excluded.',
      'Medication charge-credit completeness remains excluded.',
      'Pharmacy claim completeness remains excluded.'
    ]
    prepare_batch_f_approved_entry(
      requirement_id, status: 'defer', disposition: 'exclude',
      target: 'Scoped Batch E pharmacy and GF exclusion', exclusions: exclusions
    )

    mutate_batch_f_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == requirement_id }
      prefix = requirement_id.downcase.tr('-', '_')
      %w[pharmacy_gf finance_accounting].each do |domain|
        appointment = entry['gate_authority_appointments'].find { |candidate| candidate['authority_domain'] == domain }
        identity = "Fixture #{domain} E Deferral Authority"
        artifact = {
          'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
          'requirement_id' => requirement_id, 'subject' => 'appointment_dependency',
          'identity' => identity, 'authority_domain' => domain, 'scope' => appointment['required_scope'],
          'date' => '2026-08-25', 'reviewer' => valid_reviewer
        }
        reference, digest = write_json_artifact("#{prefix}_#{domain}_gate_appointment.json", artifact, batch: 'F')
        appointment.merge!(
          'status' => 'appointed', 'identity' => identity, 'date' => '2026-08-25',
          'reference' => reference, 'artifact_sha256' => digest
        )
      end

      gate = entry['dependency_gates'].find { |candidate| candidate['batch'] == 'E' }
      resolution = 'Authority-scoped synthetic deferral; no medication, stock valuation, pharmacy claim, or live-delivery readiness is claimed.'
      exclusions = %w[no_medication_charge_readiness no_stock_valuation_readiness no_pharmacy_claim_completeness no_live_delivery]
      bindings = %w[pharmacy_gf finance_accounting].map do |domain|
        appointment = entry['gate_authority_appointments'].find { |candidate| candidate['authority_domain'] == domain }
        approval_artifact = {
          'artifact_type' => ParityGovernanceValidator::BATCH_F_GATE_DEFERRAL_APPROVAL_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
          'requirement_id' => requirement_id, 'subject' => 'gate_deferral_approval',
          'batch' => 'E', 'scope' => gate['scope'], 'status' => 'deferred',
          'resolution' => resolution, 'exclusions' => exclusions,
          'identity' => appointment['identity'], 'authority_domain' => domain,
          'date' => '2026-08-25', 'reviewer' => valid_reviewer
        }
        approval_reference, approval_digest = write_json_artifact("#{prefix}_#{domain}_signed_e_deferral.json", approval_artifact, batch: 'F')
        {
          'authority_domain' => domain, 'identity' => appointment['identity'],
          'appointment_reference' => appointment['reference'], 'appointment_sha256' => appointment['artifact_sha256'],
          'approval_reference' => approval_reference, 'approval_sha256' => approval_digest
        }
      end
      artifact = {
        'artifact_type' => ParityGovernanceValidator::BATCH_F_GATE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
        'requirement_id' => requirement_id, 'subject' => 'dependency_gate',
        'direction' => 'upstream', 'batch' => 'E', 'scope' => gate['scope'], 'status' => 'deferred',
        'resolution' => resolution, 'identity' => bindings.first['identity'], 'authority_domain' => 'pharmacy_gf',
        'source_register_id' => ParityGovernanceValidator::BATCH_E_REGISTER_ID,
        'source_register_sha256' => Digest::SHA256.file(@batch_e_decision_register).hexdigest,
        'source_bindings' => [], 'deferral_bindings' => bindings, 'exclusions' => exclusions,
        'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }
      reference, digest = write_json_artifact("#{prefix}_valid_e_deferral.json", artifact, batch: 'F')
      gate.merge!(
        'status' => 'deferred', 'resolution' => resolution,
        'defer_authority_domain' => 'pharmacy_gf_and_finance_accounting',
        'resolution_reference' => reference, 'resolution_artifact_sha256' => digest
      )
    end
  end

  def prepare_fully_resolved_batch_f_entry(requirement_id)
    source_ids = %w[PAR-ADM-003 PAR-ADM-037]
    mutate_decision_register do |register|
      register['register_status'] = 'complete'
      register['entries'].each do |entry|
        entry['decision'] = {
          'status' => 'defer', 'canonical_disposition' => 'exclude',
          'target' => {
            'kind' => 'exclusion', 'reference' => 'Fixture-only unresolved Batch A scope',
            'exclusions' => ['No operational readiness is asserted by this synthetic fixture.']
          },
          'rationale' => 'Fixture-only terminal state used to exercise a downstream gate without claiming Batch A readiness.'
        }
      end
      source_ids.each do |source_id|
        entry = register['entries'].find { |candidate| candidate['requirement_id'] == source_id }
        prefix = source_id.downcase.tr('-', '_')
        owner_identity = "Fixture Batch A #{source_id} Security Owner"
        owner_scope = entry.dig('accountable_owner', 'required_scope')
        entry['decision'] = {
          'status' => 'approve', 'canonical_disposition' => 'reproduce',
          'target' => { 'kind' => 'capability', 'reference' => "fixture_#{prefix}_foundation", 'exclusions' => [] },
          'rationale' => 'Fixture-only approved reproduce decision for exact downstream source binding.'
        }
        owner_artifact = {
          'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_A_REGISTER_ID,
          'requirement_id' => source_id, 'subject' => 'accountable_owner',
          'identity' => owner_identity, 'authority_domain' => 'security_privacy_data',
          'scope' => owner_scope, 'date' => '2026-08-25', 'reviewer' => valid_reviewer
        }
        owner_reference, owner_digest = write_json_artifact("#{prefix}_f_gate_owner.json", owner_artifact, batch: 'A')
        entry['accountable_owner'].merge!(
          'appointment_status' => 'appointed', 'identity' => owner_identity,
          'authority_domain' => 'security_privacy_data', 'appointed_scope' => owner_scope,
          'appointment_date' => '2026-08-25', 'appointment_reference' => owner_reference,
          'artifact_sha256' => owner_digest
        )
        approval_scope = "Approve fixture-only #{source_id} source binding."
        approval_artifact = {
          'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_A_REGISTER_ID,
          'requirement_id' => source_id, 'subject' => 'approval', 'identity' => owner_identity,
          'scope' => approval_scope, 'date' => '2026-08-25', 'decision_status' => 'approve',
          'canonical_disposition' => 'reproduce', 'conditions' => [], 'reviewer' => valid_reviewer
        }
        approval_reference, approval_digest = write_json_artifact("#{prefix}_f_gate_approval.json", approval_artifact, batch: 'A')
        entry['approval'].merge!(
          'status' => 'recorded', 'identity' => owner_identity, 'scope' => approval_scope,
          'date' => '2026-08-25', 'reference' => approval_reference,
          'artifact_sha256' => approval_digest, 'conditions' => []
        )
      end
    end

    prepare_batch_f_approved_entry(requirement_id, disposition: 'reproduce', target: 'fixture_bank_mapping_capability')
    source_register = JSON.parse(File.read(@decision_register))
    source_sha = Digest::SHA256.file(@decision_register).hexdigest
    mutate_batch_f_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == requirement_id }
      prefix = requirement_id.downcase.tr('-', '_')

      evidence_record = {
        'evidence_class' => 'O', 'evidence_basis' => 'behavioral_execution',
        'date' => '2026-08-25', 'source' => 'Deterministic synthetic finance fixture',
        'reference' => 'FIX-F-BANK-MAPPING-OBSERVATION-01',
        'interpreter' => 'Fixture Batch F Evidence Interpreter', 'confidence' => 'high'
      }
      evidence_artifact = {
        'artifact_type' => ParityGovernanceValidator::EVIDENCE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
        'requirement_id' => requirement_id
      }.merge(evidence_record).merge('reviewer' => valid_reviewer)
      evidence_reference, evidence_digest = write_json_artifact("#{prefix}_resolved_evidence.json", evidence_artifact, batch: 'F')
      entry['evidence'] = [evidence_record.merge(
        'artifact_reference' => evidence_reference, 'artifact_sha256' => evidence_digest,
        'note' => 'Synthetic behavioral fixture used only to prove the fail-closed Batch F validator path.'
      )]
      entry['synthetic_scenarios'].each_value { |scenario| scenario['status'] = 'ready' }

      entry['appointment_dependencies'].each_with_index do |appointment, index|
        identity = "Fixture #{requirement_id} #{appointment['authority_domain']} Authority"
        artifact = {
          'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
          'requirement_id' => requirement_id, 'subject' => 'appointment_dependency',
          'identity' => identity, 'authority_domain' => appointment['authority_domain'],
          'scope' => appointment['required_scope'], 'date' => '2026-08-25', 'reviewer' => valid_reviewer
        }
        reference, digest = write_json_artifact("#{prefix}_resolved_appointment_#{index}.json", artifact, batch: 'F')
        appointment.merge!(
          'status' => 'appointed', 'identity' => identity, 'date' => '2026-08-25',
          'reference' => reference, 'artifact_sha256' => digest
        )
      end

      reporting = entry['gate_authority_appointments'].find { |appointment| appointment['authority_domain'] == 'reporting' }
      reporting_identity = "Fixture #{requirement_id} Reporting Gate Authority"
      reporting_artifact = {
        'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
        'requirement_id' => requirement_id, 'subject' => 'appointment_dependency',
        'identity' => reporting_identity, 'authority_domain' => 'reporting',
        'scope' => reporting['required_scope'], 'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }
      reporting_reference, reporting_digest = write_json_artifact("#{prefix}_reporting_gate_appointment.json", reporting_artifact, batch: 'F')
      reporting.merge!(
        'status' => 'appointed', 'identity' => reporting_identity, 'date' => '2026-08-25',
        'reference' => reporting_reference, 'artifact_sha256' => reporting_digest
      )

      a_gate = entry['dependency_gates'].find { |gate| gate['batch'] == 'A' }
      source_bindings = source_ids.map do |source_id|
        source = source_register['entries'].find { |candidate| candidate['requirement_id'] == source_id }
        {
          'requirement_id' => source_id, 'lead_authority_domain' => source.dig('accountable_owner', 'authority_domain'),
          'owner_identity' => source.dig('accountable_owner', 'identity'),
          'approval_reference' => source.dig('approval', 'reference'),
          'approval_sha256' => source.dig('approval', 'artifact_sha256')
        }
      end
      a_resolution = 'Resolve the exact synthetic identity, access, audit, and configuration foundation against appointed Batch A source owners.'
      a_artifact = {
        'artifact_type' => ParityGovernanceValidator::BATCH_F_GATE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
        'requirement_id' => requirement_id, 'subject' => 'dependency_gate',
        'direction' => 'upstream', 'batch' => 'A', 'scope' => a_gate['scope'], 'status' => 'resolved',
        'resolution' => a_resolution, 'identity' => source_bindings.first['owner_identity'],
        'authority_domain' => source_bindings.first['lead_authority_domain'],
        'source_register_id' => ParityGovernanceValidator::BATCH_A_REGISTER_ID,
        'source_register_sha256' => source_sha, 'source_bindings' => source_bindings,
        'deferral_bindings' => [], 'exclusions' => [], 'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }
      a_reference, a_digest = write_json_artifact("#{prefix}_resolved_a_gate.json", a_artifact, batch: 'F')
      a_gate.merge!(
        'status' => 'resolved', 'resolution' => a_resolution, 'defer_authority_domain' => nil,
        'resolution_reference' => a_reference, 'resolution_artifact_sha256' => a_digest
      )

      g_gate = entry['dependency_gates'].find { |gate| gate['batch'] == 'G' }
      g_resolution = 'Defer the unavailable Batch G projection and export gate without claiming reporting or financial truth readiness.'
      g_exclusions = %w[no_g_readiness no_reporting_export no_financial_truth no_live_delivery]
      g_approval_artifact = {
        'artifact_type' => ParityGovernanceValidator::BATCH_F_GATE_DEFERRAL_APPROVAL_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
        'requirement_id' => requirement_id, 'subject' => 'gate_deferral_approval',
        'batch' => 'G', 'scope' => g_gate['scope'], 'status' => 'deferred',
        'resolution' => g_resolution, 'exclusions' => g_exclusions,
        'identity' => reporting_identity, 'authority_domain' => 'reporting',
        'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }
      g_approval_reference, g_approval_digest = write_json_artifact("#{prefix}_reporting_signed_g_deferral.json", g_approval_artifact, batch: 'F')
      g_artifact = {
        'artifact_type' => ParityGovernanceValidator::BATCH_F_GATE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
        'requirement_id' => requirement_id, 'subject' => 'dependency_gate',
        'direction' => 'forward', 'batch' => 'G', 'scope' => g_gate['scope'], 'status' => 'deferred',
        'resolution' => g_resolution, 'identity' => reporting_identity, 'authority_domain' => 'reporting',
        'source_register_id' => 'G0_PARITY_BATCH_MANIFEST.json#batch-G',
        'source_register_sha256' => Digest::SHA256.file(@batch_manifest).hexdigest,
        'source_bindings' => [],
        'deferral_bindings' => [{
          'authority_domain' => 'reporting', 'identity' => reporting_identity,
          'appointment_reference' => reporting_reference, 'appointment_sha256' => reporting_digest,
          'approval_reference' => g_approval_reference, 'approval_sha256' => g_approval_digest
        }],
        'exclusions' => g_exclusions,
        'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }
      g_reference, g_digest = write_json_artifact("#{prefix}_deferred_g_gate.json", g_artifact, batch: 'F')
      g_gate.merge!(
        'status' => 'deferred', 'resolution' => g_resolution, 'defer_authority_domain' => 'reporting',
        'resolution_reference' => g_reference, 'resolution_artifact_sha256' => g_digest
      )

      control_values = {
        'active_master_count' => 1, 'effective_version_count' => 1,
        'invalid_overlap_count' => 0, 'correction_event_count' => 1
      }
      common_receipt = {
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
        'requirement_id' => requirement_id, 'profile_id' => 'master_version',
        'synthetic_only' => true, 'currency' => 'IDR', 'minor_unit' => 1,
        'period_start' => '2026-08-01', 'period_end' => '2026-08-25',
        'period_timezone' => 'Asia/Jakarta', 'cutoff_at' => '2026-08-25T23:59:59+07:00',
        'late_posting_policy' => 'append_to_open_period_with_prior_period_reference',
        'event_count' => 1, 'control_values' => control_values,
        'idempotency_key' => 'FIX-F-ADM-016-RECON-01', 'date' => '2026-08-25',
        'author_identity' => 'Fixture Batch F Ledger Author', 'reviewer' => valid_reviewer
      }
      receipt_artifact = common_receipt.merge(
        'artifact_type' => ParityGovernanceValidator::BATCH_F_LEDGER_RECEIPT_ARTIFACT_TYPE,
        'ledger_kind' => 'finance_master_ledger',
        'ledger_digest' => Digest::SHA256.hexdigest('fixture-adm-016-finance-master-ledger')
      )
      ledger_reference, ledger_digest = write_json_artifact("#{prefix}_finance_master_receipt.json", receipt_artifact, batch: 'F')
      reconciliation_artifact = common_receipt.merge(
        'artifact_type' => ParityGovernanceValidator::BATCH_F_RECONCILIATION_ARTIFACT_TYPE,
        'family_id' => 'F1',
        'ledger_receipts' => [{ 'ledger_kind' => 'finance_master_ledger', 'reference' => ledger_reference, 'sha256' => ledger_digest }],
        'equations' => ['invalid_overlap_count=0'],
        'differences' => { 'master_overlap_difference' => 0 }
      )
      reconciliation_reference, reconciliation_digest = write_json_artifact("#{prefix}_reconciliation.json", reconciliation_artifact, batch: 'F')
      entry['reconciliation_contract'].merge!(
        'status' => 'complete', 'receipt_reference' => reconciliation_reference,
        'receipt_artifact_sha256' => reconciliation_digest
      )
    end
  end

  def prepare_batch_f_upstream_sources(batch, source_ids)
    path = {
      'A' => @decision_register, 'B' => @batch_b_decision_register,
      'C' => @batch_c_decision_register, 'D' => @batch_d_decision_register
    }.fetch(batch)
    register_id = {
      'A' => ParityGovernanceValidator::BATCH_A_REGISTER_ID,
      'B' => ParityGovernanceValidator::BATCH_B_REGISTER_ID,
      'C' => ParityGovernanceValidator::BATCH_C_REGISTER_ID,
      'D' => ParityGovernanceValidator::BATCH_D_REGISTER_ID
    }.fetch(batch)
    register = JSON.parse(File.read(path))
    register['register_status'] = 'complete'
    register['entries'].each do |entry|
      entry['decision'] = {
        'status' => 'defer', 'canonical_disposition' => 'exclude',
        'target' => {
          'kind' => 'exclusion', 'reference' => "Fixture-only terminal Batch #{batch} scope",
          'exclusions' => ['No operational readiness is asserted by this synthetic fixture.']
        },
        'rationale' => "Fixture-only terminal Batch #{batch} state used to exercise an exact downstream binding."
      }
    end
    source_ids.each do |source_id|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == source_id }
      prefix = source_id.downcase.tr('-', '_')
      lead = entry['lead_authority_domain'] || (batch == 'B' ? 'registration_admission' : 'security_privacy_data')
      owner_identity = "Fixture Batch #{batch} #{source_id} Owner"
      owner_scope = entry.dig('accountable_owner', 'required_scope')
      entry['decision'] = {
        'status' => 'approve', 'canonical_disposition' => 'reproduce',
        'target' => { 'kind' => 'capability', 'reference' => "fixture_#{prefix}_source", 'exclusions' => [] },
        'rationale' => "Fixture-only approved Batch #{batch} source for exact downstream binding."
      }
      owner_artifact = {
        'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => register_id, 'requirement_id' => source_id, 'subject' => 'accountable_owner',
        'identity' => owner_identity, 'authority_domain' => lead, 'scope' => owner_scope,
        'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }
      owner_reference, owner_digest = write_json_artifact("#{prefix}_f_complex_owner.json", owner_artifact, batch: batch)
      entry['accountable_owner'].merge!(
        'appointment_status' => 'appointed', 'identity' => owner_identity, 'authority_domain' => lead,
        'appointed_scope' => owner_scope, 'appointment_date' => '2026-08-25',
        'appointment_reference' => owner_reference, 'artifact_sha256' => owner_digest
      )
      approval_scope = "Approve fixture-only #{source_id} downstream source binding."
      approval_artifact = {
        'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => register_id, 'requirement_id' => source_id, 'subject' => 'approval',
        'identity' => owner_identity, 'scope' => approval_scope, 'date' => '2026-08-25',
        'decision_status' => 'approve', 'canonical_disposition' => 'reproduce',
        'conditions' => [], 'reviewer' => valid_reviewer
      }
      approval_artifact['authority_domain'] = lead if %w[C D].include?(batch)
      approval_reference, approval_digest = write_json_artifact("#{prefix}_f_complex_approval.json", approval_artifact, batch: batch)
      entry['approval'].merge!(
        'status' => 'recorded', 'identity' => owner_identity, 'scope' => approval_scope,
        'date' => '2026-08-25', 'reference' => approval_reference,
        'artifact_sha256' => approval_digest, 'conditions' => []
      )
      entry['approval']['authority_domain'] = lead if %w[C D].include?(batch)
    end
    File.write(path, JSON.pretty_generate(register) + "\n")
    [register, Digest::SHA256.file(path).hexdigest]
  end

  def prepare_complex_batch_f_g0_entry
    requirement_id = 'PAR-FIN-001'
    gate_sources = ParityGovernanceValidator::BATCH_F_GATE_PROFILES.fetch('billing')
    upstream = %w[A B C D].to_h do |batch|
      [batch, prepare_batch_f_upstream_sources(batch, gate_sources.fetch(batch))]
    end
    %w[PAR-ADM-011 PAR-ADM-018 PAR-ADM-019].each do |target_id|
      prepare_batch_f_approved_entry(target_id, disposition: 'reproduce', target: "fixture_#{target_id.downcase.tr('-', '_')}_capability")
    end
    prepare_batch_f_scoped_e_deferral(requirement_id)

    mutate_batch_f_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == requirement_id }
      prefix = requirement_id.downcase.tr('-', '_')
      evidence = {
        'evidence_class' => 'O', 'evidence_basis' => 'reconciled_ledger',
        'date' => '2026-08-25', 'source' => 'Deterministic synthetic bill reconciliation fixture',
        'reference' => 'FIX-F-FIN-001-RECONCILED-LEDGER-01',
        'interpreter' => 'Fixture Batch F Bill Interpreter', 'confidence' => 'high'
      }
      evidence_artifact = {
        'artifact_type' => ParityGovernanceValidator::EVIDENCE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
        'requirement_id' => requirement_id
      }.merge(evidence).merge('reviewer' => valid_reviewer)
      evidence_reference, evidence_digest = write_json_artifact("#{prefix}_complex_evidence.json", evidence_artifact, batch: 'F')
      entry['evidence'] = [evidence.merge(
        'artifact_reference' => evidence_reference, 'artifact_sha256' => evidence_digest,
        'note' => 'Synthetic reconciled-ledger fixture; no operational or external readiness is claimed.'
      )]
      entry['synthetic_scenarios'].each_value { |scenario| scenario['status'] = 'ready' }

      entry['appointment_dependencies'].each_with_index do |appointment, index|
        identity = "Fixture #{requirement_id} #{appointment['authority_domain']} Authority"
        artifact = {
          'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
          'requirement_id' => requirement_id, 'subject' => 'appointment_dependency',
          'identity' => identity, 'authority_domain' => appointment['authority_domain'],
          'scope' => appointment['required_scope'], 'date' => '2026-08-25', 'reviewer' => valid_reviewer
        }
        reference, digest = write_json_artifact("#{prefix}_complex_appointment_#{index}.json", artifact, batch: 'F')
        appointment.merge!('status' => 'appointed', 'identity' => identity, 'date' => '2026-08-25', 'reference' => reference, 'artifact_sha256' => digest)
      end

      reporting = entry['gate_authority_appointments'].find { |appointment| appointment['authority_domain'] == 'reporting' }
      reporting_identity = 'Fixture FIN-001 Reporting Gate Authority'
      reporting_artifact = {
        'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
        'requirement_id' => requirement_id, 'subject' => 'appointment_dependency',
        'identity' => reporting_identity, 'authority_domain' => 'reporting',
        'scope' => reporting['required_scope'], 'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }
      reporting_reference, reporting_digest = write_json_artifact("#{prefix}_complex_reporting_appointment.json", reporting_artifact, batch: 'F')
      reporting.merge!('status' => 'appointed', 'identity' => reporting_identity, 'date' => '2026-08-25', 'reference' => reporting_reference, 'artifact_sha256' => reporting_digest)

      %w[A B C D].each do |batch|
        gate = entry['dependency_gates'].find { |candidate| candidate['batch'] == batch }
        source_register, source_sha = upstream.fetch(batch)
        bindings = gate['source_requirement_ids'].map do |source_id|
          source = source_register['entries'].find { |candidate| candidate['requirement_id'] == source_id }
          {
            'requirement_id' => source_id,
            'lead_authority_domain' => source['lead_authority_domain'] || source.dig('accountable_owner', 'authority_domain'),
            'owner_identity' => source.dig('accountable_owner', 'identity'),
            'approval_reference' => source.dig('approval', 'reference'),
            'approval_sha256' => source.dig('approval', 'artifact_sha256')
          }
        end
        resolution = "Resolve exact synthetic Batch #{batch} source decisions for #{requirement_id}."
        artifact = {
          'artifact_type' => ParityGovernanceValidator::BATCH_F_GATE_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
          'requirement_id' => requirement_id, 'subject' => 'dependency_gate',
          'direction' => 'upstream', 'batch' => batch, 'scope' => gate['scope'], 'status' => 'resolved',
          'resolution' => resolution, 'identity' => bindings.first['owner_identity'],
          'authority_domain' => bindings.first['lead_authority_domain'],
          'source_register_id' => { 'A' => ParityGovernanceValidator::BATCH_A_REGISTER_ID, 'B' => ParityGovernanceValidator::BATCH_B_REGISTER_ID, 'C' => ParityGovernanceValidator::BATCH_C_REGISTER_ID, 'D' => ParityGovernanceValidator::BATCH_D_REGISTER_ID }.fetch(batch),
          'source_register_sha256' => source_sha, 'source_bindings' => bindings,
          'deferral_bindings' => [], 'exclusions' => [], 'date' => '2026-08-25', 'reviewer' => valid_reviewer
        }
        reference, digest = write_json_artifact("#{prefix}_complex_#{batch.downcase}_gate.json", artifact, batch: 'F')
        gate.merge!('status' => 'resolved', 'resolution' => resolution, 'defer_authority_domain' => nil, 'resolution_reference' => reference, 'resolution_artifact_sha256' => digest)
      end

      entry['intra_batch_dependencies'].each_with_index do |dependency, index|
        target = register['entries'].find { |candidate| candidate['requirement_id'] == dependency['requirement_id'] }
        artifact = {
          'artifact_type' => ParityGovernanceValidator::BATCH_F_INTRA_DEPENDENCY_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
          'source_requirement_id' => requirement_id, 'target_requirement_id' => target['requirement_id'],
          'scope' => dependency['scope'], 'status' => 'resolved',
          'identity' => target.dig('accountable_owner', 'identity'), 'authority_domain' => target['lead_authority_domain'],
          'target_decision_status' => 'approve', 'target_disposition' => target.dig('decision', 'canonical_disposition'),
          'target_approval_reference' => target.dig('approval', 'reference'),
          'target_approval_sha256' => target.dig('approval', 'artifact_sha256'),
          'date' => '2026-08-25', 'reviewer' => valid_reviewer
        }
        reference, digest = write_json_artifact("#{prefix}_complex_intra_#{index}.json", artifact, batch: 'F')
        dependency.merge!('status' => 'resolved', 'resolution_reference' => reference, 'resolution_artifact_sha256' => digest)
      end

      g_gate = entry['dependency_gates'].find { |candidate| candidate['batch'] == 'G' }
      g_resolution = 'Defer unavailable G reporting without claiming export, financial truth, or live-delivery readiness.'
      g_approval = {
        'artifact_type' => ParityGovernanceValidator::BATCH_F_GATE_DEFERRAL_APPROVAL_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
        'requirement_id' => requirement_id, 'subject' => 'gate_deferral_approval',
        'batch' => 'G', 'scope' => g_gate['scope'], 'status' => 'deferred', 'resolution' => g_resolution,
        'exclusions' => ParityGovernanceValidator::BATCH_F_G_DEFERRAL_EXCLUSIONS,
        'identity' => reporting_identity, 'authority_domain' => 'reporting',
        'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }
      g_approval_reference, g_approval_digest = write_json_artifact("#{prefix}_complex_g_approval.json", g_approval, batch: 'F')
      g_artifact = {
        'artifact_type' => ParityGovernanceValidator::BATCH_F_GATE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
        'requirement_id' => requirement_id, 'subject' => 'dependency_gate',
        'direction' => 'forward', 'batch' => 'G', 'scope' => g_gate['scope'], 'status' => 'deferred',
        'resolution' => g_resolution, 'identity' => reporting_identity, 'authority_domain' => 'reporting',
        'source_register_id' => 'G0_PARITY_BATCH_MANIFEST.json#batch-G',
        'source_register_sha256' => Digest::SHA256.file(@batch_manifest).hexdigest,
        'source_bindings' => [], 'deferral_bindings' => [{
          'authority_domain' => 'reporting', 'identity' => reporting_identity,
          'appointment_reference' => reporting_reference, 'appointment_sha256' => reporting_digest,
          'approval_reference' => g_approval_reference, 'approval_sha256' => g_approval_digest
        }],
        'exclusions' => ParityGovernanceValidator::BATCH_F_G_DEFERRAL_EXCLUSIONS,
        'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }
      g_reference, g_digest = write_json_artifact("#{prefix}_complex_g_gate.json", g_artifact, batch: 'F')
      g_gate.merge!('status' => 'deferred', 'resolution' => g_resolution, 'defer_authority_domain' => 'reporting', 'resolution_reference' => g_reference, 'resolution_artifact_sha256' => g_digest)

      values = {
        'gross_charge_total' => 1000, 'approved_discount_total' => 100, 'tax_fee_total' => 50,
        'debit_adjustment_total' => 20, 'credit_adjustment_total' => 30,
        'reversal_total' => 40, 'net_bill_total' => 900
      }
      common = {
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
        'requirement_id' => requirement_id, 'profile_id' => 'bill_version', 'synthetic_only' => true,
        'currency' => 'IDR', 'minor_unit' => 1, 'period_start' => '2026-08-01', 'period_end' => '2026-08-25',
        'period_timezone' => 'Asia/Jakarta', 'cutoff_at' => '2026-08-25T23:59:59+07:00',
        'late_posting_policy' => 'append_to_open_period_with_prior_period_reference',
        'event_count' => 4, 'control_values' => values, 'idempotency_key' => 'FIX-F-FIN-001-COMPLEX-01',
        'date' => '2026-08-25', 'author_identity' => 'Fixture FIN-001 Ledger Author', 'reviewer' => valid_reviewer
      }
      ledgers = ParityGovernanceValidator::BATCH_F_RECONCILIATION_PROFILES.fetch('bill_version').fetch(:ledgers)
      descriptors = ledgers.each_with_index.map do |ledger, index|
        receipt = common.merge(
          'artifact_type' => ParityGovernanceValidator::BATCH_F_LEDGER_RECEIPT_ARTIFACT_TYPE,
          'ledger_kind' => ledger, 'ledger_digest' => Digest::SHA256.hexdigest("fixture-fin-001-#{ledger}-#{index}")
        )
        reference, digest = write_json_artifact("#{prefix}_complex_receipt_#{index}.json", receipt, batch: 'F')
        { 'ledger_kind' => ledger, 'reference' => reference, 'sha256' => digest }
      end
      reconciliation = common.merge(
        'artifact_type' => ParityGovernanceValidator::BATCH_F_RECONCILIATION_ARTIFACT_TYPE,
        'family_id' => 'F2', 'ledger_receipts' => descriptors,
        'equations' => ParityGovernanceValidator::BATCH_F_RECONCILIATION_PROFILES.fetch('bill_version').fetch(:equations),
        'differences' => { 'bill_difference' => 0 }
      )
      reference, digest = write_json_artifact("#{prefix}_complex_reconciliation.json", reconciliation, batch: 'F')
      entry['reconciliation_contract'].merge!('status' => 'complete', 'receipt_reference' => reference, 'receipt_artifact_sha256' => digest)
    end
  end

  def prepare_batch_f_coherent_consolidation_candidate_f_c01
    prepare_batch_f_approved_entry('PAR-ADM-018', disposition: 'reproduce', target: 'fixture_component_group_capability')
    prepare_batch_f_approved_entry('PAR-ADM-019', disposition: 'consolidate', target: 'PAR-ADM-018')
    mapping = ParityGovernanceValidator::BATCH_F_CONSOLIDATION_MAPPING_CONTRACTS.fetch('F-C01')
    members = ParityGovernanceValidator::BATCH_F_CONSOLIDATION_GROUPS.fetch('F-C01')
    target_entry = JSON.parse(File.read(@batch_f_decision_register))['entries'].find { |entry| entry['requirement_id'] == 'PAR-ADM-018' }
    artifact = {
      'artifact_type' => ParityGovernanceValidator::BATCH_F_CONSOLIDATION_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_F_REGISTER_ID,
      'candidate_id' => 'F-C01', 'members' => members, 'target_requirement_id' => 'PAR-ADM-018',
      'terminal_owner_identity' => target_entry.dig('accountable_owner', 'identity'),
      'terminal_authority_domain' => target_entry['lead_authority_domain'],
      'terminal_approval_reference' => target_entry.dig('approval', 'reference'),
      'terminal_approval_sha256' => target_entry.dig('approval', 'artifact_sha256'),
      'member_impacts' => members.to_h { |member| [member, ["Preserve #{member} role, state, audit, and lineage in the shared component capability."]] },
      'mapped_fields' => mapping.fetch(:fields), 'mapped_states' => mapping.fetch(:states),
      'mapped_control_totals' => mapping.fetch(:control_totals),
      'lineage_preserved' => true, 'authorities_preserved' => true,
      'exclusions' => ['No business equivalence beyond this fixture contract is asserted.'],
      'date' => '2026-08-25', 'author_identity' => 'Fixture Batch F Mapping Author', 'reviewer' => valid_reviewer
    }
    reference, digest = write_json_artifact('f_c01_shared_mapping.json', artifact, batch: 'F')
    mutate_batch_f_decision_register do |register|
      members.each do |requirement_id|
        entry = register['entries'].find { |candidate| candidate['requirement_id'] == requirement_id }
        entry['consolidation_mapping'].merge!(
          'status' => 'complete', 'terminal_target_requirement_id' => 'PAR-ADM-018',
          'artifact_reference' => reference, 'artifact_sha256' => digest
        )
      end
    end
  end

  def prepare_batch_g_governed_entry(requirement_id, status:, disposition:, target_kind:, target_reference:, exclusions: [])
    mutate_batch_g_decision_register do |register|
      entry = register['entries'].find { |candidate| candidate['requirement_id'] == requirement_id }
      prefix = requirement_id.downcase.tr('-', '_')
      lead = entry['lead_authority_domain']
      owner_identity = "Fixture Batch G #{requirement_id} Lead Owner"
      owner_scope = entry.dig('accountable_owner', 'required_scope')
      entry['decision'] = {
        'status' => status,
        'canonical_disposition' => disposition,
        'target' => { 'kind' => target_kind, 'reference' => target_reference, 'exclusions' => exclusions },
        'rationale' => 'Fixture-only synthetic Batch G decision proving the closed governance contract without operational claims.'
      }

      evidence_record = {
        'evidence_class' => 'O', 'evidence_basis' => 'independent_output_verification',
        'date' => '2026-08-25', 'source' => 'Deterministic rooted synthetic Batch G fixture',
        'reference' => "FIX-G-EVIDENCE-#{requirement_id}",
        'interpreter' => "Fixture Batch G #{requirement_id} Evidence Interpreter", 'confidence' => 'high'
      }
      evidence_artifact = {
        'artifact_type' => ParityGovernanceValidator::EVIDENCE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
        'requirement_id' => requirement_id
      }.merge(evidence_record).merge('reviewer' => valid_reviewer)
      evidence_reference, evidence_digest = write_json_artifact("#{prefix}_evidence.json", evidence_artifact, batch: 'G')
      entry['evidence'] = [evidence_record.merge(
        'artifact_reference' => evidence_reference, 'artifact_sha256' => evidence_digest,
        'note' => 'Fixture-only rooted synthetic evidence; no production, compliance, or live transmission claim.'
      )]
      entry['synthetic_scenarios'].each_value { |scenario| scenario['status'] = 'ready' }

      owner_artifact = {
        'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
        'requirement_id' => requirement_id, 'subject' => 'accountable_owner',
        'identity' => owner_identity, 'authority_domain' => lead,
        'scope' => owner_scope, 'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }
      owner_reference, owner_digest = write_json_artifact("#{prefix}_owner.json", owner_artifact, batch: 'G')
      entry['accountable_owner'].merge!(
        'appointment_status' => 'appointed', 'identity' => owner_identity,
        'appointed_scope' => owner_scope, 'appointment_date' => '2026-08-25',
        'appointment_reference' => owner_reference, 'artifact_sha256' => owner_digest
      )

      entry['appointment_dependencies'].each_with_index do |appointment, index|
        identity = "Fixture #{requirement_id} #{appointment['authority_domain']} Authority"
        artifact = {
          'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
          'requirement_id' => requirement_id, 'subject' => 'appointment_dependency',
          'identity' => identity, 'authority_domain' => appointment['authority_domain'],
          'scope' => appointment['required_scope'], 'date' => '2026-08-25', 'reviewer' => valid_reviewer
        }
        reference, digest = write_json_artifact("#{prefix}_appointment_#{index}.json", artifact, batch: 'G')
        appointment.merge!(
          'status' => 'appointed', 'identity' => identity, 'date' => '2026-08-25',
          'reference' => reference, 'artifact_sha256' => digest
        )
      end

      approval_scope = "Approve fixture-only #{status}/#{disposition} decision for #{requirement_id}."
      entry['approval'].merge!(
        'status' => 'recorded', 'identity' => owner_identity, 'authority_domain' => lead,
        'scope' => approval_scope, 'date' => '2026-08-25', 'reference' => nil,
        'artifact_sha256' => nil, 'conditions' => []
      )
    end
    refresh_batch_g_control_approval(requirement_id)
  end

  def refresh_batch_g_control_approval(requirement_id)
    register = JSON.parse(File.read(@batch_g_decision_register))
    entry = register['entries'].find { |candidate| candidate['requirement_id'] == requirement_id }
    prefix = requirement_id.downcase.tr('-', '_')
    manifest = {
      'requirement_id' => requirement_id,
      'decision_status' => entry.dig('decision', 'status'),
      'canonical_disposition' => entry.dig('decision', 'canonical_disposition'),
      'definition_sha256' => Digest::SHA256.hexdigest(JSON.generate(entry['definition_contract'])),
      'source_resolution_sha256s' => entry['source_dependencies'].map { |dependency| dependency['resolution_artifact_sha256'] },
      'intra_resolution_sha256s' => entry['intra_batch_dependencies'].map { |dependency| dependency['resolution_artifact_sha256'] },
      'reconciliation_sha256' => entry.dig('reconciliation_contract', 'receipt_artifact_sha256'),
      'output_boundary_sha256' => Digest::SHA256.hexdigest(JSON.generate(entry['output_boundary'])),
      'statutory_definition_sha256' => entry.dig('statutory_definition', 'authority_artifact_sha256')
    }
    manifest_sha = Digest::SHA256.hexdigest(JSON.generate(manifest))
    required_domains = entry['co_owners']
    bindings = required_domains.each_with_index.map do |domain, index|
      if domain == entry['lead_authority_domain']
        identity = entry.dig('accountable_owner', 'identity')
        appointment_reference = entry.dig('accountable_owner', 'appointment_reference')
        appointment_sha = entry.dig('accountable_owner', 'artifact_sha256')
      else
        appointment = entry['appointment_dependencies'].find { |candidate| candidate['authority_domain'] == domain }
        identity = appointment['identity']
        appointment_reference = appointment['reference']
        appointment_sha = appointment['artifact_sha256']
      end
      authority_approval = {
        'artifact_type' => ParityGovernanceValidator::BATCH_G_AUTHORITY_APPROVAL_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
        'requirement_id' => requirement_id, 'subject' => 'control_authority_approval',
        'authority_domain' => domain, 'identity' => identity,
        'control_manifest_sha256' => manifest_sha,
        'decision_status' => entry.dig('decision', 'status'),
        'canonical_disposition' => entry.dig('decision', 'canonical_disposition'),
        'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }
      approval_reference, approval_sha = write_json_artifact("#{prefix}_control_authority_#{index}.json", authority_approval, batch: 'G')
      {
        'authority_domain' => domain, 'identity' => identity,
        'appointment_reference' => appointment_reference, 'appointment_sha256' => appointment_sha,
        'approval_reference' => approval_reference, 'approval_sha256' => approval_sha
      }
    end
    approval_artifact = {
      'artifact_type' => ParityGovernanceValidator::GOVERNANCE_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
      'requirement_id' => requirement_id, 'subject' => 'approval',
      'identity' => entry.dig('accountable_owner', 'identity'),
      'authority_domain' => entry['lead_authority_domain'],
      'scope' => entry.dig('approval', 'scope'), 'date' => entry.dig('approval', 'date'),
      'decision_status' => entry.dig('decision', 'status'),
      'canonical_disposition' => entry.dig('decision', 'canonical_disposition'),
      'conditions' => entry.dig('approval', 'conditions'),
      'control_manifest_sha256' => manifest_sha,
      'definition_sha256' => manifest['definition_sha256'],
      'source_resolution_sha256s' => manifest['source_resolution_sha256s'],
      'intra_resolution_sha256s' => manifest['intra_resolution_sha256s'],
      'reconciliation_sha256' => manifest['reconciliation_sha256'],
      'output_boundary_sha256' => manifest['output_boundary_sha256'],
      'statutory_definition_sha256' => manifest['statutory_definition_sha256'],
      'authority_bindings' => bindings, 'reviewer' => valid_reviewer
    }
    approval_reference, approval_sha = write_json_artifact("#{prefix}_approval.json", approval_artifact, batch: 'G')
    entry['approval']['reference'] = approval_reference
    entry['approval']['artifact_sha256'] = approval_sha
    File.write(@batch_g_decision_register, JSON.pretty_generate(register) + "\n")
  end

  def prepare_fully_resolved_batch_g_entry(requirement_id)
    register = JSON.parse(File.read(@batch_g_decision_register))
    entry = register['entries'].find { |candidate| candidate['requirement_id'] == requirement_id }
    sources_by_batch = entry['source_dependencies'].group_by { |source| source['batch'] }
    sources_by_batch.each do |batch, dependencies|
      prepare_batch_f_upstream_sources(batch, dependencies.map { |dependency| dependency['requirement_id'] })
    end
    prepare_batch_g_governed_entry(
      requirement_id, status: 'approve', disposition: 'reproduce', target_kind: 'capability',
      target_reference: "fixture_#{requirement_id.downcase.tr('-', '_')}_read_only_projection"
    )

    source_register_paths = {
      'A' => @decision_register, 'B' => @batch_b_decision_register,
      'C' => @batch_c_decision_register, 'D' => @batch_d_decision_register,
      'E' => @batch_e_decision_register, 'F' => @batch_f_decision_register
    }
    source_register_ids = {
      'A' => ParityGovernanceValidator::BATCH_A_REGISTER_ID,
      'B' => ParityGovernanceValidator::BATCH_B_REGISTER_ID,
      'C' => ParityGovernanceValidator::BATCH_C_REGISTER_ID,
      'D' => ParityGovernanceValidator::BATCH_D_REGISTER_ID,
      'E' => ParityGovernanceValidator::BATCH_E_REGISTER_ID,
      'F' => ParityGovernanceValidator::BATCH_F_REGISTER_ID
    }
    mutate_batch_g_decision_register do |decision_register|
      resolved_entry = decision_register['entries'].find { |candidate| candidate['requirement_id'] == requirement_id }
      prefix = requirement_id.downcase.tr('-', '_')
      resolved_entry['source_dependencies'].each_with_index do |dependency, index|
        batch = dependency['batch']
        source_path = source_register_paths.fetch(batch)
        source_register = JSON.parse(File.read(source_path))
        source_entry = source_register['entries'].find { |candidate| candidate['requirement_id'] == dependency['requirement_id'] }
        owner_identity = source_entry.dig('accountable_owner', 'identity')
        artifact = {
          'artifact_type' => ParityGovernanceValidator::BATCH_G_SOURCE_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
          'requirement_id' => requirement_id, 'subject' => 'source_dependency',
          'source_batch' => batch, 'source_requirement_id' => dependency['requirement_id'],
          'source_entity' => dependency['source_entity'],
          'source_owner_authority' => dependency['source_owner_authority'],
          'source_register_id' => source_register_ids.fetch(batch),
          'source_register_sha256' => Digest::SHA256.file(source_path).hexdigest,
          'source_owner_identity' => owner_identity,
          'source_approval_reference' => source_entry.dig('approval', 'reference'),
          'source_approval_sha256' => source_entry.dig('approval', 'artifact_sha256'),
          'status' => 'resolved', 'date' => '2026-08-25', 'reviewer' => valid_reviewer
        }
        reference, digest = write_json_artifact("#{prefix}_source_resolution_#{index}.json", artifact, batch: 'G')
        dependency.merge!('status' => 'resolved', 'resolution_reference' => reference, 'resolution_artifact_sha256' => digest)
      end

      semantic_type = ParityGovernanceValidator::BATCH_G_ROW_SEMANTIC_TYPE.fetch(requirement_id)
      semantic = ParityGovernanceValidator::BATCH_G_ROW_SEMANTIC_SPECS.fetch(requirement_id)
      next unless semantic.fetch(:reconciliation)

      source_roles = semantic.fetch(:source_roles)
      snapshot_id = "SYN-G-SNAPSHOT-#{requirement_id}"
      definition_sha = Digest::SHA256.hexdigest(JSON.generate(resolved_entry['definition_contract']))
      parameter_values = resolved_entry.dig('definition_contract', 'parameters').to_h do |parameter|
        value = case parameter
                when 'period_start' then '2026-08-01'
                when 'period_end' then '2026-08-25'
                when 'organization_scope' then 'SYN-UEU'
                when 'authorized_cohort' then 'SYN-UEU'
                when 'definition_version' then resolved_entry.dig('definition_contract', 'definition_version')
                else semantic.fetch(:fixed_parameter_values).fetch(parameter, "fixture-#{parameter}")
                end
        [parameter, value]
      end
      parameter_sha = Digest::SHA256.hexdigest(JSON.generate(parameter_values))
      parameter_artifact = {
        'artifact_type' => ParityGovernanceValidator::BATCH_G_PARAMETER_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
        'requirement_id' => requirement_id, 'parameter_values' => parameter_values,
        'canonical_parameter_sha256' => parameter_sha, 'date' => '2026-08-25',
        'author_identity' => 'Fixture Batch G Parameter Author', 'reviewer' => valid_reviewer
      }
      parameter_reference, parameter_digest = write_json_artifact("#{prefix}_parameters.json", parameter_artifact, batch: 'G')
      descriptors = []
      source_exports = []
      resolved_entry['source_dependencies'].each_with_index do |dependency, index|
        measure = source_roles.fetch(index).fetch(:role) == 'authoritative_measure'
        row = {
          'synthetic_id' => "SYN-G-SRC-#{index}",
          'distinct_key' => "SYN-KEY-#{index}",
          'value' => measure ? 5 : 0,
          'state' => measure ? 'known_numeric' : 'numeric_zero',
          'included' => true, 'exclusion_reason' => nil,
          'source_version' => "fixture-source-v1/#{dependency['requirement_id']}"
        }
        source_export = {
          'artifact_type' => ParityGovernanceValidator::BATCH_G_SOURCE_EXPORT_ARTIFACT_TYPE,
          'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
          'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
          'requirement_id' => requirement_id, 'source_batch' => dependency['batch'],
          'source_requirement_id' => dependency['requirement_id'], 'source_entity' => dependency['source_entity'],
          'control_role' => measure ? 'authoritative_measure' : 'lineage_context',
          'snapshot_id' => snapshot_id, 'canonical_export_id' => "SYN-G-EXPORT-#{requirement_id}-#{index}",
          'rows' => [row], 'source_root_sha256' => Digest::SHA256.hexdigest(JSON.generate([row])),
          'author_identity' => 'Fixture Batch G Source Export Author', 'date' => '2026-08-25',
          'reviewer' => valid_reviewer
        }
        reference, digest = write_json_artifact("#{prefix}_source_export_#{index}.json", source_export, batch: 'G')
        descriptors << {
          'source_batch' => dependency['batch'], 'source_requirement_id' => dependency['requirement_id'],
          'source_entity' => dependency['source_entity'], 'control_role' => source_export['control_role'],
          'reference' => reference, 'sha256' => digest
        }
        source_exports << source_export
      end
      measure_total = source_exports.select { |source_export| source_export['control_role'] == 'authoritative_measure' }.sum { |source_export| source_export['rows'].sum { |row| row['value'] } }
      output_row = {
        'synthetic_id' => "SYN-G-OUT-#{requirement_id}", 'distinct_key' => 'SYN-REPORT-KEY-1',
        'value' => measure_total, 'state' => measure_total.zero? ? 'numeric_zero' : 'known_numeric',
        'included' => true, 'exclusion_reason' => nil, 'source_version' => 'rooted_source_aggregation:v1'
      }
      output = {
        'artifact_type' => ParityGovernanceValidator::BATCH_G_OUTPUT_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
        'requirement_id' => requirement_id, 'snapshot_id' => snapshot_id,
        'definition_sha256' => definition_sha, 'parameter_sha256' => parameter_sha,
        'rows' => [output_row], 'output_sha256' => Digest::SHA256.hexdigest(JSON.generate([output_row])),
        'watermark' => resolved_entry.dig('output_boundary', 'watermark'), 'exportable' => true,
        'access_scope' => %w[fixture_reporting_role SYN-UEU],
        'export_reason' => 'Fixture-only deterministic Batch G governance verification.',
        'audit_event_ids' => { 'view' => 'SYN-AUDIT-VIEW-1', 'run' => 'SYN-AUDIT-RUN-1', 'export' => 'SYN-AUDIT-EXPORT-1' },
        'retention_class' => 'synthetic_governance_evidence_pending_policy',
        'masked' => true, 'small_cell_suppression_applied' => true,
        'author_identity' => 'Fixture Batch G Output Author', 'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }
      output_reference, output_digest = write_json_artifact("#{prefix}_output.json", output, batch: 'G')
      rerun_artifact = {
        'artifact_type' => ParityGovernanceValidator::BATCH_G_RERUN_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
        'requirement_id' => requirement_id, 'execution_id' => "SYN-G-RERUN-#{requirement_id}-02",
        'definition_sha256' => definition_sha, 'parameter_sha256' => parameter_sha,
        'snapshot_id' => snapshot_id, 'source_roots' => source_exports.map { |source_export| source_export['source_root_sha256'] },
        'cutoff_at' => '2026-08-25T23:59:59+07:00', 'rows' => output['rows'],
        'output_sha256' => output['output_sha256'], 'author_identity' => 'Fixture Batch G Independent Rerun Author',
        'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }
      rerun_reference, rerun_digest = write_json_artifact("#{prefix}_rerun.json", rerun_artifact, batch: 'G')
      security = resolved_entry['appointment_dependencies'].find { |appointment| appointment['authority_domain'] == 'security_privacy_data' }
      access_payload = {
        'role' => 'synthetic_report_security_verifier', 'cohort_scope' => ['SYN-UEU'],
        'permitted_fields' => %w[synthetic_id distinct_key aggregate_value state source_version],
        'prohibited_fields' => %w[real_patient_identifier direct_identifier credential live_endpoint],
        'watermark' => output['watermark'], 'retention_class' => output['retention_class'],
        'masked' => output['masked'], 'small_cell_suppression_applied' => output['small_cell_suppression_applied']
      }
      access_artifact = {
        'artifact_type' => ParityGovernanceValidator::BATCH_G_ACCESS_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
        'requirement_id' => requirement_id, 'identity' => security['identity'],
        'export_reason' => output['export_reason'], 'output_sha256' => output['output_sha256'],
        'policy_sha256' => Digest::SHA256.hexdigest(JSON.generate(access_payload)),
        'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }.merge(access_payload)
      access_reference, access_digest = write_json_artifact("#{prefix}_access.json", access_artifact, batch: 'G')
      audit_events = %w[view run export].map do |event_type|
        {
          'event_type' => event_type, 'event_id' => output.dig('audit_event_ids', event_type),
          'actor_identity' => security['identity'], 'role' => access_artifact['role'],
          'cohort_scope' => access_artifact['cohort_scope'], 'occurred_at' => '2026-08-25T10:00:00+07:00',
          'outcome' => 'allowed', 'reason' => output['export_reason'], 'output_sha256' => output['output_sha256']
        }
      end
      audit_artifact = {
        'artifact_type' => ParityGovernanceValidator::BATCH_G_AUDIT_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
        'requirement_id' => requirement_id, 'output_sha256' => output['output_sha256'],
        'events' => audit_events, 'audit_root_sha256' => Digest::SHA256.hexdigest(JSON.generate(audit_events)),
        'author_identity' => 'Fixture Batch G Audit Author', 'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }
      audit_reference, audit_digest = write_json_artifact("#{prefix}_audit.json", audit_artifact, batch: 'G')
      measure_export = source_exports.find { |source_export| source_export['control_role'] == 'authoritative_measure' }
      control_values = {
        'authoritative_source_total' => measure_total, 'documented_exclusion_total' => 0,
        'approved_adjustment_total' => 0, 'report_total' => measure_total,
        'source_row_count' => source_exports.length, 'report_row_count' => 1,
        'distinct_key_count' => 1, 'duplicate_join_count' => 0,
        'traced_sample_count' => 1, 'missing_source_count' => 0, 'null_state_count' => 0
      }
      reconciliation = {
        'artifact_type' => ParityGovernanceValidator::BATCH_G_RECONCILIATION_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
        'requirement_id' => requirement_id, 'definition_sha256' => definition_sha,
        'parameter_descriptor' => { 'reference' => parameter_reference, 'sha256' => parameter_digest }, 'snapshot_id' => snapshot_id,
        'cutoff_at' => '2026-08-25T23:59:59+07:00', 'period_state' => 'closed',
        'prior_output_reference' => nil, 'prior_output_sha256' => nil, 'restatement_reason' => nil,
        'source_exports' => descriptors,
        'report_output' => { 'reference' => output_reference, 'sha256' => output_digest },
        'rerun_receipt' => { 'reference' => rerun_reference, 'sha256' => rerun_digest },
        'access_receipt' => { 'reference' => access_reference, 'sha256' => access_digest },
        'audit_receipt' => { 'reference' => audit_reference, 'sha256' => audit_digest },
        'control_values' => control_values,
        'equation' => ParityGovernanceValidator::BATCH_G_RECONCILIATION_EQUATION,
        'difference' => 0,
        'sampled_lineage' => [{
          'report_distinct_key' => output_row['distinct_key'],
          'source_batch' => measure_export['source_batch'],
          'source_requirement_id' => measure_export['source_requirement_id'],
          'source_distinct_key' => measure_export['rows'].first['distinct_key'],
          'source_root_sha256' => measure_export['source_root_sha256']
        }],
        'late_event_policy' => 'append_linked_restatement_never_overwrite_closed_output',
        'author_identity' => 'Fixture Batch G Reconciliation Author', 'reviewer' => valid_reviewer
      }
      reconciliation_reference, reconciliation_digest = write_json_artifact("#{prefix}_reconciliation.json", reconciliation, batch: 'G')
      resolved_entry['reconciliation_contract'].merge!(
        'status' => 'complete', 'receipt_reference' => reconciliation_reference,
        'receipt_artifact_sha256' => reconciliation_digest
      )
    end
    refresh_batch_g_control_approval(requirement_id)
  end

  def prepare_batch_g_deferred_statutory_entry(requirement_id)
    register = JSON.parse(File.read(@batch_g_decision_register))
    entry = register['entries'].find { |candidate| candidate['requirement_id'] == requirement_id }
    pending_sources = entry['source_dependencies'].map { |dependency| "missing_source:#{dependency['batch']}:#{dependency['requirement_id']}" }
    pending_intra = entry['intra_batch_dependencies'].map { |dependency| "missing_intra_g:#{dependency['requirement_id']}" }
    exclusions = [*ParityGovernanceValidator::BATCH_G_DEFERRAL_BASE_EXCLUSIONS, *pending_sources, *pending_intra]
    prepare_batch_g_governed_entry(
      requirement_id, status: 'defer', disposition: 'exclude', target_kind: 'exclusion',
      target_reference: 'legacy statutory label retained only as non-exportable synthetic proposal', exclusions: exclusions
    )
  end

  def complete_batch_g_statutory_definition(requirement_id)
    register = JSON.parse(File.read(@batch_g_decision_register))
    entry = register['entries'].find { |candidate| candidate['requirement_id'] == requirement_id }
    prefix = requirement_id.downcase.tr('-', '_')
    definition = {
      'standard_identifier' => "CURRENT-SYNTHETIC-STANDARD-#{requirement_id}",
      'standard_version' => '2026-fixture-v1',
      'effective_date' => '2026-08-25',
      'definition_source' => "FIXTURE-SIGNED-CURRENT-DEFINITION-#{requirement_id}"
    }
    frozen_definition = entry['definition_contract']
    semantic_type = ParityGovernanceValidator::BATCH_G_ROW_SEMANTIC_TYPE.fetch(requirement_id)
    semantic_spec = ParityGovernanceValidator::BATCH_G_ROW_SEMANTIC_SPECS.fetch(requirement_id)
    semantic_digest = Digest::SHA256.hexdigest(JSON.generate(semantic_spec))
    source_bindings = entry['source_dependencies'].map do |dependency|
      {
        'batch' => dependency['batch'], 'requirement_id' => dependency['requirement_id'],
        'source_entity' => dependency['source_entity'], 'source_owner_authority' => dependency['source_owner_authority'],
        'resolution_reference' => dependency['resolution_reference'],
        'resolution_sha256' => dependency['resolution_artifact_sha256']
      }
    end
    report_definition = {
      'artifact_type' => ParityGovernanceValidator::BATCH_G_SIGNED_REPORT_DEFINITION_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
      'requirement_id' => requirement_id, 'subject' => 'signed_current_report_definition',
      'semantic_digest' => semantic_digest,
      'definition_sha256' => Digest::SHA256.hexdigest(JSON.generate(frozen_definition)),
      'grain' => frozen_definition['grain'], 'distinct_key' => frozen_definition['distinct_key'],
      'numerator' => frozen_definition['numerator'], 'denominator' => frozen_definition['denominator'],
      'inclusions' => frozen_definition['inclusions'], 'exclusions' => frozen_definition['exclusions'],
      'parameters' => frozen_definition['parameters'], 'source_bindings' => source_bindings,
      'period_basis' => frozen_definition.slice('time_basis', 'timezone', 'cutoff_policy', 'period_close_policy'),
      'date' => '2026-08-25', 'author_identity' => 'Fixture Signed Report Definition Author',
      'reviewer' => valid_reviewer
    }.merge(definition)
    report_definition_reference, report_definition_sha = write_json_artifact("#{prefix}_signed_report_definition.json", report_definition, batch: 'G')
    domains = entry['co_owners']
    bindings = domains.each_with_index.map do |domain, index|
      appointment = entry['appointment_dependencies'].find { |candidate| candidate['authority_domain'] == domain }
      approval = {
        'artifact_type' => ParityGovernanceValidator::BATCH_G_STATUTORY_APPROVAL_ARTIFACT_TYPE,
        'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
        'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
        'requirement_id' => requirement_id, 'subject' => 'current_statutory_definition_approval',
        'authority_domain' => domain, 'identity' => appointment['identity'],
        'report_definition_sha256' => report_definition_sha,
        'date' => '2026-08-25', 'reviewer' => valid_reviewer
      }.merge(definition)
      reference, digest = write_json_artifact("#{prefix}_statutory_approval_#{index}.json", approval, batch: 'G')
      {
        'authority_domain' => domain, 'identity' => appointment['identity'],
        'appointment_reference' => appointment['reference'], 'appointment_sha256' => appointment['artifact_sha256'],
        'approval_reference' => reference, 'approval_sha256' => digest
      }
    end
    artifact = {
      'artifact_type' => ParityGovernanceValidator::BATCH_G_STATUTORY_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
      'requirement_id' => requirement_id, 'subject' => 'current_statutory_definition',
      'legacy_simulation_only' => true,
      'report_definition_reference' => report_definition_reference,
      'report_definition_sha256' => report_definition_sha,
      'authority_bindings' => bindings,
      'date' => '2026-08-25', 'author_identity' => 'Fixture Statutory Definition Custodian',
      'reviewer' => valid_reviewer
    }.merge(definition)
    reference, digest = write_json_artifact("#{prefix}_statutory_definition.json", artifact, batch: 'G')
    entry['statutory_definition'].merge!(
      'status' => 'complete', 'standard_identifier' => definition['standard_identifier'],
      'standard_version' => definition['standard_version'], 'effective_date' => definition['effective_date'],
      'definition_source' => definition['definition_source'], 'authority_reference' => reference,
      'authority_artifact_sha256' => digest
    )
    File.write(@batch_g_decision_register, JSON.pretty_generate(register) + "\n")
    refresh_batch_g_control_approval(requirement_id)
  end

  def prepare_batch_g_coherent_candidate_g_c01
    prepare_batch_g_governed_entry(
      'PAR-RPT-001', status: 'approve', disposition: 'reproduce', target_kind: 'capability',
      target_reference: 'fixture_parameterized_clinical_report_projection'
    )
    prepare_batch_g_governed_entry(
      'PAR-RPT-002', status: 'approve', disposition: 'consolidate', target_kind: 'consolidation_target',
      target_reference: 'PAR-RPT-001'
    )
    register = JSON.parse(File.read(@batch_g_decision_register))
    target = register['entries'].find { |entry| entry['requirement_id'] == 'PAR-RPT-001' }
    members = ParityGovernanceValidator::BATCH_G_CONSOLIDATION_GROUPS.fetch('G-C01')
    parameter_policy = ParityGovernanceValidator::BATCH_G_CONSOLIDATION_PARAMETER_POLICIES.fetch('G-C01')
    member_mappings = members.to_h do |member|
      member_entry = register['entries'].find { |entry| entry['requirement_id'] == member }
      [member, {
        'semantic_parameter_values' => parameter_policy.fetch('member_values').fetch(member),
        'definition_sha256' => Digest::SHA256.hexdigest(JSON.generate(member_entry['definition_contract'])),
        'source_requirement_ids' => member_entry['source_dependencies'].map { |source| source['requirement_id'] },
        'mapped_control_totals' => ParityGovernanceValidator::BATCH_G_CONTROL_TOTALS,
        'authority_domains' => member_entry['co_owners'],
        'approval_reference' => member_entry.dig('approval', 'reference'),
        'approval_sha256' => member_entry.dig('approval', 'artifact_sha256')
      }]
    end
    artifact = {
      'artifact_type' => ParityGovernanceValidator::BATCH_G_CONSOLIDATION_ARTIFACT_TYPE,
      'schema_version' => ParityGovernanceValidator::ARTIFACT_SCHEMA_VERSION,
      'register_id' => ParityGovernanceValidator::BATCH_G_REGISTER_ID,
      'candidate_id' => 'G-C01', 'members' => members, 'target_requirement_id' => 'PAR-RPT-001',
      'terminal_owner_identity' => target.dig('accountable_owner', 'identity'),
      'terminal_authority_domain' => target['lead_authority_domain'],
      'terminal_approval_reference' => target.dig('approval', 'reference'),
      'terminal_approval_sha256' => target.dig('approval', 'artifact_sha256'),
      'declared_parameters' => parameter_policy.fetch('declared_parameters'),
      'member_mappings' => member_mappings,
      'date' => '2026-08-25', 'author_identity' => 'Fixture Batch G Mapping Author', 'reviewer' => valid_reviewer
    }
    reference, digest = write_json_artifact('g_c01_shared_mapping.json', artifact, batch: 'G')
    mutate_batch_g_decision_register do |decision_register|
      members.each do |requirement_id|
        control = decision_register['entries'].find { |entry| entry['requirement_id'] == requirement_id }['consolidation_mapping']
        control.merge!(
          'status' => 'complete', 'terminal_target_requirement_id' => 'PAR-RPT-001',
          'artifact_reference' => reference, 'artifact_sha256' => digest
        )
      end
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
    directory_name = {
      'A' => ParityGovernanceValidator::BATCH_A_EVIDENCE_DIRECTORY,
      'B' => ParityGovernanceValidator::BATCH_B_EVIDENCE_DIRECTORY,
      'C' => ParityGovernanceValidator::BATCH_C_EVIDENCE_DIRECTORY,
      'D' => ParityGovernanceValidator::BATCH_D_EVIDENCE_DIRECTORY,
      'E' => ParityGovernanceValidator::BATCH_E_EVIDENCE_DIRECTORY,
      'F' => ParityGovernanceValidator::BATCH_F_EVIDENCE_DIRECTORY,
      'G' => ParityGovernanceValidator::BATCH_G_EVIDENCE_DIRECTORY
    }.fetch(batch)
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
