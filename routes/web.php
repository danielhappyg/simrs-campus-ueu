<?php

use App\Http\Controllers\Clinical\LaboratoryController;
use App\Http\Controllers\Clinical\LaboratoryMasterController;
use App\Http\Controllers\Clinical\LaboratoryWorkflowController;
use App\Http\Controllers\Clinical\RadiologyMasterController;
use App\Http\Controllers\Clinical\RadiologyWorkflowController;
use App\Http\Controllers\Emergency\EmergencyDiagnosticFollowUpController;
use App\Http\Controllers\Emergency\EmergencyDispositionController;
use App\Http\Controllers\Emergency\EmergencyDocumentationController;
use App\Http\Controllers\Emergency\EmergencyExaminationController;
use App\Http\Controllers\Emergency\EmergencyInpatientHandoffController;
use App\Http\Controllers\Emergency\EmergencyRegistrationController;
use App\Http\Controllers\Emergency\EmergencyTriageController;
use App\Http\Controllers\Emergency\EmergencyTriageVocabularyController;
use App\Http\Controllers\Emergency\EmergencyTriageWorkflowController;
use App\Http\Controllers\Finance\FinanceAccommodationTariffController;
use App\Http\Controllers\Finance\FinanceBillController;
use App\Http\Controllers\Finance\FinanceCashierCollectionController;
use App\Http\Controllers\Finance\FinanceCashSettlementController;
use App\Http\Controllers\Finance\FinanceCashSettlementCorrectionController;
use App\Http\Controllers\Finance\FinanceLaboratoryTariffController;
use App\Http\Controllers\Finance\FinanceRadiologyTariffController;
use App\Http\Controllers\Finance\FinanceTariffMasterController;
use App\Http\Controllers\Inpatient\InpatientBedTransferController;
use App\Http\Controllers\Inpatient\InpatientDischargeController;
use App\Http\Controllers\Inpatient\InpatientExaminationController;
use App\Http\Controllers\Inpatient\InpatientRegistrationController;
use App\Http\Controllers\Inpatient\InpatientRmController;
use App\Http\Controllers\Inpatient\InpatientSummaryAddendumController;
use App\Http\Controllers\Inpatient\InpatientWardBedMasterController;
use App\Http\Controllers\ModulePlaceholderController;
use App\Http\Controllers\Outpatient\OutpatientConsentController;
use App\Http\Controllers\Outpatient\OutpatientExaminationController;
use App\Http\Controllers\Outpatient\OutpatientPostClosureAmendmentController;
use App\Http\Controllers\Outpatient\OutpatientPrintController;
use App\Http\Controllers\Outpatient\OutpatientRecapController;
use App\Http\Controllers\Outpatient\OutpatientRegistrationController;
use App\Http\Controllers\Outpatient\OutpatientRmAmendmentController;
use App\Http\Controllers\Outpatient\OutpatientRmController;
use App\Http\Controllers\Pharmacy\PharmacyDispensingController;
use App\Http\Controllers\Pharmacy\PharmacyMasterController;
use App\Http\Controllers\Pharmacy\PharmacyPrescriptionController;
use App\Http\Controllers\Pharmacy\PharmacyWorklistController;
use App\Http\Controllers\Rebuild\RebuildHomeController;
use App\Http\Controllers\Registration\EncounterCancellationController;
use App\Http\Controllers\Wilayah\WilayahController;
use App\Support\SimrsModuleCategories;
use Illuminate\Support\Facades\Route;

