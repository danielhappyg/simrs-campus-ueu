<?php

use App\Http\Controllers\Work\WorkQueueController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/work')->name('home');

Route::middleware(['auth', 'active.account', 'verified', 'simulation'])->group(function () {
    Route::get('work', WorkQueueController::class)->name('work');
});

require __DIR__.'/settings.php';
