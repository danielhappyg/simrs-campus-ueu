# frozen_string_literal: true

require 'digest'
require 'fileutils'
require 'json'
require 'minitest/autorun'
require 'pathname'
require 'tmpdir'
require_relative '../../scripts/generate-g0-g3-coverage-ledger'

class G0G3CoverageLedgerV2R4AppendOnlyPublicationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  V2 = G0G3CoverageLedgerV2
  Core = G0ProportionalGovernanceV2
  Selector = G0GovernanceConsumerSelector

  def setup
    @historical_paths = [
      V2::ORIGINAL_OUTPUT_PATH,
      V2::R2_OUTPUT_PATH,
      V2::PREDECESSOR_OUTPUT_PATH
    ]
    @historical_before = @historical_paths.to_h do |relative|
      [relative, File.binread(File.join(ROOT, relative))]
    end
    @tmpdir = Pathname.new(Dir.mktmpdir('.g0-g3-ledger-r4-append-only-', ROOT)).realpath
  end

  def teardown
    @historical_before.each do |relative, bytes|
      path = File.join(ROOT, relative)
      assert_equal bytes, File.binread(path), "R4 tests must preserve #{relative} byte-for-byte"
      assert_equal Digest::SHA256.hexdigest(bytes), Digest::SHA256.file(path).hexdigest
    end
    FileUtils.remove_entry_secure(@tmpdir) if @tmpdir&.exist?
  end

  def test_r4_binds_the_complete_r1_to_r2_to_r3_to_r4_chain
    original = parse_historical(V2::ORIGINAL_OUTPUT_PATH)
    r2 = parse_historical(V2::R2_OUTPUT_PATH)
    r3 = parse_historical(V2::PREDECESSOR_OUTPUT_PATH)

    assert_equal 'G0-G3-COVERAGE-LEDGER-V2-2026-08-29-R4', V2::ARTIFACT_ID
    assert_equal 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R4.json', V2::OUTPUT_PATH
    assert_equal V2::ORIGINAL_ARTIFACT_ID, original.fetch('artifact_id')
    assert_equal V2::R2_ARTIFACT_ID, r2.fetch('artifact_id')
    assert_equal V2::PREDECESSOR_ARTIFACT_ID, r3.fetch('artifact_id')
    assert_equal V2.send(:original_ledger_reference), r2.dig('sources', 'superseded_ledger')
    assert_equal V2.send(:r2_ledger_reference), r3.dig('sources', 'superseded_ledger')
    assert_equal V2.send(:superseded_ledger_reference), V2.send(:verified_superseded_ledger, Pathname.new(ROOT).realpath)
    assert_equal V2.send(:r2_ledger_reference), V2.send(:verified_r2_ledger, Pathname.new(ROOT).realpath)
    assert_equal V2.send(:original_ledger_reference), V2.send(:verified_original_ledger, Pathname.new(ROOT).realpath)

    assert_equal V2::ORIGINAL_SHA256, Digest::SHA256.hexdigest(@historical_before.fetch(V2::ORIGINAL_OUTPUT_PATH))
    assert_equal V2::R2_SHA256, Digest::SHA256.hexdigest(@historical_before.fetch(V2::R2_OUTPUT_PATH))
    assert_equal V2::PREDECESSOR_SHA256, Digest::SHA256.hexdigest(@historical_before.fetch(V2::PREDECESSOR_OUTPUT_PATH))
    assert_equal V2::PREDECESSOR_CONTRACT_SHA256, r3.dig('sources', 'governance_contract', 'sha256')
    assert_equal V2::PREDECESSOR_CONTRACT_VERSION,
                 r3.dig('governance_profile_binding', 'validator_contract_version')
  end

  def test_r4_direct_predecessor_rejects_missing_drift_symlink_and_hardlink
    root = predecessor_fixture_root
    target = root.join(V2::PREDECESSOR_OUTPUT_PATH)
    FileUtils.mkdir_p(target.parent)

    assert_raises(V2::Error) { V2.send(:verified_superseded_ledger, root) }

    File.binwrite(target, @historical_before.fetch(V2::PREDECESSOR_OUTPUT_PATH) + " ")
    assert_raises(V2::Error) { V2.send(:verified_superseded_ledger, root) }

    File.unlink(target)
    File.symlink(File.join(ROOT, V2::PREDECESSOR_OUTPUT_PATH), target)
    assert_raises(V2::Error) { V2.send(:verified_superseded_ledger, root) }

    File.unlink(target)
    File.link(File.join(ROOT, V2::PREDECESSOR_OUTPUT_PATH), target)
    assert_operator target.lstat.nlink, :>, 1
    assert_raises(V2::Error) { V2.send(:verified_superseded_ledger, root) }
  end

  def test_selector_lock_must_preexist_and_is_never_created_or_chmodded
    relative = 'selector.lock'
    lock = @tmpdir.join(relative)

    error = assert_raises(V2::Error) do
      V2.send(:with_stable_lock, @tmpdir, relative, File::LOCK_SH, 'conflict', create: false) { flunk }
    end
    assert_match(/selector lock|unavailable|unsafe/i, error.message)
    refute lock.exist?
    refute lock.symlink?

    File.binwrite(lock, '')
    File.chmod(0o640, lock)
    before = lock.lstat
    assert_raises(V2::Error) do
      V2.send(:with_stable_lock, @tmpdir, relative, File::LOCK_SH, 'conflict', create: false) { flunk }
    end
    after = lock.lstat
    assert_equal before.ino, after.ino
    assert_equal before.mode & 0o777, after.mode & 0o777
    assert_equal 0o640, after.mode & 0o777
    assert_equal '', File.binread(lock)
  end

  def test_selector_lock_revalidation_rejects_same_uid_path_replacement
    relative = 'selector.lock'
    lock = @tmpdir.join(relative)
    displaced = @tmpdir.join('selector.lock.displaced')
    File.open(lock, File::WRONLY | File::CREAT | File::EXCL, 0o600) {}
    original = lock.lstat

    error = assert_raises(V2::Error) do
      V2.send(:with_stable_lock, @tmpdir, relative, File::LOCK_SH, 'conflict', create: false) do |revalidate|
        File.rename(lock, displaced)
        File.open(lock, File::WRONLY | File::CREAT | File::EXCL, 0o600) {}
        replacement = lock.lstat
        assert_equal Process.uid, replacement.uid
        refute_equal original.ino, replacement.ino
        revalidate.call
      end
    end

    assert_match(/lock path|unsafe|identity/i, error.message)
    assert_equal original.ino, displaced.lstat.ino
    assert_equal '', File.binread(displaced)
    assert_equal '', File.binread(lock)
  end

  private

  def parse_historical(relative)
    Core.parse_json(@historical_before.fetch(relative), label: relative)
  end

  def predecessor_fixture_root
    root = @tmpdir.join('predecessor-root')
    [V2::ORIGINAL_OUTPUT_PATH, V2::R2_OUTPUT_PATH].each do |relative|
      target = root.join(relative)
      FileUtils.mkdir_p(target.parent)
      File.binwrite(target, @historical_before.fetch(relative))
      File.chmod(0o600, target)
    end
    root.realpath
  end
end
