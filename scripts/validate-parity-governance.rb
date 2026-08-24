#!/usr/bin/env ruby
# frozen_string_literal: true

require 'date'
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

  PAR_ID_PATTERN = /\APAR-[A-Z0-9]+-\d{3}\z/.freeze
  REL_ID_PATTERN = /\AREL-(\d{8})-(\d{2})\z/.freeze
  PLACEHOLDER_OWNER_PATTERN = /(?:\bTBD\b|\bunknown\b|\bunassigned\b|\bpending\b|to[ _-]?be[ _-]?assigned|replace[ _-]?with|\bN\/?A\b)/i.freeze
  EVIDENCE_PLACEHOLDER_PATTERN = /(?:\bpending\b|not (?:committed|pushed|deployed|verified)|filled at commit|updated after push|\bunknown\b|\bTBD\b|\bN\/?A\b)/i.freeze

  attr_reader :errors, :rows, :release_rows

  def initialize(matrix_path:, baseline_path:, release_index_path:, mode: 'integrity')
    @matrix_path = File.expand_path(matrix_path)
    @baseline_path = File.expand_path(baseline_path)
    @release_index_path = File.expand_path(release_index_path)
    @mode = mode
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
    parse_matrix
    parse_release_index

    validate_matrix_header
    validate_matrix_shape_and_cells
    validate_exact_id_set(baseline)
    validate_categories_and_prefixes(baseline)
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
    release_index: 'docs/new-simrs-rebuild/phase-0/RELEASE_EVIDENCE_INDEX.md'
  }

  parser = OptionParser.new do |opts|
    opts.banner = 'Usage: ruby scripts/validate-parity-governance.rb [options]'
    opts.on('--mode MODE', %w[integrity g0], 'integrity (default) or g0') { |value| options[:mode] = value }
    opts.on('--matrix PATH', 'parity matrix Markdown path') { |value| options[:matrix] = value }
    opts.on('--baseline PATH', 'immutable matrix baseline JSON path') { |value| options[:baseline] = value }
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
    release_index_path: options[:release_index],
    mode: options[:mode]
  )

  if validator.validate
    puts "Parity governance #{options[:mode]} validation passed: #{validator.rows.length} requirements, #{validator.release_rows.length} release evidence rows"
  else
    warn "Parity governance #{options[:mode]} validation failed (#{validator.errors.length} errors):"
    validator.errors.each { |error| warn "- #{error}" }
    exit 1
  end
end
