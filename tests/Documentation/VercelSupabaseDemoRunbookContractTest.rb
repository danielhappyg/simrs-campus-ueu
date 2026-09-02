# frozen_string_literal: true

require 'minitest/autorun'

class VercelSupabaseDemoRunbookContractTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PATH = File.join(ROOT, 'docs/operations/VERCEL_SUPABASE_DEMO.md')

  def setup
    @runbook = File.read(PATH, encoding: Encoding::UTF_8)
  end

  def test_mode_kampus_presentation_does_not_relax_backend_boundary
    assert_includes @runbook, 'restrained `Mode Kampus` posture'
    assert_includes @runbook, 'persistent front-of-screen `SIMULASI — DATA SINTETIS` banner is not required'
    assert_includes @runbook, 'does not relax backend `SIMULATION`, synthetic-only'
  end

  def test_current_checkpoint_holds_warehouse_schema_and_routes
    assert_includes @runbook, '`WAREHOUSE_SCHEMA_MIGRATION_ENABLED=false`'
    assert_includes @runbook, '`database/migrations/2026_09_03_000100_expand_warehouse_teaching_role_access_roster.php`'
    assert_includes @runbook, '`database/migrations/2026_09_03_000200_create_medication_replenishment_warehouse_custody_tables.php`'
    assert_includes @runbook, 'warehouse schema activation does not'
    assert_includes @runbook, 'Do not expose warehouse routes or navigation'
    assert_includes @runbook, 'exact PostgreSQL/MySQL warehouse rehearsal remains `READY / NOT RUN`'
    assert_includes @runbook, 'do not run an unfiltered migrate --force'
  end

  def test_apotek_is_not_globally_soon_and_hosted_state_requires_verification
    assert_includes @runbook, 'Klaim and BPJS remain `Soon`'
    assert_includes @runbook, 'Apotek is an implemented application module in the release candidate'
    assert_includes @runbook, 'do not record it as hosted until these checks pass on the exact deployed commit'
  end
end
