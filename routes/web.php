<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\CompanyController;
use App\Http\Controllers\Web\InvoiceController;
use App\Http\Controllers\Web\PaymentController;
use App\Http\Controllers\Web\CompanyContactController;
use App\Http\Controllers\Web\ContractController;
use App\Http\Controllers\Web\OrderController;
use App\Http\Controllers\Web\SubscriptionController;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::resource('companies', CompanyController::class);
Route::resource('invoices', InvoiceController::class);
Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('payments.store');

// Контакты компании
Route::get('companies/{company}/contacts/create', [CompanyContactController::class, 'create'])
    ->name('companies.contacts.create');
Route::post('companies/{company}/contacts', [CompanyContactController::class, 'store'])
    ->name('companies.contacts.store');
Route::get('contacts/{contact}/edit', [CompanyContactController::class, 'edit'])
    ->name('contacts.edit');
Route::put('contacts/{contact}', [CompanyContactController::class, 'update'])
    ->name('contacts.update');
Route::delete('contacts/{contact}', [CompanyContactController::class, 'destroy'])
    ->name('contacts.destroy');

// Договоры компании
Route::get('companies/{company}/contracts/create', [ContractController::class, 'create'])
    ->name('companies.contracts.create');
Route::post('companies/{company}/contracts', [ContractController::class, 'store'])
    ->name('companies.contracts.store');
Route::get('contracts/{contract}/edit', [ContractController::class, 'edit'])
    ->name('contracts.edit');
Route::put('contracts/{contract}', [ContractController::class, 'update'])
    ->name('contracts.update');
Route::delete('contracts/{contract}', [ContractController::class, 'destroy'])
    ->name('contracts.destroy');
Route::get('contracts', [ContractController::class, 'index'])
    ->name('contracts.index');
    Route::get('contracts/{contract}', [ContractController::class, 'show'])
    ->name('contracts.show');

// Заказы
Route::get('contracts/{contract}/orders/create', [OrderController::class, 'create'])
    ->name('contracts.orders.create');
Route::post('contracts/{contract}/orders', [OrderController::class, 'store'])
    ->name('contracts.orders.store');
Route::get('orders/{order}/edit', [OrderController::class, 'edit'])
    ->name('orders.edit');
Route::put('orders/{order}', [OrderController::class, 'update'])
    ->name('orders.update');
Route::delete('orders/{order}', [OrderController::class, 'destroy'])
    ->name('orders.destroy');

// Подписки
Route::get('contracts/{contract}/subscriptions/create', [SubscriptionController::class, 'create'])
    ->name('contracts.subscriptions.create');
Route::post('contracts/{contract}/subscriptions', [SubscriptionController::class, 'store'])
    ->name('contracts.subscriptions.store');
Route::get('subscriptions/{subscription}/edit', [SubscriptionController::class, 'edit'])
    ->name('subscriptions.edit');
Route::put('subscriptions/{subscription}', [SubscriptionController::class, 'update'])
    ->name('subscriptions.update');
Route::delete('subscriptions/{subscription}', [SubscriptionController::class, 'destroy'])
    ->name('subscriptions.destroy');

// AJAX эндпоинты для формы инвойса
Route::get('ajax/companies/{company}/contracts', [InvoiceController::class, 'getContracts'])
    ->name('ajax.contracts');
Route::get('ajax/contracts/{contract}/items', [InvoiceController::class, 'getContractItems'])
    ->name('ajax.items');