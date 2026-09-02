# frozen_string_literal: true

require 'minitest/autorun'

class StructuredEmergencyTriageDispositionV1LocalEngineeringAuthorizationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  AUTHORIZATION_PATH = 'docs/new-simrs-rebuild/phase-1/STRUCTURED_EMERGENCY_TRIAGE_DISPOSITION_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-01.md'
  README_PATH = 'docs/new-simrs-rebuild/phase-1/README.md'

  def setup
    @authorization = File.read(File.join(ROOT, AUTHORIZATION_PATH), encoding: Encoding::UTF_8)
  end

  def test_identity_coverage_and_local_only_authority_are_exact
    assert_includes @authorization, '# Structured IGD Triage-to-Disposition V1'
    assert_includes @authorization, 'Status: **locally authorized for bounded implementation and engineering verification**'
    assert_includes @authorization, 'Date: 2026-09-01'
    assert_includes @authorization, 'Coverage: `PAR-REG-002`, `PAR-CLN-002`, and `PAR-CLN-003`'
    assert_includes @authorization, 'Journey: `E2E-02` emergency registration, triage, treatment, and disposition'
    assert_includes @authorization, 'It does not confer Clinical/IGD-owner acceptance, Nursing acceptance, RMIK acceptance, parity acceptance, hosted readiness, deployment approval, or production use.'
    assert_includes @authorization, 'No commit, push, hosted migration, or deployment is authorized by this record.'
  end

  def test_exact_roles_and_authorization_before_lookup_are_fail_closed
    role_section = @authorization.split('## Actors and exact role boundaries', 2).last
                                 .split('## Managed triage vocabulary', 2).first

    assert_includes role_section, 'Exact `admin` manages the triage vocabulary only.'
    assert_includes role_section, 'Exact `registrar` retains the existing emergency-registration workflow, read-only status visibility, and executes a physician-approved inpatient handoff by selecting one current managed bed.'
    assert_includes role_section, 'A registrar cannot choose the clinical disposition, triage, or document care.'
    assert_includes role_section, 'Exact `nurse` records the initial triage assessment, appends reassessments, and authors nursing Draft/Final documentation.'
    assert_includes role_section, 'Exact `physician` authors medical Draft/Final documentation, reviews the current triage history, places existing governed laboratory/radiology orders, and signs one disposition.'
    assert_includes role_section, 'Exact `rmik` receives read-only clinical-readiness and provenance facts.'
    assert_includes role_section, 'No administrator, instructor, mixed-role account, or system-administrator flag may bypass these clinical boundaries.'
    assert_includes role_section, 'Every mutation checks capability before route-resource lookup'
    assert_includes role_section, 'Unauthorized actors receive no resource-existence disclosure.'
  end

  def test_permenkes_categories_are_fixed_and_director_sop_boundary_is_explicit
    vocabulary = @authorization.split('## Managed triage vocabulary', 2).last
                               .split('## Initial triage and reassessment', 2).first
    regulatory = @authorization.split('## Regulatory and evidence boundary', 2).last
                               .split('## Actors and exact role boundaries', 2).first

    assert_includes regulatory, 'requires every hospital to have a triage standard approved by its director'
    assert_includes regulatory, 'manually assigned `MERAH`, `KUNING`, `HIJAU`, and `HITAM` categories'
    assert_includes regulatory, 'assessment based on ABCDE, and continuous reassessment/retriage'
    assert_includes regulatory, 'It does not calculate a category, infer acuity from vital signs, recommend treatment, or claim that the campus catalogue is a director-approved hospital SOP.'
    assert_includes regulatory, 'it cannot add, remove, merge, reorder, or repurpose them'
    assert_includes regulatory, 'A category `HITAM` record is a bounded triage fact.'
    assert_includes regulatory, 'It does not itself declare death, establish cause or manner of death, authorize non-resuscitation, create a death certificate, or replace a physician disposition.'
    assert_includes vocabulary, 'stable code, display name, status, effective version, and immutable version history'
    assert_includes vocabulary, 'exactly the four fixed categories `MERAH`, `KUNING`, `HIJAU`, and `HITAM` in their canonical priority order'
    assert_includes vocabulary, 'without adding local symptom thresholds, diagnosis rules, target-time claims, or automated clinical advice'
    assert_includes vocabulary, 'Colour is never the sole indicator.'
    assert_includes vocabulary, 'the fixed codes and order cannot change'
    assert_includes vocabulary, 'Updates never rewrite historical assessments.'
    assert_includes vocabulary, 'only the current Active version may be selected for a new initial triage'
  end

  def test_manual_abcde_vital_missingness_and_late_entry_contract_is_exact
    triage = @authorization.split('## Initial triage and reassessment', 2).last
                            .split('## Structured nursing and medical documentation', 2).first

    assert_includes triage, 'Every active emergency encounter must receive one finalized initial triage before any governed nursing or medical write, diagnostic order, or disposition.'
    assert_includes triage, 'Finalizing the initial triage moves a `REGISTERED` encounter to `IN_EXAMINATION`; merely opening or partially filling the form does not change state.'
    assert_includes triage, 'the exact triage-vocabulary version and selected priority level'
    assert_includes triage, 'observed time, server-recorded time, exact nurse identity, and a late-entry reason when recorded more than fifteen minutes after observation'
    assert_includes triage, 'required manual clinical basis for the selected priority'
    assert_includes triage, 'manual ABCDE observations, each recorded as `ASSESSED_NO_CONCERN`, `ASSESSED_CONCERN`, or `NOT_ASSESSED` with a bounded note'
    assert_includes triage, 'respiratory rate in breaths/minute (`0..100`), pulse in beats/minute (`0..300`), paired systolic/diastolic pressure in mmHg (`0..300`), oxygen saturation in percent (`0..100`), temperature in Celsius (`20.0..45.0`), pain score (`0..10`), and optional weight in kilograms (`0.1..500.0`)'
    assert_includes triage, 'an explicit `unobtainable_fields` list and bounded reason for every expected observation that is absent; BP values must be supplied or marked unobtainable as a pair'
    assert_includes triage, 'Observed time may be up to 24 hours before registration for attributable pre-arrival observations and at most five minutes in the future for clock tolerance.'
    assert_includes triage, 'Zero values are preserved as observations and never interpreted by the software.'
    assert_includes triage, 'Triage does not record a medical intervention; treatment belongs to governed nursing/medical documentation.'
    assert_includes triage, 'does not interpret normality, infer severity, calculate early-warning scores, or change the selected category'
    assert_includes triage, 'Reassessment is an immutable append-only version that binds the prior assessment digest, reason, new manual category, repeated observations, assessor, observed/recorded times, and timestamp.'
    assert_includes triage, 'Same-content reassessment is allowed only with a documented reason; stale expected-version writes fail closed.'
    assert_includes triage, 'Reassessment is allowed only while the encounter is `IN_EXAMINATION` and before a current signed disposition.'
    assert_includes triage, 'After disposition or `READY_FOR_RM`, triage and clinical-document writes are rejected until a separately governed correction returns the episode to examination.'
  end

  def test_nursing_and_medical_documents_are_separate_immutable_version_chains
    documentation = @authorization.split('## Structured nursing and medical documentation', 2).last
                                  .split('## Diagnostic integration', 2).first

    assert_includes documentation, 'one nursing document head and one medical document head'
    assert_includes documentation, 'immutable versions with optimistic `expected_version` control'
    assert_includes documentation, 'no document -> DRAFT v1 -> DRAFT v2 ... -> FINAL vN'
    assert_includes documentation, 'Nursing documentation contains arrival condition, focused assessment, interventions, response/evaluation, safety or observation needs, and handoff note.'
    assert_includes documentation, 'Medical documentation contains anamnesis, focused physical examination, clinical impression, problem list, treatment/action plan, diagnostic-order rationale, and disposition-readiness note.'
    assert_includes documentation, 'Finalization appends an identical-content Final version; it does not mutate a Draft.'
    assert_includes documentation, 'A Final document cannot be silently edited or deleted.'
    assert_includes documentation, 'all new IGD clinical writes use the governed documents'
    assert_includes documentation, 'The legacy IGD write route becomes an explicit `410 Gone` fence and can no longer advance encounter state.'
  end

  def test_diagnostic_follow_up_and_receiving_inpatient_provenance_are_attributable
    diagnostics = @authorization.split('## Diagnostic integration', 2).last
                                .split('## Physician disposition and atomic inpatient handoff', 2).first

    assert_includes diagnostics, 'existing governed laboratory and radiology lifecycles remain the only diagnostic write paths'
    assert_includes diagnostics, 'order, specimen/performance, Verified/amended result, communication, and acknowledgement states'
    assert_includes diagnostics, 'according to the existing exact-role redaction rules'
    assert_includes diagnostics, 'Disposition may be signed while diagnostic work remains unresolved.'
    assert_includes diagnostics, 'the physician must create an immutable result-follow-up proposal to one current exact active physician, with assignment reason, effective time, and handoff note'
    assert_includes diagnostics, 'The proposed physician must explicitly accept the exact assignment fingerprint before responsibility transfers; self-assignment by the current accountable physician is accepted atomically.'
    assert_includes diagnostics, 'Until acceptance, the original ordering physician remains accountable and the disposition cannot rely on the proposal.'
    assert_includes diagnostics, 'The original ordering physician or the current accepted covering physician may acknowledge the exact current result fingerprint'
    assert_includes diagnostics, 'covering acknowledgement is attributable and requires the accepted assignment reference'
    assert_includes diagnostics, 'A later amendment still makes an earlier acknowledgement stale.'
    assert_includes diagnostics, 'Reassignment is append-only, requires a reason and fresh assignee acceptance, and cannot remove accountability while unresolved evidence remains.'
    assert_includes diagnostics, 'Those orders stay bound to the emergency encounter'
    assert_includes diagnostics, 'their existing acknowledgement and later IGD-closure blockers remain effective'
    assert_includes diagnostics, 'does not copy, re-parent, or silently reinterpret an order into the inpatient encounter'
    assert_includes diagnostics, 'The linked inpatient read model must expose a read-only source-IGD projection containing the triage timeline, current Final nursing/medical documents, disposition and correction chain, diagnostic statuses/results allowed by the actor\'s role, and current follow-up assignment.'
    assert_includes diagnostics, 'Source and target same-patient/link integrity is verified on every projection.'
  end

  def test_five_dispositions_and_separated_admission_handoff_are_closed
    disposition = @authorization.split('## Physician disposition and atomic inpatient handoff', 2).last
                                .split('## Cancellation, correction, and downstream safety', 2).first
    outcomes = disposition.scan(/^- `([A-Z_]+)`:/).flatten

    assert_equal %w[PULANG DIRUJUK RAWAT_INAP MENINGGAL_DI_IGD DOA], outcomes
    assert_includes disposition, 'Only a physician may create and sign the disposition.'
    assert_includes disposition, 'Bed selection and creation of the linked inpatient encounter are a separate registrar-executed step after the physician decision'
    assert_includes disposition, '`MENINGGAL_DI_IGD`: physician-recorded event time, bounded clinical note, and identity of the recording physician'
    assert_includes disposition, '`DOA`: physician-recorded arrival/declaration time, bounded clinical note, and identity of the recording physician'
    assert_includes disposition, 'The death/DOA branches record bounded episode facts only; they do not create a certificate, cause/manner-of-death determination, mortuary workflow, statutory report, or external notification.'
    assert_includes disposition, 'A signed disposition requires a finalized initial triage and current Final nursing and medical documents.'
    assert_includes disposition, 'Its exact version is immutable.'
    assert_includes disposition, 'an exact registrar subsequently selects a current managed bed'
    assert_includes disposition, 'direct and IGD-origin admission both acquire the patient-level active-admission claim mutex first, then the bed-code mutex'
    assert_includes disposition, 'The service refuses a second active inpatient encounter for the same patient, even when two different IGD encounters race for different beds.'
    assert_includes disposition, 'creates exactly one inpatient encounter for the same patient with `continue_from=DARI_IGD`'
    assert_includes disposition, 'copies the locked payer/insurance snapshot, admission reason, visit/registration time, queue semantics, ward/class/bed snapshots, and actor attribution'
    assert_includes disposition, 'Any validation, same-patient claim, bed race, linkage, receipt, or audit failure rolls the entire handoff operation back without changing the signed clinical decision.'
    assert_includes disposition, 'It must never lock an encounter and then request either admission mutex.'
    assert_includes disposition, '`PULANG`, `DIRUJUK`, `MENINGGAL_DI_IGD`, and `DOA` move the emergency encounter to `READY_FOR_RM` when signed.'
    assert_includes disposition, '`RAWAT_INAP` remains `IN_EXAMINATION` until the registrar-executed inpatient handoff commits, then moves to `READY_FOR_RM`.'
  end

  def test_cancellation_and_correction_boundaries_prevent_partial_effects
    safety = @authorization.split('## Cancellation, correction, and downstream safety', 2).last
                           .split('## User experience contract', 2).first

    assert_includes safety, 'only before any triage, governed emergency document, diagnostic, or disposition evidence exists'
    assert_includes safety, 'Once triage exists, encounter cancellation is denied with an attributable reason and no partial mutation.'
    assert_includes safety, 'A signed disposition cannot be edited or deleted, but an exact physician may append a corrected disposition version with a bounded reason while the IGD encounter is not `CLOSED`.'
    assert_includes safety, 'The prior signed version remains visible and fingerprinted.'
    assert_includes safety, 'Correction temporarily returns the source encounter to `IN_EXAMINATION` until the corrected branch requirements are satisfied.'
    assert_includes safety, 'A pre-handoff `RAWAT_INAP` decision may be corrected directly.'
    assert_includes safety, 'For an executed handoff, the physician first appends an immutable correction intent containing the complete pre-signed replacement disposition, reason, current source/disposition/handoff/child fingerprints, and expiry.'
    assert_includes safety, 'This intent changes no active state and grants no bed authority.'
    assert_includes safety, 'An exact registrar then executes the accepted physician intent in one transaction, without changing any clinical field.'
    assert_includes safety, 'The service revalidates the intent and all bound fingerprints, requires the linked inpatient encounter to remain `REGISTERED` with no clinical, diagnostic, discharge, RMIK, transfer, medication, inventory, financial, or other downstream evidence'
    assert_includes safety, 'runs attributable pre-clinical inpatient cancellation and bed reconciliation, appends an immutable handoff-compensation event, activates the physician-signed replacement disposition, and audits every effect.'
    assert_includes safety, 'Stale, expired, revoked, replay-conflicting, or progressed-child intents fail closed.'
    assert_includes safety, 'Identical replay returns the original compensation.'
    assert_includes safety, 'Concurrent physician correction, registrar execution, child progression, cancellation, or bed operation yields one coherent outcome with no interval in which a corrected source and active old child are both current.'
    assert_includes safety, '`PULANG`, `DIRUJUK`, `MENINGGAL_DI_IGD`, and `DOA` corrections are append-only'
    assert_includes safety, 'A linked inpatient encounter cannot be duplicated by retry, concurrent request, or a second disposition.'
    assert_includes safety, 'A discharge/referral disposition does not create an inpatient encounter, stock movement, charge, claim, document transmission, or external message.'
    assert_includes safety, 'Every triage, document, order, reassessment, disposition, or handoff mutation is rejected after `CLOSED`/`CANCELLED`'
    assert_includes safety, 'ordinary triage/document writes are also rejected after a current signed disposition or `READY_FOR_RM` until an authorized correction returns the episode to examination'
  end

  def test_idempotency_audit_guards_reset_and_rollback_are_fail_closed
    guards = @authorization.split('## Concurrency, receipts, audit, and database guards', 2).last
                           .split('## Verification gate', 2).first

    assert_includes guards, 'normalized idempotency key, canonical payload SHA-256, optional correlation ID, and immutable short operation receipt unique on actor, operation, and canonical key'
    assert_includes guards, 'Same-key/same-payload replay returns the original result after recomputing retained evidence; changed-payload key reuse is denied and audited.'
    assert_includes guards, 'Ordinary triage, documentation, result-follow-up assignment, and physician-disposition writers lock the encounter first and then the relevant mutable vocabulary/document/disposition head.'
    assert_includes guards, 'Every operation that creates, moves, releases, cancels, compensates, discharges, closes, resets, or reconciles an active inpatient claim follows one global order: patient active-admission claim mutex, sorted bed-code mutexes, all involved encounters in deterministic order, wards, beds, operation-specific evidence, audit, and receipt.'
    assert_includes guards, 'This applies equally to direct admission, IGD-origin admission, bed transfer, ordinary inpatient cancellation, executed-handoff compensation, discharge/bed release, closure release where applicable, reset, and recovery reconciliation.'
    assert_includes guards, 'No path may acquire the patient claim after a bed mutex, encounter, ward, or bed.'
    assert_includes guards, 'Mutable heads have explicit legal-transition guards.'
    assert_includes guards, 'append-only'
    assert_includes guards, 'Vocabulary versions, triage assessments, document versions, follow-up proposals/acceptances, signed disposition/correction intents/versions, handoff/compensation events, and receipts are append-only.'
    assert_includes guards, 'Database constraints and triggers refuse evidence mutation/deletion, direct disposition changes, orphan or cross-patient handoffs, duplicate active inpatient claims, and PostgreSQL evidence-table truncation.'
    assert_includes guards, 'Schema-qualified Laravel tables are never passed as strings to `Rule::exists`.'
    assert_includes guards, 'A missing success audit rolls back the business mutation.'
    assert_includes guards, 'Bounded reset deletes diagnostic children, receiving-source projections, downstream linked inpatient graphs, admission-location facts, handoff/compensation events, disposition/follow-up/document/triage evidence, encounters, and patients in dependency order while preserving audit and managed-vocabulary evidence; bed occupancy must reconcile to zero afterward.'
    assert_includes guards, 'Migration rollback refuses while governed business rows or correlated audit facts remain.'
    assert_includes guards, 'The recovery snapshot includes vocabulary, triage, document, follow-up assignment, disposition/correction, handoff/compensation, linked inpatient, admission-location, active-patient-claim, and bed counts plus deterministic digests.'
    assert_includes guards, 'It refuses orphan links, source/target patient mismatch, two current active inpatient encounters for one patient, an uncompensated handoff to a cancelled child, or a compensated handoff whose bed remains claimed.'
  end

  def test_local_verification_requires_functional_accessibility_and_cross_engine_evidence
    gate = @authorization.split('## Verification gate', 2).last
                         .split('## Explicit exclusions', 2).first

    assert_equal 5, gate.scan(/^\d+\./).length
    assert_includes gate, 'registration integration, fixed four-category vocabulary, manual ABCDE triage, reassessment/retriage, observed/recorded/late-entry rules, per-field unobtainable reasons, exact role denial, authorization-before-lookup, structured nursing/medical Draft/Final, explicit legacy-write `410`'
    assert_includes gate, 'laboratory/radiology projection and covering follow-up acknowledgement, all five dispositions, receiving-inpatient source projection, append-only disposition correction, pre-handoff correction, eligible executed-handoff compensation, and progressed-child correction refusal'
    assert_includes gate, 'database/model/SQL guards, audit-contract, fresh migration, empty down/reapply, retained-evidence rollback refusal, cross-patient/cross-encounter denial, active-patient-admission claim, deterministic lock-order tests, and recovery-snapshot orphan/mismatch/refused-state tests'
    assert_includes gate, 'keyboard, error-focus, status announcement, text-plus-colour status, contrast, and 44-pixel target checks'
    assert_includes gate, 'full PHP, frontend, formatting, type, lint, production-build, and diff gates'
    assert_includes gate, 'disposable PostgreSQL 17 and MySQL 8.4 evidence'
    assert_includes gate, 'least-privilege runtime grants'
    assert_includes gate, 'cancellation-versus-triage, two distinct IGD encounters for one patient racing into different beds, two patients racing for one bed, admission-versus-inpatient-cancellation, admission-versus-discharge'
    assert_includes gate, 'two-actor correction-intent/compensation, stale-intent/child-progression races'
    assert_includes gate, 'covering-assignment proposal/acceptance and acknowledgement-versus-amended-result races'
    assert_includes gate, 'evidence-chain verification, atomic patient/bed reconciliation, reset/audit preservation, rollback refusal, and strict cleanup'
  end

  def test_exclusions_preserve_clinical_external_release_and_acceptance_boundaries
    exclusions = @authorization.split('## Explicit exclusions', 2).last

    assert_includes exclusions, 'automated triage scoring/category assignment, symptom thresholds, diagnosis, treatment recommendation, early-warning calculation, clinical alerting, or a claim that the campus catalogue is a director-approved hospital SOP'
    assert_includes exclusions, 'death certification, cause/manner-of-death determination, mortuary workflow, leaving against advice, disaster/mass-casualty mode, observation unit, ambulance dispatch, completed external referral/custody exchange, or encounter reopen'
    assert_includes exclusions, 'medication/prescription, administration, pharmacy, stock, tariff, charge, billing, cashier, claim, INA-CBG, BPJS/VClaim, SATUSEHAT, or reporting effects'
    assert_includes exclusions, 'live terminology, device, monitor, ambulance, LIS, PACS/RIS, printer, email, SMS, push notification, or external endpoint'
    assert_includes exclusions, 'real patient data, secrets, commit, push, deployment, hosted migration, or external write'
    assert_includes exclusions, 'Clinical/IGD-owner, Nursing, RMIK, facility, parity, hosted, production-readiness, G0, or G3 acceptance'
  end

  def test_phase_readme_marks_laboratory_igd_and_accommodation_engineering_complete_without_overclaim
    readme = File.read(File.join(ROOT, README_PATH), encoding: Encoding::UTF_8)

    assert_match(/CROSS_SETTING_LABORATORY_SPECIMEN_RESULT.*Local implementation and PostgreSQL\/MySQL verification complete; laboratory\/RMIK and parity acceptance open/, readme)
    assert_match(/STRUCTURED_EMERGENCY_TRIAGE_DISPOSITION.*Local implementation and PostgreSQL 17\.10\/MySQL 8\.4\.11 exact-engine engineering verification complete.*Clinical\/IGD, Nursing, RMIK, facility, and parity acceptance open; no hosted migration or deployment authority/, readme)
    assert_match(/CROSS_SETTING_LABORATORY_VERIFIED_RESULT_TARIFF_SOURCE.*T1_LOCAL_LABORATORY_TARIFF_SOURCE_EVIDENCE_2026-09-02\.md.*laboratory, finance\/cashier, clinical, RMIK, and parity acceptance open.*no invented price.*live integration, commit, hosted migration, or deployment authority/, readme)
    assert_includes readme, 'T1_LOCAL_STRUCTURED_EMERGENCY_TRIAGE_DISPOSITION_V1_EVIDENCE_2026-09-01.md'
    assert_match(/INPATIENT_ACCOMMODATION_OCCUPANCY_DAY_TARIFF_SOURCE.*Local implementation and PostgreSQL 17\.10\/MySQL 8\.4\.11 exact-engine engineering verification complete.*T1_LOCAL_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_EVIDENCE_2026-09-02\.md.*day-count-policy, affected-domain, and parity acceptance open.*no historical inference, invented price, proration, reversal, live integration, commit, hosted migration, or deployment authority/, readme)
    assert_match(/latest exact-engine record is .*T1_LOCAL_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_EVIDENCE_2026-09-02\.md.*ff2ea1bd851a9c3741c0a3c91b57d32f9088c41cc74ac083bc8ba4602cdc0b40.*affected-domain.*day-count-policy, and parity acceptance remain open, and G0\/G3 remain open/, readme)
    assert_includes readme, 'Payment, receivables, claims, BPJS/VClaim/SATUSEHAT, commit, push, hosted migration, deployment, and any production-data inference remain outside this local milestone.'
  end
end