Route::middleware(['simulation'])->group(function (): void {
    Route::middleware(['auth', 'active.account', 'verified'])->group(function (): void {
        Route::get('/', RebuildHomeController::class)->name('home');

        Route::get('/wilayah/provinces', [WilayahController::class, 'provinces'])
            ->name('wilayah.provinces');
        Route::get('/wilayah/regencies/{provinceCode}', [WilayahController::class, 'regencies'])
            ->name('wilayah.regencies');
        Route::get('/wilayah/districts/{regencyCode}', [WilayahController::class, 'districts'])
            ->name('wilayah.districts');
        Route::get('/wilayah/villages/{districtCode}', [WilayahController::class, 'villages'])
            ->name('wilayah.villages');

        Route::get('/pendaftaran/rawat-jalan', [OutpatientRegistrationController::class, 'index'])
            ->name('pendaftaran.rawat-jalan.index');
        Route::post('/pendaftaran/rawat-jalan', [OutpatientRegistrationController::class, 'store'])
            ->name('pendaftaran.rawat-jalan.store');

        Route::get('/pendaftaran/igd', [EmergencyRegistrationController::class, 'index'])
            ->name('pendaftaran.igd.index');
        Route::post('/pendaftaran/igd', [EmergencyRegistrationController::class, 'store'])
            ->name('pendaftaran.igd.store');

        Route::get('/pendaftaran/rawat-inap', [InpatientRegistrationController::class, 'index'])
            ->name('pendaftaran.rawat-inap.index');
        Route::post('/pendaftaran/rawat-inap', [InpatientRegistrationController::class, 'store'])
            ->name('pendaftaran.rawat-inap.store');
        Route::post('/pendaftaran/rawat-inap/{encounter}/bed-transfer', InpatientBedTransferController::class)
            ->whereUlid('encounter')
            ->name('pendaftaran.rawat-inap.bed-transfer');

        Route::get('/manajemen-data/bangsal', [InpatientWardBedMasterController::class, 'index'])
            ->name('manajemen-data.bangsal.index');
        Route::post('/manajemen-data/bangsal/wards', [InpatientWardBedMasterController::class, 'storeWard'])
            ->name('manajemen-data.bangsal.wards.store');
        Route::patch('/manajemen-data/bangsal/wards/{ward}', [InpatientWardBedMasterController::class, 'updateWard'])
            ->name('manajemen-data.bangsal.wards.update');
        Route::post('/manajemen-data/bangsal/wards/{ward}/retire', [InpatientWardBedMasterController::class, 'retireWard'])
            ->name('manajemen-data.bangsal.wards.retire');
        Route::post('/manajemen-data/bangsal/wards/{ward}/beds', [InpatientWardBedMasterController::class, 'storeBed'])
            ->name('manajemen-data.bangsal.wards.beds.store');
        Route::patch('/manajemen-data/bangsal/beds/{bed}', [InpatientWardBedMasterController::class, 'updateBed'])
            ->name('manajemen-data.bangsal.beds.update');
        Route::post('/manajemen-data/bangsal/beds/{bed}/retire', [InpatientWardBedMasterController::class, 'retireBed'])
            ->name('manajemen-data.bangsal.beds.retire');

        Route::get('/manajemen-data/radiologi', [RadiologyMasterController::class, 'index'])
            ->name('radiology.masters.index');
        Route::post('/manajemen-data/radiologi', [RadiologyMasterController::class, 'store'])
            ->name('radiology.masters.store');
        Route::patch('/manajemen-data/radiologi/{master}', [RadiologyMasterController::class, 'update'])
            ->whereUlid('master')
            ->name('radiology.masters.update');
        Route::post('/manajemen-data/radiologi/{master}/retire', [RadiologyMasterController::class, 'retire'])
            ->whereUlid('master')
            ->name('radiology.masters.retire');

        Route::get('/manajemen-data/laboratorium', [LaboratoryMasterController::class, 'index'])
            ->name('laboratory.masters.index');
        Route::post('/manajemen-data/laboratorium', [LaboratoryMasterController::class, 'store'])
            ->name('laboratory.masters.store');
        Route::patch('/manajemen-data/laboratorium/{master}', [LaboratoryMasterController::class, 'update'])
            ->whereUlid('master')
            ->name('laboratory.masters.update');
        Route::post('/manajemen-data/laboratorium/{master}/retire', [LaboratoryMasterController::class, 'retire'])
            ->whereUlid('master')
            ->name('laboratory.masters.retire');

        Route::get('/manajemen-data/triage', [EmergencyTriageVocabularyController::class, 'index'])
            ->name('emergency.triage-vocabulary.index');
        Route::post('/manajemen-data/triage', [EmergencyTriageVocabularyController::class, 'create'])
            ->name('emergency.triage-vocabulary.create');
        Route::patch('/manajemen-data/triage/{vocabulary}', [EmergencyTriageVocabularyController::class, 'revise'])
            ->whereUlid('vocabulary')
            ->name('emergency.triage-vocabulary.revise');

        Route::get('/manajemen-data/apotek', [PharmacyMasterController::class, 'index'])
            ->name('pharmacy.masters.index');

        Route::get('/manajemen-data/tarif-komponen-biaya', [FinanceTariffMasterController::class, 'index'])
            ->name('finance.tariff.index');
        Route::get('/manajemen-data/tarif-komponen-biaya/pemetaan-radiologi', [FinanceRadiologyTariffController::class, 'index'])
            ->name('finance.radiology-tariff.index');
        Route::get('/manajemen-data/tarif-komponen-biaya/pemetaan-radiologi/{binding}/riwayat', [FinanceRadiologyTariffController::class, 'history'])
            ->whereUlid('binding')->name('finance.radiology-tariff.history');
        Route::post('/manajemen-data/tarif-komponen-biaya/pemetaan-radiologi', [FinanceRadiologyTariffController::class, 'create'])
            ->name('finance.radiology-tariff.create');
        Route::patch('/manajemen-data/tarif-komponen-biaya/pemetaan-radiologi/{binding}', [FinanceRadiologyTariffController::class, 'revise'])
            ->whereUlid('binding')->name('finance.radiology-tariff.revise');
        Route::post('/manajemen-data/tarif-komponen-biaya/pemetaan-radiologi/{binding}/nonaktifkan', [FinanceRadiologyTariffController::class, 'retire'])
            ->whereUlid('binding')->name('finance.radiology-tariff.retire');
        Route::get('/manajemen-data/tarif-komponen-biaya/pemetaan-laboratorium', [FinanceLaboratoryTariffController::class, 'index'])
            ->name('finance.laboratory-tariff.index');
        Route::get('/manajemen-data/tarif-komponen-biaya/pemetaan-laboratorium/{binding}/riwayat', [FinanceLaboratoryTariffController::class, 'history'])
            ->whereUlid('binding')->name('finance.laboratory-tariff.history');
        Route::post('/manajemen-data/tarif-komponen-biaya/pemetaan-laboratorium', [FinanceLaboratoryTariffController::class, 'create'])
            ->name('finance.laboratory-tariff.create');
        Route::patch('/manajemen-data/tarif-komponen-biaya/pemetaan-laboratorium/{binding}', [FinanceLaboratoryTariffController::class, 'revise'])
            ->whereUlid('binding')->name('finance.laboratory-tariff.revise');
        Route::post('/manajemen-data/tarif-komponen-biaya/pemetaan-laboratorium/{binding}/nonaktifkan', [FinanceLaboratoryTariffController::class, 'retire'])
            ->whereUlid('binding')->name('finance.laboratory-tariff.retire');
        Route::get('/manajemen-data/tarif-komponen-biaya/pemetaan-akomodasi', [FinanceAccommodationTariffController::class, 'index'])
            ->name('finance.accommodation-tariff.index');
        Route::get('/manajemen-data/tarif-komponen-biaya/pemetaan-akomodasi/{binding}/riwayat', [FinanceAccommodationTariffController::class, 'history'])
            ->whereUlid('binding')->name('finance.accommodation-tariff.history');
        Route::post('/manajemen-data/tarif-komponen-biaya/pemetaan-akomodasi', [FinanceAccommodationTariffController::class, 'create'])
            ->name('finance.accommodation-tariff.create');
        Route::patch('/manajemen-data/tarif-komponen-biaya/pemetaan-akomodasi/{binding}', [FinanceAccommodationTariffController::class, 'revise'])
            ->whereUlid('binding')->name('finance.accommodation-tariff.revise');
        Route::post('/manajemen-data/tarif-komponen-biaya/pemetaan-akomodasi/{binding}/nonaktifkan', [FinanceAccommodationTariffController::class, 'retire'])
            ->whereUlid('binding')->name('finance.accommodation-tariff.retire');
        Route::get('/manajemen-data/tarif-komponen-biaya/group/{group}/riwayat', [FinanceTariffMasterController::class, 'groupHistory'])
            ->whereUlid('group')->name('finance.tariff.groups.history');
        Route::post('/manajemen-data/tarif-komponen-biaya/group', [FinanceTariffMasterController::class, 'createGroup'])
            ->name('finance.tariff.groups.create');
        Route::patch('/manajemen-data/tarif-komponen-biaya/group/{group}', [FinanceTariffMasterController::class, 'reviseGroup'])
            ->whereUlid('group')->name('finance.tariff.groups.revise');
        Route::post('/manajemen-data/tarif-komponen-biaya/group/{group}/nonaktifkan', [FinanceTariffMasterController::class, 'retireGroup'])
            ->whereUlid('group')->name('finance.tariff.groups.retire');
        Route::get('/manajemen-data/tarif-komponen-biaya/komponen/{component}/riwayat', [FinanceTariffMasterController::class, 'componentHistory'])
            ->whereUlid('component')->name('finance.tariff.components.history');
        Route::post('/manajemen-data/tarif-komponen-biaya/komponen', [FinanceTariffMasterController::class, 'createComponent'])
            ->name('finance.tariff.components.create');
        Route::patch('/manajemen-data/tarif-komponen-biaya/komponen/{component}', [FinanceTariffMasterController::class, 'reviseComponent'])
            ->whereUlid('component')->name('finance.tariff.components.revise');
        Route::post('/manajemen-data/tarif-komponen-biaya/komponen/{component}/nonaktifkan', [FinanceTariffMasterController::class, 'retireComponent'])
            ->whereUlid('component')->name('finance.tariff.components.retire');
        Route::get('/manajemen-data/tarif-komponen-biaya/katalog/{catalogue}/riwayat', [FinanceTariffMasterController::class, 'catalogueHistory'])
            ->whereUlid('catalogue')->name('finance.tariff.catalogues.history');
        Route::post('/manajemen-data/tarif-komponen-biaya/katalog', [FinanceTariffMasterController::class, 'createCatalogue'])
            ->name('finance.tariff.catalogues.create');
        Route::patch('/manajemen-data/tarif-komponen-biaya/katalog/{catalogue}', [FinanceTariffMasterController::class, 'reviseCatalogue'])
            ->whereUlid('catalogue')->name('finance.tariff.catalogues.revise');
        Route::post('/manajemen-data/tarif-komponen-biaya/katalog/{catalogue}/nonaktifkan', [FinanceTariffMasterController::class, 'retireCatalogue'])
            ->whereUlid('catalogue')->name('finance.tariff.catalogues.retire');
        Route::get('/manajemen-data/tarif-komponen-biaya/tarif/{tariff}/riwayat', [FinanceTariffMasterController::class, 'tariffHistory'])
            ->whereUlid('tariff')->name('finance.tariff.items.history');
        Route::post('/manajemen-data/tarif-komponen-biaya/tarif', [FinanceTariffMasterController::class, 'createTariff'])
            ->name('finance.tariff.items.create');
        Route::patch('/manajemen-data/tarif-komponen-biaya/tarif/{tariff}', [FinanceTariffMasterController::class, 'reviseTariff'])
            ->whereUlid('tariff')->name('finance.tariff.items.revise');
        Route::post('/manajemen-data/tarif-komponen-biaya/tarif/{tariff}/nonaktifkan', [FinanceTariffMasterController::class, 'retireTariff'])
            ->whereUlid('tariff')->name('finance.tariff.items.retire');
        Route::post('/manajemen-data/apotek/obat', [PharmacyMasterController::class, 'storeMedicine'])
            ->name('pharmacy.medicines.store');
        Route::post('/manajemen-data/apotek/obat/{medicine}', [PharmacyMasterController::class, 'updateMedicine'])
            ->whereUlid('medicine')->name('pharmacy.medicines.update');
        Route::post('/manajemen-data/apotek/obat/{medicine}/pensiun', [PharmacyMasterController::class, 'retireMedicine'])
            ->whereUlid('medicine')->name('pharmacy.medicines.retire');
        Route::post('/manajemen-data/apotek/depo', [PharmacyMasterController::class, 'storeDepot'])
            ->name('pharmacy.depots.store');
        Route::post('/manajemen-data/apotek/depo/{depot}', [PharmacyMasterController::class, 'updateDepot'])
            ->whereUlid('depot')->name('pharmacy.depots.update');
        Route::post('/manajemen-data/apotek/depo/{depot}/pensiun', [PharmacyMasterController::class, 'retireDepot'])
            ->whereUlid('depot')->name('pharmacy.depots.retire');
        Route::post('/manajemen-data/apotek/lot', [PharmacyMasterController::class, 'storeLot'])
            ->name('pharmacy.lots.store');
        Route::post('/manajemen-data/apotek/lot/{lot}/koreksi', [PharmacyMasterController::class, 'correctLot'])
            ->whereUlid('lot')->name('pharmacy.lots.correct');
        Route::post('/manajemen-data/apotek/lot/{lot}/karantina', [PharmacyMasterController::class, 'quarantineLot'])
            ->whereUlid('lot')->name('pharmacy.lots.quarantine');
        Route::post('/manajemen-data/apotek/lot/{lot}/lepas-karantina', [PharmacyMasterController::class, 'releaseLot'])
            ->whereUlid('lot')->name('pharmacy.lots.release');

        Route::get('/apotek/resep', [PharmacyWorklistController::class, 'index'])
            ->name('pharmacy.worklist.index');
        Route::get('/apotek/riwayat', [PharmacyWorklistController::class, 'history'])
            ->name('pharmacy.history.index');
        Route::get('/apotek/kartu-stok', [PharmacyWorklistController::class, 'stockCard'])
            ->name('pharmacy.stock-card.index');
        Route::get('/apotek/resep/{prescription}', [PharmacyWorklistController::class, 'show'])
            ->whereUlid('prescription')->name('pharmacy.prescriptions.show');
        Route::post('/apotek/episode/{encounter}/resep', [PharmacyPrescriptionController::class, 'store'])
            ->whereUlid('encounter')->name('pharmacy.encounters.prescriptions.store');
        Route::post('/apotek/resep/{prescription}/draf', [PharmacyPrescriptionController::class, 'updateDraft'])
            ->whereUlid('prescription')->name('pharmacy.prescriptions.draft.update');
        Route::post('/apotek/resep/{prescription}/pesan', [PharmacyPrescriptionController::class, 'order'])
            ->whereUlid('prescription')->name('pharmacy.prescriptions.order');
        Route::post('/apotek/resep/{prescription}/pengganti', [PharmacyPrescriptionController::class, 'replace'])
            ->whereUlid('prescription')->name('pharmacy.prescriptions.replace');
        Route::post('/apotek/resep/{prescription}/batal', [PharmacyPrescriptionController::class, 'cancel'])
            ->whereUlid('prescription')->name('pharmacy.prescriptions.cancel');
        Route::post('/apotek/resep/{prescription}/verifikasi', [PharmacyDispensingController::class, 'verify'])
            ->whereUlid('prescription')->name('pharmacy.prescriptions.verify');
        Route::post('/apotek/resep/{prescription}/tolak', [PharmacyDispensingController::class, 'refuse'])
            ->whereUlid('prescription')->name('pharmacy.prescriptions.refuse');
        Route::post('/apotek/resep/{prescription}/siapkan', [PharmacyDispensingController::class, 'prepare'])
            ->whereUlid('prescription')->name('pharmacy.prescriptions.prepare');
        Route::post('/apotek/penyiapan/{preparation}/serahkan', [PharmacyDispensingController::class, 'handover'])
            ->whereUlid('preparation')->name('pharmacy.preparations.handover');
        Route::post('/apotek/resep/{prescription}/tutup-sisa', [PharmacyDispensingController::class, 'closeUnfilled'])
            ->whereUlid('prescription')->name('pharmacy.prescriptions.close-unfilled');
        Route::post('/apotek/resep/{prescription}/retur', [PharmacyDispensingController::class, 'recordReturn'])
            ->whereUlid('prescription')->name('pharmacy.prescriptions.returns.store');

        Route::get('/kasir/tagihan', [FinanceBillController::class, 'index'])
            ->name('finance.bills.index');
        Route::get('/kasir/tagihan/{encounter}', [FinanceBillController::class, 'show'])
            ->whereUlid('encounter')->name('finance.bills.show');
        Route::post('/kasir/tagihan/{encounter}/terbitkan', [FinanceBillController::class, 'issue'])
            ->whereUlid('encounter')->name('finance.bills.issue');
        Route::post('/kasir/tagihan/{encounter}/pelunasan-tunai', [FinanceCashSettlementController::class, 'store'])
            ->whereUlid('encounter')->name('finance.settlements.store');
        Route::get('/kasir/pelunasan/{settlement}/kuitansi', [FinanceCashSettlementController::class, 'receipt'])
            ->whereUlid('settlement')->name('finance.settlements.receipt');
        Route::get('/kasir/koreksi-pelunasan', [FinanceCashSettlementCorrectionController::class, 'index'])
            ->name('finance.settlement-corrections.index');
        Route::post('/kasir/pelunasan/{settlement}/koreksi', [FinanceCashSettlementCorrectionController::class, 'store'])
            ->whereUlid('settlement')->name('finance.settlement-corrections.store');
        Route::get('/kasir/koreksi-pelunasan/{correction}', [FinanceCashSettlementCorrectionController::class, 'show'])
            ->whereUlid('correction')->name('finance.settlement-corrections.show');
        Route::post('/kasir/koreksi-pelunasan/{correction}/tinjau', [FinanceCashSettlementCorrectionController::class, 'review'])
            ->whereUlid('correction')->name('finance.settlement-corrections.review');
        Route::post('/kasir/koreksi-pelunasan/{correction}/pengembalian', [FinanceCashSettlementCorrectionController::class, 'completeRefund'])
            ->whereUlid('correction')->name('finance.settlement-corrections.complete-refund');
        Route::get('/kasir/koreksi-pelunasan/{correction}/bukti-pengembalian', [FinanceCashSettlementCorrectionController::class, 'refundReceipt'])
            ->whereUlid('correction')->name('finance.settlement-corrections.refund-receipt');
        Route::get('/kasir/batch-penerimaan-kas', [FinanceCashierCollectionController::class, 'index'])
            ->name('finance.cashier-collections.index');
        Route::post('/kasir/batch-penerimaan-kas/buka', [FinanceCashierCollectionController::class, 'open'])
            ->name('finance.cashier-collections.open');
        Route::get('/kasir/batch-penerimaan-kas/penyerahan/{handoff}', [FinanceCashierCollectionController::class, 'handoffReceipt'])
            ->whereUlid('handoff')->name('finance.cashier-collections.handoff-receipt');
        Route::get('/kasir/batch-penerimaan-kas/{batch}', [FinanceCashierCollectionController::class, 'show'])
            ->whereUlid('batch')->name('finance.cashier-collections.show');
        Route::post('/kasir/batch-penerimaan-kas/{batch}/ajukan-tutup', [FinanceCashierCollectionController::class, 'requestClose'])
            ->whereUlid('batch')->name('finance.cashier-collections.close');
        Route::post('/kasir/batch-penerimaan-kas/{batch}/hitung-ulang', [FinanceCashierCollectionController::class, 'recount'])
            ->whereUlid('batch')->name('finance.cashier-collections.recount');
        Route::post('/kasir/batch-penerimaan-kas/{batch}/verifikasi', [FinanceCashierCollectionController::class, 'verify'])
            ->whereUlid('batch')->name('finance.cashier-collections.verify');
        Route::post('/kasir/batch-penerimaan-kas/{batch}/serahkan-setoran', [FinanceCashierCollectionController::class, 'handoff'])
            ->whereUlid('batch')->name('finance.cashier-collections.handoff');
        Route::post('/kasir/episode/{encounter}/sumber-biaya/sinkronkan', [FinanceBillController::class, 'synchronize'])
            ->whereUlid('encounter')->name('finance.sources.synchronize');

        Route::get('/pendaftaran/rekap', [OutpatientRecapController::class, 'index'])
            ->name('pendaftaran.rekap');
        Route::get('/pendaftaran/kunjungan/{encounter}/cetak', [OutpatientPrintController::class, 'show'])
            ->name('pendaftaran.kunjungan.cetak');
        Route::get('/pendaftaran/kunjungan/{encounter}/consent', [OutpatientConsentController::class, 'show'])
            ->name('pendaftaran.kunjungan.consent.show');
        Route::post('/pendaftaran/kunjungan/{encounter}/consent', [OutpatientConsentController::class, 'store'])
            ->name('pendaftaran.kunjungan.consent.store');
        Route::post('/pendaftaran/kunjungan/{encounter}/batalkan', EncounterCancellationController::class)
            ->name('pendaftaran.kunjungan.batalkan');

        Route::get('/pemeriksaan/rawat-jalan', [OutpatientExaminationController::class, 'index'])
            ->name('pemeriksaan.rawat-jalan.index');
        Route::get('/pemeriksaan/rawat-jalan/{encounter}', [OutpatientExaminationController::class, 'show'])
            ->name('pemeriksaan.rawat-jalan.show');
        Route::post('/pemeriksaan/rawat-jalan/{encounter}/documents/{documentType}/draft', [OutpatientExaminationController::class, 'saveDraft'])
            ->name('pemeriksaan.rawat-jalan.documents.draft');
        Route::post('/pemeriksaan/rawat-jalan/{encounter}/documents/{documentType}/final', [OutpatientExaminationController::class, 'finalize'])
            ->name('pemeriksaan.rawat-jalan.documents.final');
        Route::post('/pemeriksaan/rawat-jalan/{encounter}/laboratory-orders', [LaboratoryWorkflowController::class, 'storeOutpatientOrder'])
            ->whereUlid('encounter')
            ->name('laboratory.outpatient.orders.store');
        Route::post('/pemeriksaan/rawat-jalan/{encounter}/lab-orders', [LaboratoryController::class, 'retiredWrite'])
            ->whereUlid('encounter')
            ->name('pemeriksaan.rawat-jalan.lab-orders.store');
        Route::post('/pemeriksaan/rawat-jalan/{encounter}/radiology-orders', [RadiologyWorkflowController::class, 'storeOutpatientOrder'])
            ->whereUlid('encounter')
            ->name('radiology.outpatient.orders.store');
        Route::post('/pemeriksaan/rawat-jalan/{encounter}/amendments', [OutpatientPostClosureAmendmentController::class, 'submit'])
            ->name('pemeriksaan.rawat-jalan.amendments.store');
        Route::post('/pemeriksaan/rawat-jalan/amendments/{amendmentRequest}/decision', [OutpatientPostClosureAmendmentController::class, 'decide'])
            ->name('pemeriksaan.rawat-jalan.amendments.decision');
        Route::post('/pemeriksaan/rawat-jalan/amendments/{amendmentRequest}/addendum', [OutpatientPostClosureAmendmentController::class, 'saveAddendum'])
            ->name('pemeriksaan.rawat-jalan.amendments.addendum.store');
        Route::post('/pemeriksaan/rawat-jalan/amendments/{amendmentRequest}/addendum/finalize', [OutpatientPostClosureAmendmentController::class, 'finalizeAddendum'])
            ->name('pemeriksaan.rawat-jalan.amendments.addendum.finalize');

        Route::get('/pemeriksaan/laboratorium', [LaboratoryWorkflowController::class, 'index'])
            ->name('pemeriksaan.laboratorium.index');
        Route::post('/pemeriksaan/laboratorium/{order}/cancel', [LaboratoryWorkflowController::class, 'cancel'])
            ->whereUlid('order')
            ->name('laboratory.orders.cancel');
        Route::post('/pemeriksaan/laboratorium/{order}/results', [LaboratoryController::class, 'retiredWrite'])
            ->whereUlid('order')
            ->name('pemeriksaan.laboratorium.results.store');
        Route::post('/pemeriksaan/laboratorium/{order}/specimens', [LaboratoryWorkflowController::class, 'collectSpecimen'])
            ->whereUlid('order')
            ->name('laboratory.specimens.collect');
        Route::post('/pemeriksaan/laboratorium/specimens/{attempt}/receive', [LaboratoryWorkflowController::class, 'receiveSpecimen'])
            ->whereUlid('attempt')
            ->name('laboratory.specimens.receive');
        Route::post('/pemeriksaan/laboratorium/specimens/{attempt}/accept', [LaboratoryWorkflowController::class, 'acceptSpecimen'])
            ->whereUlid('attempt')
            ->name('laboratory.specimens.accept');
        Route::post('/pemeriksaan/laboratorium/specimens/{attempt}/reject', [LaboratoryWorkflowController::class, 'rejectSpecimen'])
            ->whereUlid('attempt')
            ->name('laboratory.specimens.reject');
        Route::post('/pemeriksaan/laboratorium/{order}/results/draft', [LaboratoryWorkflowController::class, 'saveResult'])
            ->whereUlid('order')
            ->name('laboratory.orders.results.save');
        Route::post('/pemeriksaan/laboratorium/{order}/results/verify', [LaboratoryWorkflowController::class, 'verifyResult'])
            ->whereUlid('order')
            ->name('laboratory.orders.results.verify');
        Route::post('/pemeriksaan/laboratorium/{order}/results/amendments', [LaboratoryWorkflowController::class, 'amendResult'])
            ->whereUlid('order')
            ->name('laboratory.orders.results.amend');
        Route::post('/pemeriksaan/laboratorium/{order}/results/acknowledgements', [LaboratoryWorkflowController::class, 'acknowledgeResult'])
            ->whereUlid('order')
            ->name('laboratory.orders.results.acknowledge');

        Route::get('/pemeriksaan/radiologi', [RadiologyWorkflowController::class, 'index'])
            ->name('radiology.worklist.index');
        Route::post('/pemeriksaan/radiologi/{order}/cancel', [RadiologyWorkflowController::class, 'cancel'])
            ->whereUlid('order')
            ->name('radiology.orders.cancel');
        Route::post('/pemeriksaan/radiologi/{order}/performed', [RadiologyWorkflowController::class, 'perform'])
            ->whereUlid('order')
            ->name('radiology.orders.perform');
        Route::post('/pemeriksaan/radiologi/{order}/report/draft', [RadiologyWorkflowController::class, 'saveReport'])
            ->whereUlid('order')
            ->name('radiology.orders.report.save');
        Route::post('/pemeriksaan/radiologi/{order}/report/verify', [RadiologyWorkflowController::class, 'verifyReport'])
            ->whereUlid('order')
            ->name('radiology.orders.report.verify');
        Route::post('/pemeriksaan/radiologi/{order}/report/amendments', [RadiologyWorkflowController::class, 'amendReport'])
            ->whereUlid('order')
            ->name('radiology.orders.report.amend');
        Route::post('/pemeriksaan/radiologi/{order}/report/acknowledgements', [RadiologyWorkflowController::class, 'acknowledgeReport'])
            ->whereUlid('order')
            ->name('radiology.orders.report.acknowledge');

        Route::get('/pemeriksaan/igd', [EmergencyExaminationController::class, 'index'])
            ->name('pemeriksaan.igd.index');
        Route::get('/pemeriksaan/igd/{encounter}', [EmergencyExaminationController::class, 'show'])
            ->whereUlid('encounter')
            ->name('pemeriksaan.igd.show');
        Route::post('/pemeriksaan/igd/{encounter}/entries', [EmergencyExaminationController::class, 'storeEntry'])
            ->whereUlid('encounter')
            ->name('pemeriksaan.igd.entries.store');
        Route::post('/pemeriksaan/igd/{encounter}/radiology-orders', [RadiologyWorkflowController::class, 'storeEmergencyOrder'])
            ->whereUlid('encounter')
            ->name('radiology.emergency.orders.store');
        Route::post('/pemeriksaan/igd/{encounter}/laboratory-orders', [LaboratoryWorkflowController::class, 'storeEmergencyOrder'])
            ->whereUlid('encounter')
            ->name('laboratory.emergency.orders.store');
        Route::post('/pemeriksaan/igd/{encounter}/triage/initial', [EmergencyTriageWorkflowController::class, 'finalizeInitial'])
            ->whereUlid('encounter')
            ->name('emergency.triage.initial');
        Route::post('/pemeriksaan/igd/{encounter}/triage/reassessments', [EmergencyTriageWorkflowController::class, 'reassess'])
            ->whereUlid('encounter')
            ->name('emergency.triage.reassess');
        Route::post('/pemeriksaan/igd/{encounter}/documents/{documentType}/draft', [EmergencyDocumentationController::class, 'saveDraft'])
            ->whereUlid('encounter')->whereIn('documentType', ['NURSING', 'MEDICAL'])
            ->name('emergency.documents.draft');
        Route::post('/pemeriksaan/igd/{encounter}/documents/{documentType}/finalize', [EmergencyDocumentationController::class, 'finalize'])
            ->whereUlid('encounter')->whereIn('documentType', ['NURSING', 'MEDICAL'])
            ->name('emergency.documents.finalize');
        Route::post('/pemeriksaan/igd/{encounter}/follow-up/{orderType}/{order}', [EmergencyDiagnosticFollowUpController::class, 'propose'])
            ->whereUlid('encounter')->whereIn('orderType', ['LABORATORY', 'RADIOLOGY'])->whereUlid('order')
            ->name('emergency.follow-up.propose');
        Route::post('/pemeriksaan/igd/follow-up/proposals/{proposal}/accept', [EmergencyDiagnosticFollowUpController::class, 'accept'])
            ->whereUlid('proposal')
            ->name('emergency.follow-up.accept');
        Route::post('/pemeriksaan/igd/{encounter}/disposition', [EmergencyDispositionController::class, 'sign'])
            ->whereUlid('encounter')
            ->name('emergency.disposition.sign');
        Route::post('/pemeriksaan/igd/{encounter}/disposition/corrections', [EmergencyDispositionController::class, 'correct'])
            ->whereUlid('encounter')
            ->name('emergency.disposition.correct');
        Route::post('/pemeriksaan/igd/{encounter}/disposition/correction-intents', [EmergencyDispositionController::class, 'createCorrectionIntent'])
            ->whereUlid('encounter')
            ->name('emergency.disposition.correction-intent');
        Route::post('/pemeriksaan/igd/disposition/correction-intents/{intent}/revoke', [EmergencyDispositionController::class, 'revokeCorrectionIntent'])
            ->whereUlid('intent')
            ->name('emergency.disposition.correction-intent.revoke');
        Route::post('/pemeriksaan/igd/{encounter}/disposition/handoff', [EmergencyInpatientHandoffController::class, 'execute'])
            ->whereUlid('encounter')
            ->name('emergency.disposition.handoff');
        Route::post('/pemeriksaan/igd/{encounter}/disposition/compensate', [EmergencyInpatientHandoffController::class, 'compensate'])
            ->whereUlid('encounter')
            ->name('emergency.disposition.compensate');

        Route::get('/pemeriksaan/rawat-inap', [InpatientExaminationController::class, 'index'])
            ->name('pemeriksaan.rawat-inap.index');
        Route::get('/pemeriksaan/rawat-inap/{encounter}', [InpatientExaminationController::class, 'show'])
            ->name('pemeriksaan.rawat-inap.show');
        Route::post('/pemeriksaan/rawat-inap/{encounter}/documents/{documentType}/draft', [InpatientExaminationController::class, 'saveDraft'])
            ->whereUlid('encounter')
            ->whereIn('documentType', ['NURSING_DAILY', 'MEDICAL_DAILY'])
            ->name('pemeriksaan.rawat-inap.documents.draft');
        Route::post('/pemeriksaan/rawat-inap/{encounter}/documents/{documentType}/finalize', [InpatientExaminationController::class, 'finalize'])
            ->whereUlid('encounter')
            ->whereIn('documentType', ['NURSING_DAILY', 'MEDICAL_DAILY'])
            ->name('pemeriksaan.rawat-inap.documents.finalize');
        Route::post('/pemeriksaan/rawat-inap/{encounter}/discharge-summary/draft', [InpatientExaminationController::class, 'saveDischargeSummaryDraft'])
            ->whereUlid('encounter')
            ->name('pemeriksaan.rawat-inap.discharge-summary.draft');
        Route::post('/pemeriksaan/rawat-inap/{encounter}/radiology-orders', [RadiologyWorkflowController::class, 'storeInpatientOrder'])
            ->whereUlid('encounter')
            ->name('radiology.inpatient.orders.store');
        Route::post('/pemeriksaan/rawat-inap/{encounter}/laboratory-orders', [LaboratoryWorkflowController::class, 'storeInpatientOrder'])
            ->whereUlid('encounter')
            ->name('laboratory.inpatient.orders.store');
        Route::post('/pemeriksaan/rawat-inap/{encounter}/discharge-summary/finalize', [InpatientExaminationController::class, 'finalizeDischargeSummary'])
            ->whereUlid('encounter')
            ->name('pemeriksaan.rawat-inap.discharge-summary.finalize');
        Route::post('/pemeriksaan/rawat-inap/{encounter}/discharge-coding-source/draft', [InpatientExaminationController::class, 'saveDischargeCodingSourceDraft'])->whereUlid('encounter')->name('pemeriksaan.rawat-inap.discharge-coding-source.draft');
        Route::post('/pemeriksaan/rawat-inap/{encounter}/discharge-coding-source/finalize', [InpatientExaminationController::class, 'finalizeDischargeCodingSource'])->whereUlid('encounter')->name('pemeriksaan.rawat-inap.discharge-coding-source.finalize');
        Route::post('/pemeriksaan/rawat-inap/{encounter}/discharge', InpatientDischargeController::class)
            ->whereUlid('encounter')
            ->name('pemeriksaan.rawat-inap.discharge.execute');
        Route::post('/pemeriksaan/rawat-inap/{encounter}/summary-addenda/requests', [InpatientSummaryAddendumController::class, 'submit'])
            ->whereUlid('encounter')
            ->name('pemeriksaan.rawat-inap.summary-addenda.requests.store');
        Route::post('/pemeriksaan/rawat-inap/summary-addenda/{correctionRequest}/decide', [InpatientSummaryAddendumController::class, 'decide'])
            ->whereUlid('correctionRequest')
            ->name('pemeriksaan.rawat-inap.summary-addenda.requests.decide');
        Route::post('/pemeriksaan/rawat-inap/summary-addenda/{correctionRequest}/draft', [InpatientSummaryAddendumController::class, 'draft'])
            ->whereUlid('correctionRequest')
            ->name('pemeriksaan.rawat-inap.summary-addenda.draft');
        Route::post('/pemeriksaan/rawat-inap/summary-addenda/{correctionRequest}/finalize', [InpatientSummaryAddendumController::class, 'finalize'])
            ->whereUlid('correctionRequest')
            ->name('pemeriksaan.rawat-inap.summary-addenda.finalize');

        Route::get('/pemeriksaan/triage', [EmergencyTriageController::class, 'index'])
            ->name('pemeriksaan.triage.index');
        Route::get('/pemeriksaan/triage/{encounter}', [EmergencyTriageController::class, 'show'])
            ->whereUlid('encounter')
            ->name('pemeriksaan.triage.show');

        Route::get('/rm/rawat-jalan', [OutpatientRmController::class, 'index'])
            ->name('rm.rawat-jalan.index');
        Route::get('/rm/rawat-jalan/{encounter}', [OutpatientRmController::class, 'show'])
            ->name('rm.rawat-jalan.show');
        Route::post('/rm/rawat-jalan/{encounter}/reviews', [OutpatientRmController::class, 'saveReview'])
            ->name('rm.rawat-jalan.reviews.store');
        Route::post('/rm/rawat-jalan/{encounter}/signoff', [OutpatientRmController::class, 'signoff'])
            ->name('rm.rawat-jalan.signoff');
        Route::post('/rm/rawat-jalan/amendments/{amendmentRequest}/reviews', [OutpatientRmAmendmentController::class, 'saveReview'])
            ->name('rm.rawat-jalan.amendments.reviews.store');
        Route::post('/rm/rawat-jalan/amendments/{amendmentRequest}/signoff', [OutpatientRmAmendmentController::class, 'signoff'])
            ->name('rm.rawat-jalan.amendments.signoff');

        Route::get('/rm/rawat-inap', [InpatientRmController::class, 'index'])
            ->name('rm.rawat-inap.index');
        Route::get('/rm/rawat-inap/{encounter}', [InpatientRmController::class, 'show'])
            ->whereUlid('encounter')
            ->name('rm.rawat-inap.show');
        Route::post('/rm/rawat-inap/{encounter}/coding/draft', [InpatientRmController::class, 'saveCodingDraft'])
            ->whereUlid('encounter')
            ->name('rm.rawat-inap.coding.draft');
        Route::post('/rm/rawat-inap/{encounter}/reviews', [InpatientRmController::class, 'saveReview'])
            ->whereUlid('encounter')
            ->name('rm.rawat-inap.reviews.store');
        Route::post('/rm/rawat-inap/{encounter}/signoff', [InpatientRmController::class, 'signoff'])
            ->whereUlid('encounter')
            ->name('rm.rawat-inap.signoff');
        Route::post('/rm/rawat-inap/summary-addenda/{correctionRequest}/reviews', [InpatientSummaryAddendumController::class, 'review'])
            ->whereUlid('correctionRequest')
            ->name('rm.rawat-inap.summary-addenda.reviews.store');
        Route::post('/rm/rawat-inap/summary-addenda/{correctionRequest}/signoff', [InpatientSummaryAddendumController::class, 'signoff'])
            ->whereUlid('correctionRequest')
            ->name('rm.rawat-inap.summary-addenda.signoff');

        Route::get('/modul/{category}', ModulePlaceholderController::class)
            ->whereIn('category', SimrsModuleCategories::slugs())
            ->name('modules.placeholder');
    });
});

require __DIR__.'/settings.php';
