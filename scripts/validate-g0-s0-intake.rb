# frozen_string_literal: true

require 'digest'
require 'base64'
require 'json'
require 'open3'
require 'openssl'
require 'optparse'
require 'rbconfig'
require 'time'

class G0S0IntakeValidator
  class DuplicateKeyHash < Hash
    def []=(key, value)
      raise JSON::ParserError, "duplicate JSON object key #{key.inspect}" if key?(key)

      super
    end
  end

  PHASE_ZERO = 'docs/new-simrs-rebuild/phase-0'
  INTAKE_FILE = 'G0_S0_INSTITUTIONAL_AUTHORITY_INTAKE_2026-08-26.md'
  MANIFEST_FILE = 'G0_PARITY_BATCH_MANIFEST.json'
  BATCH_A_FILE = 'G0_BATCH_A_DECISION_REGISTER_2026-08-25.json'
  SNAPSHOT_PLAN_FILE = 'G0_OWNER_GOVERNANCE_SNAPSHOT_PLAN_2026-08-25.json'
  POLICY_FILE = 'G0_OWNER_AUTHORITY_POLICY_2026-08-25.json'
  IDENTITY_FILE = 'G0_INSTITUTIONAL_IDENTITY_KEY_REGISTRY_2026-08-26.json'
  APPOINTMENT_FILE = 'G0_OWNER_APPOINTMENT_REGISTER_2026-08-25.json'
  SESSION_FILE = 'G0_DECISION_SESSION_REGISTER_2026-08-25.json'

  EXPECTED_MANIFEST_SHA256 = '59b0f05b94e2a659b342016c5b9e5e9e011f3a8e4c0f0efd3dda12a7b65fa9ca'
  EXPECTED_BATCH_A_SHA256 = 'a5c1e01686fdb576026802331d3228df9a6335f6f7176b7c023c459bfc532098'
  EXPECTED_SNAPSHOT_PLAN_SHA256 = '3ab2abd46fdcbbd7f13ff6bd87857834c88e1cc373894bbe78fa54ae0a440ee0'
  EXPECTED_ADM_005_ROW_SHA256 = 'ba37208012fd847ac6683b07a832c5db9c1ffcaa27e7d4e3c9fd8385e5aff4d3'
  EXPECTED_POLICY_CONTROL_ROOT = 'dee9dd51f7e50242b2ef3b3aaea1f5bfe3868c491db485243b1dcd3571fba26e'
  EXPECTED_SEATS = %w[operations product_delivery security_privacy_data].freeze
  EXPECTED_SAFETY_BOUNDARY = {
    'data_boundary' => 'synthetic_only',
    'real_patient_data' => 'forbidden',
    'external_integrations' => 'disabled_no_transmission'
  }.freeze
  EXPECTED_SEPARATION_CONTRACT = {
    'base_role_people_distinct' => true,
    'replace_eighth_voter_distinct' => true,
    'root_appointment_issuer_may_self_appoint' => false,
    'recusal_requires_replacement' => true
  }.freeze
  EXPECTED_READINESS_GAPS = [].freeze
  EXPECTED_SIGNING_PROFILES = {
    'reproduce' => {
      'exact_decision_seats' => %w[product_delivery operations security_privacy_data],
      'minimum_distinct_people' => 7
    },
    'replace' => {
      'exact_decision_seats' => %w[product_delivery operations security_privacy_data executive_sponsor],
      'minimum_distinct_people' => 8,
      'default_separation' => 'eighth_distinct_person_required',
      'dual_hat_requires_proven_compatibility' => true,
      'additional_role_row' => {
        'role_id' => 'replace_disposition_executive_sponsor_voter',
        'function' => 'Executive sponsor voter untuk disposisi replace, terpisah dari root appointment issuer dan voter lain',
        'required_tokens' => %w[executive_sponsor appointment_acceptance decision_vote]
      }
    }
  }.freeze

  EXPECTED_ROLE_ROWS = [
    {
      'ordinal' => 1,
      'role_id' => 'trust_root_registry_issuer_evidence_author',
      'function' => 'Trust-root A, identity-registry issuer, evidence author',
      'required_tokens' => %w[institutional_trust_root identity_registry_issuer registry_root]
    },
    {
      'ordinal' => 2,
      'role_id' => 'trust_root_implementer',
      'function' => 'Trust-root B, implementer',
      'required_tokens' => %w[institutional_trust_root registry_root]
    },
    {
      'ordinal' => 3,
      'role_id' => 'executive_sponsor_appointment_issuer',
      'function' => 'Executive sponsor dan root appointment issuer',
      'required_tokens' => %w[executive_sponsor appointment_issuer policy_approval appointment_issuance]
    },
    {
      'ordinal' => 4,
      'role_id' => 'independent_reviewer',
      'function' => 'Reviewer independen untuk policy, appointment, dan S0',
      'required_tokens' => %w[independent_reviewer registry_review session_review]
    },
    {
      'ordinal' => 5,
      'role_id' => 'product_delivery_s0_chair',
      'function' => 'Otoritas `product_delivery` dan chair S0',
      'required_tokens' => %w[product_delivery appointment_acceptance decision_vote]
    },
    {
      'ordinal' => 6,
      'role_id' => 'operations_s0_facilitator',
      'function' => 'Lead `operations` dan facilitator S0',
      'required_tokens' => %w[operations appointment_acceptance decision_vote]
    },
    {
      'ordinal' => 7,
      'role_id' => 'security_privacy_data_independent_control',
      'function' => 'Otoritas kontrol independen',
      'required_tokens' => %w[security_privacy_data appointment_acceptance decision_vote]
    }
  ].freeze
  ROLE_REQUIREMENTS = {
    1 => { 'authorization_roles' => %w[institutional_trust_root identity_registry_issuer], 'key_purposes' => %w[registry_root] },
    2 => { 'authorization_roles' => %w[institutional_trust_root], 'key_purposes' => %w[registry_root] },
    3 => { 'authorization_roles' => %w[executive_sponsor appointment_issuer], 'key_purposes' => %w[policy_approval appointment_issuance] },
    4 => { 'authorization_roles' => %w[independent_reviewer], 'key_purposes' => %w[registry_review session_review] },
    5 => { 'authorization_roles' => %w[product_delivery], 'key_purposes' => %w[appointment_acceptance decision_vote] },
    6 => { 'authorization_roles' => %w[operations], 'key_purposes' => %w[appointment_acceptance decision_vote] },
    7 => { 'authorization_roles' => %w[security_privacy_data], 'key_purposes' => %w[appointment_acceptance decision_vote] },
    8 => { 'authorization_roles' => %w[executive_sponsor], 'key_purposes' => %w[appointment_acceptance decision_vote] }
  }.freeze

  BINDING_BEGIN = '<!-- G0_S0_MACHINE_BINDING_BEGIN -->'
  BINDING_END = '<!-- G0_S0_MACHINE_BINDING_END -->'
  PRIVATE_KEY_PATTERN = /-----BEGIN (?:RSA |EC |DSA |OPENSSH |ENCRYPTED )?PRIVATE KEY-----/i
  SECRET_ASSIGNMENT_PATTERN = /(?<![A-Za-z0-9_])["']?(?:password|passphrase|api[_ -]?key|client[_ -]?secret|signing(?:[_ -]?(?:secret|key|material|credentials?))?|hmac(?:[_ -]?(?:secret|key|material|credentials?))?|access[_ -]?token|refresh[_ -]?token|session[_ -]?(?:token|secret)|authorization|cookie|credentials?(?:[_ -]?(?:value|json))?|integration[_ -]?credentials?|private[_ -]?key(?:[_ -]?(?:pem|material))?|recovery(?:[_ -]?(?:material|key|code|token|phrase))?|secrets?(?:[_ -]?(?:value|material|key))?)["']?\s*[:=]\s*([^\n|]+)/i
  TOKEN_VALUE_PATTERN = /\b(?:Bearer\s+[A-Za-z0-9._~+\/-]{8,}|ghp_[A-Za-z0-9]{8,}|github_pat_[A-Za-z0-9_]{8,}|sk_(?:live|test)_[A-Za-z0-9]{8,}|eyJ[A-Za-z0-9._-]{8,})/i
  BASIC_AUTH_PATTERN = /\bAuthorization\s*:\s*Basic\s+[A-Za-z0-9+\/=]{8,}/i
  COOKIE_VALUE_PATTERN = /\b(?:Cookie|Set-Cookie)\s*:\s*[^\s=;]+=[^\s;]+/i
  PLACEHOLDER_PATTERN = /\A(?:_+|\.+|<[^>]+>|\[[^\]]+\]|null|none|n\/a|tidak ada|tidak diisi|belum diisi|not provided|not applicable|forbidden|dilarang|disabled|kosong)\z/i
  SENSITIVE_TABLE_LABEL_PATTERN = /\A(?:password|passphrase|api key|client secret|signing(?: secret| key| material| credential| credentials)?|hmac(?: secret| key| material| credential| credentials)?|access token|refresh token|session token|session secret|authorization|cookie|credential|credentials|credential value|credentials value|credentials json|integration credential|integration credentials|credential integrasi|credentials integrasi|private key|private key pem|private key material|recovery|recovery material|recovery key|recovery code|recovery token|recovery phrase|secret|secrets|secret value|secret material|secret key)\z/i
  INTEGRATION_NAME_PATTERN = /\b(?:bpjs|vclaim|satusehat)\b/i
  INTEGRATION_ASSERTION_SIGNAL_PATTERN = /\b(?:tidak|belum|jangan|dilarang|forbidden|disabled|dinonaktifkan|nonaktif|not|must|do|does|no|hanya|only|may|can|requires?|memerlukan|membutuhkan|unless|sampai|aktif|active|enabled|authorized|allowed|tersedia|available|berjalan|running|siap|ready|beroperasi|operational|digunakan|dipakai|used|terhubung|connected|dikirim|sent|ditransmisikan|transmitted)\b/i
  ROLE_SCOPE_PATTERN = /(?:tujuh\s+(?:baris|peran)\s+dasar|ketujuh\s+peran|delapan\s+(?:baris|peran)|semua\s+(?:kursi|peran|fungsi\s+dasar)|pemegang\s+kursi|kursi\s+(?:dasar|keputusan)|product_delivery.*operations|operations.*product_delivery|chair.*facilitator|facilitator.*chair|root\s+appointment\s+issuer.*voter|voter.*root\s+appointment\s+issuer|(?:seven|all)\s+(?:base|required)\s+(?:roles|functions))/i
  ROLE_CONCENTRATION_PATTERN = /(?:satu\s+orang|orang\s+yang\s+sama|orang\s+sama|digabung|dirangkap|dual[- ]?hat|tidak\s+harus\s+(?:berbeda|terpisah)|one\s+person|same\s+person|combined|concentrated|double[- ]?hatted|need\s+not\s+be\s+(?:distinct|different|separate))/i

  attr_reader :blockers, :errors, :summary

  def initialize(root: File.expand_path('..', __dir__), authoritative_integrity_runner: nil, trusted_identity_root_sha256: nil)
    @root = File.expand_path(root)
    @authoritative_integrity_runner = authoritative_integrity_runner
    @trusted_identity_root_sha256 = trusted_identity_root_sha256
    @errors = []
    @blockers = []
    @summary = nil
    @documents = {}
  end

  def validate_integrity
    reset_results
    intake = read_text(path_for(INTAKE_FILE), 'intake')
    return false unless intake

    binding = extract_binding(intake)
    manifest = load_json(path_for(MANIFEST_FILE), 'manifest')
    batch_a = load_json(path_for(BATCH_A_FILE), 'Batch A register')
    policy = load_json(path_for(POLICY_FILE), 'owner authority policy')
    return false unless binding && manifest && batch_a && policy

    validate_binding_shape(binding)
    validate_frozen_sources(binding, manifest, batch_a, policy)
    validate_pending_decision(binding, batch_a, policy)
    validate_intake_state(binding, intake)
    validate_role_rows(binding, intake)
    validate_conditional_signing_profile_prose(intake)
    validate_safety_and_separation_contract(binding, intake, batch_a, policy)
    validate_secret_boundary(intake)
    validate_public_spki_cells(intake)

    @summary = if errors.empty?
                 'PASS G0/S0 intake integrity: frozen sources, closed machine binding, conditional role/separation rules, semantic synthetic-only/no-transmission guards, secret rejection, and public-only RSA SPKI validation verified.'
               end
    errors.empty?
  end

  def validate_signing_readiness
    return false unless validate_integrity

    identity_registry = load_json(path_for(IDENTITY_FILE), 'identity/key registry')
    appointment_register = load_json(path_for(APPOINTMENT_FILE), 'appointment register')
    session_register = load_json(path_for(SESSION_FILE), 'decision session register')
    return false unless identity_registry && appointment_register && session_register

    binding = extract_binding(@documents.fetch('intake'))
    selected_disposition = binding && binding['selected_disposition']
    required_people = EXPECTED_SIGNING_PROFILES.dig(selected_disposition, 'minimum_distinct_people')

    identities = Array(identity_registry['identities'])
    keys = identities.sum { |identity| identity.is_a?(Hash) ? Array(identity['keys']).length : 0 }
    root_signatures = Array(identity_registry.dig('registry_root', 'signatures'))
    appointments = Array(appointment_register['appointments'])
    sessions = Array(session_register['sessions'])

    if required_people
      blockers << "identity registry has #{identities.length} identities; #{required_people} are required for #{selected_disposition} under default separation" if identities.length < required_people
    elsif identities.length < 7
      blockers << "identity registry has #{identities.length} identities; the unresolved minimum is 7 for reproduce or 8 for replace under default separation"
    end
    blockers << "identity registry has #{keys} public keys; required signing purposes are not provisioned" if keys.zero?
    blockers << "identity registry root has #{root_signatures.length} signatures; 2 distinct trust-root signatures are required" if root_signatures.length < 2
    blockers << "appointment register has #{appointments.length} appointments; S0 chair, facilitator, and all required decision seats are unappointed" if appointments.empty?
    blockers << "decision session register has #{sessions.length} sessions; no S0 session exists" if sessions.empty?
    blockers << 'PAR-ADM-005 machine decision remains pending/UNDECIDED' if adm_005_pending?
    blockers << 'selected disposition is absent; minimum roster remains unresolved at 7 for reproduce versus 8 for replace under default separation' unless selected_disposition_present?
    blockers << 'external trust-root SHA-256 pin is absent; repository self-reference is not an independent pin' unless external_pin_present?
    blockers << 'role_subject_mapping_not_ready: each required role row must map to a distinct active UEU-PERSON identity in the registry before readiness can pass' unless role_subject_mapping_ready?(binding, identities)
    blockers << 'authoritative_governance_integrity_not_passed: the complete cryptographic owner-governance validator must pass before S0 signing/closure' unless authoritative_governance_integrity_passes?
    Array(binding && binding['known_signing_readiness_gaps']).each do |gap|
      case gap
      when 'snapshot_revision_timestamp_not_ready'
        blockers << 'snapshot_revision_timestamp_not_ready: source descriptors and policy generation remain frozen to revision 1 at 2026-08-25; a changed ADM-005 source requires honest linear revision/timestamp support before signing'
      when 'chair_facilitator_semantics_not_ready'
        blockers << 'chair_facilitator_semantics_not_ready: the core governance validator does not yet enforce chair=product_delivery, facilitator=operations, and distinct identities'
      else
        blockers << "unknown_signing_readiness_gap: #{gap}"
      end
    end

    @summary = if blockers.empty?
                 'READY G0/S0 signing prerequisites are present; cryptographic and session validators must still verify the signed artifacts.'
               else
                 "NO-GO G0/S0 signing readiness: #{blockers.length} blocker(s)."
               end
    blockers.empty?
  end

  private

  def reset_results
    errors.clear
    blockers.clear
    @summary = nil
    @documents.clear
  end

  def path_for(filename)
    File.join(@root, PHASE_ZERO, filename)
  end

  def read_text(path, label)
    content = File.read(path, encoding: 'UTF-8')
    @documents[label] = content
    content
  rescue SystemCallError, EncodingError => e
    errors << "#{label}: cannot read #{path}: #{e.message}"
    nil
  end

  def load_json(path, label)
    content = read_text(path, label)
    parse_json(content) if content
  rescue JSON::ParserError => e
    errors << "#{label}: invalid JSON: #{e.message}"
    nil
  end

  def extract_binding(intake)
    if intake.scan(BINDING_BEGIN).length != 1 || intake.scan(BINDING_END).length != 1
      errors << 'intake: requires exactly one machine-binding marker pair'
      return nil
    end

    pattern = /#{Regexp.escape(BINDING_BEGIN)}\s*```json\s*(.*?)\s*```\s*#{Regexp.escape(BINDING_END)}/m
    match = intake.match(pattern)
    unless match
      errors << 'intake: machine binding must be one JSON fenced block between the fixed markers'
      return nil
    end

    parse_json(match[1])
  rescue JSON::ParserError => e
    errors << "intake: invalid machine-binding JSON: #{e.message}"
    nil
  end

  def validate_binding_shape(binding)
    expected_keys = %w[batch_a_register candidate_signing_profiles external_trust_root_pin_status intake_state known_signing_readiness_gaps manifest policy required_role_rows safety_boundary schema_version selected_disposition separation_contract snapshot_plan source_required_authority_domains source_row]
    return unless validate_closed_object(binding, expected_keys, 'intake binding')
    errors << 'intake binding: schema_version must be 1' unless binding['schema_version'] == 1

    validate_closed_object(binding['manifest'], %w[reference sha256], 'intake binding manifest')
    validate_closed_object(binding['batch_a_register'], %w[reference sha256], 'intake binding Batch A register')
    validate_closed_object(binding['snapshot_plan'], %w[reference sha256], 'intake binding snapshot plan')
    validate_closed_object(binding['source_row'], %w[canonical_disposition decision_status requirement_id sha256], 'intake binding source row')
    validate_closed_object(binding['policy'], %w[control_root_sha256 reference], 'intake binding policy')
    validate_closed_object(binding['safety_boundary'], %w[data_boundary external_integrations real_patient_data], 'intake binding safety boundary')
    validate_closed_object(binding['separation_contract'], %w[base_role_people_distinct recusal_requires_replacement replace_eighth_voter_distinct root_appointment_issuer_may_self_appoint], 'intake binding separation contract')

    profiles = binding['candidate_signing_profiles']
    validate_closed_object(profiles, %w[replace reproduce], 'intake binding candidate signing profiles')
    validate_closed_object(nested_value(profiles, 'reproduce'), %w[exact_decision_seats minimum_distinct_people], 'intake binding reproduce profile')
    validate_closed_object(nested_value(profiles, 'replace'), %w[additional_role_row default_separation dual_hat_requires_proven_compatibility exact_decision_seats minimum_distinct_people], 'intake binding replace profile')
    validate_closed_object(nested_value(profiles, 'replace', 'additional_role_row'), %w[function required_tokens role_id], 'intake binding replace additional role row')

    Array(binding['required_role_rows']).each_with_index do |row, index|
      validate_closed_object(row, %w[function ordinal required_tokens role_id], "intake binding required role rows[#{index}]")
    end

    errors << 'intake binding: manifest reference changed' unless nested_value(binding, 'manifest', 'reference') == MANIFEST_FILE
    errors << 'intake binding: Batch A reference changed' unless nested_value(binding, 'batch_a_register', 'reference') == BATCH_A_FILE
    errors << 'intake binding: snapshot plan reference changed' unless nested_value(binding, 'snapshot_plan', 'reference') == SNAPSHOT_PLAN_FILE
    errors << 'intake binding: policy reference changed' unless nested_value(binding, 'policy', 'reference') == POLICY_FILE
    errors << 'intake binding: known signing-readiness gaps drifted' unless Array(binding['known_signing_readiness_gaps']).sort == EXPECTED_READINESS_GAPS
  end

  def validate_frozen_sources(binding, _manifest, batch_a, policy)
    verify_file_digest(MANIFEST_FILE, EXPECTED_MANIFEST_SHA256, nested_value(binding, 'manifest', 'sha256'), 'manifest')
    verify_file_digest(BATCH_A_FILE, EXPECTED_BATCH_A_SHA256, nested_value(binding, 'batch_a_register', 'sha256'), 'Batch A register')
    verify_file_digest(SNAPSHOT_PLAN_FILE, EXPECTED_SNAPSHOT_PLAN_SHA256, nested_value(binding, 'snapshot_plan', 'sha256'), 'owner governance snapshot plan')

    errors << 'owner authority policy: manifest SHA does not bind the exact current manifest' unless policy['manifest_sha256'] == EXPECTED_MANIFEST_SHA256
    batch_descriptor = Array(policy['source_decision_registers']).find { |item| item['batch'] == 'A' }
    errors << 'owner authority policy: Batch A SHA does not bind the exact current register' unless batch_descriptor && batch_descriptor['sha256'] == EXPECTED_BATCH_A_SHA256

    actual_root = policy['control_root_sha256']
    binding_root = nested_value(binding, 'policy', 'control_root_sha256')
    errors << "owner authority policy: control root expected #{EXPECTED_POLICY_CONTROL_ROOT}, got #{actual_root.inspect}" unless actual_root == EXPECTED_POLICY_CONTROL_ROOT
    errors << "intake binding: policy control root expected #{EXPECTED_POLICY_CONTROL_ROOT}, got #{binding_root.inspect}" unless binding_root == EXPECTED_POLICY_CONTROL_ROOT
    recomputed_root = Digest::SHA256.hexdigest(canonical_json(policy.to_h.reject { |key, _value| %w[policy_status approval control_root_sha256].include?(key) }))
    errors << 'owner authority policy: control root is not independently recomputable' unless recomputed_root == EXPECTED_POLICY_CONTROL_ROOT

    errors << 'Batch A register: must remain the exact pending synthetic-only register' unless batch_a['register_status'] == 'pending' && batch_a['data_boundary'] == 'synthetic_only' && batch_a['external_integrations'] == 'disabled'
  end

  def verify_file_digest(filename, expected, bound, label)
    actual = Digest::SHA256.file(path_for(filename)).hexdigest
    errors << "#{label}: actual SHA-256 expected #{expected}, got #{actual}" unless actual == expected
    errors << "intake binding: #{label} SHA-256 expected #{expected}, got #{bound.inspect}" unless bound == expected
  rescue SystemCallError => e
    errors << "#{label}: cannot hash source: #{e.message}"
  end

  def validate_pending_decision(binding, batch_a, policy)
    entries = Array(batch_a['entries'])
    rows = entries.select { |entry| entry['requirement_id'] == 'PAR-ADM-005' }
    unless rows.length == 1
      errors << "Batch A register: expected exactly one PAR-ADM-005 row, got #{rows.length}"
      return
    end

    row = rows.first
    row_sha = Digest::SHA256.hexdigest(canonical_json(row))
    errors << "PAR-ADM-005: source-row SHA-256 expected #{EXPECTED_ADM_005_ROW_SHA256}, got #{row_sha}" unless row_sha == EXPECTED_ADM_005_ROW_SHA256
    errors << 'intake binding: PAR-ADM-005 source-row SHA-256 is stale' unless nested_value(binding, 'source_row', 'sha256') == EXPECTED_ADM_005_ROW_SHA256
    errors << 'intake binding: source requirement must be PAR-ADM-005' unless nested_value(binding, 'source_row', 'requirement_id') == 'PAR-ADM-005'

    decision = row['decision'] || {}
    pending = decision['status'] == 'pending' && decision['canonical_disposition'] == 'pending'
    errors << 'PAR-ADM-005: machine decision must remain status=pending and canonical_disposition=pending' unless pending
    errors << 'intake binding: machine decision metadata must remain pending' unless nested_value(binding, 'source_row', 'decision_status') == 'pending' && nested_value(binding, 'source_row', 'canonical_disposition') == 'pending'

    row_seats = Array(row['co_owners']).sort
    binding_seats = Array(binding['source_required_authority_domains']).sort
    errors << "PAR-ADM-005: required seat set drifted to #{row_seats.inspect}" unless row_seats == EXPECTED_SEATS
    errors << "intake binding: required seat set drifted to #{binding_seats.inspect}" unless binding_seats == EXPECTED_SEATS
    errors << 'intake binding: selected_disposition must remain null while intake is UNDECIDED' unless binding['selected_disposition'].nil?
    errors << 'intake binding: external trust-root pin status must remain absent while intake is UNDECIDED' unless binding['external_trust_root_pin_status'] == 'absent'
    errors << 'intake binding: conditional reproduce/replace signing profiles drifted' unless binding['candidate_signing_profiles'] == EXPECTED_SIGNING_PROFILES

    policy_row = Array(policy['requirement_policies']).find { |item| item['requirement_id'] == 'PAR-ADM-005' }
    unless policy_row
      errors << 'owner authority policy: PAR-ADM-005 requirement policy is missing'
      return
    end

    policy_seats = Array(policy_row['required_authority_domains']).sort
    errors << "owner authority policy: PAR-ADM-005 required seat set drifted to #{policy_seats.inspect}" unless policy_seats == EXPECTED_SEATS
    errors << 'owner authority policy: PAR-ADM-005 row binding is stale' unless policy_row['source_row_sha256'] == EXPECTED_ADM_005_ROW_SHA256 && policy_row['batch_register_sha256'] == EXPECTED_BATCH_A_SHA256
  end

  def validate_intake_state(binding, intake)
    errors << 'intake binding: intake_state must remain UNDECIDED' unless binding['intake_state'] == 'UNDECIDED'
    errors << 'intake: explicit UNDECIDED state is missing' unless intake.match?(/status tetap \*\*UNDECIDED\*\*/)

    selected = intake.scan(/^\s*-\s+\[([xX])\]/).length
    errors << "intake: all decision/readiness checkboxes must remain unchecked while machine decision is pending (#{selected} selected)" unless selected.zero?

    decision_section = intake[/Keputusan institusi — pilih tepat satu:(.*?)Sebelum pilihan ditetapkan/m, 1]
    decision_boxes = decision_section.to_s.scan(/^\s*-\s+\[([ xX])\]/)
    errors << "intake: expected exactly 4 institutional decision boxes, got #{decision_boxes.length}" unless decision_boxes.length == 4
  end

  def validate_role_rows(binding, intake)
    bound_rows = binding['required_role_rows']
    errors << 'intake binding: seven fixed role rows changed' unless bound_rows == EXPECTED_ROLE_ROWS
    return unless bound_rows.is_a?(Array)

    ids = bound_rows.map { |row| row['role_id'] if row.is_a?(Hash) }.compact
    ordinals = bound_rows.map { |row| row['ordinal'] if row.is_a?(Hash) }.compact
    errors << 'intake binding: role row IDs must be seven distinct values' unless ids.length == 7 && ids.uniq.length == 7
    errors << 'intake binding: role row ordinals must be exactly 1 through 7' unless ordinals == (1..7).to_a

    table_rows = intake.lines.map do |line|
      cells = line.strip.split('|').map(&:strip).reject(&:empty?)
      cells if cells.length == 5 && cells.first.match?(/\A(?:[1-7]|8 \(`replace` saja\))\z/)
    end.compact
    errors << "intake: expected seven base role rows plus one conditional replace row, got #{table_rows.length}" unless table_rows.length == 8

    EXPECTED_ROLE_ROWS.each do |expected|
      row = table_rows.find { |cells| cells.first == expected['ordinal'].to_s }
      unless row
        errors << "intake: role row #{expected['ordinal']} is missing"
        next
      end
      errors << "intake: role row #{expected['ordinal']} function drifted" unless row[1] == expected['function']
      expected['required_tokens'].each do |token|
        errors << "intake: role row #{expected['ordinal']} is missing #{token}" unless row.join(' ').include?(token)
      end
    end

    replace_row = table_rows.find { |cells| cells.first == '8 (`replace` saja)' }
    expected_replace_row = EXPECTED_SIGNING_PROFILES.dig('replace', 'additional_role_row')
    unless replace_row
      errors << 'intake: conditional replace executive-sponsor voter row is missing'
      return
    end
    errors << 'intake: conditional replace executive-sponsor voter function drifted' unless replace_row[1] == expected_replace_row['function']
    expected_replace_row['required_tokens'].each do |token|
      errors << "intake: conditional replace executive-sponsor voter row is missing #{token}" unless replace_row.join(' ').include?(token)
    end
  end

  def validate_conditional_signing_profile_prose(intake)
    required_fragments = [
      '| Kursi dasar jika `reproduce` | `product_delivery`, `operations`, `security_privacy_data` |',
      '| Kursi tambahan jika `replace` | `executive_sponsor`; total empat kursi keputusan |',
      '| Kuorum keputusan | Tiga orang unik untuk `reproduce`; empat orang unik untuk `replace` dengan pemisahan default |',
      'Untuk `reproduce`, tiga kursi dasar memberikan `consent`; untuk `replace`, tiga kursi dasar plus kursi `executive_sponsor` memberikan `consent`.',
      '`Reproduce`: tiga orang unik memberikan consent untuk tiga kursi; `replace`: empat orang unik memberikan consent untuk empat kursi termasuk `executive_sponsor`'
    ]
    missing = required_fragments.reject { |fragment| intake.include?(fragment) }
    errors << "intake: conditional reproduce/replace human instructions drifted (#{missing.length} required fragment(s) missing)" unless missing.empty?
  end

  def validate_safety_and_separation_contract(binding, intake, batch_a, policy)
    errors << 'intake binding: synthetic-only safety boundary drifted' unless binding['safety_boundary'] == EXPECTED_SAFETY_BOUNDARY
    errors << 'intake binding: human separation contract drifted' unless binding['separation_contract'] == EXPECTED_SEPARATION_CONTRACT
    errors << 'Batch A register: safety boundary is not synthetic-only with external integrations disabled' unless batch_a['data_boundary'] == 'synthetic_only' && batch_a['external_integrations'] == 'disabled'
    errors << 'owner authority policy: data boundary is not synthetic_only' unless policy['data_boundary'] == 'synthetic_only'

    required_fragments = [
      '**Batas data:** `synthetic_only`.',
      'Tujuh baris dasar berikut harus diisi oleh tujuh orang berbeda.',
      'Jika institusi memilih `replace`, baris kedelapan juga wajib diisi oleh orang berbeda',
      '- appointer berbeda dari setiap appointee;',
      '- recusal membuat kursi kosong dan harus digantikan appointment lain yang sah;',
      '- Tidak ada data pasien nyata, identitas nyata di data klinis, atau pemindahan data produksi.',
      '- Tidak ada koneksi atau transmisi live ke BPJS, VClaim, SATUSEHAT, perangkat produksi, sertifikat TTE produksi, atau endpoint eksternal lain.'
    ]
    missing = required_fragments.reject { |fragment| intake.include?(fragment) }
    errors << "intake: synthetic-only/no-transmission or separation instructions drifted (#{missing.length} required fragment(s) missing)" unless missing.empty?
  end

  def validate_secret_boundary(intake)
    errors << 'intake: private-key PEM material is forbidden' if intake.match?(PRIVATE_KEY_PATTERN)
    errors << 'intake: populated credential or secret assignment is forbidden' if populated_secret_assignment?(intake)
    errors << 'intake: bearer/token material is forbidden' if intake.match?(TOKEN_VALUE_PATTERN)
    errors << 'intake: Basic authorization material is forbidden' if intake.match?(BASIC_AUTH_PATTERN)
    errors << 'intake: Cookie material is forbidden' if intake.match?(COOKIE_VALUE_PATTERN)
    validate_semantic_safety_claims(intake)
  end

  def populated_secret_assignment?(intake)
    markdown_unwrapped_assignment_source(intake).scan(SECRET_ASSIGNMENT_PATTERN).any? do |match|
      populated_value?(match.first)
    end || intake.lines.any? do |line|
      cells = markdown_cells(line)
      next false unless cells.length >= 2

      normalized_label = normalize_table_label(cells[0])
      normalized_label.match?(SENSITIVE_TABLE_LABEL_PATTERN) && populated_value?(cells[1])
    end
  end

  def validate_public_spki_cells(intake)
    intake.lines.each_with_index do |line, index|
      cells = markdown_cells(line)
      next unless cells.length >= 2 && normalize_table_label(cells[0]) == 'rsa public spki base64'

      value = cells[1].to_s.strip.delete('`')
      next unless populated_value?(value)

      begin
        der = Base64.strict_decode64(value)
        key = OpenSSL::PKey.read(der)
        valid = Base64.strict_encode64(der) == value &&
                key.is_a?(OpenSSL::PKey::RSA) && !key.private? &&
                key.n.num_bits >= 3072 && key.e.to_i == 65_537 &&
                key.public_key.to_der == der
        errors << "intake: RSA public SPKI Base64 row #{index + 1} must contain canonical public-only RSA SPKI (3072+ bits, exponent 65537)" unless valid
      rescue ArgumentError, OpenSSL::PKey::PKeyError, OpenSSL::PKey::RSAError
        errors << "intake: RSA public SPKI Base64 row #{index + 1} is not canonical public-only RSA SPKI"
      end
    end
  end

  def validate_semantic_safety_claims(intake)
    prose_without_binding(intake).lines.each_with_index do |line, index|
      integration_assertions(line).each do |assertion|
        if unsafe_live_integration_claim?(assertion)
          errors << "intake: live BPJS/VClaim/SATUSEHAT assertion is not an unambiguous prohibition or explicit future-decision gate (line #{index + 1})"
        end
      end
      semantic_clauses(line).each do |clause|
        if unsafe_role_concentration_claim?(clause)
          errors << "intake: affirmative concentration of required institutional roles is forbidden (line #{index + 1})"
        end
      end
    end
  end

  def unsafe_live_integration_claim?(clause)
    normalized = normalize_prose(clause)
    return false unless normalized.match?(INTEGRATION_NAME_PATTERN) && normalized.match?(/\blive\b/i)

    !safe_live_integration_clause?(normalized)
  end

  def safe_live_integration_clause?(normalized)
    negated_restriction_patterns = [
      /\b(?:belum|tidak|bukan|jangan)\s+(?:(?:pernah|boleh|harus|akan|sedang|lagi|untuk)\s+){0,2}(?:dilarang|dinonaktifkan|nonaktif)\b/i,
      /\b(?:do|does|must|should|shall)\s+not\s+(?:be\s+)?(?:prohibited|forbidden|disabled|prohibit|forbid|disable)\b/i,
      /\b(?:not|never)\s+(?:be\s+)?(?:prohibited|forbidden|disabled)\b/i
    ]
    return false if negated_restriction_patterns.any? { |pattern| normalized.match?(pattern) }

    prohibition_patterns = [
      /\b(?:tidak|belum|jangan)\b[^.;]*\b(?:live|diizinkan|diperbolehkan|boleh|diaktifkan|mengaktifkan|aktif|digunakan|dipakai|beroperasi|tersedia|berjalan|terhubung|dikirim|ditransmisikan)\b/i,
      /\b(?:dilarang|forbidden|disabled|dinonaktifkan|nonaktif)\b/i,
      /\b(?:is|are)\s+not\s+(?:enabled|authorized|allowed|active|available|operational|running|used|connected|sent|transmitted)\b/i,
      /\bmust\s+not\s+(?:be\s+)?(?:enabled|authorized|allowed|active|available|operational|running|used|connected|sent|transmitted)\b/i,
      /\b(?:do|does)\s+not\s+(?:enable|authorize|allow|use|connect|send|transmit|operate)\b/i,
      /\bno\s+live\s+(?:connection|transmission|integration|use|operation)\b/i
    ]
    return true if prohibition_patterns.any? { |pattern| normalized.match?(pattern) }

    future_gate_patterns = [
      /\b(?:hanya\s+)?(?:boleh|dapat)\b[^.;]*\b(?:sesudah|setelah)\b[^.;]*\bkeputusan\s+baru(?:\s+yang\s+eksplisit)?\b/i,
      /\b(?:memerlukan|membutuhkan)\b[^.;]*\bkeputusan\s+baru(?:\s+yang\s+eksplisit)?\b[^.;]*\bsebelum\b/i,
      /\b(?:only|may\s+only|can\s+only)\b[^.;]*\bafter\b[^.;]*\b(?:an?\s+)?(?:explicit\s+)?new\s+decision\b/i,
      /\brequires?\b[^.;]*\b(?:an?\s+)?(?:explicit\s+)?new\s+decision\b[^.;]*\bbefore\b/i,
      /\bunless\b[^.;]*\b(?:separate|explicit|new)\b[^.;]*\b(?:decision|authorization)\b/i,
      /\bsampai\b[^.;]*\bdiotorisasi\s+melalui\s+keputusan\s+baru\b/i
    ]
    future_gate_patterns.any? { |pattern| normalized.match?(pattern) }
  end

  def unsafe_role_concentration_claim?(clause)
    normalized = normalize_prose(clause)
    return false unless normalized.match?(ROLE_SCOPE_PATTERN) && normalized.match?(ROLE_CONCENTRATION_PATTERN)
    return true if normalized.match?(/\btidak\s+harus\s+(?:berbeda|terpisah)\b/i)
    return true if normalized.match?(/\bneed\s+not\s+be\s+(?:distinct|different|separate)\b/i)
    return false if normalized.match?(/\b(?:tidak\s+boleh|jangan|dilarang|forbidden)\b[^.;]*(?:satu\s+orang|orang\s+(?:yang\s+)?sama|digabung|dirangkap|dual[- ]?hat)/i)
    return false if normalized.match?(/\b(?:must\s+not|not\s+allowed\s+to)\b[^.;]*(?:one\s+person|same\s+person|combined|concentrated|double[- ]?hatted)/i)
    return false if normalized.match?(/\b(?:harus|wajib)\b[^.;]*(?:berbeda|terpisah|unik)/i)
    return false if normalized.match?(/\bmust\s+(?:be\s+)?(?:distinct|different|separate)\b/i)

    normalized.match?(/\b(?:boleh|dapat|diizinkan|diperbolehkan|may|can|allowed)\b[^.;]*(?:satu\s+orang|orang\s+(?:yang\s+)?sama|digabung|dirangkap|dual[- ]?hat|one\s+person|same\s+person|combined|concentrated|double[- ]?hatted)/i) ||
      normalized.match?(/(?:satu\s+orang|orang\s+(?:yang\s+)?sama|digabung|dirangkap|dual[- ]?hat|one\s+person|same\s+person|combined|concentrated|double[- ]?hatted)[^.;]*\b(?:boleh|dapat|diizinkan|diperbolehkan|may|can|allowed)\b/i)
  end

  def prose_without_binding(intake)
    pattern = /#{Regexp.escape(BINDING_BEGIN)}.*?#{Regexp.escape(BINDING_END)}/m
    intake.sub(pattern, '')
  end

  def semantic_clauses(line)
    line.split(/(?:[.;]|\b(?:namun|tetapi)\b)/i).map(&:strip).reject(&:empty?)
  end

  def integration_assertions(line)
    context_integrations = []
    live_context = false
    normalized = normalize_prose(line)
    fragments = normalized.split(/(?:[,;.]|\b(?:dan|serta|and|namun|tetapi|but)\b)/i).map(&:strip).reject(&:empty?)

    fragments.each_with_object([]) do |fragment, assertions|
      integrations = fragment.scan(INTEGRATION_NAME_PATTERN).map(&:downcase).uniq
      context_integrations = integrations unless integrations.empty?
      live_context = true if fragment.match?(/\blive\b/i)
      next if context_integrations.empty? || !live_context

      explicit_live = fragment.match?(/\blive\b/i)
      assertion_signal = fragment.match?(INTEGRATION_ASSERTION_SIGNAL_PATTERN)
      next unless explicit_live || assertion_signal

      assertions << "#{context_integrations.join(' ')} live #{fragment}"
    end
  end

  def markdown_cells(line)
    line.strip.split('|').map(&:strip).reject(&:empty?)
  end

  def normalize_table_label(label)
    label.to_s.downcase.gsub(/[^a-z0-9]+/, ' ').gsub(/\s+/, ' ').strip
  end

  def normalize_prose(value)
    value.to_s.downcase.tr('`*', '  ').gsub(/\s+/, ' ').strip
  end

  def populated_value?(value)
    normalized = value.to_s.strip.delete('`*_').strip
    normalized = normalized.sub(/\A["']\s*/, '').sub(/\s*["']?[}\],]?\s*\z/, '')
    !normalized.empty? && !normalized.match?(PLACEHOLDER_PATTERN)
  end

  def markdown_unwrapped_assignment_source(intake)
    intake.gsub(/[`*]/, '')
  end

  def role_subject_mapping_ready?(binding, identities)
    selected_disposition = binding && binding['selected_disposition']
    required_ordinals = case selected_disposition
                        when 'reproduce' then (1..7).to_a
                        when 'replace' then (1..8).to_a
                        else return false
                        end

    assignments = role_subject_assignments(@documents.fetch('intake'))
    return false unless assignments.keys.sort == required_ordinals

    assigned_subjects = assignments.values
    return false unless assigned_subjects.uniq.length == required_ordinals.length

    registry_subjects = identities.map { |identity| identity['subject_id'] if identity.is_a?(Hash) }.compact
    return false unless registry_subjects.uniq.length == registry_subjects.length

    identities_by_subject = identities.each_with_object({}) do |identity, index|
      index[identity['subject_id']] = identity if identity.is_a?(Hash)
    end
    required_ordinals.all? do |ordinal|
      identity = identities_by_subject[assignments.fetch(ordinal)]
      requirements = ROLE_REQUIREMENTS.fetch(ordinal)
      next false unless identity && identity['identity_type'] == 'person' && identity['status'] == 'active'

      authorization_roles = Array(identity['authorization_roles'])
      purposes = Array(identity['keys']).flat_map { |key| key.is_a?(Hash) ? Array(key['allowed_purposes']) : [] }.uniq
      (requirements['authorization_roles'] - authorization_roles).empty? &&
        (requirements['key_purposes'] - purposes).empty?
    end
  end

  def role_subject_assignments(intake)
    intake.lines.each_with_object({}) do |line, assignments|
      cells = markdown_cells(line)
      next unless cells.length == 5

      ordinal = if cells[0].match?(/\A[1-7]\z/)
                  cells[0].to_i
                elsif cells[0] == '8 (`replace` saja)'
                  8
                end
      next unless ordinal

      subject_ids = cells[4].scan(/\bUEU-PERSON-[A-Z0-9][A-Z0-9._-]*\b/)
      return {} unless subject_ids.length == 1 || !populated_value?(cells[4])

      assignments[ordinal] = subject_ids.first if subject_ids.length == 1
    end
  end

  def validate_closed_object(value, expected_keys, label)
    unless value.is_a?(Hash)
      errors << "#{label}: must be an object with the closed field set #{expected_keys.sort.join(', ')}"
      return false
    end

    return true if value.keys.sort == expected_keys.sort

    errors << "#{label}: closed field set changed (expected #{expected_keys.sort.inspect}, got #{value.keys.sort.inspect})"
    false
  end

  def nested_value(value, *keys)
    keys.reduce(value) { |object, key| object.is_a?(Hash) ? object[key] : nil }
  end

  def adm_005_pending?
    binding = extract_binding(@documents.fetch('intake'))
    binding && nested_value(binding, 'source_row', 'decision_status') == 'pending' && binding['intake_state'] == 'UNDECIDED'
  end

  def external_pin_present?
    binding = extract_binding(@documents.fetch('intake'))
    binding && binding['external_trust_root_pin_status'] == 'verified_external' && @trusted_identity_root_sha256.to_s.match?(/\A[0-9a-f]{64}\z/)
  end

  def selected_disposition_present?
    binding = extract_binding(@documents.fetch('intake'))
    binding && %w[reproduce replace].include?(binding['selected_disposition'])
  end

  def authoritative_governance_integrity_passes?
    return @authoritative_integrity_runner.call(@trusted_identity_root_sha256) if @authoritative_integrity_runner

    script = File.join(@root, 'scripts/validate-parity-governance.rb')
    return false unless File.file?(script)

    arguments = [RbConfig.ruby, script, '--mode', 'integrity']
    if @trusted_identity_root_sha256.to_s.match?(/\A[0-9a-f]{64}\z/)
      arguments.concat(['--trusted-identity-root-sha256', @trusted_identity_root_sha256])
    end
    _stdout, _stderr, status = Open3.capture3(*arguments, chdir: @root)
    status.success?
  rescue SystemCallError
    false
  end

  def canonical_json(value)
    JSON.generate(canonical_value(value), ascii_only: true).encode(Encoding::UTF_8)
  end

  def parse_json(source)
    JSON.parse(source, object_class: DuplicateKeyHash, allow_duplicate_key: false)
  end

  def canonical_value(value)
    case value
    when Hash
      value.keys.each { |key| raise ArgumentError, 'canonical JSON keys must be ASCII strings' unless key.is_a?(String) && key.ascii_only? }
      value.keys.sort.to_h { |key| [key, canonical_value(value.fetch(key))] }
    when Array
      value.map { |item| canonical_value(item) }
    when String
      normalized = value.encode(Encoding::UTF_8).unicode_normalize(:nfc)
      normalized.match?(/\A\d{4}-\d{2}-\d{2}T/) ? Time.iso8601(normalized).utc.iso8601 : normalized
    when Integer
      raise ArgumentError, 'canonical JSON integer is outside int64' unless value.between?(-(2**63), (2**63) - 1)

      value
    when TrueClass, FalseClass, NilClass
      value
    when Float
      raise ArgumentError, 'canonical JSON forbids floating-point values'
    else
      raise ArgumentError, "canonical JSON forbids #{value.class}"
    end
  end
end

if __FILE__ == $PROGRAM_NAME
  options = { mode: 'integrity', root: File.expand_path('..', __dir__), trusted_identity_root_sha256: nil }
  parser = OptionParser.new do |opts|
    opts.banner = 'Usage: ruby scripts/validate-g0-s0-intake.rb [--mode integrity|signing-readiness] [--root PATH]'
    opts.on('--mode MODE', %w[integrity signing-readiness], 'Validation mode') { |value| options[:mode] = value }
    opts.on('--root PATH', 'Repository or deterministic fixture root') { |value| options[:root] = value }
    opts.on('--trusted-identity-root-sha256 SHA', 'Independently supplied institutional trust-root SHA-256') { |value| options[:trusted_identity_root_sha256] = value }
  end

  begin
    parser.parse!
    validator = G0S0IntakeValidator.new(root: options[:root], trusted_identity_root_sha256: options[:trusted_identity_root_sha256])
    valid = options[:mode] == 'signing-readiness' ? validator.validate_signing_readiness : validator.validate_integrity
    puts validator.summary if validator.summary
    validator.errors.each { |error| warn "ERROR: #{error}" }
    validator.blockers.each { |blocker| warn "BLOCKER: #{blocker}" }
    exit(valid ? 0 : 1)
  rescue OptionParser::ParseError => e
    warn "ERROR: #{e.message}"
    warn parser
    exit 2
  end
end
