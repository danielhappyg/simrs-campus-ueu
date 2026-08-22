<?php

use App\Http\Controllers\Emergency\EmergencyExaminationController;
use App\Http\Controllers\Emergency\EmergencyRegistrationController;
use App\Http\Controllers\Emergency\EmergencyTriageController;
use App\Http\Controllers\Inpatient\InpatientExaminationController;
use App\Http\Controllers\Inpatient\InpatientRegistrationController;
use App\Http\Controllers\ModulePlaceholderController;
use App\Http\Controllers\Outpatient\OutpatientExaminationController;
use App\Http\Controllers\Outpatient\OutpatientPrintController;
use App\Http\Controllers\Outpatient\OutpatientRecapController;
use App\Http\Controllers\Outpatient\OutpatientRegistrationController;
use App\Http\Controllers\Outpatient\OutpatientRmController;
use App\Http\Controllers\Rebuild\RebuildHomeController;
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

        Route::get('/pendaftaran/rekap', [OutpatientRecapController::class, 'index'])
            ->name('pendaftaran.rekap');
        Route::get('/pendaftaran/kunjungan/{encounter}/cetak', [OutpatientPrintController::class, 'show'])
            ->name('pendaftaran.kunjungan.cetak');

        Route::get('/pemeriksaan/rawat-jalan', [OutpatientExaminationController::class, 'index'])
            ->name('pemeriksaan.rawat-jalan.index');
        Route::get('/pemeriksaan/rawat-jalan/{encounter}', [OutpatientExaminationController::class, 'show'])
            ->name('pemeriksaan.rawat-jalan.show');
        Route::post('/pemeriksaan/rawat-jalan/{encounter}/entries', [OutpatientExaminationController::class, 'storeEntry'])
            ->name('pemeriksaan.rawat-jalan.entries.store');

        Route::get('/pemeriksaan/igd', [EmergencyExaminationController::class, 'index'])
            ->name('pemeriksaan.igd.index');
        Route::get('/pemeriksaan/igd/{encounter}', [EmergencyExaminationController::class, 'show'])
            ->name('pemeriksaan.igd.show');
        Route::post('/pemeriksaan/igd/{encounter}/entries', [EmergencyExaminationController::class, 'storeEntry'])
            ->name('pemeriksaan.igd.entries.store');

        Route::get('/pemeriksaan/rawat-inap', [InpatientExaminationController::class, 'index'])
            ->name('pemeriksaan.rawat-inap.index');
        Route::get('/pemeriksaan/rawat-inap/{encounter}', [InpatientExaminationController::class, 'show'])
            ->name('pemeriksaan.rawat-inap.show');
        Route::post('/pemeriksaan/rawat-inap/{encounter}/entries', [InpatientExaminationController::class, 'storeEntry'])
            ->name('pemeriksaan.rawat-inap.entries.store');

        Route::get('/pemeriksaan/triage', [EmergencyTriageController::class, 'index'])
            ->name('pemeriksaan.triage.index');

        Route::get('/rm/rawat-jalan', [OutpatientRmController::class, 'index'])
            ->name('rm.rawat-jalan.index');
        Route::post('/rm/rawat-jalan/{encounter}/complete', [OutpatientRmController::class, 'complete'])
            ->name('rm.rawat-jalan.complete');

        Route::get('/modul/{category}', ModulePlaceholderController::class)
            ->whereIn('category', SimrsModuleCategories::slugs())
            ->name('modules.placeholder');
    });
});

require __DIR__.'/settings.php';
