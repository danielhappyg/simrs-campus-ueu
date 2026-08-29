# frozen_string_literal: true

require 'digest'
require 'json'
require 'minitest/autorun'

class G0GovernanceV2HostTrustBoundaryArchitectureDecisionTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  ADR_PATH = File.join(ROOT, 'docs/adr/ADR-019-HOST-OWNED-G0-GOVERNANCE-TRUST-BOUNDARY.md')
  ADR018_PATH = File.join(ROOT, 'docs/adr/ADR-018-PROPORTIONAL-G0-GOVERNANCE-PROFILE.md')
  ADOPTION_PATH = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ADOPTION_DECISION.json')
  PROPOSAL_PATH = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_EXTERNAL_TRUST_PROVISIONING_PROPOSAL_2026-08-29.md')

  ADR018_SHA = 'cd7834e7f5b0be99acee3f1b46f8af9fc81b77418541fddf7276fadc26edf962'
  ADOPTION_SHA = '139bf2f952ab5c47cd34a43f80d93224e48386c57f180a799d8f206a9049c0f3'
  PROPOSAL_SHA = 'a5397de2333c9e107cc78175b09031450122bcada14c17afc5e30d48eccd6eea'

  IDENTITY_FIELDS = %w[
    artifact_type schema_version profile status host_id root_uid root_gid wheel_gid
    repository_writer_uid application_runtime_uid canonical_checkout_path
    canonical_checkout_device_id canonical_checkout_inode state_root_path
    state_root_device_id state_root_inode bound_at bound_by_uid
  ].freeze
  PARENT_FIELDS = %w[
    artifact_type schema_version profile checkout_root checkout_root_device_id
    checkout_root_inode phase0_parent_path phase0_parent_device_id phase0_parent_inode
    owner_uid group_gid mode file_flags acl_type acl_serialization acl_sha256 aces
    authority_entries checked_at expires_at
  ].freeze
  ACE_FIELDS = %w[
    position ace_type principal_kind principal_name principal_uuid rights
    inheritance_flags
  ].freeze
  AUTHORITY_FIELDS = %w[
    relative_path object_type owner_uid group_gid mode device_id inode_or_null
    creation_parent_relative_path creation_parent_device_id creation_parent_inode
    entry_acl_sha256_or_null parent_acl_sha256 allowed_operations
  ].freeze
  AUTHORITY_PATHS = %w[
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_JOURNAL
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_POINTER.json
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_POINTER_RECOVERY_REQUIRED.json
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_SELECTION.lock
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_SELECTIONS
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ACTIVATION_DECISIONS
  ].freeze
  BOOTSTRAP_FIELDS = %w[
    artifact_type schema_version profile status bootstrap_generation
    host_identity_reference immutable_binary pin_record_directory_reference
    current_pointer_path created_at created_by_uid
  ].freeze
  PIN_DIRECTORY_FIELDS = %w[
    artifact_type schema_version path device_id inode owner_uid group_gid mode
    acl_sha256 created_at
  ].freeze
  BINARY_FIELDS = %w[
    path device_id inode link_count owner_uid group_gid mode size_bytes sha256
    code_identity
  ].freeze
  CODE_IDENTITY_FIELDS = %w[
    schema_version designated_requirement_bytes_base64 designated_requirement_sha256
    team_identifier signing_certificate_sha256 signing_identifier
    cdhashes_by_architecture architectures hardened_runtime_required
    library_validation_required permitted_apple_library_roots
    forbid_dlopen_and_plugins
  ].freeze
  PIN_FIELDS = %w[
    artifact_type schema_version profile status pin_id generation valid_from expires_at
    predecessor_pin_path predecessor_pin_sha256 bootstrap_pin_sha256
    host_identity_reference immutable_binary canonical_checkout_reference
    git_tool_reference ssh_tool_reference state_acl_reference request_inbox_reference
    replay_identity_reference replay_policy_reference receipt_store_reference
    trust_store_reference authorship_roster_reference reason_code_contract_reference
    created_at
  ].freeze
  CURRENT_POINTER_FIELDS = %w[
    artifact_type schema_version profile status generation pin_record_path
    pin_record_sha256 updated_at updated_by_uid
  ].freeze
  GIT_FIELDS = %w[
    path device_id inode owner_uid group_gid mode sha256 version_stdout_sha256
    maximum_stdout_bytes maximum_stderr_bytes maximum_runtime_milliseconds
    sanitized_environment
  ].freeze
  SSH_FIELDS = %w[
    path device_id inode owner_uid group_gid mode sha256 version_stdout_sha256
    maximum_stdin_bytes maximum_stdout_bytes maximum_stderr_bytes
    maximum_runtime_milliseconds sanitized_environment
  ].freeze
  REPLAY_IDENTITY_FIELDS = %w[
    artifact_type schema_version replay_store_id directory_path device_id inode
    owner_uid group_gid mode acl_sha256 genesis_record_path genesis_record_sha256
    chain_algorithm created_at
  ].freeze
  REPLAY_POLICY_FIELDS = %w[
    artifact_type schema_version policy_id status replay_identity_reference writer_uid
    valid_from expires_at predecessor_policy_reference approved_reason
  ].freeze
  ROSTER_FIELDS = %w[
    artifact_type schema_version roster_id status valid_from expires_at
    canonical_checkout_reference canonical_commit_oid_sha1
    canonical_commit_content_sha256 executor_identity_id product_owner_identity_id
    reviewer_identity_id covered_artifacts excluded_identity_set_sha256
    predecessor_roster_reference created_at
  ].freeze
  COVERED_FIELDS = %w[
    path sha256 artifact_kind verified_author_identity_ids
    verified_approver_identity_ids provenance_records
  ].freeze
  PROVENANCE_FIELDS = %w[
    record_type external_record_path external_record_sha256 identity_id event_at
    verification_method
  ].freeze
  FILE_REFERENCE_FIELDS = %w[
    logical_role filename sha256 size_bytes device_id inode link_count owner_uid
    group_gid mode
  ].freeze
  BUNDLE_FIELDS = %w[
    artifact_type schema_version bundle_id request_id operation created_at expires_at
    inbox_identity_reference operation_intent_reference approval_subject_reference
    owner_attestation_payload_reference owner_signature_reference
    owner_signature_import_receipt_reference owner_attestation_reference
    reviewer_attestation_payload_reference reviewer_signature_reference
    reviewer_signature_import_receipt_reference reviewer_attestation_reference
    operation_decision_reference request_reference file_inventory_sha256
  ].freeze
  SIGNATURE_IMPORT_FIELDS = %w[
    artifact_type schema_version import_receipt_id receipt_kind bundle_id request_id
    operation role signer_key_id approval_subject_sha256 attestation_payload_sha256
    signature_filename signature_sha256
    signature_size_bytes inbox_directory_path inbox_directory_device_id
    inbox_directory_inode signature_inode imported_at imported_by_real_uid
    imported_by_effective_uid
  ].freeze
  BUNDLE_IMPORT_FIELDS = %w[
    artifact_type schema_version import_receipt_id receipt_kind bundle_id request_id
    operation inbox_directory_path inbox_directory_device_id inbox_directory_inode
    imported_file_references request_bundle_path request_bundle_sha256 imported_at
    imported_by_real_uid imported_by_effective_uid
  ].freeze
  IMPORT_STATE_FIELDS = %w[
    artifact_type schema_version request_id status sealed next_filename_or_null
    imported_file_references signature_import_receipt_references
    request_bundle_reference_or_null bundle_import_receipt_reference_or_null
    request_seal_reference_or_null last_transition_at last_transition_by_uid
  ].freeze
  REQUEST_SEAL_FIELDS = %w[
    artifact_type schema_version seal_id request_id bundle_id operation
    request_bundle_reference bundle_import_receipt_reference sealed sealed_at
    sealed_by_uid
  ].freeze
  INTENT_FIELDS = %w[
    artifact_type schema_version intent_id request_id bundle_id operation environment
    candidate_reference prior_state_reference actor conditions created_at expires_at
  ].freeze
  SUBJECT_FIELDS = %w[
    artifact_type schema_version subject_id request_id bundle_id operation
    operation_intent_reference contract_reference technical_preflight_reference
    authorship_roster_reference excluded_identity_set_sha256 issued_at expires_at
  ].freeze
  ATTESTATION_PAYLOAD_FIELDS = %w[
    artifact_type schema_version payload_id request_id bundle_id operation role
    signer_key_id approval_subject_reference issued_at expires_at nonce
  ].freeze
  DECISION_REFERENCE_FIELDS = %w[
    artifact_type schema_version operation decision_id filename sha256
    canonical_publish_relative_path
  ].freeze
  ATTESTATION_FIELDS = %w[
    artifact_type schema_version attestation_id request_id bundle_id operation role
    signer_key_id approval_subject_reference attestation_payload_reference
    external_signature_reference signature_import_receipt_reference
  ].freeze
  SIGNATURE_REFERENCE_FIELDS = %w[
    filename sha256 size_bytes inbox_directory_path inbox_directory_device_id
    inbox_directory_inode file_inode link_count owner_uid group_gid mode
    import_receipt_path import_receipt_sha256
  ].freeze
  MUTATION_REQUEST_FIELDS = %w[
    artifact_type schema_version request_id bundle_id operation requested_at
    expires_at nonce operation_decision_reference
  ].freeze
  FINAL_DECISION_FIELDS = %w[
    artifact_type schema_version decision_id request_id bundle_id operation
    operation_intent_reference approval_subject_reference owner_attestation_reference
    reviewer_attestation_reference approval_evidence_reference
    independent_review_reference decision_effect conditions issued_at expires_at
  ].freeze
  RESERVATION_FIELDS = %w[
    artifact_type schema_version reservation_id request_id bundle_id operation
    current_pointer_sha256 pin_record_sha256 intent_id intent_sha256 decision_id
    decision_sha256 subject_id subject_sha256 owner_attestation_id
    owner_attestation_sha256 reviewer_attestation_id reviewer_attestation_sha256
    request_bundle_sha256 bundle_import_receipt_sha256 request_seal_sha256
    prior_pointer_sha256 replay_predecessor_sha256 reserved_at
  ].freeze
  RECEIPT_STORE_FIELDS = %w[
    artifact_type schema_version store_id root_path device_id inode owner_uid group_gid
    mode acl_sha256 terminal_directory_reference failure_directory_reference
    reconciliation_directory_reference genesis_receipt_path genesis_receipt_sha256
    predecessor_store_reference created_at
  ].freeze
  COMMON_RECEIPT_FIELDS = %w[
    receipt_id request_id bundle_id operation status reason_code phase caller_real_uid
    caller_effective_uid started_at finished_at current_pointer_sha256
    current_pin_sha256 request_sha256 bundle_sha256 reservation_sha256
    active_chain_sha256 result_sha256
    secret_scan_passed
  ].freeze
  DIAGNOSTIC_FIELDS = %w[
    artifact_type schema_version receipt_kind common
  ].freeze
  TERMINAL_FIELDS = %w[
    artifact_type schema_version receipt_kind common decision_sha256 selection_sha256
    journal_sha256 recovery_marker_sha256 prior_pointer_sha256 result_pointer_sha256
  ].freeze
  FAILURE_FIELDS = %w[
    artifact_type schema_version receipt_kind common failed_boundary errno_or_null
    observed_decision_sha256 observed_selection_sha256 observed_journal_sha256
    observed_recovery_marker_sha256 observed_pointer_sha256 required_next_action
  ].freeze
  RECONCILIATION_FIELDS = %w[
    artifact_type schema_version receipt_kind common detected_reservation_sha256
    observed_decision_sha256 observed_selection_sha256 observed_journal_sha256
    observed_recovery_marker_sha256 observed_pointer_sha256 classified_partial_state
    required_next_action
  ].freeze
  ACTIVE_RESULT_FIELDS = %w[
    artifact_type schema_version request_id_or_null operation status resolved_at
    current_pointer_sha256 current_pin_sha256 pointer_exists pointer_reference_or_null
    selection_reference_or_null decision_reference_or_null
    approval_subject_reference_or_null owner_attestation_reference_or_null
    reviewer_attestation_reference_or_null reservation_reference_or_null
    journal_reference_or_null recovery_marker_reference_or_null
    active_operation_or_null active_candidate_bundle_id_or_null
    active_contract_version_or_null prior_chain_sha256_or_null
    active_chain_sha256_or_null synthetic_only
  ].freeze

  FIXED_PATHS = [
    '/Library/PrivilegedHelperTools/id.ac.esaunggul.simrs-campus-ueu.g0-governance/versions',
    '/Library/PrivilegedHelperTools/id.ac.esaunggul.simrs-campus-ueu.g0-governance/bootstrap-pin-v2.json',
    '/Library/Application Support/SIMRSCampusUEU/G0Governance',
    '/Library/Application Support/SIMRSCampusUEU/G0Governance/pins',
    '/Library/Application Support/SIMRSCampusUEU/G0Governance/pin-record-directory-v2.json',
    '/Library/Application Support/SIMRSCampusUEU/G0Governance/current-pin-v2.json',
    '/Library/Application Support/SIMRSCampusUEU/G0Governance/deployment-checkout',
    '/Library/Application Support/SIMRSCampusUEU/G0Governance/requests/inbox',
    '/Library/Application Support/SIMRSCampusUEU/G0Governance/requests/import-receipts',
    '/Library/Application Support/SIMRSCampusUEU/G0Governance/replay',
    '/Library/Application Support/SIMRSCampusUEU/G0Governance/receipts',
    '/Library/Application Support/SIMRSCampusUEU/G0Governance/receipts/terminal',
    '/Library/Application Support/SIMRSCampusUEU/G0Governance/receipts/failure',
    '/Library/Application Support/SIMRSCampusUEU/G0Governance/receipts/reconciliation',
    '/Library/Application Support/SIMRSCampusUEU/G0Governance/trust-stores',
    '/Library/Application Support/SIMRSCampusUEU/G0Governance/policies',
    '/Library/Application Support/SIMRSCampusUEU/G0Governance/enrollments',
    '/Library/Application Support/SIMRSCampusUEU/G0Governance/rosters',
    '/usr/bin/ssh-keygen',
    '/usr/bin/git'
  ].freeze
  OPERATIONS = %w[self-test preflight resolve-active import-file finalize-request activate rollback disable recover].freeze
  IMPORT_FILENAMES = %w[
    operation-intent-v2.json approval-subject-v2.json
    owner-attestation-payload-v2.json reviewer-attestation-payload-v2.json
    owner-attestation.sshsig reviewer-attestation.sshsig owner-attestation-v2.json
    reviewer-attestation-v2.json operation-decision-v2.json request.json
  ].freeze
  IMPORT_CAPS = %w[16384 32768 16384 16384 8192 8192 16384 16384 32768 16384].freeze
  CLI_ARGVS = [
    '--operation self-test',
    '--operation preflight',
    '--operation resolve-active',
    '--operation import-file --request-id <64-lowercase-hex> --filename <closed-basename>',
    '--operation finalize-request --request-id <64-lowercase-hex>',
    '--operation activate --request-id <64-lowercase-hex>',
    '--operation rollback --request-id <64-lowercase-hex>',
    '--operation disable --request-id <64-lowercase-hex>',
    '--operation recover --request-id <64-lowercase-hex>'
  ].freeze
  DIRECTORY_ACL_ROWS = [
    ['0', 'deny', 'provisioned repository writer', 'add_file,add_subdirectory,chown,delete,delete_child,writeattr,writeextattr,writesecurity', 'directory_inherit,file_inherit'],
    ['1', 'deny', 'provisioned application runtime', 'add_file,add_subdirectory,chown,delete,delete_child,writeattr,writeextattr,writesecurity', 'directory_inherit,file_inherit'],
    ['2', 'allow', 'root', 'add_file,add_subdirectory,chown,delete,delete_child,list,readattr,readextattr,readsecurity,search,writeattr,writeextattr,writesecurity', 'directory_inherit,file_inherit']
  ].freeze
  FILE_ACL_ROWS = [
    ['0', 'deny', 'provisioned repository writer', 'append,chown,delete,write,writeattr,writeextattr,writesecurity', 'empty'],
    ['1', 'deny', 'provisioned application runtime', 'append,chown,delete,write,writeattr,writeextattr,writesecurity', 'empty'],
    ['2', 'allow', 'root', 'append,chown,delete,read,readattr,readextattr,readsecurity,write,writeattr,writeextattr,writesecurity', 'empty']
  ].freeze
  DAG_REFERENCE_EDGES = [
    ['operation_intent', 'approval_subject', '`operation_intent_reference`'],
    ['approval_subject', 'owner_attestation_payload', '`approval_subject_reference`'],
    ['approval_subject', 'reviewer_attestation_payload', '`approval_subject_reference`'],
    ['owner_attestation_payload', 'owner_signature', 'signature covers exact payload bytes'],
    ['reviewer_attestation_payload', 'reviewer_signature', 'signature covers exact payload bytes'],
    ['approval_subject', 'owner_signature_import_receipt', '`approval_subject_sha256`'],
    ['owner_attestation_payload', 'owner_signature_import_receipt', '`attestation_payload_sha256`'],
    ['owner_signature', 'owner_signature_import_receipt', '`signature_filename`, `signature_sha256`, `signature_size_bytes`, `signature_inode`'],
    ['approval_subject', 'reviewer_signature_import_receipt', '`approval_subject_sha256`'],
    ['reviewer_attestation_payload', 'reviewer_signature_import_receipt', '`attestation_payload_sha256`'],
    ['reviewer_signature', 'reviewer_signature_import_receipt', '`signature_filename`, `signature_sha256`, `signature_size_bytes`, `signature_inode`'],
    ['approval_subject', 'owner_attestation', '`approval_subject_reference`'],
    ['owner_attestation_payload', 'owner_attestation', '`attestation_payload_reference`'],
    ['owner_signature', 'owner_attestation', '`external_signature_reference`'],
    ['owner_signature_import_receipt', 'owner_attestation', '`signature_import_receipt_reference`'],
    ['owner_signature_import_receipt', 'owner_attestation', '`external_signature_reference.import_receipt_path`, `external_signature_reference.import_receipt_sha256`'],
    ['approval_subject', 'reviewer_attestation', '`approval_subject_reference`'],
    ['reviewer_attestation_payload', 'reviewer_attestation', '`attestation_payload_reference`'],
    ['reviewer_signature', 'reviewer_attestation', '`external_signature_reference`'],
    ['reviewer_signature_import_receipt', 'reviewer_attestation', '`signature_import_receipt_reference`'],
    ['reviewer_signature_import_receipt', 'reviewer_attestation', '`external_signature_reference.import_receipt_path`, `external_signature_reference.import_receipt_sha256`'],
    ['operation_intent', 'final_operation_decision', '`operation_intent_reference`'],
    ['approval_subject', 'final_operation_decision', '`approval_subject_reference`'],
    ['owner_attestation', 'final_operation_decision', '`owner_attestation_reference` and `approval_evidence_reference`'],
    ['reviewer_attestation', 'final_operation_decision', '`reviewer_attestation_reference` and `independent_review_reference`'],
    ['final_operation_decision', 'mutation_request', '`operation_decision_reference`'],
    ['operation_intent', 'request_bundle', '`operation_intent_reference`'],
    ['approval_subject', 'request_bundle', '`approval_subject_reference`'],
    ['owner_attestation_payload', 'request_bundle', '`owner_attestation_payload_reference`'],
    ['owner_signature', 'request_bundle', '`owner_signature_reference`'],
    ['owner_signature_import_receipt', 'request_bundle', '`owner_signature_import_receipt_reference`'],
    ['owner_attestation', 'request_bundle', '`owner_attestation_reference`'],
    ['reviewer_attestation_payload', 'request_bundle', '`reviewer_attestation_payload_reference`'],
    ['reviewer_signature', 'request_bundle', '`reviewer_signature_reference`'],
    ['reviewer_signature_import_receipt', 'request_bundle', '`reviewer_signature_import_receipt_reference`'],
    ['reviewer_attestation', 'request_bundle', '`reviewer_attestation_reference`'],
    ['final_operation_decision', 'request_bundle', '`operation_decision_reference`'],
    ['mutation_request', 'request_bundle', '`request_reference`'],
    ['operation_intent', 'request_bundle', '`file_inventory_sha256[operation-intent-v2.json]`'],
    ['approval_subject', 'request_bundle', '`file_inventory_sha256[approval-subject-v2.json]`'],
    ['owner_attestation_payload', 'request_bundle', '`file_inventory_sha256[owner-attestation-payload-v2.json]`'],
    ['reviewer_attestation_payload', 'request_bundle', '`file_inventory_sha256[reviewer-attestation-payload-v2.json]`'],
    ['owner_signature', 'request_bundle', '`file_inventory_sha256[owner-attestation.sshsig]`'],
    ['reviewer_signature', 'request_bundle', '`file_inventory_sha256[reviewer-attestation.sshsig]`'],
    ['owner_attestation', 'request_bundle', '`file_inventory_sha256[owner-attestation-v2.json]`'],
    ['reviewer_attestation', 'request_bundle', '`file_inventory_sha256[reviewer-attestation-v2.json]`'],
    ['final_operation_decision', 'request_bundle', '`file_inventory_sha256[operation-decision-v2.json]`'],
    ['mutation_request', 'request_bundle', '`file_inventory_sha256[request.json]`'],
    ['request_bundle', 'bundle_import_receipt', '`request_bundle_path`, `request_bundle_sha256`'],
    ['operation_intent', 'bundle_import_receipt', '`imported_file_references[operation-intent-v2.json]`'],
    ['approval_subject', 'bundle_import_receipt', '`imported_file_references[approval-subject-v2.json]`'],
    ['owner_attestation_payload', 'bundle_import_receipt', '`imported_file_references[owner-attestation-payload-v2.json]`'],
    ['reviewer_attestation_payload', 'bundle_import_receipt', '`imported_file_references[reviewer-attestation-payload-v2.json]`'],
    ['owner_signature', 'bundle_import_receipt', '`imported_file_references[owner-attestation.sshsig]`'],
    ['reviewer_signature', 'bundle_import_receipt', '`imported_file_references[reviewer-attestation.sshsig]`'],
    ['owner_attestation', 'bundle_import_receipt', '`imported_file_references[owner-attestation-v2.json]`'],
    ['reviewer_attestation', 'bundle_import_receipt', '`imported_file_references[reviewer-attestation-v2.json]`'],
    ['final_operation_decision', 'bundle_import_receipt', '`imported_file_references[operation-decision-v2.json]`'],
    ['mutation_request', 'bundle_import_receipt', '`imported_file_references[request.json]`'],
    ['request_bundle', 'request_seal', '`request_bundle_reference`'],
    ['bundle_import_receipt', 'request_seal', '`bundle_import_receipt_reference`'],
    ['operation_intent', 'replay_reservation', '`intent_id`, `intent_sha256`'],
    ['approval_subject', 'replay_reservation', '`subject_id`, `subject_sha256`'],
    ['owner_attestation', 'replay_reservation', '`owner_attestation_id`, `owner_attestation_sha256`'],
    ['reviewer_attestation', 'replay_reservation', '`reviewer_attestation_id`, `reviewer_attestation_sha256`'],
    ['final_operation_decision', 'replay_reservation', '`decision_id`, `decision_sha256`'],
    ['request_bundle', 'replay_reservation', '`request_bundle_sha256`'],
    ['bundle_import_receipt', 'replay_reservation', '`bundle_import_receipt_sha256`'],
    ['request_seal', 'replay_reservation', '`request_seal_sha256`']
  ].freeze
  EXTERNAL_ROOT_EDGES = [
    ['candidate_evidence', 'operation_intent', '`candidate_reference`'],
    ['prior_state_evidence', 'operation_intent', '`prior_state_reference`'],
    ['governance_contract', 'approval_subject', '`contract_reference`'],
    ['technical_preflight', 'approval_subject', '`technical_preflight_reference`'],
    ['authorship_roster', 'approval_subject', '`authorship_roster_reference`'],
    ['authorship_roster', 'approval_subject', '`excluded_identity_set_sha256`'],
    ['request_inbox_identity', 'owner_signature_import_receipt', '`inbox_directory_path`, `inbox_directory_device_id`, `inbox_directory_inode`'],
    ['request_inbox_identity', 'reviewer_signature_import_receipt', '`inbox_directory_path`, `inbox_directory_device_id`, `inbox_directory_inode`'],
    ['request_inbox_identity', 'owner_attestation', '`external_signature_reference.inbox_directory_path`, `external_signature_reference.inbox_directory_device_id`, `external_signature_reference.inbox_directory_inode`'],
    ['request_inbox_identity', 'reviewer_attestation', '`external_signature_reference.inbox_directory_path`, `external_signature_reference.inbox_directory_device_id`, `external_signature_reference.inbox_directory_inode`'],
    ['request_inbox_identity', 'request_bundle', '`inbox_identity_reference`'],
    ['request_inbox_identity', 'bundle_import_receipt', '`inbox_directory_path`, `inbox_directory_device_id`, `inbox_directory_inode`'],
    ['current_pointer', 'replay_reservation', '`current_pointer_sha256`'],
    ['immutable_pin_record', 'replay_reservation', '`pin_record_sha256`'],
    ['prior_canonical_pointer', 'replay_reservation', '`prior_pointer_sha256`'],
    ['replay_predecessor', 'replay_reservation', '`replay_predecessor_sha256`']
  ].freeze
  IMPORT_STATE_EDGES = [
    ['operation_intent', 'import_state', '`imported_file_references[operation-intent-v2.json]`'],
    ['approval_subject', 'import_state', '`imported_file_references[approval-subject-v2.json]`'],
    ['owner_attestation_payload', 'import_state', '`imported_file_references[owner-attestation-payload-v2.json]`'],
    ['reviewer_attestation_payload', 'import_state', '`imported_file_references[reviewer-attestation-payload-v2.json]`'],
    ['owner_signature', 'import_state', '`imported_file_references[owner-attestation.sshsig]`'],
    ['reviewer_signature', 'import_state', '`imported_file_references[reviewer-attestation.sshsig]`'],
    ['owner_attestation', 'import_state', '`imported_file_references[owner-attestation-v2.json]`'],
    ['reviewer_attestation', 'import_state', '`imported_file_references[reviewer-attestation-v2.json]`'],
    ['final_operation_decision', 'import_state', '`imported_file_references[operation-decision-v2.json]`'],
    ['mutation_request', 'import_state', '`imported_file_references[request.json]`'],
    ['owner_signature_import_receipt', 'import_state', '`signature_import_receipt_references[product_owner]`'],
    ['reviewer_signature_import_receipt', 'import_state', '`signature_import_receipt_references[independent_reviewer]`'],
    ['request_bundle', 'import_state', '`request_bundle_reference_or_null`'],
    ['bundle_import_receipt', 'import_state', '`bundle_import_receipt_reference_or_null`'],
    ['request_seal', 'import_state', '`request_seal_reference_or_null`']
  ].freeze
  NODE_FIELDS = {
    'operation_intent' => INTENT_FIELDS,
    'approval_subject' => SUBJECT_FIELDS,
    'owner_attestation_payload' => ATTESTATION_PAYLOAD_FIELDS,
    'reviewer_attestation_payload' => ATTESTATION_PAYLOAD_FIELDS,
    'owner_signature_import_receipt' => SIGNATURE_IMPORT_FIELDS,
    'reviewer_signature_import_receipt' => SIGNATURE_IMPORT_FIELDS,
    'owner_attestation' => ATTESTATION_FIELDS,
    'reviewer_attestation' => ATTESTATION_FIELDS,
    'final_operation_decision' => FINAL_DECISION_FIELDS,
    'mutation_request' => MUTATION_REQUEST_FIELDS,
    'request_bundle' => BUNDLE_FIELDS,
    'bundle_import_receipt' => BUNDLE_IMPORT_FIELDS,
    'request_seal' => REQUEST_SEAL_FIELDS,
    'replay_reservation' => RESERVATION_FIELDS,
    'import_state' => IMPORT_STATE_FIELDS
  }.freeze
  PARTIAL_BOUNDARIES = %w[
    replay_burn decision_publish selection_publish journal_publish marker_publish
    pointer_stage pointer_rename active_readback terminal_receipt
  ].freeze
  PARTIAL_CLASSIFICATIONS = %w[
    BURN_ONLY DECISION_ONLY SELECTION_PARTIAL JOURNAL_PARTIAL MARKER_PARTIAL
    STAGED_POINTER_PARTIAL RENAMED_UNREAD READBACK_NO_RECEIPT COMPLETE
  ].freeze

  def setup
    @adr = File.binread(ADR_PATH)
  end

  def test_is_proposed_no_effect_and_binds_exact_current_predecessors
    assert_includes @adr, 'Status: **PROPOSED / NOT APPROVED / NOT AUTHORITATIVE / NO EFFECT**'
    assert_equal ADR018_SHA, Digest::SHA256.file(ADR018_PATH).hexdigest
    assert_equal ADOPTION_SHA, Digest::SHA256.file(ADOPTION_PATH).hexdigest
    assert_equal PROPOSAL_SHA, Digest::SHA256.file(PROPOSAL_PATH).hexdigest
    assert_includes @adr, "ADR-018-PROPORTIONAL-G0-GOVERNANCE-PROFILE.md` SHA-256 `#{ADR018_SHA}`"
    assert_includes @adr, "G0_GOVERNANCE_V2_ADOPTION_DECISION.json` SHA-256 `#{ADOPTION_SHA}`"
    assert_includes @adr, "G0_GOVERNANCE_V2_EXTERNAL_TRUST_PROVISIONING_PROPOSAL_2026-08-29.md` SHA-256 `#{PROPOSAL_SHA}`"
    assert_includes @adr, 'accepts the governance-v2 proposal plus ADR-018 only for local governance-v2 implementation'
    assert_includes @adr, 'does not approve the later external-trust proposal, this ADR-019'
    assert_includes @adr, 'cannot mutate or authoritatively resolve the canonical deployment checkout'
    assert_includes @adr, 'change any current all-false Gate-B authorization'
    refute_match(/^\s*(?:[-*+]\s+)?\[[xX]\]/, @adr)
  end

  def test_root_only_offline_boundary_and_unauthorized_no_open_contract_are_exact
    assert_includes @adr, 'one offline native macOS executable invoked directly by root'
    assert_includes @adr, 'real UID and effective UID SHALL both equal `0`'
    assert_includes @adr, '`root:wheel` and `0700`'
    assert_includes @adr, 'There is no XPC service, socket, daemon, sudo environment handoff'
    assert_includes @adr, 'Before opening the state root, request inbox, deployment checkout'
    assert_includes @adr, '"status":"REJECTED_UNAUTHORIZED"'
    assert_includes @adr, '"current_pointer_sha256":null'
    assert_includes @adr, '"current_pin_sha256":null'
    assert_includes @adr, 'exits `77`'
    assert_includes @adr, 'test-linked credential provider that cannot be compiled into the release binary'
  end

  def test_fixed_layout_uses_dedicated_checkout_and_closed_identity
    assert_equal FIXED_PATHS, two_column_table('The only supported profile is `macos_local_v2`:', 'The dedicated checkout').map(&:last)
    assert_equal IDENTITY_FIELDS, fields_after('The provision-time host identity binding SHALL contain exactly:')
    assert_includes @adr, 'never the developer worktree'
    assert_includes @adr, 'does not alter, freeze, chmod, chown, or add ACLs to any developer checkout'
    assert_includes @adr, 'are not invented in a draft'
  end

  def test_ancestor_safe_openat_protocol_and_races_are_normative
    %w[O_DIRECTORY O_NOFOLLOW O_CLOEXEC openat fstat renameat FD_CLOEXEC].each do |term|
      assert_includes @adr, term
    end
    assert_includes @adr, 'reopen none by absolute path'
    assert_includes @adr, 'Before each security-sensitive syscall, and after every blocking syscall'
    assert_includes @adr, 'Symlink, hard-link, mount swap, ancestor rename, leaf replacement'
    assert_includes @adr, 'race every component and leaf boundary'
  end

  def test_acl_schemas_tuples_and_authority_order_are_closed
    assert_equal PARENT_FIELDS, fields_after('The phase-0 parent contract SHALL contain exactly:')
    assert_equal ACE_FIELDS, fields_after('Every `aces` item SHALL contain exactly:')
    assert_equal AUTHORITY_FIELDS, fields_after('Every authority entry SHALL contain exactly:')
    directory_rows = five_column_table('The exact protected-directory ACE table', 'The exact protected-regular-file ACE table')
    file_rows = five_column_table('The exact protected-regular-file ACE table', 'Protected directories are')
    assert_equal DIRECTORY_ACL_ROWS, directory_rows
    assert_equal FILE_ACL_ROWS, file_rows
    authority_rows = three_column_table('The exact UTF-8 byte-sorted authority inventory is:', '## 6.')
    assert_equal AUTHORITY_PATHS, authority_rows.map(&:first)
    assert_equal AUTHORITY_PATHS.sort, AUTHORITY_PATHS
    assert_includes @adr, 'acl_get_fd_np(fd, ACL_TYPE_EXTENDED)'
    assert_includes @adr, 'acl_set_fd_np(fd, ACL_TYPE_EXTENDED, exact_object_acl)'
    assert_includes @adr, 'before writing any file content'
    assert_includes @adr, 'native ACE index order'
  end

  def test_binary_bootstrap_pin_code_identity_and_library_policy_are_closed
    assert_equal BOOTSTRAP_FIELDS, fields_after('The bootstrap pin v2 SHALL contain exactly:')
    assert_equal PIN_DIRECTORY_FIELDS, fields_after('The immutable pin-record directory identity SHALL contain exactly:')
    assert_equal BINARY_FIELDS, fields_after('The immutable binary reference SHALL contain exactly:')
    assert_equal CODE_IDENTITY_FIELDS, fields_after('The code identity SHALL contain exactly:')
    assert_equal PIN_FIELDS, fields_after('The immutable pin record v2 SHALL contain exactly:')
    assert_equal CURRENT_POINTER_FIELDS, fields_after('The mutable current-pointer v2 SHALL contain exactly:')
    assert_includes @adr, 'versions/<binary_sha256>/g0-host'
    assert_includes @adr, 'SecCodeCopySelf'
    assert_includes @adr, 'SecCodeCheckValidity'
    assert_includes @adr, 'kSecCSStrictValidate|kSecCSCheckAllArchitectures|kSecCSCheckNestedCode'
    assert_includes @adr, 'Pinned Apple frameworks and libraries under `/System/Library` and `/usr/lib` are permitted'
    assert_includes @adr, 'caller-selected, repository-selected, injected, relative, writable, non-Apple, or unpinned runtime code is rejected'
    assert_includes @adr, '`cdhashes_by_architecture` has exactly the same keys and each value is 40 lower-case hexadecimal'
    assert_includes @adr, '`_dyld_image_count`/`_dyld_get_image_name`'
    assert_includes @adr, 'SecStaticCodeCreateWithPath'
    graph = dag_table('The exact bootstrap graph is:', 'There is no edge')
    assert_equal [%w[bootstrap_pin immutable_generation_1_pin_record], %w[immutable_pin_record mutable_current_pointer]], graph.map { |row| row[0, 2] }
    assert_includes @adr, 'The bootstrap does not contain the generation-1 pin path or hash'
    assert_includes @adr, 'There is no mutual hash.'
  end

  def test_git_tool_and_checkout_constraints_are_exact
    assert_equal GIT_FIELDS, fields_after('The Git tool reference SHALL contain exactly:')
    %w[1048576 65536 5000].each { |cap| assert_includes @adr, "`#{cap}`" }
    %w[HOME=/var/empty LANG=C LC_ALL=C PATH=/usr/bin:/bin GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_COUNT=0 GIT_NO_REPLACE_OBJECTS=1].each do |binding|
      assert_includes @adr, "`#{binding}`"
    end
    assert_includes @adr, 'rejects `.git/shallow`, `.git/info/grafts`, any `refs/replace/*`, alternates, promisor/partial clone state'
    assert_includes @adr, 'without a shell, `-c`, aliases, hooks, pager, network transport, or caller arguments'
    assert_equal SSH_FIELDS, fields_after('The SSH tool reference SHALL contain exactly:')
    assert_includes @adr, 'only `/dev/fd/<host-selected-number>` paths for one already-open signature and one pinned allowed-signers descriptor'
    assert_includes @adr, 'never accepted from CLI/JSON/environment'
  end

  def test_replay_and_roster_contracts_are_closed
    assert_equal REPLAY_IDENTITY_FIELDS, fields_after('The immutable replay identity SHALL contain exactly:')
    assert_equal REPLAY_POLICY_FIELDS, fields_after('The renewable replay policy SHALL contain exactly:')
    assert_equal ROSTER_FIELDS, fields_after('The external authorship roster v2 SHALL contain exactly:')
    assert_equal COVERED_FIELDS, fields_after('Every `covered_artifacts` item SHALL contain exactly:')
    assert_equal PROVENANCE_FIELDS, fields_after('Every `provenance_records` item SHALL contain exactly:')
    assert_includes @adr, 'It has no expiry.'
    assert_includes @adr, 'Renewal cannot reset, fork, truncate, relocate, or replace replay history.'
    assert_includes @adr, 'repository-authored identity arrays are ignored'
  end

  def test_external_bundle_and_import_receipts_are_closed_and_acyclic
    assert_equal FILE_REFERENCE_FIELDS, fields_after('An immutable file reference SHALL contain exactly:')
    assert_equal BUNDLE_FIELDS, fields_after('The request bundle v2 SHALL contain exactly:')
    assert_equal SIGNATURE_IMPORT_FIELDS, fields_after('A signature import receipt v2 SHALL contain exactly:')
    assert_equal BUNDLE_IMPORT_FIELDS, fields_after('The request-bundle import receipt v2 SHALL contain exactly:')
    assert_equal IMPORT_STATE_FIELDS, fields_after('The request import-state v2 SHALL contain exactly:')
    assert_equal REQUEST_SEAL_FIELDS, fields_after('The immutable request seal v2 SHALL contain exactly:')
    %w[
      request.json operation-intent-v2.json approval-subject-v2.json operation-decision-v2.json
      owner-attestation-payload-v2.json reviewer-attestation-payload-v2.json
      owner-attestation-v2.json owner-attestation.sshsig
      reviewer-attestation-v2.json reviewer-attestation.sshsig request-bundle-v2.json
    ].each { |name| assert_includes @adr, "`#{name}`" }
    assert_includes @adr, 'The generated bundle does not hash itself or reference its later bundle receipt/seal.'
    assert_includes @adr, 'signed payload, signature, signature receipt, attestation, final decision, bundle, then bundle receipt'
    assert_includes @adr, '`request_id` and `bundle_id` are independent 32-byte CSPRNG values'
    assert_includes @adr, 'UTF-8 byte-sorted ten-line imported inventory'
    assert_includes @adr, 'Only then may the exact byte-identical `operation-decision-v2.json` be published with `O_EXCL`'
    assert_includes @adr, 'A preauthorization rejection writes neither canonical state nor replay/receipt state.'
  end

  def test_subject_decision_attestation_v2_and_signature_binding_are_closed
    assert_equal INTENT_FIELDS, fields_after('The operation intent v2 SHALL contain exactly:')
    assert_equal SUBJECT_FIELDS, fields_after('The approval subject v2 SHALL contain exactly:')
    assert_equal ATTESTATION_PAYLOAD_FIELDS, fields_after('The attestation signed payload v2 SHALL contain exactly:')
    assert_equal DECISION_REFERENCE_FIELDS, fields_after('An operation decision reference SHALL contain exactly:')
    assert_equal ATTESTATION_FIELDS, fields_after('The attestation v2 SHALL contain exactly:')
    assert_equal SIGNATURE_REFERENCE_FIELDS, fields_after('An external signature reference SHALL contain exactly:')
    assert_equal FINAL_DECISION_FIELDS, fields_after('The final operation decision v2 SHALL contain exactly:')
    assert_includes @adr, 'The predecessor v1 attestation and its `signature_path` field are canonically rejected'
    assert_includes @adr, 'exact canonical UTF-8-plus-one-LF bytes'
    assert_includes @adr, '`approval_evidence_reference` is byte-equal to `owner_attestation_reference`'
    assert_includes @adr, "supersedes governance contract 1.3's repository-authored `approval_evidence` construction"
    assert_includes @adr, 'The final decision adds no unsigned discretion'
    assert_includes @adr, '`conditions` is byte-equal to the signed intent conditions'
    assert_includes @adr, '`decision_effect` is exactly `authorize_one_gate_b_consumer_operation_after_all_preconditions`'
    assert_includes @adr, 'Substituting either signature, receipt, signer, subject, or sibling request fails closed.'
  end

  def test_sshsig_caps_and_sha256_negative_are_exact
    %w[16384 8192 4096 128 64 0 16].each { |cap| assert_includes @adr, "`#{cap}`" }
    assert_includes @adr, 'exact version-1 field count and no trailing byte'
    assert_includes @adr, 'cryptographically valid SHA-256 SSHSIG is rejected before OpenSSH is invoked'
    assert_includes @adr, 'All length additions use checked arithmetic'
  end

  def test_approval_dag_is_exact_acyclic_topological_and_hash_constructible
    edges = dag_table('The exact DAG edges are:', 'The exact external-root dependency table')
    roots = dag_table('The exact external-root dependency table', 'The exact non-authoritative import-state dependency table')
    import_edges = dag_table('The exact non-authoritative import-state dependency table', 'Construction follows a deterministic topological order')
    assert_equal DAG_REFERENCE_EDGES, edges
    assert_equal EXTERNAL_ROOT_EDGES, roots
    assert_equal IMPORT_STATE_EDGES, import_edges
    assert_equal edges.length, edges.uniq.length
    assert_equal roots.length, roots.uniq.length
    assert_equal import_edges.length, import_edges.uniq.length
    all_declared_edges = edges + roots + import_edges
    NODE_FIELDS.each do |node, fields|
      required = fields.select { |field| field.include?('reference') || field.end_with?('_sha256') }.sort
      mapped = all_declared_edges.select { |_from, to, _binding| to == node }
                                 .flat_map { |_from, _to, binding| binding_fields(binding) }
                                 .select { |field| required.include?(field) }.uniq.sort
      assert_equal required, mapped, "every closed reference/hash field must map exactly for #{node}"
    end

    nodes = edges.flat_map { |edge| edge[0, 2] }.uniq
    incoming = nodes.each_with_object({}) { |node, memo| memo[node] = 0 }
    outgoing = nodes.each_with_object({}) { |node, memo| memo[node] = [] }
    edges.each do |from, to, _binding|
      outgoing.fetch(from) << to
      incoming[to] += 1
    end

    ready = incoming.select { |_node, count| count.zero? }.keys.sort
    order = []
    until ready.empty?
      node = ready.shift
      order << node
      outgoing.fetch(node).sort.each do |child|
        incoming[child] -= 1
        ready << child if incoming[child].zero?
      end
      ready.sort!
    end

    assert_equal nodes.length, order.length, 'DAG must have a complete topological order'
    assert_equal 'operation_intent', order.first
    assert_equal 'replay_reservation', order.last

    external = EXTERNAL_ROOT_EDGES.map(&:first).uniq.each_with_object({}) do |root, memo|
      bytes = canonical_json_lf('artifact_type' => 'external-root-fixture', 'root_id' => root)
      memo[root] = { 'bytes' => bytes, 'sha256' => Digest::SHA256.hexdigest(bytes) }
    end
    objects = {}
    hashes = external.transform_values { |entry| entry.fetch('sha256') }
    fixture_bytes = external.transform_values { |entry| entry.fetch('bytes') }
    order.each do |node|
      incoming_edges = edges.select { |_from, to, _binding| to == node }
      incoming_roots = roots.select { |_from, to, _binding| to == node }
      if node.end_with?('_signature')
        payload = incoming_edges.fetch(0).first
        bytes = "SSHSIG-FIXTURE-V2\0#{hashes.fetch(payload)}\n".b
      else
        object = fixture_object(node)
        (incoming_edges + incoming_roots).each do |from, _to, binding|
          apply_fixture_binding!(object, node, from, binding, hashes, objects, fixture_bytes)
        end
        finalize_collective_bindings!(object, node, incoming_edges, hashes, fixture_bytes)
        assert_equal NODE_FIELDS.fetch(node).sort, object.keys.sort, "closed fields for #{node}"
        objects[node] = object
        bytes = canonical_json_lf(object)
      end
      fixture_bytes[node] = bytes
      hashes[node] = Digest::SHA256.hexdigest(bytes)
    end
    assert_equal nodes.length + external.length, hashes.values.uniq.length

    import_state = fixture_object('import_state')
    import_edges.each do |from, _to, binding|
      apply_fixture_binding!(import_state, 'import_state', from, binding, hashes, objects, fixture_bytes)
    end
    assert_equal IMPORT_STATE_FIELDS.sort, import_state.keys.sort
    objects['import_state'] = import_state
    fixture_bytes['import_state'] = canonical_json_lf(import_state)
    hashes['import_state'] = Digest::SHA256.hexdigest(fixture_bytes.fetch('import_state'))
    objects.each { |node, object| assert_fixture_references_closed(node, object) }

    (edges + roots + import_edges).each do |from, to, binding|
      target_bytes = fixture_bytes.fetch(to)
      assert binding_observable?(target_bytes, from, binding, hashes.fetch(from)), "missing #{from} -> #{to} #{binding}"
      mutated = mutate_binding(target_bytes, binding)
      refute_equal Digest::SHA256.hexdigest(target_bytes), Digest::SHA256.hexdigest(mutated), "mutation must affect #{from} -> #{to} #{binding}"
    end

    reservation = objects.fetch('replay_reservation')
    assert_equal hashes.fetch('operation_intent'), reservation.fetch('intent_sha256')
    assert_equal hashes.fetch('approval_subject'), reservation.fetch('subject_sha256')
    assert_equal hashes.fetch('owner_attestation'), reservation.fetch('owner_attestation_sha256')
    assert_equal hashes.fetch('reviewer_attestation'), reservation.fetch('reviewer_attestation_sha256')
    assert_equal hashes.fetch('final_operation_decision'), reservation.fetch('decision_sha256')
    assert_equal hashes.fetch('request_bundle'), reservation.fetch('request_bundle_sha256')
    assert_equal hashes.fetch('bundle_import_receipt'), reservation.fetch('bundle_import_receipt_sha256')
    assert_equal hashes.fetch('request_seal'), reservation.fetch('request_seal_sha256')
    assert_equal hashes.fetch('current_pointer'), reservation.fetch('current_pointer_sha256')
    assert_equal hashes.fetch('immutable_pin_record'), reservation.fetch('pin_record_sha256')
    assert_equal hashes.fetch('prior_canonical_pointer'), reservation.fetch('prior_pointer_sha256')
    assert_equal hashes.fetch('replay_predecessor'), reservation.fetch('replay_predecessor_sha256')
    refute_includes SUBJECT_FIELDS, 'operation_decision_reference'
    assert_includes FINAL_DECISION_FIELDS, 'approval_subject_reference'
  end

  def test_canonical_json_lf_contract_is_exact_and_fixture_enforces_safe_numbers
    assert_includes @adr, '`canonical_json_lf` is exactly the RFC 8785 JSON Canonicalization Scheme (JCS) byte sequence followed by exactly one LF byte (`0x0a`)'
    assert_includes @adr, 'duplicate object keys, invalid UTF-8, lone UTF-16 surrogates, a BOM, trailing bytes, every non-integer number'
    assert_includes @adr, '`-9007199254740991` through `9007199254740991`'
    assert_includes @adr, 'arrays preserve source order unless their closed schema explicitly requires UTF-8 byte sorting'
    assert_includes @adr, 'No Unicode pre-normalization is performed'
    assert_equal "{\"a\":1,\"b\":2}\n", canonical_json_lf('b' => 2, 'a' => 1)
    assert_equal "[2,1]\n", canonical_json_lf([2, 1])
    assert_raises(ArgumentError) { canonical_json_lf(1.0) }
    assert_raises(ArgumentError) { canonical_json_lf(9_007_199_254_740_992) }
    assert_raises(ArgumentError) { canonical_json_lf(-9_007_199_254_740_992) }
  end

  def test_cli_table_reachable_self_test_preflight_and_mutations_is_exact
    rows = four_column_table('The release executable accepts only these exact argument forms', 'No other flag')
    assert_equal OPERATIONS, rows.map(&:first)
    assert_equal CLI_ARGVS, rows.map { |row| row[1] }
    assert_equal MUTATION_REQUEST_FIELDS, fields_after('A governance mutation request v2 SHALL contain exactly:')
    imports = four_column_table('The exact import order, content kind, and inclusive byte caps are:', '`import-file` first reads')
    assert_equal (1..10).map(&:to_s), imports.map(&:first)
    assert_equal IMPORT_FILENAMES, imports.map { |row| row[1] }
    assert_equal IMPORT_CAPS, imports.map { |row| row[3] }
    assert_includes @adr, 'openat(O_CREAT|O_EXCL|O_NOFOLLOW|O_CLOEXEC)'
    assert_includes @adr, 'bundle import receipt, and request seal'
    assert_includes @adr, 'request ID is never reusable'
    assert_includes @adr, 'never accepts an arbitrary source/destination path'
    assert_includes @adr, 'compiled fixed public vectors'
    assert_includes @adr, 'provisioning-only `preflight` may create an ephemeral signing key'
    assert_includes @adr, 'release canonical mutation never generates or reads private keys'
    assert_includes @adr, 'only the next `import-file` or `finalize-request` invocation for the same `request_id` mark it ABORTED'
    assert_includes @adr, '`self-test`, `preflight`, and `resolve-active` never inspect, repair, import, abort, or write request state'
  end

  def test_operation_equality_and_pairwise_substitutions_are_required
    equality = 'CLI argv, request, request bundle, bundle/signature import receipts, operation intent, approval subject, both attestation payloads, both attestations, final operation decision, operation-decision reference, request seal, replay reservation, and every receipt common block SHALL be byte-equal'
    assert_includes @adr, equality
    assert_includes @adr, 'one of `activate`, `rollback`, `disable`, or `recover`'
    assert_includes @adr, 'pairwise substitution for every pair of operation-bearing artifacts'
    assert_includes @adr, 'Every substitution fails before replay burn.'
  end

  def test_reservation_receipt_store_and_receipt_schemas_are_closed
    assert_equal RESERVATION_FIELDS, fields_after('The replay reservation v2 SHALL contain exactly:')
    assert_equal RECEIPT_STORE_FIELDS, fields_after('The protected receipt-store identity SHALL contain exactly:')
    assert_equal COMMON_RECEIPT_FIELDS, fields_after('A common receipt block SHALL contain exactly:')
    assert_equal DIAGNOSTIC_FIELDS, fields_after('A diagnostic receipt v2 SHALL contain exactly:')
    assert_equal TERMINAL_FIELDS, fields_after('A terminal receipt v2 SHALL contain exactly:')
    assert_equal FAILURE_FIELDS, fields_after('A failure receipt v2 SHALL contain exactly:')
    assert_equal RECONCILIATION_FIELDS, fields_after('A reconciliation receipt v2 SHALL contain exactly:')
    assert_includes @adr, 'receipt_id = sha256("g0-receipt-v2\\0" || reservation_sha256 || "\\0" || receipt_kind)'
    assert_includes @adr, 'Creation is `O_CREAT|O_EXCL`, then file fsync, descriptor readback/hash comparison, and directory fsync.'
  end

  def test_status_phase_reason_and_preauthorization_no_write_are_closed
    statuses = tokens_between('Receipt statuses are exactly ', '. Phases are exactly')
    phases = tokens_between('Phases are exactly ', ".\n\nReason codes are exactly")
    reasons = tokens_between('Reason codes are exactly ', '. Unknown status')
    assert_equal %w[PASS_NO_WRITE PASS_NONAUTHORITATIVE_IMPORT PASS_NONAUTHORITATIVE_FINALIZE ABORTED_NONAUTHORITATIVE_IMPORT PASS FAIL_NO_WRITE BURNED_NO_AUTHORITY_CHANGE FAIL_CLOSED_ACTIVE_UNRESOLVED RECONCILED_BURNED_NO_REPLAY], statuses
    assert_equal 24, phases.length
    assert_equal phases.length, phases.uniq.length
    assert_equal 41, reasons.length
    assert_equal reasons.length, reasons.uniq.length
    assert_includes @adr, 'every failure before replay burn is a stdout-only diagnostic receipt with `FAIL_NO_WRITE`'
    assert_includes @adr, 'writes no replay reservation, canonical decision, selection, journal, marker, pointer, log, or cache'
    assert_includes @adr, '`resolve-active` emits only the active-chain result in Section 17.'
  end

  def test_reconciliation_order_and_every_partial_boundary_are_exact
    rows = four_column_table('The exact observable partial-state table is:', 'Any impossible combination')
    assert_equal PARTIAL_BOUNDARIES, rows.map(&:first)
    assert_equal PARTIAL_CLASSIFICATIONS, rows.map { |row| row[2] }
    assert rows[0...-1].all? { |row| row[3].include?('receipt') }
    assert_includes rows.last[3], 'reject replay'
    assert_includes @adr, 'Required action; never resume'
    assert_includes @adr, 'Reconciliation occurs under the stable lock before the current request is rejected as replay.'
    assert_includes @adr, 'Two reconcilers contend for the stable lock'
    assert_includes @adr, '`O_EXCL` permits exactly one winner'
    assert_includes @adr, 'authority rename components themselves must share the deployment-checkout device'
  end

  def test_active_result_is_closed_and_not_runtime_authorization
    assert_equal ACTIVE_RESULT_FIELDS, fields_after('The active-chain result v2 SHALL contain exactly:')
    assert_includes @adr, '`resolve-active` is a root-only governance/ledger operator command.'
    assert_includes @adr, 'it is never an application-runtime authorization API'
    assert_includes @adr, 'not a capability grant, session token, application policy, or clinical/runtime authorization'
    assert_includes @adr, '`operation` is `resolve-active`, `synthetic_only` is true'
    assert_includes @adr, 'Every non-null `*_reference_or_null` is exactly `path`, `sha256`, `device_id`, and `inode`.'
    assert_includes @adr, '`current_pointer_sha256` hashes the exact mutable pointer bytes'
    assert_includes @adr, '`current_pin_sha256` hashes the immutable pin record'
  end

  def test_prose_lock_requires_later_native_macos_acceptance
    assert_includes @adr, 'This Markdown test is a prose/contract lock only.'
    assert_includes @adr, 'cannot establish native syscall behavior, macOS ACL enforcement, code signing, root separation, fsync durability, or host provisioning'
    assert_includes @adr, 'A separately reviewed machine contract, native implementation, and provisioned-macOS acceptance suite are mandatory'
    assert_includes @adr, 'direct repository-writer/application-runtime/selector writes denied'
    assert_includes @adr, 'empirical `acl_set_fd_np` then `acl_get_fd_np` round trips'
    assert_includes @adr, 'newly created child inheritance replacement before first content write'
    assert_includes @adr, 'two-process reconciliation'
  end

  def test_all_false_synthetic_no_live_no_deploy_and_secret_boundaries_hold
    assert_includes @adr, 'All data and operations remain synthetic.'
    assert_includes @adr, 'live BPJS, VClaim, SATUSEHAT, payment, LIS, PACS, or device integration'
    assert_includes @adr, 'application deployment; hosted migration; a capability disposition; domain acceptance; G0 closure; or G3 acceptance'
    assert_includes @adr, 'Klaim, BPJS, and Apotek remain Soon'
    assert_includes @adr, 'failed Antrean/work-queue MVP remains excluded under DEC-013'
    refute_match(/-----BEGIN (?:OPENSSH |RSA |EC )?PRIVATE KEY-----/, @adr)
    refute_match(/\b(?:password|token|secret|private_key)\s*[:=]\s*["'][^"']+/i, @adr)
  end

  def test_exact_approval_block_is_single_and_cannot_activate
    marker = '> Approve ADR-019 exactly as written as the append-only Gate-B root-only offline host-trust-boundary successor'
    assert_equal 1, @adr.scan(marker).length
    block = @adr.lines.find { |line| line.start_with?(marker) }
    assert_includes block, 'publication in the limited sense of local working-tree artifact creation'
    assert_includes block, 'after a separate independent technical/security review of this exact ADR passes'
    assert_includes block, 'Commit, push, pull request creation, release, deployment, and migration remain unauthorized.'
    assert_includes block, 'Keep canonical mutation and resolution, request import, host provisioning, human key enrollment, consumer activation'
    assert_includes block, 'This approval has no activation effect'
    assert_includes block, 'current all-false Gate-B authorization state'
    assert_includes @adr, 'that pending draft cannot embed its own SHA-256, so only the later successor approval artifact supplies this binding'
    assert_includes @adr, 'identity `Daniel Happy Putra`, capacity `product_owner`'
    assert_includes @adr, 'decision-reference prefix `codex_thread:01a02b58-641d-7090-aad4-00871c7ddf47#decision-message-sha256:`'
    assert_includes @adr, 'host/platform task record outside repository-writer control'
    assert_includes @adr, 'exact whole-message bytes and their SHA-256'
    assert_includes @adr, '`request_presented_at < source_message_at <= approval_verified_at <= recorded_at < effective_at`'
    assert_includes @adr, "`recorded_at` is the host recorder's observation time"
    assert_includes @adr, "`effective_at` is the host recorder's first effect time"
    assert_includes @adr, 'null field, or unverifiable external record fails closed'
    assert_includes @adr, 'no-commit/no-push/no-PR/no-release/no-deploy/no-migrate boundary'
    assert_includes @adr, 'Do not edit this ADR or the pending draft to mark either accepted; create a new local working-tree successor decision artifact'
    assert_equal %w[
      artifact_type schema_version artifact_id status effect data_boundary
      draft_reference source_bindings independent_review_reference actor
      decision_reference decision_message decision_message_encoding
      decision_message_sha256 external_task_record_reference request_presented_at
      source_message_at recorded_at effective_at recorded_at_basis effective_at_basis
      conditions approved_scope authorization immutability secret_handling
    ], fields_after('The future immutable successor approval SHALL contain exactly:')
    assert_equal %w[
      artifact_type schema_version provider_id task_id message_id event_id account_id
      record_sha256 resolver_id
    ], fields_after('The hash-addressed external task-record reference SHALL contain exactly:')
    assert_equal %w[verification_status resolver_id external_verification_identity_id resolved_envelope], fields_after("The resolver API's verified result SHALL contain exactly:")
    assert_equal %w[artifact_type schema_version record record_sha256 verified_result_metadata], fields_after("The trusted resolver's resolved envelope SHALL contain exactly:")
    assert_equal %w[
      provider_id task_id message_id event_id account_id author_role author_account_id
      author_identity_id author_display_identity request_draft_path
      request_draft_sha256 requested_reply_sha256 request_presented_at
      whole_message_bytes whole_message_sha256 source_message_at
    ], fields_after('The resolved `record` SHALL contain exactly:')
    assert_equal %w[
      resolver_id resolver_kind verification_method external_verification_identity_id
      verification_evidence_sha256 verified_reference_sha256 verified_record_sha256
      account_binding_status bound_author_account_id bound_author_identity_id
      bound_gate_a_identity bound_gate_a_capacity verified_at
    ], fields_after('Approval `verified_result_metadata` SHALL contain exactly:')
    assert_equal %w[artifact_type schema_version review_id record_sha256 resolver_id], fields_after('The independent-review reference SHALL contain exactly:')
    assert_equal %w[
      review_id reviewer_identity_id reviewer_display_identity reviewer_capacity
      reviewer_kind reviewer_author_role reviewer_account_id executor_identity_id
      reviewed_adr_sha256 reviewed_draft_sha256 review_bytes review_sha256 verdict reviewed_at
    ], fields_after('Its resolved review `record` SHALL contain exactly:')
    assert_equal %w[
      resolver_id resolver_kind verification_method external_verification_identity_id
      verification_evidence_sha256 verified_reference_sha256 verified_record_sha256
      verified_reviewer_identity_id verified_reviewer_account_id verified_reviewer_capacity
      verified_reviewer_kind reviewer_eligibility_status verified_executor_identity_id verified_at
    ], fields_after('Review `verified_result_metadata` SHALL contain exactly:')
    assert_includes @adr, '`provider_id: codex`, `task_id: 01a02b58-641d-7090-aad4-00871c7ddf47`'
    assert_includes @adr, '`author_role: user`'
    assert_includes @adr, '`codex-platform-trust-anchor-v1`'
    assert_includes @adr, '`independent-review-platform-trust-anchor-v1`'
    assert_includes @adr, '`reviewer_eligibility_status: VERIFIED_ELIGIBLE_INDEPENDENT_REVIEWER`'
    assert_includes @adr, 'The verified reviewer identity, account, capacity, kind, and executor identity must byte-equal their review-record counterparts.'
    assert_includes @adr, 'reviewer identity must differ from `verified_executor_identity_id`'
    assert_includes @adr, 'a review record cannot override the actual resolver-verified executor'
    assert_includes @adr, 'locally fabricated, byte-perfect but unresolved'
    assert_includes @adr, 'Unknown, duplicate, or missing fields at the successor top level or any nested'
  end

  def test_residual_blocked_patterns_are_absent_or_rejection_only
    refute_includes @adr, 'initial_current_pin_sha256'
    refute_includes @adr, 'initial current-pin SHA'
    refute_includes @adr, '`--operation resolve-active --request-id'
    refute_includes @adr, '`operation-decision.json`'
    refute_includes SUBJECT_FIELDS, 'operation_decision_reference'
    assert_equal 1, @adr.scan('`signature_path` field').length
    assert_includes @adr, 'canonically rejected'
    refute_includes @adr, 'both predecessor SHA-256 values'
  end

  def test_bytes_are_ascii_lf_and_stable
    assert @adr.valid_encoding?
    assert_match(/\A[\x00-\x7F]+\z/, @adr)
    refute_includes @adr, "\r"
    assert @adr.end_with?("\n")
  end

  private

  def fixture_object(node)
    NODE_FIELDS.fetch(node).each_with_object({}) do |field, object|
      object[field] = fixture_default(node, field)
    end
  end

  def fixture_default(node, field)
    return 2 if field == 'schema_version'
    return 'activate' if field == 'operation'
    return role_for(node) if field == 'role'
    return true if field == 'sealed'
    return [] if %w[conditions imported_file_references].include?(field)
    return {} if field == 'signature_import_receipt_references'
    return nil if field.end_with?('_or_null')
    return 0 if field.end_with?('_uid') || field.end_with?('_inode') || field == 'signature_size_bytes'
    return '2026-08-29T00:00:00Z' if field.end_with?('_at')
    return 'request-fixture' if field == 'request_id'
    return 'bundle-fixture' if field == 'bundle_id'
    return "#{node}-#{field}" if field.end_with?('_id')
    return [] if field == 'conditions'
    return {} if field.end_with?('_reference')
    return '0' * 64 if field.end_with?('_sha256')

    "fixture-#{node}-#{field}"
  end

  def apply_fixture_binding!(object, target, source, binding, hashes, objects, bytes)
    source_sha = hashes.fetch(source)
    if binding == 'signature covers exact payload bytes'
      raise "signature binding only valid on signature bytes: #{target}"
    end

    if binding.include?('file_inventory_sha256[')
      return
    elsif binding.include?('imported_file_references[')
      filename = binding[/\[([^\]]+)\]/, 1]
      object['imported_file_references'] << immutable_file_reference(source, filename, source_sha, bytes.fetch(source))
      object['imported_file_references'].sort_by! { |entry| entry.fetch('filename').bytes }
      return
    elsif binding.include?('signature_import_receipt_references[')
      role = binding[/\[([^\]]+)\]/, 1]
      object['signature_import_receipt_references'][role] = simple_reference(source, source_sha)
      return
    end

    fields = binding.scan(/`([^`]+)`/).flatten
    fields.each do |field|
      if field.include?('.')
        parent, leaf = field.split('.', 2)
        object[parent] ||= external_signature_reference(source, source_sha, bytes.fetch(source))
        object.fetch(parent)[leaf] = scalar_binding_value(leaf, source, source_sha, objects, bytes)
      elsif field == 'external_signature_reference'
        object[field] = external_signature_reference(source, source_sha, bytes.fetch(source))
      elsif field == 'operation_decision_reference'
        object[field] = decision_reference(source, source_sha, objects)
      elsif field.end_with?('_reference') || field.end_with?('_reference_or_null')
        object[field] = if target == 'request_bundle' && !source.include?('receipt') && source != 'request_inbox_identity'
                          immutable_file_reference(source, filename_for(source), source_sha, bytes.fetch(source))
                        else
                          simple_reference(source, source_sha)
                        end
      else
        object[field] = scalar_binding_value(field, source, source_sha, objects, bytes)
      end
    end
  end

  def finalize_collective_bindings!(object, node, incoming_edges, hashes, bytes)
    return unless node == 'request_bundle'

    inventory = incoming_edges.each_with_object([]) do |(source, _target, binding), rows|
      next unless binding.include?('file_inventory_sha256[')

      filename = binding[/\[([^\]]+)\]/, 1]
      rows << [filename, hashes.fetch(source), bytes.fetch(source).bytesize]
    end.sort_by { |filename, _sha, _size| filename.bytes }
    lines = inventory.map { |filename, sha, size| "#{filename}\0#{sha}\0#{size}\n" }.join
    object['file_inventory_sha256'] = Digest::SHA256.hexdigest(lines)
  end

  def scalar_binding_value(field, source, source_sha, objects, bytes)
    return source_sha if field.end_with?('_sha256')
    return filename_for(source) if field.end_with?('_filename')
    return bytes.fetch(source).bytesize if field.end_with?('_size_bytes')
    return 1000 + source.bytes.sum if field.end_with?('_inode')
    return "/fixture/#{source}" if field.end_with?('_path')
    return 1 if field.end_with?('_device_id')
    return objects.fetch(source).fetch(source_id_field(source)) if %w[intent_id subject_id owner_attestation_id reviewer_attestation_id decision_id].include?(field)

    "fixture-#{source}-#{field}"
  end

  def simple_reference(source, sha)
    {
      'path' => "/fixture/#{source}",
      'sha256' => sha,
      'device_id' => 1,
      'inode' => 1000 + source.bytes.sum
    }
  end

  def immutable_file_reference(source, filename, sha, bytes)
    {
      'logical_role' => source,
      'filename' => filename,
      'sha256' => sha,
      'size_bytes' => bytes.bytesize,
      'device_id' => 1,
      'inode' => 1000 + source.bytes.sum,
      'link_count' => 1,
      'owner_uid' => 0,
      'group_gid' => 0,
      'mode' => 600
    }
  end

  def external_signature_reference(source, sha, bytes)
    {
      'filename' => filename_for(source),
      'sha256' => sha,
      'size_bytes' => bytes.bytesize,
      'inbox_directory_path' => '/fixture/request_inbox_identity',
      'inbox_directory_device_id' => 1,
      'inbox_directory_inode' => 2000,
      'file_inode' => 1000 + source.bytes.sum,
      'link_count' => 1,
      'owner_uid' => 0,
      'group_gid' => 0,
      'mode' => 600,
      'import_receipt_path' => '/fixture/pending-receipt',
      'import_receipt_sha256' => '0' * 64
    }
  end

  def decision_reference(source, sha, objects)
    decision = objects.fetch(source)
    {
      'artifact_type' => 'g0_operation_decision_reference_v2',
      'schema_version' => 2,
      'operation' => decision.fetch('operation'),
      'decision_id' => decision.fetch('decision_id'),
      'filename' => filename_for(source),
      'sha256' => sha,
      'canonical_publish_relative_path' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ACTIVATION_DECISIONS/fixture.json'
    }
  end

  def source_id_field(source)
    {
      'operation_intent' => 'intent_id',
      'approval_subject' => 'subject_id',
      'owner_attestation' => 'attestation_id',
      'reviewer_attestation' => 'attestation_id',
      'final_operation_decision' => 'decision_id'
    }.fetch(source)
  end

  def filename_for(source)
    {
      'operation_intent' => 'operation-intent-v2.json',
      'approval_subject' => 'approval-subject-v2.json',
      'owner_attestation_payload' => 'owner-attestation-payload-v2.json',
      'reviewer_attestation_payload' => 'reviewer-attestation-payload-v2.json',
      'owner_signature' => 'owner-attestation.sshsig',
      'reviewer_signature' => 'reviewer-attestation.sshsig',
      'owner_attestation' => 'owner-attestation-v2.json',
      'reviewer_attestation' => 'reviewer-attestation-v2.json',
      'final_operation_decision' => 'operation-decision-v2.json',
      'mutation_request' => 'request.json',
      'request_bundle' => 'request-bundle-v2.json'
    }.fetch(source, "#{source}.json")
  end

  def role_for(node)
    return 'product_owner' if node.start_with?('owner_')
    return 'independent_technical_security_reviewer' if node.start_with?('reviewer_')

    'not_applicable'
  end

  def binding_observable?(target_bytes, source, binding, source_sha)
    return target_bytes.include?('/fixture/request_inbox_identity') if source == 'request_inbox_identity' && binding.include?('inbox_directory')

    target_bytes.include?(source_sha)
  end

  def binding_fields(binding)
    binding.scan(/`([^`]+)`/).flatten.map { |field| field.split(/[.\[]/, 2).first }
  end

  def assert_fixture_references_closed(node, object)
    object.each do |field, value|
      if field == 'imported_file_references'
        value.each { |reference| assert_equal FILE_REFERENCE_FIELDS.sort, reference.keys.sort, "#{node}.#{field}" }
      elsif field == 'external_signature_reference'
        assert_equal SIGNATURE_REFERENCE_FIELDS.sort, value.keys.sort, "#{node}.#{field}"
      elsif field == 'operation_decision_reference'
        assert_equal DECISION_REFERENCE_FIELDS.sort, value.keys.sort, "#{node}.#{field}"
      elsif (field.end_with?('_reference') || field.end_with?('_reference_or_null')) && !value.nil?
        allowed = [FILE_REFERENCE_FIELDS.sort, %w[device_id inode path sha256]]
        assert_includes allowed, value.keys.sort, "#{node}.#{field}"
      end
    end
  end

  def mutate_binding(target_bytes, binding)
    return target_bytes + 'mutation' if binding == 'signature covers exact payload bytes'

    object = JSON.parse(target_bytes)
    if binding.include?('file_inventory_sha256[')
      object['file_inventory_sha256'] = 'f' * 64
    elsif binding.include?('imported_file_references[')
      filename = binding[/\[([^\]]+)\]/, 1]
      object.fetch('imported_file_references').find { |entry| entry.fetch('filename') == filename }['sha256'] = 'f' * 64
    elsif binding.include?('signature_import_receipt_references[')
      role = binding[/\[([^\]]+)\]/, 1]
      object.fetch('signature_import_receipt_references').fetch(role)['sha256'] = 'f' * 64
    else
      field = binding.scan(/`([^`]+)`/).flatten.first
      if field.include?('.')
        parent, leaf = field.split('.', 2)
        object.fetch(parent)[leaf] = 'mutated'
      else
        object[field] = 'mutated'
      end
    end
    canonical_json_lf(object)
  end

  def canonical_json_lf(value)
    canonical_json(value) + "\n"
  end

  def canonical_json(value)
    case value
    when Hash
      '{' + value.keys.sort_by(&:encode).map { |key| "#{JSON.generate(key)}:#{canonical_json(value.fetch(key))}" }.join(',') + '}'
    when Array
      '[' + value.map { |entry| canonical_json(entry) }.join(',') + ']'
    when String
      utf8 = value.dup.force_encoding(Encoding::UTF_8)
      raise ArgumentError, 'invalid UTF-8' unless utf8.valid_encoding?

      JSON.generate(utf8)
    when Integer
      raise ArgumentError, 'unsafe integer' unless (-9_007_199_254_740_991..9_007_199_254_740_991).cover?(value)

      value.to_s
    when TrueClass
      'true'
    when FalseClass
      'false'
    when NilClass
      'null'
    else
      raise ArgumentError, 'unsupported canonical JSON value'
    end
  end

  def fields_after(marker)
    tail = @adr.split(marker, 2).fetch(1)
    tail.lines.find { |line| line.start_with?('`') }.scan(/`([^`]+)`/).flatten
  end

  def tokens_between(start_marker, end_marker)
    @adr.split(start_marker, 2).fetch(1).split(end_marker, 2).first.scan(/`([^`]+)`/).flatten
  end

  def table_section(start_marker, end_marker)
    @adr.split(start_marker, 2).fetch(1).split(end_marker, 2).first
  end

  def two_column_table(start_marker, end_marker)
    table_section(start_marker, end_marker).lines.each_with_object([]) do |line, rows|
      match = line.match(/^\| ([^|]+) \| `([^`]+)` \|$/)
      rows << [match[1].strip, match[2]] if match
    end
  end

  def three_column_table(start_marker, end_marker)
    table_section(start_marker, end_marker).lines.each_with_object([]) do |line, rows|
      match = line.match(/^\| `([^`]+)` \| `([^`]+)` \| `([^`]+)` \|$/)
      rows << [match[1], match[2], match[3]] if match
    end
  end

  def four_column_table(start_marker, end_marker)
    table_section(start_marker, end_marker).lines.each_with_object([]) do |line, rows|
      match = line.match(/^\| `([^`]+)` \| (?:`([^`]+)`|([^|]+)) \| (?:`([^`]+)`|([^|]+)) \| (?:`([^`]+)`|([^|]+)) \|$/)
      rows << [match[1], (match[2] || match[3]).strip, (match[4] || match[5]).strip, (match[6] || match[7]).strip] if match
    end
  end

  def five_column_table(start_marker, end_marker)
    table_section(start_marker, end_marker).lines.each_with_object([]) do |line, rows|
      match = line.match(/^\| `([^`]+)` \| `([^`]+)` \| ([^|]+) \| `([^`]+)` \| `([^`]+)` \|$/)
      rows << [match[1], match[2], match[3].strip, match[4], match[5]] if match
    end
  end

  def dag_table(start_marker, end_marker)
    table_section(start_marker, end_marker).lines.each_with_object([]) do |line, rows|
      match = line.match(/^\| `([^`]+)` \| `([^`]+)` \| (.+) \|$/)
      rows << [match[1], match[2], match[3].strip] if match
    end
  end
end
