<?php

use App\Http\Controllers\PortableSetupController;
use App\Http\Middleware\EnsurePortableSetup;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsurePortableSetup::class)->group(function () {
    Route::get('/setup', [PortableSetupController::class, 'create'])->name('portable.setup');
    Route::post('/setup', [PortableSetupController::class, 'store'])
        ->middleware('throttle:6,1')->name('portable.setup.store');
});
