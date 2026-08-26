# frozen_string_literal: true

require 'digest'
require 'fileutils'
require 'minitest/autorun'
require 'tmpdir'

require_relative '../../scripts/generate-g0-owner-governance-snapshot'

class G0OwnerGovernanceSnapshotGeneratorTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PHASE = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0')

  def setup
    @tmpdir = Dir.mktmpdir('g0-owner-generator')
  end

  def teardown
    FileUtils.remove_entry(@tmpdir)
  end

  def test_generator_preserves_arrays_regenerates_dependent_hashes_and_verifies_bundle
    output = File.join(@tmpdir, 'candidate')
    manifest = G0OwnerGovernanceSnapshotGenerator.generate(generator_options(output))

    assert_empty G0OwnerGovernanceSnapshotGenerator.verify_bundle(output, generator_options(output))
    policy_path = File.join(output, 'G0_OWNER_AUTHORITY_POLICY_2026-08-25.json')
    appointment_path = File.join(output, 'G0_OWNER_APPOINTMENT_REGISTER_2026-08-25.json')
    session_path = File.join(output, 'G0_DECISION_SESSION_REGISTER_2026-08-25.json')
    policy = JSON.parse(File.read(policy_path))
    appointments = JSON.parse(File.read(appointment_path))
    sessions = JSON.parse(File.read(session_path))
    canonical_appointments = JSON.parse(File.read(File.join(PHASE, 'G0_OWNER_APPOINTMENT_REGISTER_2026-08-25.json')))
    canonical_sessions = JSON.parse(File.read(File.join(PHASE, 'G0_DECISION_SESSION_REGISTER_2026-08-25.json')))

    assert_equal canonical_appointments['appointments'], appointments['appointments']
    assert_equal canonical_appointments['events'], appointments['events']
    assert_equal canonical_sessions['sessions'], sessions['sessions']
    assert_equal Digest::SHA256.file(policy_path).hexdigest, appointments['policy_sha256']
    assert_equal Digest::SHA256.file(policy_path).hexdigest, sessions['policy_sha256']
    assert_equal Digest::SHA256.file(appointment_path).hexdigest, sessions['appointment_register_sha256']
    assert_equal policy['source_decision_registers'], appointments['source_decision_registers']
    assert_equal policy['source_decision_registers'], sessions['source_decision_registers']
    assert_equal G0OwnerGovernanceSnapshotGenerator::FILE_ROLES, manifest['files'].map { |entry| entry['role'] }
  end

  def test_generator_rejects_changed_live_bytes_with_reused_snapshot_identity
    copied = copy_source_inputs
    File.open(copied.fetch(:decision_register), 'a') { |file| file.write("\n") }
    options = generator_options(File.join(@tmpdir, 'stale-candidate')).merge(copied)

    error = assert_raises(ArgumentError) { G0OwnerGovernanceSnapshotGenerator.generate(options) }

    assert_includes error.message, 'Batch A live bytes changed but snapshot identity metadata was reused'
    refute File.exist?(options[:output])
  end

  def test_generator_refuses_existing_output_directory_even_when_empty
    output = File.join(@tmpdir, 'already-exists')
    Dir.mkdir(output)

    error = assert_raises(ArgumentError) do
      G0OwnerGovernanceSnapshotGenerator.generate(generator_options(output))
    end

    assert_includes error.message, 'output directory already exists'
  end

  def test_bundle_verifier_rejects_hash_mutation_and_secret_material
    output = File.join(@tmpdir, 'candidate')
    G0OwnerGovernanceSnapshotGenerator.generate(generator_options(output))
    policy_path = File.join(output, 'G0_OWNER_AUTHORITY_POLICY_2026-08-25.json')
    File.open(policy_path, 'a') { |file| file.write("\n") }

    errors = G0OwnerGovernanceSnapshotGenerator.verify_bundle(output, generator_options(output))
    assert errors.any? { |error| error.include?('SHA-256 does not match candidate bytes') }

    policy = JSON.parse(File.read(policy_path))
    policy['approval']['reference'] = 'authorization=Basic synthetic-secret'
    File.write(policy_path, JSON.pretty_generate(policy) + "\n")
    manifest_path = File.join(output, G0OwnerGovernanceSnapshotGenerator::MANIFEST_NAME)
    manifest = JSON.parse(File.read(manifest_path))
    manifest['files'].find { |entry| entry['role'] == 'owner_authority_policy' }['sha256'] = Digest::SHA256.file(policy_path).hexdigest
    File.write(manifest_path, JSON.pretty_generate(manifest) + "\n")

    errors = G0OwnerGovernanceSnapshotGenerator.verify_bundle(output, generator_options(output))
    assert errors.any? { |error| error.include?('must not contain credentials, secrets or private keys') }
  end

  def test_bundle_verifier_requires_context_and_rejects_unmanifested_private_key_file
    output = File.join(@tmpdir, 'candidate')
    G0OwnerGovernanceSnapshotGenerator.generate(generator_options(output))

    assert_equal ['candidate bundle verification requires explicit authoritative matrix/baseline/A-G/identity/evidence context'], G0OwnerGovernanceSnapshotGenerator.verify_bundle(output)
    File.write(File.join(output, 'unmanifested-private-key.pem'), "-----BEGIN PRIVATE KEY-----\nsynthetic-only-test\n-----END PRIVATE KEY-----\n")

    errors = G0OwnerGovernanceSnapshotGenerator.verify_bundle(output, generator_options(output))

    assert errors.any? { |error| error.include?('directory inventory must be exactly the manifest plus its four listed files') }
  end

  def test_bundle_verifier_rejects_nested_appointment_secret_after_all_hashes_are_recomputed
    output = File.join(@tmpdir, 'candidate')
    G0OwnerGovernanceSnapshotGenerator.generate(generator_options(output))
    appointment_path = File.join(output, 'G0_OWNER_APPOINTMENT_REGISTER_2026-08-25.json')
    session_path = File.join(output, 'G0_DECISION_SESSION_REGISTER_2026-08-25.json')
    manifest_path = File.join(output, G0OwnerGovernanceSnapshotGenerator::MANIFEST_NAME)
    appointments = JSON.parse(File.read(appointment_path))
    appointments.fetch('appointments') << { 'client_secret' => 'synthetic-malicious-secret' }
    File.write(appointment_path, JSON.pretty_generate(appointments) + "\n")
    sessions = JSON.parse(File.read(session_path))
    sessions['appointment_register_sha256'] = Digest::SHA256.file(appointment_path).hexdigest
    File.write(session_path, JSON.pretty_generate(sessions) + "\n")
    manifest = JSON.parse(File.read(manifest_path))
    manifest.fetch('files').find { |entry| entry['role'] == 'owner_appointment_register' }['sha256'] = Digest::SHA256.file(appointment_path).hexdigest
    manifest.fetch('files').find { |entry| entry['role'] == 'decision_session_register' }['sha256'] = Digest::SHA256.file(session_path).hexdigest
    File.write(manifest_path, JSON.pretty_generate(manifest) + "\n")

    errors = G0OwnerGovernanceSnapshotGenerator.verify_bundle(output, generator_options(output))

    assert errors.any? { |error| error.include?('fails the core secret boundary') }
    assert errors.any? { |error| error.include?('candidate core validation: owner appointment register: must not contain credentials, secrets or private keys') }
  end

  def test_generator_sources_remain_ruby_2_6_syntax_compatible
    generator = File.join(ROOT, 'scripts/generate-g0-owner-governance-snapshot.rb')
    validator = File.join(ROOT, 'scripts/validate-parity-governance.rb')

    assert system(RbConfig.ruby, '-c', generator, out: File::NULL, err: File::NULL)
    assert system(RbConfig.ruby, '-c', validator, out: File::NULL, err: File::NULL)
    refute_match(/\b(?:then|in)\s+=>/, File.read(generator))
    refute_match(/\.\.\//, File.read(generator))
  end

  private

  def generator_options(output)
    {
      output: output,
      matrix: File.join(ROOT, 'docs/new-simrs-rebuild/PARITY_REQUIREMENTS_MATRIX.md'),
      baseline: File.join(PHASE, 'PARITY_MATRIX_BASELINE.json'),
      batch_manifest: File.join(PHASE, 'G0_PARITY_BATCH_MANIFEST.json'),
      decision_register: File.join(PHASE, 'G0_BATCH_A_DECISION_REGISTER_2026-08-25.json'),
      batch_b_decision_register: File.join(PHASE, 'G0_BATCH_B_DECISION_REGISTER_2026-08-25.json'),
      batch_c_decision_register: File.join(PHASE, 'G0_BATCH_C_DECISION_REGISTER_2026-08-25.json'),
      batch_d_decision_register: File.join(PHASE, 'G0_BATCH_D_DECISION_REGISTER_2026-08-25.json'),
      batch_e_decision_register: File.join(PHASE, 'G0_BATCH_E_DECISION_REGISTER_2026-08-25.json'),
      batch_f_decision_register: File.join(PHASE, 'G0_BATCH_F_DECISION_REGISTER_2026-08-25.json'),
      batch_g_decision_register: File.join(PHASE, 'G0_BATCH_G_DECISION_REGISTER_2026-08-25.json'),
      institutional_identity_key_registry: File.join(PHASE, 'G0_INSTITUTIONAL_IDENTITY_KEY_REGISTRY_2026-08-26.json'),
      trusted_identity_root_sha256: nil,
      owner_evidence_root: File.join(PHASE, ParityGovernanceValidator::OWNER_EVIDENCE_DIRECTORY),
      owner_snapshot_plan: File.join(PHASE, 'G0_OWNER_GOVERNANCE_SNAPSHOT_PLAN_2026-08-25.json'),
      owner_authority_policy: File.join(PHASE, 'G0_OWNER_AUTHORITY_POLICY_2026-08-25.json'),
      owner_appointment_register: File.join(PHASE, 'G0_OWNER_APPOINTMENT_REGISTER_2026-08-25.json'),
      decision_session_register: File.join(PHASE, 'G0_DECISION_SESSION_REGISTER_2026-08-25.json'),
      release_index: File.join(PHASE, 'RELEASE_EVIDENCE_INDEX.md')
    }
  end

  def copy_source_inputs
    mapping = {
      decision_register: 'G0_BATCH_A_DECISION_REGISTER_2026-08-25.json',
      batch_b_decision_register: 'G0_BATCH_B_DECISION_REGISTER_2026-08-25.json',
      batch_c_decision_register: 'G0_BATCH_C_DECISION_REGISTER_2026-08-25.json',
      batch_d_decision_register: 'G0_BATCH_D_DECISION_REGISTER_2026-08-25.json',
      batch_e_decision_register: 'G0_BATCH_E_DECISION_REGISTER_2026-08-25.json',
      batch_f_decision_register: 'G0_BATCH_F_DECISION_REGISTER_2026-08-25.json',
      batch_g_decision_register: 'G0_BATCH_G_DECISION_REGISTER_2026-08-25.json'
    }
    mapping.to_h do |key, basename|
      destination = File.join(@tmpdir, basename)
      FileUtils.cp(File.join(PHASE, basename), destination)
      [key, destination]
    end
  end
end
