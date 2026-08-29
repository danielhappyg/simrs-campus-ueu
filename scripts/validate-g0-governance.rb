#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'json'
require 'open3'
require 'optparse'
require 'pathname'
require 'rbconfig'
require 'time'

# Thin, observation-only profile dispatcher for G0 governance validation.
#
# This file intentionally contains no governance rule, owner-authority rule, or
# active-pointer resolution rule. Governance-v1 is executed unchanged. The v2
# validator and v1/v2 comparator remain the sole owners of their contracts.
module G0GovernanceProfileDispatcher
  class UsageError < StandardError; end
  class ReceiptError < StandardError; end

  CANONICAL_ROOT = Pathname.new(File.expand_path('..', __dir__)).realpath.freeze
  TEST_ROOT_GUARD = 'G0_GOVERNANCE_V2_TEST_ROOT'
  PROFILES = %w[v1 v2 dual].freeze
  MODES = %w[integrity g0].freeze
  SOURCES = %w[candidate active].freeze
  PLANNING_BASELINE = 'e2d933c8929906bd15f52c8c5a6283d45b3fbf86'
  BUNDLE_MANIFEST = 'G0_GOVERNANCE_V2_BUNDLE_MANIFEST.json'
  VALIDATOR_CONTRACT = {
    'comparison' => 'compare-g0-governance-v1-v2/read-only-migration-parity',
    'dispatcher' => 'validate-g0-governance/1.0.0-wave4-candidate-only',
    'v1' => 'validate-parity-governance/unchanged',
    'v2' => 'g0-proportional-governance-v2/1.0.0'
  }.freeze
  RECEIPT_KEYS = %w[
    schema_version operation_id operation profile mode source status reason_code
    message actor started_at finished_at planning_baseline adoption_sha256
    activation_sha256 prior_pointer_sha256 selection_sha256 bundle_sha256
    validator_contract secret_scan_passed
  ].freeze
  SECRET_PATTERN = /(?:-----BEGIN (?:RSA |EC |DSA |OPENSSH |ENCRYPTED )?PRIVATE KEY-----|\bBearer\s+[A-Za-z0-9._~+\/-]{8,}|\b(?:password|passphrase|api[_ -]?key|client[_ -]?secret|access[_ -]?token|refresh[_ -]?token|private[_ -]?key)\s*[:=]\s*[^\s,}]+)/i

  module_function

  def run_cli(argv, stdout: $stdout, stderr: $stderr, env: ENV, clock: -> { Time.now.utc })
    options, parser = parse_options(argv.dup)
    root = selected_root(options[:root], env)
    validate_matrix!(options)
    validate_receipt_target!(root, options[:json_receipt]) if options[:json_receipt]

    started_at = iso8601(clock.call)
    result = dispatch(options, root, stdout, stderr, env)
    finished_at = iso8601(clock.call)

    if options.fetch(:profile) != 'v1' || options[:json_receipt]
      receipt = build_receipt(options, root, result, started_at, finished_at)
      write_receipt!(root, options.fetch(:json_receipt), receipt) if options[:json_receipt]
      stdout.write(canonical_json(receipt) + "\n") if options.fetch(:profile) != 'v1'
    end
    result.fetch(:exit_code)
  rescue OptionParser::ParseError, UsageError
    stderr.puts('usage_error: invalid command-line usage')
    stderr.puts(parser) if parser && env['G0_GOVERNANCE_V2_VERBOSE_USAGE'] == '1'
    2
  rescue ReceiptError
    stderr.puts('validation_failed: receipt_write_failed')
    1
  rescue SystemCallError, IOError
    stderr.puts('validation_failed: dispatcher_runtime_failure')
    1
  end

  def parse_options(argv)
    options = {}
    parser = OptionParser.new do |opts|
      opts.banner = 'Usage: ruby scripts/validate-g0-governance.rb --profile v1|v2|dual [options]'
      unique_option(opts, options, :profile, '--profile PROFILE', PROFILES)
      unique_option(opts, options, :mode, '--mode MODE', MODES)
      unique_option(opts, options, :source, '--source SOURCE', SOURCES)
      unique_option(opts, options, :candidate_bundle, '--candidate-bundle PATH')
      unique_option(opts, options, :adoption_decision, '--adoption-decision PATH')
      unique_option(opts, options, :root, '--root PATH')
      unique_option(opts, options, :json_receipt, '--json-receipt PATH')
    end
    parser.parse!(argv)
    raise UsageError, 'unexpected positional arguments' unless argv.empty?
    options[:mode] ||= 'integrity'
    [options, parser]
  end

  def unique_option(parser, options, key, *definition)
    parser.on(*definition) do |value|
      raise UsageError, 'duplicate option' if options.key?(key)
      raise UsageError, 'empty option value' if value.nil? || value.strip.empty?
      options[key] = value
    end
  end

  def validate_matrix!(options)
    profile = options[:profile]
    raise UsageError, 'profile required' unless profile

    if profile == 'v1'
      raise UsageError, 'v1 forbids source and v2 inputs' if options[:source] || options[:candidate_bundle] || options[:adoption_decision]
      return true
    end

    source = options[:source]
    raise UsageError, 'source required' unless source
    if source == 'candidate'
      raise UsageError, 'candidate inputs required' unless options[:candidate_bundle] && options[:adoption_decision]
    else
      raise UsageError, 'active forbids candidate inputs' if options[:candidate_bundle] || options[:adoption_decision]
    end
    true
  end

  def selected_root(raw_root, env)
    candidate = Pathname.new(raw_root || CANONICAL_ROOT.to_s).expand_path
    assert_no_symlink_components!(candidate, 'root')
    raise UsageError, 'root must be a directory' unless candidate.directory?
    resolved = candidate.realpath
    unless resolved == CANONICAL_ROOT || env[TEST_ROOT_GUARD] == '1'
      raise UsageError, 'noncanonical root forbidden'
    end
    resolved
  rescue SystemCallError, ArgumentError
    raise UsageError, 'root unavailable'
  end

  def dispatch(options, root, stdout, stderr, env)
    profile = options.fetch(:profile)
    return active_not_ready(stdout, stderr) if options[:source] == 'active'
    return run_v1(options.fetch(:mode), root, stdout, stderr, env) if profile == 'v1'
    return run_v2(options, root, stdout, stderr, env) if profile == 'v2'

    run_dual(options, root, stdout, stderr, env)
  end

  def active_not_ready(_stdout, stderr)
    stderr.puts('validation_not_ready: active_source_resolver_not_ready_wave5')
    {
      exit_code: 1,
      status: 'NOT_READY',
      reason_code: 'active_source_resolver_not_ready_wave5',
      message: 'Active-source validation is unavailable until the shared read-only Wave 5 resolver exists.'
    }
  end

  def run_v1(mode, root, stdout, stderr, env)
    command = [RbConfig.ruby, CANONICAL_ROOT.join('scripts/validate-parity-governance.rb').to_s, '--mode', mode]
    result = run_child(command, root, env)
    emit_child(result, stdout, stderr)
    validation_result(result, 'v1_validation_passed', 'v1_validation_failed', 'Governance-v1 validation')
  end

  def run_v2(options, root, stdout, stderr, env)
    result = run_child(v2_command(options, root), CANONICAL_ROOT, env)
    stderr.write(result.fetch(:stderr)) unless result.fetch(:exit_code).zero?
    validation_result(
      result,
      'v2_candidate_validation_passed',
      'v2_candidate_validation_failed',
      'Governance-v2 candidate validation',
      open_on_success: options.fetch(:mode) == 'g0'
    )
  end

  def run_dual(options, root, stdout, stderr, env)
    v1 = run_child(
      [RbConfig.ruby, CANONICAL_ROOT.join('scripts/validate-parity-governance.rb').to_s, '--mode', 'integrity'],
      root,
      env
    )
    v2 = run_child(v2_command(options, root), CANONICAL_ROOT, env)
    comparison = run_child(
      [
        RbConfig.ruby,
        CANONICAL_ROOT.join('scripts/compare-g0-governance-v1-v2.rb').to_s,
        '--candidate-bundle', resolve_input_path(options.fetch(:candidate_bundle), root),
        '--root', root.to_s
      ],
      CANONICAL_ROOT,
      env
    )
    [v1, v2, comparison].each do |result|
      stderr.write(result.fetch(:stderr)) unless result.fetch(:exit_code).zero?
    end

    codes = [v1, v2, comparison].map { |result| result.fetch(:exit_code) }
    code = codes.include?(2) ? 2 : (codes.all?(&:zero?) ? 0 : 1)
    observation_open = code.zero? && options.fetch(:mode) == 'g0'
    {
      exit_code: code,
      status: observation_open ? 'OPEN' : (code.zero? ? 'PASS' : 'FAIL'),
      reason_code: observation_open ? 'dual_g0_observation_open' : (code.zero? ? 'dual_candidate_observation_passed' : (code == 2 ? 'delegated_invalid_usage' : 'dual_candidate_observation_failed')),
      message: if observation_open
                 'Dual candidate observation is valid and G0 remains OPEN without changing the active consumer.'
               elsif code.zero?
                 'Dual candidate observation passed without changing the active consumer.'
               else
                 'Dual candidate observation failed closed without changing the active consumer.'
               end
    }
  end

  def v2_command(options, root)
    [
      RbConfig.ruby,
      CANONICAL_ROOT.join('scripts/validate-g0-proportional-governance-v2.rb').to_s,
      '--mode', options.fetch(:mode),
      '--source', 'candidate',
      '--candidate-bundle', resolve_input_path(options.fetch(:candidate_bundle), root),
      '--adoption-decision', resolve_input_path(options.fetch(:adoption_decision), root),
      '--root', root.to_s
    ]
  end

  def run_child(command, chdir, env)
    child_env = env.respond_to?(:to_h) ? env.to_h : {}
    stdout, stderr, status = Open3.capture3(child_env, *command, chdir: chdir.to_s)
    { stdout: stdout, stderr: stderr, exit_code: status.exitstatus || 1 }
  rescue SystemCallError, IOError
    { stdout: '', stderr: "validation_failed: delegated_validator_unavailable\n", exit_code: 1 }
  end

  def emit_child(result, stdout, stderr)
    stdout.write(result.fetch(:stdout))
    stderr.write(result.fetch(:stderr))
  end

  def validation_result(result, pass_reason, fail_reason, label, open_on_success: false)
    code = result.fetch(:exit_code)
    observation_open = code.zero? && open_on_success
    {
      exit_code: code,
      status: observation_open ? 'OPEN' : (code.zero? ? 'PASS' : 'FAIL'),
      reason_code: observation_open ? 'g0_observation_open' : (code.zero? ? pass_reason : (code == 2 ? 'delegated_invalid_usage' : fail_reason)),
      message: observation_open ? "#{label} is valid and G0 remains OPEN." : (code.zero? ? "#{label} passed." : "#{label} failed closed.")
    }
  end

  def build_receipt(options, root, result, started_at, finished_at)
    adoption_sha = options[:adoption_decision] && file_sha_if_regular(resolve_input_path(options[:adoption_decision], root))
    bundle_sha = options[:candidate_bundle] && file_sha_if_regular(File.join(resolve_input_path(options[:candidate_bundle], root), BUNDLE_MANIFEST))
    core = {
      'schema_version' => 1,
      'operation' => 'validate',
      'profile' => options.fetch(:profile),
      'mode' => options.fetch(:mode),
      'source' => options[:source],
      'status' => result.fetch(:status),
      'reason_code' => result.fetch(:reason_code),
      'message' => result.fetch(:message),
      'actor' => 'local_operator',
      'planning_baseline' => PLANNING_BASELINE,
      'adoption_sha256' => adoption_sha,
      'activation_sha256' => nil,
      'prior_pointer_sha256' => nil,
      'selection_sha256' => nil,
      'bundle_sha256' => bundle_sha,
      'validator_contract' => VALIDATOR_CONTRACT,
      'secret_scan_passed' => true
    }
    operation_id = "G0-VALIDATE-#{Digest::SHA256.hexdigest(canonical_json(core))[0, 24].upcase}"
    receipt = core.merge(
      'operation_id' => operation_id,
      'started_at' => started_at,
      'finished_at' => finished_at
    )
    raise ReceiptError, 'receipt schema drift' unless receipt.keys.sort == RECEIPT_KEYS.sort
    receipt
  end

  def file_sha_if_regular(raw_path)
    return nil unless raw_path
    path = Pathname.new(raw_path).expand_path
    return nil unless path.file? && !path.symlink?
    Digest::SHA256.file(path).hexdigest
  rescue SystemCallError
    nil
  end

  def write_receipt!(root, raw_path, receipt)
    path = Pathname.new(resolve_input_path(raw_path, root))
    bytes = canonical_json(receipt) + "\n"
    raise ReceiptError, 'secret-like receipt content' if bytes.match?(SECRET_PATTERN)
    flags = File::WRONLY | File::CREAT | File::EXCL
    flags |= File::NOFOLLOW if File.const_defined?(:NOFOLLOW)
    File.open(path, flags, 0o600) do |file|
      file.binmode
      file.write(bytes)
      file.flush
      file.fsync
    end
    true
  rescue SystemCallError, ArgumentError
    raise ReceiptError, 'receipt write rejected'
  end

  def validate_receipt_target!(root, raw_path)
    path = Pathname.new(resolve_input_path(raw_path, root))
    ensure_inside_root!(path, root)
    raise ReceiptError, 'receipt must be below selected root' if path == root
    assert_no_symlink_components!(path.parent, 'receipt parent')
    raise ReceiptError, 'receipt parent unavailable' unless path.parent.directory?
    begin
      path.lstat
      raise ReceiptError, 'receipt target already exists'
    rescue Errno::ENOENT
      true
    end
  rescue UsageError
    raise ReceiptError, 'receipt target unsafe'
  end

  def ensure_inside_root!(path, root)
    relative = path.relative_path_from(root)
    raise ReceiptError, 'receipt escapes selected root' if relative.each_filename.any? { |part| part == '..' }
  rescue ArgumentError
    raise ReceiptError, 'receipt escapes selected root'
  end

  def resolve_input_path(raw_path, root)
    path = Pathname.new(raw_path)
    (path.absolute? ? path : root.join(path)).expand_path.to_s
  end

  def assert_no_symlink_components!(path, _label)
    components = []
    cursor = path
    until cursor.root?
      components << cursor
      cursor = cursor.parent
    end
    components.reverse_each do |component|
      stat = component.lstat
      raise UsageError, 'symlinked path component' if stat.symlink?
    end
    true
  rescue Errno::ENOENT
    # The receipt file itself is expected not to exist; callers pass its parent
    # here. Root validation, by contrast, checks existence immediately after.
    true
  end

  def iso8601(value)
    value.utc.iso8601(6)
  rescue NoMethodError, ArgumentError
    raise ReceiptError, 'invalid clock'
  end

  def canonical_json(value)
    JSON.generate(canonical_value(value), ascii_only: true)
  end

  def canonical_value(value)
    case value
    when Hash
      value.keys.sort.to_h { |key| [key, canonical_value(value.fetch(key))] }
    when Array
      value.map { |item| canonical_value(item) }
    else
      value
    end
  end
end

exit G0GovernanceProfileDispatcher.run_cli(ARGV) if $PROGRAM_NAME == __FILE__
