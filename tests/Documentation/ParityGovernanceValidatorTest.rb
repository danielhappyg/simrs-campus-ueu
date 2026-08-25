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
    @release_index = File.join(@tmpdir, 'release-index.md')
    FileUtils.cp(SOURCE_MATRIX, @matrix)
    FileUtils.cp(SOURCE_BASELINE, @baseline)
    FileUtils.cp(SOURCE_BATCH_MANIFEST, @batch_manifest)
    FileUtils.cp(SOURCE_BATCH_A_DECISION_REGISTER, @decision_register)
    FileUtils.cp(SOURCE_BATCH_B_DECISION_REGISTER, @batch_b_decision_register)
    FileUtils.cp(SOURCE_BATCH_C_DECISION_REGISTER, @batch_c_decision_register)
    FileUtils.cp(SOURCE_BATCH_D_DECISION_REGISTER, @batch_d_decision_register)
    FileUtils.cp(SOURCE_BATCH_E_DECISION_REGISTER, @batch_e_decision_register)
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
    assert_equal 114, validator.decision_entries.length
    assert_equal 20, validator.decision_entries_by_batch.fetch('A').length
    assert_equal 8, validator.decision_entries_by_batch.fetch('B').length
    assert_equal 19, validator.decision_entries_by_batch.fetch('C').length
    assert_equal 19, validator.decision_entries_by_batch.fetch('D').length
    assert_equal 48, validator.decision_entries_by_batch.fetch('E').length
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
      'E' => ParityGovernanceValidator::BATCH_E_EVIDENCE_DIRECTORY
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
