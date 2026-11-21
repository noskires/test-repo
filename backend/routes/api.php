<?php

use App\Http\Controllers\Api\VendorController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::apiResource('vendors', VendorController::class)->except(['destroy']);
});
