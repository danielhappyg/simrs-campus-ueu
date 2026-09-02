# frozen_string_literal: true

require 'minitest/autorun'

class CrossSettingEncounterCancellationDecisionPackTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PACK_PATH = 'docs/new-simrs-rebuild/phase-1/CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_FR_PACK_2026-08-27.md'
  ADR_PATH = 'docs/operations/ADR_CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_2026-08-27.md'
  REQUIREMENT_PATHS = %w[
    docs/new-simrs-rebuild/phase-1/requirements/PAR-REG-001-rawat-inap-registration.md
    docs/new-simrs-rebuild/phase-1/requirements/PAR-REG-002-igd-registration.md
    docs/new-simrs-rebuild/phase-1/requirements/PAR-REG-003-rawat-jalan-registration.md
  ].freeze

  def setup
    @pack = File.read(File.join(ROOT, PACK_PATH))
    @adr = File.read(File.join(ROOT, ADR_PATH))
  end

  def test_pack_records_local_implementation_without_overclaiming_domain_or_operational_authority
    assert_includes @pack, '**LOCAL IMPLEMENTATION PRESENT — focused integration verification passing; domain/parity acceptance remains open**'
    assert_includes @pack, 'authorized continued local implementation'
    assert_includes @pack, 'Blank owner rows are not consent.'
    assert_includes @pack, 'does not fill blank domain-owner rows'
    assert_includes @pack, 'no-live-integration'
    assert_includes @pack, 'or authorize hosted migration/deployment'
    assert_includes @adr, '**PROPOSED — no implementation approval**'
    refute_match(/\*\*Status:\*\*\s+APPROVED/i, @pack)
    refute_match(/\*\*Status:\*\*\s+APPROVED/i, @adr)
  end

  def test_all_decision_and_functional_requirement_ids_are_present_once
    expected_decisions = (1..20).map { |number| format('CAN-%02d', number) }
    expected_requirements = (1..12).map { |number| format('FR-CAN-%03d', number) }

    expected_decisions.each { |id| assert_equal 1, @pack.scan("| #{id} |").length, id }
    expected_requirements.each { |id| assert_equal 1, @pack.scan("`#{id}`").length, id }
  end

  def test_scope_and_safety_boundaries_are_explicit
    %w[PAR-REG-001 PAR-REG-002 PAR-REG-003 E2E-01 E2E-12 E2E-16].each do |identifier|
      assert_includes @pack, "`#{identifier}`"
    end

    assert_includes @pack, '`APP_MODE=SIMULATION`'
    assert_includes @pack, 'REGISTERED -> CANCELLED'
    assert_includes @pack, 'No transition from CANCELLED in v1.'
    assert_includes @pack, 'never decrement or reuse'
    assert_includes @pack, 'no authoritative capacity ledger exists'
    assert_match(/never cascade automatically/i, @pack)
    assert_includes @pack, 'no BPJS/VClaim/SATUSEHAT or other live integration'
  end

  def test_owner_record_covers_every_affected_authority
    authorities = [
      'Product scope and teaching outcome',
      'Registration / front office',
      'Patient identity / master patient index',
      'Outpatient scheduling',
      'Emergency / IGD',
      'Inpatient / bed management',
      'Clinical',
      'RMIK',
      'Reporting / management information',
      'Finance, pharmacy, inventory, claims',
      'Technical/security/operations',
      'Teaching data / reset authority'
    ]

    authorities.each { |authority| assert_includes @pack, "| #{authority} |" }
  end

  def test_canonical_registration_requirements_link_the_unapproved_candidate
    REQUIREMENT_PATHS.each do |relative_path|
      content = File.read(File.join(ROOT, relative_path))

      assert_includes content, 'CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_FR_PACK_2026-08-27.md'
      assert_match(/unapproved|not owner-approved/i, content)
    end
  end

  def test_companion_links_resolve
    assert File.file?(File.join(ROOT, PACK_PATH))
    assert File.file?(File.join(ROOT, ADR_PATH))
    assert_includes @pack, 'ADR_CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_2026-08-27.md'
    assert_includes @adr, 'CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_FR_PACK_2026-08-27.md'
  end

  def test_local_markdown_links_in_new_artifacts_resolve
    { PACK_PATH => @pack, ADR_PATH => @adr }.each do |relative_path, content|
      source_directory = File.dirname(File.join(ROOT, relative_path))
      links = content.scan(/\]\(([^)]+)\)/).flatten.reject { |link| link.start_with?('#', 'http://', 'https://') }

      refute_empty links, relative_path
      links.each do |link|
        target = File.expand_path(link.split('#', 2).first, source_directory)
        assert File.file?(target), "#{relative_path}: missing local link #{link}"
      end
    end
  end
end
