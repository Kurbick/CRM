<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Web\CompanyContactController;
use App\Http\Controllers\Web\CompanyController;
use App\Http\Controllers\Web\ContractController;
use App\Http\Controllers\Web\ContractDocumentController;
use App\Http\Controllers\Web\ContractSubjectController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\InvoiceController;
use App\Http\Controllers\Web\OrderController;
use App\Http\Controllers\Web\PaymentController;
use App\Http\Controllers\Web\SubscriptionController;

/*
|--------------------------------------------------------------------------
| Главная страница
|--------------------------------------------------------------------------
*/

Route::get(
    '/',
    [DashboardController::class, 'index']
)->name('dashboard');

/*
|--------------------------------------------------------------------------
| Компании
|--------------------------------------------------------------------------
*/

Route::get(
    'companies/autocomplete',
    [CompanyController::class, 'autocomplete']
)->name('companies.autocomplete');

Route::resource(
    'companies',
    CompanyController::class
);

/*
|--------------------------------------------------------------------------
| Контакты компаний
|--------------------------------------------------------------------------
*/

Route::get(
    'companies/{company}/contacts/create',
    [CompanyContactController::class, 'create']
)->name('companies.contacts.create');

Route::post(
    'companies/{company}/contacts',
    [CompanyContactController::class, 'store']
)->name('companies.contacts.store');

Route::get(
    'contacts/{contact}/edit',
    [CompanyContactController::class, 'edit']
)->name('contacts.edit');

Route::put(
    'contacts/{contact}',
    [CompanyContactController::class, 'update']
)->name('contacts.update');

Route::delete(
    'contacts/{contact}',
    [CompanyContactController::class, 'destroy']
)->name('contacts.destroy');

/*
|--------------------------------------------------------------------------
| Договоры
|--------------------------------------------------------------------------
*/

Route::get(
    'companies/{company}/contracts/create',
    [ContractController::class, 'create']
)->name('companies.contracts.create');

Route::get(
    'contracts',
    [ContractController::class, 'index']
)->name('contracts.index');

Route::get(
    'contracts/create',
    [ContractController::class, 'create']
)->name('contracts.create');

Route::post(
    'contracts',
    [ContractController::class, 'store']
)->name('contracts.store');

Route::get(
    'contracts/{contract}',
    [ContractController::class, 'show']
)->name('contracts.show');

Route::get(
    'contracts/{contract}/edit',
    [ContractController::class, 'edit']
)->name('contracts.edit');

Route::put(
    'contracts/{contract}',
    [ContractController::class, 'update']
)->name('contracts.update');

Route::delete(
    'contracts/{contract}',
    [ContractController::class, 'destroy']
)->name('contracts.destroy');

/*
|--------------------------------------------------------------------------
| Предметы договоров
|--------------------------------------------------------------------------
*/

Route::get(
    'contracts/{contract}/subjects/create',
    [ContractSubjectController::class, 'create']
)->name('contracts.subjects.create');

Route::post(
    'contracts/{contract}/subjects',
    [ContractSubjectController::class, 'store']
)->name('contracts.subjects.store');

/*
|--------------------------------------------------------------------------
| Документы договоров
|--------------------------------------------------------------------------
*/

Route::post(
    'contracts/{contract}/documents',
    [ContractDocumentController::class, 'store']
)->name('contracts.documents.store');

Route::get(
    'contract-documents/{document}/download',
    [ContractDocumentController::class, 'download']
)->name('contract-documents.download');

Route::delete(
    'contract-documents/{document}',
    [ContractDocumentController::class, 'destroy']
)->name('contract-documents.destroy');

/*
|--------------------------------------------------------------------------
| Разовые услуги
|--------------------------------------------------------------------------
*/

Route::get(
    'contracts/{contract}/orders/create',
    [OrderController::class, 'create']
)->name('contracts.orders.create');

Route::post(
    'contracts/{contract}/orders',
    [OrderController::class, 'store']
)->name('contracts.orders.store');

Route::get(
    'orders/{order}/edit',
    [OrderController::class, 'edit']
)->name('orders.edit');

Route::put(
    'orders/{order}',
    [OrderController::class, 'update']
)->name('orders.update');

Route::delete(
    'orders/{order}',
    [OrderController::class, 'destroy']
)->name('orders.destroy');

/*
|--------------------------------------------------------------------------
| Подписки
|--------------------------------------------------------------------------
*/

Route::get(
    'contracts/{contract}/subscriptions/create',
    [SubscriptionController::class, 'create']
)->name('contracts.subscriptions.create');

Route::post(
    'contracts/{contract}/subscriptions',
    [SubscriptionController::class, 'store']
)->name('contracts.subscriptions.store');

Route::get(
    'subscriptions/{subscription}/edit',
    [SubscriptionController::class, 'edit']
)->name('subscriptions.edit');

Route::put(
    'subscriptions/{subscription}',
    [SubscriptionController::class, 'update']
)->name('subscriptions.update');

Route::delete(
    'subscriptions/{subscription}',
    [SubscriptionController::class, 'destroy']
)->name('subscriptions.destroy');

/*
|--------------------------------------------------------------------------
| Инвойсы
|--------------------------------------------------------------------------
*/

Route::post(
    'invoices/{invoice}/issue',
    [InvoiceController::class, 'issue']
)->name('invoices.issue');

Route::patch(
    'invoices/{invoice}/cancel',
    [InvoiceController::class, 'cancel']
)->name('invoices.cancel');

Route::resource(
    'invoices',
    InvoiceController::class
);

/*
|--------------------------------------------------------------------------
| Платежи
|--------------------------------------------------------------------------
*/

Route::post(
    'invoices/{invoice}/payments',
    [PaymentController::class, 'store']
)->name('payments.store');

Route::patch(
    'payments/{payment}/confirm',
    [PaymentController::class, 'confirm']
)->name('payments.confirm');

Route::patch(
    'payments/{payment}/cancel',
    [PaymentController::class, 'cancel']
)->name('payments.cancel');
/*
|--------------------------------------------------------------------------
| AJAX для формы инвойса
|--------------------------------------------------------------------------
*/

Route::get(
    'ajax/companies/{company}/contracts',
    [InvoiceController::class, 'getContracts']
)->name('ajax.contracts');

Route::get(
    'ajax/contracts/{contract}/items',
    [InvoiceController::class, 'getContractItems']
)->name('ajax.items');
