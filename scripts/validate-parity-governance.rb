#!/usr/bin/env ruby
# frozen_string_literal: true

require 'date'
require 'digest'
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
  DECISION_REGISTER_CONFIGS = {
    'A' => { expected_count: EXPECTED_BATCH_COUNTS.fetch('A'), expected_ids: EXPECTED_BATCH_A_IDS, register_id: BATCH_A_REGISTER_ID, evidence_directory: BATCH_A_EVIDENCE_DIRECTORY }.freeze,
    'B' => { expected_count: EXPECTED_BATCH_COUNTS.fetch('B'), expected_ids: EXPECTED_BATCH_B_IDS, register_id: BATCH_B_REGISTER_ID, evidence_directory: BATCH_B_EVIDENCE_DIRECTORY }.freeze,
    'C' => { expected_count: EXPECTED_BATCH_COUNTS.fetch('C'), expected_ids: EXPECTED_BATCH_C_IDS, register_id: BATCH_C_REGISTER_ID, evidence_directory: BATCH_C_EVIDENCE_DIRECTORY }.freeze,
    'D' => { expected_count: EXPECTED_BATCH_COUNTS.fetch('D'), expected_ids: EXPECTED_BATCH_D_IDS, register_id: BATCH_D_REGISTER_ID, evidence_directory: BATCH_D_EVIDENCE_DIRECTORY }.freeze,
    'E' => { expected_count: EXPECTED_BATCH_COUNTS.fetch('E'), expected_ids: EXPECTED_BATCH_E_IDS, register_id: BATCH_E_REGISTER_ID, evidence_directory: BATCH_E_EVIDENCE_DIRECTORY }.freeze
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
  FOUR_SCENARIO_BATCHES = %w[C D E].freeze
  AUTHORITY_BOUND_BATCHES = %w[C D E].freeze
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

  PAR_ID_PATTERN = /\APAR-[A-Z0-9]+-\d{3}\z/.freeze
  REL_ID_PATTERN = /\AREL-(\d{8})-(\d{2})\z/.freeze
  PLACEHOLDER_OWNER_PATTERN = /(?:\bTBD\b|\bunknown\b|\bunassigned\b|\bpending\b|to[ _-]?be[ _-]?assigned|replace[ _-]?with|\bN\/?A\b)/i.freeze
  EVIDENCE_PLACEHOLDER_PATTERN = /(?:\bpending\b|not (?:committed|pushed|deployed|verified)|filled at commit|updated after push|\bunknown\b|\bTBD\b|\bN\/?A\b)/i.freeze

  attr_reader :batch_assignments, :decision_entries, :decision_entries_by_batch, :errors, :rows, :release_rows

  def initialize(matrix_path:, baseline_path:, release_index_path:, batch_manifest_path: 'docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json', decision_register_path: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_A_DECISION_REGISTER_2026-08-25.json', batch_b_decision_register_path: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_B_DECISION_REGISTER_2026-08-25.json', batch_c_decision_register_path: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_C_DECISION_REGISTER_2026-08-25.json', batch_d_decision_register_path: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_D_DECISION_REGISTER_2026-08-25.json', batch_e_decision_register_path: 'docs/new-simrs-rebuild/phase-0/G0_BATCH_E_DECISION_REGISTER_2026-08-25.json', mode: 'integrity')
    @matrix_path = File.expand_path(matrix_path)
    @baseline_path = File.expand_path(baseline_path)
    @release_index_path = File.expand_path(release_index_path)
    @batch_manifest_path = File.expand_path(batch_manifest_path)
    supplied_register_paths = {
      'A' => File.expand_path(decision_register_path),
      'B' => File.expand_path(batch_b_decision_register_path),
      'C' => File.expand_path(batch_c_decision_register_path),
      'D' => File.expand_path(batch_d_decision_register_path),
      'E' => File.expand_path(batch_e_decision_register_path)
    }
    @decision_register_paths = DECISION_REGISTER_CONFIGS.keys.to_h do |batch|
      [batch, supplied_register_paths.fetch(batch)]
    end
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
      if %w[D E].include?(@active_decision_context[:batch]) && !record['evidence_basis'].nil?
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
  end

  def validate_governance_artifact(reference:, expected_sha256:, label:, requirement_id:, subject:, record:, decision: nil)
    artifact = load_structured_json_artifact(reference, expected_sha256, label)
    return unless artifact

    expected_keys = if subject == 'approval' && AUTHORITY_BOUND_BATCHES.include?(@active_decision_context[:batch])
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
    end

    validate_artifact_reviewer(artifact['reviewer'], artifact['identity'], label)
  end

  def validate_evidence_artifact(record, requirement_id, index)
    label = "#{decision_register_label} #{requirement_id}: evidence[#{index}]"
    artifact = load_structured_json_artifact(record['artifact_reference'], record['artifact_sha256'], label)
    return unless artifact

    expected_keys = %w[D E].include?(@active_decision_context[:batch]) ? BATCH_D_EVIDENCE_ARTIFACT_KEYS : EVIDENCE_ARTIFACT_KEYS
    validate_closed_object(artifact, expected_keys, label)
    errors << "#{label} artifact_type must be #{EVIDENCE_ARTIFACT_TYPE}" unless artifact['artifact_type'] == EVIDENCE_ARTIFACT_TYPE
    errors << "#{label} schema_version must be #{ARTIFACT_SCHEMA_VERSION}" unless artifact['schema_version'] == ARTIFACT_SCHEMA_VERSION
    errors << "#{label} register_id does not match the decision register" unless artifact['register_id'] == @active_decision_context[:register_id]
    errors << "#{label} requirement_id does not match #{requirement_id}" unless artifact['requirement_id'] == requirement_id
    %w[evidence_class date source reference interpreter confidence].each do |key|
      errors << "#{label} #{key} does not match the register" unless artifact[key] == record[key]
    end
    if %w[D E].include?(@active_decision_context[:batch])
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
