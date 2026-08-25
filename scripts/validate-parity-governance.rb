#!/usr/bin/env ruby
# frozen_string_literal: true

require 'date'
require 'digest'
require 'json'
require 'optparse'
require 'pathname'

class ParityGovernanceValidator
  MATRIX_COLUMNS = [
    'Requirement ID',
    'Legacy category',
    'Legacy menu',
    'Initial disposition',
    'Evidence',
    'Parity status',
    'Business owner',
    'Target capability / slice',
    'Detailed requirement',
    'Acceptance test'
  ].freeze

  RELEASE_COLUMNS = [
    'Evidence ID',
    'Date',
    'Scope',
    'Local',
    'Committed',
    'Pushed',
    'Deployed',
    'Authz / audit / reconciliation',
    'Gate',
    'Rollback'
  ].freeze

  PARITY_STATUSES = [
    'Unspecified',
    'Specified',
    'Acceptance review',
    'Accepted',
    'Deferred'
  ].freeze

  PROMOTED_STATUSES = [
    'Specified',
    'Acceptance review',
    'Accepted'
  ].freeze

  REQUIRED_ACCEPTANCE_KEYS = [
    'parity_requirement_id',
    'decision',
    'business_owner',
    'domain_owner',
    'decision_date',
    'release_evidence_id'
  ].freeze

  EXPECTED_BATCH_COUNTS = {
    'A' => 20,
    'B' => 8,
    'C' => 19,
    'D' => 19,
    'E' => 48,
    'F' => 34,
    'G' => 120
  }.freeze

  EXPECTED_BATCH_A_IDS = %w[
    PAR-ADM-001
    PAR-ADM-002
    PAR-ADM-003
    PAR-ADM-005
    PAR-ADM-006
    PAR-ADM-008
    PAR-ADM-012
    PAR-ADM-013
    PAR-ADM-022
    PAR-ADM-023
    PAR-ADM-024
    PAR-ADM-025
    PAR-ADM-032
    PAR-ADM-037
    PAR-ADM-038
    PAR-ADM-040
    PAR-ADM-044
    PAR-ADM-045
    PAR-HLP-001
    PAR-IOT-001
  ].freeze

  BATCH_A_REGISTER_SCHEMA_VERSION = 1
  BATCH_A_REGISTER_ID = 'G0-BATCH-A-2026-08-25'
  BATCH_A_EVIDENCE_DIRECTORY = 'G0_BATCH_A_DECISION_EVIDENCE_2026-08-25'
  GOVERNANCE_ARTIFACT_TYPE = 'g0_batch_a_governance_attestation'
  EVIDENCE_ARTIFACT_TYPE = 'g0_batch_a_evidence'
  ARTIFACT_SCHEMA_VERSION = 1
  EVIDENCE_CLASSES = %w[O M I U P].freeze
  EVIDENCE_CONFIDENCES = %w[pending low medium high].freeze
  DECISION_STATUSES = %w[pending approve revise defer reject].freeze
  CANONICAL_DISPOSITIONS = %w[pending reproduce replace consolidate retire exclude].freeze
  TARGET_KINDS = %w[pending capability consolidation_target exclusion].freeze
  APPOINTMENT_STATUSES = %w[pending appointed].freeze
  APPROVAL_STATUSES = %w[pending recorded].freeze
  SCENARIO_STATUSES = %w[draft ready].freeze
  REGISTER_STATUSES = %w[pending in_progress complete].freeze
  VERIFICATION_METHODS = %w[detached_signature institutional_registry signed_document_review].freeze
  REVIEWER_KEYS = %w[identity verification_method verification_reference].freeze
  GOVERNANCE_ARTIFACT_KEYS = {
    'accountable_owner' => %w[artifact_type schema_version register_id requirement_id subject identity scope date reviewer],
    'appointment_dependency' => %w[artifact_type schema_version register_id requirement_id subject identity authority_domain scope date reviewer],
    'approval' => %w[artifact_type schema_version register_id requirement_id subject identity scope date decision_status canonical_disposition conditions reviewer]
  }.freeze
  EVIDENCE_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id evidence_class date source reference interpreter confidence reviewer].freeze

  PAR_ID_PATTERN = /\APAR-[A-Z0-9]+-\d{3}\z/.freeze
  REL_ID_PATTERN = /\AREL-(\d{8})-(\d{2})\z/.freeze
  PLACEHOLDER_OWNER_PATTERN = /(?:\bTBD\b|\bunknown\b|\bunassigned\b|\bpending\b|to[ _-]?be[ _-]?assigned|replace[ _-]?with|\bN\/?A\b)/i.freeze
  EVIDENCE_PLACEHOLDER_PATTERN = /(?:\bpending\b|not (?:committed|pushed|deployed|verified)|filled at commit|updated after push|\bunknown\b|\bTBD\b|\bN\/?A\b)/i.freeze

  attr_reader :batch_assignments, :decision_entries, :errors, :rows, :release_rows

  def initialize(matrix_path:, baseline_path:, release_index_path:, batch_manifest_path: 'docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json', decision_register_path: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_A_DECISION_REGISTER_2026-08-25.json', mode: 'integrity')
    @matrix_path = File.expand_path(matrix_path)
    @baseline_path = File.expand_path(baseline_path)
    @release_index_path = File.expand_path(release_index_path)
    @batch_manifest_path = File.expand_path(batch_manifest_path)
    @decision_register_path = File.expand_path(decision_register_path)
    @mode = mode
    @batch_assignments = {}
    @decision_entries = []
    @errors = []
    @rows = []
    @release_rows = []
  end

  def validate
    unless %w[integrity g0].include?(@mode)
      errors << "mode: expected integrity or g0, got #{@mode.inspect}"
      return false
    end

    baseline = load_baseline
    manifest = load_batch_manifest
    decision_register = load_batch_a_decision_register
    parse_matrix
    parse_release_index

    validate_matrix_header
    validate_matrix_shape_and_cells
    validate_exact_id_set(baseline)
    validate_categories_and_prefixes(baseline)
    validate_batch_manifest(baseline, manifest)
    validate_batch_a_decision_register(baseline, manifest, decision_register)
    validate_vocabularies
    validate_owners
    validate_consolidations
    validate_release_register
    validate_accepted_rows

    errors.empty?
  end

  private

  def load_baseline
    unless File.file?(@baseline_path)
      errors << "baseline: file not found: #{@baseline_path}"
      return empty_baseline
    end

    data = JSON.parse(File.read(@baseline_path))
    unless data['schema_version'] == 1
      errors << 'baseline: schema_version must be 1'
    end
    unless data['expected_row_count'] == 268
      errors << 'baseline: expected_row_count must be exactly 268'
    end

    categories = data['categories']
    unless categories.is_a?(Array) && !categories.empty?
      errors << 'baseline: categories must be a non-empty array'
      return empty_baseline
    end

    expected = {}
    categories.each_with_index do |category, index|
      unless category.is_a?(Hash)
        errors << "baseline: categories[#{index}] must be an object"
        next
      end

      name = category['legacy_category'].to_s.strip
      prefix = category['prefix'].to_s.strip
      first = category['first']
      last = category['last']
      if name.empty? || prefix !~ /\A[A-Z0-9]+\z/ || !first.is_a?(Integer) || !last.is_a?(Integer) || first < 1 || last < first
        errors << "baseline: invalid category definition at index #{index}"
        next
      end

      (first..last).each do |number|
        id = format('PAR-%s-%03d', prefix, number)
        if expected.key?(id)
          errors << "baseline: duplicate generated requirement ID #{id}"
        else
          expected[id] = { 'legacy_category' => name, 'prefix' => prefix }
        end
      end
    end

    if data['expected_row_count'].is_a?(Integer) && expected.length != data['expected_row_count']
      errors << "baseline: category ranges generate #{expected.length} IDs, expected #{data['expected_row_count']}"
    end

    { 'expected' => expected }
  rescue JSON::ParserError => e
    errors << "baseline: invalid JSON: #{e.message}"
    empty_baseline
  rescue SystemCallError => e
    errors << "baseline: cannot read file: #{e.message}"
    empty_baseline
  end

  def empty_baseline
    { 'expected' => {} }
  end

  def load_batch_manifest
    unless File.file?(@batch_manifest_path)
      errors << "batch manifest: file not found: #{@batch_manifest_path}"
      return {}
    end

    JSON.parse(File.read(@batch_manifest_path))
  rescue JSON::ParserError => e
    errors << "batch manifest: invalid JSON: #{e.message}"
    {}
  rescue SystemCallError => e
    errors << "batch manifest: cannot read file: #{e.message}"
    {}
  end

  def load_batch_a_decision_register
    unless File.file?(@decision_register_path)
      errors << "Batch A decision register: file not found: #{@decision_register_path}"
      return {}
    end

    JSON.parse(File.read(@decision_register_path))
  rescue JSON::ParserError => e
    errors << "Batch A decision register: invalid JSON: #{e.message}"
    {}
  rescue SystemCallError => e
    errors << "Batch A decision register: cannot read file: #{e.message}"
    {}
  end

  def validate_batch_a_decision_register(baseline, manifest, register)
    unless register['schema_version'] == BATCH_A_REGISTER_SCHEMA_VERSION
      errors << "Batch A decision register: schema_version must be #{BATCH_A_REGISTER_SCHEMA_VERSION}"
    end
    unless register['register_id'] == BATCH_A_REGISTER_ID
      errors << "Batch A decision register: register_id must be #{BATCH_A_REGISTER_ID}"
    end
    @batch_a_register_id = register['register_id']
    unless register['batch'] == 'A'
      errors << 'Batch A decision register: batch must be A'
    end
    unless register['register_status'].is_a?(String) && REGISTER_STATUSES.include?(register['register_status'])
      errors << "Batch A decision register: invalid register_status #{register['register_status'].inspect}"
    end
    unless register['data_boundary'] == 'synthetic_only'
      errors << 'Batch A decision register: data_boundary must be synthetic_only'
    end
    unless register['external_integrations'] == 'disabled'
      errors << 'Batch A decision register: external_integrations must be disabled'
    end
    unless register['source_manifest'] == 'docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json'
      errors << 'Batch A decision register: source_manifest must reference the canonical G0 batch manifest'
    end
    unless register['evidence_directory'] == BATCH_A_EVIDENCE_DIRECTORY
      errors << "Batch A decision register: evidence_directory must be #{BATCH_A_EVIDENCE_DIRECTORY}"
    end

    entries = register['entries']
    unless entries.is_a?(Array)
      errors << 'Batch A decision register: entries must be an array'
      return
    end
    @decision_entries = entries.select { |entry| entry.is_a?(Hash) }

    batches = manifest['batches']
    expected_ids = batches.is_a?(Hash) && batches['A'].is_a?(Array) ? batches['A'] : []
    actual_ids = entries.each_with_object([]) do |entry, ids|
      ids << entry['requirement_id'] if entry.is_a?(Hash)
    end
    invalid_entries = entries.each_index.reject { |index| entries[index].is_a?(Hash) }
    invalid_entries.each { |index| errors << "Batch A decision register entry #{index}: must be an object" }

    invalid_ids = actual_ids.reject { |id| id.is_a?(String) && id.match?(PAR_ID_PATTERN) }.uniq
    errors << "Batch A decision register: invalid requirement IDs: #{invalid_ids.map(&:inspect).join(', ')}" unless invalid_ids.empty?

    string_ids = actual_ids.select { |id| id.is_a?(String) }
    duplicates = string_ids.group_by { |id| id }.select { |_id, values| values.length > 1 }.keys.sort
    errors << "Batch A decision register: duplicate requirement IDs: #{duplicates.join(', ')}" unless duplicates.empty?

    missing = expected_ids - actual_ids
    unknown = string_ids - expected_ids
    errors << "Batch A decision register: missing Batch A requirement IDs: #{missing.sort.join(', ')}" unless missing.empty?
    errors << "Batch A decision register: unknown Batch A requirement IDs: #{unknown.sort.join(', ')}" unless unknown.empty?
    if entries.length != EXPECTED_BATCH_COUNTS['A']
      errors << "Batch A decision register: expected exactly #{EXPECTED_BATCH_COUNTS['A']} entries, got #{entries.length}"
    end
    if entries.length == expected_ids.length && actual_ids != expected_ids
      errors << 'Batch A decision register: entries must follow the canonical Batch A manifest order'
    end

    entries.each_with_index do |entry, index|
      next unless entry.is_a?(Hash)

      validate_batch_a_entry(entry, index, baseline['expected'])
    end
    validate_batch_a_consolidation_graph(entries, baseline['expected'])

    return unless register['register_status'] == 'complete'

    unresolved = entries.select do |entry|
      decision = entry['decision'] if entry.is_a?(Hash)
      !decision.is_a?(Hash) || !%w[approve defer].include?(decision['status'])
    end
    errors << 'Batch A decision register: register_status complete requires every entry to be approved or deferred' unless unresolved.empty?

    # Integrity mode must not accept a self-declared complete register whose
    # decisions lack the evidence, appointments, approvals, or ready scenarios
    # that G0 itself requires. G0 mode already performs this check per entry.
    unless @mode == 'g0'
      entries.each_with_index do |entry, index|
        next unless entry.is_a?(Hash)

        id = entry['requirement_id']
        label = id.is_a?(String) && !id.empty? ? id : "entry #{index}"
        validate_batch_a_g0_resolution(entry, label)
      end
    end
  end

  def validate_batch_a_entry(entry, index, known_requirements)
    id = entry['requirement_id']
    label = id.is_a?(String) && !id.empty? ? id : "entry #{index}"

    errors << "Batch A decision register #{label}: batch must be A" unless entry['batch'] == 'A'
    errors << "Batch A decision register #{label}: legacy_menu must be a non-empty string" unless nonempty_string?(entry['legacy_menu'])

    evidence = entry['evidence']
    if !evidence.is_a?(Array) || evidence.empty?
      errors << "Batch A decision register #{label}: evidence must be a non-empty array"
    else
      evidence.each_with_index { |record, evidence_index| validate_batch_a_evidence(record, label, evidence_index) }
    end

    decision = entry['decision']
    validate_batch_a_decision(decision, label, known_requirements)

    validate_nonempty_string_array(entry['affected_domains'], "Batch A decision register #{label}: affected_domains")
    validate_nonempty_string_array(entry['co_owners'], "Batch A decision register #{label}: co_owners")
    validate_nonempty_string_array(entry['downstream_impacts'], "Batch A decision register #{label}: downstream_impacts")

    scenarios = entry['synthetic_scenarios']
    if !scenarios.is_a?(Hash)
      errors << "Batch A decision register #{label}: synthetic_scenarios must be an object"
    else
      validate_batch_a_scenario(scenarios['normal'], label, 'normal')
      validate_batch_a_scenario(scenarios['denial_or_correction'], label, 'denial_or_correction')
    end

    validate_accountable_owner(entry['accountable_owner'], label)
    validate_appointment_dependencies(entry['appointment_dependencies'], entry['co_owners'], label)
    validate_batch_a_approval(entry['approval'], label, decision)

    validate_batch_a_g0_resolution(entry, label) if @mode == 'g0'
  end

  def validate_batch_a_evidence(record, label, index)
    unless record.is_a?(Hash)
      errors << "Batch A decision register #{label}: evidence[#{index}] must be an object"
      return
    end

    evidence_class = record['evidence_class']
    confidence = record['confidence']
    unless EVIDENCE_CLASSES.include?(evidence_class)
      errors << "Batch A decision register #{label}: invalid evidence_class #{evidence_class.inspect}"
    end
    unless EVIDENCE_CONFIDENCES.include?(confidence)
      errors << "Batch A decision register #{label}: invalid evidence confidence #{confidence.inspect}"
    end

    if evidence_class == 'P'
      %w[date source reference interpreter artifact_reference artifact_sha256].each do |key|
        errors << "Batch A decision register #{label}: pending evidence #{key} must be null" unless record[key].nil?
      end
      errors << "Batch A decision register #{label}: pending evidence confidence must be pending" unless confidence == 'pending'
      errors << "Batch A decision register #{label}: pending evidence note must explain the gap" unless nonempty_string?(record['note'])
      return
    end

    errors << "Batch A decision register #{label}: evidence date must be YYYY-MM-DD" unless iso_date?(record['date'])
    %w[source reference interpreter].each do |key|
      errors << "Batch A decision register #{label}: evidence #{key} must be a non-empty string" unless nonempty_string?(record[key])
    end
    if confidence == 'pending'
      errors << "Batch A decision register #{label}: non-pending evidence confidence cannot be pending"
    end
    validate_evidence_artifact(record, label, index)
  end

  def validate_batch_a_decision(decision, label, known_requirements)
    unless decision.is_a?(Hash)
      errors << "Batch A decision register #{label}: decision must be an object"
      return
    end

    status = decision['status']
    disposition = decision['canonical_disposition']
    errors << "Batch A decision register #{label}: invalid decision status #{status.inspect}" unless DECISION_STATUSES.include?(status)
    unless CANONICAL_DISPOSITIONS.include?(disposition)
      errors << "Batch A decision register #{label}: invalid canonical_disposition #{disposition.inspect}"
    end

    target = decision['target']
    unless target.is_a?(Hash)
      errors << "Batch A decision register #{label}: decision target must be an object"
      return
    end

    kind = target['kind']
    errors << "Batch A decision register #{label}: invalid target kind #{kind.inspect}" unless TARGET_KINDS.include?(kind)
    unless target['exclusions'].is_a?(Array) && target['exclusions'].all? { |value| nonempty_string?(value) }
      errors << "Batch A decision register #{label}: target exclusions must be an array of non-empty strings"
    end

    if status == 'pending'
      errors << "Batch A decision register #{label}: pending decision must keep canonical_disposition pending" unless disposition == 'pending'
      errors << "Batch A decision register #{label}: pending decision must keep target kind pending" unless kind == 'pending'
      errors << "Batch A decision register #{label}: pending decision target reference must be null" unless target['reference'].nil?
      errors << "Batch A decision register #{label}: pending decision exclusions must be empty" unless target['exclusions'] == []
      errors << "Batch A decision register #{label}: pending decision rationale must be null" unless decision['rationale'].nil?
      return
    end

    errors << "Batch A decision register #{label}: non-pending decision rationale must be a non-empty string" unless nonempty_string?(decision['rationale'])
    validate_canonical_target(disposition, target, label, known_requirements) if %w[approve defer].include?(status)
  end

  def validate_canonical_target(disposition, target, label, known_requirements)
    kind = target['kind']
    reference = target['reference']
    exclusions = target['exclusions']

    case disposition
    when 'reproduce', 'replace'
      errors << "Batch A decision register #{label}: #{disposition} requires a capability target" unless kind == 'capability' && nonempty_string?(reference)
    when 'consolidate'
      unless kind == 'consolidation_target' && nonempty_string?(reference) && known_requirements.key?(reference)
        errors << "Batch A decision register #{label}: consolidate requires a known PAR consolidation target"
      end
      errors << "Batch A decision register #{label}: consolidation cannot target itself" if reference == label
    when 'retire', 'exclude'
      unless kind == 'exclusion' && nonempty_string?(reference) && exclusions.is_a?(Array) && !exclusions.empty?
        errors << "Batch A decision register #{label}: #{disposition} requires an exclusion target and explicit exclusions"
      end
    else
      errors << "Batch A decision register #{label}: approved or deferred decision cannot keep canonical_disposition pending"
    end
  end

  def validate_batch_a_consolidation_graph(entries, known_requirements)
    graph = Hash.new { |hash, key| hash[key] = [] }
    register_edges = {}

    rows.each do |row|
      next unless row[:cells].length == MATRIX_COLUMNS.length

      source = row[:cells][0]
      disposition = row[:cells][3]
      next unless disposition.start_with?('Consolidate')

      disposition.scan(/PAR-[A-Z0-9]+-\d{3}/).each do |target|
        graph[source] << target if known_requirements.key?(target)
      end
    end

    entries.each do |entry|
      next unless entry.is_a?(Hash)

      decision = entry['decision']
      next unless decision.is_a?(Hash) && %w[approve defer].include?(decision['status'])
      next unless decision['canonical_disposition'] == 'consolidate'

      target = decision['target']
      next unless target.is_a?(Hash) && target['kind'] == 'consolidation_target'

      source = entry['requirement_id']
      reference = target['reference']
      next unless known_requirements.key?(source) && known_requirements.key?(reference)

      graph[source] << reference
      register_edges[[source, reference]] = true
    end

    detect_batch_a_consolidation_cycles(graph, register_edges)
  end

  def detect_batch_a_consolidation_cycles(graph, register_edges)
    state = {}
    stack = []
    reported = {}

    visit = lambda do |node|
      state[node] = :visiting
      stack << node
      graph[node].uniq.each do |target|
        if state[target] == :visiting
          start = stack.index(target) || 0
          cycle = stack[start..-1] + [target]
          next unless cycle.each_cons(2).any? { |edge| register_edges[edge] }

          key = cycle[0...-1].sort.join('|')
          unless reported[key]
            errors << "Batch A decision register: consolidation cycle detected: #{cycle.join(' -> ')}"
            reported[key] = true
          end
        elsif state[target].nil?
          visit.call(target)
        end
      end
      stack.pop
      state[node] = :visited
    end

    graph.keys.each { |node| visit.call(node) if state[node].nil? }
  end

  def validate_batch_a_scenario(scenario, label, name)
    unless scenario.is_a?(Hash)
      errors << "Batch A decision register #{label}: synthetic scenario #{name} must be an object"
      return
    end

    status = scenario['status']
    errors << "Batch A decision register #{label}: invalid synthetic scenario status #{status.inspect}" unless SCENARIO_STATUSES.include?(status)
    errors << "Batch A decision register #{label}: synthetic scenario #{name} data_class must be synthetic" unless scenario['data_class'] == 'synthetic'
    errors << "Batch A decision register #{label}: synthetic scenario #{name} description must be a non-empty string" unless nonempty_string?(scenario['description'])
    validate_nonempty_string_array(scenario['expected_results'], "Batch A decision register #{label}: synthetic scenario #{name} expected_results")
  end

  def validate_accountable_owner(owner, label)
    unless owner.is_a?(Hash)
      errors << "Batch A decision register #{label}: accountable_owner must be an object"
      return
    end

    status = owner['appointment_status']
    errors << "Batch A decision register #{label}: invalid accountable owner appointment_status #{status.inspect}" unless APPOINTMENT_STATUSES.include?(status)
    errors << "Batch A decision register #{label}: accountable owner required_scope must be a non-empty string" unless nonempty_string?(owner['required_scope'])

    if status == 'pending'
      %w[identity appointed_scope appointment_date appointment_reference artifact_sha256].each do |key|
        errors << "Batch A decision register #{label}: pending accountable owner #{key} must be null" unless owner[key].nil?
      end
    elsif status == 'appointed'
      %w[identity appointed_scope appointment_reference].each do |key|
        errors << "Batch A decision register #{label}: appointed accountable owner #{key} must be a non-empty string" unless nonempty_string?(owner[key])
      end
      if nonempty_string?(owner['identity']) && owner['identity'].match?(PLACEHOLDER_OWNER_PATTERN)
        errors << "Batch A decision register #{label}: appointed accountable owner identity must not be a placeholder"
      end
      unless owner['appointed_scope'] == owner['required_scope']
        errors << "Batch A decision register #{label}: appointed_scope must exactly match required_scope"
      end
      errors << "Batch A decision register #{label}: accountable owner appointment_date must be YYYY-MM-DD" unless iso_date?(owner['appointment_date'])
      validate_governance_artifact(
        reference: owner['appointment_reference'],
        expected_sha256: owner['artifact_sha256'],
        label: "Batch A decision register #{label}: accountable owner appointment",
        requirement_id: label,
        subject: 'accountable_owner',
        record: owner
      )
    end
  end

  def validate_appointment_dependencies(dependencies, co_owners, label)
    if !dependencies.is_a?(Array) || dependencies.empty?
      errors << "Batch A decision register #{label}: appointment_dependencies must be a non-empty array"
      return
    end

    dependencies.each_with_index do |dependency, index|
      unless dependency.is_a?(Hash)
        errors << "Batch A decision register #{label}: appointment_dependencies[#{index}] must be an object"
        next
      end

      prefix = "Batch A decision register #{label}: appointment_dependencies[#{index}]"
      errors << "#{prefix} authority_domain must be a non-empty string" unless nonempty_string?(dependency['authority_domain'])
      errors << "#{prefix} required_scope must be a non-empty string" unless nonempty_string?(dependency['required_scope'])
      status = dependency['status']
      errors << "#{prefix} invalid status #{status.inspect}" unless APPOINTMENT_STATUSES.include?(status)

      if status == 'pending'
        %w[identity date reference artifact_sha256].each do |key|
          errors << "#{prefix} pending #{key} must be null" unless dependency[key].nil?
        end
      elsif status == 'appointed'
        errors << "#{prefix} identity must be a non-empty string" unless nonempty_string?(dependency['identity'])
        if nonempty_string?(dependency['identity']) && dependency['identity'].match?(PLACEHOLDER_OWNER_PATTERN)
          errors << "#{prefix} identity must not be a placeholder"
        end
        errors << "#{prefix} date must be YYYY-MM-DD" unless iso_date?(dependency['date'])
        errors << "#{prefix} reference must be a non-empty string" unless nonempty_string?(dependency['reference'])
        validate_governance_artifact(
          reference: dependency['reference'],
          expected_sha256: dependency['artifact_sha256'],
          label: "#{prefix} appointment",
          requirement_id: label,
          subject: 'appointment_dependency',
          record: dependency
        )
      end
    end

    domains = dependencies.select { |dependency| dependency.is_a?(Hash) && nonempty_string?(dependency['authority_domain']) }
      .map { |dependency| dependency['authority_domain'] }
    duplicates = domains.group_by { |domain| domain }.select { |_domain, values| values.length > 1 }.keys.sort
    errors << "Batch A decision register #{label}: appointment dependency authority_domain values must be unique: #{duplicates.join(', ')}" unless duplicates.empty?

    return unless co_owners.is_a?(Array) && co_owners.all? { |domain| nonempty_string?(domain) }

    missing = co_owners - domains
    unexpected = domains - co_owners
    unless missing.empty? && unexpected.empty?
      errors << "Batch A decision register #{label}: appointment dependencies must exactly cover co_owners; missing #{missing.sort.inspect}; unexpected #{unexpected.sort.inspect}"
    end
  end

  def validate_batch_a_approval(approval, label, decision)
    unless approval.is_a?(Hash)
      errors << "Batch A decision register #{label}: approval must be an object"
      return
    end

    status = approval['status']
    errors << "Batch A decision register #{label}: invalid approval status #{status.inspect}" unless APPROVAL_STATUSES.include?(status)
    unless approval['conditions'].is_a?(Array) && approval['conditions'].all? { |value| nonempty_string?(value) }
      errors << "Batch A decision register #{label}: approval conditions must be an array of non-empty strings"
    end

    if status == 'pending'
      %w[identity scope date reference artifact_sha256].each do |key|
        errors << "Batch A decision register #{label}: pending approval #{key} must be null" unless approval[key].nil?
      end
      errors << "Batch A decision register #{label}: pending approval conditions must be empty" unless approval['conditions'] == []
      if decision.is_a?(Hash) && decision['status'] != 'pending'
        errors << "Batch A decision register #{label}: non-pending decision requires recorded approval"
      end
      return
    end

    if !decision.is_a?(Hash) || decision['status'] == 'pending'
      errors << "Batch A decision register #{label}: recorded approval cannot accompany a pending decision"
    end
    errors << "Batch A decision register #{label}: approval identity must be a non-empty string" unless nonempty_string?(approval['identity'])
    if nonempty_string?(approval['identity']) && approval['identity'].match?(PLACEHOLDER_OWNER_PATTERN)
      errors << "Batch A decision register #{label}: approval identity must not be a placeholder"
    end
    errors << "Batch A decision register #{label}: approval scope must be a non-empty string" unless nonempty_string?(approval['scope'])
    errors << "Batch A decision register #{label}: approval date must be YYYY-MM-DD" unless iso_date?(approval['date'])
    errors << "Batch A decision register #{label}: approval reference must be a non-empty string" unless nonempty_string?(approval['reference'])
    validate_governance_artifact(
      reference: approval['reference'],
      expected_sha256: approval['artifact_sha256'],
      label: "Batch A decision register #{label}: approval",
      requirement_id: label,
      subject: 'approval',
      record: approval,
      decision: decision
    )
  end

  def validate_batch_a_g0_resolution(entry, label)
    decision = entry['decision']
    decision_status = decision['status'] if decision.is_a?(Hash)
    unless %w[approve defer].include?(decision_status)
      errors << "Batch A decision register #{label}: G0 remains open until decision status is approve or defer"
      return
    end

    evidence = entry['evidence']
    unless evidence.is_a?(Array) && evidence.any? { |record| record.is_a?(Hash) && EVIDENCE_CLASSES[0...-1].include?(record['evidence_class']) }
      errors << "Batch A decision register #{label}: G0-approved/deferred entry requires non-pending evidence"
    end
    owner = entry['accountable_owner']
    unless owner.is_a?(Hash) && owner['appointment_status'] == 'appointed'
      errors << "Batch A decision register #{label}: G0-approved/deferred entry requires an appointed accountable owner"
    end
    dependencies = entry['appointment_dependencies']
    unless dependencies.is_a?(Array) && !dependencies.empty? && dependencies.all? { |dependency| dependency.is_a?(Hash) && dependency['status'] == 'appointed' }
      errors << "Batch A decision register #{label}: G0-approved/deferred entry requires all appointment dependencies"
    end
    approval = entry['approval']
    unless approval.is_a?(Hash) && approval['status'] == 'recorded'
      errors << "Batch A decision register #{label}: G0-approved/deferred entry requires a recorded approval"
    end
    scenarios = entry['synthetic_scenarios']
    unless scenarios.is_a?(Hash) && %w[normal denial_or_correction].all? { |name| scenarios[name].is_a?(Hash) && scenarios[name]['status'] == 'ready' }
      errors << "Batch A decision register #{label}: G0-approved/deferred entry requires ready normal and denial/correction scenarios"
    end
  end

  def validate_governance_artifact(reference:, expected_sha256:, label:, requirement_id:, subject:, record:, decision: nil)
    artifact = load_structured_json_artifact(reference, expected_sha256, label)
    return unless artifact

    expected_keys = GOVERNANCE_ARTIFACT_KEYS.fetch(subject)
    validate_closed_object(artifact, expected_keys, label)
    errors << "#{label} artifact_type must be #{GOVERNANCE_ARTIFACT_TYPE}" unless artifact['artifact_type'] == GOVERNANCE_ARTIFACT_TYPE
    errors << "#{label} schema_version must be #{ARTIFACT_SCHEMA_VERSION}" unless artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION
    errors << "#{label} register_id does not match the decision register" unless artifact['register_id'] == @batch_a_register_id
    errors << "#{label} requirement_id does not match #{requirement_id}" unless artifact['requirement_id'] == requirement_id
    errors << "#{label} subject does not match #{subject}" unless artifact['subject'] == subject
    errors << "#{label} identity does not match the register" unless artifact['identity'] == record['identity']

    case subject
    when 'accountable_owner'
      errors << "#{label} scope does not match appointed_scope" unless artifact['scope'] == record['appointed_scope']
      errors << "#{label} date does not match appointment_date" unless artifact['date'] == record['appointment_date']
    when 'appointment_dependency'
      errors << "#{label} authority_domain does not match the register" unless artifact['authority_domain'] == record['authority_domain']
      errors << "#{label} scope does not match required_scope" unless artifact['scope'] == record['required_scope']
      errors << "#{label} date does not match the appointment date" unless artifact['date'] == record['date']
    when 'approval'
      errors << "#{label} scope does not match the register" unless artifact['scope'] == record['scope']
      errors << "#{label} date does not match the approval date" unless artifact['date'] == record['date']
      errors << "#{label} decision_status does not match the register decision" unless decision.is_a?(Hash) && artifact['decision_status'] == decision['status']
      errors << "#{label} canonical_disposition does not match the register decision" unless decision.is_a?(Hash) && artifact['canonical_disposition'] == decision['canonical_disposition']
      errors << "#{label} conditions do not match the register" unless artifact['conditions'] == record['conditions']
    end

    validate_artifact_reviewer(artifact['reviewer'], artifact['identity'], label)
  end

  def validate_evidence_artifact(record, requirement_id, index)
    label = "Batch A decision register #{requirement_id}: evidence[#{index}]"
    artifact = load_structured_json_artifact(record['artifact_reference'], record['artifact_sha256'], label)
    return unless artifact

    validate_closed_object(artifact, EVIDENCE_ARTIFACT_KEYS, label)
    errors << "#{label} artifact_type must be #{EVIDENCE_ARTIFACT_TYPE}" unless artifact['artifact_type'] == EVIDENCE_ARTIFACT_TYPE
    errors << "#{label} schema_version must be #{ARTIFACT_SCHEMA_VERSION}" unless artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION
    errors << "#{label} register_id does not match the decision register" unless artifact['register_id'] == @batch_a_register_id
    errors << "#{label} requirement_id does not match #{requirement_id}" unless artifact['requirement_id'] == requirement_id
    %w[evidence_class date source reference interpreter confidence].each do |key|
      errors << "#{label} #{key} does not match the register" unless artifact[key] == record[key]
    end
    validate_artifact_reviewer(artifact['reviewer'], artifact['interpreter'], label)
  end

  def validate_artifact_reviewer(reviewer, subject_identity, label)
    unless reviewer.is_a?(Hash)
      errors << "#{label} reviewer must be an object"
      return
    end

    validate_closed_object(reviewer, REVIEWER_KEYS, "#{label} reviewer")
    errors << "#{label} reviewer identity must be a non-placeholder string" unless nonempty_string?(reviewer['identity']) && !reviewer['identity'].match?(PLACEHOLDER_OWNER_PATTERN)
    if nonempty_string?(reviewer['identity']) && nonempty_string?(subject_identity) && reviewer['identity'].strip.casecmp?(subject_identity.strip)
      errors << "#{label} reviewer identity must be distinct from the subject"
    end
    unless VERIFICATION_METHODS.include?(reviewer['verification_method'])
      errors << "#{label} reviewer verification_method must be one of #{VERIFICATION_METHODS.join(', ')}"
    end
    errors << "#{label} reviewer verification_reference must be a non-empty string" unless nonempty_string?(reviewer['verification_reference'])
  end

  def validate_closed_object(value, expected_keys, label)
    unless value.is_a?(Hash)
      errors << "#{label} must be a JSON object"
      return false
    end

    missing = expected_keys - value.keys
    unknown = value.keys - expected_keys
    errors << "#{label} missing fields: #{missing.sort.join(', ')}" unless missing.empty?
    errors << "#{label} unknown fields: #{unknown.sort.join(', ')}" unless unknown.empty?
    missing.empty? && unknown.empty?
  end

  def load_structured_json_artifact(reference, expected_sha256, label)
    unless nonempty_string?(reference)
      errors << "#{label} must reference an existing signed artifact"
      return
    end
    unless expected_sha256.is_a?(String) && expected_sha256.match?(/\A[0-9a-f]{64}\z/i)
      errors << "#{label} artifact_sha256 must be a 64-character hexadecimal digest"
      return
    end

    register_directory = File.realpath(File.dirname(@decision_register_path))
    evidence_root = File.expand_path(BATCH_A_EVIDENCE_DIRECTORY, register_directory)
    path = File.expand_path(reference, register_directory)
    allowed_prefix = "#{evidence_root}#{File::SEPARATOR}"
    unless path.start_with?(allowed_prefix)
      errors << "#{label} signed artifact must remain inside the decision-register evidence directory"
      return
    end
    unless File.extname(path).casecmp?('.json')
      errors << "#{label} signed artifact must be a JSON file"
      return
    end
    unless File.file?(path)
      errors << "#{label} signed artifact does not exist: #{reference}"
      return
    end
    unless File.directory?(evidence_root) && File.lstat(evidence_root).directory?
      errors << "#{label} evidence directory must be a regular directory under the decision register"
      return
    end
    unless File.lstat(path).file?
      errors << "#{label} signed artifact must be a regular JSON file"
      return
    end
    real_evidence_root = File.realpath(evidence_root)
    register_prefix = "#{register_directory}#{File::SEPARATOR}"
    unless real_evidence_root.start_with?(register_prefix)
      errors << "#{label} signed artifact must remain inside the decision-register evidence directory"
      return
    end
    real_path = File.realpath(path)
    real_allowed_prefix = "#{real_evidence_root}#{File::SEPARATOR}"
    unless real_path.start_with?(real_allowed_prefix)
      errors << "#{label} signed artifact must remain inside the decision-register evidence directory"
      return
    end
    actual_sha256 = Digest::SHA256.file(real_path).hexdigest
    unless actual_sha256.casecmp?(expected_sha256)
      errors << "#{label} artifact_sha256 does not match #{reference}"
      return
    end

    artifact = JSON.parse(File.read(real_path))
    unless artifact.is_a?(Hash)
      errors << "#{label} must contain one JSON object"
      return
    end
    artifact
  rescue JSON::ParserError => e
    errors << "#{label} contains invalid JSON: #{e.message}"
    nil
  rescue SystemCallError => e
    errors << "#{label} cannot read signed artifact: #{e.message}"
    nil
  end

  def validate_nonempty_string_array(value, label)
    unless value.is_a?(Array) && !value.empty? && value.all? { |item| nonempty_string?(item) }
      errors << "#{label} must be a non-empty array of non-empty strings"
      return
    end

    errors << "#{label} must not contain duplicates" unless value.uniq.length == value.length
  end

  def nonempty_string?(value)
    value.is_a?(String) && !value.strip.empty?
  end

  def iso_date?(value)
    return false unless nonempty_string?(value)

    Date.iso8601(value)
    true
  rescue ArgumentError
    false
  end

  def validate_batch_manifest(baseline, manifest)
    unless manifest['schema_version'] == 1
      errors << 'batch manifest: schema_version must be 1'
    end
    unless manifest['dependency_order'] == EXPECTED_BATCH_COUNTS.keys
      errors << "batch manifest: dependency_order must be #{EXPECTED_BATCH_COUNTS.keys.inspect}"
    end
    unless manifest['expected_counts'] == EXPECTED_BATCH_COUNTS
      errors << "batch manifest: expected_counts must be #{EXPECTED_BATCH_COUNTS.inspect}"
    end

    batches = manifest['batches']
    unless batches.is_a?(Hash)
      errors << 'batch manifest: batches must be an object'
      return
    end

    actual_batch_keys = batches.keys.sort
    expected_batch_keys = EXPECTED_BATCH_COUNTS.keys
    missing_batches = expected_batch_keys - actual_batch_keys
    unexpected_batches = actual_batch_keys - expected_batch_keys
    errors << "batch manifest: missing batches: #{missing_batches.join(', ')}" unless missing_batches.empty?
    errors << "batch manifest: invalid batches: #{unexpected_batches.join(', ')}" unless unexpected_batches.empty?

    occurrences = Hash.new { |hash, key| hash[key] = [] }
    expected_batch_keys.each do |batch|
      ids = batches[batch]
      unless ids.is_a?(Array) && ids.all? { |id| id.is_a?(String) }
        errors << "batch manifest: batch #{batch} must be an array of requirement IDs"
        next
      end

      errors << "batch manifest: batch #{batch} IDs must be sorted" if ids != ids.sort
      if ids.length != EXPECTED_BATCH_COUNTS[batch]
        errors << "batch manifest: batch #{batch} must contain exactly #{EXPECTED_BATCH_COUNTS[batch]} IDs, got #{ids.length}"
      end

      ids.each do |id|
        occurrences[id] << batch
        @batch_assignments[id] = batch unless @batch_assignments.key?(id)
      end
    end

    all_ids = occurrences.keys
    invalid_ids = all_ids.reject { |id| id.match?(PAR_ID_PATTERN) }.sort
    errors << "batch manifest: invalid requirement IDs: #{invalid_ids.join(', ')}" unless invalid_ids.empty?

    duplicates = occurrences.select { |_id, assigned_batches| assigned_batches.length > 1 }
    unless duplicates.empty?
      rendered = duplicates.keys.sort.map { |id| "#{id} (#{duplicates[id].join(', ')})" }
      errors << "batch manifest: requirement IDs assigned more than once: #{rendered.join('; ')}"
    end

    expected_ids = baseline['expected'].keys
    missing = expected_ids - all_ids
    unexpected = all_ids - expected_ids
    errors << "batch manifest: missing baseline requirement IDs: #{missing.sort.join(', ')}" unless missing.empty?
    errors << "batch manifest: unexpected requirement IDs: #{unexpected.sort.join(', ')}" unless unexpected.empty?
    assignment_count = occurrences.values.map(&:length).inject(0, :+)
    errors << "batch manifest: expected exactly 268 assignments, got #{assignment_count}" if assignment_count != 268

    batch_a_ids = batches['A'].is_a?(Array) ? batches['A'].sort : []
    missing_from_a = EXPECTED_BATCH_A_IDS - batch_a_ids
    unexpected_in_a = batch_a_ids - EXPECTED_BATCH_A_IDS
    unless missing_from_a.empty? && unexpected_in_a.empty?
      errors << "batch manifest: Batch A exact set mismatch; missing #{missing_from_a.sort.inspect}; unexpected #{unexpected_in_a.sort.inspect}"
    end
  end

  def parse_matrix
    unless File.file?(@matrix_path)
      errors << "matrix: file not found: #{@matrix_path}"
      return
    end

    in_matrix = false
    header_seen = false
    File.readlines(@matrix_path).each_with_index do |line, index|
      stripped = line.strip
      if stripped == '## Matrix'
        in_matrix = true
        next
      end
      break if in_matrix && stripped.start_with?('## ')
      next unless in_matrix

      cells = markdown_cells(line)
      next unless cells
      next if separator_row?(cells)

      unless header_seen
        @matrix_header = cells
        header_seen = true
        next
      end

      rows << { cells: cells, line: index + 1 }
    end

    errors << 'matrix: missing ## Matrix section or table header' unless header_seen
  rescue SystemCallError => e
    errors << "matrix: cannot read file: #{e.message}"
  end

  def parse_release_index
    unless File.file?(@release_index_path)
      errors << "release index: file not found: #{@release_index_path}"
      return
    end

    in_register = false
    header_seen = false
    File.readlines(@release_index_path).each_with_index do |line, index|
      stripped = line.strip
      if stripped == '## Register'
        in_register = true
        next
      end
      break if in_register && stripped.start_with?('## ')
      next unless in_register

      cells = markdown_cells(line)
      next unless cells
      next if separator_row?(cells)

      unless header_seen
        @release_header = cells
        header_seen = true
        next
      end

      release_rows << { cells: cells, line: index + 1 }
    end

    errors << 'release index: missing ## Register section or table header' unless header_seen
  rescue SystemCallError => e
    errors << "release index: cannot read file: #{e.message}"
  end

  def markdown_cells(line)
    stripped = line.strip
    return nil unless stripped.start_with?('|') && stripped.end_with?('|')

    cells = []
    current = +''
    escaped = false
    stripped[1...-1].each_char do |character|
      if character == '|' && !escaped
        cells << current.strip
        current = +''
      else
        current << character
      end
      escaped = character == '\\' && !escaped
      escaped = false unless character == '\\'
    end
    cells << current.strip
    cells
  end

  def separator_row?(cells)
    !cells.empty? && cells.all? { |cell| cell.match?(/\A:?-{3,}:?\z/) }
  end

  def validate_matrix_header
    return unless @matrix_header
    return if @matrix_header == MATRIX_COLUMNS

    errors << "matrix: expected canonical 10-column header #{MATRIX_COLUMNS.inspect}, got #{@matrix_header.inspect}"
  end

  def validate_matrix_shape_and_cells
    rows.each do |row|
      cells = row[:cells]
      if cells.length != MATRIX_COLUMNS.length
        errors << "matrix line #{row[:line]}: expected 10 cells, got #{cells.length}"
        next
      end

      cells.each_with_index do |cell, index|
        if cell.strip.empty?
          errors << "matrix line #{row[:line]}: #{MATRIX_COLUMNS[index]} must not be empty"
        end
      end
    end
  end

  def validate_exact_id_set(baseline)
    expected = baseline['expected'].keys
    actual = rows.select { |row| row[:cells].length == MATRIX_COLUMNS.length }.map { |row| row[:cells][0] }

    invalid = actual.reject { |id| id.match?(PAR_ID_PATTERN) }.uniq.sort
    errors << "matrix: invalid requirement IDs: #{invalid.join(', ')}" unless invalid.empty?

    duplicates = actual.group_by { |id| id }.select { |_id, values| values.length > 1 }.keys.sort
    errors << "matrix: duplicate requirement IDs: #{duplicates.join(', ')}" unless duplicates.empty?

    missing = expected - actual
    unexpected = actual - expected
    errors << "matrix: missing baseline requirement IDs: #{missing.sort.join(', ')}" unless missing.empty?
    errors << "matrix: unexpected requirement IDs: #{unexpected.sort.join(', ')}" unless unexpected.empty?

    if actual.length != 268
      errors << "matrix: expected exactly 268 data rows, got #{actual.length}"
    end
  end

  def validate_categories_and_prefixes(baseline)
    expected = baseline['expected']
    rows.each do |row|
      next unless row[:cells].length == MATRIX_COLUMNS.length

      id = row[:cells][0]
      definition = expected[id]
      next unless definition

      category = row[:cells][1]
      if category != definition['legacy_category']
        errors << "matrix line #{row[:line]} #{id}: category #{category.inspect} must be #{definition['legacy_category'].inspect}"
      end

      actual_prefix = id.split('-')[1]
      if actual_prefix != definition['prefix']
        errors << "matrix line #{row[:line]} #{id}: prefix #{actual_prefix.inspect} must be #{definition['prefix'].inspect}"
      end
    end
  end

  def validate_vocabularies
    rows.each do |row|
      next unless row[:cells].length == MATRIX_COLUMNS.length

      id = row[:cells][0]
      disposition = row[:cells][3]
      status = row[:cells][5]

      unless valid_disposition?(disposition)
        errors << "matrix line #{row[:line]} #{id}: invalid disposition #{disposition.inspect}"
      end
      unless PARITY_STATUSES.include?(status)
        errors << "matrix line #{row[:line]} #{id}: invalid parity status #{status.inspect}"
      end
    end
  end

  def valid_disposition?(value)
    return true if value.match?(/\A(?:Reproduce|Replace|Retire|Pending evidence)(?: \([^()]+\))?\z/)

    value.match?(/\AConsolidate\s*(?:→|->)\s*PAR-[A-Z0-9]+-\d{3}(?:\s*\/\s*PAR-[A-Z0-9]+-\d{3})*(?: \([^()]+\))?\z/)
  end

  def validate_owners
    rows.each do |row|
      next unless row[:cells].length == MATRIX_COLUMNS.length

      id = row[:cells][0]
      status = row[:cells][5]
      owner = row[:cells][6]
      if PROMOTED_STATUSES.include?(status) && placeholder_only_owner?(owner)
        errors << "matrix line #{row[:line]} #{id}: promoted status #{status.inspect} requires a non-placeholder accountable owner"
      end
      if @mode == 'g0' && owner.match?(PLACEHOLDER_OWNER_PATTERN)
        errors << "matrix line #{row[:line]} #{id}: g0 forbids placeholder owner #{owner.inspect}"
      end
    end
  end

  def placeholder_only_owner?(owner)
    return false unless owner.match?(PLACEHOLDER_OWNER_PATTERN)

    clauses = owner.split(/\s*(?:;|\+|\/)\s*/)
    clauses.none? do |clause|
      next false if clause.empty? || clause.match?(PLACEHOLDER_OWNER_PATTERN)

      clause.match?(/\bDaniel\b/i) ||
        clause.match?(/\bRMIK Department\b/i) ||
        clause.match?(/\bUEU\b/i) ||
        clause.scan(/[[:alpha:]]+/).length >= 2
    end
  end

  def validate_consolidations
    graph = Hash.new { |hash, key| hash[key] = [] }
    known = rows.select { |row| row[:cells].length == MATRIX_COLUMNS.length }.map { |row| row[:cells][0] }

    rows.each do |row|
      next unless row[:cells].length == MATRIX_COLUMNS.length

      id = row[:cells][0]
      disposition = row[:cells][3]
      next unless disposition.start_with?('Consolidate')

      targets = disposition.scan(/PAR-[A-Z0-9]+-\d{3}/)
      if targets.empty?
        errors << "matrix line #{row[:line]} #{id}: Consolidate requires at least one canonical PAR target"
        next
      end
      targets.each do |target|
        errors << "matrix line #{row[:line]} #{id}: consolidation target #{target} does not exist" unless known.include?(target)
        errors << "matrix line #{row[:line]} #{id}: consolidation cannot target itself" if target == id
        graph[id] << target
      end
    end

    detect_consolidation_cycles(graph)
  end

  def detect_consolidation_cycles(graph)
    state = {}
    stack = []
    reported = {}

    visit = lambda do |node|
      state[node] = :visiting
      stack << node
      graph[node].each do |target|
        if state[target] == :visiting
          start = stack.index(target) || 0
          cycle = stack[start..-1] + [target]
          key = cycle.sort.join('|')
          unless reported[key]
            errors << "matrix: consolidation cycle detected: #{cycle.join(' -> ')}"
            reported[key] = true
          end
        elsif state[target].nil?
          visit.call(target)
        end
      end
      stack.pop
      state[node] = :visited
    end

    graph.keys.each { |node| visit.call(node) if state[node].nil? }
  end

  def validate_release_register
    if @release_header && @release_header != RELEASE_COLUMNS
      errors << "release index: expected canonical 10-column header #{RELEASE_COLUMNS.inspect}, got #{@release_header.inspect}"
    end

    ids = []
    release_rows.each do |row|
      cells = row[:cells]
      if cells.length != RELEASE_COLUMNS.length
        errors << "release index line #{row[:line]}: expected 10 cells, got #{cells.length}"
        next
      end
      cells.each_with_index do |cell, index|
        errors << "release index line #{row[:line]}: #{RELEASE_COLUMNS[index]} must not be empty" if cell.strip.empty?
      end

      id = cells[0]
      ids << id
      match = id.match(REL_ID_PATTERN)
      unless match
        errors << "release index line #{row[:line]}: invalid release evidence ID #{id.inspect}"
        next
      end

      begin
        Date.strptime(match[1], '%Y%m%d')
      rescue ArgumentError
        errors << "release index line #{row[:line]}: release evidence ID has invalid date #{id.inspect}"
      end

      begin
        date = Date.iso8601(cells[1])
        if date.strftime('%Y%m%d') != match[1]
          errors << "release index line #{row[:line]} #{id}: Date must match the date encoded in the evidence ID"
        end
      rescue ArgumentError
        errors << "release index line #{row[:line]} #{id}: invalid ISO date #{cells[1].inspect}"
      end
    end

    duplicates = ids.group_by { |id| id }.select { |_id, values| values.length > 1 }.keys.sort
    errors << "release index: duplicate evidence IDs: #{duplicates.join(', ')}" unless duplicates.empty?
  end

  def validate_accepted_rows
    releases = release_rows.select { |row| row[:cells].length == RELEASE_COLUMNS.length }.each_with_object({}) do |row, index|
      index[row[:cells][0]] = row
    end

    rows.each do |row|
      next unless row[:cells].length == MATRIX_COLUMNS.length
      next unless row[:cells][5] == 'Accepted'

      validate_accepted_row(row, releases)
    end
  end

  def validate_accepted_row(row, releases)
    cells = row[:cells]
    id = cells[0]
    owner = cells[6]
    target = cells[7]
    detail_cell = cells[8]
    acceptance_cell = cells[9]

    if owner.match?(PLACEHOLDER_OWNER_PATTERN)
      errors << "matrix line #{row[:line]} #{id}: Accepted owner must not contain placeholders"
    end
    if target.match?(EVIDENCE_PLACEHOLDER_PATTERN)
      errors << "matrix line #{row[:line]} #{id}: Accepted target capability must be final"
    end

    detail_links = existing_local_links(detail_cell, @matrix_path, "#{id} detailed requirement")
    if detail_links.empty?
      errors << "matrix line #{row[:line]} #{id}: Accepted requires an existing local detailed-requirement artifact link"
    end

    acceptance_links = existing_local_links(acceptance_cell, @matrix_path, "#{id} acceptance")
    if acceptance_links.empty?
      errors << "matrix line #{row[:line]} #{id}: Accepted requires an existing local acceptance artifact link"
    end

    release_ids = acceptance_cell.scan(/REL-\d{8}-\d{2}/).uniq
    if release_ids.length != 1
      errors << "matrix line #{row[:line]} #{id}: Accepted requires exactly one REL-YYYYMMDD-NN reference"
      return
    end
    release_id = release_ids.first

    decisions = acceptance_links.map { |path| acceptance_frontmatter(path) }.compact
    matching = decisions.find do |frontmatter|
      frontmatter['parity_requirement_id'] == id &&
        frontmatter['decision'] == 'Accepted' &&
        frontmatter['release_evidence_id'] == release_id
    end
    unless matching
      errors << "matrix line #{row[:line]} #{id}: no acceptance artifact has matching Accepted frontmatter and release_evidence_id"
    else
      validate_acceptance_frontmatter(id, matching)
    end

    release = releases[release_id]
    unless release
      errors << "matrix line #{row[:line]} #{id}: release evidence #{release_id} is not registered"
      return
    end
    validate_accepted_release(id, release)
  end

  def acceptance_frontmatter(path)
    lines = File.readlines(path)
    return nil unless lines.first && lines.first.strip == '---'

    closing = lines[1..-1].index { |line| line.strip == '---' }
    return nil unless closing

    values = {}
    lines[1, closing].each do |line|
      next if line.strip.empty? || line.lstrip.start_with?('#')

      match = line.match(/\A([a-z_]+):\s*(.*?)\s*\z/)
      return nil unless match
      return nil if values.key?(match[1])

      values[match[1]] = unquote(match[2])
    end
    values
  rescue SystemCallError
    nil
  end

  def unquote(value)
    if value.length >= 2 && ((value.start_with?('"') && value.end_with?('"')) || (value.start_with?("'") && value.end_with?("'")))
      value[1...-1]
    else
      value
    end
  end

  def validate_acceptance_frontmatter(id, frontmatter)
    missing = REQUIRED_ACCEPTANCE_KEYS.reject { |key| frontmatter.key?(key) && !frontmatter[key].strip.empty? }
    unless missing.empty?
      errors << "#{id}: acceptance artifact frontmatter is missing: #{missing.join(', ')}"
      return
    end

    %w[business_owner domain_owner].each do |key|
      if frontmatter[key].match?(PLACEHOLDER_OWNER_PATTERN)
        errors << "#{id}: acceptance artifact #{key} must not contain a placeholder"
      end
    end

    begin
      Date.iso8601(frontmatter['decision_date'])
    rescue ArgumentError
      errors << "#{id}: acceptance artifact decision_date must be YYYY-MM-DD"
    end

    unless frontmatter['release_evidence_id'].match?(REL_ID_PATTERN)
      errors << "#{id}: acceptance artifact release_evidence_id is invalid"
    end
  end

  def validate_accepted_release(id, row)
    cells = row[:cells]
    release_id = cells[0]
    committed = cells[4]
    pushed = cells[5]
    deployed = cells[6]
    reconciliation = cells[7]
    gate = cells[8]
    rollback = cells[9]

    if committed.match?(EVIDENCE_PLACEHOLDER_PATTERN) || !committed.match?(/\b[0-9a-f]{7,40}\b/i)
      errors << "#{id}: #{release_id} must record a concrete commit SHA"
    end
    if pushed.match?(EVIDENCE_PLACEHOLDER_PATTERN) || !pushed.match?(/(?:\borigin\/|\brefs\/|https?:\/\/|git@)/i)
      errors << "#{id}: #{release_id} must record a concrete pushed remote ref"
    end
    if deployed.match?(EVIDENCE_PLACEHOLDER_PATTERN) || !deployed.match?(/(?:https?:\/\/|\bdpl_|\bdeployment\b|\bVercel\b|\bdemo\b|\bUAT\b|\bproduction\b)/i)
      errors << "#{id}: #{release_id} must record a concrete deployment environment and URL or ID"
    end
    if reconciliation.match?(EVIDENCE_PLACEHOLDER_PATTERN)
      errors << "#{id}: #{release_id} must record authz, audit and reconciliation evidence"
    end
    if existing_local_links(reconciliation, @release_index_path, "#{release_id} reconciliation").empty?
      errors << "#{id}: #{release_id} authz/audit/reconciliation must link an existing local artifact"
    end
    unless gate.match?(/\bG[0-9]+\s+PASS\b/i)
      errors << "#{id}: #{release_id} gate must record Gx PASS"
    end
    if rollback.match?(EVIDENCE_PLACEHOLDER_PATTERN)
      errors << "#{id}: #{release_id} must record a concrete rollback or restore path"
    end
  end

  def existing_local_links(cell, source_path, label)
    markdown_links(cell).each_with_object([]) do |target, paths|
      next if target.match?(/\A(?:https?:|mailto:|#)/i)

      clean = target.split('#', 2).first.strip
      next if clean.empty?

      resolved = File.expand_path(clean, File.dirname(source_path))
      if File.file?(resolved)
        paths << resolved
      else
        errors << "#{label}: linked artifact does not exist: #{clean}"
      end
    end
  end

  def markdown_links(cell)
    cell.scan(/\[[^\]]*\]\(([^)\s]+)(?:\s+['"][^'"]*['"])?\)/).flatten
  end
end

if $PROGRAM_NAME == __FILE__
  options = {
    mode: 'integrity',
    matrix: 'docs/new-simrs-rebuild/PARITY_REQUIREMENTS_MATRIX.md',
    baseline: 'docs/new-simrs-rebuild/phase-0/PARITY_MATRIX_BASELINE.json',
    batch_manifest: 'docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json',
    decision_register: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_A_DECISION_REGISTER_2026-08-25.json',
    release_index: 'docs/new-simrs-rebuild/phase-0/RELEASE_EVIDENCE_INDEX.md'
  }

  parser = OptionParser.new do |opts|
    opts.banner = 'Usage: ruby scripts/validate-parity-governance.rb [options]'
    opts.on('--mode MODE', %w[integrity g0], 'integrity (default) or g0') { |value| options[:mode] = value }
    opts.on('--matrix PATH', 'parity matrix Markdown path') { |value| options[:matrix] = value }
    opts.on('--baseline PATH', 'immutable matrix baseline JSON path') { |value| options[:baseline] = value }
    opts.on('--batch-manifest PATH', 'deterministic G0 batch manifest JSON path') { |value| options[:batch_manifest] = value }
    opts.on('--decision-register PATH', 'Batch A G0 decision register JSON path') { |value| options[:decision_register] = value }
    opts.on('--release-index PATH', 'release evidence index Markdown path') { |value| options[:release_index] = value }
  end

  begin
    parser.parse!
  rescue OptionParser::ParseError => e
    warn e.message
    warn parser
    exit 2
  end

  validator = ParityGovernanceValidator.new(
    matrix_path: options[:matrix],
    baseline_path: options[:baseline],
    batch_manifest_path: options[:batch_manifest],
    decision_register_path: options[:decision_register],
    release_index_path: options[:release_index],
    mode: options[:mode]
  )

  if validator.validate
    puts "Parity governance #{options[:mode]} validation passed: #{validator.rows.length} requirements, #{validator.batch_assignments.length} batch assignments, #{validator.decision_entries.length} Batch A decisions, #{validator.release_rows.length} release evidence rows"
  else
    warn "Parity governance #{options[:mode]} validation failed (#{validator.errors.length} errors):"
    validator.errors.each { |error| warn "- #{error}" }
    exit 1
  end
end
