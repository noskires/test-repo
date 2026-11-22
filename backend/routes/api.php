<?php

use App\Http\Controllers\Api\BillingThresholdController;
use App\Http\Controllers\Api\ChecklistTemplateController;
use App\Http\Controllers\Api\ECallController;
use App\Http\Controllers\Api\PriceListItemController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\SiteSettingsController;
use App\Http\Controllers\Api\VendorController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::apiResource('vendors', VendorController::class)->except(['destroy']);
    Route::get('vendors/{vendor}/price-list-items', [PriceListItemController::class, 'index'])->name('vendors.price-list-items.index');
    Route::post('vendors/{vendor}/price-list-items', [PriceListItemController::class, 'store'])->name('vendors.price-list-items.store');
    Route::patch('price-list-items/{priceListItem}', [PriceListItemController::class, 'update'])->name('price-list-items.update');

    Route::get('sites', [SiteController::class, 'index'])->name('sites.index');
    Route::get('sites/{site}/equipment', [SiteController::class, 'equipment'])->name('sites.equipment.index');
    Route::get('sites/{site}/settings', [SiteSettingsController::class, 'show'])->name('sites.settings.show');
    Route::put('sites/{site}/settings/vendor', [SiteSettingsController::class, 'updateVendor'])->name('sites.settings.vendor.update');
    Route::put('sites/{site}/settings/eic', [SiteSettingsController::class, 'updateEic'])->name('sites.settings.eic.update');
    Route::put('sites/{site}/settings/pm-frequency', [SiteSettingsController::class, 'updatePmFrequency'])->name('sites.settings.pm-frequency.update');

    Route::get('checklist-templates', [ChecklistTemplateController::class, 'index'])->name('checklist-templates.index');
    Route::get('checklist-templates/{checklistTemplate}', [ChecklistTemplateController::class, 'show'])->name('checklist-templates.show');
    Route::get('checklist-templates/{checklistTemplate}/versions/{versionNumber}', [ChecklistTemplateController::class, 'version'])
        ->whereNumber('versionNumber')
        ->name('checklist-templates.versions.show');

    Route::get('e-calls', [ECallController::class, 'index'])->name('e-calls.index');
    Route::post('e-calls', [ECallController::class, 'store'])->name('e-calls.store');
    Route::get('e-calls/{eCall}', [ECallController::class, 'show'])->name('e-calls.show');

    Route::get('settings/billing-thresholds', [BillingThresholdController::class, 'show'])->name('settings.billing-thresholds.show');
    Route::put('settings/billing-thresholds', [BillingThresholdController::class, 'update'])->name('settings.billing-thresholds.update');
});
