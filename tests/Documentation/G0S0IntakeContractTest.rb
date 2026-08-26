# frozen_string_literal: true

require 'fileutils'
require 'base64'
require 'json'
require 'minitest/autorun'
require 'openssl'
require 'tmpdir'

require_relative '../../scripts/validate-g0-s0-intake'

class G0S0IntakeContractTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PHASE_ZERO = 'docs/new-simrs-rebuild/phase-0'
  FIXTURE_FILES = [
    G0S0IntakeValidator::INTAKE_FILE,
    G0S0IntakeValidator::MANIFEST_FILE,
    G0S0IntakeValidator::BATCH_A_FILE,
    G0S0IntakeValidator::SNAPSHOT_PLAN_FILE,
    G0S0IntakeValidator::POLICY_FILE,
    G0S0IntakeValidator::IDENTITY_FILE,
    G0S0IntakeValidator::APPOINTMENT_FILE,
    G0S0IntakeValidator::SESSION_FILE
  ].freeze

  def setup
    @tmpdir = Dir.mktmpdir('g0-s0-intake-contract')
    @phase_zero = File.join(@tmpdir, PHASE_ZERO)
    FileUtils.mkdir_p(@phase_zero)
    FIXTURE_FILES.each do |filename|
      FileUtils.cp(File.join(ROOT, PHASE_ZERO, filename), File.join(@phase_zero, filename))
    end
  end

  def teardown
    FileUtils.remove_entry(@tmpdir)
  end

  def test_current_empty_intake_passes_integrity
    validator = fixture_validator

    assert validator.validate_integrity, validator.errors.join("\n")
    assert_match(/PASS G0\/S0 intake integrity/, validator.summary)
    assert_empty validator.errors
  end

  def test_stale_manifest_hash_fails_closed
    File.open(fixture_path(G0S0IntakeValidator::MANIFEST_FILE), 'a') { |file| file.write("\n") }
    validator = fixture_validator

    refute validator.validate_integrity
    assert_error validator, 'manifest: actual SHA-256 expected'
  end

  def test_stale_snapshot_plan_hash_fails_closed
    File.open(fixture_path(G0S0IntakeValidator::SNAPSHOT_PLAN_FILE), 'a') { |file| file.write("\n") }
    validator = fixture_validator

    refute validator.validate_integrity
    assert_error validator, 'owner governance snapshot plan: actual SHA-256 expected'
  end

  def test_duplicate_machine_binding_key_fails_closed
    mutate_intake do |content|
      content.sub('"selected_disposition": null,', "\"selected_disposition\": \"replace\",\n  \"selected_disposition\": null,")
    end
    validator = fixture_validator

    refute validator.validate_integrity
    assert_error validator, 'duplicate JSON object key "selected_disposition"'
  end

  def test_selected_checkbox_is_rejected_while_machine_decision_is_pending
    mutate_intake do |content|
      content.sub('- [ ] `approve + replace`', '- [x] `approve + replace`')
    end
    validator = fixture_validator

    refute validator.validate_integrity
    assert_error validator, 'all decision/readiness checkboxes must remain unchecked'
  end

  def test_required_seat_drift_is_rejected
    mutate_binding do |binding|
      binding['source_required_authority_domains'] = %w[product_delivery operations finance_accounting]
    end
    validator = fixture_validator

    refute validator.validate_integrity
    assert_error validator, 'required seat set drifted'
  end

  def test_nested_manifest_binding_rejects_unknown_key
    mutate_binding { |binding| binding['manifest']['unexpected'] = 'bypass' }
    validator = fixture_validator

    refute validator.validate_integrity
    assert_error validator, 'intake binding manifest: closed field set changed'
  end

  def test_nested_binding_scalar_fails_closed_without_crashing
    mutate_binding { |binding| binding['manifest'] = 'not-an-object' }
    validator = fixture_validator

    refute validator.validate_integrity
    assert_error validator, 'intake binding manifest: must be an object'
  end

  def test_nested_signing_profile_rejects_unknown_key
    mutate_binding { |binding| binding['candidate_signing_profiles']['reproduce']['unexpected'] = true }
    validator = fixture_validator

    refute validator.validate_integrity
    assert_error validator, 'intake binding reproduce profile: closed field set changed'
  end

  def test_nested_role_row_rejects_unknown_key
    mutate_binding { |binding| binding['required_role_rows'][0]['unexpected'] = true }
    validator = fixture_validator

    refute validator.validate_integrity
    assert_error validator, 'intake binding required role rows[0]: closed field set changed'
  end

  def test_private_key_material_is_rejected
    mutate_intake do |content|
      content + "\n-----BEGIN PRIVATE KEY-----\nfixture-secret\n-----END PRIVATE KEY-----\n"
    end
    validator = fixture_validator

    refute validator.validate_integrity
    assert_error validator, 'private-key PEM material is forbidden'
  end

  def test_basic_authorization_and_cookie_material_are_rejected
    mutate_intake do |content|
      content + "\nAuthorization: Basic dXNlcjpwYXNzd29yZA==\nCookie: session=supersecretvalue\n"
    end
    validator = fixture_validator

    refute validator.validate_integrity
    assert_error validator, 'Basic authorization material is forbidden'
    assert_error validator, 'Cookie material is forbidden'
  end

  def test_assignment_style_authorization_and_cookie_material_are_rejected
    mutate_intake do |content|
      content + "\nauthorization = Basic dXNlcjpwYXNzd29yZA==\ncookie = session=supersecretvalue\n"
    end
    validator = fixture_validator

    refute validator.validate_integrity
    assert_error validator, 'populated credential or secret assignment is forbidden'
  end

  def test_generic_secret_assignment_variants_are_rejected
    variants = %w[secret secret_value private_key private_key_material recovery recovery_phrase signing HMAC credentials credentials_json]
    original = File.read(fixture_path(G0S0IntakeValidator::INTAKE_FILE))

    variants.each do |label|
      File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), "#{original}\n#{label} = fixture-sensitive-value\n")
      validator = fixture_validator

      refute validator.validate_integrity, "Expected #{label.inspect} assignment to fail closed"
      assert_error validator, 'populated credential or secret assignment is forbidden'
    end
  ensure
    File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), original) if original
  end

  def test_quoted_json_and_yaml_secret_keys_are_rejected
    assignments = [
      '{"private_key":"fixture-sensitive-value"}',
      '{ "private_key" : "fixture-sensitive-value" }',
      "'private_key' : 'fixture-sensitive-value'",
      '"credentials"  :  "fixture-sensitive-value"'
    ]
    original = File.read(fixture_path(G0S0IntakeValidator::INTAKE_FILE))

    assignments.each do |assignment|
      File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), "#{original}\n#{assignment}\n")
      validator = fixture_validator

      refute validator.validate_integrity, "Expected quoted assignment #{assignment.inspect} to fail closed"
      assert_error validator, 'populated credential or secret assignment is forbidden'
    end
  ensure
    File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), original) if original
  end

  def test_markdown_wrapped_secret_assignments_are_rejected
    assignments = [
      '`private_key` = `fixture-sensitive-value`',
      '**private_key** = fixture-sensitive-value',
      '**credentials** : **fixture-sensitive-value**'
    ]
    original = File.read(fixture_path(G0S0IntakeValidator::INTAKE_FILE))

    assignments.each do |assignment|
      File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), "#{original}\n#{assignment}\n")
      validator = fixture_validator

      refute validator.validate_integrity, "Expected Markdown assignment #{assignment.inspect} to fail closed"
      assert_error validator, 'populated credential or secret assignment is forbidden'
    end
  ensure
    File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), original) if original
  end

  def test_generic_secret_table_label_variants_are_rejected
    variants = ['Secret', 'Secret Value', 'Private-Key', 'Private Key (PEM)', 'Recovery Material', 'Signing Key', 'HMAC', 'Credentials']
    original = File.read(fixture_path(G0S0IntakeValidator::INTAKE_FILE))

    variants.each do |label|
      File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), "#{original}\n| #{label} | fixture-sensitive-value |\n")
      validator = fixture_validator

      refute validator.validate_integrity, "Expected #{label.inspect} table value to fail closed"
      assert_error validator, 'populated credential or secret assignment is forbidden'
    end
  ensure
    File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), original) if original
  end

  def test_generic_secret_placeholders_remain_allowed
    mutate_intake do |content|
      content + "\nsecret = none\nprivate_key = forbidden\nrecovery = tidak ada\n| Credentials | ____________________ |\n"
    end
    validator = fixture_validator

    assert validator.validate_integrity, validator.errors.join("\n")
  end

  def test_private_der_base64_in_public_spki_cell_is_rejected
    set_public_spki(Base64.strict_encode64(test_rsa_key.to_der))
    validator = fixture_validator

    refute validator.validate_integrity
    assert_error validator, 'must contain canonical public-only RSA SPKI'
  end

  def test_valid_public_spki_is_accepted
    set_public_spki(Base64.strict_encode64(test_rsa_key.public_key.to_der))
    validator = fixture_validator

    assert validator.validate_integrity, validator.errors.join("\n")
  end

  def test_conditional_replace_human_instructions_cannot_drift_from_machine_profile
    mutate_intake do |content|
      content.sub('total empat kursi keputusan', 'total tiga kursi keputusan')
    end
    validator = fixture_validator

    refute validator.validate_integrity
    assert_error validator, 'conditional reproduce/replace human instructions drifted'
  end

  def test_synthetic_only_and_no_transmission_prose_cannot_be_reversed
    mutate_intake do |content|
      content.sub(
        '- Tidak ada koneksi atau transmisi live ke BPJS, VClaim, SATUSEHAT, perangkat produksi, sertifikat TTE produksi, atau endpoint eksternal lain.',
        '- Koneksi dan transmisi live ke BPJS, VClaim, dan SATUSEHAT diizinkan.'
      )
    end
    validator = fixture_validator

    refute validator.validate_integrity
    assert_error validator, 'synthetic-only/no-transmission or separation instructions drifted'
  end

  def test_distinct_people_separation_prose_cannot_be_weakened
    mutate_intake do |content|
      content.sub('Tujuh baris dasar berikut harus diisi oleh tujuh orang berbeda.', 'Tujuh baris dasar boleh diisi oleh satu orang.')
    end
    validator = fixture_validator

    refute validator.validate_integrity
    assert_error validator, 'synthetic-only/no-transmission or separation instructions drifted'
  end

  def test_additive_live_integration_and_role_concentration_contradictions_are_rejected
    mutate_intake do |content|
      content + "\nKoneksi dan transmisi live ke BPJS/VClaim/SATUSEHAT diizinkan.\nTujuh peran dasar boleh dipegang satu orang.\n"
    end
    validator = fixture_validator

    refute validator.validate_integrity
    assert_error validator, 'live BPJS/VClaim/SATUSEHAT assertion is not an unambiguous prohibition or explicit future-decision gate'
    assert_error validator, 'affirmative concentration of required institutional roles is forbidden'
  end

  def test_affirmative_live_integration_variants_are_rejected
    claims = [
      'Live BPJS diizinkan sekarang.',
      'VClaim boleh diaktifkan untuk transmisi live.',
      'SATUSEHAT enabled untuk transmisi live.',
      'BPJS live siap digunakan sekarang.',
      'BPJS live siap dipakai sekarang.'
    ]
    original = File.read(fixture_path(G0S0IntakeValidator::INTAKE_FILE))

    claims.each do |claim|
      File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), "#{original}\n#{claim}\n")
      validator = fixture_validator

      refute validator.validate_integrity, "Expected unsafe claim #{claim.inspect} to fail closed"
      assert_error validator, 'live BPJS/VClaim/SATUSEHAT assertion is not an unambiguous prohibition or explicit future-decision gate'
    end
  ensure
    File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), original) if original
  end

  def test_safe_live_integration_negations_and_future_gate_are_allowed
    claims = [
      'Live BPJS tidak diizinkan.',
      'VClaim tetap disabled dan tidak boleh diaktifkan.',
      'SATUSEHAT hanya boleh diaktifkan setelah keputusan baru yang eksplisit.',
      'BPJS hanya boleh digunakan setelah keputusan baru yang eksplisit.'
    ]
    original = File.read(fixture_path(G0S0IntakeValidator::INTAKE_FILE))

    claims.each do |claim|
      File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), "#{original}\n#{claim}\n")
      validator = fixture_validator

      assert validator.validate_integrity, "Expected safe claim #{claim.inspect} to pass:\n#{validator.errors.join("\n")}"
    end
  ensure
    File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), original) if original
  end

  def test_unclassified_live_integration_current_state_claims_fail_closed
    claims = [
      'BPJS live tersedia sekarang.',
      'VClaim live sudah berjalan.',
      'SATUSEHAT live siap beroperasi.'
    ]
    original = File.read(fixture_path(G0S0IntakeValidator::INTAKE_FILE))

    claims.each do |claim|
      File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), "#{original}\n#{claim}\n")
      validator = fixture_validator

      refute validator.validate_integrity, "Expected unclassified live claim #{claim.inspect} to fail closed"
      assert_error validator, 'live BPJS/VClaim/SATUSEHAT assertion is not an unambiguous prohibition or explicit future-decision gate'
    end
  ensure
    File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), original) if original
  end

  def test_live_integration_explicit_future_decision_gates_are_allowed
    claims = [
      'BPJS live hanya boleh diaktifkan setelah keputusan baru yang eksplisit.',
      'VClaim live may only be enabled after an explicit new decision.',
      'SATUSEHAT live requires an explicit new decision before activation.'
    ]
    original = File.read(fixture_path(G0S0IntakeValidator::INTAKE_FILE))

    claims.each do |claim|
      File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), "#{original}\n#{claim}\n")
      validator = fixture_validator

      assert validator.validate_integrity, "Expected future gate #{claim.inspect} to pass:\n#{validator.errors.join("\n")}"
    end
  ensure
    File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), original) if original
  end

  def test_each_mixed_live_integration_assertion_is_evaluated_independently
    claims = [
      'BPJS live tidak dilarang dan aktif.',
      'BPJS live tidak aktif, VClaim live aktif.',
      'BPJS tidak memiliki sandbox, tetapi live aktif.',
      'VClaim live is not forbidden.'
    ]
    original = File.read(fixture_path(G0S0IntakeValidator::INTAKE_FILE))

    claims.each do |claim|
      File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), "#{original}\n#{claim}\n")
      validator = fixture_validator

      refute validator.validate_integrity, "Expected mixed assertion #{claim.inspect} to fail closed"
      assert_error validator, 'live BPJS/VClaim/SATUSEHAT assertion is not an unambiguous prohibition or explicit future-decision gate'
    end
  ensure
    File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), original) if original
  end

  def test_negated_live_integration_restrictions_are_rejected
    claims = [
      'BPJS live belum dilarang.',
      'VClaim live jangan dinonaktifkan.',
      'SATUSEHAT live must not be disabled.',
      'BPJS live tidak boleh dilarang.',
      'VClaim live bukan nonaktif.',
      'SATUSEHAT live should not be prohibited.',
      'BPJS live must never be disabled.'
    ]
    original = File.read(fixture_path(G0S0IntakeValidator::INTAKE_FILE))

    claims.each do |claim|
      File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), "#{original}\n#{claim}\n")
      validator = fixture_validator

      refute validator.validate_integrity, "Expected negated restriction #{claim.inspect} to fail closed"
      assert_error validator, 'live BPJS/VClaim/SATUSEHAT assertion is not an unambiguous prohibition or explicit future-decision gate'
    end
  ensure
    File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), original) if original
  end

  def test_indonesian_and_english_live_integration_prohibitions_are_allowed
    claims = [
      'BPJS live jangan dipakai sekarang.',
      'VClaim live is not allowed.',
      'SATUSEHAT live must not be enabled.',
      'BPJS live tidak aktif.',
      'VClaim live dilarang.',
      'BPJS live tidak aktif, VClaim live tidak aktif.'
    ]
    original = File.read(fixture_path(G0S0IntakeValidator::INTAKE_FILE))

    claims.each do |claim|
      File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), "#{original}\n#{claim}\n")
      validator = fixture_validator

      assert validator.validate_integrity, "Expected prohibition #{claim.inspect} to pass:\n#{validator.errors.join("\n")}"
    end
  ensure
    File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), original) if original
  end

  def test_affirmative_role_concentration_variants_are_rejected
    claims = [
      'Tujuh peran dasar boleh dipegang satu orang.',
      '`product_delivery` dan `operations` boleh dipegang orang yang sama.',
      'Pemegang kursi dasar tidak harus berbeda.',
      'Chair dan facilitator boleh dipegang orang yang sama.',
      'Ketujuh peran boleh dipegang satu orang.',
      'Semua fungsi dasar dapat dirangkap oleh satu orang.'
    ]
    original = File.read(fixture_path(G0S0IntakeValidator::INTAKE_FILE))

    claims.each do |claim|
      File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), "#{original}\n#{claim}\n")
      validator = fixture_validator

      refute validator.validate_integrity, "Expected unsafe claim #{claim.inspect} to fail closed"
      assert_error validator, 'affirmative concentration of required institutional roles is forbidden'
    end
  ensure
    File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), original) if original
  end

  def test_safe_role_separation_negations_and_strengthening_are_allowed
    claims = [
      'Tujuh peran dasar tidak boleh dipegang satu orang.',
      'Semua pemegang kursi dasar wajib dipegang orang berbeda.',
      '`product_delivery` dan `operations` dilarang dipegang orang yang sama.',
      'Chair dan facilitator wajib dipegang orang berbeda.'
    ]
    original = File.read(fixture_path(G0S0IntakeValidator::INTAKE_FILE))

    claims.each do |claim|
      File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), "#{original}\n#{claim}\n")
      validator = fixture_validator

      assert validator.validate_integrity, "Expected safe claim #{claim.inspect} to pass:\n#{validator.errors.join("\n")}"
    end
  ensure
    File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), original) if original
  end

  def test_indonesian_and_english_role_concentration_prohibitions_are_allowed
    claims = [
      'Ketujuh peran jangan dipegang satu orang.',
      'All base functions must not be combined in one person.',
      'All required roles must not be concentrated in one person.'
    ]
    original = File.read(fixture_path(G0S0IntakeValidator::INTAKE_FILE))

    claims.each do |claim|
      File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), "#{original}\n#{claim}\n")
      validator = fixture_validator

      assert validator.validate_integrity, "Expected prohibition #{claim.inspect} to pass:\n#{validator.errors.join("\n")}"
    end
  ensure
    File.write(fixture_path(G0S0IntakeValidator::INTAKE_FILE), original) if original
  end

  def test_signing_readiness_is_precise_no_go_for_current_empty_authority_state
    validator = fixture_validator

    refute validator.validate_signing_readiness
    assert_equal 'NO-GO G0/S0 signing readiness: 9 blocker(s).', validator.summary
    assert_blocker validator, '0 identities; the unresolved minimum is 7 for reproduce or 8 for replace'
    assert_blocker validator, '0 public keys'
    assert_blocker validator, '0 signatures; 2 distinct trust-root signatures are required'
    assert_blocker validator, '0 appointments'
    assert_blocker validator, '0 sessions'
    assert_blocker validator, 'PAR-ADM-005 machine decision remains pending/UNDECIDED'
    assert_blocker validator, 'minimum roster remains unresolved at 7 for reproduce versus 8 for replace'
    assert_blocker validator, 'external trust-root SHA-256 pin is absent'
    assert_blocker validator, 'role_subject_mapping_not_ready'
    assert_empty validator.errors
  end

  def test_signing_readiness_requires_authoritative_governance_integrity
    validator = fixture_validator(authoritative_integrity: false)

    refute validator.validate_signing_readiness
    assert_blocker validator, 'authoritative_governance_integrity_not_passed'
  end

  def test_unused_identity_counts_cannot_satisfy_role_subject_mapping
    mutate_json(G0S0IntakeValidator::IDENTITY_FILE) do |registry|
      registry['identities'] = 8.times.map do |index|
        {
          'subject_id' => "UEU-PERSON-UNUSED-#{index + 1}",
          'identity_type' => 'person',
          'status' => 'active',
          'authorization_roles' => [],
          'keys' => [{ 'key_id' => "UEU-PUBKEY-UNUSED-#{index + 1}", 'allowed_purposes' => [] }]
        }
      end
      registry['registry_root']['signatures'] = [{}, {}]
    end
    validator = fixture_validator

    refute validator.validate_signing_readiness
    refute validator.blockers.any? { |blocker| blocker.include?('identity registry has 8 identities') }
    assert_blocker validator, 'role_subject_mapping_not_ready'
  end

  private

  def fixture_validator(authoritative_integrity: true)
    G0S0IntakeValidator.new(root: @tmpdir, authoritative_integrity_runner: ->(_pin) { authoritative_integrity })
  end

  def fixture_path(filename)
    File.join(@phase_zero, filename)
  end

  def mutate_intake
    path = fixture_path(G0S0IntakeValidator::INTAKE_FILE)
    File.write(path, yield(File.read(path)))
  end

  def mutate_binding
    mutate_intake do |content|
      pattern = /(#{Regexp.escape(G0S0IntakeValidator::BINDING_BEGIN)}\s*```json\s*)(.*?)(\s*```\s*#{Regexp.escape(G0S0IntakeValidator::BINDING_END)})/m
      match = content.match(pattern)
      raise 'machine binding not found in fixture' unless match

      binding = JSON.parse(match[2])
      yield binding
      content.sub(pattern, "\\1#{JSON.pretty_generate(binding)}\\3")
    end
  end

  def mutate_json(filename)
    path = fixture_path(filename)
    document = JSON.parse(File.read(path))
    yield document
    File.write(path, JSON.pretty_generate(document) + "\n")
  end

  def set_public_spki(value)
    mutate_intake do |content|
      content.sub(
        '| RSA public SPKI Base64 | ________________________________ |',
        "| RSA public SPKI Base64 | #{value} |"
      )
    end
  end

  def test_rsa_key
    unless self.class.instance_variable_defined?(:@test_rsa_key)
      self.class.instance_variable_set(:@test_rsa_key, OpenSSL::PKey::RSA.new(3072))
    end
    self.class.instance_variable_get(:@test_rsa_key)
  end

  def assert_error(validator, fragment)
    assert validator.errors.any? { |error| error.include?(fragment) }, "Expected error containing #{fragment.inspect}, got:\n#{validator.errors.join("\n")}"
  end

  def assert_blocker(validator, fragment)
    assert validator.blockers.any? { |blocker| blocker.include?(fragment) }, "Expected blocker containing #{fragment.inspect}, got:\n#{validator.blockers.join("\n")}"
  end
end
