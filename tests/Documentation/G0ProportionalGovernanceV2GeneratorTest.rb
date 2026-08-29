# frozen_string_literal: true

require 'digest'
require 'fileutils'
require 'json'
require 'minitest/autorun'
require 'open3'
require 'tmpdir'

require_relative '../../scripts/generate-g0-proportional-governance-v2'
require_relative '../../scripts/compare-g0-governance-v1-v2'

class G0ProportionalGovernanceV2GeneratorTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PHASE = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0')
  GENERATOR = File.join(ROOT, 'scripts/generate-g0-proportional-governance-v2.rb')
  TEMP_PARENT = File.realpath(Dir.tmpdir)
  Generator = G0ProportionalGovernanceV2Generator
  Comparator = G0GovernanceV1V2Comparator
  Core = G0ProportionalGovernanceV2

  EXPANDED_KEYS = %w[
    artifact_type schema_version register_id profile status effect data_boundary
    required_app_mode source_manifest source_evidence_map canonical_order entries
  ].freeze
  ROW_KEYS = %w[
    requirement_id batch source_decision_pointer legacy_menu synthetic_scenarios
    appointment_dependencies upstream_dependencies affected_domains downstream_effects
    source_pending_fragments boundary governance_state owner_state
    owner_assignment_status decision_status owner_record_id decision_event_id
    owner_outcome canonical_disposition target consequence_map derived_tier
    product_authority_id domain_authority_ids co_owner_authority_ids
    independent_review_ids evidence_references conditions predecessor_event_id
    predecessor_event_sha256 supersedes_event_id g0_terminal
    implementation_authorized provisional_engineering_binding
  ].freeze

  def setup
    @tmpdir = Dir.mktmpdir('g0-v2-generator-', TEMP_PARENT)
    @contract = Core.parse_json_file(File.join(PHASE, 'G0_GOVERNANCE_V2_CONTRACT.json'))
    @v1_manifest = Core.parse_json_file(File.join(PHASE, 'G0_GOVERNANCE_V1_HISTORICAL_HASH_MANIFEST.json'))
    @batch_manifest = Core.parse_json_file(File.join(PHASE, 'G0_PARITY_BATCH_MANIFEST.json'))
    @evidence_map = Core.parse_json_file(File.join(ROOT, Generator::EVIDENCE_MAP_PATH))
    @owner_policy = Core.parse_json_file(File.join(PHASE, 'G0_OWNER_AUTHORITY_POLICY_2026-08-25.json'))
  end

  def teardown
    FileUtils.remove_entry(@tmpdir) if File.exist?(@tmpdir)
  end

  def test_generates_closed_six_file_pending_bundle_in_exact_manifest_order
    output = File.join(@tmpdir, 'candidate')
    receipt = Generator.generate!(root: ROOT, output: output)

    assert_equal 'generated_pending_candidate', receipt.fetch('status')
    assert Generator.complete_candidate?(output)
    assert_equal Generator::FILES.values.sort, Dir.children(output).sort

    expanded = read_candidate(output, 'expanded_decision_register')
    assert_equal EXPANDED_KEYS.sort, expanded.keys.sort
    assert_equal 'pending_projection', expanded.fetch('status')
    assert_equal 'none_no_capability_disposition_or_implementation_authority', expanded.fetch('effect')
    expected_ids = ('A'..'G').flat_map { |batch| @batch_manifest.dig('batches', batch) }
    assert_equal 268, expanded.fetch('entries').length
    assert_equal expected_ids, expanded.fetch('canonical_order')
    assert_equal expected_ids, expanded.fetch('entries').map { |row| row.fetch('requirement_id') }
    assert_equal({ 'path' => Generator::EVIDENCE_MAP_PATH, 'sha256' => Generator::EVIDENCE_MAP_SHA256 }, expanded.fetch('source_evidence_map'))

    expanded.fetch('entries').each do |row|
      assert_equal ROW_KEYS.sort, row.keys.sort
      assert_pending_row(row)
    end
  end

  def test_source_rows_bind_exact_v1_hash_semantics_and_preserve_scenarios_dependencies_and_pending_fragments
    output = File.join(@tmpdir, 'candidate')
    Generator.generate!(root: ROOT, output: output)
    rows = read_candidate(output, 'expanded_decision_register').fetch('entries')
    policies = @owner_policy.fetch('requirement_policies').to_h { |item| [item.fetch('requirement_id'), item] }

    rows.each do |row|
      pointer = row.fetch('source_decision_pointer')
      source = Core.parse_json_file(File.join(ROOT, pointer.fetch('path')))
      source_row = source.fetch('entries').fetch(pointer.fetch('entry_index'))
      id = row.fetch('requirement_id')

      assert_equal id, source_row.fetch('requirement_id')
      assert_equal 0, pointer.fetch('entry_index_base')
      assert_equal policies.fetch(id).fetch('source_row_sha256'), pointer.fetch('entry_sha256')
      assert_equal Core.canonical_sha256(source_row), pointer.fetch('entry_v2_canonical_sha256')
      assert_equal source_row.fetch('synthetic_scenarios'), row.fetch('synthetic_scenarios')
      assert_equal source_row.fetch('appointment_dependencies'), row.fetch('appointment_dependencies')
      assert_equal source_row.fetch('affected_domains'), row.fetch('affected_domains')
      assert_equal source_row.fetch('downstream_impacts'), row.fetch('downstream_effects')
      assert_equal source_row.fetch('decision'), row.dig('source_pending_fragments', 'decision')
      assert_equal source_row.fetch('accountable_owner'), row.dig('source_pending_fragments', 'accountable_owner')
      assert_equal source_row.fetch('approval'), row.dig('source_pending_fragments', 'approval')
      assert_equal source_row.fetch('co_owners'), row.dig('source_pending_fragments', 'co_owners')
      assert_equal source_row.fetch('gate_authority_appointments', []), row.dig('source_pending_fragments', 'gate_authority_appointments')
      assert_equal source_row.fetch('upstream_requirement_dependencies', []), row.dig('upstream_dependencies', 'upstream_requirement_dependencies')
      assert_equal source_row.fetch('dependency_gates', []), row.dig('upstream_dependencies', 'dependency_gates')
      assert_equal source_row.fetch('intra_batch_dependencies', []), row.dig('upstream_dependencies', 'intra_batch_dependencies')
      assert_equal source_row.fetch('source_dependencies', []), row.dig('upstream_dependencies', 'source_dependencies')
    end
  end

  def test_exactly_fourteen_provisional_bindings_are_comparison_only_and_never_promote_authority
    output = File.join(@tmpdir, 'candidate')
    Generator.generate!(root: ROOT, output: output)
    rows = read_candidate(output, 'expanded_decision_register').fetch('entries')
    row_by_id = rows.to_h { |row| [row.fetch('requirement_id'), row] }
    overrides = @evidence_map.fetch('capability_overrides')

    assert_equal 14, rows.count { |row| row.dig('provisional_engineering_binding', 'status') == 'PROVISIONAL' }
    overrides.each do |id, override|
      binding = row_by_id.fetch(id).fetch('provisional_engineering_binding')
      assert_equal override.fetch('workflow_binding'), binding.slice('status', 'scenario_ids', 'authority_reference')
      assert_equal 'engineering_only', binding.fetch('comparison_dimension')
      %w[owner_authority_effect tier_effect approval_effect disposition_effect gate_effect].each do |field|
        assert_equal 'none', binding.fetch(field)
      end
    end

    (row_by_id.keys - overrides.keys).each do |id|
      assert_equal @evidence_map.fetch('workflow_binding_default'), row_by_id.fetch(id).fetch('provisional_engineering_binding').slice('status', 'scenario_ids', 'authority_reference')
    end
  end

  def test_pending_authority_owner_event_and_gate_artifacts_cannot_claim_a_decision
    output = File.join(@tmpdir, 'candidate')
    Generator.generate!(root: ROOT, output: output)
    authority = read_candidate(output, 'authority_register')
    owner = read_candidate(output, 'owner_register')
    events = read_candidate(output, 'decision_event_register')
    gate = read_candidate(output, 'gate_register')

    assert_equal [], authority.fetch('authorities')
    assert_equal [], owner.fetch('owners')
    assert_equal [], events.fetch('events')
    [authority, owner, events].each do |artifact|
      assert_equal 'pending', artifact.fetch('status')
      assert_equal 'none_no_authority_or_activation', artifact.fetch('effect')
    end
    assert_equal 'OPEN', gate.fetch('project_g0')
    assert_equal 'OPEN', gate.fetch('project_g3')
    assert_equal 0, gate.dig('counts', 'terminal')
    assert_equal 268, gate.dig('counts', 'nonterminal')
    assert_equal false, gate.dig('derivation', 'all_rows_terminal')
    assert_equal 'none', gate.dig('derivation', 'provisional_engineering_binding_effect')
  end

  def test_bundle_manifest_hashes_every_generated_artifact_and_binds_exact_sources
    output = File.join(@tmpdir, 'candidate')
    receipt = Generator.generate!(root: ROOT, output: output)
    manifest_path = File.join(output, Generator::FILES.fetch('bundle_manifest'))
    manifest = Core.parse_json_file(manifest_path)

    assert_equal Digest::SHA256.file(manifest_path).hexdigest, receipt.fetch('bundle_manifest_sha256')
    assert_equal 268, manifest.fetch('capability_count')
    assert_equal 14, manifest.fetch('provisional_engineering_binding_count')
    assert_equal Generator::EVIDENCE_MAP_SHA256, manifest.dig('source_evidence_map', 'sha256')
    assert_equal @contract.dig('adopted_sources', 'adoption_decision'), manifest.fetch('adoption_decision')
    assert_equal Generator::PUBLICATION_ORDER.map { |role| Generator::FILES.fetch(role) }, manifest.dig('publication', 'required_files')
    assert_equal true, manifest.dig('publication', 'manifest_published_last')
    assert_equal false, manifest.dig('publication', 'partial_candidate_usable')

    manifest.fetch('generated_artifacts').each do |entry|
      assert_equal Digest::SHA256.file(File.join(output, entry.fetch('path'))).hexdigest, entry.fetch('sha256')
    end
    identity_payload = Generator.send(:bundle_identity_payload_from_manifest, manifest)
    assert_equal "G0-GOVERNANCE-V2-PENDING-#{Core.canonical_sha256(identity_payload)[0, 24]}", manifest.fetch('bundle_id')
  end

  def test_bundle_identity_changes_for_validator_or_adoption_revision_and_tamper_is_incomplete
    sources = Generator.send(:load_sources, Pathname.new(ROOT).realpath)
    artifacts = Generator.send(:build_artifacts, sources)
    bytes = artifacts.to_h { |role, artifact| [role, Core.canonical_json(artifact) + "\n"] }
    baseline = Generator.send(:build_bundle_manifest, sources, bytes)

    validator_revision = sources.merge(contract_sha256: 'e' * 64)
    validator_manifest = Generator.send(:build_bundle_manifest, validator_revision, bytes)
    refute_equal baseline.fetch('bundle_id'), validator_manifest.fetch('bundle_id')

    revised_contract = JSON.parse(JSON.generate(sources.fetch(:contract)))
    revised_contract.fetch('adopted_sources').fetch('adoption_decision')['sha256'] = 'f' * 64
    adoption_revision = sources.merge(contract: revised_contract)
    adoption_manifest = Generator.send(:build_bundle_manifest, adoption_revision, bytes)
    refute_equal baseline.fetch('bundle_id'), adoption_manifest.fetch('bundle_id')

    output = File.join(@tmpdir, 'candidate')
    Generator.generate!(root: ROOT, output: output)
    manifest_path = File.join(output, Generator::FILES.fetch('bundle_manifest'))
    tampered = JSON.parse(File.binread(manifest_path))
    tampered['bundle_id'] = 'G0-GOVERNANCE-V2-PENDING-' + ('0' * 24)
    File.binwrite(manifest_path, Core.canonical_json(tampered) + "\n")
    refute Generator.complete_candidate?(output)
  end

  def test_generator_and_comparator_share_exact_v1_nfc_time_int64_and_float_semantics
    fixture = {
      'label' => "e\u0301",
      'at' => '2026-08-29T03:00:00+07:00',
      'minimum' => -(2**63),
      'maximum' => (2**63) - 1
    }
    generator_sha = Generator.send(:owner_canonical_sha256, fixture)
    comparator_bytes = Comparator.send(:v1_owner_canonical_json, fixture)
    assert_equal generator_sha, Digest::SHA256.hexdigest(comparator_bytes)
    assert_equal '{"at":"2026-08-28T20:00:00Z","label":"\\u00e9","maximum":9223372036854775807,"minimum":-9223372036854775808}', comparator_bytes

    [2**63, -(2**63) - 1, 1.5].each do |invalid|
      assert_raises(Generator::Error) { Generator.send(:owner_canonical_sha256, { 'value' => invalid }) }
      assert_raises(Comparator::ComparisonError) { Comparator.send(:v1_owner_canonical_json, { 'value' => invalid }) }
    end
  end

  def test_two_clean_runs_produce_identical_bytes_and_preserve_all_v1_bytes
    before = historical_hashes
    first = File.join(@tmpdir, 'first')
    second = File.join(@tmpdir, 'second')
    Generator.generate!(root: ROOT, output: first)
    Generator.generate!(root: ROOT, output: second)

    Generator::FILES.values.each do |name|
      assert_equal File.binread(File.join(first, name)), File.binread(File.join(second, name)), name
      assert File.binread(File.join(first, name)).end_with?("\n")
      refute File.binread(File.join(first, name)).end_with?("\n\n")
    end
    assert_equal before, historical_hashes
  end

  def test_existing_output_is_refused_without_changing_its_bytes
    output = File.join(@tmpdir, 'existing')
    Dir.mkdir(output)
    marker = File.join(output, 'owned-by-caller')
    File.binwrite(marker, 'unchanged')

    error = assert_raises(Generator::UsageError) { Generator.generate!(root: ROOT, output: output) }

    assert_match(/already exists/, error.message)
    assert_equal ['owned-by-caller'], Dir.children(output)
    assert_equal 'unchanged', File.binread(marker)
  end

  def test_every_injected_partial_publication_is_explicitly_incomplete_and_never_usable
    (1..5).each do |published_count|
      output = File.join(@tmpdir, "partial-#{published_count}")
      error = assert_raises(Generator::Error) do
        Generator.generate!(root: ROOT, output: output, fault_after_publications: published_count)
      end
      assert_equal 'injected publication failure', error.message
      assert File.file?(File.join(output, '.incomplete'))
      refute Generator.complete_candidate?(output)
      refute File.exist?(File.join(output, Generator::FILES.fetch('bundle_manifest')))
    end
  end

  def test_complete_candidate_rejects_extra_files_hash_drift_and_manifest_drift
    output = File.join(@tmpdir, 'candidate')
    Generator.generate!(root: ROOT, output: output)
    extra = File.join(output, 'unexpected.json')
    File.binwrite(extra, "{}\n")
    refute Generator.complete_candidate?(output)
    File.unlink(extra)

    authority = File.join(output, Generator::FILES.fetch('authority_register'))
    File.open(authority, 'ab') { |file| file.write("\n") }
    refute Generator.complete_candidate?(output)
  end

  def test_complete_candidate_rejects_empty_or_reordered_generated_artifact_manifest
    output = File.join(@tmpdir, 'candidate')
    Generator.generate!(root: ROOT, output: output)
    manifest_path = File.join(output, Generator::FILES.fetch('bundle_manifest'))
    manifest = Core.parse_json_file(manifest_path)
    manifest.fetch('generated_artifacts').clear
    File.binwrite(manifest_path, Core.canonical_json(manifest) + "\n")
    refute Generator.complete_candidate?(output)

    FileUtils.remove_entry(output)
    Generator.generate!(root: ROOT, output: output)
    manifest = Core.parse_json_file(manifest_path)
    manifest['generated_artifacts'].reverse!
    File.binwrite(manifest_path, Core.canonical_json(manifest) + "\n")
    refute Generator.complete_candidate?(output)
  end

  def test_symlinked_repository_or_output_parent_is_rejected
    root_link = File.join(@tmpdir, 'root-link')
    File.symlink(ROOT, root_link)
    assert_raises(Generator::UsageError) do
      Generator.generate!(root: root_link, output: File.join(@tmpdir, 'root-link-output'))
    end

    real_parent = File.join(@tmpdir, 'real-parent')
    linked_parent = File.join(@tmpdir, 'linked-parent')
    Dir.mkdir(real_parent)
    File.symlink(real_parent, linked_parent)
    assert_raises(Generator::UsageError) do
      Generator.generate!(root: ROOT, output: File.join(linked_parent, 'candidate'))
    end
  end

  def test_cli_is_closed_and_uses_stable_exit_classes
    _out, err, status = Open3.capture3(RbConfig.ruby, GENERATOR)
    assert_equal 2, status.exitstatus
    assert_match(/--output is required/, err)

    _out, _err, status = Open3.capture3(RbConfig.ruby, GENERATOR, '--unknown')
    assert_equal 2, status.exitstatus

    _out, _err, status = Open3.capture3(RbConfig.ruby, GENERATOR, '--output', 'a', '--output', 'b')
    assert_equal 2, status.exitstatus

    existing = File.join(@tmpdir, 'cli-existing')
    Dir.mkdir(existing)
    _out, _err, status = Open3.capture3(RbConfig.ruby, GENERATOR, '--output', existing)
    assert_equal 2, status.exitstatus

    output = File.join(@tmpdir, 'cli-success')
    out, err, status = Open3.capture3(RbConfig.ruby, GENERATOR, '--output', output)
    assert_equal 0, status.exitstatus
    assert_empty err
    assert_equal 'generated_pending_candidate', JSON.parse(out).fetch('status')
    assert Generator.complete_candidate?(output)
  end

  def test_parse_and_cli_diagnostics_never_echo_secret_like_attacker_input
    sentinel = 'DO-NOT-ECHO-SENTINEL-7291'
    malformed = File.join(@tmpdir, 'malformed.json')
    File.binwrite(malformed, "{\"#{sentinel}\":1,\"#{sentinel}\":2}\n")

    error = assert_raises(Generator::Error) do
      Generator.send(:parse_source, Pathname.new(@tmpdir).realpath, 'malformed.json')
    end
    refute_includes error.message, sentinel
    assert_equal 'malformed.json: invalid JSON source', error.message

    out, err, status = Open3.capture3(RbConfig.ruby, GENERATOR, "--client-secret=#{sentinel}")
    assert_equal 2, status.exitstatus
    refute_includes out, sentinel
    refute_includes err, sentinel
  end

  def test_generator_has_no_activation_pointer_deployment_or_network_behavior
    source = File.binread(GENERATOR)
    refute_match(/G0_GOVERNANCE_CONSUMER_POINTER/, source)
    refute_match(/\b(?:activate|rollback|deploy)!?\b/i, source)
    refute_match(/Net::HTTP|TCPSocket|UDPSocket|Faraday|curl\s|wget\s/, source)
    assert system(RbConfig.ruby, '-c', GENERATOR, out: File::NULL, err: File::NULL)
  end

  private

  def read_candidate(directory, role)
    Core.parse_json_file(File.join(directory, Generator::FILES.fetch(role)))
  end

  def assert_pending_row(row)
    assert_equal 'PENDING', row.fetch('governance_state')
    assert_equal 'DRAFT', row.fetch('owner_state')
    assert_equal 'pending', row.fetch('owner_assignment_status')
    assert_equal 'pending', row.fetch('decision_status')
    %w[
      owner_record_id decision_event_id owner_outcome canonical_disposition target
      consequence_map derived_tier product_authority_id predecessor_event_id
      predecessor_event_sha256 supersedes_event_id
    ].each { |field| assert_nil row.fetch(field), "#{row.fetch('requirement_id')} #{field}" }
    %w[
      domain_authority_ids co_owner_authority_ids independent_review_ids
      evidence_references conditions
    ].each { |field| assert_equal [], row.fetch(field), "#{row.fetch('requirement_id')} #{field}" }
    assert_equal false, row.fetch('g0_terminal')
    assert_equal false, row.fetch('implementation_authorized')
    assert_equal Generator::COMMON_BOUNDARY, row.fetch('boundary')
  end

  def historical_hashes
    @v1_manifest.fetch('files').to_h do |entry|
      [entry.fetch('path'), Digest::SHA256.file(File.join(ROOT, entry.fetch('path'))).hexdigest]
    end
  end
end
