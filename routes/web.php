<?php

use App\Http\Controllers\Claims\AdvanceEClaimSimulationController;
use App\Http\Controllers\Claims\EClaimSimulationWorkspaceController;
use App\Http\Controllers\Clinical\ClinicalReviewWorkspaceController;
use App\Http\Controllers\Clinical\EncounterClosureWorkspaceController;
use App\Http\Controllers\Clinical\MedicalAssessmentWorkspaceController;
use App\Http\Controllers\Clinical\NursingIntakeWorkspaceController;
use App\Http\Controllers\Clinical\OrderResultWorkspaceController;
use App\Http\Controllers\Clinical\OutpatientEarlyDepartureWorkspaceController;
use App\Http\Controllers\Clinical\OutpatientSafetyDispositionWorkspaceController;
use App\Http\Controllers\Clinical\PharmacyWorkspaceController;
use App\Http\Controllers\Clinical\StoreClinicalReviewDecisionController;
use App\Http\Controllers\Clinical\StoreDiagnosticResultController;
use App\Http\Controllers\Clinical\StoreEncounterClosureReviewDecisionController;
use App\Http\Controllers\Clinical\StoreEncounterClosureVersionController;
use App\Http\Controllers\Clinical\StoreMedicalAssessmentVersionController;
use App\Http\Controllers\Clinical\StoreMedicationDispenseController;
use App\Http\Controllers\Clinical\StoreNursingIntakeVersionController;
use App\Http\Controllers\Clinical\StoreOutpatientEarlyDepartureController;
use App\Http\Controllers\Clinical\StoreOutpatientSafetyDispositionController;
use App\Http\Controllers\Clinical\StorePharmacyInterventionResponseController;
use App\Http\Controllers\Clinical\StorePharmacyReviewController;
use App\Http\Controllers\Clinical\StoreResultAcknowledgementController;
use App\Http\Controllers\Coding\CodingWorkspaceController;
use App\Http\Controllers\Coding\StoreCodingDecisionController;
use App\Http\Controllers\Coding\StoreCodingReviewDecisionController;
use App\Http\Controllers\Coding\StoreCodingSuggestionController;
use App\Http\Controllers\Coding\StoreProcedureCodingSuggestionController;
use App\Http\Controllers\Coding\SubmitCodingAssignmentController;
use App\Http\Controllers\Encounter\EncounterDebriefController;
use App\Http\Controllers\Encounter\EncounterOverviewController;
use App\Http\Controllers\Encounter\EncounterRecordTimelineController;
use App\Http\Controllers\Encounter\PublicQueueController;
use App\Http\Controllers\Encounter\ReviseDebriefNoteController;
use App\Http\Controllers\Encounter\StoreDebriefNoteController;
use App\Http\Controllers\Hospital\HospitalDeskController;
use App\Http\Controllers\Hospital\HospitalModuleDeskController;
use App\Http\Controllers\Hospital\PendaftaranDeskController;
use App\Http\Controllers\Interoperability\OutpatientInteroperabilityPreviewController;
use App\Http\Controllers\Patient\AppointmentCheckInController;
use App\Http\Controllers\Patient\RegistrationWorkspaceController;
use App\Http\Controllers\Patient\SyntheticRegistrationController;
use App\Http\Controllers\Patient\TerminateAppointmentController;
use App\Http\Controllers\RecordQuality\RecordQualityWorkspaceController;
use App\Http\Controllers\RecordQuality\StoreRecordCorrectionController;
use App\Http\Controllers\RecordQuality\StoreRecordQualityReviewController;
use App\Http\Controllers\RecordQuality\StoreRecordQualityReviewDecisionController;
use App\Http\Controllers\Reporting\DebriefEvidenceReportController;
use App\Http\Controllers\Reporting\OutpatientSummaryReportController;
use App\Http\Controllers\Work\WorkQueueController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/desk')->name('home');

Route::get('queue-display/{session}', PublicQueueController::class)
    ->middleware(['simulation', 'throttle:60,1'])
    ->name('queue-display');

