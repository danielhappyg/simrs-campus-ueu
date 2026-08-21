<?php

use App\Http\Controllers\Rebuild\RebuildHomeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['simulation'])->group(function (): void {
    Route::get('/', RebuildHomeController::class)
        ->middleware(['auth', 'active.account', 'verified'])
        ->name('home');
});

require __DIR__.'/settings.php';
