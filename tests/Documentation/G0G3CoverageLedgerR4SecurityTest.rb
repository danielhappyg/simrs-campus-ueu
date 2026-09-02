# frozen_string_literal: true

require 'digest'
require 'fileutils'
require 'json'
require 'minitest/autorun'
require 'pathname'
require 'tmpdir'
require_relative '../../scripts/generate-g0-g3-coverage-ledger'

class G0G3CoverageLedgerV2R4Test < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  V2 = G0G3CoverageLedgerV2
  Core = G0ProportionalGovernanceV2
  Selector = G0GovernanceConsumerSelector

  def setup
    @tmpdir = Pathname.new(Dir.mktmpdir('.g0-g3-ledger-r4-case-', ROOT)).realpath
    @selector_lock = Pathname.new(ROOT).join(Selector::LOCK_RELATIVE_PATH)
    assert @selector_lock.file?, 'R4 observation publication requires the pre-existing selector lock'
    refute @selector_lock.symlink?
    assert_equal 0o600, @selector_lock.lstat.mode & 0o777
  end

  def teardown
    FileUtils.remove_entry_secure(@tmpdir) if @tmpdir&.exist?
  end

  def test_retained_r4_is_exact_authority_empty_and_contract_bound
    path = File.join(ROOT, V2::OUTPUT_PATH)
    bytes = File.binread(path)
    ledger = Core.parse_json(bytes, label: '$.r4')

    assert_equal '473cba5aec79a09621dda979ebbf0b4ec4c456aaede5bac159291c58062cd8a4',
                 Digest::SHA256.hexdigest(bytes)
    assert_equal V2::ARTIFACT_ID, ledger.fetch('artifact_id')
    assert_equal V2.send(:superseded_ledger_reference), ledger.dig('sources', 'superseded_ledger')
    assert_equal({ 'path' => V2::CONTRACT_PATH, 'sha256' => V2::PREDECESSOR_CONTRACT_SHA256 },
                 ledger.dig('sources', 'governance_contract'))
    assert_equal [V2::CONTRACT_PATH, V2::PREDECESSOR_CONTRACT_SHA256, V2::PREDECESSOR_CONTRACT_VERSION],
                 ledger.fetch('governance_profile_binding').values_at(
                   'validator_contract_path', 'validator_contract_sha256', 'validator_contract_version'
                 )
    assert_equal %w[unavailable pointer_missing],
                 ledger.fetch('governance_profile_binding').values_at('status', 'reason_code')
    assert_equal %w[OPEN OPEN], ledger.fetch('gate_summary').values_at('g0', 'g3').map { |gate| gate.fetch('status') }
    assert ledger.fetch('capabilities').all? { |row| row.fetch('governance_decision_pointer').nil? }
    assert ledger.fetch('capabilities').all? { |row| row.dig('governance', 'implementation_authorized') == false }
    assert_empty Core.secret_locations(ledger)
  end

  def test_r4_republication_is_refused_and_preserves_authority_and_bytes
    path = Pathname.new(ROOT).join(V2::OUTPUT_PATH)
    bytes_before = File.binread(path)
    authority_before = authority_inventory
    selector_before = entry_record(@selector_lock)

    assert_raises(V2::Error) { V2.write!(output: V2::OUTPUT_PATH) }

    assert_equal bytes_before, File.binread(path)
    assert_equal authority_before, authority_inventory
    assert_equal selector_before, entry_record(@selector_lock)
  end

  def test_historical_r4_writer_refuses_current_source_drift_before_publication
    output = relative(@tmpdir.join('rejected-R4.json'))
    authority_before = authority_inventory
    selector_before = entry_record(@selector_lock)
    callback_called = false

    error = assert_raises(V2::Error) do
      V2.write!(output: output, before_publish: -> { callback_called = true })
    end

    assert_match(/engineering evidence map|projection|drift/i, error.message)
    refute callback_called
    refute Pathname.new(ROOT).join(output).exist?
    assert_empty Dir.glob(@tmpdir.join('.rejected-R4.json.tmp-*').to_s)
    assert_equal authority_before, authority_inventory
    assert_equal selector_before, entry_record(@selector_lock)
  end

  def test_publication_rolls_back_before_and_after_the_link_boundary
    %i[before_link after_link].each do |fault|
      directory = @tmpdir.join(fault.to_s)
      directory.mkpath
      directory.chmod(0o700)
      relative_output = relative(directory.join('ledger.json'))
      calls = 0
      revalidate = lambda do
        calls += 1
        raise V2::Error, "fixture #{fault}" if (fault == :before_link && calls == 1) || (fault == :after_link && calls == 2)
      end

      error = assert_raises(V2::Error, fault) do
        V2.send(:publish_create_only!, Pathname.new(ROOT).realpath, relative_output, "fixture\n",
                revalidate: revalidate)
      end

      assert_match(/fixture #{fault}/, error.message)
      refute directory.join('ledger.json').exist?, fault
      refute directory.join('ledger.json').symlink?, fault
      assert_empty Dir.glob(directory.join('.ledger.json.tmp-*').to_s), fault
    end
  end

  def test_publication_rejects_parent_replacement_and_removes_its_link_from_the_pinned_parent
    directory = @tmpdir.join('publish-parent')
    displaced = @tmpdir.join('publish-parent-displaced')
    directory.mkpath
    directory.chmod(0o700)
    calls = 0
    revalidate = lambda do
      calls += 1
      next unless calls == 1

      File.rename(directory, displaced)
      directory.mkpath
      directory.chmod(0o700)
    end

    assert_raises(V2::Error) do
      V2.send(:publish_create_only!, Pathname.new(ROOT).realpath, relative(directory.join('ledger.json')),
              "fixture\n", revalidate: revalidate)
    end

    refute directory.join('ledger.json').exist?
    refute displaced.join('ledger.json').exist?
    assert_empty Dir.glob(directory.join('.*.tmp-*').to_s)
    assert_empty Dir.glob(displaced.join('.*.tmp-*').to_s)
  end

  def test_target_substitution_is_reported_as_a_residual_conflict_not_a_success
    directory = @tmpdir.join('target-substitution')
    directory.mkpath
    directory.chmod(0o700)
    target = directory.join('ledger.json')
    displaced = directory.join('created-ledger.displaced')
    calls = 0
    revalidate = lambda do
      calls += 1
      next unless calls == 2

      File.rename(target, displaced)
      File.binwrite(target, "attacker-controlled fixture\n")
      File.chmod(0o600, target)
    end

    error = assert_raises(V2::Error) do
      V2.send(:publish_create_only!, Pathname.new(ROOT).realpath, relative(target), "expected\n",
              revalidate: revalidate)
    end

    assert_match(/residual conflict; manual recovery required/i, error.message)
    assert_equal "attacker-controlled fixture\n", File.binread(target)
    assert_equal "expected\n", File.binread(displaced)
  end

  def test_safe_read_rejects_symlink_hardlink_and_post_open_entry_substitution
    source = @tmpdir.join('source.json')
    outside = @tmpdir.join('outside.json')
    File.binwrite(outside, "outside\n")
    File.chmod(0o600, outside)

    File.symlink(outside, source)
    assert_raises(V2::Error) { safe_read(source) }

    File.unlink(source)
    File.link(outside, source)
    assert_operator source.lstat.nlink, :>, 1
    assert_raises(V2::Error) { safe_read(source) }

    File.unlink(source)
    File.binwrite(source, "before\n")
    File.chmod(0o600, source)
    displaced = @tmpdir.join('source.displaced')
    native_openat = V2::NativeFs.method(:openat)
    source_opens = 0
    replacing_openat = lambda do |dirfd, leaf, flags, permissions|
      if leaf == source.basename.to_s
        source_opens += 1
        if source_opens == 2
          File.rename(source, displaced)
          File.binwrite(source, "after\n")
          File.chmod(0o600, source)
        end
      end
      native_openat.call(dirfd, leaf, flags, permissions)
    end

    V2::NativeFs.stub(:openat, replacing_openat) do
      error = assert_raises(V2::Error) { safe_read(source) }
      assert_match(/identity|changed|unsafe/i, error.message)
    end
    assert_equal "before\n", File.binread(displaced)
    assert_equal "after\n", File.binread(source)
  end

  private

  def safe_read(path)
    V2.send(:safe_read, @tmpdir, path.basename.to_s, label: 'fixture source')
  end

  def relative(path)
    Pathname.new(path).expand_path.relative_path_from(Pathname.new(ROOT).realpath).to_s
  end

  def authority_inventory
    relative_paths = [
      Selector::POINTER_RELATIVE_PATH,
      Selector::RECOVERY_MARKER_RELATIVE_PATH,
      Selector::LOCK_RELATIVE_PATH,
      Selector::SELECTIONS_RELATIVE_PATH,
      Selector::DECISIONS_RELATIVE_PATH,
      Selector::JOURNAL_RELATIVE_PATH
    ]
    relative_paths.to_h do |relative_path|
      path = Pathname.new(ROOT).join(relative_path)
      records = if path.exist? || path.symlink?
                  descendants = path.directory? && !path.symlink? ? Dir.glob(path.join('**', '*').to_s, File::FNM_DOTMATCH) : []
                  ([path.to_s] + descendants).uniq.sort.to_h do |entry|
                    entry_path = Pathname.new(entry)
                    [entry_path.relative_path_from(Pathname.new(ROOT)).to_s, entry_record(entry_path)]
                  end
                else
                  :absent
                end
      [relative_path, records]
    end
  end

  def entry_record(path)
    stat = path.lstat
    record = {
      type: stat.ftype, dev: stat.dev, ino: stat.ino, nlink: stat.nlink,
      uid: stat.uid, gid: stat.gid, mode: stat.mode & 0o7777, size: stat.size
    }
    record[:sha256] = Digest::SHA256.file(path).hexdigest if stat.file? && !stat.symlink?
    record
  end
end
