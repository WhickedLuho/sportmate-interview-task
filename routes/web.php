<?php

use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\SyncTargetController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('targets', [SyncTargetController::class, 'index'])->name('targets.index');
    Route::post('targets', [SyncTargetController::class, 'store'])->name('targets.store');
    Route::post('targets/{target}/sync', [SyncTargetController::class, 'sync'])->name('targets.sync');
    Route::post('targets/{target}/cancel', [SyncTargetController::class, 'cancel'])->name('targets.cancel');

    Route::get('repositories', [RepositoryController::class, 'index'])->name('repositories.index');
});

require __DIR__.'/settings.php';
