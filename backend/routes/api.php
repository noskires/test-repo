<?php

use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\VendorController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::apiResource('vendors', VendorController::class)->except(['destroy']);

    Route::get('sites', [SiteController::class, 'index'])->name('sites.index');
    Route::get('sites/{site}/equipment', [SiteController::class, 'equipment'])->name('sites.equipment.index');
});
