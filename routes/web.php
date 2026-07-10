<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\CompanyController;
use App\Http\Controllers\Web\InvoiceController;
use App\Http\Controllers\Web\PaymentController;
use App\Http\Controllers\Web\CompanyContactController;
use App\Http\Controllers\Web\ContractController;

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