Route::middleware(['auth', 'active.account', 'verified', 'simulation'])->group(function () {
    Route::get('desk', HospitalDeskController::class)->name('desk');
    Route::get('desk/pendaftaran', PendaftaranDeskController::class)->name('desk.pendaftaran');
    Route::get('desk/pemeriksaan', [HospitalModuleDeskController::class, 'pemeriksaan'])->name('desk.pemeriksaan');
    Route::get('desk/rekam-medis', [HospitalModuleDeskController::class, 'rekamMedis'])->name('desk.rekam-medis');
    Route::get('desk/apotek', [HospitalModuleDeskController::class, 'apotek'])->name('desk.apotek');
    Route::get('desk/klaim', [HospitalModuleDeskController::class, 'klaim'])->name('desk.klaim');
    Route::get('desk/laporan', [HospitalModuleDeskController::class, 'laporan'])->name('desk.laporan');
    Route::get('desk/bpjs', [HospitalModuleDeskController::class, 'bpjs'])->name('desk.bpjs');
    Route::get('desk/kasir', [HospitalModuleDeskController::class, 'kasir'])->name('desk.kasir');
    Route::get('work', WorkQueueController::class)->name('work');
    Route::get('sessions/{session}/registration', RegistrationWorkspaceController::class)
        ->name('sessions.registration');
    Route::post('sessions/{session}/registrations', [SyntheticRegistrationController::class, 'store'])
        ->name('sessions.registrations.store');
    Route::post('appointments/{appointment}/check-in', AppointmentCheckInController::class)
        ->name('appointments.check-in');
    Route::post('appointments/{appointment}/termination', TerminateAppointmentController::class)
        ->name('appointments.termination.store');
    Route::get('encounters/{encounter}', EncounterOverviewController::class)
        ->name('encounters.show');
    Route::get('encounters/{encounter}/timeline', EncounterRecordTimelineController::class)
        ->name('encounters.timeline.show');
    Route::get('encounters/{encounter}/debrief', EncounterDebriefController::class)
        ->name('encounters.debrief.show');
    Route::get('encounters/{encounter}/reports/outpatient-summary', OutpatientSummaryReportController::class)
        ->name('encounters.reports.outpatient-summary');
    Route::get('encounters/{encounter}/reports/debrief-evidence', DebriefEvidenceReportController::class)
        ->name('encounters.reports.debrief-evidence');
    Route::get('encounters/{encounter}/interoperability-preview', OutpatientInteroperabilityPreviewController::class)
        ->name('encounters.interoperability-preview.show');
    Route::get('encounters/{encounter}/eclaim-simulation', EClaimSimulationWorkspaceController::class)
        ->name('encounters.eclaim-simulation.show');
    Route::post('encounters/{encounter}/eclaim-simulation/advance', AdvanceEClaimSimulationController::class)
        ->name('encounters.eclaim-simulation.advance');
    Route::post('encounters/{encounter}/debrief/notes', StoreDebriefNoteController::class)
        ->name('encounters.debrief.notes.store');
    Route::post('debrief-notes/{note}/versions', ReviseDebriefNoteController::class)
        ->name('debrief-notes.versions.store');
    Route::get('encounters/{encounter}/nursing-intake', NursingIntakeWorkspaceController::class)
        ->name('encounters.nursing-intake.show');
    Route::post('encounters/{encounter}/nursing-intake/versions', StoreNursingIntakeVersionController::class)
        ->name('encounters.nursing-intake.versions.store');
    Route::get('encounters/{encounter}/safety-disposition', OutpatientSafetyDispositionWorkspaceController::class)
        ->name('encounters.safety-disposition.show');
    Route::post('encounters/{encounter}/safety-disposition', StoreOutpatientSafetyDispositionController::class)
        ->name('encounters.safety-disposition.store');
    Route::get('encounters/{encounter}/early-departure', OutpatientEarlyDepartureWorkspaceController::class)
        ->name('encounters.early-departure.show');
    Route::post('encounters/{encounter}/early-departure', StoreOutpatientEarlyDepartureController::class)
        ->name('encounters.early-departure.store');
    Route::get('encounters/{encounter}/medical-assessment', MedicalAssessmentWorkspaceController::class)
        ->name('encounters.medical-assessment.show');
    Route::post('encounters/{encounter}/medical-assessment/versions', StoreMedicalAssessmentVersionController::class)
        ->name('encounters.medical-assessment.versions.store');
    Route::get('encounters/{encounter}/order-results', OrderResultWorkspaceController::class)
        ->name('encounters.order-results.show');
    Route::post('service-requests/{serviceRequest}/results', StoreDiagnosticResultController::class)
        ->name('service-requests.results.store');
    Route::post('diagnostic-results/{result}/acknowledgements', StoreResultAcknowledgementController::class)
        ->name('diagnostic-results.acknowledgements.store');
    Route::get('encounters/{encounter}/pharmacy', PharmacyWorkspaceController::class)
        ->name('encounters.pharmacy.show');
    Route::post('medication-requests/{medicationRequest}/pharmacy-reviews', StorePharmacyReviewController::class)
        ->name('medication-requests.pharmacy-reviews.store');
    Route::post('pharmacy-interventions/{intervention}/responses', StorePharmacyInterventionResponseController::class)
        ->name('pharmacy-interventions.responses.store');
    Route::post('medication-requests/{medicationRequest}/dispenses', StoreMedicationDispenseController::class)
        ->name('medication-requests.dispenses.store');
    Route::get('encounters/{encounter}/closure', EncounterClosureWorkspaceController::class)
        ->name('encounters.closure.show');
    Route::post('encounters/{encounter}/closure/versions', StoreEncounterClosureVersionController::class)
        ->name('encounters.closure.versions.store');
    Route::post('encounter-closures/{closure}/review-decisions', StoreEncounterClosureReviewDecisionController::class)
        ->name('encounter-closures.review-decisions.store');
    Route::get('encounters/{encounter}/record-quality', RecordQualityWorkspaceController::class)
        ->name('encounters.record-quality.show');
    Route::post('encounters/{encounter}/record-quality/reviews', StoreRecordQualityReviewController::class)
        ->name('encounters.record-quality.reviews.store');
    Route::post('record-quality-findings/{finding}/corrections', StoreRecordCorrectionController::class)
        ->name('record-quality-findings.corrections.store');
    Route::post('record-quality-reviews/{review}/decisions', StoreRecordQualityReviewDecisionController::class)
        ->name('record-quality-reviews.decisions.store');
    Route::get('encounters/{encounter}/coding', CodingWorkspaceController::class)
        ->name('encounters.coding.show');
    Route::post('encounters/{encounter}/conditions/{condition}/coding-suggestions', StoreCodingSuggestionController::class)
        ->name('encounters.conditions.coding-suggestions.store');
    Route::post('encounters/{encounter}/procedures/{procedure}/coding-suggestions', StoreProcedureCodingSuggestionController::class)
        ->name('encounters.procedures.coding-suggestions.store');
    Route::post('coding-suggestion-runs/{run}/decisions', StoreCodingDecisionController::class)
        ->name('coding-suggestion-runs.decisions.store');
    Route::post('coding-assignments/{codingAssignment}/submit', SubmitCodingAssignmentController::class)
        ->name('coding-assignments.submit');
    Route::post('coding-assignments/{codingAssignment}/reviews', StoreCodingReviewDecisionController::class)
        ->name('coding-assignments.reviews.store');
    Route::get('clinical-entry-versions/{version}/review', ClinicalReviewWorkspaceController::class)
        ->name('clinical-versions.review.show');
    Route::post('clinical-entry-versions/{version}/review-decisions', StoreClinicalReviewDecisionController::class)
        ->name('clinical-versions.review-decisions.store');
});

require __DIR__.'/settings.php';
