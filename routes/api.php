<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\ServiceTypeController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\CompanyContactController;
use App\Http\Controllers\ServiceTypeItemController;
use App\Http\Controllers\DashboardController;

Route::middleware(['auth:sanctum', 'active', 'password.changed'])->name('api.')->group(function () {
    Route::apiResource('companies', CompanyController::class);

    Route::apiResource('companies.contracts', ContractController::class)
        ->shallow();

    Route::apiResource('service-types', ServiceTypeController::class);

    Route::apiResource('contracts.orders', OrderController::class)
        ->shallow();

    Route::apiResource('contracts.subscriptions', SubscriptionController::class)
        ->shallow();

    Route::apiResource('companies.invoices', InvoiceController::class)
        ->shallow();

    Route::apiResource('invoices.payments', PaymentController::class)
        ->shallow();

    Route::apiResource('companies.contacts', CompanyContactController::class)
        ->shallow();

    Route::apiResource('service-types.items', ServiceTypeItemController::class)
        ->shallow();

    Route::get('dashboard', [DashboardController::class, 'overview'])->name('dashboard');

    Route::get('dashboard/companies', [DashboardController::class, 'companies'])->name('dashboard.companies');

    Route::get('dashboard/companies/{company}', [DashboardController::class, 'company'])->name('dashboard.company');
});
