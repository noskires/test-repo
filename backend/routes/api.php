<?php

use App\Http\Controllers\Api\BillingThresholdController;
use App\Http\Controllers\Api\ChecklistTemplateController;
use App\Http\Controllers\Api\ECallController;
use App\Http\Controllers\Api\ECallProposalController;
use App\Http\Controllers\Api\ECallReviewController;
use App\Http\Controllers\Api\PmReportController;
use App\Http\Controllers\Api\PmTaskController;
use App\Http\Controllers\Api\PmTaskECallController;
use App\Http\Controllers\Api\PmTaskValidationController;
use App\Http\Controllers\Api\PriceListItemController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\SiteSettingsController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\WaitingListController;
use App\Http\Controllers\Api\WorkOrderCompletionController;
use App\Http\Controllers\Api\WorkOrderCompletionValidationController;
use App\Http\Controllers\Api\WorkOrderController;
use App\Http\Controllers\Api\WorkOrderEndorsementController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('me/waiting', WaitingListController::class)->name('me.waiting.index');

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
    Route::get('e-calls/{eCall}/proposals', [ECallProposalController::class, 'index'])->name('e-calls.proposals.index');
    Route::post('e-calls/{eCall}/proposal', [ECallProposalController::class, 'store'])->name('e-calls.proposal.store');
    Route::post('e-calls/{eCall}/approve', [ECallReviewController::class, 'approve'])->name('e-calls.approve');
    Route::post('e-calls/{eCall}/return', [ECallReviewController::class, 'returnToVendor'])->name('e-calls.return');
    Route::post('e-calls/{eCall}/reject', [ECallReviewController::class, 'reject'])->name('e-calls.reject');
    Route::get('e-calls/{eCall}/approvals', [ECallReviewController::class, 'approvals'])->name('e-calls.approvals.index');
    Route::post('e-calls/{eCall}/work-order', [WorkOrderController::class, 'store'])->name('e-calls.work-order.store');
    Route::get('work-orders/{workOrder}', [WorkOrderController::class, 'show'])->name('work-orders.show');
    Route::patch('work-orders/{workOrder}', [WorkOrderController::class, 'update'])->name('work-orders.update');
    Route::post('work-orders/{workOrder}/submit', [WorkOrderEndorsementController::class, 'submit'])->name('work-orders.submit');
    Route::post('work-orders/{workOrder}/approve', [WorkOrderEndorsementController::class, 'approve'])->name('work-orders.approve');
    Route::post('work-orders/{workOrder}/return', [WorkOrderEndorsementController::class, 'returnOneStep'])->name('work-orders.return');
    Route::post('work-orders/{workOrder}/reject', [WorkOrderEndorsementController::class, 'reject'])->name('work-orders.reject');
    Route::get('work-orders/{workOrder}/approvals', [WorkOrderEndorsementController::class, 'approvals'])->name('work-orders.approvals.index');
    Route::post('work-orders/{workOrder}/completion', [WorkOrderCompletionController::class, 'store'])->name('work-orders.completion.store');
    Route::get('work-orders/{workOrder}/completions', [WorkOrderCompletionController::class, 'index'])->name('work-orders.completions.index');
    Route::get('vendor/work-orders', [WorkOrderCompletionController::class, 'vendorIndex'])->name('vendor.work-orders.index');
    Route::post('work-orders/{workOrder}/validate-completion', [WorkOrderCompletionValidationController::class, 'validateCompletion'])->name('work-orders.validate-completion');
    Route::post('work-orders/{workOrder}/return-completion', [WorkOrderCompletionValidationController::class, 'returnCompletion'])->name('work-orders.return-completion');
    Route::post('work-orders/{workOrder}/reject-completion', [WorkOrderCompletionValidationController::class, 'rejectCompletion'])->name('work-orders.reject-completion');

    Route::get('vendor/pm-tasks', [PmTaskController::class, 'vendorIndex'])->name('vendor.pm-tasks.index');
    Route::get('pm-tasks/{pmTask}', [PmTaskController::class, 'show'])->name('pm-tasks.show');
    Route::post('pm-tasks/{pmTask}/e-calls', [PmTaskECallController::class, 'store'])->name('pm-tasks.e-calls.store');
    Route::post('pm-tasks/{pmTask}/start', [PmTaskController::class, 'start'])->name('pm-tasks.start');
    Route::get('pm-tasks/{pmTask}/reports', [PmReportController::class, 'index'])->name('pm-tasks.reports.index');
    Route::post('pm-tasks/{pmTask}/report', [PmReportController::class, 'store'])->name('pm-tasks.report.store');
    Route::post('pm-tasks/{pmTask}/approve', [PmTaskValidationController::class, 'approve'])->name('pm-tasks.approve');
    Route::post('pm-tasks/{pmTask}/return', [PmTaskValidationController::class, 'returnToVendor'])->name('pm-tasks.return');
    Route::get('pm-tasks/{pmTask}/approvals', [PmTaskValidationController::class, 'approvals'])->name('pm-tasks.approvals.index');

    Route::get('settings/billing-thresholds', [BillingThresholdController::class, 'show'])->name('settings.billing-thresholds.show');
    Route::put('settings/billing-thresholds', [BillingThresholdController::class, 'update'])->name('settings.billing-thresholds.update');
});
