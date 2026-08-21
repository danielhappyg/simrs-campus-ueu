<?php

use App\Http\Controllers\ModulePlaceholderController;
use App\Http\Controllers\Outpatient\OutpatientExaminationController;
use App\Http\Controllers\Outpatient\OutpatientRegistrationController;
use App\Http\Controllers\Outpatient\OutpatientRmController;
use App\Http\Controllers\Rebuild\RebuildHomeController;
use App\Support\SimrsModuleCategories;
use Illuminate\Support\Facades\Route;

Route::middleware(['simulation'])->group(function (): void {
    Route::middleware(['auth', 'active.account', 'verified'])->group(function (): void {
        Route::get('/', RebuildHomeController::class)->name('home');

        Route::get('/pendaftaran/rawat-jalan', [OutpatientRegistrationController::class, 'index'])
            ->name('pendaftaran.rawat-jalan.index');
        Route::post('/pendaftaran/rawat-jalan', [OutpatientRegistrationController::class, 'store'])
            ->name('pendaftaran.rawat-jalan.store');

        Route::get('/pemeriksaan/rawat-jalan', [OutpatientExaminationController::class, 'index'])
            ->name('pemeriksaan.rawat-jalan.index');
        Route::get('/pemeriksaan/rawat-jalan/{encounter}', [OutpatientExaminationController::class, 'show'])
            ->name('pemeriksaan.rawat-jalan.show');
        Route::post('/pemeriksaan/rawat-jalan/{encounter}/entries', [OutpatientExaminationController::class, 'storeEntry'])
            ->name('pemeriksaan.rawat-jalan.entries.store');

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
