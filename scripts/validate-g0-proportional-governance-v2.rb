#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'json'
require 'optparse'
require 'pathname'
require 'time'

require_relative 'g0-proportional-governance-v2'
require_relative 'compare-g0-governance-v1-v2'

# Standalone, read-only governance-v2 candidate validator.
#
# Wave 4 deliberately supports candidate observation only. It does not read a
# consumer pointer, select or activate a bundle, mutate governance state, or
# confer owner, disposition, implementation, deployment, or gate authority.
module G0ProportionalGovernanceV2ValidatorCLI
  class Error < StandardError; end
  class UsageError < Error; end
  class ReceiptError < Error; end
  class ValidationFailure < Error; end

  Core = G0ProportionalGovernanceV2
  Comparator = G0GovernanceV1V2Comparator

  CONTRACT_PATH = 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_CONTRACT.json'
  BUNDLE_MANIFEST = Comparator::BUNDLE_FILE
  TEST_ROOT_GUARD = 'G0_GOVERNANCE_V2_TEST_ROOT'
  MODES = %w[integrity g0].freeze
  SOURCES = %w[candidate active].freeze
  RECEIPT_KEYS = %w[
    schema_version operation_id operation profile mode source status reason_code
    message actor started_at finished_at planning_baseline adoption_sha256
    activation_sha256 prior_pointer_sha256 selection_sha256 bundle_sha256
    validator_contract secret_scan_passed
  ].freeze

  module_function

  def run_cli(argv, stdout: $stdout, stderr: $stderr, env: ENV, now: -> { Time.now.utc })
    options = parse_options(argv)
    root = validate_root!(options.fetch(:root), env)
    receipt_path = options[:json_receipt] && new_receipt_path!(root, options.fetch(:json_receipt))
    started_at = timestamp(now)

    if options.fetch(:source) == 'active'
      receipt = build_receipt(
        mode: options.fetch(:mode), source: 'active', status: 'NOT_READY',
        reason_code: 'active_source_not_ready',
        message: 'Active governance v2 validation is not available before the shared pointer resolver.',
        started_at: started_at, finished_at: timestamp(now)
      )
      publish_receipt!(root, receipt_path, receipt) if receipt_path
      stderr.puts('validation_failed: active source is not ready in governance v2 Wave 4')
      return 1
    end

    context = validate_candidate!(root, options)
    status, reason_code, message = observation_result(options.fetch(:mode))
    receipt = build_receipt(
      mode: options.fetch(:mode), source: 'candidate', status: status,
      reason_code: reason_code, message: message,
      started_at: started_at, finished_at: timestamp(now), **context
    )
    publish_receipt!(root, receipt_path, receipt) if receipt_path
    stdout.write(Core.canonical_json(receipt) + "\n")
    0
  rescue OptionParser::ParseError
    stderr.puts('usage_error: invalid command-line usage')
    2
  rescue UsageError => e
    stderr.puts("usage_error: #{e.message}")
    2
  rescue ReceiptError
    stderr.puts('validation_failed: receipt path or exclusive write rejected')
    1
  rescue ValidationFailure, Comparator::ComparisonError, Core::Error, KeyError, SystemCallError
    if defined?(receipt_path) && receipt_path && defined?(options) && options.is_a?(Hash) &&
       MODES.include?(options[:mode]) && SOURCES.include?(options[:source])
      failure = build_receipt(
        mode: options.fetch(:mode), source: options.fetch(:source), status: 'FAIL',
        reason_code: 'candidate_contract_failed',
        message: 'Candidate governance v2 or its adopted-source binding failed validation.',
        started_at: (defined?(started_at) && started_at) || timestamp(now),
        finished_at: timestamp(now)
      )
      begin
        publish_receipt!(root, receipt_path, failure)
      rescue ReceiptError
        stderr.puts('validation_failed: receipt path or exclusive write rejected')
        return 1
      end
    end
    stderr.puts('validation_failed: candidate or adopted-source contract rejected')
    1
  end

  def parse_options(argv)
    options = { root: canonical_checkout.to_s }
    seen = {}
    parser = OptionParser.new do |opts|
      opts.banner = <<~USAGE.strip
        Usage: validate-g0-proportional-governance-v2.rb --mode integrity|g0 --source candidate \
          --candidate-bundle PATH --adoption-decision PATH [--root PATH] [--json-receipt PATH]
      USAGE
      unique_option(opts, options, seen, :mode, '--mode MODE')
      unique_option(opts, options, seen, :source, '--source SOURCE')
      unique_option(opts, options, seen, :candidate_bundle, '--candidate-bundle PATH')
      unique_option(opts, options, seen, :adoption_decision, '--adoption-decision PATH')
      unique_option(opts, options, seen, :root, '--root PATH')
      unique_option(opts, options, seen, :json_receipt, '--json-receipt PATH')
    end

    args = argv.dup
    parser.parse!(args)
    raise UsageError, 'unexpected positional arguments' unless args.empty?
    raise UsageError, '--mode is required' unless options.key?(:mode)
    raise UsageError, '--mode must be integrity or g0' unless MODES.include?(options.fetch(:mode))
    raise UsageError, '--source is required' unless options.key?(:source)
    raise UsageError, '--source must be candidate or active' unless SOURCES.include?(options.fetch(:source))

    if options.fetch(:source) == 'candidate'
      raise UsageError, '--candidate-bundle is required for candidate source' unless options.key?(:candidate_bundle)
      raise UsageError, '--adoption-decision is required for candidate source' unless options.key?(:adoption_decision)
    else
      raise UsageError, '--candidate-bundle is irrelevant for active source' if options.key?(:candidate_bundle)
      raise UsageError, '--adoption-decision is irrelevant for active source' if options.key?(:adoption_decision)
    end
    options
  end

  def unique_option(parser, options, seen, key, declaration)
    parser.on(declaration) do |value|
      raise UsageError, "#{declaration.split.first} specified more than once" if seen[key]
      raise UsageError, "#{declaration.split.first} requires a nonempty value" if value.nil? || value.strip.empty?

      seen[key] = true
      options[key] = value
    end
  end
  private_class_method :unique_option

  def validate_candidate!(root, options)
    contract_path = secure_regular_file!(root.join(CONTRACT_PATH), root, '$.validator_contract')
    contract = Core.parse_json_file(contract_path, label: '$.validator_contract')
    Core.validate_contract!(contract, root: root.to_s)

    adoption_binding = contract.fetch('adopted_sources').fetch('adoption_decision')
    adoption_path = option_path(options.fetch(:adoption_decision), root)
    expected_adoption = root.join(adoption_binding.fetch('path')).expand_path
    raise ValidationFailure, 'unexpected adoption decision path' unless adoption_path == expected_adoption

    adoption_path = secure_regular_file!(adoption_path, root, '$.adoption_decision')
    adoption_sha = Digest::SHA256.file(adoption_path).hexdigest
    raise ValidationFailure, 'adoption decision hash mismatch' unless adoption_sha == adoption_binding.fetch('sha256')
    adoption = Core.parse_json_file(adoption_path, label: '$.adoption_decision')
    # The immutable adoption record intentionally contains a closed
    # `secret_handling` policy object. Its exact contract-bound byte hash, not
    # the generic candidate secret scanner, authenticates that trusted source.
    unless adoption.fetch('artifact_type') == 'g0_governance_v2_adoption_decision' &&
           adoption.fetch('status') == 'approved_as_written' &&
           adoption.fetch('effect') == 'authorizes_local_governance_v2_implementation_only' &&
           adoption.fetch('data_boundary') == 'synthetic_only'
      raise ValidationFailure, 'adoption decision is not the approved local-only instrument'
    end
    authorization = adoption.fetch('authorization')
    unless authorization.fetch('governance_v2_local_implementation') == true &&
           authorization.reject { |key, _value| key == 'governance_v2_local_implementation' }.values.all?(false)
      raise ValidationFailure, 'adoption decision authority scope changed'
    end

    candidate_path = option_path(options.fetch(:candidate_bundle), root)
    comparison = Comparator.compare!(root: root.to_s, candidate_bundle: candidate_path.to_s)
    unless comparison.fetch('status') == 'PASS' &&
           comparison.values_at('authority_effect', 'activation_effect', 'gate_effect') == %w[none none none]
      raise ValidationFailure, 'candidate comparison did not remain non-authoritative'
    end

    bundle_path = secure_regular_file!(candidate_path.join(BUNDLE_MANIFEST), candidate_path, '$.candidate_bundle_manifest')
    {
      planning_baseline: adoption.fetch('planning_head'),
      adoption_sha256: adoption_sha,
      bundle_sha256: Digest::SHA256.file(bundle_path).hexdigest,
      validator_contract: validator_contract_receipt(contract, contract_path)
    }
  end
  private_class_method :validate_candidate!

  def observation_result(mode)
    if mode == 'integrity'
      ['PASS', 'integrity_contract_passed', 'Candidate governance v2 integrity contract passed without authority effect.']
    else
      ['OPEN', 'g0_observation_open', 'Candidate governance v2 G0 is OPEN; the observation is valid and non-authoritative.']
    end
  end
  private_class_method :observation_result

  def build_receipt(mode:, source:, status:, reason_code:, message:, started_at:, finished_at:,
                    planning_baseline: nil, adoption_sha256: nil, bundle_sha256: nil,
                    validator_contract: nil)
    contract_receipt = validator_contract || {
      'name' => 'g0_proportional_governance_v2',
      'version' => '1.0.0',
      'path' => CONTRACT_PATH,
      'sha256' => nil
    }
    identity = Core.canonical_sha256(
      'operation' => 'candidate_observation', 'profile' => 'v2', 'mode' => mode,
      'source' => source, 'status' => status, 'reason_code' => reason_code,
      'started_at' => started_at, 'adoption_sha256' => adoption_sha256,
      'bundle_sha256' => bundle_sha256
    )
    receipt = {
      'schema_version' => 1,
      'operation_id' => "G0-V2-OBSERVATION-#{identity[0, 24]}",
      'operation' => 'candidate_observation',
      'profile' => 'v2',
      'mode' => mode,
      'source' => source,
      'status' => status,
      'reason_code' => reason_code,
      'message' => message,
      'actor' => 'standalone_read_only_validator',
      'started_at' => started_at,
      'finished_at' => finished_at,
      'planning_baseline' => planning_baseline,
      'adoption_sha256' => adoption_sha256,
      'activation_sha256' => nil,
      'prior_pointer_sha256' => nil,
      'selection_sha256' => nil,
      'bundle_sha256' => bundle_sha256,
      'validator_contract' => contract_receipt,
      'secret_scan_passed' => true
    }
    Core.assert_closed_schema!(receipt, required: RECEIPT_KEYS, label: '$.receipt')
    assert_receipt_secret_free!(receipt)
    receipt
  end
  private_class_method :build_receipt

  def validator_contract_receipt(contract, path)
    validator = contract.fetch('validator')
    {
      'name' => validator.fetch('contract_name'),
      'version' => validator.fetch('version'),
      'path' => CONTRACT_PATH,
      'sha256' => Digest::SHA256.file(path).hexdigest
    }
  end
  private_class_method :validator_contract_receipt

  def validate_root!(value, env)
    root = secure_directory!(Pathname.new(value.to_s).expand_path, '$.root')
    unless root == canonical_checkout || env[TEST_ROOT_GUARD] == '1'
      raise UsageError, "noncanonical --root requires #{TEST_ROOT_GUARD}=1"
    end
    root
  rescue ArgumentError
    raise UsageError, '$.root: invalid path'
  end
  private_class_method :validate_root!

  def canonical_checkout
    @canonical_checkout ||= Pathname.new(File.expand_path('..', __dir__)).realpath
  end
  private_class_method :canonical_checkout

  def option_path(value, root)
    raw = value.to_s
    raise ValidationFailure, 'unsafe path input' if raw.include?("\0")
    path = Pathname.new(raw)
    raise ValidationFailure, 'path traversal input rejected' if path.each_filename.any? { |part| part == '..' }

    path = root.join(path) unless path.absolute?
    path.expand_path
  rescue ArgumentError
    raise ValidationFailure, 'invalid path input'
  end
  private_class_method :option_path

  def secure_directory!(path, label)
    candidate = Pathname.new(path).expand_path
    path_components(candidate).each do |component|
      stat = component.lstat
      raise UsageError, "#{label}: symlinked path component" if stat.symlink?
    rescue SystemCallError
      raise UsageError, "#{label}: path unavailable"
    end
    raise UsageError, "#{label}: expected directory" unless candidate.directory?
    candidate.realpath
  rescue SystemCallError
    raise UsageError, "#{label}: path unavailable"
  end
  private_class_method :secure_directory!

  def secure_regular_file!(path, containing_root, label)
    root = Pathname.new(containing_root).realpath
    candidate = Pathname.new(path).expand_path
    relative = candidate.relative_path_from(root)
    raise ValidationFailure, "#{label}: path escapes allowed root" if relative.each_filename.any? { |part| part == '..' }

    current = root
    parts = relative.each_filename.to_a
    raise ValidationFailure, "#{label}: expected file below root" if parts.empty?
    parts.each_with_index do |part, index|
      current = current.join(part)
      stat = current.lstat
      raise ValidationFailure, "#{label}: symlinked path component" if stat.symlink?
      if index == parts.length - 1
        raise ValidationFailure, "#{label}: expected regular file" unless stat.file?
      else
        raise ValidationFailure, "#{label}: parent is not a directory" unless stat.directory?
      end
    rescue SystemCallError
      raise ValidationFailure, "#{label}: path unavailable"
    end
    current
  rescue ArgumentError, SystemCallError
    raise ValidationFailure, "#{label}: unsafe path"
  end
  private_class_method :secure_regular_file!

  def new_receipt_path!(root, value)
    candidate = option_path(value, root)
    relative = candidate.relative_path_from(root)
    raise ReceiptError, '--json-receipt must remain below --root' if relative.each_filename.any? { |part| part == '..' }
    raise ReceiptError, '--json-receipt must name a file below --root' if relative.each_filename.to_a.empty?

    parent = secure_directory!(candidate.parent, '$.json_receipt.parent')
    unless parent.to_s == root.to_s || parent.to_s.start_with?("#{root}#{File::SEPARATOR}")
      raise ReceiptError, '--json-receipt parent resolves outside --root'
    end
    begin
      candidate.lstat
      raise ReceiptError, '--json-receipt already exists; refusing overwrite'
    rescue Errno::ENOENT
      parent.join(candidate.basename)
    end
  rescue ArgumentError
    raise ReceiptError, '--json-receipt escapes --root'
  rescue UsageError, ValidationFailure
    raise ReceiptError, '--json-receipt parent is unsafe or unavailable'
  end
  private_class_method :new_receipt_path!

  def publish_receipt!(root, path, receipt)
    assert_receipt_secret_free!(receipt)
    parent = secure_directory!(path.parent, '$.json_receipt.parent')
    unless parent.to_s == root.to_s || parent.to_s.start_with?("#{root}#{File::SEPARATOR}")
      raise ReceiptError, '--json-receipt parent resolves outside --root'
    end
    bytes = Core.canonical_json(receipt) + "\n"
    flags = File::WRONLY | File::CREAT | File::EXCL
    flags |= File::NOFOLLOW if File.const_defined?(:NOFOLLOW)
    File.open(path, flags, 0o600) do |file|
      file.binmode
      file.write(bytes)
      file.flush
      file.fsync
    end
    stat = path.lstat
    raise ReceiptError, '--json-receipt publication is not a regular file' unless stat.file? && !stat.symlink?
    true
  rescue Errno::EEXIST, Errno::ELOOP
    raise ReceiptError, '--json-receipt already exists or is a symlink; refusing overwrite'
  rescue UsageError, SystemCallError
    raise ReceiptError, '--json-receipt could not be written safely'
  end
  private_class_method :publish_receipt!

  def assert_receipt_secret_free!(receipt)
    # `secret_scan_passed` is the required boolean attestation field from
    # ADR-018, not a credential. Exclude that key only, then apply the shared
    # recursive scanner to every other receipt field and value.
    scan_target = receipt.reject { |key, _value| key == 'secret_scan_passed' }
    Core.assert_secret_free!(scan_target, label: '$.receipt')
    raise ValidationFailure, '$.receipt.secret_scan_passed must be true' unless receipt['secret_scan_passed'] == true

    true
  end
  private_class_method :assert_receipt_secret_free!

  def path_components(path)
    components = []
    cursor = path
    until cursor.root?
      components << cursor
      cursor = cursor.parent
    end
    components.reverse
  end
  private_class_method :path_components

  def timestamp(now)
    value = now.respond_to?(:call) ? now.call : now
    value.utc.iso8601(6)
  rescue NoMethodError, ArgumentError
    raise ReceiptError, 'invalid validator clock'
  end
  private_class_method :timestamp
end

exit G0ProportionalGovernanceV2ValidatorCLI.run_cli(ARGV) if $PROGRAM_NAME == __FILE__
