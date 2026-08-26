#!/usr/bin/env ruby
# frozen_string_literal: true

require 'date'
require 'base64'
require 'digest'
require 'json'
require 'openssl'
require 'optparse'
require 'pathname'
require 'time'

class ParityGovernanceValidator
  class DuplicateKeyHash < Hash
    def []=(key, value)
      raise JSON::ParserError, "duplicate JSON object key #{key.inspect}" if key?(key)

      super
    end
  end
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

  EXPECTED_BATCH_B_IDS = %w[
    PAR-ADM-009
    PAR-ADM-010
    PAR-ADM-033
    PAR-REG-001
    PAR-REG-002
    PAR-REG-003
    PAR-REG-004
    PAR-REG-005
  ].freeze

  EXPECTED_BATCH_C_IDS = %w[
    PAR-ADM-004
    PAR-ADM-021
    PAR-ADM-026
    PAR-ADM-027
    PAR-ADM-028
    PAR-ADM-034
    PAR-ADM-036
    PAR-ADM-046
    PAR-CLN-001
    PAR-CLN-002
    PAR-CLN-003
    PAR-CLN-004
    PAR-CLN-005
    PAR-CLN-019
    PAR-RMIK-001
    PAR-RMIK-002
    PAR-RMIK-004
    PAR-RMIK-006
    PAR-RMIK-007
  ].freeze

  EXPECTED_BATCH_D_IDS = %w[
    PAR-ADM-014
    PAR-ADM-015
    PAR-ADM-041
    PAR-ADM-043
    PAR-CLN-006
    PAR-CLN-007
    PAR-CLN-008
    PAR-CLN-009
    PAR-CLN-010
    PAR-CLN-011
    PAR-CLN-012
    PAR-CLN-013
    PAR-CLN-014
    PAR-CLN-015
    PAR-CLN-016
    PAR-CLN-017
    PAR-CLN-018
    PAR-CLN-020
    PAR-ORP-001
  ].freeze

  EXPECTED_BATCH_E_IDS = %w[
    PAR-ADM-017 PAR-ADM-020 PAR-ADM-029 PAR-ADM-030 PAR-ADM-042
    PAR-PHA-001 PAR-PHA-002 PAR-PHA-003 PAR-PHA-004 PAR-PHA-005 PAR-PHA-006 PAR-PHA-007 PAR-PHA-008 PAR-PHA-009 PAR-PHA-010
    PAR-PHA-011 PAR-PHA-012 PAR-PHA-013 PAR-PHA-014 PAR-PHA-015 PAR-PHA-016 PAR-PHA-017 PAR-PHA-018 PAR-PHA-019 PAR-PHA-020
    PAR-PWH-001 PAR-PWH-002 PAR-PWH-003 PAR-PWH-004 PAR-PWH-005 PAR-PWH-006 PAR-PWH-007 PAR-PWH-008 PAR-PWH-009 PAR-PWH-010
    PAR-PWH-011 PAR-PWH-012 PAR-PWH-013 PAR-PWH-014 PAR-PWH-015 PAR-PWH-016 PAR-PWH-017 PAR-PWH-018 PAR-PWH-019 PAR-PWH-020
    PAR-PWH-021 PAR-PWH-022 PAR-PWH-023
  ].freeze

  EXPECTED_BATCH_F_IDS = %w[
    PAR-ADM-011 PAR-ADM-016 PAR-ADM-018 PAR-ADM-019 PAR-ADM-039
    PAR-BPJS-001 PAR-BPJS-002
    PAR-CLM-001 PAR-CLM-002 PAR-CLM-003 PAR-CLM-004 PAR-CLM-005 PAR-CLM-006
    PAR-FIN-001 PAR-FIN-002 PAR-FIN-003 PAR-FIN-004 PAR-FIN-005 PAR-FIN-006 PAR-FIN-007 PAR-FIN-008 PAR-FIN-009 PAR-FIN-010
    PAR-FIN-011 PAR-FIN-012 PAR-FIN-013 PAR-FIN-014 PAR-FIN-015 PAR-FIN-016 PAR-FIN-017 PAR-FIN-018 PAR-FIN-019
    PAR-RMIK-003 PAR-RMIK-005
  ].freeze

  EXPECTED_BATCH_G_IDS = [
    'PAR-ADM-007', 'PAR-ADM-031', 'PAR-ADM-035',
    *(1..117).map { |number| format('PAR-RPT-%03d', number) }
  ].freeze

  BATCH_A_REGISTER_SCHEMA_VERSION = 1
  BATCH_A_REGISTER_ID = 'G0-BATCH-A-2026-08-25'
  BATCH_A_EVIDENCE_DIRECTORY = 'G0_BATCH_A_DECISION_EVIDENCE_2026-08-25'
  BATCH_B_REGISTER_ID = 'G0-BATCH-B-2026-08-25'
  BATCH_B_EVIDENCE_DIRECTORY = 'G0_BATCH_B_DECISION_EVIDENCE_2026-08-25'
  BATCH_C_REGISTER_ID = 'G0-BATCH-C-2026-08-25'
  BATCH_C_EVIDENCE_DIRECTORY = 'G0_BATCH_C_DECISION_EVIDENCE_2026-08-25'
  BATCH_D_REGISTER_ID = 'G0-BATCH-D-2026-08-25'
  BATCH_D_EVIDENCE_DIRECTORY = 'G0_BATCH_D_DECISION_EVIDENCE_2026-08-25'
  BATCH_E_REGISTER_ID = 'G0-BATCH-E-2026-08-25'
  BATCH_E_EVIDENCE_DIRECTORY = 'G0_BATCH_E_DECISION_EVIDENCE_2026-08-25'
  BATCH_E_SOURCE_REVISION = '36c309cd734f78716ae8ee146a08c2129beedbd9'
  BATCH_F_REGISTER_ID = 'G0-BATCH-F-2026-08-25'
  BATCH_F_EVIDENCE_DIRECTORY = 'G0_BATCH_F_DECISION_EVIDENCE_2026-08-25'
  BATCH_F_SOURCE_REVISION = 'e3cd84d94be2768cbc597cfe5cb277f973c2f2b4'
  BATCH_G_REGISTER_ID = 'G0-BATCH-G-2026-08-25'
  BATCH_G_EVIDENCE_DIRECTORY = 'G0_BATCH_G_DECISION_EVIDENCE_2026-08-25'
  BATCH_G_SOURCE_REVISION = '9347940e61e68a07662d5b96da9132a55421bb59'
  DECISION_REGISTER_CONFIGS = {
    'A' => { expected_count: EXPECTED_BATCH_COUNTS.fetch('A'), expected_ids: EXPECTED_BATCH_A_IDS, register_id: BATCH_A_REGISTER_ID, evidence_directory: BATCH_A_EVIDENCE_DIRECTORY }.freeze,
    'B' => { expected_count: EXPECTED_BATCH_COUNTS.fetch('B'), expected_ids: EXPECTED_BATCH_B_IDS, register_id: BATCH_B_REGISTER_ID, evidence_directory: BATCH_B_EVIDENCE_DIRECTORY }.freeze,
    'C' => { expected_count: EXPECTED_BATCH_COUNTS.fetch('C'), expected_ids: EXPECTED_BATCH_C_IDS, register_id: BATCH_C_REGISTER_ID, evidence_directory: BATCH_C_EVIDENCE_DIRECTORY }.freeze,
    'D' => { expected_count: EXPECTED_BATCH_COUNTS.fetch('D'), expected_ids: EXPECTED_BATCH_D_IDS, register_id: BATCH_D_REGISTER_ID, evidence_directory: BATCH_D_EVIDENCE_DIRECTORY }.freeze,
    'E' => { expected_count: EXPECTED_BATCH_COUNTS.fetch('E'), expected_ids: EXPECTED_BATCH_E_IDS, register_id: BATCH_E_REGISTER_ID, evidence_directory: BATCH_E_EVIDENCE_DIRECTORY }.freeze,
    'F' => { expected_count: EXPECTED_BATCH_COUNTS.fetch('F'), expected_ids: EXPECTED_BATCH_F_IDS, register_id: BATCH_F_REGISTER_ID, evidence_directory: BATCH_F_EVIDENCE_DIRECTORY }.freeze,
    'G' => { expected_count: EXPECTED_BATCH_COUNTS.fetch('G'), expected_ids: EXPECTED_BATCH_G_IDS, register_id: BATCH_G_REGISTER_ID, evidence_directory: BATCH_G_EVIDENCE_DIRECTORY }.freeze
  }.transform_values(&:freeze).freeze
  BATCH_C_REQUIRED_AUTHORITIES = {
    'PAR-ADM-004' => %w[product_delivery clinical_governance rmik quality_analytics security_privacy_data],
    'PAR-ADM-021' => %w[product_delivery inpatient_clinical nursing_governance rmik coding_claims reporting security_privacy_data],
    'PAR-ADM-026' => %w[product_delivery clinical_governance rmik quality_analytics security_privacy_data],
    'PAR-ADM-027' => %w[product_delivery clinical_governance rmik security_privacy_data],
    'PAR-ADM-028' => %w[product_delivery clinical_governance rmik data_migration security_privacy_data],
    'PAR-ADM-034' => %w[product_delivery nursing_governance clinical_governance rmik reporting security_privacy_data],
    'PAR-ADM-036' => %w[product_delivery dental_clinical clinical_governance rmik security_privacy_data],
    'PAR-ADM-046' => %w[product_delivery clinical_operations biomedical_equipment inventory_supply rmik security_privacy_data],
    'PAR-CLN-001' => %w[product_delivery clinical_governance outpatient_clinical rmik data_migration security_privacy_data],
    'PAR-CLN-002' => %w[product_delivery emergency_clinical nursing_governance patient_flow rmik security_privacy_data],
    'PAR-CLN-003' => %w[product_delivery emergency_clinical nursing_governance orders_results rmik finance_claims security_privacy_data],
    'PAR-CLN-004' => %w[product_delivery outpatient_clinical nursing_governance orders_results rmik coding_claims security_privacy_data],
    'PAR-CLN-005' => %w[product_delivery inpatient_clinical nursing_governance orders_results rmik facility_bed_management coding_claims security_privacy_data],
    'PAR-CLN-019' => %w[product_delivery inpatient_clinical nursing_governance rmik data_migration security_privacy_data],
    'PAR-RMIK-001' => %w[product_delivery rmik outpatient_clinical coding_claims record_custody security_privacy_data],
    'PAR-RMIK-002' => %w[product_delivery rmik inpatient_clinical nursing_governance coding_claims record_custody security_privacy_data],
    'PAR-RMIK-004' => %w[product_delivery rmik record_custody clinical_access legal_retention security_privacy_data],
    'PAR-RMIK-006' => %w[product_delivery rmik outpatient_clinical clinical_governance data_migration security_privacy_data],
    'PAR-RMIK-007' => %w[product_delivery rmik inpatient_clinical nursing_governance clinical_governance data_migration security_privacy_data]
  }.transform_values(&:freeze).freeze
  BATCH_C_LEAD_AUTHORITIES = {
    'PAR-ADM-004' => 'clinical_governance', 'PAR-ADM-021' => 'rmik',
    'PAR-ADM-026' => 'clinical_governance', 'PAR-ADM-027' => 'clinical_governance',
    'PAR-ADM-028' => 'clinical_governance', 'PAR-ADM-034' => 'nursing_governance',
    'PAR-ADM-036' => 'dental_clinical', 'PAR-ADM-046' => 'biomedical_equipment',
    'PAR-CLN-001' => 'clinical_governance', 'PAR-CLN-002' => 'emergency_clinical',
    'PAR-CLN-003' => 'emergency_clinical', 'PAR-CLN-004' => 'outpatient_clinical',
    'PAR-CLN-005' => 'inpatient_clinical', 'PAR-CLN-019' => 'inpatient_clinical',
    'PAR-RMIK-001' => 'rmik', 'PAR-RMIK-002' => 'rmik', 'PAR-RMIK-004' => 'rmik',
    'PAR-RMIK-006' => 'rmik', 'PAR-RMIK-007' => 'rmik'
  }.freeze
  BATCH_D_REQUIRED_AUTHORITIES = {
    'PAR-ADM-014' => %w[product_delivery clinical_ordering laboratory rmik finance_claims security_privacy_data data_migration],
    'PAR-ADM-015' => %w[product_delivery clinical_ordering radiology rmik finance_claims security_privacy_data data_migration],
    'PAR-ADM-041' => %w[product_delivery surgery_anesthesia theatre_operations rmik pharmacy warehouse_stock finance_claims security_privacy_data data_migration],
    'PAR-ADM-043' => %w[product_delivery clinical_ordering laboratory anatomical_pathology microbiology rmik security_privacy_data],
    'PAR-CLN-006' => %w[product_delivery clinical_ordering nursing laboratory rmik finance_claims security_privacy_data],
    'PAR-CLN-007' => %w[product_delivery clinical_ordering radiology rmik finance_claims security_privacy_data operations_recovery],
    'PAR-CLN-008' => %w[product_delivery clinical_ordering nursing nutrition_dietetics rmik finance_claims security_privacy_data],
    'PAR-CLN-009' => %w[product_delivery clinical_ordering nursing surgery_anesthesia theatre_operations rmik anatomical_pathology blood_bank pharmacy warehouse_stock finance_claims security_privacy_data],
    'PAR-CLN-010' => %w[product_delivery clinical_ordering nursing surgery_anesthesia theatre_operations rmik anatomical_pathology blood_bank pharmacy warehouse_stock finance_claims security_privacy_data data_migration],
    'PAR-CLN-011' => %w[product_delivery clinical_ordering rehabilitation_medicine nursing rmik finance_claims security_privacy_data],
    'PAR-CLN-012' => %w[product_delivery clinical_ordering rehabilitation_medicine nursing rmik finance_claims security_privacy_data data_migration],
    'PAR-CLN-013' => %w[product_delivery clinical_ordering rehabilitation_medicine speech_therapy rmik finance_claims security_privacy_data],
    'PAR-CLN-014' => %w[product_delivery clinical_ordering rehabilitation_medicine occupational_therapy rmik finance_claims security_privacy_data],
    'PAR-CLN-015' => %w[product_delivery clinical_ordering laboratory anatomical_pathology rmik finance_claims security_privacy_data operations_recovery],
    'PAR-CLN-016' => %w[product_delivery clinical_ordering laboratory microbiology rmik finance_claims security_privacy_data operations_recovery],
    'PAR-CLN-017' => %w[product_delivery clinical_ordering nursing blood_bank transfusion_clinical rmik warehouse_stock finance_claims security_privacy_data],
    'PAR-CLN-018' => %w[product_delivery registration_admission mortuary_operations rmik finance_claims security_privacy_data operations_recovery],
    'PAR-CLN-020' => %w[product_delivery clinical_ordering nursing registration_admission ambulance_transport rmik finance_claims security_privacy_data operations_recovery],
    'PAR-ORP-001' => %w[product_delivery surgery_anesthesia theatre_operations nursing pharmacy warehouse_stock rmik finance_claims security_privacy_data]
  }.transform_values(&:freeze).freeze
  BATCH_D_LEAD_AUTHORITIES = {
    'PAR-ADM-014' => 'laboratory', 'PAR-ADM-015' => 'radiology',
    'PAR-ADM-041' => 'theatre_operations', 'PAR-ADM-043' => 'laboratory',
    'PAR-CLN-006' => 'laboratory', 'PAR-CLN-007' => 'radiology',
    'PAR-CLN-008' => 'nutrition_dietetics', 'PAR-CLN-009' => 'surgery_anesthesia',
    'PAR-CLN-010' => 'surgery_anesthesia', 'PAR-CLN-011' => 'rehabilitation_medicine',
    'PAR-CLN-012' => 'rehabilitation_medicine', 'PAR-CLN-013' => 'speech_therapy',
    'PAR-CLN-014' => 'occupational_therapy', 'PAR-CLN-015' => 'anatomical_pathology',
    'PAR-CLN-016' => 'microbiology', 'PAR-CLN-017' => 'blood_bank',
    'PAR-CLN-018' => 'mortuary_operations', 'PAR-CLN-020' => 'ambulance_transport',
    'PAR-ORP-001' => 'pharmacy'
  }.freeze
  BATCH_D_REQUIRED_UPSTREAM_DEPENDENCIES = {
    'PAR-ADM-014' => [['batch_gate', 'C', %w[clinical_ordering rmik finance security]]],
    'PAR-ADM-015' => [['batch_gate', 'C', %w[clinical_ordering rmik finance security]]],
    'PAR-ADM-041' => [['batch_gate', 'C', %w[encounter clinical_documentation rmik security]]],
    'PAR-ADM-043' => [['batch_gate', 'C', %w[clinical_ordering rmik security]]],
    'PAR-CLN-006' => [['requirement', 'PAR-ADM-014', %w[examination order result lifecycle]], ['requirement', 'PAR-ADM-043', %w[specimen label custody rejection]]],
    'PAR-CLN-007' => [['requirement', 'PAR-ADM-015', %w[examination order result correction]]],
    'PAR-CLN-008' => [['requirement', 'PAR-CLN-005', %w[encounter diet_order delivery cancellation]]],
    'PAR-CLN-009' => [['requirement', 'PAR-ADM-041', %w[schedule procedure cancellation correction]]],
    'PAR-CLN-010' => [['requirement', 'PAR-ADM-041', %w[schedule procedure cancellation correction]], ['requirement', 'PAR-CLN-009', %w[field state lifecycle exclusion]]],
    'PAR-CLN-011' => [['requirement', 'PAR-REG-003', %w[encounter appointment authorization correction]]],
    'PAR-CLN-012' => [['requirement', 'PAR-CLN-011', %w[field state lifecycle exclusion]]],
    'PAR-CLN-013' => [['requirement', 'PAR-CLN-011', %w[referral session result correction]]],
    'PAR-CLN-014' => [['requirement', 'PAR-CLN-011', %w[referral session result correction]]],
    'PAR-CLN-015' => [['requirement', 'PAR-ADM-014', %w[examination order result lifecycle]], ['requirement', 'PAR-ADM-043', %w[specimen custody result amendment]]],
    'PAR-CLN-016' => [['requirement', 'PAR-ADM-014', %w[examination order result lifecycle]], ['requirement', 'PAR-ADM-043', %w[specimen custody result amendment]]],
    'PAR-CLN-017' => [['requirement', 'PAR-ADM-043', %w[specimen custody compatibility traceability]]],
    'PAR-CLN-018' => [['requirement', 'PAR-RMIK-004', %w[identity custody release retention]]],
    'PAR-CLN-020' => [['requirement', 'PAR-REG-002', %w[encounter dispatch handoff cancellation]]],
    'PAR-ORP-001' => [['batch_gate', 'E', %w[stock issue return lot charge]]]
  }.transform_values(&:freeze).freeze
  BATCH_D_SHARED_A_FOUNDATION = ['batch_gate', 'A', %w[identity access audit configuration]].freeze
  BATCH_D_SERVICE_FOUNDATIONS = [
    ['batch_gate', 'B', %w[patient encounter admission identity]],
    ['batch_gate', 'C', %w[clinical_state clinical_authority rmik_record amendment]]
  ].map(&:freeze).freeze
  FOUR_SCENARIO_BATCHES = %w[C D E F G].freeze
  AUTHORITY_BOUND_BATCHES = %w[C D E F G].freeze
  FOUR_SCENARIO_NAMES = %w[normal denial correction_or_amendment dependency_outage].freeze
  BATCH_D_CAPABILITY_KINDS = {
    'PAR-ADM-014' => 'diagnostic_master', 'PAR-ADM-015' => 'diagnostic_master',
    'PAR-ADM-041' => 'procedure_master', 'PAR-ADM-043' => 'specimen_master',
    'PAR-CLN-006' => 'diagnostic_service', 'PAR-CLN-007' => 'diagnostic_service',
    'PAR-CLN-008' => 'ancillary_service', 'PAR-CLN-009' => 'procedure_service',
    'PAR-CLN-010' => 'consolidation_candidate', 'PAR-CLN-011' => 'therapy_service',
    'PAR-CLN-012' => 'consolidation_candidate', 'PAR-CLN-013' => 'therapy_service',
    'PAR-CLN-014' => 'therapy_service', 'PAR-CLN-015' => 'diagnostic_service',
    'PAR-CLN-016' => 'diagnostic_service', 'PAR-CLN-017' => 'blood_bank_service',
    'PAR-CLN-018' => 'mortuary_service', 'PAR-CLN-020' => 'transport_service',
    'PAR-ORP-001' => 'pharmacy_boundary'
  }.freeze
  BATCH_D_LIFECYCLE_MAPPING_REQUIRED_IDS = EXPECTED_BATCH_D_IDS.freeze
  BATCH_D_INTEGRATION_MODES = %w[none non_transmitting_simulation simulated_adapter].freeze
  BATCH_D_EVIDENCE_BASES = %w[observed_behavior signed_document implementation_verified user_interview route_or_menu].freeze
  UPSTREAM_DEPENDENCY_KINDS = %w[requirement batch_gate].freeze
  UPSTREAM_DEPENDENCY_STATUSES = %w[pending resolved deferred].freeze
  LIFECYCLE_MAPPING_STATUSES = %w[pending complete not_applicable].freeze
  LIFECYCLE_ARTIFACT_TYPE = 'g0_batch_d_lifecycle'
  MAPPING_ARTIFACT_TYPE = 'g0_batch_d_mapping'
  UPSTREAM_RESOLUTION_ARTIFACT_TYPE = 'g0_batch_d_upstream_resolution'
  BATCH_GATE_AUTHORITIES = { 'A' => 'security_privacy_data', 'B' => 'registration_admission', 'C' => 'rmik', 'E' => 'pharmacy' }.freeze
  BATCH_D_LIFECYCLE_STATES = {
    'diagnostic_master' => %w[draft effective retired merged],
    'procedure_master' => %w[draft effective retired merged],
    'specimen_master' => %w[draft effective retired merged],
    'diagnostic_service' => %w[ordered accepted performed verified final cancelled amended],
    'ancillary_service' => %w[requested accepted fulfilled cancelled amended],
    'procedure_service' => %w[scheduled readiness started completed recovery cancelled amended],
    'consolidation_candidate' => %w[legacy_identified mapped verified migrated retired],
    'therapy_service' => %w[referred scheduled in_progress completed cancelled amended],
    'blood_bank_service' => %w[requested specimen_received compatible reserved simulated_issued returned reaction_reviewed cancelled reconciled],
    'mortuary_service' => %w[identified received in_custody release_authorized released amended],
    'transport_service' => %w[requested triaged dispatched handoff completed cancelled amended],
    'pharmacy_boundary' => %w[requested authorized simulated_issue used returned wasted cancelled reconciled]
  }.transform_values(&:freeze).freeze
  BATCH_D_LIFECYCLE_TRANSITIONS = {
    'diagnostic_master' => %w[draft->effective effective->retired effective->merged],
    'procedure_master' => %w[draft->effective effective->retired effective->merged],
    'specimen_master' => %w[draft->effective effective->retired effective->merged],
    'diagnostic_service' => %w[ordered->accepted ordered->cancelled accepted->performed accepted->cancelled performed->verified verified->final final->amended],
    'ancillary_service' => %w[requested->accepted requested->cancelled accepted->fulfilled accepted->cancelled fulfilled->amended],
    'procedure_service' => %w[scheduled->readiness scheduled->cancelled readiness->started readiness->cancelled started->completed completed->recovery completed->amended recovery->amended],
    'consolidation_candidate' => %w[legacy_identified->mapped mapped->verified verified->migrated migrated->retired],
    'therapy_service' => %w[referred->scheduled referred->cancelled scheduled->in_progress scheduled->cancelled in_progress->completed completed->amended],
    'blood_bank_service' => %w[requested->specimen_received requested->cancelled specimen_received->compatible compatible->reserved reserved->simulated_issued reserved->cancelled simulated_issued->returned simulated_issued->reaction_reviewed simulated_issued->reconciled returned->reconciled reaction_reviewed->reconciled],
    'mortuary_service' => %w[identified->received received->in_custody in_custody->release_authorized release_authorized->released received->amended in_custody->amended released->amended],
    'transport_service' => %w[requested->triaged requested->cancelled triaged->dispatched triaged->cancelled dispatched->handoff dispatched->cancelled handoff->completed completed->amended],
    'pharmacy_boundary' => %w[requested->authorized requested->cancelled authorized->simulated_issue authorized->cancelled simulated_issue->used simulated_issue->returned simulated_issue->wasted used->reconciled returned->reconciled wasted->reconciled]
  }.transform_values(&:freeze).freeze
  BATCH_D_COMMON_CORRECTION_RULES = %w[prior_state_immutable reason_required authorized_actor single_reconciliation].freeze
  BATCH_D_MAPPING_FIELDS = %w[identity authority timestamps status audit].freeze
  BATCH_D_SCENARIO_FOCUS = {
    'PAR-ADM-014' => 'laboratory_examination_master', 'PAR-ADM-015' => 'radiology_examination_master',
    'PAR-ADM-041' => 'ibs_procedure_group', 'PAR-ADM-043' => 'specimen_custody_master',
    'PAR-CLN-006' => 'laboratory_specimen_result', 'PAR-CLN-007' => 'radiology_acquisition_report',
    'PAR-CLN-008' => 'nutrition_diet_delivery', 'PAR-CLN-009' => 'operation_readiness_recovery',
    'PAR-CLN-010' => 'operation_v3_mapping', 'PAR-CLN-011' => 'rehabilitation_referral_session',
    'PAR-CLN-012' => 'rehabilitation_v2_mapping', 'PAR-CLN-013' => 'speech_therapy_session',
    'PAR-CLN-014' => 'occupational_therapy_session', 'PAR-CLN-015' => 'pathology_specimen_report',
    'PAR-CLN-016' => 'microbiology_culture_susceptibility', 'PAR-CLN-017' => 'blood_compatibility_traceability',
    'PAR-CLN-018' => 'mortuary_custody_release', 'PAR-CLN-020' => 'ambulance_dispatch_handoff',
    'PAR-ORP-001' => 'ibs_pharmacy_stock_boundary'
  }.freeze
  BATCH_E_FAMILY_MEMBERS = {
    'E1' => %w[PAR-ADM-017 PAR-ADM-020 PAR-ADM-029 PAR-ADM-030 PAR-ADM-042 PAR-PWH-001],
    'E2' => %w[PAR-PHA-001 PAR-PHA-002 PAR-PHA-003 PAR-PHA-004 PAR-PHA-015 PAR-PHA-016 PAR-PHA-017],
    'E3' => %w[PAR-PHA-005 PAR-PHA-011 PAR-PHA-012 PAR-PHA-013 PAR-PHA-014 PAR-PHA-019],
    'E4' => %w[PAR-PHA-006 PAR-PHA-007 PAR-PHA-008 PAR-PHA-009 PAR-PHA-010 PAR-PHA-018 PAR-PHA-020],
    'E5' => %w[PAR-PWH-002 PAR-PWH-005 PAR-PWH-009 PAR-PWH-012 PAR-PWH-017],
    'E6' => %w[PAR-PWH-013 PAR-PWH-018 PAR-PWH-021],
    'E7' => %w[PAR-PWH-003 PAR-PWH-006 PAR-PWH-008 PAR-PWH-016],
    'E8' => %w[PAR-PWH-004 PAR-PWH-007 PAR-PWH-010 PAR-PWH-011 PAR-PWH-014 PAR-PWH-015 PAR-PWH-019 PAR-PWH-020 PAR-PWH-023],
    'E9' => %w[PAR-PWH-022]
  }.transform_values(&:freeze).freeze
  BATCH_E_FAMILY_AUTHORITIES = {
    'E1' => { lead: 'pharmacy_master_data', co_owners: %w[product_delivery pharmacy_master_data pharmacy warehouse_stock clinical_governance finance_claims procurement security_privacy_data] },
    'E2' => { lead: 'pharmacy', co_owners: %w[product_delivery pharmacy clinical_ordering clinical_governance warehouse_stock finance_claims rmik security_privacy_data] },
    'E3' => { lead: 'pharmacy_operations', co_owners: %w[product_delivery pharmacy_operations pharmacy warehouse_stock finance_claims rmik security_privacy_data] },
    'E4' => { lead: 'pharmacy_inventory_control', co_owners: %w[product_delivery pharmacy_inventory_control pharmacy warehouse_stock finance_claims reporting security_privacy_data] },
    'E5' => { lead: 'procurement', co_owners: %w[product_delivery procurement warehouse_stock pharmacy finance_claims security_privacy_data] },
    'E6' => { lead: 'warehouse_stock', co_owners: %w[product_delivery warehouse_stock pharmacy clinical_governance finance_claims security_privacy_data] },
    'E7' => { lead: 'warehouse_stock', co_owners: %w[product_delivery warehouse_stock pharmacy unit_operations finance_claims security_privacy_data] },
    'E8' => { lead: 'inventory_control', co_owners: %w[product_delivery inventory_control warehouse_stock pharmacy finance_claims security_privacy_data operations_recovery] },
    'E9' => { lead: 'blood_bank', co_owners: %w[product_delivery blood_bank transfusion_clinical warehouse_stock clinical_governance finance_claims security_privacy_data operations_recovery] }
  }.transform_values { |policy| { lead: policy.fetch(:lead).freeze, co_owners: policy.fetch(:co_owners).freeze }.freeze }.freeze
  BATCH_E_FAMILY_HAZARDS = {
    'E1' => %w[versioned_master duplicate_code invalid_unit destructive_master_edit],
    'E2' => %w[wrong_context duplicate_dispense expired_or_quarantined_issue duplicate_charge],
    'E3' => %w[one_sided_issue_or_return negative_stock duplicate_movement charge_divergence],
    'E4' => %w[projection_drift stale_balance structural_capture_only period_mismatch],
    'E5' => %w[unapproved_supplier duplicate_receipt lot_expiry_missing valuation_or_ap_divergence],
    'E6' => %w[replenishment_drift expired_or_quarantined_issue fefo_violation buffer_miscalculation],
    'E7' => %w[one_sided_transfer destination_mismatch duplicate_movement unit_return_divergence],
    'E8' => %w[history_edit_or_delete negative_stock period_reopen duplicate_correction],
    'E9' => %w[blood_stock_medicine_substitution compatibility_bypass custody_gap expired_or_quarantined_issue]
  }.transform_values(&:freeze).freeze
  BATCH_E_ROW_HAZARDS = {
    'PAR-ADM-017' => %w[supplier_master_duplicate_or_unapproved],
    'PAR-ADM-020' => %w[discount_overlap_or_unauthorized],
    'PAR-ADM-029' => %w[monitor_semantics_unknown],
    'PAR-ADM-030' => %w[depot_identity_or_scope_mismatch],
    'PAR-ADM-042' => %w[package_component_version_or_duplicate_charge],
    'PAR-PHA-001' => %w[emergency_dispense_wrong_encounter],
    'PAR-PHA-002' => %w[outpatient_dispense_duplicate_charge],
    'PAR-PHA-003' => %w[inpatient_dispense_wrong_admission],
    'PAR-PHA-004' => %w[external_patient_identity_or_charge_gap],
    'PAR-PHA-005' => %w[pharmacy_transfer_one_sided_posting],
    'PAR-PHA-006' => %w[pharmacy_count_period_or_mode_confusion],
    'PAR-PHA-007' => %w[pharmacy_stock_position_projection_drift],
    'PAR-PHA-008' => %w[pharmacy_distribution_projection_duplicate],
    'PAR-PHA-009' => %w[pharmacy_receipt_projection_drift],
    'PAR-PHA-010' => %w[pharmacy_usage_charge_divergence],
    'PAR-PHA-011' => %w[outpatient_issue_wrong_context],
    'PAR-PHA-012' => %w[external_issue_identity_or_charge_gap],
    'PAR-PHA-013' => %w[inpatient_issue_wrong_admission],
    'PAR-PHA-014' => %w[pharmacy_unit_return_wrong_origin],
    'PAR-PHA-015' => %w[prescription_history_missing_reversal_link],
    'PAR-PHA-016' => %w[outpatient_trolley_semantics_unknown],
    'PAR-PHA-017' => %w[inpatient_trolley_semantics_unknown],
    'PAR-PHA-018' => %w[patient_return_projection_duplicate],
    'PAR-PHA-019' => %w[generic_issue_orp_interface_ambiguity],
    'PAR-PHA-020' => %w[pharmacy_stock_card_missing_compensation],
    'PAR-PWH-001' => %w[medicine_master_lot_unit_mapping_drift],
    'PAR-PWH-002' => %w[receipt_variant_equivalence_unknown],
    'PAR-PWH-003' => %w[warehouse_transfer_one_sided_posting],
    'PAR-PWH-004' => %w[periodic_count_mode_confusion],
    'PAR-PWH-005' => %w[supplier_return_without_receipt_link],
    'PAR-PWH-006' => %w[unit_return_wrong_origin],
    'PAR-PWH-007' => %w[warehouse_stock_position_projection_drift],
    'PAR-PWH-008' => %w[warehouse_distribution_projection_duplicate],
    'PAR-PWH-009' => %w[warehouse_receipt_projection_drift],
    'PAR-PWH-010' => %w[perpetual_report_missing_correction],
    'PAR-PWH-011' => %w[stock_card_missing_correction_link],
    'PAR-PWH-012' => %w[receipt_variant_equivalence_unknown],
    'PAR-PWH-013' => %w[expired_item_issue_or_quarantine_bypass],
    'PAR-PWH-014' => %w[append_only_correction_no_mutation],
    'PAR-PWH-015' => %w[stocktake_report_period_mismatch],
    'PAR-PWH-016' => %w[inter_depot_transfer_one_sided_posting],
    'PAR-PWH-017' => %w[purchase_order_supplier_or_approval_bypass],
    'PAR-PWH-018' => %w[buffer_report_stale_projection],
    'PAR-PWH-019' => %w[per_item_perpetual_report_reconciliation_gap],
    'PAR-PWH-020' => %w[per_item_perpetual_view_reconciliation_gap],
    'PAR-PWH-021' => %w[buffer_replenishment_duplicate_order],
    'PAR-PWH-022' => %w[blood_stock_exception_not_medicine_stock compatibility_and_custody_required],
    'PAR-PWH-023' => %w[initial_count_repeated_baseline]
  }.transform_values(&:freeze).freeze
  BATCH_E_ROW_CAPABILITY_FOCUS = {
    'PAR-ADM-017' => 'supplier_master_approval_versioning', 'PAR-ADM-020' => 'pharmacy_discount_precedence_authorization',
    'PAR-ADM-029' => 'pharmacy_monitor_semantics_discovery', 'PAR-ADM-030' => 'depot_identity_scope_mapping',
    'PAR-ADM-042' => 'medicine_package_component_versioning', 'PAR-PHA-001' => 'emergency_dispensing',
    'PAR-PHA-002' => 'outpatient_dispensing', 'PAR-PHA-003' => 'inpatient_dispensing',
    'PAR-PHA-004' => 'external_patient_dispensing', 'PAR-PHA-005' => 'pharmacy_distribution_transfer',
    'PAR-PHA-006' => 'pharmacy_periodic_stock_count', 'PAR-PHA-007' => 'pharmacy_stock_position_projection',
    'PAR-PHA-008' => 'pharmacy_distribution_projection', 'PAR-PHA-009' => 'pharmacy_receipt_projection',
    'PAR-PHA-010' => 'pharmacy_usage_projection', 'PAR-PHA-011' => 'outpatient_issue_ledger',
    'PAR-PHA-012' => 'external_issue_ledger', 'PAR-PHA-013' => 'inpatient_issue_ledger',
    'PAR-PHA-014' => 'pharmacy_unit_return', 'PAR-PHA-015' => 'prescription_history_projection',
    'PAR-PHA-016' => 'outpatient_trolley_semantics', 'PAR-PHA-017' => 'inpatient_trolley_semantics',
    'PAR-PHA-018' => 'patient_return_projection', 'PAR-PHA-019' => 'generic_issue_orp_boundary',
    'PAR-PHA-020' => 'pharmacy_stock_card_projection', 'PAR-PWH-001' => 'medicine_master_lot_unit_mapping',
    'PAR-PWH-002' => 'warehouse_receipt_variant_one', 'PAR-PWH-003' => 'warehouse_distribution_transfer',
    'PAR-PWH-004' => 'warehouse_periodic_stock_count', 'PAR-PWH-005' => 'supplier_return',
    'PAR-PWH-006' => 'warehouse_unit_return', 'PAR-PWH-007' => 'warehouse_stock_position_projection',
    'PAR-PWH-008' => 'warehouse_distribution_projection', 'PAR-PWH-009' => 'warehouse_receipt_projection',
    'PAR-PWH-010' => 'warehouse_perpetual_projection', 'PAR-PWH-011' => 'warehouse_stock_card_projection',
    'PAR-PWH-012' => 'warehouse_receipt_variant_two', 'PAR-PWH-013' => 'expired_medicine_quarantine',
    'PAR-PWH-014' => 'append_only_stock_correction_reversal', 'PAR-PWH-015' => 'warehouse_stocktake_projection',
    'PAR-PWH-016' => 'inter_depot_distribution_transfer', 'PAR-PWH-017' => 'purchase_order_approval',
    'PAR-PWH-018' => 'buffer_stock_projection', 'PAR-PWH-019' => 'per_item_perpetual_report',
    'PAR-PWH-020' => 'per_item_perpetual_view', 'PAR-PWH-021' => 'buffer_stock_replenishment',
    'PAR-PWH-022' => 'blood_stock_compatibility_custody', 'PAR-PWH-023' => 'initial_stock_count_baseline'
  }.freeze
  BATCH_E_ROW_LIFECYCLES = {
    'PAR-ADM-017' => %w[supplier_version_draft validate_supplier_approval supplier_version_effective supplier_code_unique],
    'PAR-ADM-020' => %w[discount_rule_draft authorize_discount_precedence discount_rule_effective one_discount_path],
    'PAR-ADM-029' => %w[monitor_semantics_unmapped perform_authority_mapping monitor_semantics_reviewed no_stock_mutation],
    'PAR-ADM-030' => %w[depot_scope_draft validate_depot_identity depot_scope_effective location_scope_unique],
    'PAR-ADM-042' => %w[package_version_draft validate_package_components package_version_effective components_version_pinned],
    'PAR-PHA-001' => %w[emergency_prescription_authorized dispense_fefo_to_emergency_encounter emergency_dispense_reconciled single_emergency_charge],
    'PAR-PHA-002' => %w[outpatient_prescription_authorized dispense_fefo_to_outpatient_encounter outpatient_dispense_reconciled single_outpatient_charge],
    'PAR-PHA-003' => %w[inpatient_prescription_authorized dispense_fefo_to_inpatient_admission inpatient_dispense_reconciled single_inpatient_charge],
    'PAR-PHA-004' => %w[external_prescription_authorized dispense_fefo_to_external_identity external_dispense_reconciled external_identity_attributed],
    'PAR-PHA-005' => %w[pharmacy_transfer_authorized post_paired_pharmacy_transfer pharmacy_transfer_reconciled source_destination_balanced],
    'PAR-PHA-006' => %w[pharmacy_count_period_open post_periodic_count_compensation pharmacy_count_period_closed variance_compensated],
    'PAR-PHA-007' => %w[pharmacy_stock_ledger_posted project_pharmacy_stock_position pharmacy_stock_projection_reconciled projection_equals_ledger],
    'PAR-PHA-008' => %w[pharmacy_transfer_ledger_posted project_pharmacy_distribution pharmacy_distribution_projection_reconciled transfer_projection_unique],
    'PAR-PHA-009' => %w[pharmacy_receipt_ledger_posted project_pharmacy_receipts pharmacy_receipt_projection_reconciled receipt_projection_unique],
    'PAR-PHA-010' => %w[pharmacy_issue_ledger_posted project_pharmacy_usage pharmacy_usage_projection_reconciled usage_charge_reconciled],
    'PAR-PHA-011' => %w[outpatient_issue_authorized post_outpatient_issue outpatient_issue_reconciled outpatient_context_bound],
    'PAR-PHA-012' => %w[external_issue_authorized post_external_issue external_issue_reconciled external_identity_bound],
    'PAR-PHA-013' => %w[inpatient_issue_authorized post_inpatient_issue inpatient_issue_reconciled admission_context_bound],
    'PAR-PHA-014' => %w[pharmacy_unit_return_authorized post_paired_pharmacy_unit_return pharmacy_unit_return_reconciled return_origin_bound],
    'PAR-PHA-015' => %w[prescription_ledger_posted project_prescription_history prescription_history_reconciled reversals_linked],
    'PAR-PHA-016' => %w[outpatient_trolley_unmapped deny_until_trolley_mapping outpatient_trolley_no_movement semantics_remain_unknown],
    'PAR-PHA-017' => %w[inpatient_trolley_unmapped deny_until_trolley_mapping inpatient_trolley_no_movement semantics_remain_unknown],
    'PAR-PHA-018' => %w[patient_return_ledger_posted project_patient_returns patient_return_projection_reconciled return_projection_unique],
    'PAR-PHA-019' => %w[orp_issue_request_authorized post_orp_issue_boundary orp_issue_reconciled orp_charge_single],
    'PAR-PHA-020' => %w[pharmacy_movement_ledger_posted project_pharmacy_stock_card pharmacy_stock_card_reconciled corrections_linked],
    'PAR-PWH-001' => %w[medicine_version_draft validate_lot_unit_mapping medicine_version_effective lot_unit_mapping_pinned],
    'PAR-PWH-002' => %w[warehouse_receipt_variant_one_authorized post_supplier_receipt_variant_one receipt_variant_one_reconciled supplier_lot_value_bound],
    'PAR-PWH-003' => %w[warehouse_transfer_authorized post_paired_warehouse_transfer warehouse_transfer_reconciled source_destination_balanced],
    'PAR-PWH-004' => %w[warehouse_count_period_open post_periodic_count_compensation warehouse_count_period_closed variance_compensated],
    'PAR-PWH-005' => %w[supplier_return_authorized post_supplier_return supplier_return_reconciled source_receipt_linked],
    'PAR-PWH-006' => %w[warehouse_unit_return_authorized post_paired_warehouse_unit_return warehouse_unit_return_reconciled return_origin_bound],
    'PAR-PWH-007' => %w[warehouse_stock_ledger_posted project_warehouse_stock_position warehouse_stock_projection_reconciled projection_equals_ledger],
    'PAR-PWH-008' => %w[warehouse_transfer_ledger_posted project_warehouse_distribution warehouse_distribution_projection_reconciled transfer_projection_unique],
    'PAR-PWH-009' => %w[warehouse_receipt_ledger_posted project_warehouse_receipts warehouse_receipt_projection_reconciled receipt_projection_unique],
    'PAR-PWH-010' => %w[warehouse_movement_ledger_posted project_warehouse_perpetual warehouse_perpetual_reconciled corrections_linked],
    'PAR-PWH-011' => %w[warehouse_movement_ledger_posted project_warehouse_stock_card warehouse_stock_card_reconciled corrections_linked],
    'PAR-PWH-012' => %w[warehouse_receipt_variant_two_authorized post_supplier_receipt_variant_two receipt_variant_two_reconciled variant_equivalence_pending],
    'PAR-PWH-013' => %w[lot_expiry_evaluated quarantine_expired_lot expired_lot_quarantined no_expired_issue],
    'PAR-PWH-014' => %w[posted_ledger_event request_append_only_correction compensating_event_reconciled prior_event_immutable],
    'PAR-PWH-015' => %w[stocktake_ledger_posted project_stocktake_report stocktake_report_reconciled period_bound],
    'PAR-PWH-016' => %w[inter_depot_transfer_authorized post_paired_inter_depot_transfer inter_depot_transfer_reconciled source_destination_balanced],
    'PAR-PWH-017' => %w[purchase_request_approved authorize_purchase_order purchase_order_issued supplier_approval_bound],
    'PAR-PWH-018' => %w[buffer_ledger_posted project_buffer_stock_report buffer_report_reconciled cutoff_bound],
    'PAR-PWH-019' => %w[item_movement_ledger_posted project_item_perpetual_report item_perpetual_report_reconciled item_totals_balanced],
    'PAR-PWH-020' => %w[item_movement_ledger_posted project_item_perpetual_view item_perpetual_view_reconciled item_totals_balanced],
    'PAR-PWH-021' => %w[buffer_threshold_effective calculate_replenishment_order replenishment_order_authorized duplicate_order_prevented],
    'PAR-PWH-022' => %w[blood_unit_compatible reserve_compatible_blood_unit blood_unit_custody_reconciled compatibility_custody_preserved],
    'PAR-PWH-023' => %w[initial_count_not_recorded post_initial_count_baseline initial_count_baseline_closed baseline_posted_once]
  }.transform_values do |values|
    { pre_state: values[0].freeze, transition: values[1].freeze, post_state: values[2].freeze, assertion: values[3].freeze }.freeze
  end.freeze
  BATCH_E_CONSOLIDATION_GROUPS = {
    'E-C01' => %w[PAR-PHA-001 PAR-PHA-002 PAR-PHA-003 PAR-PHA-004],
    'E-C02' => %w[PAR-PHA-011 PAR-PHA-012 PAR-PHA-013 PAR-PHA-019],
    'E-C03' => %w[PAR-PHA-005 PAR-PWH-003 PAR-PWH-016],
    'E-C04' => %w[PAR-PHA-014 PAR-PWH-006],
    'E-C05' => %w[PAR-PHA-006 PAR-PWH-004 PAR-PWH-023],
    'E-C06' => %w[PAR-PHA-007 PAR-PWH-007],
    'E-C07' => %w[PAR-PHA-008 PAR-PWH-008],
    'E-C08' => %w[PAR-PHA-009 PAR-PWH-009],
    'E-C09' => %w[PAR-PHA-020 PAR-PWH-010 PAR-PWH-011 PAR-PWH-019 PAR-PWH-020],
    'E-C10' => %w[PAR-PWH-002 PAR-PWH-012],
    'E-C11' => %w[PAR-PWH-014]
  }.transform_values(&:freeze).freeze
  BATCH_E_CONSOLIDATION_MAPPING_CONTRACTS = {
    'E-C01' => { fields: %w[legacy_requirement_id dispensing_setting encounter_context prescription_id item lot expiry quantity charge_reference idempotency_key], states: %w[prescribed authorized dispensed returned cancelled reconciled] },
    'E-C02' => { fields: %w[legacy_requirement_id issue_context request_id source_location destination_location item lot expiry quantity charge_reference idempotency_key], states: %w[requested authorized issued returned cancelled reconciled] },
    'E-C03' => { fields: %w[legacy_requirement_id transfer_id source_location destination_location item lot expiry quantity valuation period idempotency_key], states: %w[requested authorized source_posted destination_posted received returned cancelled reconciled] },
    'E-C04' => { fields: %w[legacy_requirement_id return_id source_location destination_location item lot expiry quantity reason period idempotency_key], states: %w[requested authorized returned received rejected cancelled reconciled] },
    'E-C05' => { fields: %w[legacy_requirement_id count_mode count_id location item lot expiry book_quantity counted_quantity variance_quantity period idempotency_key], states: %w[opened counted variance_reviewed compensated closed] },
    'E-C06' => { fields: %w[legacy_requirement_id projection_id location item lot expiry quantity period ledger_event_id], states: %w[ledger_posted projected reconciled] },
    'E-C07' => { fields: %w[legacy_requirement_id projection_id source_location destination_location item lot expiry quantity period ledger_event_id], states: %w[ledger_posted projected reconciled] },
    'E-C08' => { fields: %w[legacy_requirement_id projection_id receipt_id supplier item lot expiry quantity valuation period ledger_event_id], states: %w[ledger_posted projected reconciled] },
    'E-C09' => { fields: %w[legacy_requirement_id ledger_event_id location item lot expiry quantity valuation period correction_reference idempotency_key], states: %w[posted corrected reversed reconciled] },
    'E-C10' => { fields: %w[legacy_requirement_id receipt_variant purchase_order_id receipt_id supplier item lot expiry quantity valuation period idempotency_key], states: %w[ordered received accepted rejected returned reconciled] },
    'E-C11' => { fields: %w[legacy_requirement_id ledger_event_id correction_reference actor reason period idempotency_key], states: %w[posted corrected reversed reconciled] }
  }.transform_values { |contract| { fields: contract.fetch(:fields).freeze, states: contract.fetch(:states).freeze }.freeze }.freeze
  BATCH_E_LEDGER_INVARIANTS = %w[immutable_ledger append_only_compensation no_edit_or_delete_history no_negative_stock no_duplicate_movement no_duplicate_charge no_one_sided_transfer no_expired_issue no_quarantined_issue idempotent_reconciliation].freeze
  BATCH_E_MOVEMENT_CONTRACT_KEYS = %w[source_destination_pair lot expiry fefo valuation period idempotency reconciliation].freeze
  BATCH_E_FAMILY_MOVEMENT_CONTRACTS = {
    'E1' => [false, false, false, false, false, true, true, true],
    'E2' => [true, true, true, true, true, true, true, true],
    'E3' => [true, true, true, true, true, true, true, true],
    'E4' => [false, true, true, false, true, true, true, true],
    'E5' => [true, true, true, true, true, true, true, true],
    'E6' => [true, true, true, true, true, true, true, true],
    'E7' => [true, true, true, true, true, true, true, true],
    'E8' => [true, true, true, true, true, true, true, true],
    'E9' => [true, true, true, true, true, true, true, true]
  }.transform_values { |values| BATCH_E_MOVEMENT_CONTRACT_KEYS.zip(values).to_h.freeze }.freeze
  BATCH_E_FAMILY_CONTROL_TOTALS = {
    'E1' => %w[active_master_count version_count],
    'E2' => %w[prescribed_quantity dispensed_quantity returned_quantity stock_delta charge_delta],
    'E3' => %w[source_delta destination_delta returned_quantity charge_delta],
    'E4' => %w[opening_balance movement_total closing_balance projection_total],
    'E5' => %w[ordered_quantity received_quantity accepted_quantity rejected_quantity inventory_value ap_value],
    'E6' => %w[reorder_quantity available_quantity quarantined_quantity expired_quantity],
    'E7' => %w[source_delta destination_delta unit_return_quantity],
    'E8' => %w[book_quantity counted_quantity variance_quantity compensation_total closing_balance],
    'E9' => %w[compatible_units reserved_units simulated_issued_units returned_units quarantined_units]
  }.transform_values(&:freeze).freeze
  BATCH_E_FAMILY_RECONCILIATION_EQUATIONS = {
    'E1' => %w[active_master_lte_version_count],
    'E2' => %w[prescribed_gte_dispensed stock_delta_equals_returns_minus_dispense charge_delta_equals_dispense_minus_returns],
    'E3' => %w[source_plus_destination_zero transfer_charge_zero],
    'E4' => %w[opening_plus_movements_equals_closing projection_equals_closing],
    'E5' => %w[accepted_plus_rejected_equals_received received_lte_ordered inventory_value_equals_ap_value],
    'E6' => %w[replenishment_totals_nonnegative],
    'E7' => %w[source_plus_destination_zero unit_return_nonnegative],
    'E8' => %w[book_plus_variance_equals_counted compensation_equals_variance closing_equals_counted],
    'E9' => %w[reserved_lte_compatible issued_returned_quarantined_lte_compatible]
  }.transform_values(&:freeze).freeze
  BATCH_E_LEDGER_KINDS = %w[source_ledger destination_ledger finance_ledger reporting_projection].freeze
  BATCH_E_INTEGRATION_MODES = %w[none non_transmitting_simulation simulated_adapter].freeze
  BATCH_E_PROHIBITED_TARGETS = %w[BPJS SATUSEHAT eRx supplier AP accounting device printer].freeze
  BATCH_E_EVIDENCE_BASES = %w[behavioral_execution signed_operating_procedure reconciled_ledger user_interview structural_capture].freeze
  BATCH_E_RECONCILIATION_ARTIFACT_TYPE = 'g0_batch_e_reconciliation'
  BATCH_E_CONSOLIDATION_ARTIFACT_TYPE = 'g0_batch_e_consolidation_mapping'
  BATCH_E_GATE_ARTIFACT_TYPE = 'g0_batch_e_gate_resolution'
  BATCH_E_GATE_STATUSES = %w[pending resolved deferred].freeze
  BATCH_E_D_INTERFACE_IDS = %w[PAR-PHA-005 PAR-PHA-014 PAR-PHA-019 PAR-PWH-003 PAR-PWH-006 PAR-PWH-016 PAR-PWH-022].freeze
  BATCH_E_GATE_SCOPES = {
    'C' => %w[medication order encounter rmik],
    'D' => %w[orp request issue return charge],
    'F' => %w[charge valuation claim accounting],
    'G' => %w[report projection reconciliation control_total]
  }.transform_values(&:freeze).freeze
  BATCH_E_GATE_AUTHORITIES = { 'C' => 'clinical_governance', 'D' => 'pharmacy', 'F' => 'finance_claims', 'G' => 'reporting' }.freeze
  BATCH_E_RECONCILIATION_STATUSES = %w[pending complete].freeze
  BATCH_E_CONSOLIDATION_STATUSES = %w[pending complete not_applicable].freeze
  BATCH_E_FAMILY_POLICY_KEYS = %w[family_id members lead_authority_domain co_owners inherited_hazards movement_contract reconciliation_control_totals].freeze
  BATCH_E_SCENARIO_KEYS = %w[status data_class description expected_results family_contract row_hazard_assertions lifecycle_pre_state lifecycle_transition lifecycle_post_state capability_assertions preconditions actions assertions].freeze
  BATCH_E_BOUNDARY_KEYS = %w[mode endpoint credential_state outbound_network delivery_state prohibited_targets notes].freeze
  BATCH_E_GATE_KEYS = %w[direction batch scope status resolution defer_authority_domain resolution_reference resolution_artifact_sha256].freeze
  BATCH_E_RECONCILIATION_KEYS = %w[status control_totals receipt_reference receipt_artifact_sha256].freeze
  BATCH_E_CONSOLIDATION_KEYS = %w[candidate_id status terminal_target_requirement_id artifact_reference artifact_sha256].freeze
  BATCH_E_ENTRY_KEYS = %w[requirement_id batch legacy_menu family_id availability_state lead_authority_domain evidence decision affected_domains co_owners downstream_impacts synthetic_scenarios accountable_owner appointment_dependencies gate_authority_appointments dependency_gates integration_boundary ledger_invariants movement_contract reconciliation_contract consolidation_mapping approval row_hazards].freeze
  BATCH_E_REGISTER_KEYS = %w[schema_version register_id batch register_status data_boundary external_integrations source_manifest source_manifest_sha256 source_revision evidence_directory purpose family_policies entries].freeze
  BATCH_E_EVIDENCE_RECORD_KEYS = %w[evidence_class evidence_basis date source reference interpreter confidence artifact_reference artifact_sha256 note].freeze
  BATCH_E_DECISION_KEYS = %w[status canonical_disposition target rationale].freeze
  BATCH_E_TARGET_KEYS = %w[kind reference exclusions].freeze
  BATCH_E_OWNER_KEYS = %w[appointment_status identity authority_domain required_scope appointed_scope appointment_date appointment_reference artifact_sha256].freeze
  BATCH_E_APPOINTMENT_KEYS = %w[authority_domain required_scope status identity date reference artifact_sha256].freeze
  BATCH_E_APPROVAL_KEYS = %w[status identity authority_domain scope date reference artifact_sha256 conditions].freeze
  BATCH_E_GATE_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id subject direction batch scope status resolution identity authority_domain upstream_requirement_id upstream_approval_reference upstream_approval_sha256 upstream_source_id upstream_source_sha256 exclusions date reviewer].freeze
  BATCH_E_RECONCILIATION_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id family_id synthetic_only period_start period_end cutoff_at event_count ledger_receipts control_totals control_values equations differences idempotency_key date author_identity reviewer].freeze
  BATCH_E_LEDGER_RECEIPT_ARTIFACT_TYPE = 'g0_batch_e_ledger_receipt'
  BATCH_E_LEDGER_RECEIPT_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id ledger_kind synthetic_only period_start period_end cutoff_at event_count control_values ledger_digest idempotency_key date author_identity reviewer].freeze
  BATCH_E_CONSOLIDATION_ARTIFACT_KEYS = %w[artifact_type schema_version register_id candidate_id members target_requirement_id member_impacts mapped_fields mapped_states exclusions date author_identity reviewer].freeze
  BATCH_F_FAMILY_MEMBERS = {
    'F1' => %w[PAR-ADM-011 PAR-ADM-016 PAR-ADM-018 PAR-ADM-019 PAR-ADM-039],
    'F2' => %w[PAR-FIN-001 PAR-FIN-002 PAR-FIN-003 PAR-FIN-004 PAR-FIN-005 PAR-FIN-006 PAR-FIN-007 PAR-FIN-008 PAR-FIN-009 PAR-FIN-010 PAR-FIN-013 PAR-FIN-014 PAR-FIN-016 PAR-FIN-018 PAR-FIN-019],
    'F3' => %w[PAR-FIN-011 PAR-FIN-012 PAR-FIN-015 PAR-FIN-017],
    'F4' => %w[PAR-RMIK-003 PAR-CLM-001 PAR-CLM-002 PAR-CLM-003 PAR-CLM-004 PAR-CLM-005 PAR-CLM-006],
    'F5' => %w[PAR-RMIK-005 PAR-BPJS-001 PAR-BPJS-002]
  }.transform_values(&:freeze).freeze
  BATCH_F_FAMILY_AUTHORITIES = {
    'F1' => { lead: 'finance_master', co_owners: %w[product_delivery finance_master cashier_revenue finance_accounting rmik_coding claims_simulation pharmacy_gf security_privacy_data] },
    'F2' => { lead: 'cashier_revenue', co_owners: %w[product_delivery cashier_revenue finance_accounting treasury rmik_coding claims_simulation clinical_governance pharmacy_gf security_privacy_data] },
    'F3' => { lead: 'finance_accounting', co_owners: %w[product_delivery finance_accounting treasury cashier_revenue rmik_coding claims_simulation clinical_governance pharmacy_gf security_privacy_data operations_recovery] },
    'F4' => { lead: 'rmik_coding', co_owners: %w[product_delivery rmik_coding claims_simulation clinical_governance cashier_revenue finance_accounting pharmacy_gf security_privacy_data] },
    'F5' => { lead: 'claims_simulation', co_owners: %w[product_delivery claims_simulation rmik_coding clinical_governance finance_accounting interoperability_security security_privacy_data] }
  }.transform_values { |policy| { lead: policy.fetch(:lead).freeze, co_owners: policy.fetch(:co_owners).freeze }.freeze }.freeze
  BATCH_F_FAMILY_HAZARDS = {
    'F1' => %w[overlapping_effective_dates destructive_master_edit unauthorized_tariff_change orphan_cost_component target_used_as_actual_revenue],
    'F2' => %w[duplicate_charge bill_version_overwrite projection_writeback report_dimension_double_count unapproved_adjustment],
    'F3' => %w[payment_without_allocation settlement_without_receipt duplicate_journal unbalanced_journal payment_reversal_without_ar_reopen closed_period_mutation],
    'F4' => %w[claim_without_final_encounter claim_after_unapproved_coding mutable_claim_snapshot monitor_used_as_financial_truth duplicate_claim_version],
    'F5' => %w[live_national_submission credential_presence ambiguous_ack_duplicate_send integration_monitor_used_as_truth unsupported_external_response]
  }.transform_values(&:freeze).freeze
  BATCH_F_CROSS_HAZARDS = %w[
    e_dispense_return_charge_duplication d_procedure_correction_without_reversal
    b_payer_class_change_after_bill_snapshot c_coding_amendment_after_claim_submission
    monitor_used_as_financial_truth report_projection_double_count duplicate_journal_generation
    payment_reversal_without_ar_reopen closed_period_correction_mutation
  ].freeze
  BATCH_F_ROW_HAZARDS = {
    'PAR-ADM-011' => %w[tariff_overlap_or_unapproved_version], 'PAR-ADM-016' => %w[bank_master_or_account_mapping_ambiguity],
    'PAR-ADM-018' => %w[cost_component_group_member_loss], 'PAR-ADM-019' => %w[cost_component_orphan_or_duplicate],
    'PAR-ADM-039' => %w[revenue_target_misrepresented_as_actual],
    'PAR-BPJS-001' => %w[outpatient_vclaim_live_send_or_duplicate_ack], 'PAR-BPJS-002' => %w[inpatient_vclaim_live_send_or_duplicate_ack],
    'PAR-CLM-001' => %w[outpatient_grouping_snapshot_or_version_drift], 'PAR-CLM-002' => %w[inpatient_grouping_snapshot_or_version_drift],
    'PAR-CLM-003' => %w[outpatient_idrg_mapping_equivalence_unproven], 'PAR-CLM-004' => %w[inpatient_idrg_mapping_equivalence_unproven],
    'PAR-CLM-005' => %w[inpatient_ceiling_estimate_used_as_approved_claim], 'PAR-CLM-006' => %w[claim_monitor_relocation_equivalence_unproven],
    'PAR-FIN-001' => %w[outpatient_bill_wrong_encounter_or_payer], 'PAR-FIN-002' => %w[inpatient_bill_wrong_admission_or_payer],
    'PAR-FIN-003' => %w[other_transaction_untyped_or_duplicate], 'PAR-FIN-004' => %w[outpatient_detail_projection_writeback],
    'PAR-FIN-005' => %w[inpatient_detail_projection_writeback], 'PAR-FIN-006' => %w[overall_revenue_partition_double_count],
    'PAR-FIN-007' => %w[inpatient_revenue_partition_overlap], 'PAR-FIN-008' => %w[outpatient_revenue_partition_overlap],
    'PAR-FIN-009' => %w[emergency_revenue_partition_overlap], 'PAR-FIN-010' => %w[medical_fee_allocation_exceeds_approved_basis],
    'PAR-FIN-011' => %w[receivable_balance_or_ageing_drift], 'PAR-FIN-012' => %w[cashier_settlement_without_treasury_receipt],
    'PAR-FIN-013' => %w[other_revenue_partition_overlap], 'PAR-FIN-014' => %w[unit_revenue_partition_overlap],
    'PAR-FIN-015' => %w[treasury_receipt_without_cashier_handoff], 'PAR-FIN-016' => %w[procedure_revenue_without_d_reversal],
    'PAR-FIN-017' => %w[automatic_journal_duplicate_or_unbalanced], 'PAR-FIN-018' => %w[billing_report_variant_equivalence_unknown],
    'PAR-FIN-019' => %w[billing_report_v2_equivalence_unknown], 'PAR-RMIK-003' => %w[monitor_claim_relocation_equivalence_unproven],
    'PAR-RMIK-005' => %w[satusehat_live_send_or_identity_disclosure]
  }.transform_values(&:freeze).freeze
  BATCH_F_ROW_LIFECYCLES = {
    'PAR-ADM-011' => %w[tariff_version_draft authorize_effective_dated_tariff tariff_version_effective one_tariff_per_context_and_date],
    'PAR-ADM-016' => %w[bank_mapping_draft validate_bank_account_mapping bank_mapping_effective bank_account_mapping_unique],
    'PAR-ADM-018' => %w[component_group_draft validate_group_membership component_group_effective every_component_membership_retained],
    'PAR-ADM-019' => %w[cost_component_draft validate_component_identity cost_component_effective component_code_unique_and_grouped],
    'PAR-ADM-039' => %w[revenue_target_draft authorize_target_period revenue_target_effective target_never_posts_actual_revenue],
    'PAR-BPJS-001' => %w[outpatient_claim_snapshot_ready simulate_vclaim_outpatient_request outpatient_vclaim_simulation_not_sent request_version_idempotent],
    'PAR-BPJS-002' => %w[inpatient_claim_snapshot_ready simulate_vclaim_inpatient_request inpatient_vclaim_simulation_not_sent request_version_idempotent],
    'PAR-CLM-001' => %w[outpatient_encounter_coding_bill_final freeze_and_group_outpatient_claim outpatient_claim_snapshot_versioned grouping_inputs_and_version_immutable],
    'PAR-CLM-002' => %w[inpatient_encounter_coding_bill_final freeze_and_group_inpatient_claim inpatient_claim_snapshot_versioned grouping_inputs_and_version_immutable],
    'PAR-CLM-003' => %w[outpatient_claim_snapshot_versioned map_outpatient_idrg_result outpatient_idrg_mapping_versioned source_and_target_lineage_complete],
    'PAR-CLM-004' => %w[inpatient_claim_snapshot_versioned map_inpatient_idrg_result inpatient_idrg_mapping_versioned source_and_target_lineage_complete],
    'PAR-CLM-005' => %w[inpatient_claim_snapshot_versioned calculate_non_authoritative_ceiling inpatient_ceiling_estimate_versioned estimate_never_marks_claim_approved],
    'PAR-CLM-006' => %w[claim_events_posted project_claim_monitor claim_monitor_reconciled monitor_read_only_and_not_financial_truth],
    'PAR-FIN-001' => %w[outpatient_charge_events_posted freeze_outpatient_bill_version outpatient_bill_version_issued bill_equals_versioned_charges_and_adjustments],
    'PAR-FIN-002' => %w[inpatient_charge_events_posted freeze_inpatient_bill_version inpatient_bill_version_issued bill_equals_versioned_charges_and_adjustments],
    'PAR-FIN-003' => %w[typed_other_transaction_authorized append_other_charge_event other_charge_posted one_typed_charge_event],
    'PAR-FIN-004' => %w[outpatient_bill_version_issued project_outpatient_bill_detail outpatient_detail_reconciled projection_read_only_and_line_complete],
    'PAR-FIN-005' => %w[inpatient_bill_version_issued project_inpatient_bill_detail inpatient_detail_reconciled projection_read_only_and_line_complete],
    'PAR-FIN-006' => %w[revenue_partitions_reconciled project_overall_revenue overall_revenue_reconciled overall_equals_disjoint_partitions],
    'PAR-FIN-007' => %w[inpatient_revenue_events_posted project_inpatient_revenue inpatient_revenue_reconciled inpatient_partition_disjoint],
    'PAR-FIN-008' => %w[outpatient_revenue_events_posted project_outpatient_revenue outpatient_revenue_reconciled outpatient_partition_disjoint],
    'PAR-FIN-009' => %w[emergency_revenue_events_posted project_emergency_revenue emergency_revenue_reconciled emergency_partition_disjoint],
    'PAR-FIN-010' => %w[approved_fee_basis_collected allocate_medical_fee_recipients medical_fee_allocation_posted recipient_sum_within_approved_basis],
    'PAR-FIN-011' => %w[bill_version_issued open_or_adjust_receivable receivable_balance_reconciled closing_ar_matches_subledger],
    'PAR-FIN-012' => %w[cashier_receipts_reconciled handoff_cashier_settlement settlement_batch_handed_off settlement_equals_receipts_less_refunds_reversals],
    'PAR-FIN-013' => %w[other_revenue_events_posted project_other_revenue other_revenue_reconciled other_partition_disjoint],
    'PAR-FIN-014' => %w[unit_revenue_events_posted project_unit_revenue unit_revenue_reconciled unit_partition_disjoint],
    'PAR-FIN-015' => %w[settlement_batch_handed_off accept_treasury_deposit settlement_batch_accepted accepted_deposit_equals_cashier_net_settlement],
    'PAR-FIN-016' => %w[procedure_charge_and_reversal_events_posted project_procedure_revenue procedure_revenue_reconciled cancelled_procedure_not_recognized_twice],
    'PAR-FIN-017' => %w[source_subledger_events_closed simulate_balanced_automatic_journal journal_simulation_not_sent debits_equal_credits_and_source],
    'PAR-FIN-018' => %w[bill_versions_posted project_billing_report_variant_one billing_report_one_reconciled projection_read_only_and_version_visible],
    'PAR-FIN-019' => %w[bill_versions_posted project_billing_report_variant_two billing_report_two_reconciled projection_read_only_and_version_visible],
    'PAR-RMIK-003' => %w[claim_events_posted project_rmik_claim_monitor rmik_claim_monitor_reconciled monitor_read_only_and_not_financial_truth],
    'PAR-RMIK-005' => %w[outpatient_encounter_snapshot_ready simulate_satusehat_outpatient_bundle satusehat_simulation_not_sent patient_data_never_leaves_local_fixture]
  }.transform_values(&:freeze).freeze
  BATCH_F_PROJECTION_IDS = %w[PAR-CLM-006 PAR-FIN-004 PAR-FIN-005 PAR-FIN-006 PAR-FIN-007 PAR-FIN-008 PAR-FIN-009 PAR-FIN-013 PAR-FIN-014 PAR-FIN-016 PAR-FIN-018 PAR-FIN-019 PAR-RMIK-003].freeze
  BATCH_F_LEDGER_OWNERSHIP = {
    'finance_master_ledger' => 'finance_master', 'charge_ledger' => 'cashier_revenue', 'bill_version_ledger' => 'cashier_revenue',
    'payment_settlement_ledger' => 'treasury', 'claim_version_ledger' => 'rmik_coding', 'reversal_adjustment_ledger' => 'finance_accounting',
    'receivable_ledger' => 'finance_accounting', 'medical_fee_allocation_ledger' => 'finance_accounting',
    'journal_ledger' => 'finance_accounting', 'integration_simulation_ledger' => 'claims_simulation', 'reporting_projection' => nil
  }.freeze
  BATCH_F_ROW_WRITE_LEDGERS = EXPECTED_BATCH_F_IDS.to_h do |id|
    ledgers = if id.start_with?('PAR-ADM-')
                %w[finance_master_ledger]
              elsif %w[PAR-FIN-001 PAR-FIN-002 PAR-FIN-003].include?(id)
                %w[charge_ledger bill_version_ledger]
              elsif id == 'PAR-FIN-010'
                %w[medical_fee_allocation_ledger]
              elsif id == 'PAR-FIN-011'
                %w[receivable_ledger reversal_adjustment_ledger]
              elsif %w[PAR-FIN-012 PAR-FIN-015].include?(id)
                %w[payment_settlement_ledger reversal_adjustment_ledger]
              elsif id == 'PAR-FIN-017'
                %w[journal_ledger]
              elsif %w[PAR-CLM-001 PAR-CLM-002 PAR-CLM-003 PAR-CLM-004 PAR-CLM-005].include?(id)
                %w[claim_version_ledger]
              elsif %w[PAR-BPJS-001 PAR-BPJS-002 PAR-RMIK-005].include?(id)
                %w[integration_simulation_ledger]
              else
                []
              end
    [id, ledgers.freeze]
  end.freeze
  BATCH_F_LEDGER_INVARIANTS = %w[single_write_owner immutable_append_only_events versioned_bill_snapshot versioned_claim_snapshot compensating_reversal_or_adjustment no_edit_or_delete_history no_duplicate_charge no_duplicate_receipt no_duplicate_claim balanced_journal idempotent_posting closed_period_immutable projections_read_only partial_failure_visible].freeze
  BATCH_F_CONTROL_VALUE_KEYS = %w[
    active_master_count effective_version_count invalid_overlap_count correction_event_count
    gross_charge_total approved_discount_total tax_fee_total debit_adjustment_total credit_adjustment_total reversal_total net_bill_total
    opening_ar_total net_billed_total payment_total payer_remittance_total writeoff_total closing_ar_total
    receipt_total refund_total reversed_receipt_total net_settlement_total accepted_deposit_total
    eligible_bill_snapshot_total submitted_claim_total accepted_claim_amount remitted_claim_amount denied_claim_amount pending_claim_amount reversed_claim_amount
    submitted_claim_count accepted_claim_count remitted_claim_count denied_claim_count pending_claim_count reversed_claim_count
    journal_debit_total journal_credit_total journal_source_total medical_fee_approved_basis medical_fee_recipient_sum
    revenue_overall_total revenue_inpatient_total revenue_outpatient_total revenue_emergency_total revenue_other_total revenue_unit_total revenue_procedure_total
    request_count not_sent_count duplicate_request_count
  ].freeze
  BATCH_F_RECONCILIATION_EQUATIONS = [
    'invalid_overlap_count=0',
    'gross_charge_total-approved_discount_total+tax_fee_total+debit_adjustment_total-credit_adjustment_total-reversal_total=net_bill_total',
    'opening_ar_total+net_billed_total-payment_total-payer_remittance_total-writeoff_total+debit_adjustment_total-credit_adjustment_total=closing_ar_total',
    'receipt_total-refund_total-reversed_receipt_total=net_settlement_total', 'net_settlement_total=accepted_deposit_total',
    'submitted_claim_total=eligible_bill_snapshot_total',
    'accepted_claim_amount+remitted_claim_amount+denied_claim_amount+pending_claim_amount+reversed_claim_amount=submitted_claim_total',
    'accepted_claim_count+remitted_claim_count+denied_claim_count+pending_claim_count+reversed_claim_count=submitted_claim_count',
    'journal_debit_total=journal_credit_total', 'journal_source_total=journal_debit_total',
    'medical_fee_recipient_sum<=medical_fee_approved_basis',
    'revenue_inpatient_total+revenue_outpatient_total+revenue_emergency_total+revenue_other_total+revenue_unit_total+revenue_procedure_total=revenue_overall_total',
    'request_count=not_sent_count', 'duplicate_request_count=0'
  ].freeze
  BATCH_F_DIFFERENCE_KEYS = %w[master_overlap_difference bill_difference ar_difference settlement_difference deposit_difference claim_snapshot_difference claim_amount_cohort_difference claim_count_cohort_difference journal_balance_difference journal_source_difference fee_basis_excess revenue_partition_difference not_sent_difference duplicate_request_difference].freeze
  BATCH_F_RECONCILIATION_PROFILES = {
    'master_version' => { control_totals: %w[active_master_count effective_version_count invalid_overlap_count correction_event_count], equations: ['invalid_overlap_count=0'], ledgers: %w[finance_master_ledger] },
    'bill_version' => { control_totals: %w[gross_charge_total approved_discount_total tax_fee_total debit_adjustment_total credit_adjustment_total reversal_total net_bill_total], equations: [BATCH_F_RECONCILIATION_EQUATIONS[1]], ledgers: %w[charge_ledger bill_version_ledger reversal_adjustment_ledger reporting_projection] },
    'revenue_projection' => { control_totals: %w[revenue_overall_total revenue_inpatient_total revenue_outpatient_total revenue_emergency_total revenue_other_total revenue_unit_total revenue_procedure_total], equations: [BATCH_F_RECONCILIATION_EQUATIONS[11]], ledgers: %w[charge_ledger bill_version_ledger reversal_adjustment_ledger reporting_projection] },
    'medical_fee' => { control_totals: %w[medical_fee_approved_basis medical_fee_recipient_sum], equations: [BATCH_F_RECONCILIATION_EQUATIONS[10]], ledgers: %w[medical_fee_allocation_ledger reporting_projection] },
    'receivable' => { control_totals: %w[opening_ar_total net_billed_total payment_total payer_remittance_total writeoff_total debit_adjustment_total credit_adjustment_total closing_ar_total], equations: [BATCH_F_RECONCILIATION_EQUATIONS[2]], ledgers: %w[bill_version_ledger receivable_ledger payment_settlement_ledger reversal_adjustment_ledger reporting_projection] },
    'settlement' => { control_totals: %w[receipt_total refund_total reversed_receipt_total net_settlement_total accepted_deposit_total], equations: BATCH_F_RECONCILIATION_EQUATIONS.values_at(3, 4), ledgers: %w[payment_settlement_ledger reversal_adjustment_ledger reporting_projection] },
    'journal' => { control_totals: %w[journal_debit_total journal_credit_total journal_source_total], equations: BATCH_F_RECONCILIATION_EQUATIONS.values_at(8, 9), ledgers: %w[bill_version_ledger reversal_adjustment_ledger journal_ledger reporting_projection] },
    'claim_snapshot' => { control_totals: %w[eligible_bill_snapshot_total submitted_claim_total accepted_claim_amount remitted_claim_amount denied_claim_amount pending_claim_amount reversed_claim_amount submitted_claim_count accepted_claim_count remitted_claim_count denied_claim_count pending_claim_count reversed_claim_count], equations: BATCH_F_RECONCILIATION_EQUATIONS.values_at(5, 6, 7), ledgers: %w[bill_version_ledger claim_version_ledger reversal_adjustment_ledger reporting_projection] },
    'claim_boundary' => { control_totals: %w[eligible_bill_snapshot_total submitted_claim_total accepted_claim_amount remitted_claim_amount denied_claim_amount pending_claim_amount reversed_claim_amount submitted_claim_count accepted_claim_count remitted_claim_count denied_claim_count pending_claim_count reversed_claim_count request_count not_sent_count duplicate_request_count], equations: [*BATCH_F_RECONCILIATION_EQUATIONS.values_at(5, 6, 7), *BATCH_F_RECONCILIATION_EQUATIONS.values_at(12, 13)], ledgers: %w[bill_version_ledger claim_version_ledger integration_simulation_ledger reporting_projection] },
    'integration_boundary' => { control_totals: %w[request_count not_sent_count duplicate_request_count], equations: BATCH_F_RECONCILIATION_EQUATIONS.values_at(12, 13), ledgers: %w[integration_simulation_ledger reporting_projection] }
  }.transform_values { |profile| profile.transform_values(&:freeze).freeze }.freeze
  BATCH_F_PROFILE_DIFFERENCE_KEYS = {
    'master_version' => %w[master_overlap_difference], 'bill_version' => %w[bill_difference],
    'revenue_projection' => %w[revenue_partition_difference], 'medical_fee' => %w[fee_basis_excess],
    'receivable' => %w[ar_difference], 'settlement' => %w[settlement_difference deposit_difference],
    'journal' => %w[journal_balance_difference journal_source_difference],
    'claim_snapshot' => %w[claim_snapshot_difference claim_amount_cohort_difference claim_count_cohort_difference],
    'claim_boundary' => %w[claim_snapshot_difference claim_amount_cohort_difference claim_count_cohort_difference not_sent_difference duplicate_request_difference],
    'integration_boundary' => %w[not_sent_difference duplicate_request_difference]
  }.transform_values(&:freeze).freeze
  BATCH_F_ROW_RECONCILIATION_PROFILE = EXPECTED_BATCH_F_IDS.to_h do |id|
    profile = if id.start_with?('PAR-ADM-')
                'master_version'
              elsif %w[PAR-FIN-001 PAR-FIN-002 PAR-FIN-003 PAR-FIN-004 PAR-FIN-005 PAR-FIN-018 PAR-FIN-019].include?(id)
                'bill_version'
              elsif %w[PAR-FIN-006 PAR-FIN-007 PAR-FIN-008 PAR-FIN-009 PAR-FIN-013 PAR-FIN-014 PAR-FIN-016].include?(id)
                'revenue_projection'
              elsif id == 'PAR-FIN-010'
                'medical_fee'
              elsif id == 'PAR-FIN-011'
                'receivable'
              elsif %w[PAR-FIN-012 PAR-FIN-015].include?(id)
                'settlement'
              elsif id == 'PAR-FIN-017'
                'journal'
              elsif %w[PAR-BPJS-001 PAR-BPJS-002].include?(id)
                'claim_boundary'
              elsif id == 'PAR-RMIK-005'
                'integration_boundary'
              else
                'claim_snapshot'
              end
    [id, profile]
  end.freeze
  BATCH_F_CONSOLIDATION_GROUPS = {
    'F-C01' => %w[PAR-ADM-018 PAR-ADM-019], 'F-C02' => %w[PAR-FIN-001 PAR-FIN-002], 'F-C03' => %w[PAR-FIN-004 PAR-FIN-005],
    'F-C04' => %w[PAR-FIN-006 PAR-FIN-007 PAR-FIN-008 PAR-FIN-009 PAR-FIN-013 PAR-FIN-014 PAR-FIN-016],
    'F-C05' => %w[PAR-FIN-018 PAR-FIN-019], 'F-C06' => %w[PAR-FIN-012 PAR-FIN-015],
    'F-C07' => %w[PAR-CLM-001 PAR-CLM-003], 'F-C08' => %w[PAR-CLM-002 PAR-CLM-004],
    'F-C09' => %w[PAR-RMIK-003 PAR-CLM-006], 'F-C10' => %w[PAR-BPJS-001 PAR-BPJS-002]
  }.transform_values(&:freeze).freeze
  BATCH_F_CONSOLIDATION_MAPPING_CONTRACTS = BATCH_F_CONSOLIDATION_GROUPS.to_h do |candidate, members|
    family = BATCH_F_FAMILY_MEMBERS.find { |_family, family_members| family_members.include?(members.first) }&.first
    fields = %w[requirement_id source_menu role context identity version status effective_period amounts currency event_lineage authority audit]
    states = members.flat_map { |member| BATCH_F_ROW_LIFECYCLES.fetch(member).values_at(0, 2) }.uniq
    totals = members.flat_map do |member|
      profile_id = BATCH_F_ROW_RECONCILIATION_PROFILE.fetch(member)
      BATCH_F_RECONCILIATION_PROFILES.fetch(profile_id).fetch(:control_totals)
    end.uniq
    [candidate, { family: family, fields: fields.freeze, states: states.freeze, control_totals: totals.freeze }.freeze]
  end.freeze
  BATCH_F_GATE_SCOPES = {
    'A' => %w[identity access audit configuration], 'B' => %w[patient encounter admission payer_class],
    'C' => %w[clinical_record coding amendment authorization], 'D' => %w[order result procedure cancellation],
    'E' => %w[medication stock valuation charge_credit], 'G' => %w[report projection reconciliation export]
  }.transform_values(&:freeze).freeze
  BATCH_F_GATE_PROFILES = {
    'master_basic' => { 'A' => %w[PAR-ADM-003 PAR-ADM-037], 'G' => [] },
    'tariff' => { 'A' => %w[PAR-ADM-003 PAR-ADM-037], 'B' => %w[PAR-ADM-010 PAR-ADM-033], 'G' => [] },
    'billing' => {
      'A' => %w[PAR-ADM-003 PAR-ADM-037], 'B' => %w[PAR-ADM-010 PAR-ADM-033 PAR-REG-001 PAR-REG-002 PAR-REG-003],
      'C' => %w[PAR-CLN-004 PAR-CLN-005 PAR-RMIK-001 PAR-RMIK-002], 'D' => %w[PAR-CLN-006 PAR-CLN-007 PAR-CLN-009 PAR-ORP-001],
      'E' => %w[PAR-PHA-002 PAR-PHA-003 PAR-PHA-020 PAR-PWH-014], 'G' => []
    },
    'medical_fee' => {
      'A' => %w[PAR-ADM-003 PAR-ADM-037], 'B' => %w[PAR-ADM-010 PAR-ADM-033 PAR-REG-001 PAR-REG-002 PAR-REG-003],
      'C' => %w[PAR-CLN-004 PAR-CLN-005 PAR-RMIK-001 PAR-RMIK-002], 'D' => %w[PAR-CLN-006 PAR-CLN-007 PAR-CLN-009], 'G' => []
    },
    'finance_handoff' => { 'A' => %w[PAR-ADM-003 PAR-ADM-037], 'B' => %w[PAR-ADM-010 PAR-ADM-033 PAR-REG-001 PAR-REG-002 PAR-REG-003], 'G' => [] },
    'claims' => {
      'A' => %w[PAR-ADM-003 PAR-ADM-037], 'B' => %w[PAR-ADM-010 PAR-ADM-033 PAR-REG-001 PAR-REG-002 PAR-REG-003],
      'C' => %w[PAR-CLN-004 PAR-CLN-005 PAR-RMIK-001 PAR-RMIK-002], 'D' => %w[PAR-CLN-006 PAR-CLN-007 PAR-CLN-009 PAR-ORP-001],
      'E' => %w[PAR-PHA-002 PAR-PHA-003 PAR-PHA-020 PAR-PWH-014], 'G' => []
    },
    'monitor' => { 'A' => %w[PAR-ADM-003 PAR-ADM-037], 'G' => [] },
    'bpjs_boundary' => {
      'A' => %w[PAR-ADM-003 PAR-ADM-037], 'B' => %w[PAR-ADM-010 PAR-ADM-033 PAR-REG-001 PAR-REG-002 PAR-REG-003],
      'C' => %w[PAR-CLN-004 PAR-CLN-005 PAR-RMIK-001 PAR-RMIK-002], 'G' => []
    },
    'satusehat_boundary' => { 'A' => %w[PAR-ADM-003 PAR-ADM-037], 'B' => %w[PAR-REG-003], 'C' => %w[PAR-CLN-004 PAR-RMIK-001], 'G' => [] }
  }.transform_values { |profile| profile.transform_values(&:freeze).freeze }.freeze
  BATCH_F_ROW_GATE_PROFILE = EXPECTED_BATCH_F_IDS.to_h do |id|
    profile = if id == 'PAR-ADM-011' then 'tariff'
              elsif id.start_with?('PAR-ADM-') then 'master_basic'
              elsif id == 'PAR-FIN-010' then 'medical_fee'
              elsif %w[PAR-FIN-011 PAR-FIN-012 PAR-FIN-015 PAR-FIN-017].include?(id) then 'finance_handoff'
              elsif id == 'PAR-RMIK-003' || id == 'PAR-CLM-006' then 'monitor'
              elsif id.start_with?('PAR-CLM-') then 'claims'
              elsif id.start_with?('PAR-BPJS-') then 'bpjs_boundary'
              elsif id == 'PAR-RMIK-005' then 'satusehat_boundary'
              else 'billing'
              end
    [id, profile]
  end.freeze
  BATCH_F_INTRA_BATCH_DEPENDENCIES = {
    'PAR-ADM-011' => [], 'PAR-ADM-016' => [], 'PAR-ADM-018' => [], 'PAR-ADM-019' => %w[PAR-ADM-018], 'PAR-ADM-039' => [],
    'PAR-BPJS-001' => %w[PAR-CLM-001], 'PAR-BPJS-002' => %w[PAR-CLM-002],
    'PAR-CLM-001' => %w[PAR-ADM-011 PAR-FIN-001], 'PAR-CLM-002' => %w[PAR-ADM-011 PAR-FIN-002], 'PAR-CLM-003' => %w[PAR-CLM-001],
    'PAR-CLM-004' => %w[PAR-CLM-002], 'PAR-CLM-005' => %w[PAR-CLM-002], 'PAR-CLM-006' => %w[PAR-CLM-001 PAR-CLM-002],
    'PAR-FIN-001' => %w[PAR-ADM-011 PAR-ADM-018 PAR-ADM-019], 'PAR-FIN-002' => %w[PAR-ADM-011 PAR-ADM-018 PAR-ADM-019],
    'PAR-FIN-003' => %w[PAR-ADM-011 PAR-ADM-018 PAR-ADM-019], 'PAR-FIN-004' => %w[PAR-FIN-001], 'PAR-FIN-005' => %w[PAR-FIN-002],
    'PAR-FIN-006' => %w[PAR-FIN-007 PAR-FIN-008 PAR-FIN-009 PAR-FIN-013 PAR-FIN-014 PAR-FIN-016], 'PAR-FIN-007' => %w[PAR-FIN-002],
    'PAR-FIN-008' => %w[PAR-FIN-001], 'PAR-FIN-009' => %w[PAR-FIN-003], 'PAR-FIN-010' => %w[PAR-FIN-001 PAR-FIN-002],
    'PAR-FIN-011' => %w[PAR-FIN-001 PAR-FIN-002 PAR-FIN-003], 'PAR-FIN-012' => %w[PAR-FIN-011], 'PAR-FIN-013' => %w[PAR-FIN-003],
    'PAR-FIN-014' => %w[PAR-FIN-001 PAR-FIN-002 PAR-FIN-003], 'PAR-FIN-015' => %w[PAR-FIN-012], 'PAR-FIN-016' => %w[PAR-FIN-001 PAR-FIN-002],
    'PAR-FIN-017' => %w[PAR-FIN-001 PAR-FIN-002 PAR-FIN-003 PAR-FIN-011 PAR-FIN-012 PAR-FIN-015],
    'PAR-FIN-018' => %w[PAR-FIN-001 PAR-FIN-002], 'PAR-FIN-019' => %w[PAR-FIN-001 PAR-FIN-002],
    'PAR-RMIK-003' => %w[PAR-CLM-001 PAR-CLM-002 PAR-CLM-006], 'PAR-RMIK-005' => []
  }.transform_values(&:freeze).freeze
  BATCH_F_GATE_AUTHORITIES = { 'G' => 'reporting' }.freeze
  BATCH_F_E_DEFERRAL_EXCLUSIONS = %w[no_medication_charge_readiness no_stock_valuation_readiness no_pharmacy_claim_completeness no_live_delivery].freeze
  BATCH_F_G_DEFERRAL_EXCLUSIONS = %w[no_g_readiness no_reporting_export no_financial_truth no_live_delivery].freeze
  BATCH_F_GATE_STATUSES = %w[pending resolved deferred].freeze
  BATCH_F_INTEGRATION_MODES = %w[none non_transmitting_simulation].freeze
  BATCH_F_PROHIBITED_TARGETS = %w[BPJS VClaim Antrol Aplicares E-Klaim iDRG SATUSEHAT payment_bank accounting_ERP live_endpoint device printer].freeze
  BATCH_F_EVIDENCE_BASES = %w[behavioral_execution signed_finance_policy reconciled_ledger integration_sandbox_result user_interview structural_capture].freeze
  BATCH_G_EVIDENCE_BASES = %w[behavioral_execution signed_reporting_policy reconciled_ledger independent_output_verification user_interview structural_capture].freeze
  BATCH_F_REGISTER_KEYS = %w[schema_version register_id batch register_status data_boundary external_integrations source_manifest source_manifest_sha256 source_revision evidence_directory purpose availability_state family_policies ledger_ownership entries].freeze
  BATCH_F_FAMILY_POLICY_KEYS = %w[family_id members lead_authority_domain co_owners inherited_hazards].freeze
  BATCH_F_ENTRY_KEYS = %w[requirement_id batch legacy_menu family_id availability_state lead_authority_domain lifecycle_contract evidence decision affected_domains co_owners downstream_impacts synthetic_scenarios accountable_owner appointment_dependencies gate_authority_appointments dependency_gates intra_batch_dependencies integration_boundary ledger_invariants write_contract reconciliation_contract consolidation_mapping approval row_hazards].freeze
  BATCH_F_EVIDENCE_RECORD_KEYS = BATCH_E_EVIDENCE_RECORD_KEYS
  BATCH_F_DECISION_KEYS = BATCH_E_DECISION_KEYS
  BATCH_F_TARGET_KEYS = BATCH_E_TARGET_KEYS
  BATCH_F_OWNER_KEYS = BATCH_E_OWNER_KEYS
  BATCH_F_APPOINTMENT_KEYS = BATCH_E_APPOINTMENT_KEYS
  BATCH_F_APPROVAL_KEYS = BATCH_E_APPROVAL_KEYS
  BATCH_F_LIFECYCLE_KEYS = %w[pre_state transition post_state assertion].freeze
  BATCH_F_SCENARIO_KEYS = %w[status data_class description expected_results contract_ref].freeze
  BATCH_F_BOUNDARY_KEYS = %w[mode endpoint credential_state outbound_network delivery_state prohibited_targets transport_result notes].freeze
  BATCH_F_WRITE_CONTRACT_KEYS = %w[owned_ledgers projection_write_access allowed_event_operations prohibited_mutations].freeze
  BATCH_F_GATE_KEYS = %w[direction batch scope source_requirement_ids status resolution defer_authority_domain resolution_reference resolution_artifact_sha256].freeze
  BATCH_F_INTRA_DEPENDENCY_KEYS = %w[requirement_id scope status resolution_reference resolution_artifact_sha256].freeze
  BATCH_F_RECONCILIATION_KEYS = %w[status profile_id currency minor_unit period_timezone late_posting_policy control_totals equations receipt_reference receipt_artifact_sha256].freeze
  BATCH_F_CONSOLIDATION_KEYS = BATCH_E_CONSOLIDATION_KEYS
  BATCH_F_GATE_ARTIFACT_TYPE = 'g0_batch_f_gate_resolution'
  BATCH_F_GATE_DEFERRAL_APPROVAL_ARTIFACT_TYPE = 'g0_batch_f_gate_deferral_approval'
  BATCH_F_INTRA_DEPENDENCY_ARTIFACT_TYPE = 'g0_batch_f_intra_dependency_resolution'
  BATCH_F_RECONCILIATION_ARTIFACT_TYPE = 'g0_batch_f_reconciliation'
  BATCH_F_LEDGER_RECEIPT_ARTIFACT_TYPE = 'g0_batch_f_ledger_receipt'
  BATCH_F_CONSOLIDATION_ARTIFACT_TYPE = 'g0_batch_f_consolidation_mapping'
  BATCH_F_GATE_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id subject direction batch scope status resolution identity authority_domain source_register_id source_register_sha256 source_bindings deferral_bindings exclusions date reviewer].freeze
  BATCH_F_GATE_SOURCE_BINDING_KEYS = %w[requirement_id lead_authority_domain owner_identity approval_reference approval_sha256].freeze
  BATCH_F_GATE_DEFERRAL_BINDING_KEYS = %w[authority_domain identity appointment_reference appointment_sha256 approval_reference approval_sha256].freeze
  BATCH_F_GATE_DEFERRAL_APPROVAL_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id subject batch scope status resolution exclusions identity authority_domain date reviewer].freeze
  BATCH_F_INTRA_DEPENDENCY_ARTIFACT_KEYS = %w[artifact_type schema_version register_id source_requirement_id target_requirement_id scope status identity authority_domain target_decision_status target_disposition target_approval_reference target_approval_sha256 date reviewer].freeze
  BATCH_F_RECONCILIATION_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id family_id profile_id synthetic_only currency minor_unit period_start period_end period_timezone cutoff_at late_posting_policy event_count ledger_receipts control_values equations differences idempotency_key date author_identity reviewer].freeze
  BATCH_F_LEDGER_RECEIPT_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id profile_id ledger_kind synthetic_only currency minor_unit period_start period_end period_timezone cutoff_at late_posting_policy event_count control_values ledger_digest idempotency_key date author_identity reviewer].freeze
  BATCH_F_CONSOLIDATION_ARTIFACT_KEYS = %w[artifact_type schema_version register_id candidate_id members target_requirement_id terminal_owner_identity terminal_authority_domain terminal_approval_reference terminal_approval_sha256 member_impacts mapped_fields mapped_states mapped_control_totals lineage_preserved authorities_preserved exclusions date author_identity reviewer].freeze

  BATCH_G_FAMILY_MEMBERS = {
    'G1' => [*%w[PAR-RPT-005 PAR-RPT-006 PAR-RPT-007 PAR-RPT-008 PAR-RPT-009 PAR-RPT-010 PAR-RPT-011 PAR-RPT-013 PAR-RPT-016 PAR-RPT-017 PAR-RPT-018 PAR-RPT-019 PAR-RPT-020 PAR-RPT-022 PAR-RPT-023 PAR-RPT-024 PAR-RPT-025 PAR-RPT-042 PAR-RPT-050 PAR-RPT-051 PAR-RPT-052], *(96..107).map { |n| format('PAR-RPT-%03d', n) }],
    'G2' => [*(1..4).map { |n| format('PAR-RPT-%03d', n) }, *%w[PAR-RPT-012 PAR-RPT-014 PAR-RPT-015], *(26..40).map { |n| format('PAR-RPT-%03d', n) }, *(46..49).map { |n| format('PAR-RPT-%03d', n) }, *(53..61).map { |n| format('PAR-RPT-%03d', n) }, 'PAR-RPT-117'],
    'G3' => [*%w[PAR-ADM-031 PAR-ADM-035 PAR-RPT-021 PAR-RPT-041 PAR-RPT-043 PAR-RPT-044], *(62..95).map { |n| format('PAR-RPT-%03d', n) }],
    'G4' => [*%w[PAR-ADM-007 PAR-RPT-045], *(108..116).map { |n| format('PAR-RPT-%03d', n) }]
  }.transform_values(&:freeze).freeze
  BATCH_G_FAMILY_POLICY = {
    'G1' => { lead: 'reporting_quality', mandatory: %w[product_delivery reporting_quality security_privacy_data operations facility_bed_management], description: 'patient access, encounter, census and operational flow' },
    'G2' => { lead: 'rmik_reporting', mandatory: %w[product_delivery rmik_reporting security_privacy_data operations quality_patient_safety nursing_governance], description: 'clinical, RMIK, nursing and service quality' },
    'G3' => { lead: 'public_health_reporting', mandatory: %w[product_delivery public_health_reporting statutory_sponsor security_privacy_data operations], description: 'legacy statutory, public-health and special outcome' },
    'G4' => { lead: 'management_reporting', mandatory: %w[product_delivery management_reporting management_target_owner security_privacy_data operations finance_accounting], description: 'management, finance, payer and inpatient outcome' }
  }.transform_values(&:freeze).freeze
  BATCH_G_SOURCE_SPECS = {
    'A_CONFIG' => { batch: 'A', id: 'PAR-ADM-003', entity: 'configuration_version', authority: 'security_privacy_data' },
    'A_AUDIT' => { batch: 'A', id: 'PAR-ADM-037', entity: 'immutable_audit_event', authority: 'security_privacy_data' },
    'B_PAYER' => { batch: 'B', id: 'PAR-ADM-010', entity: 'payer_class_version', authority: 'registration_admission' },
    'B_BED' => { batch: 'B', id: 'PAR-ADM-033', entity: 'admission_bed_state', authority: 'registration_admission' },
    'B_ACTIVE_ADMISSION' => { batch: 'B', id: 'PAR-REG-001', entity: 'active_inpatient_admission_as_of_version', authority: 'registration_admission' },
    'B_DISCHARGE' => { batch: 'B', id: 'PAR-REG-001', entity: 'finalized_inpatient_discharge_state', authority: 'registration_admission' },
    'B_RI' => { batch: 'B', id: 'PAR-REG-001', entity: 'inpatient_encounter_version', authority: 'registration_admission' },
    'B_RJ' => { batch: 'B', id: 'PAR-REG-002', entity: 'outpatient_encounter_version', authority: 'registration_admission' },
    'B_IGD' => { batch: 'B', id: 'PAR-REG-003', entity: 'emergency_encounter_version', authority: 'registration_admission' },
    'B_OUTGOING_REFERRAL' => { batch: 'B', id: 'PAR-REG-003', entity: 'outgoing_referral_encounter_version', authority: 'registration_admission' },
    'C_RJ' => { batch: 'C', id: 'PAR-CLN-004', entity: 'final_outpatient_record_version', authority: 'outpatient_clinical' },
    'C_RI' => { batch: 'C', id: 'PAR-CLN-005', entity: 'final_inpatient_record_version', authority: 'inpatient_clinical' },
    'C_DEATH' => { batch: 'C', id: 'PAR-CLN-005', entity: 'approved_death_disposition_version', authority: 'inpatient_clinical' },
    'C_PROVIDER_RJ' => { batch: 'C', id: 'PAR-CLN-004', entity: 'approved_outpatient_provider_assignment_version', authority: 'outpatient_clinical' },
    'C_PROVIDER_RI' => { batch: 'C', id: 'PAR-CLN-005', entity: 'approved_inpatient_provider_assignment_version', authority: 'inpatient_clinical' },
    'C_DISPOSITION_RI' => { batch: 'C', id: 'PAR-CLN-005', entity: 'approved_discharge_disposition_reason_version', authority: 'inpatient_clinical' },
    'C_REFERRAL' => { batch: 'C', id: 'PAR-CLN-004', entity: 'approved_outgoing_referral_disposition_version', authority: 'outpatient_clinical' },
    'C_RMIK_RJ' => { batch: 'C', id: 'PAR-RMIK-001', entity: 'approved_outpatient_coding_version', authority: 'rmik' },
    'C_RMIK_RI' => { batch: 'C', id: 'PAR-RMIK-002', entity: 'approved_inpatient_coding_version', authority: 'rmik' },
    'D_LAB' => { batch: 'D', id: 'PAR-CLN-006', entity: 'verified_laboratory_result_version', authority: 'laboratory' },
    'D_RAD' => { batch: 'D', id: 'PAR-CLN-007', entity: 'verified_radiology_result_version', authority: 'radiology' },
    'D_SURGERY' => { batch: 'D', id: 'PAR-CLN-009', entity: 'completed_procedure_version', authority: 'surgery_anesthesia' },
    'E_RX' => { batch: 'E', id: 'PAR-PHA-002', entity: 'prescription_order_version', authority: 'pharmacy' },
    'E_DISPENSE' => { batch: 'E', id: 'PAR-PHA-003', entity: 'dispense_return_ledger', authority: 'pharmacy' },
    'F_RJ_BILL' => { batch: 'F', id: 'PAR-FIN-001', entity: 'outpatient_bill_version', authority: 'cashier_revenue' },
    'F_RI_BILL' => { batch: 'F', id: 'PAR-FIN-002', entity: 'inpatient_bill_version', authority: 'cashier_revenue' },
    'F_RJ_CLAIM' => { batch: 'F', id: 'PAR-CLM-001', entity: 'outpatient_claim_version', authority: 'rmik_coding' },
    'F_RI_CLAIM' => { batch: 'F', id: 'PAR-CLM-002', entity: 'inpatient_claim_version', authority: 'rmik_coding' }
  }.transform_values(&:freeze).freeze
  BATCH_G_SEMANTIC_TEMPLATES = {
    'target_master' => { status: 'defined', care_setting: 'outpatient_management', dimension: 'target_definition', grain: 'target_metric_per_period_and_organization_unit', key: 'target_definition_id+effective_period+organization_unit_id+target_metric+target_unit', numerator: 'approved_target_value', denominator: 'not_applicable', parameters: %w[effective_period organization_unit_id target_metric target_unit definition_version], sources: %w[A_CONFIG A_AUDIT], measures: [], reconciliation: false },
    'disease_reference_master' => { status: 'defined', care_setting: 'cross_setting', dimension: 'infectious_disease_reference', grain: 'disease_reference_version', key: 'disease_reference_id+disease_code+version', numerator: 'not_applicable', denominator: 'not_applicable', parameters: %w[effective_date disease_classification terminology_version definition_version], sources: %w[A_CONFIG A_AUDIT], measures: [], reconciliation: false },
    'w2_reference_master' => { status: 'unresolved_owner_definition', care_setting: 'public_health', dimension: 'w2_form_reference', grain: 'w2_form_definition_version_and_field_indicator', key: 'form_definition_id+field_or_indicator_id+version', numerator: 'not_applicable', denominator: 'not_applicable', parameters: %w[standard_identifier standard_version effective_period field_or_indicator definition_version], sources: %w[A_CONFIG A_AUDIT], measures: [], reconciliation: false },
    'outpatient_coded_diagnosis' => { status: 'defined', care_setting: 'outpatient', dimension: 'coded_diagnosis', grain: 'one_row_per_approved_diagnosis_per_outpatient_encounter', key: 'outpatient_encounter_id+approved_diagnosis_id+coding_version', numerator: 'distinct_outpatient_encounters_with_approved_diagnosis', denominator: 'eligible_final_outpatient_encounters', parameters: %w[period_start period_end care_setting diagnosis_group organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RJ C_RJ C_RMIK_RJ], measures: %w[PAR-RMIK-001], reconciliation: true },
    'inpatient_coded_diagnosis' => { status: 'defined', care_setting: 'inpatient', dimension: 'coded_diagnosis', grain: 'one_row_per_approved_diagnosis_per_inpatient_encounter', key: 'inpatient_encounter_id+approved_diagnosis_id+coding_version', numerator: 'distinct_inpatient_encounters_with_approved_diagnosis', denominator: 'eligible_final_inpatient_encounters', parameters: %w[period_start period_end care_setting diagnosis_group organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI C_RI C_RMIK_RI], measures: %w[PAR-RMIK-002], reconciliation: true },
    'outpatient_coded_procedure' => { status: 'defined', care_setting: 'outpatient', dimension: 'coded_procedure', grain: 'one_row_per_approved_procedure_per_outpatient_encounter', key: 'outpatient_encounter_id+approved_procedure_id+coding_version', numerator: 'distinct_outpatient_encounters_with_approved_procedure', denominator: 'eligible_final_outpatient_encounters', parameters: %w[period_start period_end care_setting procedure_group organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RJ C_RJ C_RMIK_RJ], measures: %w[PAR-RMIK-001], reconciliation: true },
    'inpatient_coded_procedure' => { status: 'defined', care_setting: 'inpatient', dimension: 'coded_procedure', grain: 'one_row_per_approved_procedure_per_inpatient_encounter', key: 'inpatient_encounter_id+approved_procedure_id+coding_version', numerator: 'distinct_inpatient_encounters_with_approved_procedure', denominator: 'eligible_final_inpatient_encounters', parameters: %w[period_start period_end care_setting procedure_group organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI C_RI C_RMIK_RI D_SURGERY], measures: %w[PAR-RMIK-002 PAR-CLN-009], reconciliation: true },
    'patient_cohort' => { status: 'defined', care_setting: 'cross_setting', dimension: 'patient_cohort', grain: 'one_row_per_synthetic_patient', key: 'synthetic_patient_id', numerator: 'distinct_synthetic_patients_meeting_declared_cohort', denominator: 'eligible_synthetic_patient_registry', parameters: %w[period_start period_end encounter_scope organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI B_RJ B_IGD], measures: %w[PAR-REG-001 PAR-REG-002 PAR-REG-003], reconciliation: true },
    'outpatient_encounter' => { status: 'defined', care_setting: 'outpatient', dimension: 'encounter_flow', grain: 'one_row_per_outpatient_encounter', key: 'outpatient_encounter_id', numerator: 'distinct_eligible_outpatient_encounters', denominator: 'eligible_outpatient_encounter_snapshot', parameters: %w[period_start period_end care_setting clinic_id payer_class provider_id organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RJ C_RJ], measures: %w[PAR-REG-002], reconciliation: true },
    'inpatient_encounter' => { status: 'defined', care_setting: 'inpatient', dimension: 'encounter_flow', grain: 'one_row_per_inpatient_encounter', key: 'inpatient_encounter_id', numerator: 'distinct_eligible_inpatient_encounters', denominator: 'eligible_inpatient_encounter_snapshot', parameters: %w[period_start period_end care_setting ward_id room_class discharge_disposition organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_BED B_RI C_RI], measures: %w[PAR-REG-001], reconciliation: true },
    'emergency_encounter' => { status: 'defined', care_setting: 'emergency', dimension: 'emergency_flow', grain: 'one_row_per_emergency_encounter', key: 'emergency_encounter_id', numerator: 'distinct_eligible_emergency_encounters', denominator: 'eligible_emergency_encounter_snapshot', parameters: %w[period_start period_end care_setting triage_state disposition payer_class organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_IGD C_RJ], measures: %w[PAR-REG-003], reconciliation: true },
    'prescription_monitor' => { status: 'defined', care_setting: 'cross_setting', dimension: 'prescription_dispense_status', grain: 'one_row_per_prescription_order_version', key: 'prescription_order_id+version', numerator: 'distinct_prescription_orders_by_dispense_return_status', denominator: 'eligible_prescription_orders', parameters: %w[period_start period_end care_setting dispense_status organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI B_RJ B_IGD C_RJ C_RI E_RX E_DISPENSE], measures: %w[PAR-PHA-002 PAR-PHA-003], reconciliation: true },
    'wait_time' => { status: 'defined', care_setting: 'cross_setting', dimension: 'elapsed_time', grain: 'one_row_per_encounter_or_document_milestone_pair', key: 'encounter_or_document_id+start_milestone+end_milestone', numerator: 'sum_elapsed_minutes_for_valid_milestone_pairs', denominator: 'eligible_events_with_both_authoritative_milestones', parameters: %w[period_start period_end care_setting milestone_pair delay_threshold_minutes organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI B_RJ B_IGD C_RJ C_RI], measures: %w[PAR-CLN-004 PAR-CLN-005], reconciliation: true },
    'last_visit' => { status: 'defined', care_setting: 'cross_setting', dimension: 'latest_qualifying_encounter', grain: 'one_row_per_synthetic_patient', key: 'synthetic_patient_id', numerator: 'maximum_qualifying_encounter_event_time', denominator: 'not_applicable', parameters: %w[synthetic_patient_id care_setting lookback_start lookback_end organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI B_RJ B_IGD], measures: %w[PAR-REG-001 PAR-REG-002 PAR-REG-003], reconciliation: true },
    'laboratory_register' => { status: 'defined', care_setting: 'cross_setting', dimension: 'laboratory_order_specimen_result', grain: 'one_row_per_laboratory_order_specimen_result_version', key: 'laboratory_order_id+specimen_id+result_version', numerator: 'distinct_verified_laboratory_results', denominator: 'eligible_laboratory_orders', parameters: %w[period_start period_end care_setting laboratory_service result_status organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI B_RJ B_IGD C_RJ C_RI D_LAB], measures: %w[PAR-CLN-006], reconciliation: true },
    'radiology_register' => { status: 'defined', care_setting: 'cross_setting', dimension: 'radiology_order_result_report', grain: 'one_row_per_radiology_order_report_version', key: 'radiology_order_id+report_version', numerator: 'distinct_verified_radiology_reports', denominator: 'eligible_radiology_orders', parameters: %w[period_start period_end care_setting radiology_service report_status organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI B_RJ B_IGD C_RJ C_RI D_RAD], measures: %w[PAR-CLN-007], reconciliation: true },
    'surgery_register' => { status: 'defined', care_setting: 'inpatient_theatre', dimension: 'surgery_theatre_procedure', grain: 'one_row_per_surgical_procedure_version', key: 'procedure_id+theatre_session_id+procedure_version', numerator: 'distinct_completed_or_cancelled_surgical_procedures_by_state', denominator: 'eligible_surgery_theatre_orders', parameters: %w[period_start period_end care_setting theatre procedure_state organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI C_RI D_SURGERY], measures: %w[PAR-CLN-009], reconciliation: true },
    'mortality' => { status: 'defined', care_setting: 'cross_setting', dimension: 'death_disposition', grain: 'one_row_per_death_or_doa_disposition_event', key: 'encounter_id+death_disposition_version', numerator: 'distinct_encounters_with_approved_death_or_doa_disposition', denominator: 'eligible_discharged_or_emergency_encounters', parameters: %w[period_start period_end care_setting death_disposition organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_DISCHARGE B_IGD C_DEATH], measures: %w[PAR-REG-001 PAR-REG-003 PAR-CLN-005], reconciliation: true },
    'current_inpatient_census' => { status: 'defined', care_setting: 'inpatient', dimension: 'active_admission_bed_as_of', grain: 'one_row_per_active_admission_as_of_cutoff', key: 'inpatient_encounter_id+bed_assignment_version+as_of', numerator: 'distinct_active_inpatient_admissions_as_of_cutoff', denominator: 'eligible_admission_bed_snapshot_as_of_cutoff', parameters: %w[as_of care_setting ward room_class organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_BED B_ACTIVE_ADMISSION], measures: %w[PAR-ADM-033 PAR-REG-001], reconciliation: true },
    'payer_dimension_analysis' => { status: 'defined', care_setting: 'cross_setting', dimension: 'encounter_payer_class', grain: 'one_row_per_encounter_and_payer_class_version', key: 'encounter_id+payer_class_version', numerator: 'distinct_eligible_encounters_by_payer_class', denominator: 'eligible_encounters_when_share_is_requested', parameters: %w[period_start period_end care_setting payer_class output_measure organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_PAYER B_RI B_RJ B_IGD], measures: %w[PAR-REG-001 PAR-REG-002 PAR-REG-003], reconciliation: true },
    'clinical_record_quality' => { status: 'defined', care_setting: 'cross_setting', dimension: 'record_quality_state', grain: 'one_row_per_record_version_and_quality_rule', key: 'record_id+record_version+quality_rule_id', numerator: 'records_passing_or_failing_declared_quality_rule', denominator: 'eligible_final_record_versions', parameters: %w[period_start period_end care_setting quality_rule organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI B_RJ C_RJ C_RI C_RMIK_RJ C_RMIK_RI], measures: %w[PAR-CLN-004 PAR-CLN-005], reconciliation: true },
    'referral' => { status: 'defined', care_setting: 'cross_setting', dimension: 'referral_state', grain: 'one_row_per_referral_version', key: 'referral_id+version', numerator: 'distinct_referrals_by_direction_and_state', denominator: 'eligible_referral_versions', parameters: %w[period_start period_end care_setting referral_direction referral_state organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI B_RJ B_IGD C_RJ C_RI], measures: %w[PAR-REG-001 PAR-REG-002 PAR-REG-003], reconciliation: true },
    'clinical_event' => { status: 'defined', care_setting: 'cross_setting', dimension: 'clinical_event_state', grain: 'one_row_per_clinical_event_version', key: 'encounter_id+clinical_event_id+version', numerator: 'distinct_approved_clinical_events_by_declared_state', denominator: 'eligible_final_encounter_records', parameters: %w[period_start period_end care_setting clinical_event_type organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI B_RJ B_IGD C_RJ C_RI], measures: %w[PAR-CLN-004 PAR-CLN-005], reconciliation: true },
    'infection_incident' => { status: 'defined_count_denominator_unresolved', care_setting: 'cross_setting', dimension: 'infection_surveillance_state', grain: 'one_row_per_infection_surveillance_event_version', key: 'infection_case_id+event_version', numerator: 'distinct_recorded_infection_surveillance_cases', denominator: 'unresolved_until_approved_exposure_population_definition', parameters: %w[period_start period_end care_setting unit_id infection_category event_state organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI B_RJ C_RJ C_RI], measures: %w[PAR-CLN-004 PAR-CLN-005], reconciliation: true },
    'patient_safety_incident' => { status: 'defined', care_setting: 'cross_setting', dimension: 'patient_safety_incident_state', grain: 'one_row_per_patient_safety_incident_version', key: 'incident_id+incident_version', numerator: 'distinct_recorded_patient_safety_incidents_by_type_and_status', denominator: 'not_applicable', parameters: %w[period_start period_end unit_id incident_type severity status organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI B_RJ B_IGD C_RJ C_RI], measures: %w[PAR-CLN-004 PAR-CLN-005], reconciliation: true },
    'filing_custody' => { status: 'defined', care_setting: 'cross_setting', dimension: 'rmik_filing_custody_state', grain: 'one_row_per_record_and_filing_event_version', key: 'record_id+filing_event_id+filing_version', numerator: 'distinct_filing_events_by_location_and_status', denominator: 'eligible_records_when_completion_rate_is_requested', parameters: %w[period_start period_end filing_location filing_status organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT C_RMIK_RJ C_RMIK_RI], measures: %w[PAR-RMIK-001 PAR-RMIK-002], reconciliation: true },
    'cppt_document' => { status: 'defined', care_setting: 'cross_setting', dimension: 'cppt_document_completion_signoff', grain: 'one_row_per_cppt_document_version', key: 'encounter_id+cppt_document_id+version', numerator: 'distinct_cppt_documents_by_completion_and_signoff_state', denominator: 'eligible_cppt_document_versions', parameters: %w[period_start period_end care_setting author_id completion_status organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI B_RJ C_RJ C_RI], measures: %w[PAR-CLN-004 PAR-CLN-005], reconciliation: true },
    'inpatient_record_completeness_detail' => { status: 'defined', care_setting: 'inpatient', dimension: 'record_completeness_checklist_item', grain: 'one_row_per_inpatient_record_version_and_completeness_item', key: 'inpatient_record_id+record_version+checklist_item_id', numerator: 'completed_required_inpatient_record_checklist_items', denominator: 'eligible_required_inpatient_record_checklist_items', parameters: %w[period_start period_end care_setting checklist_version organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI C_RI], measures: %w[PAR-CLN-005], reconciliation: true },
    'outpatient_record_completeness_detail' => { status: 'defined', care_setting: 'outpatient', dimension: 'record_completeness_checklist_item', grain: 'one_row_per_outpatient_record_version_and_completeness_item', key: 'outpatient_record_id+record_version+checklist_item_id', numerator: 'completed_required_outpatient_record_checklist_items', denominator: 'eligible_required_outpatient_record_checklist_items', parameters: %w[period_start period_end care_setting checklist_version organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RJ C_RJ], measures: %w[PAR-CLN-004], reconciliation: true },
    'outpatient_record_completeness_summary' => { status: 'defined', care_setting: 'outpatient', dimension: 'record_completeness_summary', grain: 'one_row_per_outpatient_record_version', key: 'outpatient_record_id+record_version', numerator: 'eligible_outpatient_records_with_all_required_items_complete', denominator: 'eligible_final_outpatient_records', parameters: %w[period_start period_end care_setting checklist_version organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RJ C_RJ], measures: %w[PAR-CLN-004], reconciliation: true },
    'inpatient_record_completeness_summary' => { status: 'defined', care_setting: 'inpatient', dimension: 'record_completeness_summary', grain: 'one_row_per_inpatient_record_version', key: 'inpatient_record_id+record_version', numerator: 'eligible_inpatient_records_with_all_required_items_complete', denominator: 'eligible_final_inpatient_records', parameters: %w[period_start period_end care_setting checklist_version organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI C_RI], measures: %w[PAR-CLN-005], reconciliation: true },
    'respiratory_disease_recap' => { status: 'defined', care_setting: 'cross_setting', dimension: 'coded_ispa_case', grain: 'one_row_per_encounter_with_approved_ispa_diagnosis_version', key: 'encounter_id+approved_ispa_diagnosis_id+coding_version', numerator: 'distinct_encounters_with_approved_ispa_diagnosis', denominator: 'eligible_final_encounters_with_approved_diagnosis', parameters: %w[period_start period_end care_setting ispa_code_set_version organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI B_RJ B_IGD C_RMIK_RJ C_RMIK_RI], measures: %w[PAR-RMIK-001 PAR-RMIK-002], reconciliation: true },
    'outpatient_provider_activity' => { status: 'defined', care_setting: 'outpatient', dimension: 'provider_activity', grain: 'one_row_per_outpatient_encounter_and_approved_provider_assignment', key: 'outpatient_encounter_id+provider_assignment_version', numerator: 'distinct_outpatient_encounters_by_approved_provider', denominator: 'eligible_final_outpatient_encounters', parameters: %w[period_start period_end care_setting provider_id organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RJ C_PROVIDER_RJ], measures: %w[PAR-REG-002 PAR-CLN-004], reconciliation: true },
    'inpatient_provider_activity' => { status: 'defined', care_setting: 'inpatient', dimension: 'provider_activity', grain: 'one_row_per_inpatient_encounter_and_approved_provider_assignment', key: 'inpatient_encounter_id+provider_assignment_version', numerator: 'distinct_inpatient_encounters_by_approved_provider', denominator: 'eligible_final_inpatient_encounters', parameters: %w[period_start period_end care_setting provider_id organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI C_PROVIDER_RI], measures: %w[PAR-REG-001 PAR-CLN-005], reconciliation: true },
    'emergency_provider_activity' => { status: 'defined', care_setting: 'emergency', dimension: 'emergency_provider_activity', grain: 'one_row_per_emergency_encounter_and_approved_provider_assignment', key: 'emergency_encounter_id+provider_assignment_version', numerator: 'distinct_emergency_encounters_by_approved_provider', denominator: 'eligible_final_emergency_encounters', parameters: %w[period_start period_end provider_id provider_type emergency_unit organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_IGD C_PROVIDER_RJ], measures: %w[PAR-REG-003 PAR-CLN-004], reconciliation: true },
    'outgoing_referral' => { status: 'defined', care_setting: 'emergency', dimension: 'outgoing_referral_disposition', grain: 'one_row_per_outgoing_referral_version', key: 'encounter_id+outgoing_referral_version', numerator: 'distinct_encounters_with_approved_outgoing_referral', denominator: 'eligible_emergency_encounters_with_final_disposition', parameters: %w[period_start period_end care_setting referral_destination organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_OUTGOING_REFERRAL C_REFERRAL], measures: %w[PAR-REG-003 PAR-CLN-004], reconciliation: true },
    'aps_discharge_by_provider' => { status: 'defined', care_setting: 'inpatient', dimension: 'against_medical_advice_discharge_by_provider', grain: 'one_row_per_aps_discharge_and_approved_provider', key: 'inpatient_encounter_id+discharge_disposition_version+provider_assignment_version', numerator: 'distinct_aps_discharges_by_approved_provider', denominator: 'eligible_final_inpatient_discharges', parameters: %w[period_start period_end care_setting provider_id organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_DISCHARGE C_DISPOSITION_RI C_PROVIDER_RI], measures: %w[PAR-REG-001 PAR-CLN-005], reconciliation: true },
    'discharge_indication_by_class' => { status: 'defined', care_setting: 'inpatient', dimension: 'discharge_indication_by_room_class', grain: 'one_row_per_final_discharge_and_room_class_version', key: 'inpatient_encounter_id+discharge_disposition_version+room_class_version', numerator: 'distinct_final_discharges_by_indication_and_room_class', denominator: 'eligible_final_inpatient_discharges', parameters: %w[period_start period_end care_setting discharge_indication room_class organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_DISCHARGE B_BED C_DISPOSITION_RI], measures: %w[PAR-REG-001 PAR-CLN-005], reconciliation: true },
    'discharge_indication_by_room' => { status: 'defined', care_setting: 'inpatient', dimension: 'discharge_indication_by_room', grain: 'one_row_per_final_discharge_and_room_version', key: 'inpatient_encounter_id+discharge_disposition_version+room_assignment_version', numerator: 'distinct_final_discharges_by_indication_and_room', denominator: 'eligible_final_inpatient_discharges', parameters: %w[period_start period_end care_setting discharge_indication room_id organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_DISCHARGE B_BED C_DISPOSITION_RI], measures: %w[PAR-REG-001 PAR-CLN-005], reconciliation: true },
    'aps_discharge_reason' => { status: 'defined', care_setting: 'inpatient', dimension: 'against_medical_advice_discharge_reason', grain: 'one_row_per_aps_discharge_reason_version', key: 'inpatient_encounter_id+aps_reason_version', numerator: 'distinct_aps_discharges_by_approved_reason', denominator: 'eligible_final_aps_discharges', parameters: %w[period_start period_end care_setting aps_reason organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_DISCHARGE C_DISPOSITION_RI], measures: %w[PAR-REG-001 PAR-CLN-005], reconciliation: true },
    'cancer_case_registry' => { status: 'defined', care_setting: 'cross_setting', dimension: 'coded_cancer_case', grain: 'one_row_per_encounter_and_approved_cancer_diagnosis_version', key: 'encounter_id+approved_cancer_diagnosis_id+coding_version', numerator: 'distinct_encounters_with_approved_cancer_diagnosis', denominator: 'eligible_final_encounters_in_declared_cancer_cohort', parameters: %w[period_start period_end care_setting cancer_cohort cancer_code_set_version organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI B_RJ B_IGD C_RMIK_RJ C_RMIK_RI], measures: %w[PAR-RMIK-001 PAR-RMIK-002], reconciliation: true },
    'registration_encounter_register' => { status: 'defined', care_setting: 'cross_setting', dimension: 'registration_encounter_state', grain: 'one_row_per_registration_encounter_version', key: 'encounter_id+registration_version', numerator: 'distinct_registered_encounters_by_declared_state', denominator: 'eligible_registration_encounter_versions', parameters: %w[period_start period_end care_setting registration_state organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI B_RJ B_IGD], measures: %w[PAR-REG-001 PAR-REG-002 PAR-REG-003], reconciliation: true },
    'inpatient_transfer_event' => { status: 'defined', care_setting: 'inpatient', dimension: 'bed_ward_transfer_event', grain: 'one_row_per_inpatient_transfer_event_version', key: 'inpatient_encounter_id+transfer_event_id+source_ward_id+target_ward_id+transfer_state+transfer_version', numerator: 'distinct_approved_inpatient_transfer_events_by_source_target_ward_and_state', denominator: 'eligible_active_inpatient_encounters_for_transfer', parameters: %w[period_start period_end care_setting source_ward_id target_ward_id transfer_state organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_BED B_RI C_RI], measures: %w[PAR-ADM-033 PAR-REG-001], reconciliation: true },
    'inpatient_discharge_event' => { status: 'defined', care_setting: 'inpatient', dimension: 'final_discharge_event', grain: 'one_row_per_final_inpatient_discharge_version', key: 'inpatient_encounter_id+discharge_disposition_version', numerator: 'distinct_final_inpatient_discharges_by_disposition', denominator: 'eligible_admitted_inpatient_encounters', parameters: %w[period_start period_end care_setting discharge_disposition organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_DISCHARGE C_DISPOSITION_RI], measures: %w[PAR-REG-001 PAR-CLN-005], reconciliation: true },
    'askes_mortality' => { status: 'defined', care_setting: 'cross_setting', dimension: 'askes_death_disposition', grain: 'one_row_per_askes_death_episode_and_disposition_version', key: 'encounter_id+payer_class_version+death_disposition_version', numerator: 'distinct_askes_encounters_with_approved_death_or_doa_disposition', denominator: 'eligible_askes_discharged_or_emergency_encounters', parameters: %w[period_start period_end care_setting payer_class death_disposition organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_PAYER B_DISCHARGE B_IGD C_DEATH], measures: %w[PAR-REG-001 PAR-REG-003 PAR-CLN-005], reconciliation: true },
    'inpatient_ward_room_census' => { status: 'defined', care_setting: 'inpatient', dimension: 'active_admission_by_ward_room', grain: 'one_row_per_active_admission_ward_room_assignment_as_of', key: 'inpatient_encounter_id+ward_id+room_id+bed_assignment_version+as_of', numerator: 'distinct_active_inpatient_admissions_by_ward_and_room_as_of_cutoff', denominator: 'eligible_active_admission_bed_snapshot_as_of_cutoff', parameters: %w[as_of care_setting ward_id room_id organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_BED B_ACTIVE_ADMISSION], measures: %w[PAR-ADM-033 PAR-REG-001], reconciliation: true },
    'statutory_indicator_unresolved' => { status: 'unresolved_owner_definition', care_setting: 'statutory', dimension: 'legacy_statutory_indicator', grain: 'blocked_until_current_definition', key: 'blocked_until_current_definition', numerator: 'unresolved_current_authority_formula', denominator: 'unresolved_current_authority_population', parameters: %w[standard_identifier standard_version effective_date reporting_period organization_scope definition_version], sources: %w[A_CONFIG A_AUDIT], measures: [], reconciliation: true },
    'patient_distribution_unresolved' => { status: 'unresolved_owner_definition', care_setting: 'cross_setting', dimension: 'patient_distribution_scope_unresolved', grain: 'blocked_until_patient_or_encounter_grain_and_distribution_dimension_are_approved', key: 'blocked_until_patient_or_encounter_distinct_key_is_approved', numerator: 'unresolved_patient_or_encounter_distribution_measure', denominator: 'unresolved_distribution_population', parameters: %w[owner_definition_reference distribution_dimension period_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_BED B_RI B_RJ B_IGD C_RJ C_RI], measures: [], reconciliation: true },
    'period_utilisation_unresolved' => { status: 'unresolved_owner_definition', care_setting: 'cross_setting', dimension: 'period_metric_and_route_scope_unresolved', grain: 'blocked_until_metric_setting_and_period_grain_are_approved', key: 'blocked_until_authoritative_encounter_or_event_key_is_approved', numerator: 'unresolved_period_aggregate_measure', denominator: 'unresolved_eligible_population', parameters: %w[owner_definition_reference time_bucket care_setting metric route_scope definition_version], sources: %w[A_CONFIG A_AUDIT B_RI B_RJ B_IGD C_RJ C_RI], measures: [], reconciliation: true },
    'new_returning_rule_unresolved' => { status: 'unresolved_owner_definition', care_setting: 'outpatient', dimension: 'new_returning_patient_rule_unresolved', grain: 'blocked_until_new_returning_rule_and_provider_encounter_grain_are_approved', key: 'blocked_until_patient_history_and_encounter_key_are_approved', numerator: 'unresolved_new_returning_provider_activity_measure', denominator: 'unresolved_eligible_outpatient_population', parameters: %w[owner_definition_reference new_returning_rule provider_id clinic_id definition_version], sources: %w[A_CONFIG A_AUDIT B_RJ C_PROVIDER_RJ], measures: [], reconciliation: true },
    'provider_duplicate_unresolved' => { status: 'unresolved_owner_definition', care_setting: 'cross_setting', dimension: 'duplicate_provider_route_scope_unresolved', grain: 'blocked_until_provider_route_and_care_setting_grain_are_approved', key: 'blocked_until_provider_assignment_and_encounter_key_are_approved', numerator: 'unresolved_provider_activity_measure', denominator: 'unresolved_eligible_encounter_population', parameters: %w[owner_definition_reference route_mapping_reference care_setting provider_id definition_version], sources: %w[A_CONFIG A_AUDIT B_RI B_RJ C_PROVIDER_RJ C_PROVIDER_RI], measures: [], reconciliation: true },
    'mortality_duplicate_unresolved' => { status: 'unresolved_owner_definition', care_setting: 'cross_setting', dimension: 'duplicate_mortality_route_scope_unresolved', grain: 'blocked_until_death_episode_route_and_scope_are_approved', key: 'blocked_until_death_event_or_episode_key_is_approved', numerator: 'unresolved_recorded_death_measure', denominator: 'unresolved_mortality_population', parameters: %w[owner_definition_reference route_mapping_reference care_setting death_classification definition_version], sources: %w[A_CONFIG A_AUDIT B_DISCHARGE B_IGD C_DEATH], measures: [], reconciliation: true },
    'unresolved_variant' => { status: 'unresolved_owner_definition', care_setting: 'unknown', dimension: 'legacy_variant_or_duplicate', grain: 'blocked_until_owner_mapping', key: 'blocked_until_owner_mapping', numerator: 'unresolved_owner_formula', denominator: 'unresolved_owner_population', parameters: %w[owner_definition_reference variant_mapping_reference definition_version], sources: %w[A_CONFIG A_AUDIT], measures: [], reconciliation: true }
  }.transform_values(&:freeze).freeze
  BATCH_G_STATUTORY_FORM_CODES = {
    'PAR-RPT-062' => 'RL-3.1', 'PAR-RPT-063' => 'RL-3.2', 'PAR-RPT-064' => 'RL-3.3', 'PAR-RPT-065' => 'RL-3.4',
    'PAR-RPT-066' => 'RL-3.5', 'PAR-RPT-067' => 'RL-3.6', 'PAR-RPT-068' => 'RL-3.7', 'PAR-RPT-069' => 'RL-3.8',
    'PAR-RPT-070' => 'RL-3.9', 'PAR-RPT-071' => 'RL-3.11', 'PAR-RPT-072' => 'RL-3.10', 'PAR-RPT-073' => 'RL-3.12',
    'PAR-RPT-074' => 'RL-3.13', 'PAR-RPT-075' => 'RL-3.14', 'PAR-RPT-076' => 'RL-3.15', 'PAR-RPT-077' => 'RL-3.16',
    'PAR-RPT-078' => 'RL-3.17', 'PAR-RPT-079' => 'RL-3.18', 'PAR-RPT-080' => 'RL-3.19', 'PAR-RPT-081' => 'RL-4.1',
    'PAR-RPT-082' => 'RL-4.2', 'PAR-RPT-083' => 'RL-4.3', 'PAR-RPT-084' => 'RL-5.1', 'PAR-RPT-085' => 'RL-5.2',
    'PAR-RPT-086' => 'RL-5.3', 'PAR-RPT-087' => 'RL-4A', 'PAR-RPT-088' => 'RL-4B', 'PAR-RPT-089' => 'RL-4A-SEBAB',
    'PAR-RPT-090' => 'RL-4B-SEBAB', 'PAR-RPT-091' => 'RL-5.4', 'PAR-RPT-092' => 'STP-RS-RJ',
    'PAR-RPT-093' => 'STP-RS-RI', 'PAR-RPT-094' => 'STPRS-RI2', 'PAR-RPT-095' => 'STPRS-RJ2'
  }.freeze
  BATCH_G_ROW_SEMANTIC_MAPPING = {
    'PAR-ADM-007' => ['target_master', 'outpatient_management_target_definition', { 'care_setting' => 'outpatient', 'value_role' => 'target_never_actual' }],
    'PAR-ADM-031' => ['disease_reference_master', 'communicable_disease_reference', { 'reference_scope' => 'communicable_disease' }],
    'PAR-ADM-035' => ['w2_reference_master', 'w2_form_reference_unresolved', { 'legacy_form_code' => 'W2', 'definition_state' => 'unresolved' }],
    'PAR-RPT-001' => ['outpatient_coded_diagnosis', 'top_ten_outpatient_diagnoses', { 'care_setting' => 'outpatient', 'rank_limit' => '10' }],
    'PAR-RPT-002' => ['inpatient_coded_diagnosis', 'top_ten_inpatient_diagnoses', { 'care_setting' => 'inpatient', 'rank_limit' => '10' }],
    'PAR-RPT-003' => ['outpatient_coded_procedure', 'top_ten_outpatient_procedures', { 'care_setting' => 'outpatient', 'rank_limit' => '10' }],
    'PAR-RPT-004' => ['inpatient_coded_procedure', 'top_ten_inpatient_procedures', { 'care_setting' => 'inpatient', 'rank_limit' => '10' }],
    'PAR-RPT-005' => ['patient_cohort', 'synthetic_patient_registry_cohort', { 'patient_cohort' => 'registered_synthetic_patients' }],
    'PAR-RPT-006' => ['inpatient_encounter', 'inpatient_visit_count', { 'care_setting' => 'inpatient', 'aggregation_dimension' => 'visit' }],
    'PAR-RPT-007' => ['outpatient_encounter', 'outpatient_visit_count', { 'care_setting' => 'outpatient', 'aggregation_dimension' => 'visit' }],
    'PAR-RPT-008' => ['unresolved_variant', 'ambiguous_trend_definition', { 'definition_state' => 'unresolved', 'legacy_label' => 'Trend' }],
    'PAR-RPT-009' => ['prescription_monitor', 'prescription_dispense_monitor', { 'output_mode' => 'detail' }],
    'PAR-RPT-010' => ['unresolved_variant', 'ambiguous_time_measure_definition', { 'definition_state' => 'unresolved', 'legacy_label' => 'Waktu' }],
    'PAR-RPT-011' => ['last_visit', 'latest_qualifying_patient_visit', { 'encounter_selector' => 'latest_qualifying' }],
    'PAR-RPT-012' => ['laboratory_register', 'laboratory_order_specimen_result_register', { 'diagnostic_domain' => 'laboratory' }],
    'PAR-RPT-013' => ['emergency_encounter', 'emergency_encounter_register', { 'care_setting' => 'emergency', 'output_mode' => 'register' }],
    'PAR-RPT-014' => ['radiology_register', 'radiology_order_report_register', { 'diagnostic_domain' => 'radiology' }],
    'PAR-RPT-015' => ['cancer_case_registry', 'coded_cancer_case_registry', { 'cancer_cohort' => 'approved_coded_cancer_case', 'cancer_code_set_version' => 'authority_approved_required' }],
    'PAR-RPT-016' => ['outpatient_encounter', 'outpatient_encounter_register', { 'care_setting' => 'outpatient', 'output_mode' => 'register' }],
    'PAR-RPT-017' => ['inpatient_encounter', 'inpatient_encounter_register', { 'care_setting' => 'inpatient', 'output_mode' => 'register' }],
    'PAR-RPT-018' => ['surgery_register', 'surgery_theatre_procedure_register', { 'care_setting' => 'inpatient_theatre', 'output_mode' => 'register' }],
    'PAR-RPT-019' => ['registration_encounter_register', 'cross_setting_registration_register', { 'registration_scope' => 'all_encounter_settings' }],
    'PAR-RPT-020' => ['unresolved_variant', 'jhp_definition_unresolved', { 'definition_state' => 'unresolved', 'legacy_label' => 'JHP' }],
    'PAR-RPT-021' => ['statutory_indicator_unresolved', 'w2_report_formula_unresolved', { 'legacy_form_code' => 'W2', 'definition_state' => 'unresolved' }],
    'PAR-RPT-022' => ['inpatient_record_completeness_detail', 'inpatient_quantitative_record_completeness', { 'care_setting' => 'inpatient', 'output_mode' => 'checklist_detail' }],
    'PAR-RPT-023' => ['inpatient_encounter', 'inpatient_admission_event', { 'care_setting' => 'inpatient', 'encounter_event' => 'admission' }],
    'PAR-RPT-024' => ['inpatient_transfer_event', 'inpatient_source_target_ward_transfer', { 'care_setting' => 'inpatient', 'required_transfer_fields' => 'source_ward+target_ward+transfer_state' }],
    'PAR-RPT-025' => ['inpatient_discharge_event', 'final_inpatient_discharge_event', { 'care_setting' => 'inpatient', 'encounter_event' => 'discharge' }],
    'PAR-RPT-026' => ['outpatient_coded_diagnosis', 'outpatient_noncommunicable_disease_cohort', { 'care_setting' => 'outpatient', 'diagnosis_cohort' => 'noncommunicable_disease', 'definition_variant' => 'base' }],
    'PAR-RPT-027' => ['unresolved_variant', 'outpatient_ptm_v2_mapping_unresolved', { 'care_setting' => 'outpatient', 'definition_variant' => 'v2_unresolved' }],
    'PAR-RPT-028' => ['inpatient_coded_diagnosis', 'inpatient_noncommunicable_disease_cohort', { 'care_setting' => 'inpatient', 'diagnosis_cohort' => 'noncommunicable_disease', 'definition_variant' => 'base' }],
    'PAR-RPT-029' => ['unresolved_variant', 'inpatient_ptm_v2_mapping_unresolved', { 'care_setting' => 'inpatient', 'definition_variant' => 'v2_unresolved' }],
    'PAR-RPT-030' => ['outpatient_record_completeness_detail', 'outpatient_quantitative_record_completeness', { 'care_setting' => 'outpatient', 'output_mode' => 'checklist_detail' }],
    'PAR-RPT-031' => ['outpatient_record_completeness_summary', 'outpatient_completeness_summary', { 'care_setting' => 'outpatient', 'output_mode' => 'summary' }],
    'PAR-RPT-032' => ['inpatient_record_completeness_summary', 'inpatient_completeness_summary', { 'care_setting' => 'inpatient', 'output_mode' => 'summary' }],
    'PAR-RPT-033' => ['wait_time', 'documentation_completion_delay_detail', { 'milestone_pair' => 'record_opened_to_completion', 'output_mode' => 'detail' }],
    'PAR-RPT-034' => ['wait_time', 'documentation_completion_delay_summary', { 'milestone_pair' => 'record_opened_to_completion', 'output_mode' => 'summary' }],
    'PAR-RPT-035' => ['clinical_record_quality', 'nursing_note_completeness', { 'record_component' => 'nursing_note' }],
    'PAR-RPT-036' => ['clinical_record_quality', 'nursing_resume_completeness', { 'record_component' => 'nursing_resume' }],
    'PAR-RPT-037' => ['clinical_record_quality', 'medical_record_completeness', { 'record_component' => 'medical_record' }],
    'PAR-RPT-038' => ['referral', 'referring_source_activity', { 'referral_direction' => 'incoming', 'aggregation_dimension' => 'referrer' }],
    'PAR-RPT-039' => ['clinical_event', 'outpatient_immunization_event', { 'care_setting' => 'outpatient', 'clinical_event_type' => 'immunization' }],
    'PAR-RPT-040' => ['mortality', 'clinical_mortality_episode', { 'death_scope' => 'general_clinical', 'payer_filter' => 'all' }],
    'PAR-RPT-041' => ['askes_mortality', 'askes_mortality_episode', { 'payer_class' => 'ASKES', 'death_scope' => 'askes_episode' }],
    'PAR-RPT-042' => ['current_inpatient_census', 'currently_treated_inpatient_census', { 'care_setting' => 'inpatient', 'census_state' => 'active_as_of' }],
    'PAR-RPT-043' => ['mortality', 'external_payer_mortality_episode', { 'death_scope' => 'external', 'payer_filter' => 'external' }],
    'PAR-RPT-044' => ['clinical_event', 'inpatient_immunization_event', { 'care_setting' => 'inpatient', 'clinical_event_type' => 'immunization' }],
    'PAR-RPT-045' => ['payer_dimension_analysis', 'payment_method_encounter_projection', { 'payer_dimension' => 'payment_method', 'output_measure' => 'distinct_encounter_count' }],
    'PAR-RPT-046' => ['infection_incident', 'hai_summary', { 'event_type' => 'healthcare_associated_infection', 'output_mode' => 'summary', 'definition_variant' => 'base' }],
    'PAR-RPT-047' => ['unresolved_variant', 'hai_summary_v2_mapping_unresolved', { 'event_type' => 'healthcare_associated_infection', 'definition_variant' => 'v2_unresolved' }],
    'PAR-RPT-048' => ['patient_safety_incident', 'patient_safety_incident_register', { 'event_type' => 'patient_safety_incident', 'output_mode' => 'register' }],
    'PAR-RPT-049' => ['infection_incident', 'hai_event_register', { 'event_type' => 'healthcare_associated_infection', 'output_mode' => 'register' }],
    'PAR-RPT-050' => ['patient_distribution_unresolved', 'patient_distribution_grain_and_dimension_unresolved', { 'definition_state' => 'unresolved', 'legacy_label' => 'Sebaran Pasien' }],
    'PAR-RPT-051' => ['referral', 'outgoing_referral_detail', { 'referral_direction' => 'outgoing', 'output_mode' => 'detail' }],
    'PAR-RPT-052' => ['referral', 'outgoing_referral_summary', { 'referral_direction' => 'outgoing', 'output_mode' => 'summary' }],
    'PAR-RPT-053' => ['filing_custody', 'rmik_filing_custody_register', { 'record_component' => 'filing_custody', 'output_mode' => 'register' }],
    'PAR-RPT-054' => ['respiratory_disease_recap', 'coded_ispa_case_recap', { 'diagnosis_cohort' => 'ISPA', 'output_mode' => 'summary' }],
    'PAR-RPT-055' => ['cppt_document', 'cppt_completion_and_signoff_projection', { 'record_component' => 'CPPT', 'quality_program' => 'eKin' }],
    'PAR-RPT-056' => ['outpatient_coded_diagnosis', 'outpatient_disease_projection', { 'care_setting' => 'outpatient', 'aggregation_dimension' => 'diagnosis' }],
    'PAR-RPT-057' => ['outpatient_provider_activity', 'outpatient_doctor_activity', { 'care_setting' => 'outpatient', 'provider_dimension' => 'doctor' }],
    'PAR-RPT-058' => ['inpatient_coded_diagnosis', 'inpatient_disease_projection', { 'care_setting' => 'inpatient', 'aggregation_dimension' => 'diagnosis' }],
    'PAR-RPT-059' => ['outpatient_coded_procedure', 'outpatient_procedure_projection', { 'care_setting' => 'outpatient', 'aggregation_dimension' => 'procedure' }],
    'PAR-RPT-060' => ['inpatient_provider_activity', 'inpatient_doctor_activity', { 'care_setting' => 'inpatient', 'provider_dimension' => 'doctor' }],
    'PAR-RPT-061' => ['inpatient_coded_procedure', 'inpatient_procedure_projection', { 'care_setting' => 'inpatient', 'aggregation_dimension' => 'procedure' }],
    'PAR-RPT-096' => ['period_utilisation_unresolved', 'per_day_metric_and_scope_unresolved', { 'definition_state' => 'unresolved', 'legacy_label' => 'Per Hari', 'time_bucket' => 'day' }],
    'PAR-RPT-097' => ['period_utilisation_unresolved', 'first_per_month_metric_and_scope_unresolved', { 'definition_state' => 'unresolved', 'legacy_label' => 'Per Bulan', 'time_bucket' => 'month', 'route_variant' => 'first' }],
    'PAR-RPT-098' => ['outpatient_encounter', 'outpatient_visits_by_clinic', { 'care_setting' => 'outpatient', 'aggregation_dimension' => 'clinic' }],
    'PAR-RPT-099' => ['outpatient_provider_activity', 'outpatient_visits_by_doctor', { 'care_setting' => 'outpatient', 'provider_dimension' => 'doctor' }],
    'PAR-RPT-100' => ['new_returning_rule_unresolved', 'new_returning_patient_rule_unresolved', { 'definition_state' => 'unresolved', 'legacy_label' => 'Per Dokter Baru-Lama' }],
    'PAR-RPT-101' => ['mortality', 'doa_dos_mortality_episode', { 'death_scope' => 'DOA_or_DOS', 'care_setting' => 'emergency' }],
    'PAR-RPT-102' => ['outgoing_referral', 'emergency_outgoing_referral', { 'care_setting' => 'emergency', 'referral_direction' => 'outgoing' }],
    'PAR-RPT-103' => ['emergency_encounter', 'traffic_accident_emergency_encounter', { 'care_setting' => 'emergency', 'encounter_cohort' => 'traffic_accident' }],
    'PAR-RPT-104' => ['emergency_provider_activity', 'general_practitioner_emergency_activity', { 'care_setting' => 'emergency', 'provider_type' => 'general_practitioner' }],
    'PAR-RPT-105' => ['emergency_provider_activity', 'emergency_doctor_activity', { 'care_setting' => 'emergency', 'provider_type' => 'emergency_doctor' }],
    'PAR-RPT-106' => ['period_utilisation_unresolved', 'second_per_month_metric_and_scope_unresolved', { 'definition_state' => 'unresolved', 'legacy_label' => 'Per Bulan', 'time_bucket' => 'month', 'route_variant' => 'second' }],
    'PAR-RPT-107' => ['inpatient_ward_room_census', 'active_inpatient_census_by_ward_room', { 'care_setting' => 'inpatient', 'required_dimensions' => 'ward_id+room_id' }],
    'PAR-RPT-108' => ['current_inpatient_census', 'active_inpatient_census_by_class', { 'care_setting' => 'inpatient', 'aggregation_dimension' => 'room_class' }],
    'PAR-RPT-109' => ['inpatient_provider_activity', 'inpatient_activity_by_specialty', { 'care_setting' => 'inpatient', 'provider_dimension' => 'specialty' }],
    'PAR-RPT-110' => ['provider_duplicate_unresolved', 'second_per_doctor_route_scope_unresolved', { 'definition_state' => 'unresolved', 'legacy_label' => 'Per Dokter', 'route_variant' => 'second' }],
    'PAR-RPT-111' => ['aps_discharge_by_provider', 'aps_discharges_by_doctor', { 'care_setting' => 'inpatient', 'discharge_disposition' => 'APS', 'provider_dimension' => 'doctor' }],
    'PAR-RPT-112' => ['discharge_indication_by_class', 'discharge_indication_by_room_class', { 'care_setting' => 'inpatient', 'aggregation_dimension' => 'room_class' }],
    'PAR-RPT-113' => ['discharge_indication_by_room', 'discharge_indication_by_room', { 'care_setting' => 'inpatient', 'aggregation_dimension' => 'room' }],
    'PAR-RPT-114' => ['aps_discharge_reason', 'aps_discharge_reason_summary', { 'care_setting' => 'inpatient', 'discharge_disposition' => 'APS', 'aggregation_dimension' => 'reason' }],
    'PAR-RPT-115' => ['mortality_duplicate_unresolved', 'second_mortality_route_scope_unresolved', { 'definition_state' => 'unresolved', 'legacy_label' => 'Kematian', 'route_variant' => 'second' }],
    'PAR-RPT-116' => ['current_inpatient_census', 'current_inpatient_status', { 'care_setting' => 'inpatient', 'census_state' => 'active_as_of' }],
    'PAR-RPT-117' => ['prescription_monitor', 'prescription_monitor_summary', { 'output_mode' => 'summary' }]
  }.merge(
    BATCH_G_STATUTORY_FORM_CODES.to_h do |requirement_id, form_code|
      [requirement_id, ['statutory_indicator_unresolved', "#{form_code.downcase.gsub(/[^a-z0-9]+/, '_')}_formula_unresolved", { 'legacy_form_code' => form_code, 'definition_state' => 'unresolved' }]]
    end
  ).then { |mapping| EXPECTED_BATCH_G_IDS.to_h { |requirement_id| [requirement_id, mapping.fetch(requirement_id)] } }.freeze
  BATCH_G_ROW_SEMANTIC_TYPE = BATCH_G_ROW_SEMANTIC_MAPPING.to_h { |requirement_id, mapping| [requirement_id, mapping.fetch(0)] }.freeze
  BATCH_G_ROW_SEMANTIC_SPECS = EXPECTED_BATCH_G_IDS.to_h do |requirement_id|
    semantic_type, capability_focus, fixed_parameter_values = BATCH_G_ROW_SEMANTIC_MAPPING.fetch(requirement_id)
    template = BATCH_G_SEMANTIC_TEMPLATES.fetch(semantic_type)
    exact_parameters = [*template.fetch(:parameters), *fixed_parameter_values.keys].uniq.freeze
    source_roles = template.fetch(:sources).map do |source_key|
      source = BATCH_G_SOURCE_SPECS.fetch(source_key)
      { source_key: source_key, batch: source.fetch(:batch), requirement_id: source.fetch(:id), entity: source.fetch(:entity), role: template.fetch(:measures).include?(source.fetch(:id)) ? 'authoritative_measure' : 'lineage_context' }.freeze
    end.freeze
    exact_spec = template.merge(
      requirement_id: requirement_id,
      semantic_type: semantic_type,
      semantic_contract_id: "G-SEMANTIC-#{requirement_id}",
      capability_focus: capability_focus,
      fixed_parameter_values: fixed_parameter_values.freeze,
      parameters: exact_parameters,
      source_roles: source_roles,
      inclusions: ["#{capability_focus}:eligible_population", "#{template.fetch(:care_setting)}:source_version_effective_at_cutoff"].freeze,
      exclusions: ['real_patient_identifiers', 'unavailable_required_source', "#{capability_focus}:outside_declared_population"].freeze,
      time_basis: template.fetch(:parameters).include?('as_of') ? 'effective_state_as_of_cutoff_then_recorded_time' : 'event_time_then_effective_time_then_recorded_time',
      cutoff_policy: template.fetch(:parameters).include?('as_of') ? 'inclusive_as_of_cutoff_with_late_state_in_linked_restatement' : 'inclusive_cutoff_with_late_events_in_append_only_restatement',
      period_close_policy: 'closed_output_is_immutable_and_late_events_create_linked_new_version'
    ).freeze
    [requirement_id, exact_spec]
  end.freeze
  BATCH_G_INTRA_BATCH_DEPENDENCIES = EXPECTED_BATCH_G_IDS.to_h { |id| [id, []] }.merge(
    'PAR-RPT-021' => %w[PAR-ADM-031 PAR-ADM-035], 'PAR-RPT-034' => %w[PAR-RPT-033],
    'PAR-RPT-027' => %w[PAR-RPT-026], 'PAR-RPT-029' => %w[PAR-RPT-028],
    'PAR-RPT-047' => %w[PAR-RPT-046], 'PAR-RPT-049' => %w[PAR-RPT-048],
    'PAR-RPT-052' => %w[PAR-RPT-051], 'PAR-RPT-106' => %w[PAR-RPT-097],
    'PAR-RPT-110' => %w[PAR-RPT-099], 'PAR-RPT-115' => %w[PAR-RPT-040],
    'PAR-RPT-117' => %w[PAR-RPT-009]
  ).transform_values(&:freeze).freeze
  BATCH_G_CONSOLIDATION_GROUPS = {
    'G-C01' => %w[PAR-RPT-001 PAR-RPT-002], 'G-C02' => %w[PAR-RPT-003 PAR-RPT-004],
    'G-C03A' => %w[PAR-RPT-026 PAR-RPT-027], 'G-C03B' => %w[PAR-RPT-028 PAR-RPT-029],
    'G-C04' => %w[PAR-RPT-033 PAR-RPT-034], 'G-C05' => %w[PAR-RPT-046 PAR-RPT-047]
  }.transform_values(&:freeze).freeze
  BATCH_G_CONSOLIDATION_PARAMETER_POLICIES = {
    'G-C01' => {
      'declared_parameters' => { 'care_setting' => %w[outpatient inpatient] },
      'member_values' => { 'PAR-RPT-001' => { 'care_setting' => 'outpatient' }, 'PAR-RPT-002' => { 'care_setting' => 'inpatient' } }
    },
    'G-C02' => {
      'declared_parameters' => { 'care_setting' => %w[outpatient inpatient] },
      'member_values' => { 'PAR-RPT-003' => { 'care_setting' => 'outpatient' }, 'PAR-RPT-004' => { 'care_setting' => 'inpatient' } }
    },
    'G-C03A' => {
      'declared_parameters' => { 'care_setting' => %w[outpatient], 'definition_variant' => %w[base v2] },
      'member_values' => { 'PAR-RPT-026' => { 'care_setting' => 'outpatient', 'definition_variant' => 'base' }, 'PAR-RPT-027' => { 'care_setting' => 'outpatient', 'definition_variant' => 'v2' } }
    },
    'G-C03B' => {
      'declared_parameters' => { 'care_setting' => %w[inpatient], 'definition_variant' => %w[base v2] },
      'member_values' => { 'PAR-RPT-028' => { 'care_setting' => 'inpatient', 'definition_variant' => 'base' }, 'PAR-RPT-029' => { 'care_setting' => 'inpatient', 'definition_variant' => 'v2' } }
    },
    'G-C04' => {
      'declared_parameters' => { 'output_mode' => %w[detail summary] },
      'member_values' => { 'PAR-RPT-033' => { 'output_mode' => 'detail' }, 'PAR-RPT-034' => { 'output_mode' => 'summary' } }
    },
    'G-C05' => {
      'declared_parameters' => { 'definition_variant' => %w[base v2] },
      'member_values' => { 'PAR-RPT-046' => { 'definition_variant' => 'base' }, 'PAR-RPT-047' => { 'definition_variant' => 'v2' } }
    }
  }.freeze
  BATCH_G_STATUTORY_IDS = BATCH_G_FAMILY_MEMBERS.fetch('G3')
  BATCH_G_PROJECTION_IDS = EXPECTED_BATCH_G_IDS.grep(/PAR-RPT-/).freeze
  BATCH_G_CAPABILITY_KINDS = EXPECTED_BATCH_G_IDS.to_h do |id|
    kind = if id == 'PAR-ADM-007' then 'effective_dated_target_master'
           elsif id == 'PAR-ADM-031' then 'surveillance_reference_master'
           elsif id == 'PAR-ADM-035' then 'versioned_statutory_reference'
           else "read_only_#{BATCH_G_ROW_SEMANTIC_TYPE.fetch(id)}_projection"
           end
    [id, kind]
  end.freeze
  BATCH_G_NULL_TOKENS = %w[unknown not_collected not_applicable unavailable suppressed numeric_zero].freeze
  BATCH_G_VALUE_STATES = ['known_numeric', *BATCH_G_NULL_TOKENS].freeze
  BATCH_G_PROHIBITED_TARGETS = %w[BPJS VClaim SATUSEHAT E-Klaim iDRG LIS PACS accounting_ERP SIRS public_health statutory_submission live_endpoint].freeze
  BATCH_G_CONTROL_TOTALS = %w[authoritative_source_total documented_exclusion_total approved_adjustment_total report_total source_row_count report_row_count distinct_key_count duplicate_join_count traced_sample_count missing_source_count null_state_count].freeze
  BATCH_G_RECONCILIATION_EQUATION = 'report_total-authoritative_source_total+documented_exclusion_total-approved_adjustment_total=0'
  BATCH_G_DEFERRAL_BASE_EXCLUSIONS = %w[not_report_ready not_exportable not_current_compliance not_transmitted].freeze
  BATCH_G_REGISTER_KEYS = %w[schema_version register_id batch register_status data_boundary external_integrations source_manifest source_manifest_sha256 source_revision evidence_directory purpose availability_state family_policies consolidation_candidates entries].freeze
  BATCH_G_FAMILY_POLICY_KEYS = %w[family_id members lead_authority_domain mandatory_authorities description].freeze
  BATCH_G_ENTRY_KEYS = %w[requirement_id batch legacy_menu family_id availability_state capability_kind lead_authority_domain definition_contract evidence decision affected_domains co_owners downstream_impacts synthetic_scenarios accountable_owner appointment_dependencies source_dependencies intra_batch_dependencies output_boundary reconciliation_contract statutory_definition consolidation_mapping approval].freeze
  BATCH_G_DEFINITION_KEYS = %w[semantic_status semantic_digest semantic_focus capability_focus care_setting dimension fixed_parameter_values source_roles requires_reconciliation purpose intended_users sensitivity report_class grain distinct_key numerator denominator inclusions exclusions parameters source_fields transformations terminology_version time_basis timezone cutoff_policy period_close_policy freshness_policy null_policy suppression_masking_policy layout_export_policy retention_policy definition_version join_contract snapshot_policy correction_restatement_policy incomplete_source_behavior write_semantics].freeze
  BATCH_G_JOIN_KEYS = %w[allowed_cardinalities dedup_rule aggregation_rule unbounded_many_to_many].freeze
  BATCH_G_SCENARIO_KEYS = %w[status data_class description expected_results contract_ref].freeze
  BATCH_G_SOURCE_DEPENDENCY_KEYS = %w[batch requirement_id source_entity source_owner_authority status resolution_reference resolution_artifact_sha256].freeze
  BATCH_G_INTRA_DEPENDENCY_KEYS = %w[requirement_id status resolution_reference resolution_artifact_sha256].freeze
  BATCH_G_BOUNDARY_KEYS = %w[mode endpoint credential_state outbound_network delivery_state export_mode watermark audit_events retention_policy prohibited_targets transmission_claim].freeze
  BATCH_G_RECONCILIATION_KEYS = %w[status profile_id equation control_totals receipt_reference receipt_artifact_sha256].freeze
  BATCH_G_STATUTORY_KEYS = %w[status legacy_simulation_only standard_identifier standard_version effective_date definition_source authority_reference authority_artifact_sha256].freeze
  BATCH_G_CONSOLIDATION_KEYS = %w[candidate_id status terminal_target_requirement_id artifact_reference artifact_sha256].freeze
  BATCH_G_SOURCE_ARTIFACT_TYPE = 'g0_batch_g_source_resolution'
  BATCH_G_INTRA_ARTIFACT_TYPE = 'g0_batch_g_intra_resolution'
  BATCH_G_RECONCILIATION_ARTIFACT_TYPE = 'g0_batch_g_reconciliation'
  BATCH_G_SOURCE_EXPORT_ARTIFACT_TYPE = 'g0_batch_g_source_export'
  BATCH_G_OUTPUT_ARTIFACT_TYPE = 'g0_batch_g_output'
  BATCH_G_STATUTORY_ARTIFACT_TYPE = 'g0_batch_g_statutory_definition'
  BATCH_G_STATUTORY_APPROVAL_ARTIFACT_TYPE = 'g0_batch_g_statutory_approval'
  BATCH_G_CONSOLIDATION_ARTIFACT_TYPE = 'g0_batch_g_consolidation_mapping'
  BATCH_G_SOURCE_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id subject source_batch source_requirement_id source_entity source_owner_authority source_register_id source_register_sha256 source_owner_identity source_approval_reference source_approval_sha256 status date reviewer].freeze
  BATCH_G_INTRA_ARTIFACT_KEYS = %w[artifact_type schema_version register_id source_requirement_id target_requirement_id target_owner_identity target_authority_domain target_approval_reference target_approval_sha256 target_decision_status target_disposition status date reviewer].freeze
  BATCH_G_RECONCILIATION_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id definition_sha256 parameter_descriptor snapshot_id cutoff_at period_state prior_output_reference prior_output_sha256 restatement_reason source_exports report_output rerun_receipt access_receipt audit_receipt control_values equation difference sampled_lineage late_event_policy author_identity reviewer].freeze
  BATCH_G_ARTIFACT_DESCRIPTOR_KEYS = %w[reference sha256].freeze
  BATCH_G_EXPORT_DESCRIPTOR_KEYS = %w[source_batch source_requirement_id source_entity control_role reference sha256].freeze
  BATCH_G_OUTPUT_DESCRIPTOR_KEYS = %w[reference sha256].freeze
  BATCH_G_SOURCE_EXPORT_KEYS = %w[artifact_type schema_version register_id requirement_id source_batch source_requirement_id source_entity control_role snapshot_id canonical_export_id rows source_root_sha256 author_identity date reviewer].freeze
  BATCH_G_OUTPUT_KEYS = %w[artifact_type schema_version register_id requirement_id snapshot_id definition_sha256 parameter_sha256 rows output_sha256 watermark exportable access_scope export_reason audit_event_ids retention_class masked small_cell_suppression_applied author_identity date reviewer].freeze
  BATCH_G_SYNTHETIC_ROW_KEYS = %w[synthetic_id distinct_key value state included exclusion_reason source_version].freeze
  BATCH_G_LINEAGE_SAMPLE_KEYS = %w[report_distinct_key source_batch source_requirement_id source_distinct_key source_root_sha256].freeze
  BATCH_G_STATUTORY_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id subject legacy_simulation_only standard_identifier standard_version effective_date definition_source report_definition_reference report_definition_sha256 authority_bindings date author_identity reviewer].freeze
  BATCH_G_STATUTORY_BINDING_KEYS = %w[authority_domain identity appointment_reference appointment_sha256 approval_reference approval_sha256].freeze
  BATCH_G_STATUTORY_APPROVAL_KEYS = %w[artifact_type schema_version register_id requirement_id subject authority_domain identity standard_identifier standard_version effective_date definition_source report_definition_sha256 date reviewer].freeze
  BATCH_G_SIGNED_REPORT_DEFINITION_ARTIFACT_TYPE = 'g0_batch_g_signed_report_definition'
  BATCH_G_SIGNED_REPORT_DEFINITION_KEYS = %w[artifact_type schema_version register_id requirement_id subject semantic_digest definition_sha256 grain distinct_key numerator denominator inclusions exclusions parameters source_bindings period_basis standard_identifier standard_version effective_date definition_source date author_identity reviewer].freeze
  BATCH_G_SIGNED_REPORT_SOURCE_BINDING_KEYS = %w[batch requirement_id source_entity source_owner_authority resolution_reference resolution_sha256].freeze
  BATCH_G_SIGNED_REPORT_PERIOD_KEYS = %w[time_basis timezone cutoff_policy period_close_policy].freeze
  BATCH_G_CONSOLIDATION_ARTIFACT_KEYS = %w[artifact_type schema_version register_id candidate_id members target_requirement_id terminal_owner_identity terminal_authority_domain terminal_approval_reference terminal_approval_sha256 declared_parameters member_mappings date author_identity reviewer].freeze
  BATCH_G_CONSOLIDATION_MEMBER_MAPPING_KEYS = %w[semantic_parameter_values definition_sha256 source_requirement_ids mapped_control_totals authority_domains approval_reference approval_sha256].freeze
  BATCH_G_PARAMETER_ARTIFACT_TYPE = 'g0_batch_g_canonical_parameters'
  BATCH_G_RERUN_ARTIFACT_TYPE = 'g0_batch_g_deterministic_rerun'
  BATCH_G_ACCESS_ARTIFACT_TYPE = 'g0_batch_g_access_export_receipt'
  BATCH_G_AUDIT_ARTIFACT_TYPE = 'g0_batch_g_audit_receipt'
  BATCH_G_AUTHORITY_APPROVAL_ARTIFACT_TYPE = 'g0_batch_g_control_authority_approval'
  BATCH_G_PARAMETER_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id parameter_values canonical_parameter_sha256 date author_identity reviewer].freeze
  BATCH_G_RERUN_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id execution_id definition_sha256 parameter_sha256 snapshot_id source_roots cutoff_at rows output_sha256 author_identity date reviewer].freeze
  BATCH_G_ACCESS_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id identity role cohort_scope permitted_fields prohibited_fields export_reason output_sha256 watermark retention_class masked small_cell_suppression_applied policy_sha256 date reviewer].freeze
  BATCH_G_AUDIT_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id output_sha256 events audit_root_sha256 author_identity date reviewer].freeze
  BATCH_G_AUDIT_EVENT_KEYS = %w[event_type event_id actor_identity role cohort_scope occurred_at outcome reason output_sha256].freeze
  BATCH_G_APPROVAL_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id subject identity authority_domain scope date decision_status canonical_disposition conditions control_manifest_sha256 definition_sha256 source_resolution_sha256s intra_resolution_sha256s reconciliation_sha256 output_boundary_sha256 statutory_definition_sha256 authority_bindings reviewer].freeze
  BATCH_G_CONTROL_APPROVAL_BINDING_KEYS = %w[authority_domain identity appointment_reference appointment_sha256 approval_reference approval_sha256].freeze
  BATCH_G_AUTHORITY_APPROVAL_KEYS = %w[artifact_type schema_version register_id requirement_id subject authority_domain identity control_manifest_sha256 decision_status canonical_disposition date reviewer].freeze
  GOVERNANCE_ARTIFACT_TYPE = 'g0_parity_governance_attestation'
  EVIDENCE_ARTIFACT_TYPE = 'g0_parity_evidence'
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
    'accountable_owner' => %w[artifact_type schema_version register_id requirement_id subject identity authority_domain scope date reviewer],
    'appointment_dependency' => %w[artifact_type schema_version register_id requirement_id subject identity authority_domain scope date reviewer],
    'approval' => %w[artifact_type schema_version register_id requirement_id subject identity scope date decision_status canonical_disposition conditions reviewer]
  }.freeze
  AUTHORITY_BOUND_APPROVAL_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id subject identity authority_domain scope date decision_status canonical_disposition conditions reviewer].freeze
  EVIDENCE_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id evidence_class date source reference interpreter confidence reviewer].freeze
  BATCH_D_EVIDENCE_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id evidence_class evidence_basis date source reference interpreter confidence reviewer].freeze
  LIFECYCLE_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id lifecycle_states lifecycle_transitions correction_rules date author_identity reviewer].freeze
  MAPPING_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id target_reference coverage_status mapped_fields mapped_states unmapped_items exclusions date author_identity reviewer].freeze
  UPSTREAM_RESOLUTION_ARTIFACT_KEYS = %w[artifact_type schema_version register_id requirement_id subject dependency_kind dependency_reference scope status resolution identity authority_domain upstream_batch upstream_source_id upstream_source_sha256 upstream_decision_status upstream_approval_sha256 date reviewer].freeze

  OWNER_POLICY_SCHEMA_VERSION = 1
  OWNER_KEY_REGISTRY_ID = 'G0-INSTITUTIONAL-IDENTITY-KEY-REGISTRY-2026-08-26'
  OWNER_POLICY_ID = 'G0-OWNER-AUTHORITY-POLICY-2026-08-25'
  OWNER_SOURCE_REVISION = 'cbad920453274f8ae79f28a00496538bae0ad0fe'
  OWNER_APPOINTMENT_REGISTER_ID = 'G0-OWNER-APPOINTMENTS-2026-08-25'
  OWNER_DECISION_SESSION_REGISTER_ID = 'G0-DECISION-SESSIONS-2026-08-25'
  OWNER_EVIDENCE_DIRECTORY = 'G0_OWNER_DECISION_EVIDENCE_2026-08-25'
  OWNER_ALLOWED_ALGORITHMS = %w[RS256].freeze
  OWNER_SIGNATURE_PURPOSES = %w[registry_root policy_approval appointment_acceptance appointment_issuance registry_review lifecycle_event decision_vote session_review automated_integrity_receipt].freeze
  OWNER_SIGNATURE_ARTIFACT_TYPES = {
    'registry_root' => 'institutional_identity_registry_root_signature',
    'policy_approval' => 'owner_authority_policy_approval_signature',
    'appointment_acceptance' => 'owner_appointment_acceptance_signature',
    'appointment_issuance' => 'owner_appointment_issuance_signature',
    'registry_review' => 'owner_registry_review_receipt',
    'lifecycle_event' => 'owner_appointment_lifecycle_event_signature',
    'decision_vote' => 'owner_decision_vote_signature',
    'session_review' => 'owner_decision_session_review_receipt',
    'automated_integrity_receipt' => 'owner_automated_integrity_receipt'
  }.freeze
  OWNER_KEY_REGISTRY_KEYS = %w[schema_version registry_id registry_status data_boundary source_revision snapshot_id snapshot_revision snapshot_at prior_snapshot_reference prior_snapshot_sha256 identities registry_root].freeze
  OWNER_REGISTRY_IDENTITY_KEYS = %w[subject_id identity_type display_name unit title status status_effective_at issuer_subject_id authorization_roles keys].freeze
  OWNER_REGISTRY_KEY_KEYS = %w[key_id fingerprint_sha256 algorithm public_key_spki_base64 allowed_purposes valid_from valid_until revoked_at revocation_reason].freeze
  OWNER_REGISTRY_ROOT_KEYS = %w[status identity_set_sha256 trust_root_sha256 snapshot_payload_sha256 signatures].freeze
  OWNER_REGISTRY_AUTHORIZATIONS = %w[institutional_trust_root identity_registry_issuer executive_sponsor appointment_issuer independent_reviewer automated_integrity_receipt].freeze
  OWNER_DECISION_ACTIONS = %w[consent recuse].freeze
  OWNER_DECISION_STATUSES = %w[approve revise defer reject].freeze
  OWNER_PERMITTED_DISPOSITIONS = %w[reproduce replace consolidate retire exclude].freeze
  OWNER_CAPACITY_DESCRIPTIONS = {
    'executive_sponsorship' => 'Executive mandate, residual-risk sponsorship and material disposition authority.',
    'product_business' => 'Product, business-scope and product-delivery decision authority.',
    'security_privacy_data' => 'Security, privacy, data governance, retention, audit and export authority.',
    'operations_recovery_integration' => 'Operations, recovery, technical integration and service-boundary authority.',
    'registration_admission' => 'Patient identity, access, registration, admission, bed and encounter-flow authority.',
    'clinical_care' => 'Emergency, outpatient, inpatient, ordering and clinical-service authority.',
    'clinical_safety' => 'Clinical governance, patient-safety and independent clinical-control authority.',
    'nursing' => 'Nursing documentation, workflow and professional-governance authority.',
    'rmik_coding' => 'RMIK, coding, record custody, completeness and mapping authority.',
    'laboratory' => 'Laboratory, pathology and microbiology authority.',
    'radiology' => 'Radiology and diagnostic-imaging authority.',
    'allied_rehabilitation' => 'Nutrition, rehabilitation and allied-health authority.',
    'blood_bank_transfusion' => 'Blood-bank, transfusion and custody authority.',
    'surgery_special_operations' => 'Surgery, theatre, ambulance, mortuary and special-service authority.',
    'pharmacy' => 'Pharmacy master, prescription, dispense and pharmacy-operations authority.',
    'warehouse_gf' => 'Warehouse, GF, procurement, inventory, cold-chain and stock-control authority.',
    'finance_control' => 'Independent accounting, finance, valuation, reconciliation and revenue-control authority.',
    'cashier_treasury' => 'Cashier, treasury, settlement and deposit execution authority.',
    'claims_bpjs_simulation' => 'Claims, coding-grouping and disabled BPJS-simulation authority.',
    'reporting_quality' => 'Reporting, management information, quality analytics and formula-owner authority.',
    'statutory_public_health' => 'Statutory sponsor and public-health definition authority.',
    'teaching_facilitation' => 'Teaching, facilitation, accessibility and affected-role authority.',
    'migration_execution' => 'Synthetic migration execution authority.',
    'data_mapping_approval' => 'Independent data-mapping approval authority.',
    'registry_review' => 'Independent appointment and decision registry-review authority.'
  }.freeze
  OWNER_BASE_ROLE_CAPACITY_MAP = {
    'accessibility' => 'teaching_facilitation', 'affected_document_owner' => 'rmik_coding',
    'affected_domain_owner' => 'operations_recovery_integration', 'affected_role_owner' => 'teaching_facilitation',
    'ambulance_transport' => 'surgery_special_operations', 'anatomical_pathology' => 'laboratory',
    'biomedical_equipment' => 'operations_recovery_integration', 'blood_bank' => 'blood_bank_transfusion',
    'cashier_revenue' => 'cashier_treasury', 'claims_simulation' => 'claims_bpjs_simulation',
    'clinical' => 'clinical_care', 'clinical_access' => 'clinical_care', 'clinical_governance' => 'clinical_safety',
    'coding_claims' => 'rmik_coding',
    'clinical_operations' => 'clinical_care', 'clinical_ordering' => 'clinical_care', 'cold_chain' => 'warehouse_gf',
    'data_migration' => 'migration_execution', 'dental_clinical' => 'clinical_care',
    'emergency_clinical' => 'clinical_care', 'facility_bed_management' => 'registration_admission',
    'finance_accounting' => 'finance_control', 'finance_claims' => 'finance_control', 'finance_master' => 'finance_control',
    'inpatient_clinical' => 'clinical_care', 'interoperability_security' => 'operations_recovery_integration',
    'inventory_control' => 'warehouse_gf', 'inventory_supply' => 'warehouse_gf', 'laboratory' => 'laboratory',
    'legal_retention' => 'security_privacy_data', 'management_reporting' => 'reporting_quality',
    'management_target_owner' => 'product_business', 'medical_administration' => 'clinical_care',
    'microbiology' => 'laboratory', 'mortuary_operations' => 'surgery_special_operations', 'nursing' => 'nursing',
    'nursing_governance' => 'nursing', 'nutrition_dietetics' => 'allied_rehabilitation',
    'occupational_therapy' => 'allied_rehabilitation', 'operations' => 'operations_recovery_integration',
    'operations_recovery' => 'operations_recovery_integration', 'orders_results' => 'clinical_care',
    'outpatient_clinical' => 'clinical_care', 'patient_flow' => 'registration_admission',
    'patient_identity' => 'registration_admission', 'pharmacy' => 'pharmacy', 'pharmacy_gf' => 'warehouse_gf',
    'pharmacy_inventory_control' => 'warehouse_gf', 'pharmacy_master_data' => 'pharmacy',
    'pharmacy_operations' => 'pharmacy', 'procurement' => 'warehouse_gf', 'product_delivery' => 'product_business',
    'public_health_reporting' => 'statutory_public_health', 'quality_analytics' => 'reporting_quality',
    'quality_patient_safety' => 'clinical_safety', 'radiology' => 'radiology', 'record_custody' => 'rmik_coding',
    'registration_admission' => 'registration_admission', 'rehabilitation_medicine' => 'allied_rehabilitation',
    'reporting' => 'reporting_quality', 'reporting_quality' => 'reporting_quality', 'rmik' => 'rmik_coding',
    'rmik_coding' => 'rmik_coding', 'rmik_reporting' => 'reporting_quality', 'scheduling' => 'registration_admission',
    'security_privacy_data' => 'security_privacy_data', 'speech_therapy' => 'allied_rehabilitation',
    'statutory_sponsor' => 'statutory_public_health', 'surgery_anesthesia' => 'surgery_special_operations',
    'teaching_facilitation' => 'teaching_facilitation', 'theatre_operations' => 'surgery_special_operations',
    'transfusion_clinical' => 'blood_bank_transfusion', 'treasury' => 'cashier_treasury',
    'unit_operations' => 'operations_recovery_integration', 'warehouse_stock' => 'warehouse_gf',
    'workforce_identity' => 'security_privacy_data'
  }.freeze
  OWNER_SPECIAL_ROLE_DOMAIN_MAP = {
    'executive_sponsor' => 'executive_sponsor', 'data_migration_executor' => 'data_migration',
    'data_mapping_approver' => 'data_migration', 'cashier_operator' => 'cashier_revenue',
    'treasury_settlement_authorizer' => 'treasury', 'independent_finance_reconciler' => 'finance_accounting',
    'report_formula_author' => 'reporting_quality', 'appointment_registry_reviewer' => 'security_privacy_data'
  }.freeze
  OWNER_SPECIAL_ROLE_CAPACITY_MAP = {
    'executive_sponsor' => 'executive_sponsorship', 'data_migration_executor' => 'migration_execution',
    'data_mapping_approver' => 'data_mapping_approval', 'cashier_operator' => 'cashier_treasury',
    'treasury_settlement_authorizer' => 'cashier_treasury', 'independent_finance_reconciler' => 'finance_control',
    'report_formula_author' => 'reporting_quality', 'appointment_registry_reviewer' => 'registry_review'
  }.freeze
  OWNER_ROLE_CAPACITY_MAP = OWNER_BASE_ROLE_CAPACITY_MAP.merge(OWNER_SPECIAL_ROLE_CAPACITY_MAP).freeze
  OWNER_ROLE_DOMAIN_MAP = OWNER_BASE_ROLE_CAPACITY_MAP.keys.to_h { |role| [role, role] }.merge(OWNER_SPECIAL_ROLE_DOMAIN_MAP).freeze
  OWNER_COMPATIBILITY_WHITELIST = [
    %w[clinical_care clinical_safety], %w[product_business teaching_facilitation],
    %w[rmik_coding reporting_quality], %w[pharmacy warehouse_gf],
    %w[allied_rehabilitation clinical_care]
  ].map(&:sort).sort.freeze
  OWNER_INCOMPATIBLE_ROLE_PAIRS = [
    %w[data_migration_executor data_mapping_approver],
    %w[cashier_operator independent_finance_reconciler],
    %w[treasury_settlement_authorizer independent_finance_reconciler],
    %w[report_formula_author statutory_sponsor]
  ].map(&:sort).sort.freeze
  OWNER_BATCH_A_ACCOUNTABLE_AUTHORITIES = {
    'PAR-ADM-001' => 'security_privacy_data', 'PAR-ADM-002' => 'security_privacy_data',
    'PAR-ADM-003' => 'operations', 'PAR-ADM-005' => 'operations',
    'PAR-ADM-006' => 'medical_administration', 'PAR-ADM-008' => 'rmik',
    'PAR-ADM-012' => 'operations', 'PAR-ADM-013' => 'registration_admission',
    'PAR-ADM-022' => 'rmik', 'PAR-ADM-023' => 'rmik', 'PAR-ADM-024' => 'rmik',
    'PAR-ADM-025' => 'rmik', 'PAR-ADM-032' => 'rmik',
    'PAR-ADM-037' => 'security_privacy_data', 'PAR-ADM-038' => 'rmik',
    'PAR-ADM-040' => 'operations', 'PAR-ADM-044' => 'rmik', 'PAR-ADM-045' => 'rmik',
    'PAR-HLP-001' => 'teaching_facilitation', 'PAR-IOT-001' => 'operations'
  }.freeze
  OWNER_SESSION_SEQUENCE = [
    { 'sequence' => 0, 'session_code' => 'S0', 'scope' => 'Appointment ratification and bounded T0 authority checks' },
    *('A'..'G').each_with_index.map { |batch, index| { 'sequence' => index + 1, 'session_code' => "S#{index + 1}", 'scope' => "Batch #{batch} capability decisions" } }
  ].freeze
  OWNER_DISPOSITION_RULES = {
    'reproduce' => { 'additional_authority_roles' => [], 'member_and_terminal_owners' => false, 'unresolved_gate_owners' => false },
    'replace' => { 'additional_authority_roles' => %w[executive_sponsor], 'member_and_terminal_owners' => false, 'unresolved_gate_owners' => false },
    'consolidate' => { 'additional_authority_roles' => %w[executive_sponsor security_privacy_data], 'member_and_terminal_owners' => true, 'unresolved_gate_owners' => false },
    'retire' => { 'additional_authority_roles' => %w[executive_sponsor operations rmik security_privacy_data], 'member_and_terminal_owners' => false, 'unresolved_gate_owners' => false },
    'exclude' => { 'additional_authority_roles' => %w[executive_sponsor operations rmik security_privacy_data], 'member_and_terminal_owners' => false, 'unresolved_gate_owners' => false },
    'defer' => { 'additional_authority_roles' => %w[executive_sponsor security_privacy_data], 'member_and_terminal_owners' => false, 'unresolved_gate_owners' => true }
  }.freeze
  OWNER_POLICY_KEYS = %w[schema_version policy_id policy_status data_boundary source_revision snapshot_id snapshot_revision snapshot_at prior_snapshot_reference prior_snapshot_sha256 control_root_sha256 manifest_reference manifest_sha256 source_decision_registers authority_capacities authority_roles compatibility_whitelist incompatible_role_pairs separation_rules disposition_rules session_rules session_sequence requirement_policies approval].freeze
  OWNER_SOURCE_REGISTER_KEYS = %w[batch register_id reference sha256 snapshot_id snapshot_revision captured_at cutoff_at content_root_sha256 prior_snapshot_reference prior_snapshot_sha256].freeze
  OWNER_SNAPSHOT_PLAN_KEYS = %w[schema_version data_boundary policy sources].freeze
  OWNER_SNAPSHOT_PLAN_POLICY_KEYS = %w[snapshot_id snapshot_revision snapshot_at prior_snapshot_reference prior_snapshot_sha256].freeze
  OWNER_SNAPSHOT_PLAN_SOURCE_KEYS = %w[batch snapshot_id snapshot_revision captured_at cutoff_at prior_snapshot_reference prior_snapshot_sha256].freeze
  DEFAULT_OWNER_SNAPSHOT_PLAN_PATH = 'docs/new-simrs-rebuild/phase-0/G0_OWNER_GOVERNANCE_SNAPSHOT_PLAN_2026-08-25.json'
  OWNER_SOURCE_SNAPSHOT_ARTIFACT_KEYS = %w[artifact_type schema_version descriptor register].freeze
  OWNER_SOURCE_SNAPSHOT_ARTIFACT_TYPE = 'g0_owner_source_register_snapshot'
  OWNER_CAPACITY_KEYS = %w[capacity_id description].freeze
  OWNER_ROLE_KEYS = %w[authority_role authority_domain capacity_id].freeze
  OWNER_SEPARATION_RULE_KEYS = %w[rule_id first_role second_role rationale].freeze
  OWNER_POLICY_SESSION_KEYS = %w[consent_rule unclassified_co_owner_rule minimum_unique_people_ordinary minimum_unique_people_independent recusal_rule parallel_revision_rule post_signature_mutation_rule chair_authority_role facilitator_authority_role officer_separation_rule].freeze
  OWNER_REQUIREMENT_POLICY_KEYS = %w[requirement_id batch batch_register_id batch_register_sha256 source_row_sha256 accountable_authority_domain required_authority_domains required_special_roles eligible_authority_roles applicable_independent_controls statutory_scope].freeze
  OWNER_POLICY_APPROVAL_KEYS = %w[status identity authority_role date reference artifact_sha256].freeze
  OWNER_POLICY_APPROVAL_ARTIFACT_TYPE = 'g0_owner_authority_policy_approval'
  OWNER_POLICY_APPROVAL_ARTIFACT_KEYS = %w[artifact_type schema_version policy_id policy_control_sha256 identity authority_role mandate_reference date signature_artifact_type key_id algorithm purpose signed_at semantic_payload_sha256 identity_registry_id identity_registry_snapshot_id identity_registry_snapshot_revision identity_registry_snapshot_sha256 identity_registry_root_sha256 signature reviewer].freeze
  OWNER_APPOINTMENT_REGISTER_KEYS = %w[schema_version register_id register_status data_boundary policy_reference policy_sha256 manifest_reference manifest_sha256 source_decision_registers evidence_directory appointments events].freeze
  OWNER_APPOINTMENT_KEYS = %w[appointment_id appointment_register_id subject authority_domain authority_role capacity_id scope decision_rights data_boundary issuer effective_at expires_at conflict_disclosure delegation policy_sha256 manifest_sha256 source_register_sha256s acceptance issuer_signature registry_receipt canonical_signed_payload_sha256].freeze
  OWNER_SUBJECT_KEYS = %w[institutional_id identity_type display_name title unit].freeze
  OWNER_SCOPE_KEYS = %w[requirement_ids manifest_batches].freeze
  OWNER_DECISION_RIGHTS_KEYS = %w[session_actions permitted_decision_statuses permitted_dispositions].freeze
  OWNER_ISSUER_KEYS = %w[institutional_id authority_role mandate_reference].freeze
  OWNER_CONFLICT_KEYS = %w[status details].freeze
  OWNER_DELEGATION_KEYS = %w[parent_appointment_id scope_requirement_ids depth may_redelegate].freeze
  OWNER_SIGNATURE_REGISTRY_BINDING_KEYS = %w[identity_registry_id identity_registry_snapshot_id identity_registry_snapshot_revision identity_registry_snapshot_sha256 identity_registry_root_sha256].freeze
  OWNER_SIGNATURE_KEYS = %w[signature_artifact_type signer_institutional_id key_id algorithm purpose signed_at semantic_payload_sha256 identity_registry_id identity_registry_snapshot_id identity_registry_snapshot_revision identity_registry_snapshot_sha256 identity_registry_root_sha256 signature].freeze
  OWNER_REGISTRY_RECEIPT_KEYS = %w[signature_artifact_type reviewer_institutional_id evidence_author_institutional_id implementer_institutional_id verification_method verification_reference verified_at reviewed_payload_sha256 key_id algorithm purpose semantic_payload_sha256 identity_registry_id identity_registry_snapshot_id identity_registry_snapshot_revision identity_registry_snapshot_sha256 identity_registry_root_sha256 signature].freeze
  OWNER_EVENT_KEYS = %w[event_id appointment_id parent_appointment_id event_type effective_at reason issuer_institutional_id prior_event_sha256 signature_artifact_type key_id algorithm purpose signed_at canonical_signed_payload_sha256 identity_registry_id identity_registry_snapshot_id identity_registry_snapshot_revision identity_registry_snapshot_sha256 identity_registry_root_sha256 event_sha256 signature].freeze
  OWNER_DECISION_SESSION_REGISTER_KEYS = %w[schema_version register_id register_status data_boundary policy_reference policy_sha256 appointment_register_reference appointment_register_sha256 manifest_reference manifest_sha256 source_decision_registers sessions].freeze
  OWNER_SESSION_KEYS = %w[session_id sequence session_revision session_code status started_at ended_at policy_sha256 manifest_sha256 appointment_snapshot_cutoff appointment_snapshot_count appointment_snapshot_root_sha256 appointment_event_prefix_count appointment_event_prefix_sha256 source_register_sha256s chair_appointment_id facilitator_appointment_id prior_session_sha256 decisions canonical_session_sha256 registry_receipt].freeze
  OWNER_SESSION_DECISION_KEYS = %w[requirement_id batch decision_revision session_id policy_sha256 decided_at row_sha256 batch_register_sha256 source_decision_sha256 decision_status disposition target_reference target_requirement_id exclusions conditions consolidation_member_ids unresolved_gate_authority_domains evidence_roots control_roots appointment_digests prior_decision_sha256 decision_sha256 votes].freeze
  OWNER_APPOINTMENT_DIGEST_KEYS = %w[appointment_id appointment_sha256].freeze
  OWNER_VOTE_KEYS = %w[vote_id session_id policy_sha256 batch_register_sha256 seat_requirement_id appointment_id subject_institutional_id authority_domain authority_role vote recusal_reason decision_status disposition row_sha256 decision_sha256 evidence_roots control_roots appointment_sha256 signature_artifact_type signed_at key_id algorithm purpose canonical_signed_payload_sha256 identity_registry_id identity_registry_snapshot_id identity_registry_snapshot_revision identity_registry_snapshot_sha256 identity_registry_root_sha256 signature].freeze

  PAR_ID_PATTERN = /\APAR-[A-Z0-9]+-\d{3}\z/.freeze
  REL_ID_PATTERN = /\AREL-(\d{8})-(\d{2})\z/.freeze
  PLACEHOLDER_OWNER_PATTERN = /(?:\bTBD\b|\bunknown\b|\bunassigned\b|\bpending\b|to[ _-]?be[ _-]?assigned|replace[ _-]?with|\bN\/?A\b)/i.freeze
  EVIDENCE_PLACEHOLDER_PATTERN = /(?:\bpending\b|not (?:committed|pushed|deployed|verified)|filled at commit|updated after push|\bunknown\b|\bTBD\b|\bN\/?A\b)/i.freeze

  attr_reader :batch_assignments, :decision_entries, :decision_entries_by_batch, :errors, :rows, :release_rows

  def initialize(matrix_path:, baseline_path:, release_index_path:, batch_manifest_path: 'docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json', decision_register_path: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_A_DECISION_REGISTER_2026-08-25.json', batch_b_decision_register_path: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_B_DECISION_REGISTER_2026-08-25.json', batch_c_decision_register_path: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_C_DECISION_REGISTER_2026-08-25.json', batch_d_decision_register_path: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_D_DECISION_REGISTER_2026-08-25.json', batch_e_decision_register_path: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_E_DECISION_REGISTER_2026-08-25.json', batch_f_decision_register_path: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_F_DECISION_REGISTER_2026-08-25.json', batch_g_decision_register_path: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_G_DECISION_REGISTER_2026-08-25.json', institutional_identity_key_registry_path: 'docs/new-simrs-rebuild/phase-0/G0_INSTITUTIONAL_IDENTITY_KEY_REGISTRY_2026-08-26.json', trusted_identity_root_sha256: nil, owner_snapshot_plan_path: DEFAULT_OWNER_SNAPSHOT_PLAN_PATH, owner_authority_policy_path: 'docs/new-simrs-rebuild/phase-0/G0_OWNER_AUTHORITY_POLICY_2026-08-25.json', owner_appointment_register_path: 'docs/new-simrs-rebuild/phase-0/G0_OWNER_APPOINTMENT_REGISTER_2026-08-25.json', decision_session_register_path: 'docs/new-simrs-rebuild/phase-0/G0_DECISION_SESSION_REGISTER_2026-08-25.json', owner_evidence_root_path: nil, mode: 'integrity')
    @matrix_path = File.expand_path(matrix_path)
    @baseline_path = File.expand_path(baseline_path)
    @release_index_path = File.expand_path(release_index_path)
    @batch_manifest_path = File.expand_path(batch_manifest_path)
    supplied_register_paths = {
      'A' => File.expand_path(decision_register_path),
      'B' => File.expand_path(batch_b_decision_register_path),
      'C' => File.expand_path(batch_c_decision_register_path),
      'D' => File.expand_path(batch_d_decision_register_path),
      'E' => File.expand_path(batch_e_decision_register_path),
      'F' => File.expand_path(batch_f_decision_register_path),
      'G' => File.expand_path(batch_g_decision_register_path)
    }
    @decision_register_paths = DECISION_REGISTER_CONFIGS.keys.to_h do |batch|
      [batch, supplied_register_paths.fetch(batch)]
    end
    @institutional_identity_key_registry_path = File.expand_path(institutional_identity_key_registry_path)
    @trusted_identity_root_sha256 = trusted_identity_root_sha256
    @owner_snapshot_plan_path = File.expand_path(owner_snapshot_plan_path)
    @owner_snapshot_plan_loaded = false
    @owner_snapshot_plan = nil
    @owner_authority_policy_path = File.expand_path(owner_authority_policy_path)
    @owner_appointment_register_path = File.expand_path(owner_appointment_register_path)
    @decision_session_register_path = File.expand_path(decision_session_register_path)
    @owner_evidence_root_path = owner_evidence_root_path && File.expand_path(owner_evidence_root_path)
    @mode = mode
    @batch_assignments = {}
    @decision_entries = []
    @decision_entries_by_batch = {}
    @decision_register_statuses = {}
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
    decision_registers = DECISION_REGISTER_CONFIGS.keys.to_h { |batch| [batch, load_decision_register(batch)] }
    parse_matrix
    parse_release_index

    validate_matrix_header
    validate_matrix_shape_and_cells
    validate_exact_id_set(baseline)
    validate_categories_and_prefixes(baseline)
    validate_batch_manifest(baseline, manifest)
    decision_registers.each do |batch, register|
      @decision_entries_by_batch[batch] = validate_decision_register(baseline, manifest, register, batch)
    end
    validate_decision_register_consolidation_graph(@decision_entries_by_batch, baseline['expected'])
    @owner_key_registry_snapshots = {}
    @owner_key_registry_activation_times = {}
    @owner_source_snapshots = {}
    @owner_policy_snapshots = {}
    @owner_policy_activation_times = {}
    identity_registry = load_owner_governance_json(@institutional_identity_key_registry_path, 'institutional identity/key registry')
    @owner_key_registry = identity_registry
    validate_owner_key_registry(identity_registry)
    owner_policy = load_owner_governance_json(@owner_authority_policy_path, 'owner authority policy')
    @owner_policy = owner_policy
    appointment_register = load_owner_governance_json(@owner_appointment_register_path, 'owner appointment register')
    session_register = load_owner_governance_json(@decision_session_register_path, 'decision session register')
    validate_owner_authority_policy(owner_policy, manifest)
    validate_owner_appointment_register(appointment_register, owner_policy, manifest)
    validate_owner_decision_session_register(session_register, appointment_register, owner_policy, manifest)
    validate_vocabularies
    validate_owners
    validate_consolidations
    validate_release_register
    validate_accepted_rows

    errors.empty?
  rescue ArgumentError => e
    errors << "owner governance canonicalization failed closed: #{e.message}"
    false
  end

  # Builds deterministic candidate documents in memory. Callers remain responsible
  # for writing them to an isolated directory and for verifying the emitted bundle.
  def build_owner_governance_snapshot_candidate
    @batch_assignments = {}
    @decision_entries = []
    @decision_entries_by_batch = {}
    @decision_register_statuses = {}
    @errors = []
    @rows = []
    @owner_source_snapshots = {}
    @owner_policy_snapshots = {}
    @owner_policy_activation_times = {}

    baseline = load_baseline
    manifest = load_batch_manifest
    decision_registers = DECISION_REGISTER_CONFIGS.keys.to_h { |batch| [batch, load_decision_register(batch)] }
    parse_matrix
    validate_matrix_header
    validate_matrix_shape_and_cells
    validate_exact_id_set(baseline)
    validate_categories_and_prefixes(baseline)
    validate_batch_manifest(baseline, manifest)
    decision_registers.each do |batch, register|
      @decision_entries_by_batch[batch] = validate_decision_register(baseline, manifest, register, batch)
    end
    validate_decision_register_consolidation_graph(@decision_entries_by_batch, baseline['expected'])
    owner_snapshot_plan

    @owner_key_registry_snapshots = {}
    @owner_key_registry_activation_times = {}
    @owner_key_registry = load_owner_governance_json(@institutional_identity_key_registry_path, 'institutional identity/key registry')
    validate_owner_key_registry(@owner_key_registry)

    policy = expected_owner_authority_policy(manifest)
    @owner_policy = policy
    validate_owner_authority_policy(policy, manifest)
    canonical_policy = load_owner_governance_json(@owner_authority_policy_path, 'current canonical owner authority policy')
    validate_owner_candidate_snapshot_identity_reuse(policy, canonical_policy)

    appointment_register = load_owner_governance_json(@owner_appointment_register_path, 'current canonical owner appointment register')
    session_register = load_owner_governance_json(@decision_session_register_path, 'current canonical decision session register')
    errors << 'owner governance snapshot candidate: canonical appointments must be an array' unless appointment_register['appointments'].is_a?(Array)
    errors << 'owner governance snapshot candidate: canonical lifecycle events must be an array' unless appointment_register['events'].is_a?(Array)
    errors << 'owner governance snapshot candidate: canonical sessions must be an array' unless session_register['sessions'].is_a?(Array)
    return nil unless errors.empty?

    policy_bytes = JSON.pretty_generate(policy) + "\n"
    policy_sha = Digest::SHA256.hexdigest(policy_bytes)
    appointment_top = expected_owner_appointment_register_top(
      policy,
      policy_reference: File.basename(@owner_authority_policy_path),
      policy_sha256: policy_sha
    )
    appointment_candidate = appointment_top.merge(
      'appointments' => appointment_register['appointments'],
      'events' => appointment_register['events']
    )
    appointment_bytes = JSON.pretty_generate(appointment_candidate) + "\n"
    appointment_sha = Digest::SHA256.hexdigest(appointment_bytes)
    session_top = expected_owner_decision_session_register_top(
      policy,
      policy_reference: File.basename(@owner_authority_policy_path),
      policy_sha256: policy_sha,
      appointment_reference: File.basename(@owner_appointment_register_path),
      appointment_sha256: appointment_sha
    )
    session_candidate = session_top.merge('sessions' => session_register['sessions'])
    {
      'policy' => policy,
      'appointment_register' => appointment_candidate,
      'decision_session_register' => session_candidate
    }
  rescue ArgumentError => e
    errors << "owner governance snapshot candidate canonicalization failed closed: #{e.message}"
    nil
  end

  private

  def validate_owner_candidate_snapshot_identity_reuse(candidate, canonical)
    return unless candidate.is_a?(Hash) && canonical.is_a?(Hash)

    canonical_sources = Array(canonical['source_decision_registers']).to_h do |source|
      [[source['batch'], source['snapshot_id'], source['snapshot_revision']], source]
    end
    Array(candidate['source_decision_registers']).each do |source|
      prior = canonical_sources[[source['batch'], source['snapshot_id'], source['snapshot_revision']]]
      next unless prior

      same_bytes = source['sha256'] == prior['sha256'] && source['content_root_sha256'] == prior['content_root_sha256'] && source['register_id'] == prior['register_id']
      errors << "owner governance snapshot candidate: Batch #{source['batch']} live bytes changed but snapshot identity metadata was reused" unless same_bytes
    end
  end

  def load_owner_governance_json(path, label)
    unless File.file?(path)
      errors << "#{label}: file not found: #{path}"
      return {}
    end

    value = owner_parse_json(File.read(path))
    unless value.is_a?(Hash)
      errors << "#{label}: must contain one JSON object"
      return {}
    end
    value
  rescue JSON::ParserError => e
    errors << "#{label}: invalid JSON: #{e.message}"
    {}
  rescue SystemCallError => e
    errors << "#{label}: cannot read file: #{e.message}"
    {}
  end

  def owner_parse_json(source)
    JSON.parse(source, object_class: DuplicateKeyHash, allow_duplicate_key: false)
  end

  def owner_canonical_value(value)
    case value
    when Hash
      value.keys.each { |key| raise ArgumentError, 'canonical JSON keys must be ASCII strings' unless key.is_a?(String) && key.ascii_only? }
      value.keys.sort.each_with_object({}) { |key, result| result[key] = owner_canonical_value(value[key]) }
    when Array
      value.map { |item| owner_canonical_value(item) }
    when String
      normalized = value.encode(Encoding::UTF_8).unicode_normalize(:nfc)
      if normalized.match?(/\A\d{4}-\d{2}-\d{2}T/)
        Time.iso8601(normalized).utc.iso8601
      else
        normalized
      end
    when Integer
      raise ArgumentError, 'canonical JSON integer is outside int64' unless value.between?(-(2**63), (2**63) - 1)
      value
    when Float
      raise ArgumentError, 'canonical JSON forbids floating-point values'
    when TrueClass, FalseClass, NilClass
      value
    else
      raise ArgumentError, "canonical JSON value type #{value.class} is forbidden"
    end
  end

  def owner_canonical_json(value)
    JSON.generate(owner_canonical_value(value), ascii_only: true).encode(Encoding::UTF_8)
  end

  def owner_signature_message(purpose, payload_bytes)
    domain = 'SIMRS-UEU-G0-OWNER-SIGNATURE-V1'.b
    purpose_bytes = purpose.to_s.encode(Encoding::UTF_8)
    [domain.bytesize].pack('N') + domain + [purpose_bytes.bytesize].pack('N') + purpose_bytes + [payload_bytes.bytesize].pack('Q>') + payload_bytes
  end

  def expected_empty_owner_key_registry
    identity_sha = Digest::SHA256.hexdigest(JSON.generate([]))
    {
      'schema_version' => OWNER_POLICY_SCHEMA_VERSION,
      'registry_id' => OWNER_KEY_REGISTRY_ID,
      'registry_status' => 'proposal',
      'data_boundary' => 'synthetic_only',
      'source_revision' => OWNER_SOURCE_REVISION,
      'snapshot_id' => nil,
      'snapshot_revision' => 0,
      'snapshot_at' => nil,
      'prior_snapshot_reference' => nil,
      'prior_snapshot_sha256' => nil,
      'identities' => [],
      'registry_root' => {
        'status' => 'pending', 'identity_set_sha256' => identity_sha, 'trust_root_sha256' => nil,
        'snapshot_payload_sha256' => nil, 'signatures' => []
      }
    }
  end

  def validate_owner_key_registry(registry)
    label = 'institutional identity/key registry'
    validate_closed_object(registry, OWNER_KEY_REGISTRY_KEYS, label)
    return unless registry.is_a?(Hash)

    errors << "#{label}: fixed metadata and synthetic boundary are invalid" unless registry['schema_version'] == OWNER_POLICY_SCHEMA_VERSION && registry['registry_id'] == OWNER_KEY_REGISTRY_ID && registry['data_boundary'] == 'synthetic_only' && registry['source_revision'] == OWNER_SOURCE_REVISION
    errors << "#{label}: identities must be an array" unless registry['identities'].is_a?(Array)
    validate_closed_object(registry['registry_root'], OWNER_REGISTRY_ROOT_KEYS, "#{label} registry_root")
    errors << "#{label}: must not contain credentials, private keys, tokens or recovery material" if owner_contains_secret?(registry)
    if registry['registry_status'] == 'proposal'
      errors << "#{label}: proposal must remain the exact empty, unsigned registry" unless registry == expected_empty_owner_key_registry
      return
    end
    errors << "#{label}: registry_status must be proposal or active" unless registry['registry_status'] == 'active'
    return unless registry['registry_status'] == 'active'

    revision = registry['snapshot_revision']
    chain_valid = revision.is_a?(Integer) && revision.positive? && ((revision == 1 && registry['prior_snapshot_reference'].nil? && registry['prior_snapshot_sha256'].nil?) || (revision > 1 && nonempty_string?(registry['prior_snapshot_reference']) && registry['prior_snapshot_sha256'].to_s.match?(/\A[0-9a-f]{64}\z/)))
    errors << "#{label}: active snapshot metadata and linear prior-snapshot chain must be complete" unless nonempty_string?(registry['snapshot_id']) && iso_datetime?(registry['snapshot_at']) && chain_valid
    identities = Array(registry['identities'])
    errors << "#{label}: active registry requires identities" if identities.empty?
    subject_ids = identities.map { |identity| identity['subject_id'] if identity.is_a?(Hash) }.compact
    errors << "#{label}: subject IDs must be globally unique" unless subject_ids.uniq == subject_ids
    key_ids = []
    identities.each_with_index do |identity, index|
      validate_owner_registry_identity(identity, index, identities)
      key_ids.concat(Array(identity['keys']).map { |key| key['key_id'] if key.is_a?(Hash) }.compact)
    end
    errors << "#{label}: key IDs must be globally unique" unless key_ids.uniq == key_ids
    identity_bytes = owner_canonical_json(identities)
    identity_sha = Digest::SHA256.hexdigest(identity_bytes)
    roots = identities.select { |identity| Array(identity['authorization_roles']).include?('institutional_trust_root') }
    trust_material = roots.map do |identity|
      { 'subject_id' => identity['subject_id'], 'keys' => Array(identity['keys']).map { |key| { 'key_id' => key['key_id'], 'fingerprint_sha256' => key['fingerprint_sha256'] } } }
    end
    trust_root_sha = Digest::SHA256.hexdigest(owner_canonical_json(trust_material))
    root = registry['registry_root'] || {}
    owner_register_identity_snapshot(registry)
    root_payload = owner_registry_root_payload(registry, identity_sha, trust_root_sha)
    root_bytes = owner_canonical_json(root_payload)
    errors << "#{label}: root must bind the exact identity set, independently pinned trust root and snapshot payload" unless root['status'] == 'signed' && root['identity_set_sha256'] == identity_sha && root['trust_root_sha256'] == trust_root_sha && root['snapshot_payload_sha256'] == Digest::SHA256.hexdigest(root_bytes)
    signatures = Array(root['signatures'])
    signature_results = signatures.each_with_index.map do |signature, index|
      validate_closed_object(signature, OWNER_SIGNATURE_KEYS, "#{label} registry_root signatures[#{index}]")
      signature_valid = owner_verify_detached_signature(signature, signature['signer_institutional_id'], root_bytes, 'registry_root', signature['signed_at'], "#{label} registry_root signatures[#{index}]", required_authorization: 'institutional_trust_root')
      errors << "#{label}: registry-root signatures must be at or after the frozen snapshot timestamp" unless owner_time(signature['signed_at']) && owner_time(registry['snapshot_at']) && owner_time(signature['signed_at']) >= owner_time(registry['snapshot_at'])
      signature_valid
    end
    root_signers = signatures.map { |signature| signature['signer_institutional_id'] }.uniq
    errors << "#{label}: snapshot root requires signatures from at least two distinct human trust-root authorities" unless root_signers.length >= 2 && root_signers.all? { |subject_id| owner_person_id?(subject_id) }
    activation_candidates = [owner_time(registry['snapshot_at']), *signatures.map { |signature| owner_time(signature['signed_at']) }]
    if root_valid = root['status'] == 'signed' && root['identity_set_sha256'] == identity_sha && root['trust_root_sha256'] == trust_root_sha && root['snapshot_payload_sha256'] == Digest::SHA256.hexdigest(root_bytes)
      owner_set_identity_snapshot_activation(registry, activation_candidates.compact.max) if signature_results.all? && root_signers.length >= 2 && activation_candidates.all?
    end
    validate_owner_prior_registry_chain(registry, label)
  end

  def validate_owner_prior_registry_chain(registry, label, visited = [])
    revision = registry['snapshot_revision']
    return unless revision.is_a?(Integer) && revision > 1

    reference = registry['prior_snapshot_reference']
    if visited.include?(reference)
      errors << "#{label}: prior snapshot chain contains a cycle"
      return
    end
    previous = load_owner_evidence_artifact(reference, registry['prior_snapshot_sha256'], "#{label} prior snapshot")
    return unless previous

    prior_label = "#{label} prior snapshot revision #{revision - 1}"
    validate_closed_object(previous, OWNER_KEY_REGISTRY_KEYS, prior_label)
    return unless previous.is_a?(Hash)

    metadata_valid = previous['schema_version'] == OWNER_POLICY_SCHEMA_VERSION && previous['registry_id'] == OWNER_KEY_REGISTRY_ID && previous['registry_status'] == 'active' && previous['data_boundary'] == 'synthetic_only' && previous['source_revision'] == OWNER_SOURCE_REVISION && previous['snapshot_revision'] == revision - 1
    errors << "#{prior_label}: must be the exact immediately preceding active registry snapshot" unless metadata_valid
    previous_identities = Array(previous['identities'])
    previous_identities.each_with_index { |identity, index| validate_owner_registry_identity(identity, index, previous_identities) }
    previous_identity_sha = Digest::SHA256.hexdigest(owner_canonical_json(previous_identities))
    previous_roots = previous_identities.select { |identity| Array(identity['authorization_roles']).include?('institutional_trust_root') }
    previous_trust_material = previous_roots.map do |identity|
      { 'subject_id' => identity['subject_id'], 'keys' => Array(identity['keys']).map { |key| { 'key_id' => key['key_id'], 'fingerprint_sha256' => key['fingerprint_sha256'] } } }
    end
    previous_trust_sha = Digest::SHA256.hexdigest(owner_canonical_json(previous_trust_material))
    previous_payload = owner_registry_root_payload(previous, previous_identity_sha, previous_trust_sha)
    previous_payload_bytes = owner_canonical_json(previous_payload)
    previous_root = previous['registry_root'] || {}
    owner_register_identity_snapshot(previous)
    validate_closed_object(previous_root, OWNER_REGISTRY_ROOT_KEYS, "#{prior_label} registry_root")
    root_valid = previous_root['status'] == 'signed' && previous_root['identity_set_sha256'] == previous_identity_sha && previous_root['trust_root_sha256'] == previous_trust_sha && previous_root['snapshot_payload_sha256'] == Digest::SHA256.hexdigest(previous_payload_bytes)
    errors << "#{prior_label}: registry root is not cryptographically bound" unless root_valid
    signatures = Array(previous_root['signatures'])
    signature_results = signatures.each_with_index.map do |signature, index|
      validate_closed_object(signature, OWNER_SIGNATURE_KEYS, "#{prior_label} signatures[#{index}]")
      owner_verify_detached_signature(signature, signature['signer_institutional_id'], previous_payload_bytes, 'registry_root', signature['signed_at'], "#{prior_label} signatures[#{index}]", required_authorization: 'institutional_trust_root')
    end
    errors << "#{prior_label}: requires two distinct-person trust-root signatures" unless signatures.map { |signature| signature['signer_institutional_id'] }.uniq.length >= 2
    activation_candidates = [owner_time(previous['snapshot_at']), *signatures.map { |signature| owner_time(signature['signed_at']) }]
    if root_valid && signature_results.all? && signatures.map { |signature| signature['signer_institutional_id'] }.uniq.length >= 2 && activation_candidates.all?
      owner_set_identity_snapshot_activation(previous, activation_candidates.max)
    end
    current_by_subject = Array(registry['identities']).to_h { |identity| [identity['subject_id'], identity] }
    preserved = previous_identities.all? do |prior_identity|
      current_identity = current_by_subject[prior_identity['subject_id']]
      next false unless current_identity && current_identity['identity_type'] == prior_identity['identity_type']

      current_keys = Array(current_identity['keys']).to_h { |key| [key['key_id'], key] }
      Array(prior_identity['keys']).all? do |prior_key|
        current_key = current_keys[prior_key['key_id']]
        immutable_keys = %w[key_id fingerprint_sha256 algorithm public_key_spki_base64 allowed_purposes valid_from valid_until]
        current_key && immutable_keys.all? { |key| current_key[key] == prior_key[key] }
      end
    end
    errors << "#{prior_label}: current registry must preserve every prior subject and immutable public-key record" unless preserved
    validate_owner_prior_registry_chain(previous, label, [*visited, reference])
  end

  def validate_owner_registry_identity(identity, index, identities)
    label = "institutional identity/key registry identities[#{index}]"
    validate_closed_object(identity, OWNER_REGISTRY_IDENTITY_KEYS, label)
    return unless identity.is_a?(Hash)

    subject_id = identity['subject_id']
    expected_prefix = identity['identity_type'] == 'person' ? 'UEU-PERSON-' : 'UEU-SERVICE-'
    errors << "#{label}: identity_type and stable subject_id must match person|service" unless %w[person service].include?(identity['identity_type']) && subject_id.to_s.start_with?(expected_prefix) && owner_identity_id?(subject_id)
    errors << "#{label}: display_name, unit and title must be substantive" unless %w[display_name unit title].all? { |key| nonempty_string?(identity[key]) }
    status_effective_at = owner_time(identity['status_effective_at']) if identity['status_effective_at']
    status_valid = identity['status'] == 'active' ? identity['status_effective_at'].nil? : %w[suspended revoked].include?(identity['status']) && status_effective_at
    errors << "#{label}: status must be active or a timestamped suspended/revoked state" unless status_valid
    authorizations = identity['authorization_roles']
    errors << "#{label}: authorization_roles must be a unique closed subset" unless authorizations.is_a?(Array) && authorizations.uniq == authorizations && (authorizations - OWNER_REGISTRY_AUTHORIZATIONS).empty?
    issuer = identities.find { |candidate| candidate.is_a?(Hash) && candidate['subject_id'] == identity['issuer_subject_id'] }
    root_self_issued = identity['issuer_subject_id'] == subject_id && Array(authorizations).include?('institutional_trust_root')
    errors << "#{label}: issuer must be an active authorized registry issuer or the self-issued institutional trust root" unless root_self_issued || (issuer && issuer['status'] == 'active' && Array(issuer['authorization_roles']).include?('identity_registry_issuer'))
    errors << "#{label}: keys must be non-empty" unless identity['keys'].is_a?(Array) && !identity['keys'].empty?
    Array(identity['keys']).each_with_index { |key, key_index| validate_owner_registry_key(key, "#{label} keys[#{key_index}]") }
  end

  def validate_owner_registry_key(key, label)
    validate_closed_object(key, OWNER_REGISTRY_KEY_KEYS, label)
    return unless key.is_a?(Hash)

    errors << "#{label}: key_id and algorithm are invalid" unless key['key_id'].to_s.start_with?('UEU-PUBKEY-') && OWNER_ALLOWED_ALGORITHMS.include?(key['algorithm'])
    errors << "#{label}: allowed_purposes must be a non-empty unique closed subset" unless key['allowed_purposes'].is_a?(Array) && !key['allowed_purposes'].empty? && key['allowed_purposes'].uniq == key['allowed_purposes'] && (key['allowed_purposes'] - OWNER_SIGNATURE_PURPOSES).empty?
    valid_from = owner_time(key['valid_from'])
    valid_until = owner_time(key['valid_until'])
    revoked_at = owner_time(key['revoked_at']) if key['revoked_at']
    errors << "#{label}: validity interval/revocation fields are invalid" unless valid_from && valid_until && valid_until > valid_from && ((key['revoked_at'].nil? && key['revocation_reason'].nil?) || (revoked_at && nonempty_string?(key['revocation_reason'])))
    begin
      der = Base64.strict_decode64(key['public_key_spki_base64'].to_s)
      errors << "#{label}: SPKI must use strict canonical Base64" unless Base64.strict_encode64(der) == key['public_key_spki_base64']
      public_key = OpenSSL::PKey.read(der)
      raise OpenSSL::PKey::PKeyError unless public_key.is_a?(OpenSSL::PKey::RSA)
      errors << "#{label}: RSA key must be at least 3072 bits with exponent 65537" unless public_key.n.num_bits >= 3072 && public_key.e.to_i == 65_537 && !public_key.private?
      fingerprint = Digest::SHA256.hexdigest(public_key.public_key.to_der)
      errors << "#{label}: fingerprint_sha256 must match the RSA public key" unless key['fingerprint_sha256'] == fingerprint
    rescue ArgumentError, OpenSSL::PKey::PKeyError, OpenSSL::PKey::RSAError
      errors << "#{label}: public_key_spki_base64 must contain strict Base64 RSA SPKI public-key bytes"
    end
  end

  def owner_registry_root_payload(registry, identity_sha, trust_root_sha)
    {
      'registry_id' => registry['registry_id'], 'snapshot_id' => registry['snapshot_id'],
      'snapshot_revision' => registry['snapshot_revision'], 'snapshot_at' => registry['snapshot_at'],
      'prior_snapshot_sha256' => registry['prior_snapshot_sha256'], 'identity_set_sha256' => identity_sha,
      'trust_root_sha256' => trust_root_sha
    }
  end

  def owner_registry_snapshot_binding(registry)
    {
      'identity_registry_id' => registry['registry_id'],
      'identity_registry_snapshot_id' => registry['snapshot_id'],
      'identity_registry_snapshot_revision' => registry['snapshot_revision'],
      'identity_registry_snapshot_sha256' => registry.dig('registry_root', 'snapshot_payload_sha256'),
      'identity_registry_root_sha256' => registry.dig('registry_root', 'trust_root_sha256')
    }
  end

  def owner_register_identity_snapshot(registry)
    return unless registry.is_a?(Hash) && registry['registry_status'] == 'active'

    binding = owner_registry_snapshot_binding(registry)
    key = binding.values_at(*OWNER_SIGNATURE_REGISTRY_BINDING_KEYS)
    @owner_key_registry_snapshots ||= {}
    @owner_key_registry_snapshots[key] = registry
  end

  def owner_set_identity_snapshot_activation(registry, activation_at)
    return unless activation_at

    binding = owner_registry_snapshot_binding(registry)
    key = binding.values_at(*OWNER_SIGNATURE_REGISTRY_BINDING_KEYS)
    @owner_key_registry_activation_times ||= {}
    @owner_key_registry_activation_times[key] = activation_at
  end

  def owner_registry_activation_for_signature(signature)
    key = OWNER_SIGNATURE_REGISTRY_BINDING_KEYS.map { |field| signature[field] }
    (@owner_key_registry_activation_times || {})[key]
  end

  def owner_registry_for_signature(signature)
    key = OWNER_SIGNATURE_REGISTRY_BINDING_KEYS.map { |field| signature[field] }
    (@owner_key_registry_snapshots || {})[key]
  end

  def owner_registry_identity(subject_id, registry = @owner_key_registry)
    Array(registry && registry['identities']).find { |identity| identity.is_a?(Hash) && identity['subject_id'] == subject_id }
  end

  def owner_person_id?(subject_id)
    identity = owner_registry_identity(subject_id)
    subject_id.to_s.start_with?('UEU-PERSON-') && identity && identity['identity_type'] == 'person'
  end

  def owner_person_id_in_registry?(subject_id, registry)
    identity = owner_registry_identity(subject_id, registry)
    subject_id.to_s.start_with?('UEU-PERSON-') && identity && identity['identity_type'] == 'person'
  end

  def owner_signature_envelope(signature, expected_subject, purpose, signed_at, semantic_payload_sha)
    {
      'artifact_type' => signature['signature_artifact_type'],
      'purpose' => purpose,
      'signer_subject_id' => expected_subject,
      'key_id' => signature['key_id'],
      'algorithm' => signature['algorithm'],
      'signed_at' => signed_at,
      'semantic_payload_sha256' => semantic_payload_sha
    }.merge(signature.slice(*OWNER_SIGNATURE_REGISTRY_BINDING_KEYS))
  end

  def owner_verify_detached_signature(signature, expected_subject, payload_bytes, purpose, signed_at, label, required_authorization: nil, allow_service: false)
    return false unless signature.is_a?(Hash)

    registry = owner_registry_for_signature(signature)
    identity = owner_registry_identity(expected_subject, registry)
    timestamp = owner_time(signed_at)
    key = Array(identity && identity['keys']).find { |candidate| candidate['key_id'] == signature['key_id'] }
    human_ok = allow_service ? identity && %w[person service].include?(identity['identity_type']) : expected_subject.to_s.start_with?('UEU-PERSON-') && identity && identity['identity_type'] == 'person'
    status_effective_at = owner_time(identity['status_effective_at']) if identity && identity['status_effective_at']
    identity_valid_at_signature = identity && (identity['status'] == 'active' || (%w[suspended revoked].include?(identity['status']) && status_effective_at && timestamp && timestamp < status_effective_at))
    artifact_type = OWNER_SIGNATURE_ARTIFACT_TYPES[purpose]
    valid = registry && identity && identity_valid_at_signature && human_ok && timestamp && key && key['algorithm'] == 'RS256' && signature['algorithm'] == 'RS256' && signature['purpose'] == purpose && signature['signature_artifact_type'] == artifact_type && Array(key['allowed_purposes']).include?(purpose)
    activation_at = owner_registry_activation_for_signature(signature)
    valid &&= activation_at && timestamp >= activation_at unless purpose == 'registry_root'
    valid &&= Array(identity['authorization_roles']).include?(required_authorization) if required_authorization
    if valid
      valid_from = owner_time(key['valid_from'])
      valid_until = owner_time(key['valid_until'])
      revoked_at = owner_time(key['revoked_at']) if key['revoked_at']
      valid &&= valid_from && valid_until && timestamp >= valid_from && timestamp < valid_until && (!revoked_at || timestamp < revoked_at)
    end
    payload_sha = Digest::SHA256.hexdigest(payload_bytes)
    recorded_payload_sha = signature['semantic_payload_sha256'] || signature['canonical_signed_payload_sha256']
    valid &&= recorded_payload_sha == payload_sha
    if valid
      begin
        public_key = OpenSSL::PKey.read(Base64.strict_decode64(key['public_key_spki_base64']))
        decoded = Base64.strict_decode64(signature['signature'].to_s)
        envelope_bytes = owner_canonical_json(owner_signature_envelope(signature, expected_subject, purpose, signed_at, payload_sha))
        valid &&= public_key.verify(OpenSSL::Digest::SHA256.new, decoded, owner_signature_message(purpose, envelope_bytes))
      rescue ArgumentError, OpenSSL::PKey::PKeyError, OpenSSL::PKey::RSAError
        valid = false
      end
    end
    errors << "#{label}: detached RS256 signature, identity/key authorization, purpose or validity is invalid" unless valid
    valid
  end

  def owner_snapshot_plan
    return @owner_snapshot_plan if @owner_snapshot_plan_loaded

    @owner_snapshot_plan_loaded = true
    @owner_snapshot_plan = load_owner_governance_json(@owner_snapshot_plan_path, 'owner governance snapshot plan')
    plan = @owner_snapshot_plan
    label = 'owner governance snapshot plan'
    validate_closed_object(plan, OWNER_SNAPSHOT_PLAN_KEYS, label)
    return plan unless plan.is_a?(Hash)

    errors << "#{label}: schema_version and data_boundary must be 1 and synthetic_only" unless plan['schema_version'] == 1 && plan['data_boundary'] == 'synthetic_only'
    policy = plan['policy']
    validate_closed_object(policy, OWNER_SNAPSHOT_PLAN_POLICY_KEYS, "#{label} policy")
    validate_owner_snapshot_plan_identity(policy, "#{label} policy") if policy.is_a?(Hash)

    sources = plan['sources']
    errors << "#{label}: sources must be an exact ordered A-G array" unless sources.is_a?(Array)
    source_batches = Array(sources).map { |source| source['batch'] if source.is_a?(Hash) }
    errors << "#{label}: sources must contain each batch exactly once in A-G order" unless source_batches == DECISION_REGISTER_CONFIGS.keys
    source_ids = []
    Array(sources).each_with_index do |source, index|
      source_label = "#{label} sources[#{index}]"
      validate_closed_object(source, OWNER_SNAPSHOT_PLAN_SOURCE_KEYS, source_label)
      next unless source.is_a?(Hash)

      validate_owner_snapshot_plan_identity(source, source_label)
      captured_at = owner_time(source['captured_at'])
      cutoff_at = owner_time(source['cutoff_at'])
      errors << "#{source_label}: captured_at and cutoff_at must be explicit ISO-8601 times with cutoff_at at or before captured_at" unless captured_at && cutoff_at && cutoff_at <= captured_at
      source_ids << source['snapshot_id'] if nonempty_string?(source['snapshot_id'])
    end
    duplicates = source_ids.group_by(&:itself).select { |_id, values| values.length > 1 }.keys
    errors << "#{label}: source snapshot IDs must be globally unique; duplicates #{duplicates.join(', ')}" unless duplicates.empty?
    policy_time = policy.is_a?(Hash) ? owner_time(policy['snapshot_at']) : nil
    causal = policy_time && Array(sources).all? do |source|
      source.is_a?(Hash) && owner_time(source['captured_at']) && owner_time(source['cutoff_at']) && owner_time(source['captured_at']) <= policy_time && owner_time(source['cutoff_at']) <= policy_time
    end
    errors << "#{label}: no source capture or cutoff may be later than the policy snapshot_at" unless causal
    errors << "#{label}: must not contain credentials, secrets, private keys or recovery material" if owner_contains_secret?(plan)
    plan
  end

  def validate_owner_snapshot_plan_identity(value, label)
    revision = value['snapshot_revision']
    time_key = value.key?('snapshot_at') ? 'snapshot_at' : 'captured_at'
    base_valid = nonempty_string?(value['snapshot_id']) && revision.is_a?(Integer) && revision.positive? && iso_datetime?(value[time_key])
    chain_valid = if revision == 1
                    value['prior_snapshot_reference'].nil? && value['prior_snapshot_sha256'].nil?
                  elsif revision.is_a?(Integer) && revision > 1
                    nonempty_string?(value['prior_snapshot_reference']) && value['prior_snapshot_sha256'].to_s.match?(/\A[0-9a-f]{64}\z/)
                  else
                    false
                  end
    errors << "#{label}: snapshot identity, revision, explicit time or linear predecessor shape is invalid" unless base_valid && chain_valid
  end

  def owner_source_registers
    source_plans = Array(owner_snapshot_plan['sources'])
    DECISION_REGISTER_CONFIGS.keys.map do |batch|
      path = @decision_register_paths.fetch(batch)
      register = owner_parse_json(File.read(path))
      source_plan = source_plans.find { |item| item.is_a?(Hash) && item['batch'] == batch } || {}
      descriptor = {
        'batch' => batch, 'register_id' => register['register_id'],
        'reference' => File.basename(path), 'sha256' => Digest::SHA256.file(path).hexdigest,
        'snapshot_id' => source_plan['snapshot_id'], 'snapshot_revision' => source_plan['snapshot_revision'],
        'captured_at' => source_plan['captured_at'], 'cutoff_at' => source_plan['cutoff_at'],
        'content_root_sha256' => Digest::SHA256.hexdigest(owner_canonical_json(register)),
        'prior_snapshot_reference' => source_plan['prior_snapshot_reference'], 'prior_snapshot_sha256' => source_plan['prior_snapshot_sha256']
      }
      owner_register_source_snapshot(descriptor, register)
      descriptor
    end
  rescue JSON::ParserError, SystemCallError => e
    errors << "owner governance source register inventory cannot be built: #{e.message}"
    []
  end

  def owner_source_snapshot_key(descriptor)
    descriptor.values_at('batch', 'snapshot_id', 'snapshot_revision', 'sha256', 'content_root_sha256')
  end

  def owner_register_source_snapshot(descriptor, register)
    @owner_source_snapshots ||= {}
    @owner_source_snapshots[owner_source_snapshot_key(descriptor)] = { 'descriptor' => descriptor, 'register' => register }
  end

  def owner_source_snapshot_for_descriptor(descriptor)
    (@owner_source_snapshots || {})[owner_source_snapshot_key(descriptor)]
  end

  def validate_owner_source_snapshot_descriptor(descriptor, label, current: true, visited: [])
    validate_closed_object(descriptor, OWNER_SOURCE_REGISTER_KEYS, label)
    return unless descriptor.is_a?(Hash)

    batch = descriptor['batch']
    captured_at = owner_time(descriptor['captured_at'])
    cutoff_at = owner_time(descriptor['cutoff_at'])
    revision = descriptor['snapshot_revision']
    metadata_valid = DECISION_REGISTER_CONFIGS.key?(batch) && nonempty_string?(descriptor['snapshot_id']) && revision.is_a?(Integer) && revision.positive? && captured_at && cutoff_at && cutoff_at <= captured_at && descriptor['sha256'].to_s.match?(/\A[0-9a-f]{64}\z/) && descriptor['content_root_sha256'].to_s.match?(/\A[0-9a-f]{64}\z/)
    chain_valid = (revision == 1 && descriptor['prior_snapshot_reference'].nil? && descriptor['prior_snapshot_sha256'].nil?) || (revision.is_a?(Integer) && revision > 1 && nonempty_string?(descriptor['prior_snapshot_reference']) && descriptor['prior_snapshot_sha256'].to_s.match?(/\A[0-9a-f]{64}\z/))
    errors << "#{label}: source snapshot metadata and linear predecessor binding are invalid" unless metadata_valid && chain_valid

    if current
      path = @decision_register_paths[batch]
      reference_valid = path && descriptor['reference'] == File.basename(path)
      errors << "#{label}: current source snapshot reference must name the exact Batch #{batch} register" unless reference_valid
      if path && File.file?(path)
        register = owner_parse_json(File.read(path))
        exact = descriptor['register_id'] == register['register_id'] && descriptor['sha256'] == Digest::SHA256.file(path).hexdigest && descriptor['content_root_sha256'] == Digest::SHA256.hexdigest(owner_canonical_json(register))
        errors << "#{label}: current source snapshot SHA/root/register ID do not match the exact source bytes" unless exact
        owner_register_source_snapshot(descriptor, register) if exact
      end
    end
    return unless descriptor['snapshot_revision'].is_a?(Integer) && descriptor['snapshot_revision'] > 1

    reference = descriptor['prior_snapshot_reference']
    if visited.include?(reference)
      errors << "#{label}: source snapshot chain contains a cycle"
      return
    end
    artifact = load_owner_evidence_artifact(reference, descriptor['prior_snapshot_sha256'], "#{label} prior source snapshot")
    return unless artifact

    validate_closed_object(artifact, OWNER_SOURCE_SNAPSHOT_ARTIFACT_KEYS, "#{label} prior source snapshot artifact")
    prior_descriptor = artifact['descriptor'] || {}
    prior_register = artifact['register']
    validate_closed_object(prior_descriptor, OWNER_SOURCE_REGISTER_KEYS, "#{label} prior source snapshot descriptor")
    prior_captured_at = owner_time(prior_descriptor['captured_at'])
    prior_cutoff_at = owner_time(prior_descriptor['cutoff_at'])
    content_advanced = descriptor['sha256'] != prior_descriptor['sha256'] && descriptor['content_root_sha256'] != prior_descriptor['content_root_sha256']
    exact_prior = artifact['artifact_type'] == OWNER_SOURCE_SNAPSHOT_ARTIFACT_TYPE && artifact['schema_version'] == OWNER_POLICY_SCHEMA_VERSION && prior_register.is_a?(Hash) && prior_descriptor['batch'] == batch && prior_descriptor['snapshot_revision'] == descriptor['snapshot_revision'] - 1 && prior_descriptor['snapshot_id'] != descriptor['snapshot_id'] && prior_descriptor['register_id'] == prior_register['register_id'] && prior_descriptor['sha256'].to_s.match?(/\A[0-9a-f]{64}\z/) && prior_descriptor['content_root_sha256'] == Digest::SHA256.hexdigest(owner_canonical_json(prior_register)) && content_advanced && prior_captured_at && prior_cutoff_at && captured_at && cutoff_at && captured_at > prior_captured_at && cutoff_at > prior_cutoff_at
    errors << "#{label}: prior source artifact must contain the exact immediately preceding immutable register snapshot" unless exact_prior
    errors << "#{label}: advanced source snapshot ID must be new and both captured_at and cutoff_at must strictly increase" unless prior_descriptor['snapshot_id'] != descriptor['snapshot_id'] && prior_captured_at && prior_cutoff_at && captured_at && cutoff_at && captured_at > prior_captured_at && cutoff_at > prior_cutoff_at
    errors << "#{label}: advancing source identity/revision/time requires both raw SHA-256 and canonical content root to change" unless content_advanced
    if exact_prior
      owner_register_source_snapshot(prior_descriptor, prior_register)
      validate_owner_source_snapshot_descriptor(prior_descriptor, "#{label} prior source snapshot", current: false, visited: [*visited, reference])
    end
  end

  def owner_authority_capacities
    OWNER_CAPACITY_DESCRIPTIONS.map { |capacity_id, description| { 'capacity_id' => capacity_id, 'description' => description } }
  end

  def owner_authority_roles
    OWNER_ROLE_CAPACITY_MAP.keys.sort.map do |authority_role|
      {
        'authority_role' => authority_role,
        'authority_domain' => OWNER_ROLE_DOMAIN_MAP.fetch(authority_role),
        'capacity_id' => OWNER_ROLE_CAPACITY_MAP.fetch(authority_role)
      }
    end
  end

  def owner_separation_rules
    [
      { 'rule_id' => 'SEP-MIGRATION-MAPPING', 'first_role' => 'data_migration_executor', 'second_role' => 'data_mapping_approver', 'rationale' => 'Migration execution cannot approve its own source-to-target mapping.' },
      { 'rule_id' => 'SEP-CASHIER-RECONCILIATION', 'first_role' => 'cashier_operator', 'second_role' => 'independent_finance_reconciler', 'rationale' => 'Cash collection and settlement execution cannot reconcile the same finance control.' },
      { 'rule_id' => 'SEP-TREASURY-RECONCILIATION', 'first_role' => 'treasury_settlement_authorizer', 'second_role' => 'independent_finance_reconciler', 'rationale' => 'Treasury settlement authorization cannot reconcile the same control.' },
      { 'rule_id' => 'SEP-FORMULA-STATUTORY', 'first_role' => 'report_formula_author', 'second_role' => 'statutory_sponsor', 'rationale' => 'The report formula author cannot sponsor the statutory definition.' }
    ]
  end

  def owner_session_rules
    {
      'consent_rule' => 'unanimous_role_based_consent',
      'unclassified_co_owner_rule' => 'default_consent_required',
      'minimum_unique_people_ordinary' => 2,
      'minimum_unique_people_independent' => 3,
      'recusal_rule' => 'recusal_leaves_required_seat_vacant',
      'parallel_revision_rule' => 'one_linear_prior_decision_digest_chain',
      'post_signature_mutation_rule' => 'canonical_payload_digest_mismatch_fails_closed',
      'chair_authority_role' => 'product_delivery',
      'facilitator_authority_role' => 'operations',
      'officer_separation_rule' => 'distinct_appointment_and_identity'
    }
  end

  def owner_accountable_authority(batch, entry)
    lead = entry['lead_authority_domain']
    return lead if nonempty_string?(lead)
    return 'registration_admission' if batch == 'B'
    return OWNER_BATCH_A_ACCOUNTABLE_AUTHORITIES[entry['requirement_id']] || Array(entry['co_owners']).find { |domain| domain != 'product_delivery' } if batch == 'A'

    domains = Array(entry['co_owners'])
    %w[product_delivery security_privacy_data rmik operations registration_admission].find { |domain| domains.include?(domain) } || domains.first
  end

  def owner_required_special_roles(domains, statutory_scope)
    roles = []
    if domains.include?('data_migration')
      roles.concat(%w[data_migration_executor data_mapping_approver])
    end
    finance_control = domains.any? { |domain| %w[finance_accounting finance_claims finance_master].include?(domain) }
    if finance_control
      roles << 'cashier_operator' if domains.include?('cashier_revenue')
      roles << 'treasury_settlement_authorizer' if domains.include?('treasury')
      roles << 'independent_finance_reconciler' if domains.any? { |domain| %w[cashier_revenue treasury].include?(domain) }
    end
    roles << 'report_formula_author' if statutory_scope
    roles.uniq
  end

  def owner_applicable_controls(domains, statutory_scope, special_roles)
    capacities = domains.map { |domain| OWNER_BASE_ROLE_CAPACITY_MAP[domain] }.compact
    controls = []
    controls << 'security_privacy_data' if capacities.include?('security_privacy_data')
    controls << 'clinical_safety_or_rmik' if (capacities & %w[clinical_care clinical_safety nursing rmik_coding]).any?
    controls << 'independent_finance_control' if capacities.include?('finance_control')
    controls << 'statutory_sponsor' if statutory_scope
    controls << 'migration_mapping_separation' if special_roles.include?('data_mapping_approver')
    controls << 'cashier_finance_separation' if special_roles.include?('independent_finance_reconciler')
    controls << 'formula_sponsor_separation' if statutory_scope
    controls << 'cross_domain_source_owners' if domains.length > 2
    controls
  end

  def owner_unresolved_dependency_authorities(value)
    authorities = []
    case value
    when Hash
      unresolved = value.key?('status') && !%w[resolved complete appointed recorded not_applicable].include?(value['status'])
      if unresolved
        authorities.concat(%w[authority_domain source_owner_authority lead_authority_domain].map { |key| value[key] }.compact)
      end
      value.each_value { |child| authorities.concat(owner_unresolved_dependency_authorities(child)) }
    when Array
      value.each { |child| authorities.concat(owner_unresolved_dependency_authorities(child)) }
    end
    authorities.select { |role| OWNER_ROLE_CAPACITY_MAP.key?(role) }.uniq
  end

  def expected_owner_requirement_policies
    DECISION_REGISTER_CONFIGS.keys.flat_map do |batch|
      path = @decision_register_paths.fetch(batch)
      register_sha = Digest::SHA256.file(path).hexdigest
      register_id = @decision_entries_by_batch.dig(batch, 0, 'register_id')
      register_id ||= JSON.parse(File.read(path))['register_id']
      Array(@decision_entries_by_batch[batch]).map do |entry|
        requirement_id = entry['requirement_id']
        lead = owner_accountable_authority(batch, entry)
        domains = ['product_delivery', lead, *Array(entry['co_owners'])].compact.uniq
        statutory_scope = batch == 'G' && BATCH_G_STATUTORY_IDS.include?(requirement_id)
        special_roles = owner_required_special_roles(domains, statutory_scope)
        base_roles = [*domains, *special_roles].uniq
        disposition_roles = OWNER_DISPOSITION_RULES.values.flat_map { |rule| Array(rule['additional_authority_roles']) }
        eligible_roles = [*base_roles, *disposition_roles, *owner_unresolved_dependency_authorities(entry)].uniq
        {
          'requirement_id' => requirement_id, 'batch' => batch,
          'batch_register_id' => register_id, 'batch_register_sha256' => register_sha,
          'source_row_sha256' => Digest::SHA256.hexdigest(owner_canonical_json(entry)),
          'accountable_authority_domain' => lead,
          'required_authority_domains' => domains,
          'required_special_roles' => special_roles,
          'eligible_authority_roles' => eligible_roles,
          'applicable_independent_controls' => owner_applicable_controls(domains, statutory_scope, special_roles),
          'statutory_scope' => statutory_scope
        }
      end
    end
  end

  def expected_owner_authority_policy(manifest)
    snapshot_plan = owner_snapshot_plan
    policy_plan = snapshot_plan['policy'].is_a?(Hash) ? snapshot_plan['policy'] : {}
    policy = {
      'schema_version' => OWNER_POLICY_SCHEMA_VERSION,
      'policy_id' => OWNER_POLICY_ID,
      'policy_status' => 'proposal',
      'data_boundary' => 'synthetic_only',
      'source_revision' => OWNER_SOURCE_REVISION,
      'snapshot_id' => policy_plan['snapshot_id'],
      'snapshot_revision' => policy_plan['snapshot_revision'],
      'snapshot_at' => policy_plan['snapshot_at'],
      'prior_snapshot_reference' => policy_plan['prior_snapshot_reference'],
      'prior_snapshot_sha256' => policy_plan['prior_snapshot_sha256'],
      'control_root_sha256' => nil,
      'manifest_reference' => File.basename(@batch_manifest_path),
      'manifest_sha256' => Digest::SHA256.file(@batch_manifest_path).hexdigest,
      'source_decision_registers' => owner_source_registers,
      'authority_capacities' => owner_authority_capacities,
      'authority_roles' => owner_authority_roles,
      'compatibility_whitelist' => OWNER_COMPATIBILITY_WHITELIST,
      'incompatible_role_pairs' => OWNER_INCOMPATIBLE_ROLE_PAIRS,
      'separation_rules' => owner_separation_rules,
      'disposition_rules' => OWNER_DISPOSITION_RULES,
      'session_rules' => owner_session_rules,
      'session_sequence' => OWNER_SESSION_SEQUENCE,
      'requirement_policies' => expected_owner_requirement_policies,
      'approval' => { 'status' => 'pending', 'identity' => nil, 'authority_role' => nil, 'date' => nil, 'reference' => nil, 'artifact_sha256' => nil }
    }
    policy['control_root_sha256'] = Digest::SHA256.hexdigest(owner_canonical_json(owner_policy_control_payload(policy)))
    policy
  rescue SystemCallError => e
    errors << "owner authority policy cannot bind source files: #{e.message}"
    {}
  end

  def owner_policy_control_payload(policy)
    policy.to_h.reject { |key, _value| %w[policy_status approval control_root_sha256].include?(key) }
  end

  def owner_register_policy_snapshot(policy, activation_at = nil)
    return unless policy.is_a?(Hash) && policy['control_root_sha256'].to_s.match?(/\A[0-9a-f]{64}\z/)

    @owner_policy_snapshots ||= {}
    @owner_policy_snapshots[[policy['snapshot_id'], policy['snapshot_revision'], policy['control_root_sha256']]] = policy
    if activation_at
      @owner_policy_activation_times ||= {}
      @owner_policy_activation_times[policy['control_root_sha256']] = activation_at
    end
  end

  def owner_policy_snapshot_by_root(root_sha)
    (@owner_policy_snapshots || {}).values.find { |snapshot| snapshot['control_root_sha256'] == root_sha }
  end

  def owner_policy_activation_by_root(root_sha)
    (@owner_policy_activation_times || {})[root_sha]
  end

  def validate_owner_authority_policy(policy, manifest)
    label = 'owner authority policy'
    validation_start = errors.length
    validate_closed_object(policy, OWNER_POLICY_KEYS, label)
    return unless policy.is_a?(Hash)

    expected = expected_owner_authority_policy(manifest)
    stable_keys = OWNER_POLICY_KEYS - %w[policy_status approval]
    if policy['snapshot_revision'] == 1
      errors << "#{label}: must exactly bind the frozen A-G authority capacities, applicability, co-signers and source digests" unless policy.slice(*stable_keys) == expected.slice(*stable_keys)
    else
      immutable_catalog_keys = %w[schema_version policy_id data_boundary source_revision manifest_reference manifest_sha256 authority_capacities authority_roles compatibility_whitelist incompatible_role_pairs separation_rules disposition_rules session_rules session_sequence]
      errors << "#{label}: corrected snapshot must preserve immutable authority vocabularies, separation and session rules" unless policy.slice(*immutable_catalog_keys) == expected.slice(*immutable_catalog_keys)
      validate_owner_corrected_requirement_policies(policy, expected, label)
    end
    plan_bound_keys = %w[snapshot_id snapshot_revision snapshot_at prior_snapshot_reference prior_snapshot_sha256 source_decision_registers]
    errors << "#{label}: snapshot identity, predecessor and exact ordered A-G source descriptors must bind the explicit snapshot plan" unless policy.slice(*plan_bound_keys) == expected.slice(*plan_bound_keys)
    recomputed_control_root = Digest::SHA256.hexdigest(owner_canonical_json(owner_policy_control_payload(policy)))
    errors << "#{label}: control_root_sha256 must bind the exact immutable policy snapshot" unless policy['control_root_sha256'] == recomputed_control_root
    snapshot_chain_valid = policy['snapshot_revision'].is_a?(Integer) && policy['snapshot_revision'].positive? && iso_datetime?(policy['snapshot_at']) && ((policy['snapshot_revision'] == 1 && policy['prior_snapshot_reference'].nil? && policy['prior_snapshot_sha256'].nil?) || (policy['snapshot_revision'] > 1 && nonempty_string?(policy['prior_snapshot_reference']) && policy['prior_snapshot_sha256'].to_s.match?(/\A[0-9a-f]{64}\z/)))
    errors << "#{label}: snapshot ID/revision/time and linear predecessor binding are invalid" unless nonempty_string?(policy['snapshot_id']) && snapshot_chain_valid
    if @mode == 'g0' && policy['snapshot_revision'].to_i > 1 && policy['policy_status'] != 'approved'
      errors << "#{label}: a correction proposal keeps G0 open until cryptographic executive approval is recorded"
    end
    Array(policy['source_decision_registers']).each_with_index { |item, index| validate_owner_source_snapshot_descriptor(item, "#{label} source_decision_registers[#{index}]") }
    validate_owner_prior_policy_chain(policy, label, expected)
    validate_owner_policy_source_row_bindings(policy, label)
    Array(policy['authority_capacities']).each_with_index { |item, index| validate_closed_object(item, OWNER_CAPACITY_KEYS, "#{label} authority_capacities[#{index}]") }
    Array(policy['authority_roles']).each_with_index { |item, index| validate_closed_object(item, OWNER_ROLE_KEYS, "#{label} authority_roles[#{index}]") }
    Array(policy['separation_rules']).each_with_index { |item, index| validate_closed_object(item, OWNER_SEPARATION_RULE_KEYS, "#{label} separation_rules[#{index}]") }
    validate_closed_object(policy['session_rules'], OWNER_POLICY_SESSION_KEYS, "#{label} session_rules")
    Array(policy['requirement_policies']).each_with_index { |item, index| validate_closed_object(item, OWNER_REQUIREMENT_POLICY_KEYS, "#{label} requirement_policies[#{index}]") }
    validate_closed_object(policy['approval'], OWNER_POLICY_APPROVAL_KEYS, "#{label} approval")
    policy_activation_at = nil
    if policy['policy_status'] == 'proposal'
      errors << "#{label}: proposal approval must remain explicitly pending; no authority approval exists" unless policy['approval'] == expected['approval']
    elsif policy['policy_status'] == 'approved'
      policy_activation_at = validate_owner_policy_approval(policy, expected, label: "#{label} approval")
    else
      errors << "#{label}: policy_status must be proposal or approved"
    end
    errors << "#{label}: must not contain credentials, secrets or private keys" if owner_contains_secret?(policy)
    if @mode == 'g0' && policy['policy_status'] != 'approved'
      errors << "#{label}: G0 remains open until the authority policy has an authoritative signed approval"
    end
    owner_register_policy_snapshot(policy, policy_activation_at) if policy['policy_status'] == 'approved' && policy_activation_at && errors.length == validation_start
  end

  def validate_owner_policy_source_row_bindings(policy, label)
    descriptors = Array(policy['source_decision_registers']).to_h { |descriptor| [descriptor['batch'], descriptor] }
    Array(policy['requirement_policies']).each do |row|
      next unless row.is_a?(Hash)

      descriptor = descriptors[row['batch']]
      snapshot = descriptor && owner_source_snapshot_for_descriptor(descriptor)
      source_row = Array(snapshot && snapshot.dig('register', 'entries')).find { |entry| entry['requirement_id'] == row['requirement_id'] }
      exact = descriptor && source_row && row['batch_register_id'] == descriptor['register_id'] && row['batch_register_sha256'] == descriptor['sha256'] && row['source_row_sha256'] == Digest::SHA256.hexdigest(owner_canonical_json(source_row))
      errors << "#{label}: policy row #{row['requirement_id']} must bind its exact historical source-register snapshot row" unless exact
    end
  end

  def validate_owner_corrected_requirement_policies(policy, expected, label)
    rows = Array(policy['requirement_policies'])
    expected_rows = Array(expected['requirement_policies'])
    ids = rows.map { |row| row['requirement_id'] if row.is_a?(Hash) }
    errors << "#{label}: corrected snapshot must retain the exact 268 unique PAR IDs and batches" unless ids.length == 268 && ids.uniq.length == 268 && ids.sort == expected_rows.map { |row| row['requirement_id'] }.sort
    rows.each_with_index do |row, index|
      validate_closed_object(row, OWNER_REQUIREMENT_POLICY_KEYS, "#{label} corrected requirement_policies[#{index}]")
      next unless row.is_a?(Hash)

      expected_row = expected_rows.find { |candidate| candidate['requirement_id'] == row['requirement_id'] }
      authority_keys = %w[accountable_authority_domain required_authority_domains required_special_roles eligible_authority_roles applicable_independent_controls statutory_scope]
      exact_authority = expected_row && row.slice(*authority_keys) == expected_row.slice(*authority_keys)
      valid_binding = expected_row && row['batch'] == expected_row['batch'] && row['batch_register_id'].is_a?(String) && row['batch_register_sha256'].to_s.match?(/\A[0-9a-f]{64}\z/) && row['source_row_sha256'].to_s.match?(/\A[0-9a-f]{64}\z/)
      errors << "#{label}: corrected policy row #{row['requirement_id']} cannot weaken or amend frozen accountable/co-owner/special-role/independent-control/statutory applicability" unless exact_authority
      errors << "#{label}: corrected policy row #{row['requirement_id']} must retain its exact PAR/batch and bind a historical source row" unless valid_binding
    end
  end

  def validate_owner_prior_policy_chain(policy, label, expected, visited = [])
    revision = policy['snapshot_revision']
    return unless revision.is_a?(Integer) && revision > 1

    reference = policy['prior_snapshot_reference']
    if visited.include?(reference)
      errors << "#{label}: policy snapshot chain contains a cycle"
      return
    end
    previous = load_owner_evidence_artifact(reference, policy['prior_snapshot_sha256'], "#{label} prior policy snapshot")
    return unless previous

    prior_label = "#{label} prior snapshot revision #{revision - 1}"
    validation_start = errors.length
    validate_closed_object(previous, OWNER_POLICY_KEYS, prior_label)
    return unless previous.is_a?(Hash)

    prior_root = Digest::SHA256.hexdigest(owner_canonical_json(owner_policy_control_payload(previous)))
    prior_time = owner_time(previous['snapshot_at'])
    current_time = owner_time(policy['snapshot_at'])
    chain_valid = previous['policy_id'] == OWNER_POLICY_ID && previous['schema_version'] == OWNER_POLICY_SCHEMA_VERSION && previous['policy_status'] == 'approved' && previous['data_boundary'] == 'synthetic_only' && previous['source_revision'] == OWNER_SOURCE_REVISION && previous['snapshot_revision'] == revision - 1 && nonempty_string?(previous['snapshot_id']) && previous['snapshot_id'] != policy['snapshot_id'] && prior_time && current_time && current_time > prior_time && previous['control_root_sha256'] == prior_root
    predecessor_valid = (previous['snapshot_revision'] == 1 && previous['prior_snapshot_reference'].nil? && previous['prior_snapshot_sha256'].nil?) || (previous['snapshot_revision'].to_i > 1 && nonempty_string?(previous['prior_snapshot_reference']) && previous['prior_snapshot_sha256'].to_s.match?(/\A[0-9a-f]{64}\z/))
    exact = chain_valid && predecessor_valid
    errors << "#{prior_label}: must be the exact immediately preceding immutable policy snapshot" unless exact
    errors << "#{prior_label}: current policy snapshot ID must be new and snapshot_at must strictly increase" unless nonempty_string?(previous['snapshot_id']) && previous['snapshot_id'] != policy['snapshot_id'] && prior_time && current_time && current_time > prior_time
    errors << "#{prior_label}: historical policy must be approved with a fully verified sponsor/reviewer contract before it can authorize an old session" unless previous['policy_status'] == 'approved'
    immutable_catalog_keys = %w[schema_version policy_id data_boundary source_revision manifest_reference manifest_sha256 authority_capacities authority_roles compatibility_whitelist incompatible_role_pairs separation_rules disposition_rules session_rules session_sequence]
    errors << "#{prior_label}: historical policy must preserve immutable authority vocabularies, separation and session rules" unless previous.slice(*immutable_catalog_keys) == expected.slice(*immutable_catalog_keys)
    validate_owner_corrected_requirement_policies(previous, expected, prior_label)
    Array(previous['source_decision_registers']).each_with_index { |item, index| validate_owner_source_snapshot_descriptor(item, "#{prior_label} source_decision_registers[#{index}]", current: false) }
    validate_owner_policy_source_row_bindings(previous, prior_label)
    Array(previous['authority_capacities']).each_with_index { |item, index| validate_closed_object(item, OWNER_CAPACITY_KEYS, "#{prior_label} authority_capacities[#{index}]") }
    Array(previous['authority_roles']).each_with_index { |item, index| validate_closed_object(item, OWNER_ROLE_KEYS, "#{prior_label} authority_roles[#{index}]") }
    Array(previous['separation_rules']).each_with_index { |item, index| validate_closed_object(item, OWNER_SEPARATION_RULE_KEYS, "#{prior_label} separation_rules[#{index}]") }
    validate_closed_object(previous['session_rules'], OWNER_POLICY_SESSION_KEYS, "#{prior_label} session_rules")
    Array(previous['requirement_policies']).each_with_index { |item, index| validate_closed_object(item, OWNER_REQUIREMENT_POLICY_KEYS, "#{prior_label} requirement_policies[#{index}]") }
    validate_closed_object(previous['approval'], OWNER_POLICY_APPROVAL_KEYS, "#{prior_label} approval")
    activation_at = validate_owner_policy_approval(previous, expected, label: "#{prior_label} approval")
    errors << "#{prior_label}: historical policy cannot contain credentials, secrets or private keys" if owner_contains_secret?(previous)
    validate_owner_prior_policy_chain(previous, label, expected, [*visited, reference]) if exact
    owner_register_policy_snapshot(previous, activation_at) if exact && activation_at && errors.length == validation_start
  end

  def validate_owner_policy_approval(policy, _expected = nil, label: 'owner authority policy approval')
    validation_start = errors.length
    approval = policy['approval']
    validate_closed_object(approval, OWNER_POLICY_APPROVAL_KEYS, label)
    return unless approval.is_a?(Hash)

    errors << "#{label}: recorded approval requires an executive sponsor identity, date, evidence reference and SHA" unless approval['status'] == 'recorded' && owner_person_id?(approval['identity']) && approval['authority_role'] == 'executive_sponsor' && iso_date?(approval['date']) && nonempty_string?(approval['reference']) && approval['artifact_sha256'].to_s.match?(/\A[0-9a-f]{64}\z/)
    artifact = load_owner_evidence_artifact(approval['reference'], approval['artifact_sha256'], label)
    return unless artifact

    validate_closed_object(artifact, OWNER_POLICY_APPROVAL_ARTIFACT_KEYS, "#{label} artifact")
    validate_closed_object(artifact['reviewer'], OWNER_REGISTRY_RECEIPT_KEYS, "#{label} artifact reviewer")
    control_sha = policy['control_root_sha256']
    errors << "#{label}: artifact must bind the exact frozen policy control, sponsor and recorded approval" unless artifact['artifact_type'] == OWNER_POLICY_APPROVAL_ARTIFACT_TYPE && artifact['schema_version'] == OWNER_POLICY_SCHEMA_VERSION && artifact['policy_id'] == OWNER_POLICY_ID && artifact['policy_control_sha256'] == control_sha && artifact['identity'] == approval['identity'] && artifact['authority_role'] == 'executive_sponsor' && artifact['date'] == approval['date'] && nonempty_string?(artifact['mandate_reference'])
    signature_metadata_keys = %w[signature_artifact_type key_id algorithm purpose signed_at semantic_payload_sha256 identity_registry_id identity_registry_snapshot_id identity_registry_snapshot_revision identity_registry_snapshot_sha256 identity_registry_root_sha256 signature reviewer]
    approval_payload = artifact.to_h.reject { |key, _value| signature_metadata_keys.include?(key) }
    approval_bytes = owner_canonical_json(approval_payload)
    signature_record = artifact.to_h.slice('signature_artifact_type', 'key_id', 'algorithm', 'purpose', 'signed_at', 'semantic_payload_sha256', *OWNER_SIGNATURE_REGISTRY_BINDING_KEYS, 'signature').merge('signer_institutional_id' => artifact['identity'])
    owner_verify_detached_signature(signature_record, artifact['identity'], approval_bytes, 'policy_approval', artifact['signed_at'], "#{label} artifact", required_authorization: 'executive_sponsor')
    reviewer = artifact['reviewer'] || {}
    errors << "#{label}: independent reviewer must differ from sponsor, evidence author and implementer" unless owner_identity_id?(reviewer['reviewer_institutional_id']) && reviewer['reviewer_institutional_id'] != approval['identity'] && reviewer['reviewer_institutional_id'] != reviewer['evidence_author_institutional_id'] && reviewer['reviewer_institutional_id'] != reviewer['implementer_institutional_id']
    errors << "#{label}: reviewer metadata is invalid" unless owner_person_id?(reviewer['evidence_author_institutional_id']) && owner_person_id?(reviewer['implementer_institutional_id']) && %w[institutional_registry detached_signature].include?(reviewer['verification_method']) && nonempty_string?(reviewer['verification_reference']) && iso_datetime?(reviewer['verified_at'])
    validate_owner_reviewed_receipt_signature(reviewer, approval_bytes, 'registry_review', "#{label} reviewer")
    sponsor_time = owner_time(artifact['signed_at'])
    reviewer_time = owner_time(reviewer['verified_at'])
    snapshot_time = owner_time(policy['snapshot_at'])
    source_times = Array(policy['source_decision_registers']).map { |descriptor| owner_time(descriptor['captured_at']) }
    causal = snapshot_time && source_times.all? && source_times.all? { |captured_at| captured_at <= snapshot_time } && sponsor_time && reviewer_time && sponsor_time >= snapshot_time && reviewer_time >= sponsor_time && sponsor_time.strftime('%Y-%m-%d') == artifact['date']
    errors << "#{label}: source capture, policy snapshot, sponsor signature and independent review must form one causal chain" unless causal
    errors << "#{label}: independently supplied trust-root SHA is required for approval" unless owner_trust_root_pinned?
    reviewer_time if errors.length == validation_start
  end

  def owner_trust_root_pinned?
    root_sha = @owner_key_registry && @owner_key_registry.dig('registry_root', 'trust_root_sha256')
    @owner_key_registry && @owner_key_registry['registry_status'] == 'active' && @trusted_identity_root_sha256.to_s.match?(/\A[0-9a-f]{64}\z/) && @trusted_identity_root_sha256 == root_sha
  end

  def owner_evidence_root
    return @owner_evidence_root_path if @owner_evidence_root_path

    File.join(File.realpath(File.dirname(@owner_authority_policy_path)), OWNER_EVIDENCE_DIRECTORY)
  end

  def load_owner_evidence_artifact(reference, expected_sha, label)
    evidence_root = owner_evidence_root
    phase_directory = File.dirname(evidence_root)
    path = File.expand_path(reference.to_s, phase_directory)
    allowed_prefix = "#{evidence_root}#{File::SEPARATOR}"
    unless path.start_with?(allowed_prefix) && File.extname(path).casecmp?('.json') && File.file?(path) && File.lstat(path).file?
      errors << "#{label}: evidence must be a regular JSON file inside #{OWNER_EVIDENCE_DIRECTORY}"
      return
    end
    real_root = File.realpath(evidence_root)
    real_path = File.realpath(path)
    unless real_path.start_with?("#{real_root}#{File::SEPARATOR}") && Digest::SHA256.file(real_path).hexdigest == expected_sha
      errors << "#{label}: evidence path or SHA-256 does not bind the referenced artifact"
      return
    end
    artifact = owner_parse_json(File.read(real_path))
    if artifact.is_a?(Hash)
      errors << "#{label}: evidence contains prohibited credentials, tokens, private keys or recovery material" if owner_contains_secret?(artifact)
      return artifact
    end

    errors << "#{label}: evidence must contain one JSON object"
    nil
  rescue JSON::ParserError, SystemCallError => e
    errors << "#{label}: evidence cannot be verified: #{e.message}"
    nil
  end

  def owner_policy_sha256
    File.file?(@owner_authority_policy_path) ? Digest::SHA256.file(@owner_authority_policy_path).hexdigest : nil
  end

  def owner_manifest_sha256
    File.file?(@batch_manifest_path) ? Digest::SHA256.file(@batch_manifest_path).hexdigest : nil
  end

  def owner_source_register_sha256s
    owner_source_registers.to_h { |item| [item['batch'], item['sha256']] }
  end

  def expected_owner_appointment_register_top(policy = nil, policy_reference: File.basename(@owner_authority_policy_path), policy_sha256: owner_policy_sha256)
    policy ||= load_owner_governance_json(@owner_authority_policy_path, 'owner authority policy header source')
    policy_sources = policy.is_a?(Hash) && policy['source_decision_registers'].is_a?(Array) ? policy['source_decision_registers'] : []
    {
      'schema_version' => OWNER_POLICY_SCHEMA_VERSION,
      'register_id' => OWNER_APPOINTMENT_REGISTER_ID,
      'register_status' => 'open',
      'data_boundary' => 'synthetic_only',
      'policy_reference' => policy_reference,
      'policy_sha256' => policy_sha256,
      'manifest_reference' => File.basename(@batch_manifest_path),
      'manifest_sha256' => owner_manifest_sha256,
      'source_decision_registers' => policy_sources,
      'evidence_directory' => OWNER_EVIDENCE_DIRECTORY
    }
  end

  def validate_owner_appointment_register(register, policy, manifest)
    label = 'owner appointment register'
    validate_closed_object(register, OWNER_APPOINTMENT_REGISTER_KEYS, label)
    return unless register.is_a?(Hash)

    expected_top = expected_owner_appointment_register_top
    expected_top.each { |key, value| errors << "#{label}: #{key} must bind the current closed owner policy/manifest/A-G registers" unless register[key] == value }
    errors << "#{label}: appointments must be an array" unless register['appointments'].is_a?(Array)
    errors << "#{label}: events must be an array" unless register['events'].is_a?(Array)
    evidence_root = owner_evidence_root
    errors << "#{label}: evidence_directory must exist as a regular directory" unless File.directory?(evidence_root) && File.lstat(evidence_root).directory?
    errors << "#{label}: must not contain credentials, secrets or private keys" if owner_contains_secret?(register)

    appointments = Array(register['appointments'])
    events = Array(register['events'])
    appointment_ids = appointments.map { |appointment| appointment['appointment_id'] if appointment.is_a?(Hash) }.compact
    duplicate_appointment_ids = appointment_ids.group_by(&:itself).select { |_id, values| values.length > 1 }.keys
    errors << "#{label}: appointment IDs must be globally unique; duplicates #{duplicate_appointment_ids.join(', ')}" unless duplicate_appointment_ids.empty?
    appointment_times = appointments.map { |appointment| owner_time(appointment['effective_at']) if appointment.is_a?(Hash) }
    event_times = events.map { |event| owner_time(event['effective_at']) if event.is_a?(Hash) }
    errors << "#{label}: appointments must be append-only in nondecreasing effective_at order" unless appointment_times.compact == appointment_times && appointment_times.each_cons(2).all? { |first, second| first <= second }
    errors << "#{label}: lifecycle events must be append-only in nondecreasing effective_at order" unless event_times.compact == event_times && event_times.each_cons(2).all? { |first, second| first <= second }
    appointments.each_with_index { |appointment, index| validate_owner_appointment(appointment, index, register, policy) }
    validate_owner_appointment_identity_and_role_rules(appointments)
    validate_owner_appointment_events(events, appointments)
    validate_owner_delegations(appointments, events)
    if @mode == 'g0'
      missing = Array(policy['requirement_policies']).count do |requirement_policy|
        owner_required_roles_for_policy(requirement_policy).any? do |role|
          appointments.none? { |appointment| owner_appointment_covers?(appointment, requirement_policy['requirement_id'], role, Time.now, events, appointments) }
        end
      end
      errors << "#{label}: G0 lacks complete valid appointment coverage for #{missing} of 268 capability policies" if missing.positive?
    end
  end

  def owner_appointment_payload(appointment)
    appointment.slice(*(%w[appointment_id appointment_register_id subject authority_domain authority_role capacity_id scope decision_rights data_boundary issuer effective_at expires_at conflict_disclosure delegation policy_sha256 manifest_sha256 source_register_sha256s]))
  end

  def owner_signature_valid?(signature, expected_signer, payload_bytes, purpose, label, required_authorization: nil)
    validate_closed_object(signature, OWNER_SIGNATURE_KEYS, label)
    return false unless signature.is_a?(Hash)

    valid = signature['signer_institutional_id'] == expected_signer && iso_datetime?(signature['signed_at'])
    errors << "#{label}: signer and timestamp must bind the expected human identity" unless valid
    owner_verify_detached_signature(signature, expected_signer, payload_bytes, purpose, signature['signed_at'], label, required_authorization: required_authorization) && valid
  end

  def owner_reviewer_receipt_payload(receipt, reviewed_payload_bytes)
    {
      'reviewed_payload_sha256' => receipt['reviewed_payload_sha256'],
      'reviewer_institutional_id' => receipt['reviewer_institutional_id'],
      'evidence_author_institutional_id' => receipt['evidence_author_institutional_id'],
      'implementer_institutional_id' => receipt['implementer_institutional_id'],
      'verification_method' => receipt['verification_method'],
      'verification_reference' => receipt['verification_reference'],
      'verified_at' => receipt['verified_at'],
      'key_id' => receipt['key_id'],
      'algorithm' => receipt['algorithm'],
      'purpose' => receipt['purpose']
    }.merge(receipt.slice(*OWNER_SIGNATURE_REGISTRY_BINDING_KEYS))
  end

  def validate_owner_reviewed_receipt_signature(receipt, reviewed_payload_bytes, purpose, label, required_authorization: 'independent_reviewer')
    expected_reviewed_sha = Digest::SHA256.hexdigest(reviewed_payload_bytes)
    errors << "#{label}: reviewed_payload_sha256 must bind the exact reviewed business payload" unless receipt['reviewed_payload_sha256'] == expected_reviewed_sha
    receipt_payload_bytes = owner_canonical_json(owner_reviewer_receipt_payload(receipt, reviewed_payload_bytes))
    owner_verify_detached_signature(receipt, receipt['reviewer_institutional_id'], receipt_payload_bytes, purpose, receipt['verified_at'], label, required_authorization: required_authorization)
  end

  def validate_owner_registry_receipt(receipt, appointment, payload_bytes, label)
    validate_closed_object(receipt, OWNER_REGISTRY_RECEIPT_KEYS, label)
    return unless receipt.is_a?(Hash)

    subject_id = appointment.dig('subject', 'institutional_id')
    issuer_id = appointment.dig('issuer', 'institutional_id')
    distinct_from = [subject_id, issuer_id, receipt['evidence_author_institutional_id'], receipt['implementer_institutional_id']].compact
    errors << "#{label}: reviewer must be distinct from subject, appointer, evidence author and implementer" unless nonempty_string?(receipt['reviewer_institutional_id']) && distinct_from.none? { |identity| identity == receipt['reviewer_institutional_id'] }
    errors << "#{label}: evidence author and implementer must be human institutional IDs" unless owner_person_id?(receipt['evidence_author_institutional_id']) && owner_person_id?(receipt['implementer_institutional_id'])
    errors << "#{label}: verification_method must be institutional_registry or detached_signature" unless %w[institutional_registry detached_signature].include?(receipt['verification_method'])
    errors << "#{label}: verification_reference must be substantive" unless nonempty_string?(receipt['verification_reference'])
    validate_owner_reviewed_receipt_signature(receipt, payload_bytes, 'registry_review', label)
  end

  def validate_owner_appointment(appointment, index, register, policy)
    label = "owner appointment register appointments[#{index}]"
    validate_closed_object(appointment, OWNER_APPOINTMENT_KEYS, label)
    return unless appointment.is_a?(Hash)

    %w[subject scope decision_rights issuer conflict_disclosure delegation acceptance issuer_signature registry_receipt].each do |key|
      expected_keys = {
        'subject' => OWNER_SUBJECT_KEYS, 'scope' => OWNER_SCOPE_KEYS, 'decision_rights' => OWNER_DECISION_RIGHTS_KEYS,
        'issuer' => OWNER_ISSUER_KEYS, 'conflict_disclosure' => OWNER_CONFLICT_KEYS, 'delegation' => OWNER_DELEGATION_KEYS,
        'acceptance' => OWNER_SIGNATURE_KEYS, 'issuer_signature' => OWNER_SIGNATURE_KEYS, 'registry_receipt' => OWNER_REGISTRY_RECEIPT_KEYS
      }.fetch(key)
      validate_closed_object(appointment[key], expected_keys, "#{label} #{key}")
    end
    subject = appointment['subject'] || {}
    issuer = appointment['issuer'] || {}
    role = appointment['authority_role']
    signature_records = [appointment['acceptance'], appointment['issuer_signature'], appointment['registry_receipt']]
    bound_registries = signature_records.map { |record| owner_registry_for_signature(record || {}) }
    registry_bindings = signature_records.map { |record| Array(record).empty? ? nil : OWNER_SIGNATURE_REGISTRY_BINDING_KEYS.map { |field| record[field] } }
    exact_historical_registry = bound_registries.all? && registry_bindings.compact.uniq.length == 1
    historical_registry = exact_historical_registry ? bound_registries.first : nil
    errors << "#{label}: appointment_id must be stable APP-G0-*" unless appointment['appointment_id'].to_s.match?(/\AAPP-G0-[A-Z0-9-]+\z/)
    errors << "#{label}: appointment_register_id must bind the current register" unless appointment['appointment_register_id'] == OWNER_APPOINTMENT_REGISTER_ID
    errors << "#{label}: acceptance, issuer and reviewer must bind one exact historical identity-registry snapshot" unless exact_historical_registry
    errors << "#{label}: subject must bind a registered human institutional ID, identity type, name, title and unit" unless owner_person_id_in_registry?(subject['institutional_id'], historical_registry) && subject['identity_type'] == 'person' && %w[display_name title unit].all? { |key| nonempty_string?(subject[key]) }
    registry_identity = owner_registry_identity(subject['institutional_id'], historical_registry)
    errors << "#{label}: subject identity_type/name/title/unit must exactly match the bound historical institutional identity registry" unless registry_identity && %w[identity_type display_name title unit].all? { |key| subject[key] == registry_identity[key] }
    errors << "#{label}: unknown authority_role #{role.inspect}" unless OWNER_ROLE_CAPACITY_MAP.key?(role)
    if OWNER_ROLE_CAPACITY_MAP.key?(role)
      errors << "#{label}: authority_domain must match the closed role policy" unless appointment['authority_domain'] == OWNER_ROLE_DOMAIN_MAP.fetch(role)
      errors << "#{label}: capacity_id must match the closed role policy" unless appointment['capacity_id'] == OWNER_ROLE_CAPACITY_MAP.fetch(role)
    end
    errors << "#{label}: appointer must be a registered human identity distinct from appointee" unless owner_person_id?(issuer['institutional_id']) && issuer['institutional_id'] != subject['institutional_id']
    errors << "#{label}: issuer authority_role must be known" unless OWNER_ROLE_CAPACITY_MAP.key?(issuer['authority_role'])
    errors << "#{label}: issuer mandate_reference must be substantive" unless nonempty_string?(issuer['mandate_reference'])
    effective = owner_time(appointment['effective_at'])
    expiry = owner_time(appointment['expires_at'])
    errors << "#{label}: effective_at/expires_at must be valid and expiry must be later" unless effective && expiry && expiry > effective
    conflict = appointment['conflict_disclosure'] || {}
    errors << "#{label}: conflict status must be none or disclosed" unless %w[none disclosed].include?(conflict['status'])
    errors << "#{label}: disclosed conflicts require details; none requires null details" unless (conflict['status'] == 'none' && conflict['details'].nil?) || (conflict['status'] == 'disclosed' && nonempty_string?(conflict['details']))
    scope = appointment['scope'] || {}
    requirement_ids = scope['requirement_ids']
    bound_policy = owner_policy_snapshot_by_root(appointment['policy_sha256'])
    bound_policy_activation = owner_policy_activation_by_root(appointment['policy_sha256'])
    expected_policy_ids = Array(bound_policy && bound_policy['requirement_policies']).map { |item| item['requirement_id'] }
    errors << "#{label}: scope requirement_ids must be unique known PAR IDs" unless requirement_ids.is_a?(Array) && !requirement_ids.empty? && requirement_ids.uniq == requirement_ids && (requirement_ids - expected_policy_ids).empty?
    expected_batches = Array(requirement_ids).map { |id| Array(bound_policy && bound_policy['requirement_policies']).find { |item| item['requirement_id'] == id }&.fetch('batch') }.compact.uniq
    errors << "#{label}: scope manifest_batches must exactly match requirement scope" unless scope['manifest_batches'] == expected_batches
    rights = appointment['decision_rights'] || {}
    rights_valid = rights.values_at('session_actions', 'permitted_decision_statuses', 'permitted_dispositions').all? { |items| items.is_a?(Array) && !items.empty? && items.uniq == items }
    rights_valid &&= (rights['session_actions'] - OWNER_DECISION_ACTIONS).empty? && (rights['permitted_decision_statuses'] - OWNER_DECISION_STATUSES).empty? && (rights['permitted_dispositions'] - OWNER_PERMITTED_DISPOSITIONS).empty?
    errors << "#{label}: decision rights must be non-empty exact subsets of the closed consent/status/disposition vocabularies" unless rights_valid
    errors << "#{label}: data_boundary must be synthetic_only" unless appointment['data_boundary'] == 'synthetic_only'
    bound_source_sha256s = Array(bound_policy && bound_policy['source_decision_registers']).to_h { |item| [item['batch'], item['sha256']] }
    errors << "#{label}: policy snapshot, manifest and source snapshot digests must bind a fully approved immutable governance snapshot" unless bound_policy && bound_policy_activation && appointment['manifest_sha256'] == bound_policy['manifest_sha256'] && appointment['source_register_sha256s'] == bound_source_sha256s
    applicable = Array(requirement_ids).all? do |requirement_id|
      requirement_policy = Array(bound_policy && bound_policy['requirement_policies']).find { |item| item['requirement_id'] == requirement_id }
      Array(requirement_policy && requirement_policy['eligible_authority_roles']).include?(role) || role == 'appointment_registry_reviewer'
    end
    errors << "#{label}: authority role cannot be appointed outside its exact PAR applicability" unless applicable
    payload_bytes = owner_canonical_json(owner_appointment_payload(appointment))
    payload_sha = Digest::SHA256.hexdigest(payload_bytes)
    errors << "#{label}: canonical_signed_payload_sha256 must be recomputable" unless appointment['canonical_signed_payload_sha256'] == payload_sha
    owner_signature_valid?(appointment['acceptance'], subject['institutional_id'], payload_bytes, 'appointment_acceptance', "#{label} acceptance")
    issuer_authorization = appointment.dig('delegation', 'parent_appointment_id').nil? ? 'appointment_issuer' : nil
    owner_signature_valid?(appointment['issuer_signature'], issuer['institutional_id'], payload_bytes, 'appointment_issuance', "#{label} issuer_signature", required_authorization: issuer_authorization)
    validate_owner_registry_receipt(appointment['registry_receipt'], appointment, payload_bytes, "#{label} registry_receipt")
    signature_times = [appointment.dig('acceptance', 'signed_at'), appointment.dig('issuer_signature', 'signed_at'), appointment.dig('registry_receipt', 'verified_at')].map { |value| owner_time(value) }
    errors << "#{label}: issuer signature, acceptance and independent verification must precede activation" unless effective && signature_times.all? { |timestamp| timestamp && timestamp <= effective }
    errors << "#{label}: independent registry verification must follow issuer signature and appointee acceptance" unless signature_times.all? && signature_times[2] >= signature_times[0] && signature_times[2] >= signature_times[1]
    errors << "#{label}: appointment signatures must follow activation of the exact approved policy snapshot" unless bound_policy_activation && signature_times.all? { |timestamp| timestamp && timestamp >= bound_policy_activation }
    if appointment.dig('delegation', 'parent_appointment_id').nil?
      errors << "#{label}: root appointments require an executive_sponsor issuer mandate" unless issuer['authority_role'] == 'executive_sponsor'
    end
  end

  def owner_identity_id?(value)
    value.is_a?(String) && value.match?(/\AUEU-(?:PERSON|SERVICE)-[A-Z0-9-]+\z/)
  end

  def owner_time(value)
    Time.iso8601(value)
  rescue ArgumentError, TypeError
    nil
  end

  def owner_contains_secret?(value)
    case value
    when Hash
      value.any? do |key, item|
        normalized_key = key.to_s.downcase.gsub(/[^a-z0-9]+/, '_').sub(/\A_+/, '').sub(/_+\z/, '')
        prohibited_names = %w[
          password passphrase token session_token bearer authorization cookie session_secret
          api_key access_token refresh_token client_secret private_key private_key_pem recovery_code recovery_material
          signing_key signing_secret signing_material hmac_key hmac_secret hmac_material
          credential credentials secret secret_material
        ]
        prohibited_key = prohibited_names.include?(normalized_key)
        prohibited_key || owner_contains_secret?(item)
      end
    when Array
      value.any? { |item| owner_contains_secret?(item) }
    when String
      value.match?(/BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY|\bBearer\s+[A-Za-z0-9._~+\/-]+=*|(?:password|passphrase|token|session[_ -]?token|authorization|cookie|session[_ -]?secret|api[_ -]?key|access[_ -]?token|refresh[_ -]?token|recovery[_ -]?(?:code|material)|signing[_ -]?(?:key|secret|material)|hmac[_ -]?(?:key|secret|material)|credential|secret)\s*[:=]\s*\S+/i)
    else
      false
    end
  end

  def owner_required_roles_for_policy(requirement_policy)
    return [] unless requirement_policy.is_a?(Hash)

    [*Array(requirement_policy['required_authority_domains']), *Array(requirement_policy['required_special_roles'])].uniq
  end

  def validate_owner_appointment_identity_and_role_rules(appointments)
    appointments.group_by { |appointment| appointment.dig('subject', 'institutional_id') }.each do |identity, records|
      next unless owner_identity_id?(identity)

      biographies = records.map { |record| record['subject']&.slice('display_name', 'title', 'unit') }.uniq
      errors << "owner appointment register: institutional identity #{identity} has conflicting name/title/unit aliases" unless biographies.length == 1
      roles = records.map { |record| record['authority_role'] }.compact
      roles.combination(2).each do |first_role, second_role|
        pair = [first_role, second_role].sort
        if OWNER_INCOMPATIBLE_ROLE_PAIRS.include?(pair)
          errors << "owner appointment register: identity #{identity} holds incompatible roles #{pair.join(' + ')}"
          next
        end
        first_capacity = OWNER_ROLE_CAPACITY_MAP[first_role]
        second_capacity = OWNER_ROLE_CAPACITY_MAP[second_role]
        next if first_capacity.nil? || second_capacity.nil? || first_capacity == second_capacity
        unless OWNER_COMPATIBILITY_WHITELIST.include?([first_capacity, second_capacity].sort)
          errors << "owner appointment register: identity #{identity} dual-hat capacities #{[first_capacity, second_capacity].sort.join(' + ')} are not explicitly whitelisted"
        end
      end
      records.combination(2).each do |first, second|
        overlap = Array(first.dig('scope', 'requirement_ids')) & Array(second.dig('scope', 'requirement_ids'))
        first_start = owner_time(first['effective_at'])
        first_end = owner_time(first['expires_at'])
        second_start = owner_time(second['effective_at'])
        second_end = owner_time(second['expires_at'])
        time_overlap = first_start && first_end && second_start && second_end && [first_start, second_start].max < [first_end, second_end].min
        if first['authority_role'] == second['authority_role'] && !overlap.empty? && time_overlap
          errors << "owner appointment register: identity #{identity} requires one non-overlapping appointment per role/scope; duplicate #{first['authority_role']} scope #{overlap.join(', ')}"
        end
      end
    end
    appointments.group_by { |appointment| appointment.dig('subject', 'display_name').to_s.downcase.strip.gsub(/\s+/, ' ') }.each do |name, records|
      ids = records.map { |record| record.dig('subject', 'institutional_id') }.compact.uniq
      errors << "owner appointment register: display-name alias #{name.inspect} maps to multiple institutional identities" if !name.empty? && ids.length > 1
    end
  end

  def owner_event_payload(event)
    event.slice(*(%w[event_id appointment_id parent_appointment_id event_type effective_at reason issuer_institutional_id prior_event_sha256 signature_artifact_type key_id algorithm purpose signed_at identity_registry_id identity_registry_snapshot_id identity_registry_snapshot_revision identity_registry_snapshot_sha256 identity_registry_root_sha256]))
  end

  def validate_owner_appointment_events(events, appointments)
    known = appointments.to_h { |appointment| [appointment['appointment_id'], appointment] }
    event_ids = []
    events.group_by { |event| event['appointment_id'] if event.is_a?(Hash) }.each do |appointment_id, appointment_events|
      previous_sha = nil
      previous_time = nil
      lifecycle_state = 'active'
      appointment_events.each_with_index do |event, index|
        label = "owner appointment register event #{event['event_id'] || index}"
        validate_closed_object(event, OWNER_EVENT_KEYS, label)
        next unless event.is_a?(Hash)

        event_ids << event['event_id']
        errors << "#{label}: event_id must be stable EVT-G0-*" unless event['event_id'].to_s.match?(/\AEVT-G0-[A-Z0-9-]+\z/)
        errors << "#{label}: appointment_id must reference an appointment" unless known.key?(appointment_id)
        errors << "#{label}: event_type must be delegated, suspended, resumed or revoked" unless %w[delegated suspended resumed revoked].include?(event['event_type'])
        event_time = owner_time(event['effective_at'])
        errors << "#{label}: effective_at and reason must be substantive" unless event_time && nonempty_string?(event['reason'])
        errors << "#{label}: lifecycle events must be ordered by effective_at" if event_time && previous_time && event_time < previous_time
        appointment = known[appointment_id]
        signed_at = owner_time(event['signed_at'])
        if appointment && event_time
          effective = owner_time(appointment['effective_at'])
          expiry = owner_time(appointment['expires_at'])
          event_in_window = if event['event_type'] == 'delegated'
                              effective && event_time <= effective
                            else
                              effective && expiry && event_time >= effective && event_time < expiry
                            end
          errors << "#{label}: lifecycle/delegation event timing is outside its allowed appointment window" unless event_in_window && signed_at && signed_at <= event_time
          errors << "#{label}: lifecycle event issuer must match the appointment issuer authority" unless event['issuer_institutional_id'] == appointment.dig('issuer', 'institutional_id')
        end
        transition_valid = case event['event_type']
                           when 'delegated' then lifecycle_state == 'active'
                           when 'suspended' then lifecycle_state == 'active'
                           when 'resumed' then lifecycle_state == 'suspended'
                           when 'revoked' then %w[active suspended].include?(lifecycle_state)
                           else false
                           end
        errors << "#{label}: lifecycle transition is invalid or attempts to mutate a terminal revocation" unless transition_valid
        lifecycle_state = case event['event_type']
                          when 'suspended' then 'suspended'
                          when 'resumed' then 'active'
                          when 'revoked' then 'revoked'
                          else lifecycle_state
                          end if transition_valid
        errors << "#{label}: prior_event_sha256 must preserve append-only event order" unless event['prior_event_sha256'] == previous_sha
        payload_bytes = owner_canonical_json(owner_event_payload(event))
        payload_sha = Digest::SHA256.hexdigest(payload_bytes)
        errors << "#{label}: canonical_signed_payload_sha256 must be recomputable" unless event['canonical_signed_payload_sha256'] == payload_sha
        event_sha = Digest::SHA256.hexdigest(owner_canonical_json(event.reject { |key, _value| %w[event_sha256 signature].include?(key) }))
        errors << "#{label}: event_sha256 must be recomputable and immutable" unless event['event_sha256'] == event_sha
        event_authorization = event['event_type'] == 'delegated' ? nil : 'appointment_issuer'
        owner_verify_detached_signature(event, event['issuer_institutional_id'], payload_bytes, 'lifecycle_event', event['signed_at'], label, required_authorization: event_authorization)
        previous_sha = event['event_sha256']
        previous_time = event_time if event_time
      end
    end
    duplicates = event_ids.group_by(&:itself).select { |_id, values| values.length > 1 }.keys
    errors << "owner appointment register: duplicate lifecycle event IDs #{duplicates.join(', ')}" unless duplicates.empty?
  end

  def validate_owner_delegations(appointments, events)
    by_id = appointments.to_h { |appointment| [appointment['appointment_id'], appointment] }
    parents = {}
    appointments.each do |appointment|
      delegation = appointment['delegation'] || {}
      appointment_id = appointment['appointment_id']
      parent_id = delegation['parent_appointment_id']
      parents[appointment_id] = parent_id if nonempty_string?(parent_id)
      scope = Array(appointment.dig('scope', 'requirement_ids'))
      errors << "owner appointment register #{appointment_id}: delegation scope must exactly bind appointment scope" unless delegation['scope_requirement_ids'] == scope
      if parent_id.nil?
        errors << "owner appointment register #{appointment_id}: root appointment delegation depth must be 0" unless delegation['depth'] == 0
        next
      end
      parent = by_id[parent_id]
      errors << "owner appointment register #{appointment_id}: delegation parent must exist" unless parent
      next unless parent

      parent_delegation = parent['delegation'] || {}
      parent_scope = Array(parent.dig('scope', 'requirement_ids'))
      errors << "owner appointment register #{appointment_id}: delegation cannot widen parent PAR scope" unless (scope - parent_scope).empty?
      errors << "owner appointment register #{appointment_id}: delegation must preserve authority domain, role and capacity" unless %w[authority_domain authority_role capacity_id].all? { |key| appointment[key] == parent[key] }
      errors << "owner appointment register #{appointment_id}: delegation cannot outlive parent" unless owner_time(appointment['expires_at']) && owner_time(parent['expires_at']) && owner_time(appointment['expires_at']) <= owner_time(parent['expires_at'])
      errors << "owner appointment register #{appointment_id}: delegation depth must be exactly one and re-delegation is forbidden" unless delegation['depth'] == 1 && parent_delegation['depth'] == 0 && parent_delegation['may_redelegate'] == true && delegation['may_redelegate'] == false
      errors << "owner appointment register #{appointment_id}: child appointer must be the parent appointee" unless appointment.dig('issuer', 'institutional_id') == parent.dig('subject', 'institutional_id')
      errors << "owner appointment register #{appointment_id}: child issuer role must match the parent appointed role" unless appointment.dig('issuer', 'authority_role') == parent['authority_role']
      child_rights = appointment['decision_rights'] || {}
      parent_rights = parent['decision_rights'] || {}
      rights_narrow = OWNER_DECISION_RIGHTS_KEYS.all? { |key| (Array(child_rights[key]) - Array(parent_rights[key])).empty? }
      errors << "owner appointment register #{appointment_id}: delegated rights must be exact subsets of parent rights" unless rights_narrow
      child_effective = owner_time(appointment['effective_at'])
      errors << "owner appointment register #{appointment_id}: parent must be historically active when delegated authority activates" unless child_effective && owner_appointment_state_at(parent, events, child_effective) == 'active'
      delegated_events = events.select { |event| event.is_a?(Hash) && event['appointment_id'] == appointment_id && event['event_type'] == 'delegated' && event['parent_appointment_id'] == parent_id }
      valid_event_time = child_effective && delegated_events.one? && owner_time(delegated_events.first['effective_at']) && owner_time(delegated_events.first['effective_at']) <= child_effective
      errors << "owner appointment register #{appointment_id}: delegated child requires one effective immutable delegation event before activation" unless valid_event_time
    end
    cycle = owner_parent_cycle(parents)
    errors << "owner appointment register: delegation cycle detected #{cycle.join(' -> ')}" if cycle
  end

  def owner_parent_cycle(parents)
    parents.keys.each do |start|
      path = []
      current = start
      while current && parents.key?(current)
        return [*path[path.index(current)..], current] if path.include?(current)
        path << current
        current = parents[current]
      end
    end
    nil
  end

  def owner_appointment_state_at(appointment, events, timestamp)
    effective = owner_time(appointment['effective_at'])
    expiry = owner_time(appointment['expires_at'])
    return 'inactive' unless effective && expiry && timestamp >= effective && timestamp < expiry

    state = 'active'
    events.select { |event| event.is_a?(Hash) && event['appointment_id'] == appointment['appointment_id'] && owner_time(event['effective_at']) && owner_time(event['effective_at']) <= timestamp }.each do |event|
      state = case event['event_type']
              when 'suspended' then 'suspended'
              when 'resumed' then state == 'suspended' ? 'active' : 'invalid_resume'
              when 'revoked' then 'revoked'
              else state
              end
    end
    state
  end

  def owner_appointment_covers?(appointment, requirement_id, role, timestamp, events = [], appointments = [])
    return false unless appointment.is_a?(Hash) && appointment['authority_role'] == role && Array(appointment.dig('scope', 'requirement_ids')).include?(requirement_id) && owner_appointment_state_at(appointment, events, timestamp) == 'active'

    parent_id = appointment.dig('delegation', 'parent_appointment_id')
    return true unless nonempty_string?(parent_id)

    parent = Array(appointments).find { |candidate| candidate['appointment_id'] == parent_id }
    parent && owner_appointment_state_at(parent, events, timestamp) == 'active'
  end

  def owner_appointment_continuously_covers?(appointment, requirement_ids, role, started_at, ended_at, events = [], appointments = [], visited = [])
    return false unless appointment.is_a?(Hash) && started_at && ended_at && ended_at > started_at
    return false unless appointment['authority_role'] == role
    return false unless (Array(requirement_ids) - Array(appointment.dig('scope', 'requirement_ids'))).empty?

    effective_at = owner_time(appointment['effective_at'])
    expires_at = owner_time(appointment['expires_at'])
    return false unless effective_at && expires_at && effective_at <= started_at && expires_at >= ended_at
    return false unless owner_appointment_state_at(appointment, events, started_at) == 'active'

    interrupted = Array(events).any? do |event|
      next false unless event.is_a?(Hash) && event['appointment_id'] == appointment['appointment_id'] && %w[suspended revoked].include?(event['event_type'])

      event_time = owner_time(event['effective_at'])
      event_time && event_time >= started_at && event_time < ended_at
    end
    return false if interrupted

    parent_id = appointment.dig('delegation', 'parent_appointment_id')
    return true unless nonempty_string?(parent_id)
    return false if visited.include?(appointment['appointment_id'])

    parent = Array(appointments).find { |candidate| candidate.is_a?(Hash) && candidate['appointment_id'] == parent_id }
    parent && owner_appointment_continuously_covers?(parent, requirement_ids, parent['authority_role'], started_at, ended_at, events, appointments, [*visited, appointment['appointment_id']])
  end

  def expected_owner_decision_session_register_top(policy = nil, policy_reference: File.basename(@owner_authority_policy_path), policy_sha256: owner_policy_sha256, appointment_reference: File.basename(@owner_appointment_register_path), appointment_sha256: nil)
    policy ||= load_owner_governance_json(@owner_authority_policy_path, 'owner authority policy header source')
    policy_sources = policy.is_a?(Hash) && policy['source_decision_registers'].is_a?(Array) ? policy['source_decision_registers'] : []
    bound_appointment_sha = appointment_sha256 || (File.file?(@owner_appointment_register_path) ? Digest::SHA256.file(@owner_appointment_register_path).hexdigest : nil)
    {
      'schema_version' => OWNER_POLICY_SCHEMA_VERSION,
      'register_id' => OWNER_DECISION_SESSION_REGISTER_ID,
      'register_status' => 'open',
      'data_boundary' => 'synthetic_only',
      'policy_reference' => policy_reference,
      'policy_sha256' => policy_sha256,
      'appointment_register_reference' => appointment_reference,
      'appointment_register_sha256' => bound_appointment_sha,
      'manifest_reference' => File.basename(@batch_manifest_path),
      'manifest_sha256' => owner_manifest_sha256,
      'source_decision_registers' => policy_sources
    }
  end

  def validate_owner_decision_session_register(register, appointment_register, policy, _manifest)
    label = 'decision session register'
    validate_closed_object(register, OWNER_DECISION_SESSION_REGISTER_KEYS, label)
    return unless register.is_a?(Hash)

    expected_owner_decision_session_register_top.each do |key, value|
      next if key == 'register_status'
      errors << "#{label}: #{key} must bind the current policy, appointment register, manifest and A-G registers" unless register[key] == value
    end
    errors << "#{label}: register_status must be open or complete" unless %w[open complete].include?(register['register_status'])
    errors << "#{label}: sessions must be an array" unless register['sessions'].is_a?(Array)
    errors << "#{label}: must not contain credentials, secrets or private keys" if owner_contains_secret?(register)
    sessions = Array(register['sessions'])
    appointments = Array(appointment_register['appointments'])
    events = Array(appointment_register['events'])
    if sessions.any? || register['register_status'] == 'complete'
      errors << "#{label}: terminal sessions require a cryptographically approved authority policy and independently pinned trust root" unless policy['policy_status'] == 'approved' && owner_trust_root_pinned?
    end
    validate_owner_sessions(sessions, appointments, events, policy)
    latest = owner_latest_decisions(sessions)
    resolved = Array(policy['requirement_policies']).count do |requirement_policy|
      decision = latest[requirement_policy['requirement_id']]
      source_entry = Array(@decision_entries_by_batch[requirement_policy['batch']]).find { |entry| entry['requirement_id'] == requirement_policy['requirement_id'] }
      decision && %w[approve defer reject].include?(decision['decision_status']) && owner_session_decision_matches_source?(decision, source_entry)
    end
    sequences = sessions.map { |session| session['sequence'] if session.is_a?(Hash) }.compact.uniq.sort
    if register['register_status'] == 'complete'
      errors << "#{label}: complete requires S0-S7 coverage and all 268 exact source-matched terminal decisions" unless sequences == (0..7).to_a && resolved == 268
    end
    return unless @mode == 'g0'

    errors << "#{label}: G0 requires a complete S0-S7 register and all 268 exact source-matched terminal decisions; found #{resolved}" unless register['register_status'] == 'complete' && sequences == (0..7).to_a && resolved == 268
  end

  def validate_owner_sessions(sessions, appointments, events, policy)
    session_ids = sessions.map { |session| session['session_id'] if session.is_a?(Hash) }.compact
    duplicates = session_ids.group_by(&:itself).select { |_id, values| values.length > 1 }.keys
    errors << "decision session register: duplicate session IDs #{duplicates.join(', ')}" unless duplicates.empty?
    prior_by_requirement = {}
    revision_by_requirement = Hash.new(0)
    latest_by_requirement = {}
    revision_by_sequence = Hash.new(0)
    prior_session_sha = nil
    previous_end = nil
    sessions.each_with_index do |session, index|
      validate_owner_session(session, index, appointments, events, policy, prior_by_requirement, revision_by_requirement, latest_by_requirement, prior_session_sha, revision_by_sequence, previous_end)
      next unless session.is_a?(Hash)

      prior_session_sha = session['canonical_session_sha256']
      revision_by_sequence[session['sequence']] = session['session_revision'] if session['session_revision'].is_a?(Integer)
      previous_end = owner_time(session['ended_at']) || previous_end
    end
    validate_owner_consolidation_terminals(latest_by_requirement)
    validate_owner_session_consolidation_cycles(latest_by_requirement)
  end

  def validate_owner_session(session, index, appointments, events, policy, prior_by_requirement, revision_by_requirement, latest_by_requirement, prior_session_sha, revision_by_sequence, previous_end)
    label = "decision session register sessions[#{index}]"
    validate_closed_object(session, OWNER_SESSION_KEYS, label)
    return unless session.is_a?(Hash)

    errors << "#{label}: session_id must be stable SESSION-G0-*" unless session['session_id'].to_s.match?(/\ASESSION-G0-[A-Z0-9-]+\z/)
    sequence_policy = OWNER_SESSION_SEQUENCE.find { |item| item['sequence'] == session['sequence'] }
    errors << "#{label}: sequence/session_code must use the exact eight-session catalog" unless sequence_policy && sequence_policy['session_code'] == session['session_code']
    expected_revision = revision_by_sequence[session['sequence']] + 1
    errors << "#{label}: session_revision must be monotonic per sequence" unless session['session_revision'] == expected_revision
    errors << "#{label}: prior_session_sha256 must preserve one global append-only session chain" unless session['prior_session_sha256'] == prior_session_sha
    expected_batch = session['sequence'] == 0 ? 'A' : (64 + session['sequence'].to_i).chr
    errors << "#{label}: decisions must stay within the exact S0/A or S1-A through S7-G sequence scope" unless Array(session['decisions']).all? { |decision| decision.is_a?(Hash) && decision['batch'] == expected_batch }
    errors << "#{label}: status must be closed; drafts cannot carry terminal decisions" unless session['status'] == 'closed'
    started = owner_time(session['started_at'])
    ended = owner_time(session['ended_at'])
    errors << "#{label}: started_at/ended_at must be valid and ordered" unless started && ended && ended > started
    errors << "#{label}: sessions must be append-only in non-overlapping time order" if previous_end && started && started < previous_end
    bound_policy = owner_policy_snapshot_by_root(session['policy_sha256'])
    bound_source_sha256s = Array(bound_policy && bound_policy['source_decision_registers']).to_h { |item| [item['batch'], item['sha256']] }
    errors << "#{label}: policy/manifest/source snapshot bindings are unknown or stale" unless bound_policy && session['manifest_sha256'] == bound_policy['manifest_sha256'] && session['source_register_sha256s'] == bound_source_sha256s
    snapshot_cutoff = owner_time(session['appointment_snapshot_cutoff'])
    errors << "#{label}: appointment snapshot cutoff must be at or before session start" unless snapshot_cutoff && started && snapshot_cutoff <= started
    validate_owner_session_snapshot(session, appointments, events, label)
    errors << "#{label}: decisions must be a non-empty array" unless session['decisions'].is_a?(Array) && !session['decisions'].empty?
    duplicate_rows = Array(session['decisions']).map { |decision| decision['requirement_id'] if decision.is_a?(Hash) }.compact.group_by(&:itself).select { |_id, values| values.length > 1 }.keys
    errors << "#{label}: parallel final revisions are forbidden for #{duplicate_rows.join(', ')}" unless duplicate_rows.empty?

    by_id = appointments.to_h { |appointment| [appointment['appointment_id'], appointment] }
    snapshot_events = events.first(session['appointment_event_prefix_count'].to_i)
    snapshot_appointment_ids = appointments.first(session['appointment_snapshot_count'].to_i).map { |appointment| appointment['appointment_id'] }
    chair_appointment_id = session['chair_appointment_id']
    facilitator_appointment_id = session['facilitator_appointment_id']
    chair_appointment = by_id[chair_appointment_id]
    facilitator_appointment = by_id[facilitator_appointment_id]
    session_rules = bound_policy.is_a?(Hash) ? bound_policy['session_rules'] : {}
    chair_role = session_rules['chair_authority_role']
    facilitator_role = session_rules['facilitator_authority_role']
    officer_separation_rule = session_rules['officer_separation_rule']
    chair_domain = OWNER_ROLE_DOMAIN_MAP[chair_role]
    facilitator_domain = OWNER_ROLE_DOMAIN_MAP[facilitator_role]
    distinct_appointments = nonempty_string?(chair_appointment_id) && nonempty_string?(facilitator_appointment_id) && chair_appointment_id != facilitator_appointment_id
    errors << "#{label}: chair and facilitator must use different non-empty appointment IDs under the bound policy" unless officer_separation_rule == 'distinct_appointment_and_identity' && distinct_appointments
    errors << "#{label}: chair appointment must exist with the role/domain required by the bound policy (#{chair_role || 'missing'})" unless chair_appointment && nonempty_string?(chair_role) && chair_appointment['authority_role'] == chair_role && chair_appointment['authority_domain'] == chair_domain
    errors << "#{label}: facilitator appointment must exist with the role/domain required by the bound policy (#{facilitator_role || 'missing'})" unless facilitator_appointment && nonempty_string?(facilitator_role) && facilitator_appointment['authority_role'] == facilitator_role && facilitator_appointment['authority_domain'] == facilitator_domain
    chair_subject_id = chair_appointment&.dig('subject', 'institutional_id')
    facilitator_subject_id = facilitator_appointment&.dig('subject', 'institutional_id')
    distinct_subjects = nonempty_string?(chair_subject_id) && nonempty_string?(facilitator_subject_id) && chair_subject_id != facilitator_subject_id
    errors << "#{label}: chair and facilitator must bind different non-empty institutional subject IDs under the bound policy" unless officer_separation_rule == 'distinct_appointment_and_identity' && distinct_subjects
    session_requirement_ids = Array(session['decisions']).each_with_object([]) do |decision, requirement_ids|
      requirement_ids << decision['requirement_id'] if decision.is_a?(Hash) && nonempty_string?(decision['requirement_id'])
    end.uniq
    { 'chair' => [chair_appointment, chair_role], 'facilitator' => [facilitator_appointment, facilitator_role] }.each do |officer, (appointment, role)|
      covers_session = owner_appointment_continuously_covers?(appointment, session_requirement_ids, role, started, ended, events, appointments)
      errors << "#{label}: #{officer} appointment must cover every requirement ID decided in the session and remain continuously active throughout the session" unless covers_session
    end
    [chair_appointment_id, facilitator_appointment_id].each do |appointment_id|
      appointment = by_id[appointment_id]
      bound_to_session = appointment && appointment['policy_sha256'] == session['policy_sha256'] && appointment['source_register_sha256s'] == session['source_register_sha256s']
      continuously_active = appointment && owner_appointment_continuously_covers?(appointment, session_requirement_ids, appointment['authority_role'], started, ended, events, appointments)
      errors << "#{label}: chair/facilitator appointments must bind the session snapshots, be signed into the prefix and remain continuously active" unless bound_to_session && snapshot_appointment_ids.include?(appointment_id) && continuously_active
    end
    Array(session['decisions']).each_with_index do |decision, decision_index|
      validate_owner_session_decision(decision, decision_index, session, appointments, snapshot_events, bound_policy || policy, prior_by_requirement, revision_by_requirement, latest_by_requirement)
      next unless decision.is_a?(Hash) && nonempty_string?(decision['requirement_id'])

      prior_by_requirement[decision['requirement_id']] = decision['decision_sha256']
      revision_by_requirement[decision['requirement_id']] = decision['decision_revision'] if decision['decision_revision'].is_a?(Integer)
      latest_by_requirement[decision['requirement_id']] = decision
    end
    session_payload = session.reject { |key, _value| %w[canonical_session_sha256 registry_receipt].include?(key) }
    session_sha = Digest::SHA256.hexdigest(owner_canonical_json(session_payload))
    errors << "#{label}: canonical_session_sha256 must be recomputable; post-signature mutation fails closed" unless session['canonical_session_sha256'] == session_sha
    validate_owner_session_receipt(session['registry_receipt'], session, appointments, session_payload, label)
  end

  def validate_owner_session_snapshot(session, appointments, events, label)
    cutoff = owner_time(session['appointment_snapshot_cutoff'])
    appointment_count = session['appointment_snapshot_count']
    event_count = session['appointment_event_prefix_count']
    counts_valid = appointment_count.is_a?(Integer) && appointment_count >= 0 && appointment_count <= appointments.length && event_count.is_a?(Integer) && event_count >= 0 && event_count <= events.length
    errors << "#{label}: appointment/event snapshot counts must identify contiguous current-log prefixes" unless counts_valid
    return unless counts_valid && cutoff

    expected_appointment_count = appointments.take_while { |appointment| owner_time(appointment['effective_at']) && owner_time(appointment['effective_at']) <= cutoff }.length
    expected_event_count = events.take_while { |event| owner_time(event['effective_at']) && owner_time(event['effective_at']) <= cutoff }.length
    errors << "#{label}: appointment snapshot count must include the complete contiguous prefix at cutoff" unless appointment_count == expected_appointment_count
    errors << "#{label}: lifecycle event count must include the complete contiguous prefix at cutoff" unless event_count == expected_event_count
    appointment_root = Digest::SHA256.hexdigest(owner_canonical_json(appointments.first(appointment_count)))
    event_root = Digest::SHA256.hexdigest(owner_canonical_json(events.first(event_count)))
    errors << "#{label}: appointment snapshot root does not match the immutable prefix" unless session['appointment_snapshot_root_sha256'] == appointment_root
    errors << "#{label}: event prefix root does not match the immutable append-only prefix" unless session['appointment_event_prefix_sha256'] == event_root
  end

  def owner_required_seats_for_decision(requirement_policy, decision, policy, latest_by_requirement)
    source_id = requirement_policy['requirement_id']
    seats = owner_required_roles_for_policy(requirement_policy).map { |role| { 'requirement_id' => source_id, 'role' => role } }
    rule_key = decision['decision_status'] == 'defer' ? 'defer' : decision['disposition']
    rule = OWNER_DISPOSITION_RULES[rule_key] || {}
    seats.concat(Array(rule['additional_authority_roles']).map { |role| { 'requirement_id' => source_id, 'role' => role } })
    seats << { 'requirement_id' => source_id, 'role' => 'statutory_sponsor' } if requirement_policy['statutory_scope']
    if rule['unresolved_gate_owners']
      seats.concat(Array(decision['unresolved_gate_authority_domains']).map { |role| { 'requirement_id' => source_id, 'role' => role } })
    elsif !Array(decision['unresolved_gate_authority_domains']).empty?
      seats << { 'requirement_id' => source_id, 'role' => '__unexpected_unresolved_gate_owner__' }
    end
    if rule['member_and_terminal_owners']
      member_ids = Array(decision['consolidation_member_ids'])
      member_ids.each do |requirement_id|
        member_policy = Array(policy['requirement_policies']).find { |item| item['requirement_id'] == requirement_id }
        seats.concat(owner_required_roles_for_policy(member_policy).map { |role| { 'requirement_id' => requirement_id, 'role' => role } })
      end
      target_policy = Array(policy['requirement_policies']).find { |item| item['requirement_id'] == decision['target_requirement_id'] }
      seats.concat(owner_required_roles_for_policy(target_policy).map { |role| { 'requirement_id' => decision['target_requirement_id'], 'role' => role } })
      terminal = latest_by_requirement[decision['target_requirement_id']]
      seats << { 'requirement_id' => decision['target_requirement_id'], 'role' => '__missing_approved_terminal__' } unless terminal && terminal['decision_status'] == 'approve' && %w[reproduce replace].include?(terminal['disposition'])
    end
    seats.uniq
  end

  def owner_find_candidate_id(value)
    case value
    when Hash
      return value['candidate_id'] if nonempty_string?(value['candidate_id'])
      value.each_value { |child| (found = owner_find_candidate_id(child)) && (return found) }
    when Array
      value.each { |child| (found = owner_find_candidate_id(child)) && (return found) }
    end
    nil
  end

  def owner_source_snapshot_for(batch, source_sha = nil)
    candidates = (@owner_source_snapshots || {}).values.select { |snapshot| snapshot.dig('descriptor', 'batch') == batch }
    source_sha ? candidates.find { |snapshot| snapshot.dig('descriptor', 'sha256') == source_sha } : candidates.max_by { |snapshot| snapshot.dig('descriptor', 'snapshot_revision').to_i }
  end

  def owner_normalized_source_decision(requirement_policy, source_sha = nil)
    entry = owner_source_entry_for(requirement_policy, source_sha)
    return {} unless entry

    source = entry['decision'] || {}
    disposition = source['canonical_disposition']
    target = source['target'] || {}
    target_requirement_id = if disposition == 'reproduce'
                              requirement_policy['requirement_id']
                            elsif target['reference'].to_s.match?(PAR_ID_PATTERN)
                              target['reference']
                            end
    candidate_id = owner_find_candidate_id(entry)
    members = if disposition == 'consolidate' && candidate_id
                source_snapshot = owner_source_snapshot_for(requirement_policy['batch'], source_sha)
                Array(source_snapshot && source_snapshot.dig('register', 'entries')).select { |candidate| owner_find_candidate_id(candidate) == candidate_id }.map { |candidate| candidate['requirement_id'] }.sort
              else
                []
              end
    unresolved = source['status'] == 'defer' ? owner_unresolved_dependency_authorities(entry).sort : []
    {
      'status' => source['status'], 'disposition' => disposition,
      'target_reference' => target['reference'], 'target_requirement_id' => target_requirement_id,
      'exclusions' => Array(target['exclusions']), 'conditions' => Array(entry.dig('approval', 'conditions')),
      'consolidation_member_ids' => members, 'unresolved_gate_authority_domains' => unresolved
    }
  end

  def owner_source_entry_for(requirement_policy, source_sha = nil)
    snapshot = owner_source_snapshot_for(requirement_policy['batch'], source_sha)
    entries = snapshot ? Array(snapshot.dig('register', 'entries')) : Array(@decision_entries_by_batch[requirement_policy['batch']])
    entries.find { |entry| entry['requirement_id'] == requirement_policy['requirement_id'] }
  end

  def owner_expected_evidence_roots(requirement_policy, source_sha = nil)
    entry = owner_source_entry_for(requirement_policy, source_sha)
    return [] unless entry

    excluded = %w[legacy_menu decision downstream_impacts]
    [Digest::SHA256.hexdigest(owner_canonical_json(entry.reject { |key, _value| excluded.include?(key) }))]
  end

  def owner_expected_control_roots(requirement_policy, policy)
    control = {
      'requirement_policy' => requirement_policy,
      'separation_rules' => policy['separation_rules'],
      'disposition_rules' => policy['disposition_rules'],
      'session_rules' => policy['session_rules']
    }
    [Digest::SHA256.hexdigest(owner_canonical_json(control))]
  end

  def validate_owner_session_decision(decision, index, session, appointments, events, policy, prior_by_requirement, revision_by_requirement, latest_by_requirement)
    label = "decision session #{session['session_id']} decisions[#{index}]"
    validate_closed_object(decision, OWNER_SESSION_DECISION_KEYS, label)
    return unless decision.is_a?(Hash)

    requirement_policy = Array(policy['requirement_policies']).find { |item| item['requirement_id'] == decision['requirement_id'] }
    errors << "#{label}: requirement_id must be one of the exact 268 policy rows" unless requirement_policy
    return unless requirement_policy

    decided_at = owner_time(decision['decided_at'])
    session_started = owner_time(session['started_at'])
    session_ended = owner_time(session['ended_at'])
    errors << "#{label}: session_id, policy snapshot root and decision timestamp must bind the signed session" unless decision['session_id'] == session['session_id'] && decision['policy_sha256'] == session['policy_sha256'] && decided_at && session_started && session_ended && decided_at >= session_started && decided_at <= session_ended
    expected_revision = revision_by_requirement[decision['requirement_id']] + 1
    errors << "#{label}: decision_revision must be monotonic for this PAR" unless decision['decision_revision'] == expected_revision
    errors << "#{label}: batch, row digest and register digest must bind the current requirement row" unless decision['batch'] == requirement_policy['batch'] && decision['row_sha256'] == requirement_policy['source_row_sha256'] && decision['batch_register_sha256'] == requirement_policy['batch_register_sha256']
    normalized_source = owner_normalized_source_decision(requirement_policy, decision['batch_register_sha256'])
    source_sha = Digest::SHA256.hexdigest(owner_canonical_json(normalized_source))
    exact_source = decision['source_decision_sha256'] == source_sha && decision['decision_status'] == normalized_source['status'] && decision['disposition'] == normalized_source['disposition'] && decision['target_reference'] == normalized_source['target_reference'] && decision['target_requirement_id'] == normalized_source['target_requirement_id'] && decision['exclusions'] == normalized_source['exclusions'] && decision['conditions'] == normalized_source['conditions'] && decision['consolidation_member_ids'] == normalized_source['consolidation_member_ids'] && decision['unresolved_gate_authority_domains'] == normalized_source['unresolved_gate_authority_domains']
    errors << "#{label}: session decision must exactly match the normalized terminal source decision and derived unresolved owners" unless exact_source && %w[approve defer reject].include?(normalized_source['status'])
    errors << "#{label}: decision_status is invalid" unless OWNER_DECISION_STATUSES.include?(decision['decision_status'])
    errors << "#{label}: disposition is invalid" unless OWNER_PERMITTED_DISPOSITIONS.include?(decision['disposition'])
    errors << "#{label}: target_reference must be substantive" unless nonempty_string?(decision['target_reference'])
    errors << "#{label}: exclusions and conditions must be closed string arrays" unless owner_substantive_string_array?(decision['exclusions']) && owner_substantive_string_array?(decision['conditions'])
    errors << "#{label}: evidence_roots and control_roots must be non-empty unique SHA-256 arrays" unless owner_sha_array?(decision['evidence_roots']) && owner_sha_array?(decision['control_roots'])
    errors << "#{label}: evidence_roots must exactly bind the historical source-row evidence/control state" unless decision['evidence_roots'] == owner_expected_evidence_roots(requirement_policy, decision['batch_register_sha256'])
    errors << "#{label}: control_roots must exactly bind the requirement, separation, disposition and session policy" unless decision['control_roots'] == owner_expected_control_roots(requirement_policy, policy)
    errors << "#{label}: prior_decision_sha256 must preserve one linear history" unless decision['prior_decision_sha256'] == prior_by_requirement[decision['requirement_id']]

    if decision['disposition'] == 'consolidate'
      members = Array(decision['consolidation_member_ids'])
      known_ids = Array(policy['requirement_policies']).map { |item| item['requirement_id'] }
      errors << "#{label}: consolidation requires unique known members including the source and a different known terminal target" unless members.uniq == members && (members - known_ids).empty? && members.include?(decision['requirement_id']) && nonempty_string?(decision['target_requirement_id']) && decision['target_requirement_id'] != decision['requirement_id'] && known_ids.include?(decision['target_requirement_id'])
    else
      errors << "#{label}: non-consolidation must not declare consolidation members" unless decision['consolidation_member_ids'] == []
      errors << "#{label}: reproduce must target its own requirement; other dispositions require an explicit known or null terminal according to target_reference" if decision['disposition'] == 'reproduce' && decision['target_requirement_id'] != decision['requirement_id']
    end
    if decision['decision_status'] == 'defer'
      errors << "#{label}: defer requires exact unresolved-gate owners, exclusions and conditions" unless !Array(decision['unresolved_gate_authority_domains']).empty? && Array(decision['unresolved_gate_authority_domains']).all? { |role| OWNER_ROLE_CAPACITY_MAP.key?(role) } && !Array(decision['exclusions']).empty? && !Array(decision['conditions']).empty?
    end
    expected_seats = owner_required_seats_for_decision(requirement_policy, decision, policy, latest_by_requirement)
    validate_owner_votes(decision, session, appointments, events, expected_seats, requirement_policy, label)
    core_payload = decision.reject { |key, _value| %w[decision_sha256 votes].include?(key) }
    decision_sha = Digest::SHA256.hexdigest(owner_canonical_json(core_payload))
    errors << "#{label}: decision_sha256 must be recomputable from the exact row, disposition, targets, evidence and appointments" unless decision['decision_sha256'] == decision_sha
    Array(decision['votes']).each_with_index { |vote, vote_index| validate_owner_vote_digest(vote, vote_index, decision, session, decision_sha, label) }
  end

  def validate_owner_votes(decision, session, appointments, events, expected_seats, requirement_policy, label)
    votes = decision['votes']
    errors << "#{label}: votes must be a non-empty array" unless votes.is_a?(Array) && !votes.empty?
    return unless votes.is_a?(Array)

    by_id = appointments.to_h { |appointment| [appointment['appointment_id'], appointment] }
    snapshot_appointment_ids = appointments.first(session['appointment_snapshot_count'].to_i).map { |appointment| appointment['appointment_id'] }
    vote_ids = votes.map { |vote| vote['vote_id'] if vote.is_a?(Hash) }.compact
    errors << "#{label}: duplicate vote IDs are forbidden" unless vote_ids.uniq == vote_ids
    consent_votes = votes.select { |vote| vote.is_a?(Hash) && vote['vote'] == 'consent' }
    consent_seats = consent_votes.map { |vote| { 'requirement_id' => vote['seat_requirement_id'], 'role' => vote['authority_role'] } }
    seat_sort = lambda { |seat| [seat['requirement_id'].to_s, seat['role'].to_s] }
    errors << "#{label}: unanimous consent requires every exact PAR/role seat; recusal leaves its seat vacant" unless consent_seats.sort_by(&seat_sort) == expected_seats.sort_by(&seat_sort) && consent_seats.uniq == consent_seats
    errors << "#{label}: votes may only be consent or recuse" unless votes.all? { |vote| vote.is_a?(Hash) && OWNER_DECISION_ACTIONS.include?(vote['vote']) }
    votes.each_with_index do |vote, vote_index|
      next unless vote.is_a?(Hash)

      validate_closed_object(vote, OWNER_VOTE_KEYS, "#{label} votes[#{vote_index}]")
      appointment = by_id[vote['appointment_id']]
      signed_at = owner_time(vote['signed_at'])
      errors << "#{label} votes[#{vote_index}]: appointment must be inside the signed historical appointment prefix" unless snapshot_appointment_ids.include?(vote['appointment_id'])
      errors << "#{label} votes[#{vote_index}]: appointment must exist, cover the exact PAR/role seat and be historically active" unless appointment && signed_at && owner_appointment_covers?(appointment, vote['seat_requirement_id'], vote['authority_role'], signed_at, events, appointments)
      session_started_at = owner_time(session['started_at'])
      errors << "#{label} votes[#{vote_index}]: signing appointment must already be active when the session starts" unless appointment && session_started_at && owner_appointment_covers?(appointment, vote['seat_requirement_id'], vote['authority_role'], session_started_at, events, appointments)
      if appointment
        snapshot_bound = appointment['policy_sha256'] == decision['policy_sha256'] && appointment['source_register_sha256s'] == session['source_register_sha256s']
        errors << "#{label} votes[#{vote_index}]: identity/domain/role/appointment SHA and historical policy/source snapshots must match the appointed seat" unless snapshot_bound && vote['subject_institutional_id'] == appointment.dig('subject', 'institutional_id') && vote['authority_domain'] == appointment['authority_domain'] && vote['authority_role'] == appointment['authority_role'] && vote['appointment_sha256'] == Digest::SHA256.hexdigest(owner_canonical_json(appointment))
      end
      started = owner_time(session['started_at'])
      ended = owner_time(session['ended_at'])
      errors << "#{label} votes[#{vote_index}]: signature timestamp must fall inside the session" unless signed_at && started && ended && signed_at >= started && signed_at <= ended
      errors << "#{label} votes[#{vote_index}]: consent has null recusal_reason; recusal needs a reason" unless (vote['vote'] == 'consent' && vote['recusal_reason'].nil?) || (vote['vote'] == 'recuse' && nonempty_string?(vote['recusal_reason']))
      rights = appointment && appointment['decision_rights'] || {}
      rights_ok = Array(rights['session_actions']).include?(vote['vote']) && Array(rights['permitted_decision_statuses']).include?(decision['decision_status']) && Array(rights['permitted_dispositions']).include?(decision['disposition'])
      errors << "#{label} votes[#{vote_index}]: appointment rights do not authorize this action/status/disposition" unless rights_ok
      errors << "#{label} votes[#{vote_index}]: human quorum/signing seats require UEU-PERSON identities" unless owner_person_id?(vote['subject_institutional_id'])
    end
    unique_people = consent_votes.map { |vote| vote['subject_institutional_id'] }.uniq.length
    minimum = Array(requirement_policy['applicable_independent_controls']).empty? ? 2 : 3
    errors << "#{label}: quorum requires #{minimum} unique people; compatible dual hats count once" unless unique_people >= minimum
    OWNER_INCOMPATIBLE_ROLE_PAIRS.each do |pair|
      identities = pair.map { |role| consent_votes.find { |vote| vote['authority_role'] == role }&.fetch('subject_institutional_id', nil) }
      errors << "#{label}: separated roles #{pair.join(' + ')} cannot be signed by one identity" if identities.compact.length == 2 && identities.uniq.length == 1
    end
    appointment_digests = Array(decision['appointment_digests'])
    appointment_digests.each_with_index { |item, digest_index| validate_closed_object(item, OWNER_APPOINTMENT_DIGEST_KEYS, "#{label} appointment_digests[#{digest_index}]") }
    expected_digests = consent_votes.map { |vote| { 'appointment_id' => vote['appointment_id'], 'appointment_sha256' => vote['appointment_sha256'] } }.uniq.sort_by { |item| item['appointment_id'] }
    errors << "#{label}: appointment_digests must exactly bind every consenting appointment" unless appointment_digests == expected_digests
  end

  def validate_owner_vote_digest(vote, index, decision, session, decision_sha, label)
    return unless vote.is_a?(Hash)

    errors << "#{label} votes[#{index}]: vote must bind session, policy, batch register, decision status/disposition, row, decision, evidence and controls" unless vote['session_id'] == session['session_id'] && vote['policy_sha256'] == decision['policy_sha256'] && vote['batch_register_sha256'] == decision['batch_register_sha256'] && vote['decision_status'] == decision['decision_status'] && vote['disposition'] == decision['disposition'] && vote['row_sha256'] == decision['row_sha256'] && vote['decision_sha256'] == decision_sha && vote['evidence_roots'] == decision['evidence_roots'] && vote['control_roots'] == decision['control_roots']
    payload = vote.reject { |key, _value| %w[canonical_signed_payload_sha256 signature].include?(key) }
    payload_bytes = owner_canonical_json(payload)
    payload_sha = Digest::SHA256.hexdigest(payload_bytes)
    errors << "#{label} votes[#{index}]: canonical signed vote payload must be recomputable" unless vote['canonical_signed_payload_sha256'] == payload_sha
    owner_verify_detached_signature(vote, vote['subject_institutional_id'], payload_bytes, 'decision_vote', vote['signed_at'], "#{label} votes[#{index}]")
  end

  def validate_owner_session_receipt(receipt, session, appointments, session_payload, label)
    validate_closed_object(receipt, OWNER_REGISTRY_RECEIPT_KEYS, "#{label} registry_receipt")
    return unless receipt.is_a?(Hash)

    participant_ids = Array(session['decisions']).flat_map { |decision| Array(decision['votes']).map { |vote| vote['subject_institutional_id'] if vote.is_a?(Hash) } }.compact
    by_id = appointments.to_h { |appointment| [appointment['appointment_id'], appointment] }
    participant_ids.concat([session['chair_appointment_id'], session['facilitator_appointment_id']].map { |appointment_id| by_id.dig(appointment_id, 'subject', 'institutional_id') }.compact)
    separated = participant_ids + [receipt['evidence_author_institutional_id'], receipt['implementer_institutional_id']]
    errors << "#{label} registry_receipt: independent human reviewer must differ from every subject, evidence author, implementer and decision signer" unless owner_person_id?(receipt['reviewer_institutional_id']) && separated.none? { |identity| identity == receipt['reviewer_institutional_id'] }
    errors << "#{label} registry_receipt: evidence author and implementer require distinct human institutional identities" unless owner_person_id?(receipt['evidence_author_institutional_id']) && owner_person_id?(receipt['implementer_institutional_id']) && receipt['evidence_author_institutional_id'] != receipt['implementer_institutional_id']
    verified_at = owner_time(receipt['verified_at'])
    ended_at = owner_time(session['ended_at'])
    vote_times = Array(session['decisions']).flat_map { |decision| Array(decision['votes']).map { |vote| owner_time(vote['signed_at']) if vote.is_a?(Hash) } }.compact
    errors << "#{label} registry_receipt: reviewer receipt must be a post-session time anchor" unless %w[institutional_registry detached_signature].include?(receipt['verification_method']) && nonempty_string?(receipt['verification_reference']) && verified_at && ended_at && verified_at >= ended_at && vote_times.all? { |timestamp| verified_at >= timestamp }
    payload_bytes = owner_canonical_json(session_payload)
    validate_owner_reviewed_receipt_signature(receipt, payload_bytes, 'session_review', "#{label} registry_receipt")
  end

  def owner_latest_decisions(sessions)
    sessions.each_with_object({}) do |session, latest|
      Array(session['decisions']).each { |decision| latest[decision['requirement_id']] = decision if decision.is_a?(Hash) }
    end
  end

  def owner_session_decision_matches_source?(decision, entry)
    return false unless decision.is_a?(Hash) && entry.is_a?(Hash)

    requirement_policy = Array(@owner_policy && @owner_policy['requirement_policies']).find { |item| item['requirement_id'] == decision['requirement_id'] }
    return false unless requirement_policy

    current_source = Array(@owner_policy['source_decision_registers']).find { |descriptor| descriptor['batch'] == requirement_policy['batch'] }
    return false unless current_source && decision['policy_sha256'] == @owner_policy['control_root_sha256'] && decision['batch_register_sha256'] == current_source['sha256']

    normalized = owner_normalized_source_decision(requirement_policy, current_source['sha256'])
    decision['source_decision_sha256'] == Digest::SHA256.hexdigest(owner_canonical_json(normalized)) &&
      decision['decision_status'] == normalized['status'] && decision['disposition'] == normalized['disposition'] &&
      decision['target_reference'] == normalized['target_reference'] && decision['target_requirement_id'] == normalized['target_requirement_id'] &&
      decision['exclusions'] == normalized['exclusions'] && decision['conditions'] == normalized['conditions'] &&
      decision['consolidation_member_ids'] == normalized['consolidation_member_ids'] && decision['unresolved_gate_authority_domains'] == normalized['unresolved_gate_authority_domains']
  end

  def validate_owner_consolidation_terminals(latest)
    latest.each_value do |decision|
      next unless decision['disposition'] == 'consolidate'

      terminal = latest[decision['target_requirement_id']]
      errors << "decision session register #{decision['requirement_id']}: consolidation terminal must have an approved reproduce/replace decision" unless terminal && terminal['decision_status'] == 'approve' && %w[reproduce replace].include?(terminal['disposition'])
    end
  end

  def validate_owner_session_consolidation_cycles(latest)
    edges = latest.each_with_object({}) do |(requirement_id, decision), graph|
      graph[requirement_id] = decision['target_requirement_id'] if decision['disposition'] == 'consolidate' && nonempty_string?(decision['target_requirement_id'])
    end
    edges.keys.each do |start|
      path = []
      current = start
      while current && edges.key?(current)
        if path.include?(current)
          cycle = [*path[path.index(current)..], current]
          errors << "decision session register: consolidation cycle detected #{cycle.join(' -> ')}"
          return
        end
        path << current
        current = edges[current]
      end
    end
  end

  def owner_sha_array?(value)
    value.is_a?(Array) && !value.empty? && value.uniq == value && value.all? { |item| item.is_a?(String) && item.match?(/\A[0-9a-f]{64}\z/) }
  end

  def owner_substantive_string_array?(value)
    value.is_a?(Array) && value.uniq == value && value.all? { |item| nonempty_string?(item) }
  end

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

  def decision_register_config(batch)
    DECISION_REGISTER_CONFIGS.fetch(batch)
  end

  def decision_register_label
    "Batch #{@active_decision_context[:batch]} decision register"
  end

  def load_decision_register(batch)
    path = @decision_register_paths.fetch(batch)
    unless File.file?(path)
      errors << "Batch #{batch} decision register: file not found: #{path}"
      return {}
    end

    JSON.parse(File.read(path))
  rescue JSON::ParserError => e
    errors << "Batch #{batch} decision register: invalid JSON: #{e.message}"
    {}
  rescue SystemCallError => e
    errors << "Batch #{batch} decision register: cannot read file: #{e.message}"
    {}
  end

  def validate_decision_register(baseline, manifest, register, batch)
    config = decision_register_config(batch)
    @active_decision_context = {
      batch: batch,
      register_id: register['register_id'],
      register_path: @decision_register_paths.fetch(batch),
      evidence_directory: config[:evidence_directory]
    }
    prefix = decision_register_label
    @decision_register_statuses[batch] = register['register_status']

    unless register['schema_version'] == BATCH_A_REGISTER_SCHEMA_VERSION
      errors << "#{prefix}: schema_version must be #{BATCH_A_REGISTER_SCHEMA_VERSION}"
    end
    unless register['register_id'] == config[:register_id]
      errors << "#{prefix}: register_id must be #{config[:register_id]}"
    end
    unless register['batch'] == batch
      errors << "#{prefix}: batch must be #{batch}"
    end
    unless register['register_status'].is_a?(String) && REGISTER_STATUSES.include?(register['register_status'])
      errors << "#{prefix}: invalid register_status #{register['register_status'].inspect}"
    end
    unless register['data_boundary'] == 'synthetic_only'
      errors << "#{prefix}: data_boundary must be synthetic_only"
    end
    unless register['external_integrations'] == 'disabled'
      errors << "#{prefix}: external_integrations must be disabled"
    end
    unless register['source_manifest'] == 'docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json'
      errors << "#{prefix}: source_manifest must reference the canonical G0 batch manifest"
    end
    unless register['evidence_directory'] == config[:evidence_directory]
      errors << "#{prefix}: evidence_directory must be #{config[:evidence_directory]}"
    end
    if batch == 'E'
      validate_closed_object(register, BATCH_E_REGISTER_KEYS, prefix)
      errors << "#{prefix}: source_revision must be #{BATCH_E_SOURCE_REVISION}" unless register['source_revision'] == BATCH_E_SOURCE_REVISION
      actual_manifest_sha = Digest::SHA256.file(@batch_manifest_path).hexdigest if File.file?(@batch_manifest_path)
      errors << "#{prefix}: source_manifest_sha256 must match the loaded manifest" unless actual_manifest_sha && register['source_manifest_sha256'] == actual_manifest_sha
      validate_batch_e_family_policies(register['family_policies'])
    elsif batch == 'F'
      validate_closed_object(register, BATCH_F_REGISTER_KEYS, prefix)
      errors << "#{prefix}: source_revision must be #{BATCH_F_SOURCE_REVISION}" unless register['source_revision'] == BATCH_F_SOURCE_REVISION
      actual_manifest_sha = Digest::SHA256.file(@batch_manifest_path).hexdigest if File.file?(@batch_manifest_path)
      errors << "#{prefix}: source_manifest_sha256 must match the loaded manifest" unless actual_manifest_sha && register['source_manifest_sha256'] == actual_manifest_sha
      errors << "#{prefix}: availability_state must be Soon" unless register['availability_state'] == 'Soon'
      errors << "#{prefix}: ledger_ownership must exactly freeze one writer per ledger and a read-only reporting projection" unless register['ledger_ownership'] == BATCH_F_LEDGER_OWNERSHIP
      validate_batch_f_family_policies(register['family_policies'])
      validate_batch_f_frozen_dependency_policy
    elsif batch == 'G'
      validate_closed_object(register, BATCH_G_REGISTER_KEYS, prefix)
      errors << "#{prefix}: source_revision must be #{BATCH_G_SOURCE_REVISION}" unless register['source_revision'] == BATCH_G_SOURCE_REVISION
      actual_manifest_sha = Digest::SHA256.file(@batch_manifest_path).hexdigest if File.file?(@batch_manifest_path)
      errors << "#{prefix}: source_manifest_sha256 must match the loaded manifest" unless actual_manifest_sha && register['source_manifest_sha256'] == actual_manifest_sha
      errors << "#{prefix}: availability_state must be Soon" unless register['availability_state'] == 'Soon'
      validate_batch_g_family_policies(register['family_policies'])
      errors << "#{prefix}: consolidation_candidates must exactly match the frozen candidate graph" unless register['consolidation_candidates'] == BATCH_G_CONSOLIDATION_GROUPS
      validate_batch_g_frozen_graph
    end

    entries = register['entries']
    unless entries.is_a?(Array)
      errors << "#{prefix}: entries must be an array"
      errors << "#{prefix}: expected exactly #{config[:expected_count]} entries, got 0"
      return []
    end
    valid_entries = entries.select { |entry| entry.is_a?(Hash) }
    @decision_entries.concat(valid_entries)

    batches = manifest['batches']
    expected_ids = batches.is_a?(Hash) && batches[batch].is_a?(Array) ? batches[batch] : []
    actual_ids = entries.each_with_object([]) do |entry, ids|
      ids << entry['requirement_id'] if entry.is_a?(Hash)
    end
    invalid_entries = entries.each_index.reject { |index| entries[index].is_a?(Hash) }
    invalid_entries.each { |index| errors << "#{prefix} entry #{index}: must be an object" }

    invalid_ids = actual_ids.reject { |id| id.is_a?(String) && id.match?(PAR_ID_PATTERN) }.uniq
    errors << "#{prefix}: invalid requirement IDs: #{invalid_ids.map(&:inspect).join(', ')}" unless invalid_ids.empty?

    string_ids = actual_ids.select { |id| id.is_a?(String) }
    duplicates = string_ids.group_by { |id| id }.select { |_id, values| values.length > 1 }.keys.sort
    errors << "#{prefix}: duplicate requirement IDs: #{duplicates.join(', ')}" unless duplicates.empty?

    missing = expected_ids - actual_ids
    unknown = string_ids - expected_ids
    errors << "#{prefix}: missing Batch #{batch} requirement IDs: #{missing.sort.join(', ')}" unless missing.empty?
    errors << "#{prefix}: unknown Batch #{batch} requirement IDs: #{unknown.sort.join(', ')}" unless unknown.empty?
    if entries.length != config[:expected_count]
      errors << "#{prefix}: expected exactly #{config[:expected_count]} entries, got #{entries.length}"
    end
    if entries.length == expected_ids.length && actual_ids != expected_ids
      errors << "#{prefix}: entries must follow the canonical Batch #{batch} manifest order"
    end

    entries.each_with_index do |entry, index|
      next unless entry.is_a?(Hash)

      validate_decision_entry(entry, index, baseline['expected'])
    end

    return valid_entries unless register['register_status'] == 'complete'

    unresolved = entries.select do |entry|
      decision = entry['decision'] if entry.is_a?(Hash)
      !decision.is_a?(Hash) || !%w[approve defer].include?(decision['status'])
    end
    errors << "#{prefix}: register_status complete requires every entry to be approved or deferred" unless unresolved.empty?

    # Integrity mode must not accept a self-declared complete register whose
    # decisions lack the evidence, appointments, approvals, or ready scenarios
    # that G0 itself requires. G0 mode already performs this check per entry.
    unless @mode == 'g0'
      entries.each_with_index do |entry, index|
        next unless entry.is_a?(Hash)

        id = entry['requirement_id']
        label = id.is_a?(String) && !id.empty? ? id : "entry #{index}"
        validate_decision_g0_resolution(entry, label)
      end
    end
    valid_entries
  end

  def validate_decision_entry(entry, index, known_requirements)
    id = entry['requirement_id']
    label = id.is_a?(String) && !id.empty? ? id : "entry #{index}"

    errors << "#{decision_register_label} #{label}: batch must be #{@active_decision_context[:batch]}" unless entry['batch'] == @active_decision_context[:batch]
    errors << "#{decision_register_label} #{label}: legacy_menu must be a non-empty string" unless nonempty_string?(entry['legacy_menu'])

    evidence = entry['evidence']
    if !evidence.is_a?(Array) || evidence.empty?
      errors << "#{decision_register_label} #{label}: evidence must be a non-empty array"
    else
      evidence.each_with_index { |record, evidence_index| validate_decision_evidence(record, label, evidence_index) }
    end

    decision = entry['decision']
    validate_register_decision(decision, label, known_requirements)

    validate_nonempty_string_array(entry['affected_domains'], "#{decision_register_label} #{label}: affected_domains")
    validate_nonempty_string_array(entry['co_owners'], "#{decision_register_label} #{label}: co_owners")
    validate_nonempty_string_array(entry['downstream_impacts'], "#{decision_register_label} #{label}: downstream_impacts")
    validate_strict_batch_authorities(entry, label) if AUTHORITY_BOUND_BATCHES.include?(@active_decision_context[:batch])
    validate_batch_d_controls(entry, label) if @active_decision_context[:batch] == 'D'
    validate_batch_e_controls(entry, label) if @active_decision_context[:batch] == 'E'
    validate_batch_f_controls(entry, label) if @active_decision_context[:batch] == 'F'
    validate_batch_g_controls(entry, label) if @active_decision_context[:batch] == 'G'

    scenarios = entry['synthetic_scenarios']
    if !scenarios.is_a?(Hash)
      errors << "#{decision_register_label} #{label}: synthetic_scenarios must be an object"
    else
      scenario_names = FOUR_SCENARIO_BATCHES.include?(@active_decision_context[:batch]) ? FOUR_SCENARIO_NAMES : %w[normal denial_or_correction]
      if FOUR_SCENARIO_BATCHES.include?(@active_decision_context[:batch]) && scenarios.keys.sort != scenario_names.sort
        errors << "#{decision_register_label} #{label}: synthetic_scenarios must contain exactly #{scenario_names.join(', ')}"
      end
      scenario_names.each { |name| validate_decision_scenario(scenarios[name], label, name) }
    end

    validate_accountable_owner(entry['accountable_owner'], label)
    validate_appointment_dependencies(entry['appointment_dependencies'], entry['co_owners'], label)
    validate_decision_approval(
      entry['approval'],
      label,
      decision,
      accountable_owner: entry['accountable_owner'],
      lead_authority_domain: entry['lead_authority_domain']
    )

    validate_decision_g0_resolution(entry, label) if @mode == 'g0'
  end

  def validate_strict_batch_authorities(entry, label)
    if @active_decision_context[:batch] == 'E'
      family = batch_e_family_for(label)
      authority_policy = BATCH_E_FAMILY_AUTHORITIES[family]
      expected_authorities = authority_policy && authority_policy[:co_owners]
      expected_lead = authority_policy && authority_policy[:lead]
    elsif @active_decision_context[:batch] == 'F'
      family = batch_f_family_for(label)
      authority_policy = BATCH_F_FAMILY_AUTHORITIES[family]
      expected_authorities = authority_policy && authority_policy[:co_owners]
      expected_lead = authority_policy && authority_policy[:lead]
    elsif @active_decision_context[:batch] == 'G'
      expected_authorities = batch_g_required_authorities(label)
      family = batch_g_family_for(label)
      expected_lead = family && BATCH_G_FAMILY_POLICY.dig(family, :lead)
    else
      required_authorities = @active_decision_context[:batch] == 'C' ? BATCH_C_REQUIRED_AUTHORITIES : BATCH_D_REQUIRED_AUTHORITIES
      lead_authorities = @active_decision_context[:batch] == 'C' ? BATCH_C_LEAD_AUTHORITIES : BATCH_D_LEAD_AUTHORITIES
      expected_authorities = required_authorities[label]
      expected_lead = lead_authorities[label]
    end
    unless expected_authorities && expected_lead
      errors << "#{decision_register_label} #{label}: required authority policy is missing"
      return
    end
    unless expected_authorities.include?(expected_lead) && expected_lead != 'product_delivery'
      errors << "#{decision_register_label} #{label}: required authority policy must bind a non-product lead included in co_owners"
    end

    unless entry['co_owners'] == expected_authorities
      errors << "#{decision_register_label} #{label}: co_owners must exactly match required authorities #{expected_authorities.inspect}"
    end
    unless entry['lead_authority_domain'] == expected_lead
      errors << "#{decision_register_label} #{label}: lead_authority_domain must be #{expected_lead}"
    end
    owner = entry['accountable_owner']
    unless owner.is_a?(Hash) && owner['authority_domain'] == expected_lead
      errors << "#{decision_register_label} #{label}: accountable owner authority_domain must match lead authority #{expected_lead}"
    end
  end

  def batch_e_family_for(requirement_id)
    BATCH_E_FAMILY_MEMBERS.find { |_family, members| members.include?(requirement_id) }&.first
  end

  def batch_e_row_hazards(requirement_id)
    family = batch_e_family_for(requirement_id)
    return [] unless family

    [*BATCH_E_FAMILY_HAZARDS.fetch(family), *BATCH_E_ROW_HAZARDS.fetch(requirement_id)]
  end

  def batch_e_expected_family_policy(family)
    authority = BATCH_E_FAMILY_AUTHORITIES.fetch(family)
    {
      'family_id' => family,
      'members' => BATCH_E_FAMILY_MEMBERS.fetch(family),
      'lead_authority_domain' => authority.fetch(:lead),
      'co_owners' => authority.fetch(:co_owners),
      'inherited_hazards' => BATCH_E_FAMILY_HAZARDS.fetch(family),
      'movement_contract' => BATCH_E_FAMILY_MOVEMENT_CONTRACTS.fetch(family),
      'reconciliation_control_totals' => BATCH_E_FAMILY_CONTROL_TOTALS.fetch(family)
    }
  end

  def validate_batch_e_family_policies(policies)
    prefix = 'Batch E decision register: family_policies'
    unless policies.is_a?(Array)
      errors << "#{prefix} must be an array"
      return
    end
    expected_families = BATCH_E_FAMILY_MEMBERS.keys
    actual_families = policies.each_with_object([]) { |policy, values| values << policy['family_id'] if policy.is_a?(Hash) }
    errors << "#{prefix} must contain exactly #{expected_families.join(', ')} in frozen order" unless actual_families == expected_families
    policies.each_with_index do |policy, index|
      unless policy.is_a?(Hash)
        errors << "#{prefix}[#{index}] must be an object"
        next
      end
      family = policy['family_id']
      expected = BATCH_E_FAMILY_MEMBERS.key?(family) ? batch_e_expected_family_policy(family) : nil
      validate_closed_object(policy, BATCH_E_FAMILY_POLICY_KEYS, "#{prefix}[#{index}]")
      errors << "#{prefix}[#{index}] is not a frozen Batch E family" unless expected
      errors << "#{prefix}[#{index}] must exactly match the frozen family policy" if expected && policy != expected
    end
    members = policies.flat_map { |policy| policy.is_a?(Hash) && policy['members'].is_a?(Array) ? policy['members'] : [] }
    errors << "#{prefix} must partition the exact 48 Batch E IDs without duplicates" unless members == BATCH_E_FAMILY_MEMBERS.values.flatten && members.uniq.length == EXPECTED_BATCH_E_IDS.length
  end

  def validate_batch_e_controls(entry, label)
    validate_closed_object(entry, BATCH_E_ENTRY_KEYS, "#{decision_register_label} #{label}")
    family = batch_e_family_for(label)
    unless family
      errors << "#{decision_register_label} #{label}: Batch E family policy is missing"
      return
    end
    errors << "#{decision_register_label} #{label}: family_id must be #{family}" unless entry['family_id'] == family
    errors << "#{decision_register_label} #{label}: availability_state must remain Soon" unless entry['availability_state'] == 'Soon'
    Array(entry['evidence']).each_with_index { |record, index| validate_closed_object(record, BATCH_E_EVIDENCE_RECORD_KEYS, "#{decision_register_label} #{label}: evidence[#{index}]") if record.is_a?(Hash) }
    validate_closed_object(entry['decision'], BATCH_E_DECISION_KEYS, "#{decision_register_label} #{label}: decision") if entry['decision'].is_a?(Hash)
    validate_closed_object(entry.dig('decision', 'target'), BATCH_E_TARGET_KEYS, "#{decision_register_label} #{label}: decision target") if entry.dig('decision', 'target').is_a?(Hash)
    validate_closed_object(entry['accountable_owner'], BATCH_E_OWNER_KEYS, "#{decision_register_label} #{label}: accountable_owner") if entry['accountable_owner'].is_a?(Hash)
    Array(entry['appointment_dependencies']).each_with_index { |record, index| validate_closed_object(record, BATCH_E_APPOINTMENT_KEYS, "#{decision_register_label} #{label}: appointment_dependencies[#{index}]") if record.is_a?(Hash) }
    validate_batch_e_gate_authority_appointments(entry['gate_authority_appointments'], entry['co_owners'], label)
    validate_closed_object(entry['approval'], BATCH_E_APPROVAL_KEYS, "#{decision_register_label} #{label}: approval") if entry['approval'].is_a?(Hash)
    errors << "#{decision_register_label} #{label}: affected_domains must exactly match the frozen co-owner authorities" unless entry['affected_domains'] == BATCH_E_FAMILY_AUTHORITIES.fetch(family).fetch(:co_owners)
    expected_hazards = batch_e_row_hazards(label)
    errors << "#{decision_register_label} #{label}: row_hazards must exactly inherit family hazards plus the row traceability hazard" unless entry['row_hazards'] == expected_hazards
    errors << "#{decision_register_label} #{label}: ledger_invariants must exactly match the immutable Batch E ledger policy" unless entry['ledger_invariants'] == BATCH_E_LEDGER_INVARIANTS
    errors << "#{decision_register_label} #{label}: movement_contract must exactly match family #{family}" unless entry['movement_contract'] == BATCH_E_FAMILY_MOVEMENT_CONTRACTS.fetch(family)
    validate_batch_e_dependency_gates(entry['dependency_gates'], entry, label)
    validate_batch_e_integration_boundary(entry['integration_boundary'], label)
    validate_batch_e_reconciliation(entry['reconciliation_contract'], family, label)
    validate_batch_e_consolidation(entry['consolidation_mapping'], entry['decision'], label)
    validate_batch_e_append_only_correction(entry, label) if label == 'PAR-PWH-014'
  end

  def validate_batch_e_gate_authority_appointments(appointments, co_owners, label)
    expected_domains = %w[finance_claims reporting].reject { |domain| Array(co_owners).include?(domain) }
    unless appointments.is_a?(Array)
      errors << "#{decision_register_label} #{label}: gate_authority_appointments must be an array"
      return
    end
    domains = appointments.select { |record| record.is_a?(Hash) }.map { |record| record['authority_domain'] }
    errors << "#{decision_register_label} #{label}: gate_authority_appointments must exactly cover external F/G gate authorities #{expected_domains.inspect}" unless domains == expected_domains
    appointments.each_with_index do |record, index|
      prefix = "#{decision_register_label} #{label}: gate_authority_appointments[#{index}]"
      unless record.is_a?(Hash)
        errors << "#{prefix} must be an object"
        next
      end
      validate_closed_object(record, BATCH_E_APPOINTMENT_KEYS, prefix)
      errors << "#{prefix} required_scope must bind forward-gate deferral authority" unless nonempty_string?(record['required_scope'])
      status = record['status']
      errors << "#{prefix} invalid status #{status.inspect}" unless APPOINTMENT_STATUSES.include?(status)
      if status == 'pending'
        %w[identity date reference artifact_sha256].each { |key| errors << "#{prefix} pending #{key} must be null" unless record[key].nil? }
      elsif status == 'appointed'
        errors << "#{prefix} identity must be non-placeholder" unless nonempty_string?(record['identity']) && !record['identity'].match?(PLACEHOLDER_OWNER_PATTERN)
        errors << "#{prefix} date must be YYYY-MM-DD" unless iso_date?(record['date'])
        validate_governance_artifact(reference: record['reference'], expected_sha256: record['artifact_sha256'], label: "#{prefix} appointment", requirement_id: label, subject: 'appointment_dependency', record: record)
      end
    end
  end

  def batch_e_expected_gates(label)
    batches = ['C']
    batches << 'D' if BATCH_E_D_INTERFACE_IDS.include?(label)
    batches.concat(%w[F G])
    batches.map do |batch|
      {
        'direction' => %w[C D].include?(batch) ? 'upstream' : 'forward',
        'batch' => batch,
        'scope' => BATCH_E_GATE_SCOPES.fetch(batch)
      }
    end
  end

  def validate_batch_e_dependency_gates(gates, entry, label)
    unless gates.is_a?(Array)
      errors << "#{decision_register_label} #{label}: dependency_gates must be an array"
      return
    end
    expected = batch_e_expected_gates(label)
    actual = []
    gates.each_with_index do |gate, index|
      prefix = "#{decision_register_label} #{label}: dependency_gates[#{index}]"
      unless gate.is_a?(Hash)
        errors << "#{prefix} must be an object"
        next
      end
      validate_closed_object(gate, BATCH_E_GATE_KEYS, prefix)
      actual << gate.slice('direction', 'batch', 'scope')
      status = gate['status']
      errors << "#{prefix} invalid status #{status.inspect}" unless BATCH_E_GATE_STATUSES.include?(status)
      if status == 'pending'
        %w[resolution defer_authority_domain resolution_reference resolution_artifact_sha256].each do |key|
          errors << "#{prefix} pending #{key} must be null" unless gate[key].nil?
        end
      elsif status == 'resolved'
        errors << "#{prefix} resolved gate requires a non-empty resolution" unless nonempty_string?(gate['resolution'])
        errors << "#{prefix} resolved defer_authority_domain must be null" unless gate['defer_authority_domain'].nil?
        validate_batch_e_gate_artifact(gate, entry, label, prefix)
      elsif status == 'deferred'
        expected_authority = BATCH_E_GATE_AUTHORITIES[gate['batch']]
        errors << "#{prefix} only forward Batch F/G gates may be authority-deferred" unless gate['direction'] == 'forward' && %w[F G].include?(gate['batch'])
        errors << "#{prefix} defer_authority_domain must be #{expected_authority}" unless gate['defer_authority_domain'] == expected_authority
        errors << "#{prefix} deferred gate requires a non-empty resolution" unless nonempty_string?(gate['resolution'])
        exclusions = entry.dig('decision', 'target', 'exclusions')
        missing = Array(gate['scope']).reject { |term| Array(exclusions).any? { |item| item.downcase.include?(term.downcase) } }
        errors << "#{prefix} authority deferral requires explicit decision exclusions for #{missing.join(', ')}" unless missing.empty?
        validate_batch_e_gate_artifact(gate, entry, label, prefix)
      end
    end
    errors << "#{decision_register_label} #{label}: dependency_gates must exactly match the frozen C/D/F/G policy" unless actual == expected
  end

  def validate_batch_e_gate_artifact(gate, entry, requirement_id, label)
    artifact = load_structured_json_artifact(gate['resolution_reference'], gate['resolution_artifact_sha256'], "#{label} resolution")
    return unless artifact
    validate_closed_object(artifact, BATCH_E_GATE_ARTIFACT_KEYS, "#{label} resolution")
    errors << "#{label} resolution artifact_type must be #{BATCH_E_GATE_ARTIFACT_TYPE}" unless artifact['artifact_type'] == BATCH_E_GATE_ARTIFACT_TYPE
    errors << "#{label} resolution schema_version must be #{ARTIFACT_SCHEMA_VERSION}" unless artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION
    errors << "#{label} resolution register_id does not match Batch E" unless artifact['register_id'] == BATCH_E_REGISTER_ID
    errors << "#{label} resolution requirement_id does not match #{requirement_id}" unless artifact['requirement_id'] == requirement_id
    errors << "#{label} resolution subject must be dependency_gate" unless artifact['subject'] == 'dependency_gate'
    %w[direction batch scope status resolution].each { |key| errors << "#{label} resolution #{key} does not match the register" unless artifact[key] == gate[key] }
    expected_authority = BATCH_E_GATE_AUTHORITIES[gate['batch']]
    errors << "#{label} resolution authority_domain must be #{expected_authority}" unless artifact['authority_domain'] == expected_authority
    errors << "#{label} resolution identity must be non-placeholder" unless nonempty_string?(artifact['identity']) && !artifact['identity'].match?(PLACEHOLDER_OWNER_PATTERN)
    source_path = %w[C D].include?(gate['batch']) ? @decision_register_paths[gate['batch']] : @batch_manifest_path
    source_id = %w[C D].include?(gate['batch']) ? DECISION_REGISTER_CONFIGS.fetch(gate['batch'])[:register_id] : "G0_PARITY_BATCH_MANIFEST.json#batch-#{gate['batch']}"
    source_sha = Digest::SHA256.file(source_path).hexdigest if source_path && File.file?(source_path)
    errors << "#{label} resolution upstream_source_id does not match the loaded governance source" unless artifact['upstream_source_id'] == source_id
    errors << "#{label} resolution upstream_source_sha256 does not match the loaded governance source" unless source_sha && artifact['upstream_source_sha256'] == source_sha
    if %w[C D].include?(gate['batch'])
      source_entries = @decision_entries_by_batch.fetch(gate['batch'], [])
      unless gate['status'] == 'resolved' && @decision_register_statuses[gate['batch']] == 'complete' && source_entries.all? { |candidate| %w[approve defer].include?(candidate.dig('decision', 'status')) }
        errors << "#{label} cannot resolve Batch #{gate['batch']} while its loaded register is incomplete"
      end
      authority_entries = source_entries.select do |candidate|
        candidate['lead_authority_domain'] == expected_authority &&
          candidate.dig('accountable_owner', 'appointment_status') == 'appointed' &&
          candidate.dig('approval', 'status') == 'recorded' &&
          candidate.dig('decision', 'status') == 'approve' &&
          %w[reproduce replace].include?(candidate.dig('decision', 'canonical_disposition'))
      end
      authority_entry = authority_entries.find { |candidate| candidate['requirement_id'] == artifact['upstream_requirement_id'] }
      unless authority_entry
        errors << "#{label} must bind an approved, non-excluded upstream requirement led by appointed #{expected_authority} authority"
      else
        errors << "#{label} resolution identity must match the upstream accountable owner" unless artifact['identity'] == authority_entry.dig('accountable_owner', 'identity')
        errors << "#{label} upstream_approval_reference must match the upstream approval" unless artifact['upstream_approval_reference'] == authority_entry.dig('approval', 'reference')
        errors << "#{label} upstream_approval_sha256 must match the upstream approval" unless artifact['upstream_approval_sha256'] == authority_entry.dig('approval', 'artifact_sha256')
      end
    elsif gate['status'] == 'resolved'
      errors << "#{label} unresolved forward Batch #{gate['batch']} cannot be claimed resolved without its registered governance source"
    else
      authority_records = [*Array(entry['appointment_dependencies']), *Array(entry['gate_authority_appointments'])]
      authority_record = authority_records.find { |record| record.is_a?(Hash) && record['authority_domain'] == expected_authority }
      unless authority_record&.dig('status') == 'appointed'
        errors << "#{label} forward-gate deferral requires an appointed #{expected_authority} authority"
      end
      errors << "#{label} resolution identity must match the appointed forward-gate authority" unless authority_record.is_a?(Hash) && artifact['identity'] == authority_record['identity']
      errors << "#{label} upstream_requirement_id must be null for a forward batch deferral" unless artifact['upstream_requirement_id'].nil?
      errors << "#{label} upstream_approval_reference must be null for a forward batch deferral" unless artifact['upstream_approval_reference'].nil?
      errors << "#{label} upstream_approval_sha256 must be null for a forward batch deferral" unless artifact['upstream_approval_sha256'].nil?
    end
    expected_exclusions = gate['status'] == 'deferred' ? gate['scope'] : []
    errors << "#{label} resolution exclusions must exactly match the deferred scope" unless artifact['exclusions'] == expected_exclusions
    errors << "#{label} resolution date must be YYYY-MM-DD" unless iso_date?(artifact['date'])
    validate_artifact_reviewer(artifact['reviewer'], artifact['identity'], "#{label} resolution")
  end

  def validate_batch_e_integration_boundary(boundary, label)
    prefix = "#{decision_register_label} #{label}: integration_boundary"
    unless boundary.is_a?(Hash)
      errors << "#{prefix} must be an object"
      return
    end
    validate_closed_object(boundary, BATCH_E_BOUNDARY_KEYS, prefix)
    errors << "#{prefix} mode must be synthetic-only" unless BATCH_E_INTEGRATION_MODES.include?(boundary['mode'])
    errors << "#{prefix} endpoint must be null" unless boundary['endpoint'].nil?
    errors << "#{prefix} credential_state must be absent" unless boundary['credential_state'] == 'absent'
    errors << "#{prefix} outbound_network must be false" unless boundary['outbound_network'] == false
    errors << "#{prefix} delivery_state must be NOT_SENT" unless boundary['delivery_state'] == 'NOT_SENT'
    errors << "#{prefix} prohibited_targets must exactly name every forbidden live boundary" unless boundary['prohibited_targets'] == BATCH_E_PROHIBITED_TARGETS
    errors << "#{prefix} notes must explicitly keep Apotek/GF Soon and synthetic-only" unless nonempty_string?(boundary['notes']) && boundary['notes'].include?('Soon') && boundary['notes'].downcase.include?('synthetic')
  end

  def validate_batch_e_reconciliation(control, family, label)
    prefix = "#{decision_register_label} #{label}: reconciliation_contract"
    unless control.is_a?(Hash)
      errors << "#{prefix} must be an object"
      return
    end
    validate_closed_object(control, BATCH_E_RECONCILIATION_KEYS, prefix)
    errors << "#{prefix} control_totals must exactly match family #{family}" unless control['control_totals'] == BATCH_E_FAMILY_CONTROL_TOTALS.fetch(family)
    errors << "#{prefix} invalid status #{control['status'].inspect}" unless BATCH_E_RECONCILIATION_STATUSES.include?(control['status'])
    if control['status'] == 'pending'
      errors << "#{prefix} pending receipt_reference must be null" unless control['receipt_reference'].nil?
      errors << "#{prefix} pending receipt_artifact_sha256 must be null" unless control['receipt_artifact_sha256'].nil?
    elsif control['status'] == 'complete'
      validate_batch_e_reconciliation_artifact(control, family, label)
    end
  end

  def validate_batch_e_reconciliation_artifact(control, family, requirement_id)
    label = "#{decision_register_label} #{requirement_id}: reconciliation artifact"
    artifact = load_structured_json_artifact(control['receipt_reference'], control['receipt_artifact_sha256'], label)
    return unless artifact
    validate_closed_object(artifact, BATCH_E_RECONCILIATION_ARTIFACT_KEYS, label)
    errors << "#{label} artifact_type must be #{BATCH_E_RECONCILIATION_ARTIFACT_TYPE}" unless artifact['artifact_type'] == BATCH_E_RECONCILIATION_ARTIFACT_TYPE
    errors << "#{label} schema_version must be #{ARTIFACT_SCHEMA_VERSION}" unless artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION
    errors << "#{label} register_id does not match Batch E" unless artifact['register_id'] == BATCH_E_REGISTER_ID
    errors << "#{label} requirement_id does not match #{requirement_id}" unless artifact['requirement_id'] == requirement_id
    errors << "#{label} family_id must be #{family}" unless artifact['family_id'] == family
    errors << "#{label} must be synthetic_only" unless artifact['synthetic_only'] == true
    errors << "#{label} control_totals do not match the register" unless artifact['control_totals'] == control['control_totals']
    errors << "#{label} period_start must be YYYY-MM-DD" unless iso_date?(artifact['period_start'])
    errors << "#{label} period_end must be YYYY-MM-DD" unless iso_date?(artifact['period_end'])
    errors << "#{label} cutoff_at must be an ISO-8601 timestamp" unless iso_datetime?(artifact['cutoff_at'])
    if iso_date?(artifact['period_start']) && iso_date?(artifact['period_end']) && artifact['period_start'] > artifact['period_end']
      errors << "#{label} period_start must not be after period_end"
    end
    errors << "#{label} event_count must be a positive integer" unless artifact['event_count'].is_a?(Integer) && artifact['event_count'].positive?
    values = artifact['control_values']
    valid_values = values.is_a?(Hash) && values.keys == control['control_totals'] && values.values.all? { |value| value.is_a?(Integer) }
    errors << "#{label} control_values must exactly bind every family control total to integer quantities/minor units" unless valid_values
    if valid_values
      signed_keys = %w[stock_delta charge_delta source_delta destination_delta movement_total variance_quantity compensation_total]
      invalid_negative = values.select { |key, value| value.negative? && !signed_keys.include?(key) }.keys
      errors << "#{label} non-delta quantities and minor-unit values must be nonnegative: #{invalid_negative.join(', ')}" unless invalid_negative.empty?
      validate_batch_e_reconciliation_equations(family, values, artifact['equations'], label)
    end
    differences = artifact['differences']
    equations = BATCH_E_FAMILY_RECONCILIATION_EQUATIONS.fetch(family)
    errors << "#{label} differences must be a closed all-zero equation result object" unless differences.is_a?(Hash) && differences.keys == equations && differences.values.all? { |value| value == 0 }
    validate_batch_e_ledger_receipts(artifact['ledger_receipts'], artifact, requirement_id, label)
    errors << "#{label} idempotency_key must be non-empty" unless nonempty_string?(artifact['idempotency_key'])
    errors << "#{label} date must be YYYY-MM-DD" unless iso_date?(artifact['date'])
    errors << "#{label} author_identity must be non-placeholder" unless nonempty_string?(artifact['author_identity']) && !artifact['author_identity'].match?(PLACEHOLDER_OWNER_PATTERN)
    validate_artifact_reviewer(artifact['reviewer'], artifact['author_identity'], label)
  end

  def validate_batch_e_reconciliation_equations(family, values, equations, label)
    expected = BATCH_E_FAMILY_RECONCILIATION_EQUATIONS.fetch(family)
    errors << "#{label} equations must exactly match the frozen family reconciliation rules" unless equations == expected
    v = ->(key) { values.fetch(key) }
    valid = case family
            when 'E1' then v.call('active_master_count') >= 0 && v.call('active_master_count') <= v.call('version_count')
            when 'E2'
              v.call('prescribed_quantity') >= v.call('dispensed_quantity') &&
                v.call('stock_delta') == v.call('returned_quantity') - v.call('dispensed_quantity') &&
                v.call('charge_delta') == v.call('dispensed_quantity') - v.call('returned_quantity')
            when 'E3' then v.call('source_delta') + v.call('destination_delta') == 0 && v.call('returned_quantity') >= 0 && v.call('charge_delta').zero?
            when 'E4' then v.call('opening_balance') + v.call('movement_total') == v.call('closing_balance') && v.call('projection_total') == v.call('closing_balance')
            when 'E5'
              v.call('accepted_quantity') + v.call('rejected_quantity') == v.call('received_quantity') &&
                v.call('received_quantity') <= v.call('ordered_quantity') && v.call('inventory_value') == v.call('ap_value')
            when 'E6' then values.values.all? { |value| value >= 0 }
            when 'E7' then v.call('source_delta') + v.call('destination_delta') == 0 && v.call('unit_return_quantity') >= 0
            when 'E8'
              v.call('book_quantity') + v.call('variance_quantity') == v.call('counted_quantity') &&
                v.call('compensation_total') == v.call('variance_quantity') && v.call('closing_balance') == v.call('counted_quantity')
            when 'E9'
              v.call('reserved_units') <= v.call('compatible_units') &&
                v.call('simulated_issued_units') + v.call('returned_units') + v.call('quarantined_units') <= v.call('compatible_units')
            end
    errors << "#{label} control_values do not satisfy the frozen family reconciliation equations" unless valid
  end

  def validate_batch_e_ledger_receipts(receipts, reconciliation, requirement_id, label)
    unless receipts.is_a?(Array) && receipts.length == BATCH_E_LEDGER_KINDS.length
      errors << "#{label} ledger_receipts must contain exactly source, destination, finance, and reporting receipts"
      return
    end
    kinds = receipts.map { |receipt| receipt['ledger_kind'] if receipt.is_a?(Hash) }
    errors << "#{label} ledger_receipts must follow the frozen ledger-kind order" unless kinds == BATCH_E_LEDGER_KINDS
    digests = []
    receipts.each_with_index do |descriptor, index|
      prefix = "#{label} ledger_receipts[#{index}]"
      unless descriptor.is_a?(Hash)
        errors << "#{prefix} must be an object"
        next
      end
      validate_closed_object(descriptor, %w[ledger_kind reference sha256], prefix)
      receipt = load_structured_json_artifact(descriptor['reference'], descriptor['sha256'], prefix)
      next unless receipt
      validate_closed_object(receipt, BATCH_E_LEDGER_RECEIPT_ARTIFACT_KEYS, prefix)
      errors << "#{prefix} artifact_type must be #{BATCH_E_LEDGER_RECEIPT_ARTIFACT_TYPE}" unless receipt['artifact_type'] == BATCH_E_LEDGER_RECEIPT_ARTIFACT_TYPE
      errors << "#{prefix} schema_version must be #{ARTIFACT_SCHEMA_VERSION}" unless receipt['schema_version'] == ARTIFACT_SCHEMA_VERSION
      errors << "#{prefix} register_id does not match Batch E" unless receipt['register_id'] == BATCH_E_REGISTER_ID
      errors << "#{prefix} requirement_id does not match #{requirement_id}" unless receipt['requirement_id'] == requirement_id
      errors << "#{prefix} ledger_kind does not match the descriptor" unless receipt['ledger_kind'] == descriptor['ledger_kind']
      errors << "#{prefix} must be synthetic_only" unless receipt['synthetic_only'] == true
      %w[period_start period_end cutoff_at event_count control_values idempotency_key].each do |key|
        errors << "#{prefix} #{key} does not match the reconciliation artifact" unless receipt[key] == reconciliation[key]
      end
      errors << "#{prefix} ledger_digest must be SHA-256" unless receipt['ledger_digest'].is_a?(String) && receipt['ledger_digest'].match?(/\A[0-9a-f]{64}\z/i)
      digests << receipt['ledger_digest'] if receipt['ledger_digest'].is_a?(String)
      errors << "#{prefix} date must be YYYY-MM-DD" unless iso_date?(receipt['date'])
      errors << "#{prefix} author_identity must be non-placeholder" unless nonempty_string?(receipt['author_identity']) && !receipt['author_identity'].match?(PLACEHOLDER_OWNER_PATTERN)
      validate_artifact_reviewer(receipt['reviewer'], receipt['author_identity'], prefix)
    end
    errors << "#{label} ledger receipt digests must be distinct across all four ledgers/projections" unless digests.length == BATCH_E_LEDGER_KINDS.length && digests.uniq.length == digests.length
  end

  def batch_e_candidate_for(label)
    BATCH_E_CONSOLIDATION_GROUPS.find { |_candidate, members| members.include?(label) }&.first
  end

  def validate_batch_e_consolidation(control, decision, label)
    prefix = "#{decision_register_label} #{label}: consolidation_mapping"
    unless control.is_a?(Hash)
      errors << "#{prefix} must be an object"
      return
    end
    validate_closed_object(control, BATCH_E_CONSOLIDATION_KEYS, prefix)
    expected_candidate = batch_e_candidate_for(label)
    errors << "#{prefix} candidate_id must be #{expected_candidate.inspect}" unless control['candidate_id'] == expected_candidate
    errors << "#{prefix} invalid status #{control['status'].inspect}" unless BATCH_E_CONSOLIDATION_STATUSES.include?(control['status'])
    if control['status'] == 'pending' || control['status'] == 'not_applicable'
      errors << "#{prefix} terminal_target_requirement_id must be null" unless control['terminal_target_requirement_id'].nil?
      errors << "#{prefix} artifact_reference must be null" unless control['artifact_reference'].nil?
      errors << "#{prefix} artifact_sha256 must be null" unless control['artifact_sha256'].nil?
      errors << "#{prefix} non-candidates must be not_applicable" if expected_candidate.nil? && control['status'] != 'not_applicable'
      errors << "#{prefix} candidates must remain pending until mapped" if expected_candidate && control['status'] == 'not_applicable'
    elsif control['status'] == 'complete'
      errors << "#{prefix} cannot be complete for a non-candidate" unless expected_candidate
      errors << "#{prefix} terminal_target_requirement_id must be non-empty" unless nonempty_string?(control['terminal_target_requirement_id'])
      validate_batch_e_consolidation_artifact(control, label, expected_candidate) if expected_candidate
    end
    if decision.is_a?(Hash) && %w[approve defer].include?(decision['status']) && decision['canonical_disposition'] == 'consolidate'
      errors << "#{prefix} consolidation decision requires a complete mapping" unless expected_candidate && control['status'] == 'complete'
    end
  end

  def validate_batch_e_consolidation_artifact(control, requirement_id, candidate)
    label = "#{decision_register_label} #{requirement_id}: consolidation artifact"
    artifact = load_structured_json_artifact(control['artifact_reference'], control['artifact_sha256'], label)
    return unless artifact
    validate_closed_object(artifact, BATCH_E_CONSOLIDATION_ARTIFACT_KEYS, label)
    errors << "#{label} artifact_type must be #{BATCH_E_CONSOLIDATION_ARTIFACT_TYPE}" unless artifact['artifact_type'] == BATCH_E_CONSOLIDATION_ARTIFACT_TYPE
    errors << "#{label} schema_version must be #{ARTIFACT_SCHEMA_VERSION}" unless artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION
    errors << "#{label} register_id does not match Batch E" unless artifact['register_id'] == BATCH_E_REGISTER_ID
    errors << "#{label} candidate_id must be #{candidate}" unless artifact['candidate_id'] == candidate
    members = BATCH_E_CONSOLIDATION_GROUPS.fetch(candidate)
    errors << "#{label} members must exactly preserve every audited candidate ID" unless artifact['members'] == members
    impacts = artifact['member_impacts']
    valid_impacts = impacts.is_a?(Hash) && impacts.keys == members && impacts.values.all? { |values| values.is_a?(Array) && !values.empty? && values.all? { |value| nonempty_string?(value) } }
    errors << "#{label} member_impacts must retain every member with substantive impacts" unless valid_impacts
    errors << "#{label} target_requirement_id must match the shared terminal target" unless artifact['target_requirement_id'] == control['terminal_target_requirement_id']
    %w[mapped_fields mapped_states].each { |key| validate_nonempty_string_array(artifact[key], "#{label} #{key}") }
    mapping_contract = BATCH_E_CONSOLIDATION_MAPPING_CONTRACTS.fetch(candidate)
    errors << "#{label} mapped_fields must exactly match the frozen candidate contract" unless artifact['mapped_fields'] == mapping_contract.fetch(:fields)
    errors << "#{label} mapped_states must exactly match the frozen candidate contract" unless artifact['mapped_states'] == mapping_contract.fetch(:states)
    errors << "#{label} exclusions must be an array" unless artifact['exclusions'].is_a?(Array) && artifact['exclusions'].all? { |value| nonempty_string?(value) }
    errors << "#{label} date must be YYYY-MM-DD" unless iso_date?(artifact['date'])
    errors << "#{label} author_identity must be non-placeholder" unless nonempty_string?(artifact['author_identity']) && !artifact['author_identity'].match?(PLACEHOLDER_OWNER_PATTERN)
    validate_artifact_reviewer(artifact['reviewer'], artifact['author_identity'], label)
  end

  def validate_batch_e_append_only_correction(entry, label)
    hazards = entry['row_hazards']
    errors << "#{decision_register_label} #{label}: Edit Transaksi must remain an append-only correction/reversal candidate" unless hazards.is_a?(Array) && hazards.include?('append_only_correction_no_mutation')
    decision = entry['decision']
    return unless decision.is_a?(Hash) && %w[approve defer].include?(decision['status'])
    if decision['status'] == 'approve'
      unless decision['canonical_disposition'] == 'replace' && decision.dig('target', 'kind') == 'capability' && decision.dig('target', 'reference') == 'append_only_stock_correction_reversal'
        errors << "#{decision_register_label} #{label}: approval may only replace Edit Transaksi with append_only_stock_correction_reversal"
      end
      exclusions = Array(decision.dig('target', 'exclusions')).map(&:downcase)
      required_exclusions = ['edit history', 'delete history', 'overwrite history', 'in-place mutation']
      missing = required_exclusions.reject { |required| exclusions.any? { |item| item.include?(required) } }
      errors << "#{decision_register_label} #{label}: approval must explicitly exclude edit, delete, overwrite, and in-place mutation of history" unless missing.empty?
      mapping = entry['consolidation_mapping']
      unless mapping.is_a?(Hash) && mapping['status'] == 'complete' && mapping['terminal_target_requirement_id'] == 'append_only_stock_correction_reversal'
        errors << "#{decision_register_label} #{label}: approval requires a complete C11 mapping to append_only_stock_correction_reversal"
      end
    end
  end

  def batch_f_family_for(requirement_id)
    BATCH_F_FAMILY_MEMBERS.find { |_family, members| members.include?(requirement_id) }&.first
  end

  def batch_f_row_hazards(requirement_id)
    family = batch_f_family_for(requirement_id)
    return [] unless family && BATCH_F_ROW_HAZARDS.key?(requirement_id)

    [*BATCH_F_FAMILY_HAZARDS.fetch(family), *BATCH_F_CROSS_HAZARDS, *BATCH_F_ROW_HAZARDS.fetch(requirement_id)]
  end

  def batch_f_expected_family_policy(family)
    authority = BATCH_F_FAMILY_AUTHORITIES.fetch(family)
    {
      'family_id' => family,
      'members' => BATCH_F_FAMILY_MEMBERS.fetch(family),
      'lead_authority_domain' => authority.fetch(:lead),
      'co_owners' => authority.fetch(:co_owners),
      'inherited_hazards' => BATCH_F_FAMILY_HAZARDS.fetch(family)
    }
  end

  def validate_batch_f_family_policies(policies)
    prefix = 'Batch F decision register: family_policies'
    unless policies.is_a?(Array)
      errors << "#{prefix} must be an array"
      return
    end

    expected_families = BATCH_F_FAMILY_MEMBERS.keys
    actual_families = policies.each_with_object([]) { |policy, values| values << policy['family_id'] if policy.is_a?(Hash) }
    errors << "#{prefix} must follow exact frozen family order #{expected_families.inspect}" unless actual_families == expected_families
    duplicates = actual_families.group_by { |family| family }.select { |_family, values| values.length > 1 }.keys
    errors << "#{prefix} duplicate families: #{duplicates.join(', ')}" unless duplicates.empty?

    policies.each_with_index do |policy, index|
      family = policy['family_id'] if policy.is_a?(Hash)
      label = "#{prefix}[#{index}]"
      validate_closed_object(policy, BATCH_F_FAMILY_POLICY_KEYS, label)
      next unless BATCH_F_FAMILY_MEMBERS.key?(family)

      errors << "#{label} must exactly match frozen family #{family}" unless policy == batch_f_expected_family_policy(family)
    end
    members = policies.each_with_object([]) { |policy, values| values << policy['members'] if policy.is_a?(Hash) }.flatten
    errors << "#{prefix} must partition the exact 34 Batch F IDs without duplicates" unless members == BATCH_F_FAMILY_MEMBERS.values.flatten && members.uniq.length == EXPECTED_BATCH_F_IDS.length
  end

  def validate_batch_f_frozen_dependency_policy
    unless BATCH_F_INTRA_BATCH_DEPENDENCIES.keys == EXPECTED_BATCH_F_IDS
      errors << 'Batch F frozen intra-dependency policy must cover the exact 34 IDs in manifest order'
    end
    unknown = BATCH_F_INTRA_BATCH_DEPENDENCIES.values.flatten - EXPECTED_BATCH_F_IDS
    errors << "Batch F frozen intra-dependency policy contains unknown IDs: #{unknown.uniq.join(', ')}" unless unknown.empty?
    self_edges = BATCH_F_INTRA_BATCH_DEPENDENCIES.each_with_object([]) { |(source, targets), values| values << source if targets.include?(source) }
    errors << "Batch F frozen intra-dependency policy contains self-dependencies: #{self_edges.join(', ')}" unless self_edges.empty?
    cycle = batch_f_dependency_cycle(BATCH_F_INTRA_BATCH_DEPENDENCIES)
    errors << "Batch F frozen intra-dependency policy contains a cycle: #{cycle.join(' -> ')}" if cycle
  end

  def batch_f_dependency_cycle(graph)
    state = {}
    stack = []
    visit = lambda do |node|
      state[node] = :visiting
      stack << node
      Array(graph[node]).each do |target|
        if state[target] == :visiting
          start = stack.index(target) || 0
          return stack[start..] + [target]
        elsif state[target].nil?
          cycle = visit.call(target)
          return cycle if cycle
        end
      end
      stack.pop
      state[node] = :visited
      nil
    end
    graph.keys.each do |node|
      cycle = visit.call(node) if state[node].nil?
      return cycle if cycle
    end
    nil
  end

  def validate_batch_f_controls(entry, label)
    prefix = "#{decision_register_label} #{label}"
    validate_closed_object(entry, BATCH_F_ENTRY_KEYS, prefix)
    family = batch_f_family_for(label)
    unless family
      errors << "#{prefix}: missing frozen Batch F family policy"
      return
    end

    validate_closed_object(entry['decision'], BATCH_F_DECISION_KEYS, "#{prefix}: decision") if entry['decision'].is_a?(Hash)
    validate_closed_object(entry.dig('decision', 'target'), BATCH_F_TARGET_KEYS, "#{prefix}: decision target") if entry.dig('decision', 'target').is_a?(Hash)
    validate_closed_object(entry['accountable_owner'], BATCH_F_OWNER_KEYS, "#{prefix}: accountable_owner") if entry['accountable_owner'].is_a?(Hash)
    Array(entry['appointment_dependencies']).each_with_index { |record, index| validate_closed_object(record, BATCH_F_APPOINTMENT_KEYS, "#{prefix}: appointment_dependencies[#{index}]") if record.is_a?(Hash) }
    Array(entry['evidence']).each_with_index { |record, index| validate_closed_object(record, BATCH_F_EVIDENCE_RECORD_KEYS, "#{prefix}: evidence[#{index}]") if record.is_a?(Hash) }
    validate_closed_object(entry['approval'], BATCH_F_APPROVAL_KEYS, "#{prefix}: approval") if entry['approval'].is_a?(Hash)
    errors << "#{prefix}: family_id must be #{family}" unless entry['family_id'] == family
    errors << "#{prefix}: availability_state must be Soon" unless entry['availability_state'] == 'Soon'
    validate_closed_object(entry['lifecycle_contract'], BATCH_F_LIFECYCLE_KEYS, "#{prefix}: lifecycle_contract")
    expected_lifecycle = BATCH_F_LIFECYCLE_KEYS.zip(BATCH_F_ROW_LIFECYCLES.fetch(label)).to_h
    errors << "#{prefix}: lifecycle_contract must exactly match the substantive per-ID state transition" unless entry['lifecycle_contract'] == expected_lifecycle
    expected_authorities = BATCH_F_FAMILY_AUTHORITIES.fetch(family).fetch(:co_owners)
    errors << "#{prefix}: affected_domains must exactly match frozen co-owners" unless entry['affected_domains'] == expected_authorities
    expected_hazards = batch_f_row_hazards(label)
    errors << "#{prefix}: row_hazards must exactly match frozen family, cross-family, and row hazards" unless entry['row_hazards'] == expected_hazards
    errors << "#{prefix}: ledger_invariants must exactly match the immutable Batch F finance/claim policy" unless entry['ledger_invariants'] == BATCH_F_LEDGER_INVARIANTS
    validate_batch_f_write_contract(entry['write_contract'], label)
    validate_batch_f_gate_authority_appointments(entry['gate_authority_appointments'], label)
    validate_batch_f_dependency_gates(entry['dependency_gates'], label)
    validate_batch_f_intra_dependencies(entry['intra_batch_dependencies'], label)
    validate_batch_f_integration_boundary(entry['integration_boundary'], label)
    validate_batch_f_reconciliation(entry['reconciliation_contract'], family, label)
    validate_batch_f_consolidation(entry['consolidation_mapping'], entry['decision'], label)
  end

  def validate_batch_f_write_contract(contract, label)
    prefix = "#{decision_register_label} #{label}: write_contract"
    validate_closed_object(contract, BATCH_F_WRITE_CONTRACT_KEYS, prefix)
    return unless contract.is_a?(Hash)

    expected_ledgers = BATCH_F_ROW_WRITE_LEDGERS.fetch(label, nil)
    errors << "#{prefix} owned_ledgers must exactly match the frozen per-ID writer policy" unless expected_ledgers && contract['owned_ledgers'] == expected_ledgers
    errors << "#{prefix} projection_write_access must be false" unless contract['projection_write_access'] == false
    errors << "#{prefix} allowed_event_operations must be append/version/compensate/reverse/reconcile/project only" unless contract['allowed_event_operations'] == %w[append version compensate reverse reconcile project]
    errors << "#{prefix} prohibited_mutations must freeze edit/delete/overwrite/backdate/closed-period/projection safeguards" unless contract['prohibited_mutations'] == %w[edit delete overwrite backdate reopen_closed_period projection_writeback]
    if BATCH_F_PROJECTION_IDS.include?(label) && contract['owned_ledgers'] != []
      errors << "#{prefix} projection-only capability cannot own or write any source ledger"
    end
    Array(contract['owned_ledgers']).each do |ledger|
      errors << "#{prefix} unknown ledger #{ledger.inspect}" unless BATCH_F_LEDGER_OWNERSHIP.key?(ledger) && ledger != 'reporting_projection'
    end
  end

  def validate_batch_f_gate_authority_appointments(appointments, label)
    prefix = "#{decision_register_label} #{label}: gate_authority_appointments"
    expected_domains = []
    expected_domains.concat(%w[pharmacy_gf finance_accounting]) if batch_f_expected_gates(label).any? { |gate| gate['batch'] == 'E' }
    expected_domains << BATCH_F_GATE_AUTHORITIES.fetch('G')
    unless appointments.is_a?(Array)
      errors << "#{prefix} must be an array"
      return
    end
    domains = appointments.each_with_object([]) { |appointment, values| values << appointment['authority_domain'] if appointment.is_a?(Hash) }
    errors << "#{prefix} must exactly cover prior-batch and reporting gate authorities #{expected_domains.inspect}" unless domains == expected_domains && domains.uniq.length == expected_domains.length
    appointments.each_with_index do |appointment, index|
      next unless appointment.is_a?(Hash)

      validate_closed_object(appointment, BATCH_F_APPOINTMENT_KEYS, "#{prefix}[#{index}]")
      errors << "#{prefix}[#{index}] required_scope must bind gate closure only" unless nonempty_string?(appointment['required_scope']) && appointment['required_scope'].include?('gate')
      status = appointment['status']
      errors << "#{prefix}[#{index}] status must be pending or appointed" unless APPOINTMENT_STATUSES.include?(status)
      if status == 'pending'
        %w[identity date reference artifact_sha256].each { |key| errors << "#{prefix}[#{index}] pending #{key} must be null" unless appointment[key].nil? }
      elsif status == 'appointed'
        errors << "#{prefix}[#{index}] identity must be non-placeholder" unless nonempty_string?(appointment['identity']) && !appointment['identity'].match?(PLACEHOLDER_OWNER_PATTERN)
        errors << "#{prefix}[#{index}] date must be YYYY-MM-DD" unless iso_date?(appointment['date'])
        validate_governance_artifact(
          reference: appointment['reference'], expected_sha256: appointment['artifact_sha256'],
          label: "#{prefix}[#{index}] appointment", requirement_id: label,
          subject: 'appointment_dependency', record: appointment
        )
      end
    end
  end

  def batch_f_expected_gates(label)
    profile_id = BATCH_F_ROW_GATE_PROFILE[label]
    profile = BATCH_F_GATE_PROFILES[profile_id]
    return [] unless profile

    profile.map do |batch, source_ids|
      {
        'direction' => batch == 'G' ? 'forward' : 'upstream',
        'batch' => batch,
        'scope' => BATCH_F_GATE_SCOPES.fetch(batch),
        'source_requirement_ids' => source_ids
      }
    end
  end

  def validate_batch_f_dependency_gates(gates, label)
    prefix = "#{decision_register_label} #{label}: dependency_gates"
    unless gates.is_a?(Array)
      errors << "#{prefix} must be an array"
      return
    end
    expected = batch_f_expected_gates(label)
    actual = gates.each_with_object([]) { |gate, values| values << gate.slice('direction', 'batch', 'scope', 'source_requirement_ids') if gate.is_a?(Hash) }
    errors << "#{prefix} must exactly bind the applicability-specific upstream and forward gates in dependency order" unless actual == expected

    gates.each_with_index do |gate, index|
      next unless gate.is_a?(Hash)

      gate_label = "#{prefix}[#{index}]"
      validate_closed_object(gate, BATCH_F_GATE_KEYS, gate_label)
      status = gate['status']
      errors << "#{gate_label} invalid status #{status.inspect}" unless BATCH_F_GATE_STATUSES.include?(status)
      if status == 'pending'
        %w[resolution defer_authority_domain resolution_reference resolution_artifact_sha256].each do |key|
          errors << "#{gate_label} pending #{key} must be null" unless gate[key].nil?
        end
      elsif status == 'resolved'
        errors << "#{gate_label} forward Batch G cannot be marked resolved without a loaded G register" if gate['batch'] == 'G'
        errors << "#{gate_label} resolved gate defer_authority_domain must be null" unless gate['defer_authority_domain'].nil?
        validate_batch_f_gate_artifact(gate, label, gate_label)
      elsif status == 'deferred'
        errors << "#{gate_label} only a scoped Batch E pharmacy-charge gate or forward Batch G reporting gate may be deferred" unless %w[E G].include?(gate['batch'])
        expected_defer_authority = gate['batch'] == 'E' ? 'pharmacy_gf_and_finance_accounting' : BATCH_F_GATE_AUTHORITIES.fetch('G')
        errors << "#{gate_label} defer_authority_domain must be #{expected_defer_authority}" unless gate['defer_authority_domain'] == expected_defer_authority
        if gate['batch'] == 'G' && BATCH_F_PROJECTION_IDS.include?(label)
          entry = @decision_entries.find { |candidate| candidate['batch'] == 'F' && candidate['requirement_id'] == label }
          decision = entry && entry['decision']
          safe = decision.is_a?(Hash) && decision['status'] == 'defer' && decision['canonical_disposition'] == 'exclude' &&
            decision.dig('target', 'exclusions') == BATCH_F_G_DEFERRAL_EXCLUSIONS
          errors << "#{gate_label} projection/monitor capability must remain defer/exclude with the exact G no-readiness exclusions while Batch G is deferred" unless safe
        end
        validate_batch_f_gate_artifact(gate, label, gate_label)
      end
    end
  end

  def validate_batch_f_gate_artifact(gate, requirement_id, gate_label)
    artifact = load_structured_json_artifact(gate['resolution_reference'], gate['resolution_artifact_sha256'], "#{gate_label} resolution")
    return unless artifact

    label = "#{gate_label} resolution"
    validate_closed_object(artifact, BATCH_F_GATE_ARTIFACT_KEYS, label)
    errors << "#{label} artifact_type must be #{BATCH_F_GATE_ARTIFACT_TYPE}" unless artifact['artifact_type'] == BATCH_F_GATE_ARTIFACT_TYPE
    errors << "#{label} schema_version must be #{ARTIFACT_SCHEMA_VERSION}" unless artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION
    errors << "#{label} register_id must be #{BATCH_F_REGISTER_ID}" unless artifact['register_id'] == BATCH_F_REGISTER_ID
    errors << "#{label} requirement_id must be #{requirement_id}" unless artifact['requirement_id'] == requirement_id
    errors << "#{label} subject must be dependency_gate" unless artifact['subject'] == 'dependency_gate'
    %w[direction batch scope status].each { |key| errors << "#{label} #{key} does not match the register gate" unless artifact[key] == gate[key] }
    errors << "#{label} resolution does not match the register gate" unless artifact['resolution'] == gate['resolution']
    errors << "#{label} identity must be a non-placeholder string" unless nonempty_string?(artifact['identity']) && !artifact['identity'].match?(PLACEHOLDER_OWNER_PATTERN)
    errors << "#{label} date must be YYYY-MM-DD" unless iso_date?(artifact['date'])

    if gate['batch'] == 'G'
      expected_authority = BATCH_F_GATE_AUTHORITIES.fetch('G')
      errors << "#{label} authority_domain must be #{expected_authority}" unless artifact['authority_domain'] == expected_authority
      entry = @decision_entries.find { |candidate| candidate['requirement_id'] == requirement_id && candidate['batch'] == 'F' }
      appointment = Array(entry && entry['gate_authority_appointments']).find { |candidate| candidate.is_a?(Hash) && candidate['authority_domain'] == expected_authority }
      unless appointment && appointment['status'] == 'appointed' && appointment['identity'] == artifact['identity']
        errors << "#{label} identity must match the appointed reporting gate authority"
      end
      manifest_sha = Digest::SHA256.file(@batch_manifest_path).hexdigest if File.file?(@batch_manifest_path)
      errors << "#{label} deferred G source_register_id must bind the manifest Batch G gate" unless artifact['source_register_id'] == 'G0_PARITY_BATCH_MANIFEST.json#batch-G'
      errors << "#{label} deferred G source_register_sha256 must match the loaded manifest" unless manifest_sha && artifact['source_register_sha256'] == manifest_sha
      errors << "#{label} deferred G source_bindings must be empty" unless artifact['source_bindings'] == []
      validate_batch_f_gate_deferral_bindings(artifact, entry, [expected_authority], label)
      errors << "#{label} deferred G exclusions must exactly prevent reporting readiness, export, financial truth, and live delivery" unless artifact['exclusions'] == BATCH_F_G_DEFERRAL_EXCLUSIONS
    elsif gate['batch'] == 'E' && gate['status'] == 'deferred'
      upstream_path = @decision_register_paths.fetch('E')
      upstream_sha = Digest::SHA256.file(upstream_path).hexdigest if File.file?(upstream_path)
      errors << "#{label} deferred E source_register_id must bind Batch E" unless artifact['source_register_id'] == BATCH_E_REGISTER_ID
      errors << "#{label} deferred E source_register_sha256 must match the loaded Batch E register" unless upstream_sha && artifact['source_register_sha256'] == upstream_sha
      errors << "#{label} deferred E source_bindings must be empty because no upstream readiness is claimed" unless artifact['source_bindings'] == []
      entry = @decision_entries.find { |candidate| candidate['requirement_id'] == requirement_id && candidate['batch'] == 'F' }
      validate_batch_f_gate_deferral_bindings(artifact, entry, %w[pharmacy_gf finance_accounting], label)
      errors << "#{label} deferred E gate identity/domain must bind pharmacy_gf first approval" unless artifact['authority_domain'] == 'pharmacy_gf' && artifact['identity'] == artifact.dig('deferral_bindings', 0, 'identity')
      required_exclusions = BATCH_F_E_DEFERRAL_EXCLUSIONS
      errors << "#{label} deferred E exclusions must exactly exclude medication charges, stock valuation, pharmacy claim completeness, and live delivery" unless artifact['exclusions'] == required_exclusions
      decision_exclusions = Array(entry&.dig('decision', 'target', 'exclusions')).join(' ').downcase
      missing = ['medication', 'stock valuation', 'charge-credit', 'claim completeness'].reject { |term| decision_exclusions.include?(term) }
      errors << "#{label} deferred E gate requires matching decision target exclusions for medication, stock valuation, charge-credit, and claim completeness" unless missing.empty?
    else
      upstream_batch = gate['batch']
      upstream_config = DECISION_REGISTER_CONFIGS[upstream_batch]
      upstream_path = @decision_register_paths[upstream_batch]
      upstream_sha = Digest::SHA256.file(upstream_path).hexdigest if upstream_path && File.file?(upstream_path)
      errors << "#{label} source_register_id must bind Batch #{upstream_batch}" unless upstream_config && artifact['source_register_id'] == upstream_config[:register_id]
      errors << "#{label} source_register_sha256 must match the loaded Batch #{upstream_batch} register" unless upstream_sha && artifact['source_register_sha256'] == upstream_sha
      errors << "#{label} resolved prior-batch exclusions must be empty" unless artifact['exclusions'] == []
      errors << "#{label} resolved prior-batch deferral_bindings must be empty" unless artifact['deferral_bindings'] == []
      unless @decision_register_statuses[upstream_batch] == 'complete'
        errors << "#{label} cannot resolve while Batch #{upstream_batch} register_status is not complete"
      end
      upstream_entries = @decision_entries_by_batch.fetch(upstream_batch, [])
      compatible = upstream_entries.length == upstream_config&.dig(:expected_count) && upstream_entries.all? do |candidate|
        decision = candidate['decision']
        decision.is_a?(Hash) && %w[approve defer].include?(decision['status'])
      end
      errors << "#{label} Batch #{upstream_batch} must be complete with terminal approve/defer decisions" unless compatible
      bindings = artifact['source_bindings']
      expected_ids = gate['source_requirement_ids']
      actual_ids = Array(bindings).each_with_object([]) { |binding, values| values << binding['requirement_id'] if binding.is_a?(Hash) }
      errors << "#{label} source_bindings must exactly follow the frozen dependency ID order" unless bindings.is_a?(Array) && actual_ids == expected_ids && actual_ids.uniq.length == expected_ids.length
      Array(bindings).each_with_index do |binding, index|
        binding_label = "#{label} source_bindings[#{index}]"
        next unless validate_closed_object(binding, BATCH_F_GATE_SOURCE_BINDING_KEYS, binding_label)

        source = upstream_entries.find { |candidate| candidate['requirement_id'] == binding['requirement_id'] }
        unless source
          errors << "#{binding_label} requirement_id must identify a loaded Batch #{upstream_batch} entry"
          next
        end
        expected_lead = source['lead_authority_domain'] || source.dig('accountable_owner', 'authority_domain')
        owner = source['accountable_owner']
        errors << "#{binding_label} lead_authority_domain must match the selected upstream lead #{expected_lead}" unless nonempty_string?(expected_lead) && binding['lead_authority_domain'] == expected_lead
        unless owner.is_a?(Hash) && owner['appointment_status'] == 'appointed' && owner['identity'] == binding['owner_identity'] && owner['authority_domain'] == expected_lead
          errors << "#{binding_label} owner_identity must match the selected upstream appointed accountable owner"
        end
        decision = source['decision']
        unless decision.is_a?(Hash) && decision['status'] == 'approve' && %w[reproduce replace].include?(decision['canonical_disposition'])
          errors << "#{binding_label} source requirement must have an approved reproduce/replace decision"
        end
        approval = source['approval']
        unless approval.is_a?(Hash) && approval['status'] == 'recorded' && approval['identity'] == binding['owner_identity'] && approval['reference'] == binding['approval_reference'] && approval['artifact_sha256'] == binding['approval_sha256']
          errors << "#{binding_label} approval reference and SHA-256 must match the loaded upstream approval"
        end
      end
      first_binding = Array(bindings).first
      unless first_binding.is_a?(Hash) && artifact['identity'] == first_binding['owner_identity'] && artifact['authority_domain'] == first_binding['lead_authority_domain']
        errors << "#{label} gate resolution identity/domain must bind the first frozen upstream owner"
      end
    end
    validate_artifact_reviewer(artifact['reviewer'], artifact['identity'], label)
  end

  def validate_batch_f_gate_deferral_bindings(artifact, entry, expected_domains, label)
    bindings = artifact['deferral_bindings']
    actual_domains = Array(bindings).each_with_object([]) { |binding, values| values << binding['authority_domain'] if binding.is_a?(Hash) }
    errors << "#{label} deferral_bindings must exactly follow appointed authorities #{expected_domains.inspect}" unless bindings.is_a?(Array) && actual_domains == expected_domains
    Array(bindings).each_with_index do |binding, index|
      binding_label = "#{label} deferral_bindings[#{index}]"
      next unless validate_closed_object(binding, BATCH_F_GATE_DEFERRAL_BINDING_KEYS, binding_label)

      appointment = Array(entry && entry['gate_authority_appointments']).find { |candidate| candidate.is_a?(Hash) && candidate['authority_domain'] == binding['authority_domain'] }
      unless appointment && appointment['status'] == 'appointed' && appointment['identity'] == binding['identity'] && appointment['reference'] == binding['appointment_reference'] && appointment['artifact_sha256'] == binding['appointment_sha256']
        errors << "#{binding_label} must bind the matching appointed gate authority identity and artifact"
      end
      approval = load_structured_json_artifact(binding['approval_reference'], binding['approval_sha256'], "#{binding_label} signed deferral approval")
      next unless approval

      approval_label = "#{binding_label} signed deferral approval"
      validate_closed_object(approval, BATCH_F_GATE_DEFERRAL_APPROVAL_ARTIFACT_KEYS, approval_label)
      errors << "#{approval_label} artifact_type must be #{BATCH_F_GATE_DEFERRAL_APPROVAL_ARTIFACT_TYPE}" unless approval['artifact_type'] == BATCH_F_GATE_DEFERRAL_APPROVAL_ARTIFACT_TYPE
      errors << "#{approval_label} schema_version/register_id must match Batch F" unless approval['schema_version'] == ARTIFACT_SCHEMA_VERSION && approval['register_id'] == BATCH_F_REGISTER_ID
      errors << "#{approval_label} requirement_id must match the gate entry" unless approval['requirement_id'] == artifact['requirement_id']
      errors << "#{approval_label} subject must be gate_deferral_approval" unless approval['subject'] == 'gate_deferral_approval'
      %w[batch scope status resolution exclusions].each do |key|
        errors << "#{approval_label} #{key} must exactly match the gate deferral" unless approval[key] == artifact[key]
      end
      errors << "#{approval_label} identity/authority_domain must match the deferral binding" unless approval['identity'] == binding['identity'] && approval['authority_domain'] == binding['authority_domain']
      errors << "#{approval_label} date must match the gate artifact date" unless iso_date?(approval['date']) && approval['date'] == artifact['date']
      validate_artifact_reviewer(approval['reviewer'], approval['identity'], approval_label)
    end
  end

  def batch_f_expected_intra_dependencies(label)
    Array(BATCH_F_INTRA_BATCH_DEPENDENCIES[label]).map do |target|
      source_state = BATCH_F_ROW_LIFECYCLES.fetch(target)[2]
      target_state = BATCH_F_ROW_LIFECYCLES.fetch(label)[0]
      { 'requirement_id' => target, 'scope' => [source_state, target_state] }
    end
  end

  def validate_batch_f_intra_dependencies(dependencies, label)
    prefix = "#{decision_register_label} #{label}: intra_batch_dependencies"
    unless dependencies.is_a?(Array)
      errors << "#{prefix} must be an array"
      return
    end
    expected = batch_f_expected_intra_dependencies(label)
    actual = dependencies.each_with_object([]) { |dependency, values| values << dependency.slice('requirement_id', 'scope') if dependency.is_a?(Hash) }
    errors << "#{prefix} must exactly match the frozen intra-Batch-F dependency graph" unless actual == expected
    dependencies.each_with_index do |dependency, index|
      next unless dependency.is_a?(Hash)

      dependency_label = "#{prefix}[#{index}]"
      validate_closed_object(dependency, BATCH_F_INTRA_DEPENDENCY_KEYS, dependency_label)
      unless %w[pending resolved].include?(dependency['status'])
        errors << "#{dependency_label} status must be pending or resolved"
      end
      if dependency['status'] == 'pending'
        %w[resolution_reference resolution_artifact_sha256].each { |key| errors << "#{dependency_label} pending #{key} must be null" unless dependency[key].nil? }
      elsif dependency['status'] == 'resolved'
        validate_batch_f_intra_dependency_artifact(dependency, label, dependency_label)
      end
    end
  end

  def validate_batch_f_intra_dependency_artifact(dependency, source_id, dependency_label)
    artifact = load_structured_json_artifact(dependency['resolution_reference'], dependency['resolution_artifact_sha256'], "#{dependency_label} resolution")
    return unless artifact

    label = "#{dependency_label} resolution"
    validate_closed_object(artifact, BATCH_F_INTRA_DEPENDENCY_ARTIFACT_KEYS, label)
    errors << "#{label} artifact_type must be #{BATCH_F_INTRA_DEPENDENCY_ARTIFACT_TYPE}" unless artifact['artifact_type'] == BATCH_F_INTRA_DEPENDENCY_ARTIFACT_TYPE
    errors << "#{label} schema_version must be #{ARTIFACT_SCHEMA_VERSION}" unless artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION
    errors << "#{label} register_id must be #{BATCH_F_REGISTER_ID}" unless artifact['register_id'] == BATCH_F_REGISTER_ID
    errors << "#{label} source_requirement_id must be #{source_id}" unless artifact['source_requirement_id'] == source_id
    errors << "#{label} target_requirement_id must match the dependency" unless artifact['target_requirement_id'] == dependency['requirement_id']
    errors << "#{label} scope/status must match the dependency" unless artifact['scope'] == dependency['scope'] && artifact['status'] == 'resolved'
    target = @decision_entries.find { |entry| entry['requirement_id'] == dependency['requirement_id'] && entry['batch'] == 'F' }
    unless target
      errors << "#{label} target requirement must exist in Batch F"
      return
    end
    decision = target['decision']
    unless decision.is_a?(Hash) && decision['status'] == 'approve' && %w[reproduce replace].include?(decision['canonical_disposition'])
      errors << "#{label} target requirement must have an approved reproduce/replace decision"
    end
    owner = target['accountable_owner']
    unless owner.is_a?(Hash) && owner['appointment_status'] == 'appointed' && artifact['identity'] == owner['identity'] && artifact['authority_domain'] == target['lead_authority_domain']
      errors << "#{label} identity/domain must match the target appointed accountable owner and lead"
    end
    approval = target['approval']
    unless approval.is_a?(Hash) && approval['status'] == 'recorded' && artifact['target_approval_reference'] == approval['reference'] && artifact['target_approval_sha256'] == approval['artifact_sha256']
      errors << "#{label} target approval reference and SHA-256 must match the loaded target"
    end
    errors << "#{label} target decision binding must match approve/reproduce-or-replace" unless artifact['target_decision_status'] == 'approve' && artifact['target_disposition'] == decision&.dig('canonical_disposition')
    errors << "#{label} date must be YYYY-MM-DD" unless iso_date?(artifact['date'])
    validate_artifact_reviewer(artifact['reviewer'], artifact['identity'], label)
  end

  def validate_batch_f_integration_boundary(boundary, label)
    prefix = "#{decision_register_label} #{label}: integration_boundary"
    validate_closed_object(boundary, BATCH_F_BOUNDARY_KEYS, prefix)
    return unless boundary.is_a?(Hash)

    family = batch_f_family_for(label)
    expected_mode = %w[F4 F5].include?(family) ? 'non_transmitting_simulation' : 'none'
    expected_transport = expected_mode == 'none' ? 'not_applicable' : 'deterministic_local_fixture'
    errors << "#{prefix} mode must be #{expected_mode}" unless BATCH_F_INTEGRATION_MODES.include?(boundary['mode']) && boundary['mode'] == expected_mode
    errors << "#{prefix} endpoint must be null" unless boundary['endpoint'].nil?
    errors << "#{prefix} credential_state must be absent" unless boundary['credential_state'] == 'absent'
    errors << "#{prefix} outbound_network must be false" unless boundary['outbound_network'] == false
    errors << "#{prefix} delivery_state must be NOT_SENT" unless boundary['delivery_state'] == 'NOT_SENT'
    errors << "#{prefix} transport_result must be #{expected_transport}" unless boundary['transport_result'] == expected_transport
    errors << "#{prefix} prohibited_targets must exactly enumerate every forbidden national, payment, bank, ERP, and device boundary" unless boundary['prohibited_targets'] == BATCH_F_PROHIBITED_TARGETS
    errors << "#{prefix} notes must state the synthetic-only boundary" unless nonempty_string?(boundary['notes']) && boundary['notes'].downcase.include?('synthetic')
  end

  def validate_batch_f_reconciliation(control, family, requirement_id)
    prefix = "#{decision_register_label} #{requirement_id}: reconciliation_contract"
    validate_closed_object(control, BATCH_F_RECONCILIATION_KEYS, prefix)
    return unless control.is_a?(Hash)

    profile_id = BATCH_F_ROW_RECONCILIATION_PROFILE[requirement_id]
    profile = BATCH_F_RECONCILIATION_PROFILES[profile_id]
    errors << "#{prefix} missing frozen per-ID reconciliation profile" unless profile
    errors << "#{prefix} profile_id must be #{profile_id}" unless control['profile_id'] == profile_id
    errors << "#{prefix} status must be pending or complete" unless %w[pending complete].include?(control['status'])
    errors << "#{prefix} currency must be IDR" unless control['currency'] == 'IDR'
    errors << "#{prefix} minor_unit must be 1" unless control['minor_unit'] == 1
    errors << "#{prefix} period_timezone must be Asia/Jakarta" unless control['period_timezone'] == 'Asia/Jakarta'
    errors << "#{prefix} late_posting_policy must append to an open period with a prior-period reference" unless control['late_posting_policy'] == 'append_to_open_period_with_prior_period_reference'
    errors << "#{prefix} control_totals must exactly match the applicable per-ID totals" unless profile && control['control_totals'] == profile.fetch(:control_totals)
    errors << "#{prefix} equations must exactly match the applicable per-ID minor-unit equations" unless profile && control['equations'] == profile.fetch(:equations)
    if control['status'] == 'pending'
      %w[receipt_reference receipt_artifact_sha256].each { |key| errors << "#{prefix} pending #{key} must be null" unless control[key].nil? }
    elsif control['status'] == 'complete'
      validate_batch_f_reconciliation_artifact(control, family, requirement_id)
    end
  end

  def validate_batch_f_reconciliation_artifact(control, family, requirement_id)
    artifact = load_structured_json_artifact(control['receipt_reference'], control['receipt_artifact_sha256'], "#{decision_register_label} #{requirement_id}: reconciliation")
    return unless artifact

    label = "#{decision_register_label} #{requirement_id}: reconciliation"
    validate_closed_object(artifact, BATCH_F_RECONCILIATION_ARTIFACT_KEYS, label)
    errors << "#{label} artifact_type must be #{BATCH_F_RECONCILIATION_ARTIFACT_TYPE}" unless artifact['artifact_type'] == BATCH_F_RECONCILIATION_ARTIFACT_TYPE
    errors << "#{label} schema_version must be #{ARTIFACT_SCHEMA_VERSION}" unless artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION
    errors << "#{label} register_id must be #{BATCH_F_REGISTER_ID}" unless artifact['register_id'] == BATCH_F_REGISTER_ID
    errors << "#{label} requirement_id must be #{requirement_id}" unless artifact['requirement_id'] == requirement_id
    errors << "#{label} family_id must be #{family}" unless artifact['family_id'] == family
    profile_id = BATCH_F_ROW_RECONCILIATION_PROFILE.fetch(requirement_id)
    profile = BATCH_F_RECONCILIATION_PROFILES.fetch(profile_id)
    errors << "#{label} profile_id must be #{profile_id}" unless artifact['profile_id'] == profile_id && control['profile_id'] == profile_id
    errors << "#{label} synthetic_only must be true" unless artifact['synthetic_only'] == true
    errors << "#{label} currency/minor_unit must be IDR/1" unless artifact['currency'] == 'IDR' && artifact['minor_unit'] == 1
    errors << "#{label} period_timezone must be Asia/Jakarta" unless artifact['period_timezone'] == 'Asia/Jakarta'
    errors << "#{label} late_posting_policy does not match the register" unless artifact['late_posting_policy'] == control['late_posting_policy']
    errors << "#{label} period dates must be YYYY-MM-DD and ordered" unless iso_date?(artifact['period_start']) && iso_date?(artifact['period_end']) && artifact['period_start'] <= artifact['period_end']
    errors << "#{label} cutoff_at must include Asia/Jakarta offset +07:00" unless nonempty_string?(artifact['cutoff_at']) && artifact['cutoff_at'].match?(/\+07:00\z/)
    errors << "#{label} event_count must be a positive integer" unless artifact['event_count'].is_a?(Integer) && artifact['event_count'] > 0
    errors << "#{label} equations must exactly match the register and applicable profile" unless artifact['equations'] == control['equations'] && artifact['equations'] == profile.fetch(:equations)
    validate_batch_f_control_values(artifact['control_values'], artifact['differences'], profile_id, label)
    validate_batch_f_ledger_receipts(artifact['ledger_receipts'], artifact, requirement_id, profile_id, label)
    errors << "#{label} idempotency_key must be a non-placeholder string" unless nonempty_string?(artifact['idempotency_key'])
    errors << "#{label} date must be YYYY-MM-DD" unless iso_date?(artifact['date'])
    errors << "#{label} author_identity must be non-placeholder" unless nonempty_string?(artifact['author_identity']) && !artifact['author_identity'].match?(PLACEHOLDER_OWNER_PATTERN)
    validate_artifact_reviewer(artifact['reviewer'], artifact['author_identity'], label)
  end

  def validate_batch_f_control_values(values, differences, profile_id, label)
    profile = BATCH_F_RECONCILIATION_PROFILES.fetch(profile_id)
    expected_keys = profile.fetch(:control_totals)
    unless values.is_a?(Hash) && values.keys == expected_keys && values.values.all? { |value| value.is_a?(Integer) && value >= 0 }
      errors << "#{label} control_values must contain exact nonnegative integer IDR minor-unit/count totals"
      return
    end
    expected_differences = batch_f_expected_differences(profile_id, values)
    expected_difference_keys = BATCH_F_PROFILE_DIFFERENCE_KEYS.fetch(profile_id)
    unless differences.is_a?(Hash) && differences.keys == expected_difference_keys && differences == expected_differences && differences.values.all?(&:zero?)
      errors << "#{label} differences must exactly bind every frozen equation and all equal zero"
    end
  end

  def batch_f_expected_differences(profile_id, values)
    case profile_id
    when 'master_version'
      { 'master_overlap_difference' => values['invalid_overlap_count'] }
    when 'bill_version'
      { 'bill_difference' => values['gross_charge_total'] - values['approved_discount_total'] + values['tax_fee_total'] + values['debit_adjustment_total'] - values['credit_adjustment_total'] - values['reversal_total'] - values['net_bill_total'] }
    when 'revenue_projection'
      { 'revenue_partition_difference' => values['revenue_inpatient_total'] + values['revenue_outpatient_total'] + values['revenue_emergency_total'] + values['revenue_other_total'] + values['revenue_unit_total'] + values['revenue_procedure_total'] - values['revenue_overall_total'] }
    when 'medical_fee'
      { 'fee_basis_excess' => [values['medical_fee_recipient_sum'] - values['medical_fee_approved_basis'], 0].max }
    when 'receivable'
      { 'ar_difference' => values['opening_ar_total'] + values['net_billed_total'] - values['payment_total'] - values['payer_remittance_total'] - values['writeoff_total'] + values['debit_adjustment_total'] - values['credit_adjustment_total'] - values['closing_ar_total'] }
    when 'settlement'
      {
        'settlement_difference' => values['receipt_total'] - values['refund_total'] - values['reversed_receipt_total'] - values['net_settlement_total'],
        'deposit_difference' => values['net_settlement_total'] - values['accepted_deposit_total']
      }
    when 'journal'
      { 'journal_balance_difference' => values['journal_debit_total'] - values['journal_credit_total'], 'journal_source_difference' => values['journal_source_total'] - values['journal_debit_total'] }
    when 'claim_snapshot', 'claim_boundary'
      differences = {
        'claim_snapshot_difference' => values['submitted_claim_total'] - values['eligible_bill_snapshot_total'],
        'claim_amount_cohort_difference' => values['accepted_claim_amount'] + values['remitted_claim_amount'] + values['denied_claim_amount'] + values['pending_claim_amount'] + values['reversed_claim_amount'] - values['submitted_claim_total'],
        'claim_count_cohort_difference' => values['accepted_claim_count'] + values['remitted_claim_count'] + values['denied_claim_count'] + values['pending_claim_count'] + values['reversed_claim_count'] - values['submitted_claim_count']
      }
      if profile_id == 'claim_boundary'
        differences['not_sent_difference'] = values['request_count'] - values['not_sent_count']
        differences['duplicate_request_difference'] = values['duplicate_request_count']
      end
      differences
    when 'integration_boundary'
      { 'not_sent_difference' => values['request_count'] - values['not_sent_count'], 'duplicate_request_difference' => values['duplicate_request_count'] }
    else
      {}
    end
  end

  def validate_batch_f_ledger_receipts(receipts, reconciliation, requirement_id, profile_id, label)
    expected_kinds = BATCH_F_RECONCILIATION_PROFILES.fetch(profile_id).fetch(:ledgers)
    unless receipts.is_a?(Array) && receipts.length == expected_kinds.length
      errors << "#{label} ledger_receipts must contain exactly the applicable #{profile_id} ledgers"
      return
    end
    kinds = receipts.map { |receipt| receipt['ledger_kind'] if receipt.is_a?(Hash) }
    errors << "#{label} ledger_receipts must follow the applicable frozen ledger order" unless kinds == expected_kinds
    digests = []
    receipts.each_with_index do |descriptor, index|
      prefix = "#{label} ledger_receipts[#{index}]"
      unless descriptor.is_a?(Hash)
        errors << "#{prefix} must be an object"
        next
      end
      validate_closed_object(descriptor, %w[ledger_kind reference sha256], prefix)
      receipt = load_structured_json_artifact(descriptor['reference'], descriptor['sha256'], prefix)
      next unless receipt

      validate_closed_object(receipt, BATCH_F_LEDGER_RECEIPT_ARTIFACT_KEYS, prefix)
      errors << "#{prefix} artifact_type must be #{BATCH_F_LEDGER_RECEIPT_ARTIFACT_TYPE}" unless receipt['artifact_type'] == BATCH_F_LEDGER_RECEIPT_ARTIFACT_TYPE
      errors << "#{prefix} schema_version must be #{ARTIFACT_SCHEMA_VERSION}" unless receipt['schema_version'] == ARTIFACT_SCHEMA_VERSION
      errors << "#{prefix} register_id must be #{BATCH_F_REGISTER_ID}" unless receipt['register_id'] == BATCH_F_REGISTER_ID
      errors << "#{prefix} requirement_id/profile_id must match the reconciliation" unless receipt['requirement_id'] == requirement_id && receipt['profile_id'] == profile_id
      errors << "#{prefix} ledger_kind must match the descriptor" unless receipt['ledger_kind'] == descriptor['ledger_kind']
      errors << "#{prefix} must be synthetic_only" unless receipt['synthetic_only'] == true
      %w[currency minor_unit period_start period_end period_timezone cutoff_at late_posting_policy event_count control_values idempotency_key].each do |key|
        errors << "#{prefix} #{key} must match the reconciliation artifact" unless receipt[key] == reconciliation[key]
      end
      errors << "#{prefix} event_count must be positive" unless receipt['event_count'].is_a?(Integer) && receipt['event_count'] > 0
      errors << "#{prefix} ledger_digest must be SHA-256" unless receipt['ledger_digest'].is_a?(String) && receipt['ledger_digest'].match?(/\A[0-9a-f]{64}\z/i)
      digests << receipt['ledger_digest'] if receipt['ledger_digest'].is_a?(String)
      errors << "#{prefix} date must be YYYY-MM-DD" unless iso_date?(receipt['date'])
      errors << "#{prefix} author_identity must be non-placeholder" unless nonempty_string?(receipt['author_identity']) && !receipt['author_identity'].match?(PLACEHOLDER_OWNER_PATTERN)
      validate_artifact_reviewer(receipt['reviewer'], receipt['author_identity'], prefix)
    end
    errors << "#{label} ledger receipt digests must be distinct across every applicable ledger/projection" unless digests.length == expected_kinds.length && digests.uniq.length == digests.length
  end

  def batch_f_candidate_for(label)
    BATCH_F_CONSOLIDATION_GROUPS.find { |_candidate, members| members.include?(label) }&.first
  end

  def validate_batch_f_consolidation(control, decision, label)
    prefix = "#{decision_register_label} #{label}: consolidation_mapping"
    validate_closed_object(control, BATCH_F_CONSOLIDATION_KEYS, prefix)
    return unless control.is_a?(Hash)

    candidate = batch_f_candidate_for(label)
    errors << "#{prefix} candidate_id must be #{candidate.inspect}" unless control['candidate_id'] == candidate
    errors << "#{prefix} status must be pending, complete, or not_applicable" unless %w[pending complete not_applicable].include?(control['status'])
    if %w[pending not_applicable].include?(control['status'])
      %w[terminal_target_requirement_id artifact_reference artifact_sha256].each { |key| errors << "#{prefix} #{key} must be null until mapping is complete" unless control[key].nil? }
      errors << "#{prefix} audited candidates must remain pending until mapped" if candidate && control['status'] == 'not_applicable'
      errors << "#{prefix} non-candidates must be not_applicable" if candidate.nil? && control['status'] != 'not_applicable'
    elsif control['status'] == 'complete'
      errors << "#{prefix} cannot complete a non-candidate" unless candidate
      validate_batch_f_consolidation_artifact(control, label, candidate) if candidate
    end
    if decision.is_a?(Hash) && %w[approve defer].include?(decision['status']) && decision['canonical_disposition'] == 'consolidate'
      errors << "#{prefix} consolidation requires a complete frozen-candidate mapping" unless candidate && control['status'] == 'complete'
    end
  end

  def validate_batch_f_consolidation_artifact(control, requirement_id, candidate)
    artifact = load_structured_json_artifact(control['artifact_reference'], control['artifact_sha256'], "#{decision_register_label} #{requirement_id}: consolidation artifact")
    return unless artifact

    label = "#{decision_register_label} #{requirement_id}: consolidation artifact"
    validate_closed_object(artifact, BATCH_F_CONSOLIDATION_ARTIFACT_KEYS, label)
    errors << "#{label} artifact_type must be #{BATCH_F_CONSOLIDATION_ARTIFACT_TYPE}" unless artifact['artifact_type'] == BATCH_F_CONSOLIDATION_ARTIFACT_TYPE
    errors << "#{label} schema_version/register_id must match Batch F" unless artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION && artifact['register_id'] == BATCH_F_REGISTER_ID
    errors << "#{label} candidate_id must be #{candidate}" unless artifact['candidate_id'] == candidate
    members = BATCH_F_CONSOLIDATION_GROUPS.fetch(candidate)
    errors << "#{label} members must exactly retain all audited IDs" unless artifact['members'] == members
    impacts = artifact['member_impacts']
    errors << "#{label} member_impacts must retain each ID with substantive impacts" unless impacts.is_a?(Hash) && impacts.keys == members && impacts.values.all? { |values| values.is_a?(Array) && !values.empty? && values.all? { |value| nonempty_string?(value) } }
    mapping = BATCH_F_CONSOLIDATION_MAPPING_CONTRACTS.fetch(candidate)
    errors << "#{label} target_requirement_id must match the shared terminal target" unless artifact['target_requirement_id'] == control['terminal_target_requirement_id']
    target = @decision_entries.find { |entry| entry['batch'] == 'F' && entry['requirement_id'] == artifact['target_requirement_id'] }
    target_decision = target && target['decision']
    unless target_decision.is_a?(Hash) && target_decision['status'] == 'approve' && %w[reproduce replace].include?(target_decision['canonical_disposition'])
      errors << "#{label} terminal target must have an approved reproduce/replace decision"
    end
    target_owner = target && target['accountable_owner']
    unless target_owner.is_a?(Hash) && target_owner['appointment_status'] == 'appointed' &&
           artifact['terminal_owner_identity'] == target_owner['identity'] && artifact['terminal_authority_domain'] == target['lead_authority_domain']
      errors << "#{label} terminal owner identity/domain must bind the appointed target owner and lead"
    end
    target_approval = target && target['approval']
    unless target_approval.is_a?(Hash) && target_approval['status'] == 'recorded' &&
           artifact['terminal_approval_reference'] == target_approval['reference'] && artifact['terminal_approval_sha256'] == target_approval['artifact_sha256']
      errors << "#{label} terminal approval reference and SHA-256 must bind the recorded target approval"
    end
    errors << "#{label} mapped_fields must exactly preserve fields, roles, contexts, versions, amounts, lineage, authorities, and audit" unless artifact['mapped_fields'] == mapping.fetch(:fields)
    errors << "#{label} mapped_states must exactly preserve every member lifecycle state" unless artifact['mapped_states'] == mapping.fetch(:states)
    errors << "#{label} mapped_control_totals must exactly preserve every applicable reconciliation total" unless artifact['mapped_control_totals'] == mapping.fetch(:control_totals)
    errors << "#{label} lineage_preserved and authorities_preserved must be true" unless artifact['lineage_preserved'] == true && artifact['authorities_preserved'] == true
    errors << "#{label} exclusions must be an array" unless artifact['exclusions'].is_a?(Array) && artifact['exclusions'].all? { |value| nonempty_string?(value) }
    errors << "#{label} date must be YYYY-MM-DD" unless iso_date?(artifact['date'])
    errors << "#{label} author_identity must be non-placeholder" unless nonempty_string?(artifact['author_identity']) && !artifact['author_identity'].match?(PLACEHOLDER_OWNER_PATTERN)
    validate_artifact_reviewer(artifact['reviewer'], artifact['author_identity'], label)
  end

  def batch_g_family_for(requirement_id)
    BATCH_G_FAMILY_MEMBERS.find { |_family, members| members.include?(requirement_id) }&.first
  end

  def batch_g_menu_for(requirement_id)
    row = rows.find { |candidate| candidate[:cells].length == MATRIX_COLUMNS.length && candidate[:cells][0] == requirement_id }
    row && row[:cells][2]
  end

  def batch_g_expected_family_policy(family)
    policy = BATCH_G_FAMILY_POLICY.fetch(family)
    {
      'family_id' => family,
      'members' => BATCH_G_FAMILY_MEMBERS.fetch(family),
      'lead_authority_domain' => policy.fetch(:lead),
      'mandatory_authorities' => policy.fetch(:mandatory),
      'description' => policy.fetch(:description)
    }
  end

  def validate_batch_g_family_policies(policies)
    prefix = 'Batch G decision register: family_policies'
    unless policies.is_a?(Array)
      errors << "#{prefix} must be an array"
      return
    end
    actual = policies.each_with_object([]) { |policy, values| values << policy['family_id'] if policy.is_a?(Hash) }
    expected = BATCH_G_FAMILY_MEMBERS.keys
    errors << "#{prefix} must follow exact G1/G2/G3/G4 order" unless actual == expected
    policies.each_with_index do |policy, index|
      label = "#{prefix}[#{index}]"
      validate_closed_object(policy, BATCH_G_FAMILY_POLICY_KEYS, label)
      family = policy['family_id'] if policy.is_a?(Hash)
      errors << "#{label} is not a frozen Batch G family" unless BATCH_G_FAMILY_MEMBERS.key?(family)
      if BATCH_G_FAMILY_MEMBERS.key?(family) && policy != batch_g_expected_family_policy(family)
        errors << "#{label} must exactly match the frozen #{family} authority and membership policy"
      end
    end
    members = policies.flat_map { |policy| policy.is_a?(Hash) && policy['members'].is_a?(Array) ? policy['members'] : [] }
    unless members == BATCH_G_FAMILY_MEMBERS.values.flatten && members.uniq.length == EXPECTED_BATCH_G_IDS.length
      errors << "#{prefix} must partition the exact 120 Batch G IDs without duplicates"
    end
  end

  def validate_batch_g_frozen_graph
    unless BATCH_G_ROW_SEMANTIC_MAPPING.keys == EXPECTED_BATCH_G_IDS && BATCH_G_ROW_SEMANTIC_SPECS.keys == EXPECTED_BATCH_G_IDS && BATCH_G_ROW_SEMANTIC_SPECS.all? { |requirement_id, spec| spec[:requirement_id] == requirement_id && spec[:semantic_contract_id] == "G-SEMANTIC-#{requirement_id}" }
      errors << 'Batch G frozen per-ID semantic registry must cover all 120 IDs with exact row-bound contracts in manifest order'
    end
    required_semantic_fields = %i[requirement_id semantic_type semantic_contract_id capability_focus status care_setting dimension grain key numerator denominator parameters fixed_parameter_values sources source_roles measures reconciliation inclusions exclusions time_basis cutoff_policy period_close_policy]
    BATCH_G_ROW_SEMANTIC_SPECS.each do |requirement_id, spec|
      missing_fields = required_semantic_fields.reject { |field| spec.key?(field) }
      errors << "Batch G frozen semantic spec #{requirement_id} is missing substantive fields: #{missing_fields.join(', ')}" unless missing_fields.empty?
      mapping = BATCH_G_ROW_SEMANTIC_MAPPING[requirement_id]
      next unless mapping && missing_fields.empty?

      semantic_type, capability_focus, fixed_values = mapping
      errors << "Batch G frozen semantic spec #{requirement_id} must resolve its exact non-generic semantic group and capability focus" unless BATCH_G_SEMANTIC_TEMPLATES.key?(semantic_type) && spec[:semantic_type] == semantic_type && spec[:capability_focus] == capability_focus && nonempty_string?(capability_focus) && !capability_focus.match?(/generic|placeholder|unspecified/i)
      errors << "Batch G frozen semantic spec #{requirement_id} fixed parameters must be nonempty, declared, and exactly mapped" unless spec[:fixed_parameter_values] == fixed_values && fixed_values.is_a?(Hash) && fixed_values.keys.all? { |key| spec[:parameters].include?(key) } && fixed_values.values.all? { |value| nonempty_string?(value) }
      expected_roles = spec[:sources].map { |source_key| BATCH_G_SOURCE_SPECS.fetch(source_key).slice(:batch, :id, :entity) }
      actual_roles = spec[:source_roles].map { |role| { batch: role[:batch], id: role[:requirement_id], entity: role[:entity] } }
      errors << "Batch G frozen semantic spec #{requirement_id} source roles must exactly bind every declared source entity" unless actual_roles == expected_roles
      errors << "Batch G frozen semantic spec #{requirement_id} must declare closed eligibility/exclusion and time/close semantics" unless spec[:inclusions].is_a?(Array) && !spec[:inclusions].empty? && spec[:exclusions].is_a?(Array) && !spec[:exclusions].empty? && nonempty_string?(spec[:time_basis]) && nonempty_string?(spec[:cutoff_policy]) && nonempty_string?(spec[:period_close_policy])
      unresolved = spec[:status] == 'unresolved_owner_definition'
      if unresolved && requirement_id.start_with?('PAR-RPT-')
        errors << "Batch G unresolved semantic spec #{requirement_id} must remain formula/source blocked until authority definition" unless spec[:grain].start_with?('blocked_') && spec[:key].start_with?('blocked_') && spec[:numerator].match?(/unresolved/) && spec[:denominator].match?(/unresolved/)
      elsif spec[:grain].match?(/generic|blocked/) || spec[:key].match?(/generic|blocked/) || spec[:numerator].match?(/generic|unresolved/) || (spec[:denominator].match?(/generic|unresolved/) && spec[:status] != 'defined_count_denominator_unresolved')
        errors << "Batch G defined semantic spec #{requirement_id} cannot use a generic or unresolved grain/formula fallback"
      end
    end
    semantic_contract_ids = BATCH_G_ROW_SEMANTIC_SPECS.values.map { |spec| spec[:semantic_contract_id] }
    errors << 'Batch G frozen per-ID semantic contract IDs must be unique' unless semantic_contract_ids.uniq.length == EXPECTED_BATCH_G_IDS.length
    substantive_digests = BATCH_G_ROW_SEMANTIC_SPECS.values.map do |spec|
      Digest::SHA256.hexdigest(JSON.generate(spec.reject { |field, _value| %i[requirement_id semantic_contract_id].include?(field) }))
    end
    errors << 'Batch G semantic exactness must come from substantive focus/dimension/source/parameter differences, not PAR ID wrappers' unless substantive_digests.uniq.length == EXPECTED_BATCH_G_IDS.length
    unless BATCH_G_INTRA_BATCH_DEPENDENCIES.keys == EXPECTED_BATCH_G_IDS
      errors << 'Batch G frozen intra-dependency policy must cover the exact 120 IDs in manifest order'
    end
    unknown = BATCH_G_INTRA_BATCH_DEPENDENCIES.values.flatten - EXPECTED_BATCH_G_IDS
    errors << "Batch G frozen intra-dependency policy contains unknown IDs: #{unknown.uniq.join(', ')}" unless unknown.empty?
    self_edges = BATCH_G_INTRA_BATCH_DEPENDENCIES.select { |source, targets| targets.include?(source) }.keys
    errors << "Batch G frozen intra-dependency policy contains self-dependencies: #{self_edges.join(', ')}" unless self_edges.empty?
    cycle = batch_f_dependency_cycle(BATCH_G_INTRA_BATCH_DEPENDENCIES)
    errors << "Batch G frozen intra-dependency policy contains a cycle: #{cycle.join(' -> ')}" if cycle

    candidate_members = BATCH_G_CONSOLIDATION_GROUPS.values.flatten
    unknown_candidates = candidate_members - EXPECTED_BATCH_G_IDS
    errors << "Batch G frozen consolidation candidates contain unknown IDs: #{unknown_candidates.uniq.join(', ')}" unless unknown_candidates.empty?
    duplicates = candidate_members.group_by(&:itself).select { |_id, values| values.length > 1 }.keys
    errors << "Batch G frozen consolidation candidates overlap: #{duplicates.join(', ')}" unless duplicates.empty?
    unless BATCH_G_CONSOLIDATION_PARAMETER_POLICIES.keys == BATCH_G_CONSOLIDATION_GROUPS.keys
      errors << 'Batch G frozen consolidation semantic policies must cover every candidate exactly once'
    end
    BATCH_G_CONSOLIDATION_GROUPS.each do |candidate, members|
      policy = BATCH_G_CONSOLIDATION_PARAMETER_POLICIES[candidate]
      unless policy.is_a?(Hash) && policy.dig('member_values')&.keys == members
        errors << "Batch G frozen consolidation #{candidate} semantic policy must map every member in manifest order"
      end
    end
  end

  def batch_g_required_authorities(requirement_id)
    return nil unless BATCH_G_ROW_SEMANTIC_TYPE.key?(requirement_id)

    family = batch_g_family_for(requirement_id)
    semantic = batch_g_semantic_spec(requirement_id)
    return nil unless family && semantic

    source_authorities = semantic.fetch(:sources).map { |key| BATCH_G_SOURCE_SPECS.fetch(key).fetch(:authority) }
    [*BATCH_G_FAMILY_POLICY.fetch(family).fetch(:mandatory), *source_authorities].uniq
  end

  def batch_g_semantic_type(requirement_id)
    BATCH_G_ROW_SEMANTIC_TYPE.fetch(requirement_id)
  end

  def batch_g_semantic_spec(requirement_id)
    BATCH_G_ROW_SEMANTIC_SPECS.fetch(requirement_id)
  end

  def batch_g_semantic_status(requirement_id)
    return 'pending_current_standard' if BATCH_G_STATUTORY_IDS.include?(requirement_id) && BATCH_G_PROJECTION_IDS.include?(requirement_id)

    batch_g_semantic_spec(requirement_id).fetch(:status)
  end

  def batch_g_semantic_digest(requirement_id)
    Digest::SHA256.hexdigest(JSON.generate(batch_g_semantic_spec(requirement_id)))
  end

  def batch_g_reconciliation_profile_id(requirement_id)
    "#{batch_g_semantic_type(requirement_id)}:#{requirement_id}"
  end

  def batch_g_control_manifest(entry)
    {
      'requirement_id' => entry['requirement_id'],
      'decision_status' => entry.dig('decision', 'status'),
      'canonical_disposition' => entry.dig('decision', 'canonical_disposition'),
      'definition_sha256' => Digest::SHA256.hexdigest(JSON.generate(entry['definition_contract'])),
      'source_resolution_sha256s' => Array(entry['source_dependencies']).map { |dependency| dependency['resolution_artifact_sha256'] },
      'intra_resolution_sha256s' => Array(entry['intra_batch_dependencies']).map { |dependency| dependency['resolution_artifact_sha256'] },
      'reconciliation_sha256' => entry.dig('reconciliation_contract', 'receipt_artifact_sha256'),
      'output_boundary_sha256' => Digest::SHA256.hexdigest(JSON.generate(entry['output_boundary'])),
      'statutory_definition_sha256' => entry.dig('statutory_definition', 'authority_artifact_sha256')
    }
  end

  def batch_g_control_manifest_sha256(entry)
    Digest::SHA256.hexdigest(JSON.generate(batch_g_control_manifest(entry)))
  end

  def batch_g_expected_source_dependencies(requirement_id)
    batch_g_semantic_spec(requirement_id).fetch(:sources).map do |key|
      source = BATCH_G_SOURCE_SPECS.fetch(key)
      {
        'batch' => source.fetch(:batch),
        'requirement_id' => source.fetch(:id),
        'source_entity' => source.fetch(:entity),
        'source_owner_authority' => source.fetch(:authority)
      }
    end
  end

  def batch_g_definition_contract(requirement_id)
    kind = BATCH_G_CAPABILITY_KINDS.fetch(requirement_id)
    family = batch_g_family_for(requirement_id)
    menu = batch_g_menu_for(requirement_id) || requirement_id
    semantic_type = batch_g_semantic_type(requirement_id)
    semantic = batch_g_semantic_spec(requirement_id)
    source_dependencies = batch_g_expected_source_dependencies(requirement_id)
    report_class, write_semantics = case kind
                                    when 'effective_dated_target_master'
                                      ['target_master', 'append_versioned_target_only_never_actual']
                                    when 'surveillance_reference_master'
                                      ['reference_master', 'append_versioned_reference_only_never_observation']
                                    when 'versioned_statutory_reference'
                                      ['reference_master', 'append_versioned_form_only_never_submission']
                                    else
                                      [kind, 'read_only_projection_no_source_writeback']
                                    end
    semantic_focus = "#{requirement_id}|#{menu}|#{semantic_type}|#{semantic.fetch(:capability_focus)}|#{semantic.fetch(:care_setting)}|#{semantic.fetch(:dimension)}"
    {
      'semantic_status' => batch_g_semantic_status(requirement_id),
      'semantic_digest' => batch_g_semantic_digest(requirement_id),
      'semantic_focus' => semantic_focus,
      'capability_focus' => semantic.fetch(:capability_focus),
      'care_setting' => semantic.fetch(:care_setting),
      'dimension' => semantic.fetch(:dimension),
      'fixed_parameter_values' => semantic.fetch(:fixed_parameter_values),
      'source_roles' => semantic.fetch(:source_roles).map do |role|
        {
          'source_key' => role.fetch(:source_key), 'batch' => role.fetch(:batch),
          'requirement_id' => role.fetch(:requirement_id), 'entity' => role.fetch(:entity),
          'role' => role.fetch(:role)
        }
      end,
      'requires_reconciliation' => semantic.fetch(:reconciliation),
      'purpose' => "#{requirement_id} #{menu}: #{BATCH_G_FAMILY_POLICY.fetch(family).fetch(:description)}",
      'intended_users' => [BATCH_G_FAMILY_POLICY.fetch(family).fetch(:lead), 'authorized_source_domain_owner'],
      'sensitivity' => BATCH_G_STATUTORY_IDS.include?(requirement_id) ? 'restricted_legacy_statutory_simulation' : 'restricted_synthetic_health_information',
      'report_class' => report_class,
      'grain' => semantic.fetch(:grain),
      'distinct_key' => semantic.fetch(:key),
      'numerator' => semantic.fetch(:numerator),
      'denominator' => semantic.fetch(:denominator),
      'inclusions' => semantic.fetch(:inclusions),
      'exclusions' => semantic.fetch(:exclusions),
      'parameters' => semantic.fetch(:parameters),
      'source_fields' => source_dependencies.map { |source| "#{source['source_entity']}.authoritative_id/version/state/effective_at/recorded_at" },
      'transformations' => ["deduplicate #{semantic.fetch(:key)} before aggregation", 'aggregate one-to-many children before joining parent grain', 'preserve unknown/not_collected/not_applicable/unavailable/suppressed separately from numeric_zero'],
      'terminology_version' => 'pending_authority_approved_version',
      'time_basis' => semantic.fetch(:time_basis),
      'timezone' => 'Asia/Jakarta (+07:00)',
      'cutoff_policy' => semantic.fetch(:cutoff_policy),
      'period_close_policy' => semantic.fetch(:period_close_policy),
      'freshness_policy' => 'snapshot_freshness_and_missing_source_age_are_visible',
      'null_policy' => BATCH_G_NULL_TOKENS,
      'suppression_masking_policy' => 'minimum_necessary_fields_role_scope_masking_and_small_cell_suppression',
      'layout_export_policy' => 'local_synthetic_watermarked_export_only_with_reason_digest_and_audit',
      'retention_policy' => 'pending_security_and_records_authority_approval',
      'definition_version' => "proposal-2026-08-25/#{requirement_id}",
      'join_contract' => {
        'allowed_cardinalities' => %w[one_to_one one_to_many many_to_one],
        'dedup_rule' => "count distinct #{semantic.fetch(:key)}; source line counts remain separate",
        'aggregation_rule' => 'aggregate one-to-many children to declared grain before parent join; bridge keys must be unique',
        'unbounded_many_to_many' => false
      },
      'snapshot_policy' => 'definition_sha256+parameter_sha256+source_roots+cutoff deterministically bind immutable output_sha256',
      'correction_restatement_policy' => 'append correction or linked restatement; never overwrite source facts or closed output',
      'incomplete_source_behavior' => 'blocked_or_partial_with_missing_source_diagnostics_and_non_exportable_output',
      'write_semantics' => write_semantics
    }
  end

  def batch_g_required_scenario_contract(requirement_id, name)
    definition = batch_g_definition_contract(requirement_id)
    focus = "#{requirement_id}:#{definition['report_class']}:#{definition['grain']}:#{definition['distinct_key']}"
    description, expected = case name
                            when 'normal'
                              ["Run #{focus} from the frozen synthetic snapshot using safe joins, declared NULL semantics, RBAC, watermarking and independent reconciliation.",
                               ["Deterministic output preserves #{definition['distinct_key']} without join multiplication or source writeback.", 'Independent rooted control equation equals zero and view/run/export audit events are immutable.']]
                            when 'denial'
                              ["Deny an unauthorized cohort, direct-identifier export, watermark removal, invalid parameter or ambiguous duplicate run for #{focus}.",
                               ['No output is exported or transmitted; denial reason and attempted scope are audited.', 'The same idempotency key and payload returns one result; a different payload is rejected and ambiguous acknowledgement is quarantined.']]
                            when 'correction_or_amendment'
                              ["Apply a source correction, definition amendment or late event to #{focus} after period close without editing prior source or report versions.",
                               ['A linked append-only restatement records prior output digest, reason, cutoff and changed source roots.', 'Prior closed output remains reproducible and unknown, unavailable, suppressed and numeric zero remain distinct.']]
                            when 'dependency_outage'
                              ["Remove one required source or authority-approved definition while executing #{focus} and expose the dependency failure.",
                               ['Run is blocked or explicitly partial with missing-source diagnostics and a non-exportable watermarked output.', 'No silent omission, null-to-zero coercion, compliance claim, source writeback or outbound transmission occurs.']]
                            else
                              ['', []]
                            end
    {
      'description' => description,
      'expected_results' => expected,
      'contract_ref' => Digest::SHA256.hexdigest(JSON.generate([requirement_id, name, definition]))
    }
  end

  def batch_g_expected_boundary(requirement_id)
    {
      'mode' => BATCH_G_PROJECTION_IDS.include?(requirement_id) ? 'local_synthetic_projection' : 'versioned_synthetic_master',
      'endpoint' => nil,
      'credential_state' => 'absent',
      'outbound_network' => false,
      'delivery_state' => 'NOT_SENT',
      'export_mode' => 'local_synthetic_watermarked_only',
      'watermark' => 'SIMULASI - DATA SINTETIS - NOT_SENT',
      'audit_events' => %w[view run export denial],
      'retention_policy' => 'pending_security_and_records_authority_approval',
      'prohibited_targets' => BATCH_G_PROHIBITED_TARGETS,
      'transmission_claim' => 'legacy_simulation_only_not_submitted'
    }
  end

  def batch_g_candidate_for(requirement_id)
    BATCH_G_CONSOLIDATION_GROUPS.find { |_candidate, members| members.include?(requirement_id) }&.first
  end

  def batch_g_expected_consolidation_member_mapping(requirement_id)
    entry = @decision_entries.find { |candidate| candidate['batch'] == 'G' && candidate['requirement_id'] == requirement_id }
    {
      'semantic_parameter_values' => BATCH_G_CONSOLIDATION_PARAMETER_POLICIES.fetch(batch_g_candidate_for(requirement_id)).fetch('member_values').fetch(requirement_id),
      'definition_sha256' => Digest::SHA256.hexdigest(JSON.generate(batch_g_definition_contract(requirement_id))),
      'source_requirement_ids' => batch_g_expected_source_dependencies(requirement_id).map { |source| source['requirement_id'] },
      'mapped_control_totals' => batch_g_semantic_spec(requirement_id).fetch(:reconciliation) ? BATCH_G_CONTROL_TOTALS : [],
      'authority_domains' => batch_g_required_authorities(requirement_id),
      'approval_reference' => entry&.dig('approval', 'reference'),
      'approval_sha256' => entry&.dig('approval', 'artifact_sha256')
    }
  end

  def validate_batch_g_controls(entry, label)
    prefix = "#{decision_register_label} #{label}"
    validate_closed_object(entry, BATCH_G_ENTRY_KEYS, prefix)
    return unless EXPECTED_BATCH_G_IDS.include?(label)

    family = batch_g_family_for(label)
    errors << "#{prefix}: family_id must be #{family}" unless entry['family_id'] == family
    errors << "#{prefix}: availability_state must remain Soon" unless entry['availability_state'] == 'Soon'
    errors << "#{prefix}: capability_kind must be #{BATCH_G_CAPABILITY_KINDS.fetch(label)}" unless entry['capability_kind'] == BATCH_G_CAPABILITY_KINDS.fetch(label)
    errors << "#{prefix}: legacy_menu must exactly match the frozen parity matrix label" unless entry['legacy_menu'] == batch_g_menu_for(label)
    Array(entry['evidence']).each_with_index { |record, index| validate_closed_object(record, BATCH_F_EVIDENCE_RECORD_KEYS, "#{prefix}: evidence[#{index}]") if record.is_a?(Hash) }
    validate_closed_object(entry['decision'], BATCH_F_DECISION_KEYS, "#{prefix}: decision") if entry['decision'].is_a?(Hash)
    validate_closed_object(entry.dig('decision', 'target'), BATCH_F_TARGET_KEYS, "#{prefix}: decision target") if entry.dig('decision', 'target').is_a?(Hash)
    validate_closed_object(entry['accountable_owner'], BATCH_F_OWNER_KEYS, "#{prefix}: accountable_owner") if entry['accountable_owner'].is_a?(Hash)
    Array(entry['appointment_dependencies']).each_with_index { |record, index| validate_closed_object(record, BATCH_F_APPOINTMENT_KEYS, "#{prefix}: appointment_dependencies[#{index}]") if record.is_a?(Hash) }
    validate_closed_object(entry['approval'], BATCH_F_APPROVAL_KEYS, "#{prefix}: approval") if entry['approval'].is_a?(Hash)
    expected_authorities = batch_g_required_authorities(label)
    errors << "#{prefix}: affected_domains must exactly match every reporting and source authority" unless entry['affected_domains'] == expected_authorities
    appointed = Array(entry['appointment_dependencies']).select { |appointment| appointment.is_a?(Hash) && appointment['status'] == 'appointed' }
    duplicate_identities = appointed.map { |appointment| appointment['identity'] }.compact.group_by(&:itself).select { |_identity, values| values.length > 1 }.keys
    errors << "#{prefix}: appointed authority identities must be unique across Batch G domains" unless duplicate_identities.empty?
    product_identity = appointed.find { |appointment| appointment['authority_domain'] == 'product_delivery' }&.dig('identity')
    if nonempty_string?(product_identity) && product_identity == entry.dig('accountable_owner', 'identity')
      errors << "#{prefix}: product_delivery identity cannot substitute for the non-product accountable lead"
    end
    errors << "#{prefix}: definition_contract must exactly match the frozen per-ID report/master semantics" unless entry['definition_contract'] == batch_g_definition_contract(label)
    if BATCH_G_PROJECTION_IDS.include?(label) && entry.dig('definition_contract', 'write_semantics') != 'read_only_projection_no_source_writeback'
      errors << "#{prefix}: report projection must never write source facts"
    end
    if label == 'PAR-ADM-007' && entry.dig('definition_contract', 'write_semantics') != 'append_versioned_target_only_never_actual'
      errors << "#{prefix}: management target master must never be represented as actual outcome"
    end
    validate_batch_g_source_dependencies(entry['source_dependencies'], label)
    validate_batch_g_intra_dependencies(entry['intra_batch_dependencies'], label)
    validate_batch_g_boundary(entry['output_boundary'], label)
    validate_batch_g_reconciliation(entry['reconciliation_contract'], label)
    validate_batch_g_statutory(entry['statutory_definition'], entry, label)
    validate_batch_g_consolidation(entry['consolidation_mapping'], entry['decision'], label)
    validate_batch_g_decision_readiness(entry, label)
  end

  def validate_batch_g_decision_readiness(entry, requirement_id)
    decision = entry['decision']
    return unless decision.is_a?(Hash) && %w[approve defer].include?(decision['status'])

    prefix = "#{decision_register_label} #{requirement_id}"
    evidence = entry['evidence']
    substantive = evidence.is_a?(Array) && evidence.any? do |record|
      record.is_a?(Hash) && %w[O M I].include?(record['evidence_class']) && BATCH_G_EVIDENCE_BASES[0..3].include?(record['evidence_basis'])
    end
    errors << "#{prefix}: recorded Batch G decision requires substantive O/M/I evidence" unless substantive
    scenarios = entry['synthetic_scenarios']
    errors << "#{prefix}: recorded Batch G decision requires all four frozen scenarios ready" unless scenarios.is_a?(Hash) && FOUR_SCENARIO_NAMES.all? { |name| scenarios.dig(name, 'status') == 'ready' }
    appointments = entry['appointment_dependencies']
    errors << "#{prefix}: recorded Batch G decision requires every reporting and source authority appointed" unless appointments.is_a?(Array) && appointments.all? { |appointment| appointment.is_a?(Hash) && appointment['status'] == 'appointed' }

    sources = entry['source_dependencies']
    intra = entry['intra_batch_dependencies']
    if decision['status'] == 'approve'
      semantic_status = batch_g_semantic_status(requirement_id)
      semantic_type = batch_g_semantic_type(requirement_id)
      if semantic_status == 'unresolved_owner_definition' || %w[unresolved_variant statutory_indicator_unresolved].include?(semantic_type)
        errors << "#{prefix}: unresolved legacy/variant/statutory semantics cannot be approved reproduce/replace until the frozen per-ID semantic spec itself is authority-resolved"
      elsif semantic_status == 'defined_count_denominator_unresolved'
        errors << "#{prefix}: defined count with unresolved denominator cannot be approved reproduce/replace until the denominator population and rate semantics are authority-resolved"
      elsif semantic_status == 'pending_current_standard' && entry.dig('statutory_definition', 'status') != 'complete'
        errors << "#{prefix}: pending current-standard semantics cannot be approved reproduce/replace without an exact signed report definition"
      end
      errors << "#{prefix}: approval requires every exact A-F source resolved" unless sources.is_a?(Array) && !sources.empty? && sources.all? { |dependency| dependency.is_a?(Hash) && dependency['status'] == 'resolved' }
      errors << "#{prefix}: approval requires every intra-G dependency resolved" unless intra.is_a?(Array) && intra.all? { |dependency| dependency.is_a?(Hash) && dependency['status'] == 'resolved' }
      expected_reconciliation_status = batch_g_semantic_spec(requirement_id).fetch(:reconciliation) ? 'complete' : 'not_applicable'
      errors << "#{prefix}: approval requires the exact reconciliation applicability state #{expected_reconciliation_status}" unless entry.dig('reconciliation_contract', 'status') == expected_reconciliation_status
      if BATCH_G_STATUTORY_IDS.include?(requirement_id)
        errors << "#{prefix}: statutory/public-health approval requires a complete current signed definition" unless entry.dig('statutory_definition', 'status') == 'complete'
      end
    else
      pending_sources = Array(sources).select { |dependency| dependency.is_a?(Hash) && dependency['status'] != 'resolved' }
      pending_intra = Array(intra).select { |dependency| dependency.is_a?(Hash) && dependency['status'] != 'resolved' }
      expected_exclusions = [
        *BATCH_G_DEFERRAL_BASE_EXCLUSIONS,
        *pending_sources.map { |dependency| "missing_source:#{dependency['batch']}:#{dependency['requirement_id']}" },
        *pending_intra.map { |dependency| "missing_intra_g:#{dependency['requirement_id']}" }
      ]
      safe = decision['canonical_disposition'] == 'exclude' && decision.dig('target', 'kind') == 'exclusion' && decision.dig('target', 'exclusions') == expected_exclusions
      errors << "#{prefix}: deferral must exactly exclude report/export/compliance/transmission and every unresolved source" unless safe
    end
  end

  def validate_batch_g_source_dependencies(dependencies, requirement_id)
    prefix = "#{decision_register_label} #{requirement_id}: source_dependencies"
    unless dependencies.is_a?(Array)
      errors << "#{prefix} must be an array"
      return
    end
    expected = batch_g_expected_source_dependencies(requirement_id)
    actual = dependencies.each_with_object([]) { |dependency, values| values << dependency.slice('batch', 'requirement_id', 'source_entity', 'source_owner_authority') if dependency.is_a?(Hash) }
    errors << "#{prefix} must exactly bind the applicability-specific approved A-F source lineage" unless actual == expected
    dependencies.each_with_index do |dependency, index|
      label = "#{prefix}[#{index}]"
      validate_closed_object(dependency, BATCH_G_SOURCE_DEPENDENCY_KEYS, label)
      next unless dependency.is_a?(Hash)

      status = dependency['status']
      errors << "#{label} status must be pending or resolved" unless %w[pending resolved].include?(status)
      if status == 'pending'
        %w[resolution_reference resolution_artifact_sha256].each { |key| errors << "#{label} pending #{key} must be null" unless dependency[key].nil? }
      elsif status == 'resolved'
        validate_batch_g_source_resolution_artifact(dependency, requirement_id, label)
      end
    end
  end

  def validate_batch_g_source_resolution_artifact(dependency, requirement_id, label)
    artifact = load_structured_json_artifact(dependency['resolution_reference'], dependency['resolution_artifact_sha256'], "#{label} resolution")
    return unless artifact

    artifact_label = "#{label} resolution"
    validate_closed_object(artifact, BATCH_G_SOURCE_ARTIFACT_KEYS, artifact_label)
    errors << "#{artifact_label} artifact_type/schema_version/register_id must match Batch G" unless artifact['artifact_type'] == BATCH_G_SOURCE_ARTIFACT_TYPE && artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION && artifact['register_id'] == BATCH_G_REGISTER_ID
    errors << "#{artifact_label} requirement_id/subject/status must bind this source dependency" unless artifact['requirement_id'] == requirement_id && artifact['subject'] == 'source_dependency' && artifact['status'] == 'resolved'
    %w[batch requirement_id source_entity source_owner_authority].zip(%w[source_batch source_requirement_id source_entity source_owner_authority]).each do |dependency_key, artifact_key|
      errors << "#{artifact_label} #{artifact_key} does not match the register dependency" unless artifact[artifact_key] == dependency[dependency_key]
    end
    source_batch = dependency['batch']
    source_config = DECISION_REGISTER_CONFIGS[source_batch]
    source_path = @decision_register_paths[source_batch]
    source_sha = Digest::SHA256.file(source_path).hexdigest if source_path && File.file?(source_path)
    errors << "#{artifact_label} source_register_id must bind Batch #{source_batch}" unless source_config && artifact['source_register_id'] == source_config[:register_id]
    errors << "#{artifact_label} source_register_sha256 must match the loaded Batch #{source_batch} register" unless source_sha && artifact['source_register_sha256'] == source_sha
    unless @decision_register_statuses[source_batch] == 'complete'
      errors << "#{artifact_label} cannot resolve while Batch #{source_batch} register_status is not complete"
    end
    source_entry = @decision_entries_by_batch.fetch(source_batch, []).find { |entry| entry['requirement_id'] == dependency['requirement_id'] }
    decision = source_entry && source_entry['decision']
    unless decision.is_a?(Hash) && decision['status'] == 'approve' && %w[reproduce replace].include?(decision['canonical_disposition'])
      errors << "#{artifact_label} source requirement must be approved reproduce/replace; defer/exclude cannot support a complete report"
    end
    source_authorities = Array(source_entry && source_entry['co_owners'])
    errors << "#{artifact_label} source_owner_authority is not an affected authority on the source row" unless source_authorities.include?(dependency['source_owner_authority'])
    owner = source_entry && source_entry['accountable_owner']
    authority_appointment = Array(source_entry && source_entry['appointment_dependencies']).find do |appointment|
      appointment.is_a?(Hash) && appointment['authority_domain'] == dependency['source_owner_authority'] && appointment['status'] == 'appointed'
    end
    appointed_authority_identity = if owner.is_a?(Hash) && owner['appointment_status'] == 'appointed' && owner['authority_domain'] == dependency['source_owner_authority']
                                     owner['identity']
                                   else
                                     authority_appointment && authority_appointment['identity']
                                   end
    unless nonempty_string?(appointed_authority_identity) && artifact['source_owner_identity'] == appointed_authority_identity
      errors << "#{artifact_label} source_owner_identity must bind the appointed exact source-domain authority; product or another domain cannot substitute"
    end
    approval = source_entry && source_entry['approval']
    unless approval.is_a?(Hash) && approval['status'] == 'recorded' && artifact['source_approval_reference'] == approval['reference'] && artifact['source_approval_sha256'] == approval['artifact_sha256']
      errors << "#{artifact_label} source approval reference/SHA must bind the recorded source approval"
    end
    errors << "#{artifact_label} date must be YYYY-MM-DD" unless iso_date?(artifact['date'])
    validate_artifact_reviewer(artifact['reviewer'], artifact['source_owner_identity'], artifact_label)
  end

  def validate_batch_g_intra_dependencies(dependencies, requirement_id)
    prefix = "#{decision_register_label} #{requirement_id}: intra_batch_dependencies"
    unless dependencies.is_a?(Array)
      errors << "#{prefix} must be an array"
      return
    end
    expected_ids = BATCH_G_INTRA_BATCH_DEPENDENCIES.fetch(requirement_id)
    actual_ids = dependencies.each_with_object([]) { |dependency, values| values << dependency['requirement_id'] if dependency.is_a?(Hash) }
    errors << "#{prefix} must exactly match the frozen intra-G DAG" unless actual_ids == expected_ids
    dependencies.each_with_index do |dependency, index|
      label = "#{prefix}[#{index}]"
      validate_closed_object(dependency, BATCH_G_INTRA_DEPENDENCY_KEYS, label)
      next unless dependency.is_a?(Hash)

      status = dependency['status']
      errors << "#{label} status must be pending or resolved" unless %w[pending resolved].include?(status)
      if status == 'pending'
        %w[resolution_reference resolution_artifact_sha256].each { |key| errors << "#{label} pending #{key} must be null" unless dependency[key].nil? }
      elsif status == 'resolved'
        validate_batch_g_intra_artifact(dependency, requirement_id, label)
      end
    end
  end

  def validate_batch_g_intra_artifact(dependency, source_id, label)
    artifact = load_structured_json_artifact(dependency['resolution_reference'], dependency['resolution_artifact_sha256'], "#{label} resolution")
    return unless artifact

    artifact_label = "#{label} resolution"
    validate_closed_object(artifact, BATCH_G_INTRA_ARTIFACT_KEYS, artifact_label)
    errors << "#{artifact_label} type/schema/register must match Batch G" unless artifact['artifact_type'] == BATCH_G_INTRA_ARTIFACT_TYPE && artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION && artifact['register_id'] == BATCH_G_REGISTER_ID
    errors << "#{artifact_label} source/target/status must match the register edge" unless artifact['source_requirement_id'] == source_id && artifact['target_requirement_id'] == dependency['requirement_id'] && artifact['status'] == 'resolved'
    target = @decision_entries.find { |entry| entry['batch'] == 'G' && entry['requirement_id'] == dependency['requirement_id'] }
    decision = target && target['decision']
    unless decision.is_a?(Hash) && decision['status'] == 'approve' && %w[reproduce replace].include?(decision['canonical_disposition'])
      errors << "#{artifact_label} target must be approved reproduce/replace"
    end
    errors << "#{artifact_label} target decision fields must bind the target" unless decision.is_a?(Hash) && artifact['target_decision_status'] == decision['status'] && artifact['target_disposition'] == decision['canonical_disposition']
    owner = target && target['accountable_owner']
    unless owner.is_a?(Hash) && owner['appointment_status'] == 'appointed' && artifact['target_owner_identity'] == owner['identity'] && artifact['target_authority_domain'] == target['lead_authority_domain']
      errors << "#{artifact_label} target owner/domain must bind its appointed lead"
    end
    approval = target && target['approval']
    unless approval.is_a?(Hash) && approval['status'] == 'recorded' && artifact['target_approval_reference'] == approval['reference'] && artifact['target_approval_sha256'] == approval['artifact_sha256']
      errors << "#{artifact_label} target approval reference/SHA must bind the recorded approval"
    end
    errors << "#{artifact_label} date must be YYYY-MM-DD" unless iso_date?(artifact['date'])
    validate_artifact_reviewer(artifact['reviewer'], artifact['target_owner_identity'], artifact_label)
  end

  def validate_batch_g_boundary(boundary, requirement_id)
    label = "#{decision_register_label} #{requirement_id}: output_boundary"
    validate_closed_object(boundary, BATCH_G_BOUNDARY_KEYS, label)
    return unless boundary.is_a?(Hash)

    errors << "#{label} must exactly match the synthetic local NOT_SENT boundary" unless boundary == batch_g_expected_boundary(requirement_id)
    errors << "#{label} endpoint must be null, credentials absent, outbound false, delivery NOT_SENT" unless boundary['endpoint'].nil? && boundary['credential_state'] == 'absent' && boundary['outbound_network'] == false && boundary['delivery_state'] == 'NOT_SENT'
  end

  def validate_batch_g_reconciliation(control, requirement_id)
    label = "#{decision_register_label} #{requirement_id}: reconciliation_contract"
    validate_closed_object(control, BATCH_G_RECONCILIATION_KEYS, label)
    return unless control.is_a?(Hash)

    errors << "#{label} profile_id must bind the exact per-ID semantic profile" unless control['profile_id'] == batch_g_reconciliation_profile_id(requirement_id)
    requires_reconciliation = batch_g_semantic_spec(requirement_id).fetch(:reconciliation)
    allowed_statuses = requires_reconciliation ? %w[pending complete] : %w[not_applicable]
    errors << "#{label} status must be #{allowed_statuses.join(' or ')}" unless allowed_statuses.include?(control['status'])
    if control['status'] == 'not_applicable'
      errors << "#{label} master/reference capability must not claim a report reconciliation equation" unless control['equation'].nil? && control['control_totals'] == [] && control['receipt_reference'].nil? && control['receipt_artifact_sha256'].nil?
    else
      errors << "#{label} equation must be the frozen independently recomputed equation" unless control['equation'] == BATCH_G_RECONCILIATION_EQUATION
      errors << "#{label} control_totals must exactly match the frozen control set" unless control['control_totals'] == BATCH_G_CONTROL_TOTALS
    end
    if control['status'] == 'pending'
      errors << "#{label} pending receipt_reference must be null" unless control['receipt_reference'].nil?
      errors << "#{label} pending receipt_artifact_sha256 must be null" unless control['receipt_artifact_sha256'].nil?
    elsif control['status'] == 'complete'
      validate_batch_g_reconciliation_artifact(control, requirement_id, label)
    end
  end

  def validate_batch_g_statutory(control, entry, requirement_id)
    label = "#{decision_register_label} #{requirement_id}: statutory_definition"
    validate_closed_object(control, BATCH_G_STATUTORY_KEYS, label)
    return unless control.is_a?(Hash)

    statutory = BATCH_G_STATUTORY_IDS.include?(requirement_id)
    expected_statuses = statutory ? %w[pending complete] : %w[not_applicable]
    errors << "#{label} status must be #{expected_statuses.join(' or ')}" unless expected_statuses.include?(control['status'])
    errors << "#{label} legacy_simulation_only must be #{statutory}" unless control['legacy_simulation_only'] == statutory
    if control['status'] == 'pending'
      %w[standard_identifier standard_version effective_date definition_source authority_reference authority_artifact_sha256].each { |key| errors << "#{label} pending #{key} must be null" unless control[key].nil? }
      decision = entry['decision']
      if decision.is_a?(Hash) && decision['status'] == 'approve' && %w[reproduce replace].include?(decision['canonical_disposition'])
        errors << "#{label} legacy statutory/public-health capability cannot be approved without a current signed definition"
      end
    elsif control['status'] == 'not_applicable'
      %w[standard_identifier standard_version effective_date definition_source authority_reference authority_artifact_sha256].each { |key| errors << "#{label} non-statutory #{key} must be null" unless control[key].nil? }
    elsif control['status'] == 'complete'
      validate_batch_g_statutory_artifact(control, entry, requirement_id, label)
    end
  end

  def validate_batch_g_consolidation(control, decision, requirement_id)
    label = "#{decision_register_label} #{requirement_id}: consolidation_mapping"
    validate_closed_object(control, BATCH_G_CONSOLIDATION_KEYS, label)
    return unless control.is_a?(Hash)

    candidate = batch_g_candidate_for(requirement_id)
    errors << "#{label} candidate_id must be #{candidate.inspect}" unless control['candidate_id'] == candidate
    allowed_statuses = candidate ? %w[pending complete] : %w[not_applicable]
    errors << "#{label} status must be #{allowed_statuses.join(' or ')}" unless allowed_statuses.include?(control['status'])
    if %w[pending not_applicable].include?(control['status'])
      %w[terminal_target_requirement_id artifact_reference artifact_sha256].each { |key| errors << "#{label} #{key} must be null before mapping completion" unless control[key].nil? }
    elsif control['status'] == 'complete'
      validate_batch_g_consolidation_artifact(control, requirement_id, candidate, label)
    end
    if decision.is_a?(Hash) && %w[approve defer].include?(decision['status']) && decision['canonical_disposition'] == 'consolidate'
      errors << "#{label} consolidation requires a complete frozen candidate mapping" unless candidate && control['status'] == 'complete'
    end
  end

  def validate_batch_g_reconciliation_artifact(control, requirement_id, label)
    artifact = load_structured_json_artifact(control['receipt_reference'], control['receipt_artifact_sha256'], "#{label} receipt")
    return unless artifact

    artifact_label = "#{label} receipt"
    validate_closed_object(artifact, BATCH_G_RECONCILIATION_ARTIFACT_KEYS, artifact_label)
    errors << "#{artifact_label} type/schema/register/requirement must bind Batch G" unless artifact['artifact_type'] == BATCH_G_RECONCILIATION_ARTIFACT_TYPE && artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION && artifact['register_id'] == BATCH_G_REGISTER_ID && artifact['requirement_id'] == requirement_id
    definition_sha = Digest::SHA256.hexdigest(JSON.generate(batch_g_definition_contract(requirement_id)))
    errors << "#{artifact_label} definition_sha256 must bind the frozen per-ID definition" unless artifact['definition_sha256'] == definition_sha
    parameter_artifact = validate_batch_g_parameter_artifact(artifact['parameter_descriptor'], requirement_id, "#{artifact_label} parameter_descriptor")
    parameter_sha = parameter_artifact && parameter_artifact['canonical_parameter_sha256']
    errors << "#{artifact_label} snapshot_id must be a non-placeholder synthetic snapshot" unless nonempty_string?(artifact['snapshot_id']) && artifact['snapshot_id'].start_with?('SYN-G-SNAPSHOT-')
    errors << "#{artifact_label} cutoff_at must use +07:00" unless nonempty_string?(artifact['cutoff_at']) && artifact['cutoff_at'].end_with?('+07:00')
    errors << "#{artifact_label} period_state must be open, closed or restated" unless %w[open closed restated].include?(artifact['period_state'])
    prior_output = nil
    if artifact['period_state'] == 'restated'
      prior_output = load_structured_json_artifact(artifact['prior_output_reference'], artifact['prior_output_sha256'], "#{artifact_label} prior_output")
      errors << "#{artifact_label} restatement_reason must be substantive" unless nonempty_string?(artifact['restatement_reason']) && artifact['restatement_reason'].length >= 20
      if prior_output
        validate_closed_object(prior_output, BATCH_G_OUTPUT_KEYS, "#{artifact_label} prior_output")
        errors << "#{artifact_label} prior output must bind the same requirement" unless prior_output['requirement_id'] == requirement_id
        prior_rows_sha = Digest::SHA256.hexdigest(JSON.generate(prior_output['rows'])) if prior_output['rows'].is_a?(Array)
        errors << "#{artifact_label} prior output digest must remain independently recomputable and watermarked" unless prior_rows_sha && prior_output['output_sha256'] == prior_rows_sha && prior_output['watermark'] == batch_g_expected_boundary(requirement_id)['watermark']
      end
    else
      errors << "#{artifact_label} non-restatement prior_output_reference must be null" unless artifact['prior_output_reference'].nil?
      errors << "#{artifact_label} non-restatement prior_output_sha256 must be null" unless artifact['prior_output_sha256'].nil?
      errors << "#{artifact_label} non-restatement restatement_reason must be null" unless artifact['restatement_reason'].nil?
    end
    expected_sources = batch_g_expected_source_dependencies(requirement_id)
    descriptors = artifact['source_exports']
    actual_sources = Array(descriptors).each_with_object([]) { |descriptor, values| values << descriptor.slice('source_batch', 'source_requirement_id', 'source_entity') if descriptor.is_a?(Hash) }
    expected_source_ids = expected_sources.map { |source| { 'source_batch' => source['batch'], 'source_requirement_id' => source['requirement_id'], 'source_entity' => source['source_entity'] } }
    errors << "#{artifact_label} source_exports must bind every applicability-specific source exactly once" unless descriptors.is_a?(Array) && actual_sources == expected_source_ids
    source_authors = []
    loaded_source_exports = []
    Array(descriptors).each_with_index do |descriptor, index|
      descriptor_label = "#{artifact_label} source_exports[#{index}]"
      validate_closed_object(descriptor, BATCH_G_EXPORT_DESCRIPTOR_KEYS, descriptor_label)
      next unless descriptor.is_a?(Hash)

      expected = expected_sources[index]
      expected_role = batch_g_semantic_spec(requirement_id).fetch(:source_roles).fetch(index).fetch(:role)
      errors << "#{descriptor_label} control_role must be #{expected_role}" unless descriptor['control_role'] == expected_role
      source_export = load_structured_json_artifact(descriptor['reference'], descriptor['sha256'], descriptor_label)
      next unless source_export
      validate_closed_object(source_export, BATCH_G_SOURCE_EXPORT_KEYS, descriptor_label)
      errors << "#{descriptor_label} type/schema/register/requirement must bind Batch G" unless source_export['artifact_type'] == BATCH_G_SOURCE_EXPORT_ARTIFACT_TYPE && source_export['schema_version'] == ARTIFACT_SCHEMA_VERSION && source_export['register_id'] == BATCH_G_REGISTER_ID && source_export['requirement_id'] == requirement_id
      if expected
        errors << "#{descriptor_label} source lineage fields must match the descriptor and register" unless source_export['source_batch'] == expected['batch'] && source_export['source_requirement_id'] == expected['requirement_id'] && source_export['source_entity'] == expected['source_entity']
      end
      errors << "#{descriptor_label} control_role/snapshot must match the receipt" unless source_export['control_role'] == descriptor['control_role'] && source_export['snapshot_id'] == artifact['snapshot_id']
      rows_value = source_export['rows']
      valid_rows = rows_value.is_a?(Array) && !rows_value.empty? && rows_value.all? do |row|
        validate_closed_object(row, BATCH_G_SYNTHETIC_ROW_KEYS, "#{descriptor_label} row")
        row.is_a?(Hash) && nonempty_string?(row['synthetic_id']) && row['synthetic_id'].start_with?('SYN-') && nonempty_string?(row['distinct_key']) && row['distinct_key'].start_with?('SYN-') &&
          row['value'].is_a?(Integer) && BATCH_G_VALUE_STATES.include?(row['state']) &&
          (row['state'] == 'known_numeric' || row['value'].zero?) && [true, false].include?(row['included']) && nonempty_string?(row['source_version'])
      end
      errors << "#{descriptor_label} rows must be non-empty closed synthetic rows with explicit NULL state" unless valid_rows
      computed_root = Digest::SHA256.hexdigest(JSON.generate(rows_value)) if rows_value.is_a?(Array)
      errors << "#{descriptor_label} source_root_sha256 must be independently recomputable from canonical rows" unless computed_root && source_export['source_root_sha256'] == computed_root
      errors << "#{descriptor_label} canonical_export_id must be non-placeholder" unless nonempty_string?(source_export['canonical_export_id'])
      errors << "#{descriptor_label} date must be YYYY-MM-DD" unless iso_date?(source_export['date'])
      errors << "#{descriptor_label} author_identity must be non-placeholder" unless nonempty_string?(source_export['author_identity']) && !source_export['author_identity'].match?(PLACEHOLDER_OWNER_PATTERN)
      validate_artifact_reviewer(source_export['reviewer'], source_export['author_identity'], descriptor_label)
      source_authors << source_export['author_identity'] if nonempty_string?(source_export['author_identity'])
      loaded_source_exports << source_export
    end

    output_descriptor = artifact['report_output']
    validate_closed_object(output_descriptor, BATCH_G_OUTPUT_DESCRIPTOR_KEYS, "#{artifact_label} report_output")
    output = output_descriptor.is_a?(Hash) ? load_structured_json_artifact(output_descriptor['reference'], output_descriptor['sha256'], "#{artifact_label} report_output") : nil
    if output
      validate_closed_object(output, BATCH_G_OUTPUT_KEYS, "#{artifact_label} report_output")
      errors << "#{artifact_label} output type/schema/register/requirement must bind Batch G" unless output['artifact_type'] == BATCH_G_OUTPUT_ARTIFACT_TYPE && output['schema_version'] == ARTIFACT_SCHEMA_VERSION && output['register_id'] == BATCH_G_REGISTER_ID && output['requirement_id'] == requirement_id
      errors << "#{artifact_label} output snapshot/definition/parameters must match rooted receipt artifacts" unless output['snapshot_id'] == artifact['snapshot_id'] && output['definition_sha256'] == artifact['definition_sha256'] && output['parameter_sha256'] == parameter_sha
      errors << "#{artifact_label} output watermark must match local synthetic boundary" unless output['watermark'] == batch_g_expected_boundary(requirement_id)['watermark']
      errors << "#{artifact_label} output must be exportable only when no source is missing" unless [true, false].include?(output['exportable'])
      errors << "#{artifact_label} output access_scope must be a nonempty closed cohort/role scope without wildcard" unless output['access_scope'].is_a?(Array) && !output['access_scope'].empty? && output['access_scope'].all? { |scope| nonempty_string?(scope) && scope != '*' }
      errors << "#{artifact_label} output export_reason must be non-placeholder" unless nonempty_string?(output['export_reason'])
      audit_ids = output['audit_event_ids']
      errors << "#{artifact_label} output audit_event_ids must bind view/run/export audit events" unless audit_ids.is_a?(Hash) && audit_ids.keys == %w[view run export] && audit_ids.values.all? { |value| nonempty_string?(value) }
      errors << "#{artifact_label} output retention_class must remain synthetic governance evidence pending policy" unless output['retention_class'] == 'synthetic_governance_evidence_pending_policy'
      errors << "#{artifact_label} output masked and small_cell_suppression_applied must be explicit booleans" unless [true, false].include?(output['masked']) && [true, false].include?(output['small_cell_suppression_applied'])
      output_rows_valid = output['rows'].is_a?(Array) && !output['rows'].empty? && output['rows'].all? do |row|
        validate_closed_object(row, BATCH_G_SYNTHETIC_ROW_KEYS, "#{artifact_label} report_output row")
        row.is_a?(Hash) && nonempty_string?(row['synthetic_id']) && row['synthetic_id'].start_with?('SYN-') && nonempty_string?(row['distinct_key']) && row['distinct_key'].start_with?('SYN-') &&
          row['value'].is_a?(Integer) && BATCH_G_VALUE_STATES.include?(row['state']) &&
          (row['state'] == 'known_numeric' || row['value'].zero?) && [true, false].include?(row['included']) && nonempty_string?(row['source_version'])
      end
      errors << "#{artifact_label} output rows must be non-empty closed synthetic rows" unless output_rows_valid
      computed_output = Digest::SHA256.hexdigest(JSON.generate(output['rows'])) if output['rows'].is_a?(Array)
      errors << "#{artifact_label} output_sha256 must be independently recomputable from canonical rows" unless computed_output && output['output_sha256'] == computed_output
      errors << "#{artifact_label} output date must be YYYY-MM-DD" unless iso_date?(output['date'])
      validate_artifact_reviewer(output['reviewer'], output['author_identity'], "#{artifact_label} report_output")
    end
    if prior_output && output
      errors << "#{artifact_label} restatement must create a new immutable output digest" unless prior_output['output_sha256'] != output['output_sha256']
    end
    validate_batch_g_rerun_artifact(artifact['rerun_receipt'], requirement_id, artifact, loaded_source_exports, output, parameter_sha, "#{artifact_label} rerun_receipt")
    access_artifact = validate_batch_g_access_artifact(artifact['access_receipt'], requirement_id, output, "#{artifact_label} access_receipt")
    validate_batch_g_audit_artifact(artifact['audit_receipt'], requirement_id, output, access_artifact, "#{artifact_label} audit_receipt")
    values = artifact['control_values']
    valid_values = values.is_a?(Hash) && values.keys == BATCH_G_CONTROL_TOTALS && values.values.all? { |value| value.is_a?(Integer) && value >= 0 }
    errors << "#{artifact_label} control_values must be exact nonnegative integer rooted totals" unless valid_values
    if valid_values
      expected_difference = values['report_total'] - values['authoritative_source_total'] + values['documented_exclusion_total'] - values['approved_adjustment_total']
      errors << "#{artifact_label} equation must match the frozen reconciliation equation" unless artifact['equation'] == BATCH_G_RECONCILIATION_EQUATION
      errors << "#{artifact_label} difference must be independently recomputed and zero" unless artifact['difference'] == expected_difference && expected_difference.zero?
      errors << "#{artifact_label} duplicate_join_count, missing_source_count and null_state_count must be zero for a complete/exportable receipt" unless values['duplicate_join_count'].zero? && values['missing_source_count'].zero? && values['null_state_count'].zero?
      lineage_valid = artifact['sampled_lineage'].is_a?(Array) && artifact['sampled_lineage'].length == values['traced_sample_count'] && values['traced_sample_count'].positive? && artifact['sampled_lineage'].all? do |sample|
        validate_closed_object(sample, BATCH_G_LINEAGE_SAMPLE_KEYS, "#{artifact_label} sampled_lineage")
        next false unless sample.is_a?(Hash)

        source_export = loaded_source_exports.find do |candidate|
          candidate['source_batch'] == sample['source_batch'] && candidate['source_requirement_id'] == sample['source_requirement_id'] && candidate['source_root_sha256'] == sample['source_root_sha256']
        end
        source_key_exists = source_export && Array(source_export['rows']).any? { |row| row['distinct_key'] == sample['source_distinct_key'] }
        report_key_exists = output && Array(output['rows']).any? { |row| row['distinct_key'] == sample['report_distinct_key'] }
        source_key_exists && report_key_exists
      end
      errors << "#{artifact_label} sampled lineage must positively trace rooted source keys to rooted report keys" unless lineage_valid
      source_rows = loaded_source_exports.flat_map { |source_export| Array(source_export['rows']) }
      report_rows = output ? Array(output['rows']) : []
      measure_exports = loaded_source_exports.select { |source_export| source_export['control_role'] == 'authoritative_measure' }
      measure_rows = measure_exports.flat_map { |source_export| Array(source_export['rows']) }
      rooted_source_total = measure_rows.sum { |row| row['value'].is_a?(Integer) ? row['value'] : 0 }
      rooted_exclusion_total = measure_rows.select { |row| row['included'] == false }.sum { |row| row['value'].is_a?(Integer) ? row['value'] : 0 }
      rooted_report_total = report_rows.select { |row| row['included'] == true }.sum { |row| row['value'].is_a?(Integer) ? row['value'] : 0 }
      rooted_adjustment_total = report_rows.select { |row| row['included'] == true && row['source_version'].to_s.start_with?('approved_adjustment:') }.sum { |row| row['value'].is_a?(Integer) ? row['value'] : 0 }
      rooted_distinct_count = report_rows.select { |row| row['included'] == true }.map { |row| row['distinct_key'] }.uniq.length
      rooted_duplicate_count = report_rows.select { |row| row['included'] == true }.length - rooted_distinct_count
      source_null_count = source_rows.count { |row| !%w[known_numeric numeric_zero].include?(row['state']) }
      output_null_count = report_rows.count { |row| !%w[known_numeric numeric_zero].include?(row['state']) }
      rooted_missing_count = loaded_source_exports.count do |source_export|
        Array(source_export['rows']).empty? || Array(source_export['rows']).any? { |row| !%w[known_numeric numeric_zero].include?(row['state']) }
      end
      rooted_controls = values['authoritative_source_total'] == rooted_source_total && values['documented_exclusion_total'] == rooted_exclusion_total && values['approved_adjustment_total'] == rooted_adjustment_total &&
        values['report_total'] == rooted_report_total && values['source_row_count'] == source_rows.length && values['report_row_count'] == report_rows.length &&
        values['distinct_key_count'] == rooted_distinct_count && values['duplicate_join_count'] == rooted_duplicate_count &&
        values['missing_source_count'] == rooted_missing_count && values['null_state_count'] == source_null_count + output_null_count
      errors << "#{artifact_label} totals/counts must be independently recomputed from canonical source and output rows" unless rooted_controls
      errors << "#{artifact_label} unavailable/unknown/not-collected/not-applicable/suppressed rows force partial or blocked non-exportable output" unless rooted_missing_count.zero? && source_null_count.zero? && output_null_count.zero?
      errors << "#{artifact_label} complete output must be exportable" if output && output['exportable'] != true
    end
    errors << "#{artifact_label} late_event_policy must require linked append-only restatement" unless artifact['late_event_policy'] == 'append_linked_restatement_never_overwrite_closed_output'
    errors << "#{artifact_label} author_identity must be non-placeholder and distinct from every source writer" unless nonempty_string?(artifact['author_identity']) && !source_authors.include?(artifact['author_identity'])
    errors << "#{artifact_label} author_identity must be distinct from output author" if output && artifact['author_identity'] == output['author_identity']
    validate_artifact_reviewer(artifact['reviewer'], artifact['author_identity'], artifact_label)
    errors << "#{artifact_label} independent reviewer must be distinct from every source writer" if artifact['reviewer'].is_a?(Hash) && source_authors.include?(artifact['reviewer']['identity'])
  end

  def validate_batch_g_parameter_artifact(descriptor, requirement_id, label)
    validate_closed_object(descriptor, BATCH_G_ARTIFACT_DESCRIPTOR_KEYS, label)
    return unless descriptor.is_a?(Hash)

    artifact = load_structured_json_artifact(descriptor['reference'], descriptor['sha256'], label)
    return unless artifact
    validate_closed_object(artifact, BATCH_G_PARAMETER_ARTIFACT_KEYS, label)
    errors << "#{label} type/schema/register/requirement must bind Batch G" unless artifact['artifact_type'] == BATCH_G_PARAMETER_ARTIFACT_TYPE && artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION && artifact['register_id'] == BATCH_G_REGISTER_ID && artifact['requirement_id'] == requirement_id
    values = artifact['parameter_values']
    expected_keys = batch_g_definition_contract(requirement_id)['parameters']
    valid_values = values.is_a?(Hash) && values.keys == expected_keys && values.values.all? { |value| nonempty_string?(value) }
    errors << "#{label} parameter_values must exactly bind every frozen per-ID parameter in order" unless valid_values
    fixed_values = batch_g_semantic_spec(requirement_id).fetch(:fixed_parameter_values)
    fixed_values.each do |parameter, expected_value|
      errors << "#{label} parameter_values.#{parameter} must bind frozen semantic value #{expected_value}" unless values.is_a?(Hash) && values[parameter] == expected_value
    end
    computed = Digest::SHA256.hexdigest(JSON.generate(values)) if values.is_a?(Hash)
    errors << "#{label} canonical_parameter_sha256 must be recomputable from the exact parameter payload" unless computed && artifact['canonical_parameter_sha256'] == computed
    errors << "#{label} date must be YYYY-MM-DD" unless iso_date?(artifact['date'])
    errors << "#{label} author_identity must be non-placeholder" unless nonempty_string?(artifact['author_identity']) && !artifact['author_identity'].match?(PLACEHOLDER_OWNER_PATTERN)
    validate_artifact_reviewer(artifact['reviewer'], artifact['author_identity'], label)
    artifact
  end

  def validate_batch_g_rerun_artifact(descriptor, requirement_id, reconciliation, source_exports, output, parameter_sha, label)
    validate_closed_object(descriptor, BATCH_G_ARTIFACT_DESCRIPTOR_KEYS, label)
    return unless descriptor.is_a?(Hash)

    artifact = load_structured_json_artifact(descriptor['reference'], descriptor['sha256'], label)
    return unless artifact
    validate_closed_object(artifact, BATCH_G_RERUN_ARTIFACT_KEYS, label)
    errors << "#{label} type/schema/register/requirement must bind a second Batch G execution" unless artifact['artifact_type'] == BATCH_G_RERUN_ARTIFACT_TYPE && artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION && artifact['register_id'] == BATCH_G_REGISTER_ID && artifact['requirement_id'] == requirement_id
    source_roots = source_exports.map { |source_export| source_export['source_root_sha256'] }
    errors << "#{label} must bind the same definition, parameter payload, snapshot, source roots and cutoff" unless artifact['definition_sha256'] == reconciliation['definition_sha256'] && artifact['parameter_sha256'] == parameter_sha && artifact['snapshot_id'] == reconciliation['snapshot_id'] && artifact['source_roots'] == source_roots && artifact['cutoff_at'] == reconciliation['cutoff_at']
    errors << "#{label} execution_id must identify an independent second execution" unless nonempty_string?(artifact['execution_id']) && artifact['execution_id'].start_with?('SYN-G-RERUN-')
    rerun_sha = Digest::SHA256.hexdigest(JSON.generate(artifact['rows'])) if artifact['rows'].is_a?(Array)
    errors << "#{label} rows/output_sha256 must be independently recomputable and equal the primary rooted output" unless rerun_sha && artifact['output_sha256'] == rerun_sha && output && artifact['output_sha256'] == output['output_sha256'] && artifact['rows'] == output['rows']
    errors << "#{label} author must be distinct from primary output author" unless output && nonempty_string?(artifact['author_identity']) && artifact['author_identity'] != output['author_identity']
    errors << "#{label} date must be YYYY-MM-DD" unless iso_date?(artifact['date'])
    validate_artifact_reviewer(artifact['reviewer'], artifact['author_identity'], label)
  end

  def validate_batch_g_access_artifact(descriptor, requirement_id, output, label)
    validate_closed_object(descriptor, BATCH_G_ARTIFACT_DESCRIPTOR_KEYS, label)
    return unless descriptor.is_a?(Hash)

    artifact = load_structured_json_artifact(descriptor['reference'], descriptor['sha256'], label)
    return unless artifact
    validate_closed_object(artifact, BATCH_G_ACCESS_ARTIFACT_KEYS, label)
    errors << "#{label} type/schema/register/requirement must bind Batch G access/export" unless artifact['artifact_type'] == BATCH_G_ACCESS_ARTIFACT_TYPE && artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION && artifact['register_id'] == BATCH_G_REGISTER_ID && artifact['requirement_id'] == requirement_id
    entry = @decision_entries.find { |candidate| candidate['batch'] == 'G' && candidate['requirement_id'] == requirement_id }
    security = Array(entry && entry['appointment_dependencies']).find { |appointment| appointment.is_a?(Hash) && appointment['authority_domain'] == 'security_privacy_data' }
    errors << "#{label} identity must bind the appointed security/privacy/export authority" unless security && security['status'] == 'appointed' && artifact['identity'] == security['identity']
    errors << "#{label} role/cohort must be closed synthetic scopes" unless artifact['role'] == 'synthetic_report_security_verifier' && artifact['cohort_scope'] == ['SYN-UEU']
    expected_permitted = %w[synthetic_id distinct_key aggregate_value state source_version]
    errors << "#{label} permitted/prohibited fields must enforce minimum necessary synthetic export" unless artifact['permitted_fields'] == expected_permitted && artifact['prohibited_fields'] == %w[real_patient_identifier direct_identifier credential live_endpoint]
    errors << "#{label} output digest/watermark/export reason/retention/masking must bind the rooted output" unless output && artifact['output_sha256'] == output['output_sha256'] && artifact['watermark'] == output['watermark'] && artifact['export_reason'] == output['export_reason'] && artifact['retention_class'] == output['retention_class'] && artifact['masked'] == output['masked'] && artifact['small_cell_suppression_applied'] == output['small_cell_suppression_applied']
    policy_payload = artifact.slice('role', 'cohort_scope', 'permitted_fields', 'prohibited_fields', 'watermark', 'retention_class', 'masked', 'small_cell_suppression_applied')
    errors << "#{label} policy_sha256 must be recomputable from the closed access policy" unless artifact['policy_sha256'] == Digest::SHA256.hexdigest(JSON.generate(policy_payload))
    errors << "#{label} date must be YYYY-MM-DD" unless iso_date?(artifact['date'])
    validate_artifact_reviewer(artifact['reviewer'], artifact['identity'], label)
    artifact
  end

  def validate_batch_g_audit_artifact(descriptor, requirement_id, output, access_artifact, label)
    validate_closed_object(descriptor, BATCH_G_ARTIFACT_DESCRIPTOR_KEYS, label)
    return unless descriptor.is_a?(Hash)

    artifact = load_structured_json_artifact(descriptor['reference'], descriptor['sha256'], label)
    return unless artifact
    validate_closed_object(artifact, BATCH_G_AUDIT_ARTIFACT_KEYS, label)
    errors << "#{label} type/schema/register/requirement must bind Batch G audit" unless artifact['artifact_type'] == BATCH_G_AUDIT_ARTIFACT_TYPE && artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION && artifact['register_id'] == BATCH_G_REGISTER_ID && artifact['requirement_id'] == requirement_id
    events = artifact['events']
    valid_events = events.is_a?(Array) && events.map { |event| event['event_type'] if event.is_a?(Hash) } == %w[view run export] && events.all? do |event|
      validate_closed_object(event, BATCH_G_AUDIT_EVENT_KEYS, "#{label} event")
      event.is_a?(Hash) && nonempty_string?(event['event_id']) && event['cohort_scope'] == ['SYN-UEU'] && access_artifact && event['actor_identity'] == access_artifact['identity'] && event['role'] == access_artifact['role'] && nonempty_string?(event['occurred_at']) && event['occurred_at'].end_with?('+07:00') && event['outcome'] == 'allowed' && nonempty_string?(event['reason']) && output && event['output_sha256'] == output['output_sha256']
    end
    errors << "#{label} events must exactly root allowed view/run/export actions to actor, role, cohort, timestamp, reason and output" unless valid_events
    errors << "#{label} output_sha256 must bind the rooted output" unless output && artifact['output_sha256'] == output['output_sha256']
    errors << "#{label} audit_root_sha256 must be recomputable from exact events" unless events.is_a?(Array) && artifact['audit_root_sha256'] == Digest::SHA256.hexdigest(JSON.generate(events))
    output_audit_ids = output && output['audit_event_ids']
    errors << "#{label} event IDs must exactly match the output receipt" unless valid_events && output_audit_ids.is_a?(Hash) && events.to_h { |event| [event['event_type'], event['event_id']] } == output_audit_ids
    errors << "#{label} author_identity must be non-placeholder" unless nonempty_string?(artifact['author_identity']) && !artifact['author_identity'].match?(PLACEHOLDER_OWNER_PATTERN)
    errors << "#{label} date must be YYYY-MM-DD" unless iso_date?(artifact['date'])
    validate_artifact_reviewer(artifact['reviewer'], artifact['author_identity'], label)
  end

  def validate_batch_g_statutory_artifact(control, entry, requirement_id, label)
    artifact = load_structured_json_artifact(control['authority_reference'], control['authority_artifact_sha256'], "#{label} authority")
    return unless artifact

    artifact_label = "#{label} authority"
    validate_closed_object(artifact, BATCH_G_STATUTORY_ARTIFACT_KEYS, artifact_label)
    errors << "#{artifact_label} type/schema/register/requirement/subject must bind Batch G" unless artifact['artifact_type'] == BATCH_G_STATUTORY_ARTIFACT_TYPE && artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION && artifact['register_id'] == BATCH_G_REGISTER_ID && artifact['requirement_id'] == requirement_id && artifact['subject'] == 'current_statutory_definition'
    errors << "#{artifact_label} legacy_simulation_only must remain true" unless artifact['legacy_simulation_only'] == true
    %w[standard_identifier standard_version effective_date definition_source].each { |key| errors << "#{artifact_label} #{key} must match the register" unless artifact[key] == control[key] }
    %w[standard_identifier standard_version definition_source].each do |key|
      value = artifact[key]
      errors << "#{artifact_label} #{key} must be a substantive current authority value" unless nonempty_string?(value) && value.length >= 5 && !value.match?(/\b(?:tbd|unknown|legacy|menu label)\b/i)
    end
    errors << "#{artifact_label} effective_date must be YYYY-MM-DD" unless iso_date?(artifact['effective_date'])
    validate_batch_g_signed_report_definition(artifact, control, entry, requirement_id, artifact_label)
    expected_domains = batch_g_required_authorities(requirement_id)
    bindings = artifact['authority_bindings']
    actual_domains = Array(bindings).each_with_object([]) { |binding, values| values << binding['authority_domain'] if binding.is_a?(Hash) }
    errors << "#{artifact_label} authority_bindings must exactly cover reporting, sponsor, security and every source authority" unless bindings.is_a?(Array) && actual_domains == expected_domains
    Array(bindings).each_with_index do |binding, index|
      binding_label = "#{artifact_label} authority_bindings[#{index}]"
      validate_closed_object(binding, BATCH_G_STATUTORY_BINDING_KEYS, binding_label)
      next unless binding.is_a?(Hash)
      appointment = Array(entry['appointment_dependencies']).find { |candidate| candidate.is_a?(Hash) && candidate['authority_domain'] == binding['authority_domain'] }
      unless appointment && appointment['status'] == 'appointed' && binding['identity'] == appointment['identity'] && binding['appointment_reference'] == appointment['reference'] && binding['appointment_sha256'] == appointment['artifact_sha256']
        errors << "#{binding_label} must bind the appointed authority identity and SHA"
      end
      approval = load_structured_json_artifact(binding['approval_reference'], binding['approval_sha256'], "#{binding_label} approval")
      next unless approval
      validate_closed_object(approval, BATCH_G_STATUTORY_APPROVAL_KEYS, "#{binding_label} approval")
      errors << "#{binding_label} signed approval must bind this authority, identity, current definition and exact report-definition SHA" unless approval['artifact_type'] == BATCH_G_STATUTORY_APPROVAL_ARTIFACT_TYPE && approval['schema_version'] == ARTIFACT_SCHEMA_VERSION && approval['register_id'] == BATCH_G_REGISTER_ID && approval['requirement_id'] == requirement_id && approval['subject'] == 'current_statutory_definition_approval' && approval['authority_domain'] == binding['authority_domain'] && approval['identity'] == binding['identity'] && %w[standard_identifier standard_version effective_date definition_source].all? { |key| approval[key] == control[key] } && approval['report_definition_sha256'] == artifact['report_definition_sha256']
      errors << "#{binding_label} approval date must be YYYY-MM-DD" unless iso_date?(approval['date'])
      validate_artifact_reviewer(approval['reviewer'], approval['identity'], "#{binding_label} approval")
    end
    errors << "#{artifact_label} date must be YYYY-MM-DD" unless iso_date?(artifact['date'])
    errors << "#{artifact_label} author_identity must be non-placeholder" unless nonempty_string?(artifact['author_identity']) && !artifact['author_identity'].match?(PLACEHOLDER_OWNER_PATTERN)
    validate_artifact_reviewer(artifact['reviewer'], artifact['author_identity'], artifact_label)
  end

  def validate_batch_g_signed_report_definition(statutory_artifact, control, entry, requirement_id, label)
    artifact = load_structured_json_artifact(statutory_artifact['report_definition_reference'], statutory_artifact['report_definition_sha256'], "#{label} report_definition")
    return unless artifact

    definition_label = "#{label} report_definition"
    validate_closed_object(artifact, BATCH_G_SIGNED_REPORT_DEFINITION_KEYS, definition_label)
    expected_definition = batch_g_definition_contract(requirement_id)
    expected_definition_sha = Digest::SHA256.hexdigest(JSON.generate(expected_definition))
    errors << "#{definition_label} type/schema/register/requirement/subject must bind the exact Batch G report definition" unless artifact['artifact_type'] == BATCH_G_SIGNED_REPORT_DEFINITION_ARTIFACT_TYPE && artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION && artifact['register_id'] == BATCH_G_REGISTER_ID && artifact['requirement_id'] == requirement_id && artifact['subject'] == 'signed_current_report_definition'
    errors << "#{definition_label} semantic_digest and definition_sha256 must bind the frozen exact per-ID semantic spec" unless artifact['semantic_digest'] == batch_g_semantic_digest(requirement_id) && artifact['definition_sha256'] == expected_definition_sha
    %w[grain distinct_key numerator denominator inclusions exclusions parameters].each do |key|
      errors << "#{definition_label} #{key} must exactly match the frozen per-ID formula contract" unless artifact[key] == expected_definition[key]
    end
    expected_period = expected_definition.slice('time_basis', 'timezone', 'cutoff_policy', 'period_close_policy')
    validate_closed_object(artifact['period_basis'], BATCH_G_SIGNED_REPORT_PERIOD_KEYS, "#{definition_label} period_basis")
    errors << "#{definition_label} period_basis must bind exact event/effective/recorded time, timezone, cutoff and close semantics" unless artifact['period_basis'] == expected_period
    %w[standard_identifier standard_version effective_date definition_source].each do |key|
      errors << "#{definition_label} #{key} must match the current statutory control" unless artifact[key] == control[key]
    end
    expected_sources = Array(entry['source_dependencies']).map do |dependency|
      {
        'batch' => dependency['batch'], 'requirement_id' => dependency['requirement_id'],
        'source_entity' => dependency['source_entity'], 'source_owner_authority' => dependency['source_owner_authority'],
        'resolution_reference' => dependency['resolution_reference'], 'resolution_sha256' => dependency['resolution_artifact_sha256']
      }
    end
    bindings = artifact['source_bindings']
    errors << "#{definition_label} source_bindings must exactly bind every resolved applicability-specific source and resolution SHA" unless bindings == expected_sources && expected_sources.all? { |binding| nonempty_string?(binding['resolution_reference']) && binding['resolution_sha256'].to_s.match?(/\A[0-9a-f]{64}\z/i) }
    Array(bindings).each_with_index { |binding, index| validate_closed_object(binding, BATCH_G_SIGNED_REPORT_SOURCE_BINDING_KEYS, "#{definition_label} source_bindings[#{index}]") }
    errors << "#{definition_label} date must be YYYY-MM-DD" unless iso_date?(artifact['date'])
    errors << "#{definition_label} author_identity must be non-placeholder" unless nonempty_string?(artifact['author_identity']) && !artifact['author_identity'].match?(PLACEHOLDER_OWNER_PATTERN)
    validate_artifact_reviewer(artifact['reviewer'], artifact['author_identity'], definition_label)
    artifact
  end

  def validate_batch_g_consolidation_artifact(control, requirement_id, candidate, label)
    artifact = load_structured_json_artifact(control['artifact_reference'], control['artifact_sha256'], "#{label} artifact")
    return unless artifact

    artifact_label = "#{label} artifact"
    validate_closed_object(artifact, BATCH_G_CONSOLIDATION_ARTIFACT_KEYS, artifact_label)
    errors << "#{artifact_label} type/schema/register/candidate must bind Batch G" unless artifact['artifact_type'] == BATCH_G_CONSOLIDATION_ARTIFACT_TYPE && artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION && artifact['register_id'] == BATCH_G_REGISTER_ID && artifact['candidate_id'] == candidate
    members = BATCH_G_CONSOLIDATION_GROUPS.fetch(candidate)
    errors << "#{artifact_label} members must exactly retain all candidate PAR IDs" unless artifact['members'] == members
    errors << "#{artifact_label} target must match the shared register target" unless artifact['target_requirement_id'] == control['terminal_target_requirement_id'] && members.include?(artifact['target_requirement_id'])
    target = @decision_entries.find { |entry| entry['batch'] == 'G' && entry['requirement_id'] == artifact['target_requirement_id'] }
    decision = target && target['decision']
    errors << "#{artifact_label} terminal target must be approved reproduce/replace" unless decision.is_a?(Hash) && decision['status'] == 'approve' && %w[reproduce replace].include?(decision['canonical_disposition'])
    owner = target && target['accountable_owner']
    errors << "#{artifact_label} terminal owner identity/domain must bind the appointed target lead" unless owner.is_a?(Hash) && owner['appointment_status'] == 'appointed' && artifact['terminal_owner_identity'] == owner['identity'] && artifact['terminal_authority_domain'] == target['lead_authority_domain']
    approval = target && target['approval']
    errors << "#{artifact_label} terminal approval reference/SHA must bind target approval" unless approval.is_a?(Hash) && approval['status'] == 'recorded' && artifact['terminal_approval_reference'] == approval['reference'] && artifact['terminal_approval_sha256'] == approval['artifact_sha256']
    policy = BATCH_G_CONSOLIDATION_PARAMETER_POLICIES.fetch(candidate)
    errors << "#{artifact_label} declared_parameters must enumerate every differing cohort, care-setting, dimension or version" unless artifact['declared_parameters'] == policy.fetch('declared_parameters')
    mappings = artifact['member_mappings']
    errors << "#{artifact_label} member_mappings must exactly preserve every candidate member" unless mappings.is_a?(Hash) && mappings.keys == members
    Array(members).each do |member|
      mapping = mappings.is_a?(Hash) ? mappings[member] : nil
      mapping_label = "#{artifact_label} member_mappings[#{member}]"
      validate_closed_object(mapping, BATCH_G_CONSOLIDATION_MEMBER_MAPPING_KEYS, mapping_label)
      next unless mapping.is_a?(Hash)

      expected_mapping = batch_g_expected_consolidation_member_mapping(member)
      errors << "#{mapping_label} must exactly bind semantic parameters, definition, source lineage, controls, authorities and recorded member approval" unless mapping == expected_mapping
      member_entry = @decision_entries.find { |entry| entry['batch'] == 'G' && entry['requirement_id'] == member }
      member_decision = member_entry && member_entry['decision']
      member_approval = member_entry && member_entry['approval']
      unless member_decision.is_a?(Hash) && %w[approve defer].include?(member_decision['status']) && member_approval.is_a?(Hash) && member_approval['status'] == 'recorded'
        errors << "#{mapping_label} cannot preserve authority without a recorded member decision approval"
      end
    end
    errors << "#{artifact_label} date must be YYYY-MM-DD" unless iso_date?(artifact['date'])
    errors << "#{artifact_label} author_identity must be non-placeholder" unless nonempty_string?(artifact['author_identity']) && !artifact['author_identity'].match?(PLACEHOLDER_OWNER_PATTERN)
    validate_artifact_reviewer(artifact['reviewer'], artifact['author_identity'], artifact_label)
  end

  def validate_batch_d_controls(entry, label)
    unless entry['capability_kind'] == BATCH_D_CAPABILITY_KINDS[label]
      errors << "#{decision_register_label} #{label}: capability_kind must be #{BATCH_D_CAPABILITY_KINDS[label]}"
    end
    validate_batch_d_upstream_dependencies(entry['upstream_requirement_dependencies'], entry['co_owners'], label)
    validate_batch_d_integration_boundary(entry['integration_boundary'], label)
    validate_batch_d_lifecycle_mapping(entry['lifecycle_mapping'], entry['decision'], label)
    validate_batch_d_orp_boundary(entry, label) if label == 'PAR-ORP-001'
  end

  def validate_batch_d_orp_boundary(entry, label)
    decision = entry['decision']
    return unless decision.is_a?(Hash) && %w[approve defer].include?(decision['status'])

    upstream = entry['upstream_requirement_dependencies']
    batch_gate = upstream&.find { |dependency| dependency.is_a?(Hash) && dependency['dependency_kind'] == 'batch_gate' && dependency['reference'] == 'E' }
    if batch_gate&.dig('status') == 'resolved'
      errors << "#{decision_register_label} #{label}: Batch E stock semantics cannot be marked resolved before Batch E governance is registered"
    elsif batch_gate&.dig('status') == 'deferred'
      unless batch_gate['defer_authority_domain'] == 'pharmacy'
        errors << "#{decision_register_label} #{label}: Batch E stock-semantics deferral requires pharmacy authority"
      end
      exclusion_text = decision.dig('target', 'exclusions').is_a?(Array) ? decision.dig('target', 'exclusions').join(' ').downcase : ''
      missing_semantics = %w[stock issue return lot charge].reject { |term| exclusion_text.include?(term) }
      unless missing_semantics.empty?
        errors << "#{decision_register_label} #{label}: Batch E deferral must explicitly exclude stock, issue, return, lot and charge semantics"
      end
    end
  end

  def validate_batch_d_upstream_dependencies(dependencies, co_owners, label)
    expected = [BATCH_D_SHARED_A_FOUNDATION]
    expected += BATCH_D_SERVICE_FOUNDATIONS if label.start_with?('PAR-CLN-') || label == 'PAR-ORP-001'
    expected += BATCH_D_REQUIRED_UPSTREAM_DEPENDENCIES.fetch(label, [])
    unless dependencies.is_a?(Array) && !dependencies.empty?
      errors << "#{decision_register_label} #{label}: upstream_requirement_dependencies must be a non-empty array"
      return
    end

    actual = []
    dependencies.each_with_index do |dependency, index|
      prefix = "#{decision_register_label} #{label}: upstream_requirement_dependencies[#{index}]"
      unless dependency.is_a?(Hash)
        errors << "#{prefix} must be an object"
        next
      end
      validate_closed_object(dependency, %w[dependency_kind reference scope status resolution defer_authority_domain resolution_reference resolution_artifact_sha256], prefix)
      kind = dependency['dependency_kind']
      status = dependency['status']
      errors << "#{prefix} invalid dependency_kind #{kind.inspect}" unless UPSTREAM_DEPENDENCY_KINDS.include?(kind)
      errors << "#{prefix} reference must be a non-empty string" unless nonempty_string?(dependency['reference'])
      validate_nonempty_string_array(dependency['scope'], "#{prefix} scope")
      errors << "#{prefix} invalid status #{status.inspect}" unless UPSTREAM_DEPENDENCY_STATUSES.include?(status)
      actual << [kind, dependency['reference'], dependency['scope']] if nonempty_string?(kind) && nonempty_string?(dependency['reference']) && dependency['scope'].is_a?(Array)

      if status == 'pending'
        errors << "#{prefix} pending resolution must be null" unless dependency['resolution'].nil?
        errors << "#{prefix} pending defer_authority_domain must be null" unless dependency['defer_authority_domain'].nil?
        errors << "#{prefix} pending resolution_reference must be null" unless dependency['resolution_reference'].nil?
        errors << "#{prefix} pending resolution_artifact_sha256 must be null" unless dependency['resolution_artifact_sha256'].nil?
      elsif status == 'resolved'
        errors << "#{prefix} resolved dependency requires a non-empty resolution" unless nonempty_string?(dependency['resolution'])
        errors << "#{prefix} resolved defer_authority_domain must be null" unless dependency['defer_authority_domain'].nil?
        validate_batch_d_upstream_resolution_artifact(dependency, label, prefix)
      elsif status == 'deferred'
        errors << "#{prefix} deferred dependency requires a non-empty resolution" unless nonempty_string?(dependency['resolution'])
        authority = dependency['defer_authority_domain']
        if kind == 'batch_gate' && dependency['reference'] != 'E'
          errors << "#{prefix} prior-batch foundation gates cannot be deferred inside Batch D"
        else
          expected_authority = BATCH_D_LEAD_AUTHORITIES[label]
          unless nonempty_string?(authority) && authority == expected_authority && co_owners.is_a?(Array) && co_owners.include?(authority)
            errors << "#{prefix} deferred dependency requires the frozen lead authority #{expected_authority}"
          end
        end
        validate_batch_d_upstream_resolution_artifact(dependency, label, prefix)
      end

      if kind == 'batch_gate' && !EXPECTED_BATCH_COUNTS.key?(dependency['reference'])
        errors << "#{prefix} batch_gate reference must be a known batch"
      elsif kind == 'requirement' && !(dependency['reference'].is_a?(String) && dependency['reference'].match?(PAR_ID_PATTERN))
        errors << "#{prefix} requirement reference must be a PAR ID"
      end
    end

    unless expected && actual == expected
      errors << "#{decision_register_label} #{label}: upstream dependencies must exactly match the frozen Batch D dependency policy"
    end
  end

  def validate_batch_d_upstream_resolution_artifact(dependency, requirement_id, label)
    artifact = load_structured_json_artifact(dependency['resolution_reference'], dependency['resolution_artifact_sha256'], "#{label} resolution")
    return unless artifact

    validate_closed_object(artifact, UPSTREAM_RESOLUTION_ARTIFACT_KEYS, "#{label} resolution")
    errors << "#{label} resolution artifact_type must be #{UPSTREAM_RESOLUTION_ARTIFACT_TYPE}" unless artifact['artifact_type'] == UPSTREAM_RESOLUTION_ARTIFACT_TYPE
    errors << "#{label} resolution schema_version must be #{ARTIFACT_SCHEMA_VERSION}" unless artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION
    errors << "#{label} resolution register_id does not match the decision register" unless artifact['register_id'] == @active_decision_context[:register_id]
    errors << "#{label} resolution requirement_id does not match #{requirement_id}" unless artifact['requirement_id'] == requirement_id
    errors << "#{label} resolution subject must be upstream_requirement_dependency" unless artifact['subject'] == 'upstream_requirement_dependency'
    errors << "#{label} resolution dependency_kind does not match the register" unless artifact['dependency_kind'] == dependency['dependency_kind']
    errors << "#{label} resolution dependency_reference does not match the register" unless artifact['dependency_reference'] == dependency['reference']
    errors << "#{label} resolution scope does not match the register" unless artifact['scope'] == dependency['scope']
    errors << "#{label} resolution status does not match the register" unless artifact['status'] == dependency['status']
    errors << "#{label} resolution text does not match the register" unless artifact['resolution'] == dependency['resolution']
    upstream_batch = dependency['dependency_kind'] == 'batch_gate' ? dependency['reference'] : @batch_assignments[dependency['reference']]
    errors << "#{label} resolution upstream_batch does not match #{upstream_batch}" unless artifact['upstream_batch'] == upstream_batch

    if dependency['dependency_kind'] == 'batch_gate'
      upstream_config = DECISION_REGISTER_CONFIGS[upstream_batch]
      manifest_bound_forward_deferral = upstream_batch == 'E' && dependency['status'] == 'deferred'
      if upstream_config && !manifest_bound_forward_deferral
        upstream_path = @decision_register_paths[upstream_batch]
        upstream_source_id = upstream_config[:register_id]
      else
        upstream_path = @batch_manifest_path
        upstream_source_id = 'G0_PARITY_BATCH_MANIFEST.json#batch-E'
      end
      actual_source_sha = Digest::SHA256.file(upstream_path).hexdigest if upstream_path && File.file?(upstream_path)
      errors << "#{label} resolution upstream_source_id does not match the loaded governance source" unless artifact['upstream_source_id'] == upstream_source_id
      errors << "#{label} resolution upstream_source_sha256 does not match the loaded governance source" unless actual_source_sha && artifact['upstream_source_sha256'] == actual_source_sha
      expected_authority = BATCH_GATE_AUTHORITIES[dependency['reference']]
      unless upstream_batch == 'E' && dependency['status'] == 'deferred'
        upstream_entries = @decision_entries_by_batch.fetch(upstream_batch, [])
        unless @decision_register_statuses[upstream_batch] == 'complete' && !upstream_entries.empty? && upstream_entries.all? { |entry| %w[approve defer].include?(entry.dig('decision', 'status')) }
          errors << "#{label} resolution cannot close Batch #{upstream_batch} while its loaded decision register is incomplete"
        end
      end
      errors << "#{label} resolution upstream_decision_status must be null for a batch gate" unless artifact['upstream_decision_status'].nil?
      errors << "#{label} resolution upstream_approval_sha256 must be null for a batch gate" unless artifact['upstream_approval_sha256'].nil?
    else
      upstream_entry = @decision_entries.find { |candidate| candidate['requirement_id'] == dependency['reference'] }
      upstream_decision_status = upstream_entry.dig('decision', 'status') if upstream_entry.is_a?(Hash)
      upstream_owner = upstream_entry['accountable_owner'] if upstream_entry.is_a?(Hash)
      upstream_approval = upstream_entry['approval'] if upstream_entry.is_a?(Hash)
      if dependency['status'] == 'deferred'
        source_entry = @decision_entries.find { |candidate| candidate['requirement_id'] == requirement_id }
        source_owner = source_entry['accountable_owner'] if source_entry.is_a?(Hash)
        source_approval = source_entry['approval'] if source_entry.is_a?(Hash)
        expected_authority = BATCH_D_LEAD_AUTHORITIES[requirement_id]
        unless source_owner&.dig('appointment_status') == 'appointed' && source_approval&.dig('status') == 'recorded'
          errors << "#{label} deferral requires the source D accountable owner and approval to be complete"
        end
        errors << "#{label} resolution identity must match the source D accountable owner" unless source_owner.is_a?(Hash) && artifact['identity'] == source_owner['identity']
        errors << "#{label} resolution upstream_decision_status must match the source deferral decision" unless artifact['upstream_decision_status'] == source_entry&.dig('decision', 'status')
        errors << "#{label} resolution upstream_approval_sha256 must match the source approval" unless source_approval.is_a?(Hash) && artifact['upstream_approval_sha256'] == source_approval['artifact_sha256']
        errors << "#{label} resolution upstream_source_id must match the source approval reference" unless source_approval.is_a?(Hash) && artifact['upstream_source_id'] == source_approval['reference']
        errors << "#{label} resolution upstream_source_sha256 must match the source approval" unless source_approval.is_a?(Hash) && artifact['upstream_source_sha256'] == source_approval['artifact_sha256']
      else
        expected_authority = upstream_owner['authority_domain'] if upstream_owner.is_a?(Hash)
        unless %w[approve defer].include?(upstream_decision_status) && upstream_owner&.dig('appointment_status') == 'appointed' && upstream_approval&.dig('status') == 'recorded'
          errors << "#{label} resolution cannot close requirement #{dependency['reference']} before its decision, owner and approval are complete"
        end
        errors << "#{label} resolution identity must match the referenced accountable owner" unless upstream_owner.is_a?(Hash) && artifact['identity'] == upstream_owner['identity']
        errors << "#{label} resolution upstream_decision_status does not match the referenced decision" unless artifact['upstream_decision_status'] == upstream_decision_status
        errors << "#{label} resolution upstream_approval_sha256 does not match the referenced approval" unless upstream_approval.is_a?(Hash) && artifact['upstream_approval_sha256'] == upstream_approval['artifact_sha256']
        errors << "#{label} resolution upstream_source_id must match the referenced approval" unless upstream_approval.is_a?(Hash) && artifact['upstream_source_id'] == upstream_approval['reference']
        errors << "#{label} resolution upstream_source_sha256 must match the referenced approval" unless upstream_approval.is_a?(Hash) && artifact['upstream_source_sha256'] == upstream_approval['artifact_sha256']
      end
    end
    errors << "#{label} resolution authority_domain must be #{expected_authority}" unless artifact['authority_domain'] == expected_authority
    if dependency['status'] == 'deferred' && dependency['defer_authority_domain'] != artifact['authority_domain']
      errors << "#{label} resolution authority_domain does not match defer_authority_domain"
    end
    errors << "#{label} resolution identity must be a non-placeholder string" unless nonempty_string?(artifact['identity']) && !artifact['identity'].match?(PLACEHOLDER_OWNER_PATTERN)
    errors << "#{label} resolution date must be YYYY-MM-DD" unless iso_date?(artifact['date'])
    validate_artifact_reviewer(artifact['reviewer'], artifact['identity'], "#{label} resolution")
  end

  def validate_batch_d_integration_boundary(boundary, label)
    prefix = "#{decision_register_label} #{label}: integration_boundary"
    return unless validate_closed_object(boundary, %w[mode outbound_network live_endpoints credentials notes], prefix)

    errors << "#{prefix} invalid mode #{boundary['mode'].inspect}" unless BATCH_D_INTEGRATION_MODES.include?(boundary['mode'])
    errors << "#{prefix} outbound_network must be false" unless boundary['outbound_network'] == false
    errors << "#{prefix} live_endpoints must be false" unless boundary['live_endpoints'] == false
    errors << "#{prefix} credentials must be false" unless boundary['credentials'] == false
    errors << "#{prefix} notes must state the synthetic boundary" unless nonempty_string?(boundary['notes']) && boundary['notes'].match?(/synthetic|non-transmitting|no outbound/i)
  end

  def validate_batch_d_lifecycle_mapping(control, decision, label)
    prefix = "#{decision_register_label} #{label}: lifecycle_mapping"
    return unless validate_closed_object(control, %w[status lifecycle_artifact_reference lifecycle_artifact_sha256 mapping_artifact_reference mapping_artifact_sha256 terminal_target_requirement_id], prefix)

    status = control['status']
    errors << "#{prefix} invalid status #{status.inspect}" unless LIFECYCLE_MAPPING_STATUSES.include?(status)
    if %w[pending not_applicable].include?(status)
      %w[lifecycle_artifact_reference lifecycle_artifact_sha256 mapping_artifact_reference mapping_artifact_sha256 terminal_target_requirement_id].each do |key|
        errors << "#{prefix} #{status} #{key} must be null" unless control[key].nil?
      end
    elsif status == 'complete'
      validate_batch_d_specialized_artifact(control['lifecycle_artifact_reference'], control['lifecycle_artifact_sha256'], label, 'lifecycle', decision)
      validate_batch_d_specialized_artifact(control['mapping_artifact_reference'], control['mapping_artifact_sha256'], label, 'mapping', decision)
      errors << "#{prefix} terminal_target_requirement_id must be a PAR ID" unless control['terminal_target_requirement_id'].is_a?(String) && control['terminal_target_requirement_id'].match?(PAR_ID_PATTERN)
    end

    decision_status = decision['status'] if decision.is_a?(Hash)
    disposition = decision['canonical_disposition'] if decision.is_a?(Hash)
    requires_artifacts = BATCH_D_LIFECYCLE_MAPPING_REQUIRED_IDS.include?(label) && (decision_status == 'approve' || disposition == 'consolidate')
    errors << "#{prefix} diagnostic/procedure approval or consolidation requires complete lifecycle and mapping artifacts" if requires_artifacts && status != 'complete'
    if status == 'complete' && decision.is_a?(Hash)
      expected_terminal = case disposition
                          when 'reproduce', 'replace' then label
                          when 'consolidate' then decision.dig('target', 'reference')
                          end
      if expected_terminal
        errors << "#{prefix} terminal_target_requirement_id must be #{expected_terminal}" unless control['terminal_target_requirement_id'] == expected_terminal
      elsif %w[retire exclude pending].include?(disposition)
        errors << "#{prefix} must not be complete for an excluded, retired, deferred or pending capability"
      end
    end
  end

  def validate_batch_d_specialized_artifact(reference, digest, requirement_id, kind, decision)
    label = "#{decision_register_label} #{requirement_id}: #{kind} artifact"
    artifact = load_structured_json_artifact(reference, digest, label)
    return unless artifact

    expected_keys = kind == 'lifecycle' ? LIFECYCLE_ARTIFACT_KEYS : MAPPING_ARTIFACT_KEYS
    validate_closed_object(artifact, expected_keys, label)
    expected_type = kind == 'lifecycle' ? LIFECYCLE_ARTIFACT_TYPE : MAPPING_ARTIFACT_TYPE
    errors << "#{label} artifact_type must be #{expected_type}" unless artifact['artifact_type'] == expected_type
    errors << "#{label} schema_version must be #{ARTIFACT_SCHEMA_VERSION}" unless artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION
    errors << "#{label} register_id does not match the decision register" unless artifact['register_id'] == @active_decision_context[:register_id]
    errors << "#{label} requirement_id does not match #{requirement_id}" unless artifact['requirement_id'] == requirement_id
    %w[date author_identity].each { |key| errors << "#{label} #{key} must be a non-empty string" unless nonempty_string?(artifact[key]) }
    errors << "#{label} date must be YYYY-MM-DD" unless iso_date?(artifact['date'])
    if kind == 'lifecycle'
      %w[lifecycle_states lifecycle_transitions correction_rules].each { |key| validate_nonempty_string_array(artifact[key], "#{label} #{key}") }
      capability_kind = BATCH_D_CAPABILITY_KINDS[requirement_id]
      required_states = BATCH_D_LIFECYCLE_STATES.fetch(capability_kind)
      required_transitions = BATCH_D_LIFECYCLE_TRANSITIONS.fetch(capability_kind)
      missing_states = required_states - Array(artifact['lifecycle_states'])
      missing_transitions = required_transitions - Array(artifact['lifecycle_transitions'])
      missing_corrections = BATCH_D_COMMON_CORRECTION_RULES - Array(artifact['correction_rules'])
      errors << "#{label} missing capability lifecycle states: #{missing_states.join(', ')}" unless missing_states.empty?
      errors << "#{label} missing capability lifecycle transitions: #{missing_transitions.join(', ')}" unless missing_transitions.empty?
      errors << "#{label} missing correction safeguards: #{missing_corrections.join(', ')}" unless missing_corrections.empty?
      errors << "#{label} lifecycle_states must exactly match the frozen capability policy" unless artifact['lifecycle_states'] == required_states
      errors << "#{label} lifecycle_transitions must exactly match the frozen capability policy" unless artifact['lifecycle_transitions'] == required_transitions
      errors << "#{label} correction_rules must exactly match the frozen safeguards" unless artifact['correction_rules'] == BATCH_D_COMMON_CORRECTION_RULES
    else
      %w[mapped_fields mapped_states].each { |key| validate_nonempty_string_array(artifact[key], "#{label} #{key}") }
      errors << "#{label} coverage_status must be complete" unless artifact['coverage_status'] == 'complete'
      errors << "#{label} unmapped_items must be an empty array" unless artifact['unmapped_items'] == []
      errors << "#{label} exclusions must be an array" unless artifact['exclusions'].is_a?(Array) && artifact['exclusions'].all? { |item| nonempty_string?(item) }
      capability_kind = BATCH_D_CAPABILITY_KINDS[requirement_id]
      missing_fields = BATCH_D_MAPPING_FIELDS - Array(artifact['mapped_fields'])
      missing_states = BATCH_D_LIFECYCLE_STATES.fetch(capability_kind) - Array(artifact['mapped_states'])
      errors << "#{label} missing required mapping fields: #{missing_fields.join(', ')}" unless missing_fields.empty?
      errors << "#{label} missing required mapped states: #{missing_states.join(', ')}" unless missing_states.empty?
      errors << "#{label} mapped_fields must exactly match the frozen mapping policy" unless artifact['mapped_fields'] == BATCH_D_MAPPING_FIELDS
      errors << "#{label} mapped_states must exactly match the frozen capability states" unless artifact['mapped_states'] == BATCH_D_LIFECYCLE_STATES.fetch(capability_kind)
      target_reference = decision.dig('target', 'reference') if decision.is_a?(Hash)
      errors << "#{label} target_reference does not match the register decision" unless artifact['target_reference'] == target_reference
    end
    validate_artifact_reviewer(artifact['reviewer'], artifact['author_identity'], label)
  end

  def validate_decision_evidence(record, label, index)
    unless record.is_a?(Hash)
      errors << "#{decision_register_label} #{label}: evidence[#{index}] must be an object"
      return
    end

    evidence_class = record['evidence_class']
    confidence = record['confidence']
    unless EVIDENCE_CLASSES.include?(evidence_class)
      errors << "#{decision_register_label} #{label}: invalid evidence_class #{evidence_class.inspect}"
    end
    unless EVIDENCE_CONFIDENCES.include?(confidence)
      errors << "#{decision_register_label} #{label}: invalid evidence confidence #{confidence.inspect}"
    end

    if evidence_class == 'P'
      %w[date source reference interpreter artifact_reference artifact_sha256].each do |key|
        errors << "#{decision_register_label} #{label}: pending evidence #{key} must be null" unless record[key].nil?
      end
      if %w[D E F G].include?(@active_decision_context[:batch]) && !record['evidence_basis'].nil?
        errors << "#{decision_register_label} #{label}: pending evidence evidence_basis must be null"
      end
      errors << "#{decision_register_label} #{label}: pending evidence confidence must be pending" unless confidence == 'pending'
      errors << "#{decision_register_label} #{label}: pending evidence note must explain the gap" unless nonempty_string?(record['note'])
      return
    end

    errors << "#{decision_register_label} #{label}: evidence date must be YYYY-MM-DD" unless iso_date?(record['date'])
    %w[source reference interpreter].each do |key|
      errors << "#{decision_register_label} #{label}: evidence #{key} must be a non-empty string" unless nonempty_string?(record[key])
    end
    if confidence == 'pending'
      errors << "#{decision_register_label} #{label}: non-pending evidence confidence cannot be pending"
    end
    if @active_decision_context[:batch] == 'D' && !BATCH_D_EVIDENCE_BASES.include?(record['evidence_basis'])
      errors << "#{decision_register_label} #{label}: invalid evidence_basis #{record['evidence_basis'].inspect}"
    elsif @active_decision_context[:batch] == 'E' && !BATCH_E_EVIDENCE_BASES.include?(record['evidence_basis'])
      errors << "#{decision_register_label} #{label}: invalid evidence_basis #{record['evidence_basis'].inspect}"
    elsif @active_decision_context[:batch] == 'F' && !BATCH_F_EVIDENCE_BASES.include?(record['evidence_basis'])
      errors << "#{decision_register_label} #{label}: invalid evidence_basis #{record['evidence_basis'].inspect}"
    elsif @active_decision_context[:batch] == 'G' && !BATCH_G_EVIDENCE_BASES.include?(record['evidence_basis'])
      errors << "#{decision_register_label} #{label}: invalid evidence_basis #{record['evidence_basis'].inspect}"
    end
    validate_evidence_artifact(record, label, index)
  end

  def validate_register_decision(decision, label, known_requirements)
    unless decision.is_a?(Hash)
      errors << "#{decision_register_label} #{label}: decision must be an object"
      return
    end

    status = decision['status']
    disposition = decision['canonical_disposition']
    errors << "#{decision_register_label} #{label}: invalid decision status #{status.inspect}" unless DECISION_STATUSES.include?(status)
    unless CANONICAL_DISPOSITIONS.include?(disposition)
      errors << "#{decision_register_label} #{label}: invalid canonical_disposition #{disposition.inspect}"
    end

    target = decision['target']
    unless target.is_a?(Hash)
      errors << "#{decision_register_label} #{label}: decision target must be an object"
      return
    end

    kind = target['kind']
    errors << "#{decision_register_label} #{label}: invalid target kind #{kind.inspect}" unless TARGET_KINDS.include?(kind)
    unless target['exclusions'].is_a?(Array) && target['exclusions'].all? { |value| nonempty_string?(value) }
      errors << "#{decision_register_label} #{label}: target exclusions must be an array of non-empty strings"
    end

    if status == 'pending'
      errors << "#{decision_register_label} #{label}: pending decision must keep canonical_disposition pending" unless disposition == 'pending'
      errors << "#{decision_register_label} #{label}: pending decision must keep target kind pending" unless kind == 'pending'
      errors << "#{decision_register_label} #{label}: pending decision target reference must be null" unless target['reference'].nil?
      errors << "#{decision_register_label} #{label}: pending decision exclusions must be empty" unless target['exclusions'] == []
      errors << "#{decision_register_label} #{label}: pending decision rationale must be null" unless decision['rationale'].nil?
      return
    end

    errors << "#{decision_register_label} #{label}: non-pending decision rationale must be a non-empty string" unless nonempty_string?(decision['rationale'])
    validate_canonical_target(disposition, target, label, known_requirements) if %w[approve defer].include?(status)
  end

  def validate_canonical_target(disposition, target, label, known_requirements)
    kind = target['kind']
    reference = target['reference']
    exclusions = target['exclusions']

    case disposition
    when 'reproduce', 'replace'
      errors << "#{decision_register_label} #{label}: #{disposition} requires a capability target" unless kind == 'capability' && nonempty_string?(reference)
    when 'consolidate'
      unless kind == 'consolidation_target' && nonempty_string?(reference) && known_requirements.key?(reference)
        errors << "#{decision_register_label} #{label}: consolidate requires a known PAR consolidation target"
      end
      errors << "#{decision_register_label} #{label}: consolidation cannot target itself" if reference == label
    when 'retire', 'exclude'
      unless kind == 'exclusion' && nonempty_string?(reference) && exclusions.is_a?(Array) && !exclusions.empty?
        errors << "#{decision_register_label} #{label}: #{disposition} requires an exclusion target and explicit exclusions"
      end
    else
      errors << "#{decision_register_label} #{label}: approved or deferred decision cannot keep canonical_disposition pending"
    end
  end

  def validate_decision_register_consolidation_graph(entries_by_batch, known_requirements)
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

    entries_by_batch.each_value do |entries|
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
    end

    detect_decision_register_consolidation_cycles(graph, register_edges)
    validate_batch_d_terminal_consolidations(entries_by_batch.fetch('D', []))
    validate_batch_e_consolidation_decisions(entries_by_batch.fetch('E', []))
    validate_batch_f_consolidation_decisions(entries_by_batch.fetch('F', []))
    validate_batch_f_actual_dependency_graph(entries_by_batch.fetch('F', []))
    validate_batch_g_consolidation_decisions(entries_by_batch.fetch('G', []))
    validate_batch_g_actual_dependency_graph(entries_by_batch.fetch('G', []))
  end

  def validate_batch_g_consolidation_decisions(entries)
    by_id = entries.to_h { |entry| [entry['requirement_id'], entry] }
    active = {}
    entries.each do |entry|
      decision = entry['decision'] if entry.is_a?(Hash)
      next unless decision.is_a?(Hash) && %w[approve defer].include?(decision['status']) && decision['canonical_disposition'] == 'consolidate'

      source = entry['requirement_id']
      candidate = batch_g_candidate_for(source)
      members = candidate && BATCH_G_CONSOLIDATION_GROUPS[candidate]
      target = decision.dig('target', 'reference')
      unless members && members.include?(target) && target != source
        errors << "Batch G decision register #{source}: consolidation is allowed only inside its frozen candidate"
        next
      end
      if active.key?(candidate) && active[candidate] != target
        errors << "Batch G decision register #{source}: candidate #{candidate} cannot use conflicting terminal targets"
      else
        active[candidate] = target
      end
      target_decision = by_id.dig(target, 'decision')
      unless target_decision.is_a?(Hash) && target_decision['status'] == 'approve' && %w[reproduce replace].include?(target_decision['canonical_disposition'])
        errors << "Batch G decision register #{source}: terminal target #{target} must be approved reproduce/replace"
      end
    end
    active.each do |candidate, target|
      members = BATCH_G_CONSOLIDATION_GROUPS.fetch(candidate)
      coherent = members.all? do |member|
        decision = by_id.dig(member, 'decision')
        if member == target
          decision.is_a?(Hash) && decision['status'] == 'approve' && %w[reproduce replace].include?(decision['canonical_disposition'])
        else
          decision.is_a?(Hash) && %w[approve defer].include?(decision['status']) && decision['canonical_disposition'] == 'consolidate' && decision.dig('target', 'reference') == target
        end
      end
      errors << "Batch G decision register candidate #{candidate}: every non-terminal member must resolve to one approved terminal target #{target}" unless coherent
      controls = members.map { |member| by_id.dig(member, 'consolidation_mapping') }
      unless controls.all? { |control| control.is_a?(Hash) && control['status'] == 'complete' && control['terminal_target_requirement_id'] == target }
        errors << "Batch G decision register candidate #{candidate}: every member must share one complete mapping to #{target}"
        next
      end
      references = controls.map { |control| [control['artifact_reference'], control['artifact_sha256']] }
      errors << "Batch G decision register candidate #{candidate}: every member must bind the same mapping artifact and SHA-256" unless references.uniq.length == 1
    end
  end

  def validate_batch_g_actual_dependency_graph(entries)
    graph = {}
    entries.each do |entry|
      next unless entry.is_a?(Hash) && nonempty_string?(entry['requirement_id'])

      source = entry['requirement_id']
      targets = Array(entry['intra_batch_dependencies']).each_with_object([]) { |dependency, values| values << dependency['requirement_id'] if dependency.is_a?(Hash) }
      decision = entry['decision']
      if decision.is_a?(Hash) && %w[approve defer].include?(decision['status']) && decision['canonical_disposition'] == 'consolidate'
        target = decision.dig('target', 'reference')
        targets << target if EXPECTED_BATCH_G_IDS.include?(target)
      end
      graph[source] = targets
    end
    cycle = batch_f_dependency_cycle(graph)
    errors << "Batch G decision register dependency/consolidation cycle detected: #{cycle.join(' -> ')}" if cycle
  end

  def validate_batch_e_consolidation_decisions(entries)
    by_id = entries.to_h { |entry| [entry['requirement_id'], entry] }
    active_candidates = {}
    entries.each do |entry|
      decision = entry['decision'] if entry.is_a?(Hash)
      next unless decision.is_a?(Hash) && %w[approve defer].include?(decision['status']) && decision['canonical_disposition'] == 'consolidate'

      source = entry['requirement_id']
      candidate = batch_e_candidate_for(source)
      members = candidate && BATCH_E_CONSOLIDATION_GROUPS[candidate]
      target = decision.dig('target', 'reference')
      unless members && members.length > 1 && members.include?(target) && target != source
        errors << "Batch E decision register #{source}: consolidation is allowed only within its frozen multi-member audit candidate"
        next
      end
      if active_candidates.key?(candidate) && active_candidates[candidate] != target
        errors << "Batch E decision register #{source}: candidate #{candidate} cannot consolidate to conflicting terminal targets"
      else
        active_candidates[candidate] = target
      end
      target_decision = by_id.dig(target, 'decision')
      unless target_decision.is_a?(Hash) && %w[approve defer].include?(target_decision['status']) && target_decision['canonical_disposition'] != 'consolidate'
        errors << "Batch E decision register #{source}: consolidation target #{target} must have a resolved non-consolidation decision"
      end
    end
    active_candidates.each do |candidate, target|
      members = BATCH_E_CONSOLIDATION_GROUPS.fetch(candidate)
      coherent_decisions = members.all? do |member|
        decision = by_id.dig(member, 'decision')
        if member == target
          decision.is_a?(Hash) && %w[approve defer].include?(decision['status']) && decision['canonical_disposition'] != 'consolidate'
        else
          decision.is_a?(Hash) && %w[approve defer].include?(decision['status']) &&
            decision['canonical_disposition'] == 'consolidate' && decision.dig('target', 'reference') == target
        end
      end
      errors << "Batch E decision register candidate #{candidate}: every non-terminal member must resolve to the one terminal target #{target}" unless coherent_decisions
      controls = members.map { |member| by_id.dig(member, 'consolidation_mapping') }
      unless controls.all? { |control| control.is_a?(Hash) && control['status'] == 'complete' && control['terminal_target_requirement_id'] == target }
        errors << "Batch E decision register candidate #{candidate}: every member must share one complete mapping to terminal target #{target}"
        next
      end
      references = controls.map { |control| [control['artifact_reference'], control['artifact_sha256']] }
      unless references.uniq.length == 1
        errors << "Batch E decision register candidate #{candidate}: every member must bind the same consolidation artifact and SHA-256"
      end
    end
  end

  def validate_batch_f_consolidation_decisions(entries)
    by_id = entries.to_h { |entry| [entry['requirement_id'], entry] }
    active_candidates = {}
    entries.each do |entry|
      decision = entry['decision'] if entry.is_a?(Hash)
      next unless decision.is_a?(Hash) && %w[approve defer].include?(decision['status']) && decision['canonical_disposition'] == 'consolidate'

      source = entry['requirement_id']
      candidate = batch_f_candidate_for(source)
      members = candidate && BATCH_F_CONSOLIDATION_GROUPS[candidate]
      target = decision.dig('target', 'reference')
      unless members && members.include?(target) && target != source
        errors << "Batch F decision register #{source}: consolidation is allowed only inside its frozen candidate"
        next
      end
      if active_candidates.key?(candidate) && active_candidates[candidate] != target
        errors << "Batch F decision register #{source}: candidate #{candidate} cannot use conflicting terminal targets"
      else
        active_candidates[candidate] = target
      end
      target_decision = by_id.dig(target, 'decision')
      unless target_decision.is_a?(Hash) && target_decision['status'] == 'approve' && %w[reproduce replace].include?(target_decision['canonical_disposition'])
        errors << "Batch F decision register #{source}: terminal target #{target} must have an approved reproduce/replace decision"
      end
    end
    active_candidates.each do |candidate, target|
      members = BATCH_F_CONSOLIDATION_GROUPS.fetch(candidate)
      coherent = members.all? do |member|
        decision = by_id.dig(member, 'decision')
        if member == target
          decision.is_a?(Hash) && decision['status'] == 'approve' && %w[reproduce replace].include?(decision['canonical_disposition'])
        else
          decision.is_a?(Hash) && %w[approve defer].include?(decision['status']) && decision['canonical_disposition'] == 'consolidate' && decision.dig('target', 'reference') == target
        end
      end
      errors << "Batch F decision register candidate #{candidate}: every non-terminal member must resolve to the one terminal target #{target}" unless coherent
      controls = members.map { |member| by_id.dig(member, 'consolidation_mapping') }
      unless controls.all? { |control| control.is_a?(Hash) && control['status'] == 'complete' && control['terminal_target_requirement_id'] == target }
        errors << "Batch F decision register candidate #{candidate}: every member must share a complete mapping to #{target}"
        next
      end
      references = controls.map { |control| [control['artifact_reference'], control['artifact_sha256']] }
      errors << "Batch F decision register candidate #{candidate}: every member must bind one shared mapping artifact and SHA-256" unless references.uniq.length == 1
    end
  end

  def validate_batch_f_actual_dependency_graph(entries)
    graph = {}
    entries.each do |entry|
      next unless entry.is_a?(Hash) && nonempty_string?(entry['requirement_id'])

      source = entry['requirement_id']
      targets = Array(entry['intra_batch_dependencies']).each_with_object([]) { |dependency, values| values << dependency['requirement_id'] if dependency.is_a?(Hash) }
      decision = entry['decision']
      if decision.is_a?(Hash) && %w[approve defer].include?(decision['status']) && decision['canonical_disposition'] == 'consolidate'
        target = decision.dig('target', 'reference')
        targets << target if EXPECTED_BATCH_F_IDS.include?(target)
      end
      graph[source] = targets
    end
    cycle = batch_f_dependency_cycle(graph)
    errors << "Batch F decision register dependency/consolidation cycle detected: #{cycle.join(' -> ')}" if cycle
  end

  def validate_batch_d_terminal_consolidations(entries)
    by_id = entries.to_h { |entry| [entry['requirement_id'], entry] }
    allowed = { 'PAR-CLN-010' => 'PAR-CLN-009', 'PAR-CLN-012' => 'PAR-CLN-011' }
    entries.each do |entry|
      decision = entry['decision'] if entry.is_a?(Hash)
      next unless decision.is_a?(Hash) && %w[approve defer].include?(decision['status']) && decision['canonical_disposition'] == 'consolidate'
      source = entry['requirement_id']
      target = decision.dig('target', 'reference')
      unless allowed[source] == target
        errors << "Batch D decision register #{source}: consolidation is allowed only for PAR-CLN-010 -> PAR-CLN-009 or PAR-CLN-012 -> PAR-CLN-011"
      end
    end
    allowed.each do |source, required_target|
      entry = by_id[source]
      decision = entry['decision'] if entry.is_a?(Hash)
      next unless decision.is_a?(Hash) && %w[approve defer].include?(decision['status']) && decision['canonical_disposition'] == 'consolidate'

      target = decision.dig('target', 'reference')
      errors << "Batch D decision register #{source}: consolidation target must be #{required_target}" unless target == required_target
      control = entry['lifecycle_mapping']
      unless control.is_a?(Hash) && control['status'] == 'complete' && control['terminal_target_requirement_id'] == required_target
        errors << "Batch D decision register #{source}: consolidation requires complete mapping to terminal target #{required_target}"
      end
      target_decision = by_id.dig(required_target, 'decision')
      unless target_decision.is_a?(Hash) && %w[approve defer].include?(target_decision['status']) && target_decision['canonical_disposition'] != 'consolidate'
        errors << "Batch D decision register #{source}: terminal target #{required_target} must have a resolved non-consolidation decision"
      end
    end
  end

  def detect_decision_register_consolidation_cycles(graph, register_edges)
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
            errors << "G0 decision registers: consolidation cycle detected: #{cycle.join(' -> ')}"
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

  def validate_decision_scenario(scenario, label, name)
    unless scenario.is_a?(Hash)
      errors << "#{decision_register_label} #{label}: synthetic scenario #{name} must be an object"
      return
    end

    status = scenario['status']
    errors << "#{decision_register_label} #{label}: invalid synthetic scenario status #{status.inspect}" unless SCENARIO_STATUSES.include?(status)
    errors << "#{decision_register_label} #{label}: synthetic scenario #{name} data_class must be synthetic" unless scenario['data_class'] == 'synthetic'
    errors << "#{decision_register_label} #{label}: synthetic scenario #{name} description must be a non-empty string" unless nonempty_string?(scenario['description'])
    validate_nonempty_string_array(scenario['expected_results'], "#{decision_register_label} #{label}: synthetic scenario #{name} expected_results")
    if @active_decision_context[:batch] == 'F'
      validate_closed_object(scenario, BATCH_F_SCENARIO_KEYS, "#{decision_register_label} #{label}: synthetic scenario #{name}")
      return unless BATCH_F_ROW_LIFECYCLES.key?(label)

      expected = batch_f_required_scenario_contract(label, name)
      expected.each do |key, value|
        errors << "#{decision_register_label} #{label}: synthetic scenario #{name} #{key} must exactly match the frozen per-ID lifecycle and hazard contract" unless scenario[key] == value
      end
      return
    end
    if @active_decision_context[:batch] == 'G'
      validate_closed_object(scenario, BATCH_G_SCENARIO_KEYS, "#{decision_register_label} #{label}: synthetic scenario #{name}")
      return unless EXPECTED_BATCH_G_IDS.include?(label)

      expected = batch_g_required_scenario_contract(label, name)
      expected.each do |key, value|
        errors << "#{decision_register_label} #{label}: synthetic scenario #{name} #{key} must exactly match the per-ID definition, access, reconciliation and failure contract" unless scenario[key] == value
      end
      return
    end
    if @active_decision_context[:batch] == 'E'
      validate_closed_object(scenario, BATCH_E_SCENARIO_KEYS, "#{decision_register_label} #{label}: synthetic scenario #{name}")
      return unless batch_e_family_for(label)

      expected = batch_e_required_scenario_contract(label, name)
      expected.each do |key, value|
        errors << "#{decision_register_label} #{label}: synthetic scenario #{name} #{key} must exactly match the frozen family and row contract" unless scenario[key] == value
      end
      return
    end
    return unless @active_decision_context[:batch] == 'D'

    validate_closed_object(scenario, %w[status data_class description expected_results required_behaviors preconditions actions assertions], "#{decision_register_label} #{label}: synthetic scenario #{name}")
    expected_behaviors = batch_d_required_scenario_behaviors(label, name)
    unless scenario['required_behaviors'] == expected_behaviors
      errors << "#{decision_register_label} #{label}: synthetic scenario #{name} required_behaviors must exactly match #{expected_behaviors.inspect}"
    end
    return unless BATCH_D_CAPABILITY_KINDS.key?(label)

    expected_contract = batch_d_required_scenario_contract(label, name)
    %w[preconditions actions assertions].each do |key|
      unless scenario[key] == expected_contract.fetch(key)
        errors << "#{decision_register_label} #{label}: synthetic scenario #{name} #{key} must exactly match the frozen per-ID contract"
      end
    end
    if scenario['status'] == 'ready'
      unless scenario['description'].is_a?(String) && scenario['description'].strip.length >= 40
        errors << "#{decision_register_label} #{label}: ready synthetic scenario #{name} description must contain at least 40 characters"
      end
      results = scenario['expected_results']
      unless results.is_a?(Array) && results.length >= 2 && results.all? { |result| result.is_a?(String) && result.strip.length >= 10 }
        errors << "#{decision_register_label} #{label}: ready synthetic scenario #{name} requires at least two substantive expected results"
      end
    end
  end

  def batch_f_required_scenario_contract(label, name)
    lifecycle = BATCH_F_ROW_LIFECYCLES.fetch(label)
    family = batch_f_family_for(label)
    hazards = batch_f_row_hazards(label)
    pre_state, normal_action, normal_post_state, normal_assertion = lifecycle
    action, post_state, assertion, intent, expected_results = case name
                                                            when 'normal'
                                                              [normal_action, normal_post_state, normal_assertion,
                                                               'execute one authorized deterministic local flow and reconcile every applicable ledger receipt',
                                                               ["#{label} reaches #{normal_post_state} through #{normal_action} exactly once.", 'Every applicable integer minor-unit equation balances at the frozen cutoff without a projection write.']]
                                                            when 'denial'
                                                              ["reject_invalid_or_unauthorized_#{normal_action}", pre_state, "no_partial_state_for_#{normal_action}",
                                                               'deny an invalid, unauthorized, duplicated, stale-version, or unsafe request before any source-ledger mutation',
                                                               ["#{label} remains #{pre_state} and records an attributable denial.", 'No charge, bill, payment, claim, adjustment, journal, monitor, or outbound partial state is created.']]
                                                            when 'correction_or_amendment'
                                                              ["append_compensating_reversal_for_#{normal_action}", "#{normal_post_state}_with_linked_adjustment", 'prior_version_immutable_and_control_totals_reconciled',
                                                               'append an attributed reversal or adjustment without editing history, including after payer, procedure, coding, or stock correction',
                                                               ["#{label} preserves #{normal_post_state} and appends one reasoned actor-linked compensation.", 'Closed-period history remains immutable and the open-period control totals, AR, claims, and journal reconcile exactly where applicable.']]
                                                            when 'dependency_outage'
                                                              ["retry_#{normal_action}_after_ambiguous_ack", pre_state, 'not_sent_and_no_duplicate_event_after_idempotent_retry',
                                                               'fail closed on dependency outage, quarantine an ambiguous acknowledgement, and apply the closed idempotency replay contract',
                                                               ["#{label} remains #{pre_state}, NOT_SENT, with endpoint null and credentials absent.",
                                                                'The same idempotency key with the same payload returns the same synthetic outcome without a second event.',
                                                                'The same idempotency key with a different payload is rejected as a conflict before any mutation.',
                                                                'An ambiguous acknowledgement is quarantined until reconciliation proves the prior attempt outcome.',
                                                                'Recovery creates no duplicate charge, receipt, claim version, reversal, settlement, journal, monitor row, or outbound request.']]
                                                            end
    hazard_digest = Digest::SHA256.hexdigest(hazards.join('|'))
    {
      'description' => "For #{label} in family #{family}, #{intent}; assert #{assertion} from #{pre_state} to #{post_state} under the frozen ledger and hazard policy.",
      'expected_results' => expected_results,
      'contract_ref' => "#{label}:#{name}:#{pre_state}->#{action}->#{post_state}:#{hazard_digest}"
    }
  end

  def batch_e_required_scenario_contract(label, name)
    family = batch_e_family_for(label)
    focus = BATCH_E_ROW_CAPABILITY_FOCUS.fetch(label)
    lifecycle = BATCH_E_ROW_LIFECYCLES.fetch(label)
    hazards = batch_e_row_hazards(label)
    family_hazards = BATCH_E_FAMILY_HAZARDS.fetch(family)
    intent = {
      'normal' => 'complete one authorized synthetic flow and reconcile every applicable ledger projection',
      'denial' => 'reject an unauthorized, unsafe, duplicated, expired, quarantined, or negative-stock attempt without partial state',
      'correction_or_amendment' => 'append one attributed compensating correction while preserving immutable history and control totals',
      'dependency_outage' => 'fail closed during an unavailable C/D/F/G dependency and retry idempotently without outbound delivery'
    }.fetch(name)
    expected_results = {
      'normal' => ["#{label} records one synthetic idempotent outcome for family #{family}.", 'Source, destination, finance, and reporting control totals reconcile wherever the family contract marks them applicable.'],
      'denial' => ["#{label} creates no partial stock movement, dispense, receipt, charge, or projection.", 'The denial is attributable and inventory never becomes negative or issues an expired/quarantined lot.'],
      'correction_or_amendment' => ["#{label} preserves the prior ledger event and appends one linked compensating event.", 'The correction has an actor, reason, period, idempotency key, and one reconciled set of control totals.'],
      'dependency_outage' => ["#{label} remains NOT_SENT with no live endpoint, credential, or outbound request.", 'Retry is idempotent and produces neither a duplicate movement nor a duplicate charge after dependency recovery.']
    }.fetch(name)
    {
      'description' => "For #{label} #{focus} in #{family}, #{intent}.",
      'expected_results' => expected_results,
      'family_contract' => ["#{family}:#{name}", "#{focus}:#{name}", *family_hazards],
      'row_hazard_assertions' => hazards,
      'lifecycle_pre_state' => lifecycle.fetch(:pre_state),
      'lifecycle_transition' => lifecycle.fetch(:transition),
      'lifecycle_post_state' => lifecycle.fetch(:post_state),
      'capability_assertions' => [lifecycle.fetch(:assertion), "#{focus}:#{name}:#{lifecycle.fetch(:post_state)}"],
      'preconditions' => ['synthetic_only', 'availability:Soon', "#{label}:authorized_role", "#{family}:eligible_context", lifecycle.fetch(:pre_state)],
      'actions' => [lifecycle.fetch(:transition), "#{focus}:#{name}:#{lifecycle.fetch(:transition)}"],
      'assertions' => [lifecycle.fetch(:post_state), lifecycle.fetch(:assertion), *BATCH_E_LEDGER_INVARIANTS, *hazards]
    }
  end

  def batch_d_required_scenario_behaviors(label, name)
    capability_kind = BATCH_D_CAPABILITY_KINDS[label]
    return [] unless capability_kind
    common = {
      'normal' => %w[authorized_happy_path single_reconciled_result],
      'denial' => %w[invalid_or_unauthorized no_partial_state],
      'correction_or_amendment' => %w[prior_state_immutable reason_and_actor_attributed single_reconciliation],
      'dependency_outage' => %w[dependency_unavailable fail_closed idempotent_retry]
    }.fetch(name)
    focus = BATCH_D_SCENARIO_FOCUS.fetch(label)
    ["#{label}:#{name}", "#{focus}:#{name}", *common]
  end

  def batch_d_required_scenario_contract(label, name)
    focus = BATCH_D_SCENARIO_FOCUS.fetch(label)
    behaviors = batch_d_required_scenario_behaviors(label, name)
    {
      'preconditions' => ['synthetic_only', "#{focus}:eligible_context", "#{label}:authorized_role"],
      'actions' => ["#{label}:#{name}:execute"],
      'assertions' => ["#{focus}:#{name}:assert", *behaviors.drop(2)]
    }
  end

  def validate_accountable_owner(owner, label)
    unless owner.is_a?(Hash)
      errors << "#{decision_register_label} #{label}: accountable_owner must be an object"
      return
    end

    status = owner['appointment_status']
    errors << "#{decision_register_label} #{label}: invalid accountable owner appointment_status #{status.inspect}" unless APPOINTMENT_STATUSES.include?(status)
    errors << "#{decision_register_label} #{label}: accountable owner required_scope must be a non-empty string" unless nonempty_string?(owner['required_scope'])

    if status == 'pending'
      %w[identity appointed_scope appointment_date appointment_reference artifact_sha256].each do |key|
        errors << "#{decision_register_label} #{label}: pending accountable owner #{key} must be null" unless owner[key].nil?
      end
    elsif status == 'appointed'
      %w[identity authority_domain appointed_scope appointment_reference].each do |key|
        errors << "#{decision_register_label} #{label}: appointed accountable owner #{key} must be a non-empty string" unless nonempty_string?(owner[key])
      end
      if nonempty_string?(owner['identity']) && owner['identity'].match?(PLACEHOLDER_OWNER_PATTERN)
        errors << "#{decision_register_label} #{label}: appointed accountable owner identity must not be a placeholder"
      end
      unless owner['appointed_scope'] == owner['required_scope']
        errors << "#{decision_register_label} #{label}: appointed_scope must exactly match required_scope"
      end
      errors << "#{decision_register_label} #{label}: accountable owner appointment_date must be YYYY-MM-DD" unless iso_date?(owner['appointment_date'])
      validate_governance_artifact(
        reference: owner['appointment_reference'],
        expected_sha256: owner['artifact_sha256'],
        label: "#{decision_register_label} #{label}: accountable owner appointment",
        requirement_id: label,
        subject: 'accountable_owner',
        record: owner
      )
    end
  end

  def validate_appointment_dependencies(dependencies, co_owners, label)
    if !dependencies.is_a?(Array) || dependencies.empty?
      errors << "#{decision_register_label} #{label}: appointment_dependencies must be a non-empty array"
      return
    end

    dependencies.each_with_index do |dependency, index|
      unless dependency.is_a?(Hash)
        errors << "#{decision_register_label} #{label}: appointment_dependencies[#{index}] must be an object"
        next
      end

      prefix = "#{decision_register_label} #{label}: appointment_dependencies[#{index}]"
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
    errors << "#{decision_register_label} #{label}: appointment dependency authority_domain values must be unique: #{duplicates.join(', ')}" unless duplicates.empty?

    return unless co_owners.is_a?(Array) && co_owners.all? { |domain| nonempty_string?(domain) }

    missing = co_owners - domains
    unexpected = domains - co_owners
    unless missing.empty? && unexpected.empty?
      errors << "#{decision_register_label} #{label}: appointment dependencies must exactly cover co_owners; missing #{missing.sort.inspect}; unexpected #{unexpected.sort.inspect}"
    end
  end

  def validate_decision_approval(approval, label, decision, accountable_owner:, lead_authority_domain:)
    unless approval.is_a?(Hash)
      errors << "#{decision_register_label} #{label}: approval must be an object"
      return
    end

    status = approval['status']
    errors << "#{decision_register_label} #{label}: invalid approval status #{status.inspect}" unless APPROVAL_STATUSES.include?(status)
    unless approval['conditions'].is_a?(Array) && approval['conditions'].all? { |value| nonempty_string?(value) }
      errors << "#{decision_register_label} #{label}: approval conditions must be an array of non-empty strings"
    end

    if status == 'pending'
      pending_keys = %w[identity scope date reference artifact_sha256]
      pending_keys << 'authority_domain' if AUTHORITY_BOUND_BATCHES.include?(@active_decision_context[:batch])
      pending_keys.each do |key|
        errors << "#{decision_register_label} #{label}: pending approval #{key} must be null" unless approval[key].nil?
      end
      errors << "#{decision_register_label} #{label}: pending approval conditions must be empty" unless approval['conditions'] == []
      if decision.is_a?(Hash) && decision['status'] != 'pending'
        errors << "#{decision_register_label} #{label}: non-pending decision requires recorded approval"
      end
      return
    end

    if !decision.is_a?(Hash) || decision['status'] == 'pending'
      errors << "#{decision_register_label} #{label}: recorded approval cannot accompany a pending decision"
    end
    errors << "#{decision_register_label} #{label}: approval identity must be a non-empty string" unless nonempty_string?(approval['identity'])
    if nonempty_string?(approval['identity']) && approval['identity'].match?(PLACEHOLDER_OWNER_PATTERN)
      errors << "#{decision_register_label} #{label}: approval identity must not be a placeholder"
    end
    if AUTHORITY_BOUND_BATCHES.include?(@active_decision_context[:batch])
      unless nonempty_string?(approval['authority_domain'])
        errors << "#{decision_register_label} #{label}: approval authority_domain must be a non-empty string"
      end
      unless approval['authority_domain'] == lead_authority_domain
        errors << "#{decision_register_label} #{label}: approval authority_domain must match lead authority #{lead_authority_domain}"
      end
      owner_identity = accountable_owner['identity'] if accountable_owner.is_a?(Hash)
      unless nonempty_string?(owner_identity) && approval['identity'] == owner_identity
        errors << "#{decision_register_label} #{label}: approval identity must exactly match the appointed accountable owner"
      end
      owner_status = accountable_owner['appointment_status'] if accountable_owner.is_a?(Hash)
      unless owner_status == 'appointed'
        errors << "#{decision_register_label} #{label}: recorded approval requires an appointed accountable owner"
      end
    end
    errors << "#{decision_register_label} #{label}: approval scope must be a non-empty string" unless nonempty_string?(approval['scope'])
    errors << "#{decision_register_label} #{label}: approval date must be YYYY-MM-DD" unless iso_date?(approval['date'])
    errors << "#{decision_register_label} #{label}: approval reference must be a non-empty string" unless nonempty_string?(approval['reference'])
    validate_governance_artifact(
      reference: approval['reference'],
      expected_sha256: approval['artifact_sha256'],
      label: "#{decision_register_label} #{label}: approval",
      requirement_id: label,
      subject: 'approval',
      record: approval,
      decision: decision
    )
  end

  def validate_decision_g0_resolution(entry, label)
    decision = entry['decision']
    decision_status = decision['status'] if decision.is_a?(Hash)
    unless %w[approve defer].include?(decision_status)
      errors << "#{decision_register_label} #{label}: G0 remains open until decision status is approve or defer"
      return
    end

    evidence = entry['evidence']
    unless evidence.is_a?(Array) && evidence.any? { |record| record.is_a?(Hash) && EVIDENCE_CLASSES[0...-1].include?(record['evidence_class']) }
      errors << "#{decision_register_label} #{label}: G0-approved/deferred entry requires non-pending evidence"
    end
    owner = entry['accountable_owner']
    unless owner.is_a?(Hash) && owner['appointment_status'] == 'appointed'
      errors << "#{decision_register_label} #{label}: G0-approved/deferred entry requires an appointed accountable owner"
    end
    dependencies = entry['appointment_dependencies']
    unless dependencies.is_a?(Array) && !dependencies.empty? && dependencies.all? { |dependency| dependency.is_a?(Hash) && dependency['status'] == 'appointed' }
      errors << "#{decision_register_label} #{label}: G0-approved/deferred entry requires all appointment dependencies"
    end
    approval = entry['approval']
    unless approval.is_a?(Hash) && approval['status'] == 'recorded'
      errors << "#{decision_register_label} #{label}: G0-approved/deferred entry requires a recorded approval"
    end
    scenarios = entry['synthetic_scenarios']
    scenario_names = FOUR_SCENARIO_BATCHES.include?(@active_decision_context[:batch]) ? FOUR_SCENARIO_NAMES : %w[normal denial_or_correction]
    unless scenarios.is_a?(Hash) && scenario_names.all? { |name| scenarios[name].is_a?(Hash) && scenarios[name]['status'] == 'ready' }
      scenario_description = FOUR_SCENARIO_BATCHES.include?(@active_decision_context[:batch]) ? scenario_names.join(', ') : 'normal and denial/correction'
      errors << "#{decision_register_label} #{label}: G0-approved/deferred entry requires ready #{scenario_description} scenarios"
    end
    if @active_decision_context[:batch] == 'D'
      upstream = entry['upstream_requirement_dependencies']
      unless upstream.is_a?(Array) && !upstream.empty? && upstream.all? { |dependency| dependency.is_a?(Hash) && %w[resolved deferred].include?(dependency['status']) }
        errors << "#{decision_register_label} #{label}: G0-approved/deferred entry requires every structured upstream dependency resolved or authority-deferred"
      end

      if BATCH_D_LIFECYCLE_MAPPING_REQUIRED_IDS.include?(label) && decision_status == 'approve'
        substantive = evidence.is_a?(Array) && evidence.any? do |record|
          record.is_a?(Hash) && %w[O M I].include?(record['evidence_class']) && record['evidence_basis'] != 'route_or_menu'
        end
        errors << "#{decision_register_label} #{label}: diagnostic/procedure approval requires O/M/I evidence beyond route or menu observation" unless substantive
      end

    end
    if @active_decision_context[:batch] == 'E'
      substantive = evidence.is_a?(Array) && evidence.any? do |record|
        record.is_a?(Hash) && %w[O M I].include?(record['evidence_class']) && %w[behavioral_execution signed_operating_procedure reconciled_ledger].include?(record['evidence_basis'])
      end
      errors << "#{decision_register_label} #{label}: G0 requires behavioral or reconciled O/M/I evidence; structural capture alone cannot prove behavior" unless substantive

      gates = entry['dependency_gates']
      unless gates.is_a?(Array) && !gates.empty? && gates.all? { |gate| gate.is_a?(Hash) && %w[resolved deferred].include?(gate['status']) }
        errors << "#{decision_register_label} #{label}: G0 requires every C/D/F/G dependency gate resolved or validly authority-deferred"
      end
      reconciliation = entry['reconciliation_contract']
      if decision_status == 'approve' && !(reconciliation.is_a?(Hash) && reconciliation['status'] == 'complete')
        errors << "#{decision_register_label} #{label}: approval requires a complete synthetic cross-ledger reconciliation receipt"
      end
    end
    if @active_decision_context[:batch] == 'F'
      substantive = evidence.is_a?(Array) && evidence.any? do |record|
        record.is_a?(Hash) && %w[O M I].include?(record['evidence_class']) && %w[behavioral_execution signed_finance_policy reconciled_ledger integration_sandbox_result].include?(record['evidence_basis'])
      end
      errors << "#{decision_register_label} #{label}: G0 requires behavioral, policy, reconciled-ledger, or sandbox O/M/I evidence; structural capture alone cannot prove finance or claim behavior" unless substantive
      gates = entry['dependency_gates']
      unless gates.is_a?(Array) && !gates.empty? && gates.all? { |gate| gate.is_a?(Hash) && %w[resolved deferred].include?(gate['status']) }
        errors << "#{decision_register_label} #{label}: G0 requires every applicability-specific upstream and forward dependency gate resolved or validly authority-deferred"
      end
      dependencies = entry['intra_batch_dependencies']
      unless dependencies.is_a?(Array) && dependencies.all? { |dependency| dependency.is_a?(Hash) && dependency['status'] == 'resolved' }
        errors << "#{decision_register_label} #{label}: G0 requires every intra-Batch-F dependency resolved against an approved target"
      end
      reconciliation = entry['reconciliation_contract']
      if decision_status == 'approve' && !(reconciliation.is_a?(Hash) && reconciliation['status'] == 'complete')
        errors << "#{decision_register_label} #{label}: approval requires a complete applicability-specific integer minor-unit reconciliation"
      end
    end
    if @active_decision_context[:batch] == 'G'
      substantive = evidence.is_a?(Array) && evidence.any? do |record|
        record.is_a?(Hash) && %w[O M I].include?(record['evidence_class']) && %w[behavioral_execution signed_reporting_policy reconciled_ledger independent_output_verification].include?(record['evidence_basis'])
      end
      errors << "#{decision_register_label} #{label}: G0 requires behavioral, signed-policy, reconciled or independently verified O/M/I evidence" unless substantive
      source_dependencies = entry['source_dependencies']
      intra_dependencies = entry['intra_batch_dependencies']
      if decision_status == 'approve'
        unless source_dependencies.is_a?(Array) && !source_dependencies.empty? && source_dependencies.all? { |dependency| dependency.is_a?(Hash) && dependency['status'] == 'resolved' }
          errors << "#{decision_register_label} #{label}: approval requires every applicability-specific A-F source binding resolved against an approved source row"
        end
        unless intra_dependencies.is_a?(Array) && intra_dependencies.all? { |dependency| dependency.is_a?(Hash) && dependency['status'] == 'resolved' }
          errors << "#{decision_register_label} #{label}: approval requires every intra-G dependency resolved against an approved target"
        end
        reconciliation = entry['reconciliation_contract']
        expected_reconciliation_status = batch_g_semantic_spec(label).fetch(:reconciliation) ? 'complete' : 'not_applicable'
        errors << "#{decision_register_label} #{label}: approval requires reconciliation status #{expected_reconciliation_status} for its frozen capability kind" unless reconciliation.is_a?(Hash) && reconciliation['status'] == expected_reconciliation_status
        if BATCH_G_STATUTORY_IDS.include?(label)
          statutory = entry['statutory_definition']
          errors << "#{decision_register_label} #{label}: statutory/public-health approval requires a current signed definition and sponsor/source/security approvals" unless statutory.is_a?(Hash) && statutory['status'] == 'complete'
        end
      elsif decision_status == 'defer'
        pending_sources = Array(source_dependencies).select { |dependency| dependency.is_a?(Hash) && dependency['status'] != 'resolved' }
        pending_intra = Array(intra_dependencies).select { |dependency| dependency.is_a?(Hash) && dependency['status'] != 'resolved' }
        required_exclusions = [
          *BATCH_G_DEFERRAL_BASE_EXCLUSIONS,
          *pending_sources.map { |dependency| "missing_source:#{dependency['batch']}:#{dependency['requirement_id']}" },
          *pending_intra.map { |dependency| "missing_intra_g:#{dependency['requirement_id']}" }
        ]
        decision = entry['decision']
        safe = decision['canonical_disposition'] == 'exclude' && decision.dig('target', 'kind') == 'exclusion' && decision.dig('target', 'exclusions') == required_exclusions
        errors << "#{decision_register_label} #{label}: deferred source or definition readiness requires exact exclude/no-export/no-compliance/missing-source scope" unless safe
      end
    end
  end

  def validate_governance_artifact(reference:, expected_sha256:, label:, requirement_id:, subject:, record:, decision: nil)
    artifact = load_structured_json_artifact(reference, expected_sha256, label)
    return unless artifact

    expected_keys = if subject == 'approval' && @active_decision_context[:batch] == 'G'
                      BATCH_G_APPROVAL_ARTIFACT_KEYS
                    elsif subject == 'approval' && AUTHORITY_BOUND_BATCHES.include?(@active_decision_context[:batch])
                      AUTHORITY_BOUND_APPROVAL_ARTIFACT_KEYS
                    else
                      GOVERNANCE_ARTIFACT_KEYS.fetch(subject)
                    end
    validate_closed_object(artifact, expected_keys, label)
    errors << "#{label} artifact_type must be #{GOVERNANCE_ARTIFACT_TYPE}" unless artifact['artifact_type'] == GOVERNANCE_ARTIFACT_TYPE
    errors << "#{label} schema_version must be #{ARTIFACT_SCHEMA_VERSION}" unless artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION
    errors << "#{label} register_id does not match the decision register" unless artifact['register_id'] == @active_decision_context[:register_id]
    errors << "#{label} requirement_id does not match #{requirement_id}" unless artifact['requirement_id'] == requirement_id
    errors << "#{label} subject does not match #{subject}" unless artifact['subject'] == subject
    errors << "#{label} identity does not match the register" unless artifact['identity'] == record['identity']

    case subject
    when 'accountable_owner'
      errors << "#{label} authority_domain does not match the register" unless artifact['authority_domain'] == record['authority_domain']
      errors << "#{label} scope does not match appointed_scope" unless artifact['scope'] == record['appointed_scope']
      errors << "#{label} date does not match appointment_date" unless artifact['date'] == record['appointment_date']
    when 'appointment_dependency'
      errors << "#{label} authority_domain does not match the register" unless artifact['authority_domain'] == record['authority_domain']
      errors << "#{label} scope does not match required_scope" unless artifact['scope'] == record['required_scope']
      errors << "#{label} date does not match the appointment date" unless artifact['date'] == record['date']
    when 'approval'
      if AUTHORITY_BOUND_BATCHES.include?(@active_decision_context[:batch])
        errors << "#{label} authority_domain does not match the register" unless artifact['authority_domain'] == record['authority_domain']
      end
      errors << "#{label} scope does not match the register" unless artifact['scope'] == record['scope']
      errors << "#{label} date does not match the approval date" unless artifact['date'] == record['date']
      errors << "#{label} decision_status does not match the register decision" unless decision.is_a?(Hash) && artifact['decision_status'] == decision['status']
      errors << "#{label} canonical_disposition does not match the register decision" unless decision.is_a?(Hash) && artifact['canonical_disposition'] == decision['canonical_disposition']
      errors << "#{label} conditions do not match the register" unless artifact['conditions'] == record['conditions']
      validate_batch_g_control_approval_artifact(artifact, requirement_id, label) if @active_decision_context[:batch] == 'G'
    end

    validate_artifact_reviewer(artifact['reviewer'], artifact['identity'], label)
  end

  def validate_batch_g_control_approval_artifact(artifact, requirement_id, label)
    entry = @decision_entries.find { |candidate| candidate['batch'] == 'G' && candidate['requirement_id'] == requirement_id }
    unless entry
      errors << "#{label} cannot bind controls without the exact Batch G register entry"
      return
    end

    manifest = batch_g_control_manifest(entry)
    errors << "#{label} control_manifest_sha256 must bind the exact definition, source/intra resolutions, reconciliation, boundary/access/export and statutory definition" unless artifact['control_manifest_sha256'] == Digest::SHA256.hexdigest(JSON.generate(manifest))
    %w[definition_sha256 source_resolution_sha256s intra_resolution_sha256s reconciliation_sha256 output_boundary_sha256 statutory_definition_sha256].each do |key|
      errors << "#{label} #{key} must exactly match the approved control manifest" unless artifact[key] == manifest[key]
    end

    required_domains = batch_g_required_authorities(requirement_id)
    bindings = artifact['authority_bindings']
    actual_domains = Array(bindings).map { |binding| binding['authority_domain'] if binding.is_a?(Hash) }
    errors << "#{label} authority_bindings must exactly cover product/business decision authority, the report/formula lead, every source domain and security/privacy/export authority; product cannot substitute for another domain" unless bindings.is_a?(Array) && actual_domains == required_domains
    binding_identities = Array(bindings).map { |binding| binding['identity'] if binding.is_a?(Hash) }.compact
    errors << "#{label} authority signatures must use distinct appointed identities for independent domains" unless binding_identities.uniq.length == binding_identities.length
    Array(bindings).each_with_index do |binding, index|
      binding_label = "#{label} authority_bindings[#{index}]"
      validate_closed_object(binding, BATCH_G_CONTROL_APPROVAL_BINDING_KEYS, binding_label)
      next unless binding.is_a?(Hash)

      domain = binding['authority_domain']
      expected_identity = nil
      expected_reference = nil
      expected_sha = nil
      if domain == entry['lead_authority_domain']
        owner = entry['accountable_owner']
        if owner.is_a?(Hash) && owner['appointment_status'] == 'appointed'
          expected_identity = owner['identity']
          expected_reference = owner['appointment_reference']
          expected_sha = owner['artifact_sha256']
        end
      else
        appointment = Array(entry['appointment_dependencies']).find do |candidate|
          candidate.is_a?(Hash) && candidate['authority_domain'] == domain && candidate['status'] == 'appointed'
        end
        if appointment
          expected_identity = appointment['identity']
          expected_reference = appointment['reference']
          expected_sha = appointment['artifact_sha256']
        end
      end
      unless nonempty_string?(expected_identity) && binding['identity'] == expected_identity && binding['appointment_reference'] == expected_reference && binding['appointment_sha256'] == expected_sha
        errors << "#{binding_label} must bind the exact appointed authority identity and appointment SHA"
      end

      approval = load_structured_json_artifact(binding['approval_reference'], binding['approval_sha256'], "#{binding_label} signed approval")
      next unless approval

      approval_label = "#{binding_label} signed approval"
      validate_closed_object(approval, BATCH_G_AUTHORITY_APPROVAL_KEYS, approval_label)
      valid = approval['artifact_type'] == BATCH_G_AUTHORITY_APPROVAL_ARTIFACT_TYPE &&
        approval['schema_version'] == ARTIFACT_SCHEMA_VERSION && approval['register_id'] == BATCH_G_REGISTER_ID &&
        approval['requirement_id'] == requirement_id && approval['subject'] == 'control_authority_approval' &&
        approval['authority_domain'] == domain && approval['identity'] == binding['identity'] &&
        approval['control_manifest_sha256'] == artifact['control_manifest_sha256'] &&
        approval['decision_status'] == artifact['decision_status'] && approval['canonical_disposition'] == artifact['canonical_disposition'] &&
        approval['date'] == artifact['date']
      errors << "#{approval_label} must independently sign the exact control manifest and decision within its appointed authority" unless valid
      errors << "#{approval_label} date must be YYYY-MM-DD" unless iso_date?(approval['date'])
      validate_artifact_reviewer(approval['reviewer'], approval['identity'], approval_label)
    end
  end

  def validate_evidence_artifact(record, requirement_id, index)
    label = "#{decision_register_label} #{requirement_id}: evidence[#{index}]"
    artifact = load_structured_json_artifact(record['artifact_reference'], record['artifact_sha256'], label)
    return unless artifact

    expected_keys = %w[D E F G].include?(@active_decision_context[:batch]) ? BATCH_D_EVIDENCE_ARTIFACT_KEYS : EVIDENCE_ARTIFACT_KEYS
    validate_closed_object(artifact, expected_keys, label)
    errors << "#{label} artifact_type must be #{EVIDENCE_ARTIFACT_TYPE}" unless artifact['artifact_type'] == EVIDENCE_ARTIFACT_TYPE
    errors << "#{label} schema_version must be #{ARTIFACT_SCHEMA_VERSION}" unless artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION
    errors << "#{label} register_id does not match the decision register" unless artifact['register_id'] == @active_decision_context[:register_id]
    errors << "#{label} requirement_id does not match #{requirement_id}" unless artifact['requirement_id'] == requirement_id
    %w[evidence_class date source reference interpreter confidence].each do |key|
      errors << "#{label} #{key} does not match the register" unless artifact[key] == record[key]
    end
    if %w[D E F G].include?(@active_decision_context[:batch])
      errors << "#{label} evidence_basis does not match the register" unless artifact['evidence_basis'] == record['evidence_basis']
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

    register_directory = File.realpath(File.dirname(@active_decision_context[:register_path]))
    evidence_root = File.expand_path(@active_decision_context[:evidence_directory], register_directory)
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

  def iso_datetime?(value)
    return false unless nonempty_string?(value)

    DateTime.iso8601(value)
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

    DECISION_REGISTER_CONFIGS.each do |batch, config|
      expected_exact_ids = config[:expected_ids]
      actual_ids = batches[batch].is_a?(Array) ? batches[batch].sort : []
      missing_from_batch = expected_exact_ids - actual_ids
      unexpected_in_batch = actual_ids - expected_exact_ids
      unless missing_from_batch.empty? && unexpected_in_batch.empty?
        errors << "batch manifest: Batch #{batch} exact set mismatch; missing #{missing_from_batch.sort.inspect}; unexpected #{unexpected_in_batch.sort.inspect}"
      end
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
    batch_b_decision_register: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_B_DECISION_REGISTER_2026-08-25.json',
    batch_c_decision_register: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_C_DECISION_REGISTER_2026-08-25.json',
    batch_d_decision_register: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_D_DECISION_REGISTER_2026-08-25.json',
    batch_e_decision_register: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_E_DECISION_REGISTER_2026-08-25.json',
    batch_f_decision_register: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_F_DECISION_REGISTER_2026-08-25.json',
    batch_g_decision_register: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_G_DECISION_REGISTER_2026-08-25.json',
    institutional_identity_key_registry: 'docs/new-simrs-rebuild/phase-0/G0_INSTITUTIONAL_IDENTITY_KEY_REGISTRY_2026-08-26.json',
    trusted_identity_root_sha256: nil,
    owner_snapshot_plan: ParityGovernanceValidator::DEFAULT_OWNER_SNAPSHOT_PLAN_PATH,
    owner_authority_policy: 'docs/new-simrs-rebuild/phase-0/G0_OWNER_AUTHORITY_POLICY_2026-08-25.json',
    owner_appointment_register: 'docs/new-simrs-rebuild/phase-0/G0_OWNER_APPOINTMENT_REGISTER_2026-08-25.json',
    decision_session_register: 'docs/new-simrs-rebuild/phase-0/G0_DECISION_SESSION_REGISTER_2026-08-25.json',
    owner_evidence_root: nil,
    release_index: 'docs/new-simrs-rebuild/phase-0/RELEASE_EVIDENCE_INDEX.md'
  }

  parser = OptionParser.new do |opts|
    opts.banner = 'Usage: ruby scripts/validate-parity-governance.rb [options]'
    opts.on('--mode MODE', %w[integrity g0], 'integrity (default) or g0') { |value| options[:mode] = value }
    opts.on('--matrix PATH', 'parity matrix Markdown path') { |value| options[:matrix] = value }
    opts.on('--baseline PATH', 'immutable matrix baseline JSON path') { |value| options[:baseline] = value }
    opts.on('--batch-manifest PATH', 'deterministic G0 batch manifest JSON path') { |value| options[:batch_manifest] = value }
    opts.on('--decision-register PATH', 'Batch A G0 decision register JSON path') { |value| options[:decision_register] = value }
    opts.on('--batch-b-decision-register PATH', 'Batch B G0 decision register JSON path') { |value| options[:batch_b_decision_register] = value }
    opts.on('--batch-c-decision-register PATH', 'Batch C G0 decision register JSON path') { |value| options[:batch_c_decision_register] = value }
    opts.on('--batch-d-decision-register PATH', 'Batch D G0 decision register JSON path') { |value| options[:batch_d_decision_register] = value }
    opts.on('--batch-e-decision-register PATH', 'Batch E G0 decision register JSON path') { |value| options[:batch_e_decision_register] = value }
    opts.on('--batch-f-decision-register PATH', 'Batch F G0 decision register JSON path') { |value| options[:batch_f_decision_register] = value }
    opts.on('--batch-g-decision-register PATH', 'Batch G G0 decision register JSON path') { |value| options[:batch_g_decision_register] = value }
    opts.on('--institutional-identity-key-registry PATH', 'institutional identity and public-key registry JSON path') { |value| options[:institutional_identity_key_registry] = value }
    opts.on('--trusted-identity-root-sha256 SHA', 'independently supplied institutional trust-root SHA-256') { |value| options[:trusted_identity_root_sha256] = value }
    opts.on('--owner-snapshot-plan PATH', 'closed owner-governance snapshot-plan JSON path') { |value| options[:owner_snapshot_plan] = value }
    opts.on('--owner-authority-policy PATH', 'closed owner authority policy JSON path') { |value| options[:owner_authority_policy] = value }
    opts.on('--owner-appointment-register PATH', 'owner appointment register JSON path') { |value| options[:owner_appointment_register] = value }
    opts.on('--decision-session-register PATH', 'owner decision session register JSON path') { |value| options[:decision_session_register] = value }
    opts.on('--owner-evidence-root PATH', 'authoritative owner evidence directory path') { |value| options[:owner_evidence_root] = value }
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
    batch_b_decision_register_path: options[:batch_b_decision_register],
    batch_c_decision_register_path: options[:batch_c_decision_register],
    batch_d_decision_register_path: options[:batch_d_decision_register],
    batch_e_decision_register_path: options[:batch_e_decision_register],
    batch_f_decision_register_path: options[:batch_f_decision_register],
    batch_g_decision_register_path: options[:batch_g_decision_register],
    institutional_identity_key_registry_path: options[:institutional_identity_key_registry],
    trusted_identity_root_sha256: options[:trusted_identity_root_sha256],
    owner_snapshot_plan_path: options[:owner_snapshot_plan],
    owner_authority_policy_path: options[:owner_authority_policy],
    owner_appointment_register_path: options[:owner_appointment_register],
    decision_session_register_path: options[:decision_session_register],
    owner_evidence_root_path: options[:owner_evidence_root],
    release_index_path: options[:release_index],
    mode: options[:mode]
  )

  if validator.validate
    batch_counts = validator.decision_entries_by_batch.sort.map { |batch, entries| "#{batch}=#{entries.length}" }.join(', ')
    puts "Parity governance #{options[:mode]} validation passed: #{validator.rows.length} requirements, #{validator.batch_assignments.length} batch assignments, #{validator.decision_entries.length} governed decisions (#{batch_counts}), #{validator.release_rows.length} release evidence rows"
  else
    warn "Parity governance #{options[:mode]} validation failed (#{validator.errors.length} errors):"
    validator.errors.each { |error| warn "- #{error}" }
    exit 1
  end
end
