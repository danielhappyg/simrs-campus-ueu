<?php

use App\Http\Controllers\ModulePlaceholderController;
use App\Http\Controllers\Rebuild\RebuildHomeController;
use App\Support\SimrsModuleCategories;
use Illuminate\Support\Facades\Route;

Route::middleware(['simulation'])->group(function (): void {
    Route::middleware(['auth', 'active.account', 'verified'])->group(function (): void {
        Route::get('/', RebuildHomeController::class)->name('home');

        Route::get('/modul/{category}', ModulePlaceholderController::class)
            ->whereIn('category', SimrsModuleCategories::slugs())
            ->name('modules.placeholder');
    });
});

require __DIR__.'/settings.php';
