@extends('layouts.app')

@section('title', __('invoices.show.document') . ' ' . $invoice->invoice_number)

@section('content')
    @php
        $displayDateTime = app(\App\Support\DisplayDateTime::class);
        $formatMoney = static function ($amount): string {
            $value = round((float) $amount, 2);

            if ($value == 0.0) {
                $value = 0.0;
            }

            return number_format($value, 2, ',', ' ') . ' ₼';
        };

        $remainingColor = match (true) {
            (float) $invoice->remaining_amount === 0.0 || $invoice->status === 'paid' => 'text-green-600',
            in_array($invoice->status, ['issued', 'partially_paid'], true) && (bool) $invoice->is_overdue => 'text-red-600',
            $invoice->status === 'partially_paid' => 'text-orange-600',
            default => 'text-gray-900',
        };
        $invoiceVatRateLabel = filled($invoice->vat_rate)
            ? rtrim(rtrim((string) $invoice->vat_rate, '0'), '.')
            : '—';

        $hasPaymentRegistration = in_array($invoice->status, ['issued', 'partially_paid'], true)
            && $invoice->remaining_amount > 0
            && \Illuminate\Support\Facades\Gate::allows('create', [\App\Models\Payment::class, $invoice]);

    @endphp

    {{-- Компактный заголовок инвойса --}}
    <div data-testid="invoice-entity-header" class="invoice-page-header crm-print-hide mb-5 print:hidden">
        @php
            $canReturnToCompany = $companyContext['active'] && auth()->user()->can('view', $invoice->company);
        @endphp
        <a href="{{ $canReturnToCompany ? $companyContext['company_url'] : route('invoices.index') }}"
            class="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 transition hover:text-slate-900">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                {{ $canReturnToCompany ? $companyContext['label'] : __('invoices.actions.back_to_list') }}
        </a>

        <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="truncate font-mono text-xl font-semibold leading-tight text-slate-900">
                        {{ $invoice->invoice_number }}
                    </h1>
                    @include('partials.badge', ['status' => $invoice->status, 'label' => __('invoices.statuses.'.$invoice->status)])
                </div>

                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                    @if ($invoice->company)
                        @can('view', $invoice->company)
                            <a href="{{ $companyContext['company_url'] }}"
                                class="font-medium text-blue-600 transition hover:text-blue-800 hover:underline">
                                {{ $invoice->company->name }}
                            </a>
                        @else
                            <span>{{ $invoice->company->name }}</span>
                        @endcan
                    @else
                        <span>{{ $invoice->payer_name ?: __('invoices.show.payer_not_specified') }}</span>
                    @endif

                    @if ($invoice->contract)
                        <span class="text-slate-300">·</span>
                        @can('view', $invoice->contract)
                            <a href="{{ route('contracts.show', $invoice->contract) }}"
                                class="font-mono font-medium text-blue-600 transition hover:text-blue-800 hover:underline">
                                {{ $invoice->contract->contract_number }}
                            </a>
                        @else
                            <span class="font-mono">{{ $invoice->contract->contract_number }}</span>
                        @endcan
                    @elseif ($invoice->contract_reference)
                        <span class="text-slate-300">·</span>
                        <span class="font-mono">{{ $invoice->contract_reference }}</span>
                    @endif
                </div>
                @if ($invoice->issuerOrganization)
                    <p class="mt-2 text-xs text-slate-500"><span class="font-medium text-slate-600">{{ __('organizations.form.issuer') }}:</span> {{ $invoice->issuerOrganization->name }}</p>
                @endif
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-1">
                @can('print', $invoice)
                    <button type="button" onclick="window.print()" class="crm-light-action">
                        {{ __('invoices.actions.print') }}
                    </button>
                @endcan

                @can('update', $invoice)
                    @if ($editability['editable'])
                        <a href="{{ route('invoices.edit', $invoice) }}{{ $companyContext['active'] ? '?'.http_build_query($companyContext['query']) : '' }}"
                            class="crm-light-action">
                            {{ __('invoices.actions.edit') }}
                        </a>
                    @endif
                @endcan

                @can('delete', $invoice)
                    @if ($invoice->status === 'draft')
                        <form action="{{ route('invoices.destroy', ['invoice' => $invoice, ...$companyContext['query']]) }}" method="POST"
                            onsubmit="return confirm(@js(__('invoices.actions.delete_confirm')))" >
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                class="inline-flex items-center rounded px-1.5 py-1 text-xs font-semibold text-red-600 transition hover:bg-red-50 hover:text-red-700">
                                {{ __('invoices.actions.delete') }}
                            </button>
                        </form>
                    @endif
                @endcan
            </div>
        </div>
    </div>

    @error('issue')
        <div class="crm-print-hide mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 print:hidden">
            <p class="text-sm text-red-700">{{ $message }}</p>
        </div>
    @enderror

    @error('cancel')
        <div class="crm-print-hide mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 print:hidden">
            <p class="text-sm text-red-700">{{ $message }}</p>
        </div>
    @enderror

    @error('payment_confirm')
        <div class="crm-print-hide mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 print:hidden">
            <p class="text-sm text-red-700">{{ $message }}</p>
        </div>
    @enderror

    <div data-testid="invoice-workspace" @class([
        'invoice-screen-grid grid grid-cols-1 items-start gap-5',
        'lg:grid-cols-[minmax(0,2fr)_minmax(280px,0.85fr)]' => $hasPaymentRegistration,
        'lg:grid-cols-1' => ! $hasPaymentRegistration,
    ])>

        {{-- Основной документ инвойса (2/3 ширины) --}}
        <div class="invoice-document-column min-w-0 print:w-full print:col-span-3">

            {{-- Печатный бланк счета --}}
            <div
                class="invoice-document relative overflow-hidden border-y border-slate-200 bg-white p-4 sm:p-5 md:p-6 print:border-none print:shadow-none print:p-0">

                {{-- Верхняя декоративная полоса (скрывается при печати) --}}
                <div class="crm-print-hide absolute top-0 left-0 right-0 h-1.5 bg-blue-600 print:hidden"></div>

                {{-- Шапка бланка --}}
                <div class="flex flex-col md:flex-row justify-between gap-5 pb-6 border-b border-gray-100 mb-6">

                    {{-- Данные продавца (Мы) --}}
                    <div class="space-y-1">
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider">{{ __('invoices.show.supplier') }}</div>
                        <h2 class="text-lg font-bold text-gray-900">{{ $invoice->seller_name ?? $sellerFallback['seller_name'] }}</h2>
                        <div class="text-sm text-gray-600 font-mono">VÖEN: {{ $invoice->seller_voen ?? $sellerFallback['seller_voen'] }}
                        </div>
                        @if ($invoice->seller_bank_name ?? $sellerFallback['seller_bank_name'])
                            <div class="text-sm text-gray-600 mt-1.5">
                                <span class="font-medium text-gray-800">{{ __('invoices.show.bank') }}</span>
                                {{ $invoice->seller_bank_name ?? $sellerFallback['seller_bank_name'] }}
                            </div>
                        @endif
                        @if ($invoice->seller_iban ?? $sellerFallback['seller_iban'])
                            <div class="text-sm text-gray-600 break-words [overflow-wrap:anywhere]">
                                <span class="font-medium text-gray-800">IBAN:</span>
                                <span class="font-mono">{{ $invoice->seller_iban ?? $sellerFallback['seller_iban'] }}</span>
                            </div>
                        @endif
                        @if (($invoice->seller_swift ?? $sellerFallback['seller_swift']) || ($invoice->seller_bank_code ?? $sellerFallback['seller_bank_code']))
                            <div class="grid grid-cols-2 gap-2 text-sm text-gray-600">
                            @if ($invoice->seller_swift ?? $sellerFallback['seller_swift'])
                                <div><span class="font-medium text-gray-800">SWIFT:</span> <span
                                        class="font-mono">{{ $invoice->seller_swift ?? $sellerFallback['seller_swift'] }}</span></div>
                            @endif
                            @if ($invoice->seller_bank_code ?? $sellerFallback['seller_bank_code'])
                                <div><span class="font-medium text-gray-800">{{ __('invoices.show.bank_code') }}</span> <span
                                        class="font-mono">{{ $invoice->seller_bank_code ?? $sellerFallback['seller_bank_code'] }}</span></div>
                            @endif
                            </div>
                        @endif
                        @if ($invoice->seller_bank_voen ?? $sellerFallback['seller_bank_voen'])
                            <div class="text-sm text-gray-600 font-mono">
                                <span class="font-medium text-gray-800">{{ __('invoices.show.bank_voen') }}</span> {{ $invoice->seller_bank_voen ?? $sellerFallback['seller_bank_voen'] }}
                            </div>
                        @endif
                    </div>

                    {{-- Метаданные инвойса --}}
                    <div class="space-y-2 md:text-right md:self-start">
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider">{{ __('invoices.show.document') }}</div>
                        <h2 class="text-xl font-bold text-gray-900 font-mono">{{ $invoice->invoice_number }}</h2>

                        <div class="text-sm text-gray-600">
                            <span class="font-medium text-gray-800">{{ __('invoices.show.issue_date') }}</span> {{ $invoice->issue_date ? \Illuminate\Support\Carbon::parse($invoice->issue_date)->format('d/m/Y') : '—' }}
                        </div>
                        <div class="text-sm text-gray-600">
                            <span class="font-medium text-gray-800">{{ __('invoices.show.due_date') }}</span> {{ $invoice->due_date ? \Illuminate\Support\Carbon::parse($invoice->due_date)->format('d/m/Y') : '—' }}
                        </div>
                        @if ($invoiceBillingPeriod['kind'] !== 'none')
                            <div class="text-sm text-gray-600">
                                <span class="font-medium text-gray-800">{{ __('invoices.show.billing_period') }}</span>
                                <div class="text-xs text-gray-500 font-mono mt-0.5">{{ $invoiceBillingPeriod['kind'] === 'disjoint' ? __('invoices.form.multiple_periods') : $invoiceBillingPeriod['label'] }}</div>
                                @if ($invoiceBillingPeriod['kind'] === 'continuous' && $invoiceBillingPeriod['period_count'] > 1)
                                    <div class="text-xs text-gray-500 mt-0.5">
                                        {{ $invoiceBillingPeriod['period_count'] }} {{ $invoiceBillingPeriod['period_count'] === 1 ? __('invoices.form.period_one') : ($invoiceBillingPeriod['period_count'] <= 4 ? __('invoices.form.period_few') : __('invoices.form.period_many')) }}
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Получатель счета (Плательщик) --}}
                <div
                    class="invoice-payer bg-gray-50 rounded-lg px-4 py-3.5 mb-6 grid grid-cols-1 md:grid-cols-2 gap-3 print:bg-gray-100 print:rounded-none">
                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">{{ __('invoices.show.payer') }}</div>
                        <h3 class="font-bold text-gray-900">{{ $invoice->payer_name ?: __('invoices.show.no_payer') }}</h3>
                        @if (trim((string) $invoice->payer_voen) !== '')
                            <div class="text-sm text-gray-600 font-mono mt-0.5">VÖEN: {{ $invoice->payer_voen }}</div>
                        @endif
                    </div>
                    <div class="md:text-right md:self-center">
                        @if ($invoice->contract_reference)
                            <div class="text-sm text-gray-600">
                                <span class="font-medium text-gray-800">{{ __('invoices.show.contract') }}</span>
                                <span class="font-mono text-gray-900 font-semibold">{{ $invoice->contract_reference }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Позиции счета --}}
                <section data-testid="invoice-line-items" class="mb-6">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-slate-900">{{ __('invoices.show.lines') }}</h2>
                    </div>
                    <div class="overflow-x-auto print:overflow-visible">
                    <table class="crm-table w-full table-auto text-left text-sm">
                        <thead>
                            <tr
                                class="border-b border-gray-200 text-gray-400 font-semibold uppercase tracking-wider text-xs pb-3">
                                <th class="w-8 pb-3 pr-2">№</th>
                                <th class="pb-3 pr-4">{{ __('invoices.show.line') }}</th>
                                <th class="invoice-print-only hidden pb-3 pr-4">{{ __('invoices.show.description_type') }}</th>
                                <th class="invoice-print-only hidden pb-3 pr-4">{{ __('invoices.show.billing_period') }}</th>
                                <th class="pb-3 text-left pr-4">{{ __('invoices.show.amount') }}</th>
                                <th class="crm-print-hide pb-3 text-left pr-4 print:hidden">{{ __('invoices.show.paid') }}</th>
                                <th class="crm-print-hide pb-3 text-left pr-4 print:hidden">{{ __('invoices.show.remaining') }}</th>
                                <th class="crm-print-hide pb-3 print:hidden">{{ __('invoices.index.status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700">
                            @foreach ($paymentBreakdown['lineRows'] as $index => $line)
                                <tr class="invoice-line-row">
                                    <td class="w-8 py-4 pr-2 font-medium text-gray-400">{{ $index + 1 }}</td>
                                    <td class="py-4 pr-4">
                                        <div class="font-semibold text-gray-900 break-words">{{ $line['description'] }}</div>
                                        @if ($line['type'] === 'subscription')
                                            @if ($line['period_label'])
                                                <div class="crm-print-hide mt-0.5 text-xs text-gray-500 break-words">{{ $line['period_label'] }}</div>
                                            @endif
                                        @else
                                        <div class="crm-print-hide mt-0.5 text-xs text-gray-500 break-words">{{ match ($line['type']) {
                                            'subscription' => __('invoices.form.subscription'),
                                            'order' => __('invoices.form.one_time'),
                                            default => __('invoices.form.manual_line'),
                                        } }}</div>
                                        @endif
                                    </td>
                                    <td class="invoice-print-only hidden py-4 pr-4 text-xs text-gray-600">
                                        {{ $line['type'] === 'subscription' ? '' : match ($line['type']) {
                                            'order' => __('invoices.form.one_time'),
                                            default => __('invoices.form.manual_line'),
                                        } }}
                                    </td>
                                    <td class="invoice-print-only hidden py-4 pr-4 text-xs text-gray-600">
                                        {{ $line['period_label'] ?: '—' }}
                                    </td>
                                    <td class="py-4 text-left font-semibold text-gray-900 font-mono pr-4">
                                        <span class="whitespace-nowrap tabular-nums">{{ $formatMoney($line['amount']) }}</span>
                                    </td>
                                    <td class="crm-print-hide py-4 text-left font-semibold text-green-600 font-mono pr-4 print:hidden">
                                        <span class="whitespace-nowrap tabular-nums">{{ $formatMoney($line['paid_amount']) }}</span>
                                    </td>
                                    <td class="crm-print-hide py-4 text-left font-semibold text-gray-900 font-mono pr-4 print:hidden">
                                        <span class="whitespace-nowrap tabular-nums">{{ $formatMoney($line['remaining_amount']) }}</span>
                                    </td>
                                    <td class="crm-print-hide py-4 print:hidden">
                                        <span @class([
                                            'inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium',
                                            'bg-green-50 text-green-700' => $line['payment_state'] === 'paid',
                                            'bg-amber-50 text-amber-700' => $line['payment_state'] === 'partially_paid',
                                            'bg-gray-100 text-gray-600' => $line['payment_state'] === 'unpaid',
                                        ])>
                                            {{ __('invoices.show.payment_states.'.$line['payment_state']) }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                </section>

                @can('viewAny', \App\Models\Payment::class)
                    {{-- Платежи --}}
                    <section data-testid="invoice-payments" class="crm-print-hide mb-6 overflow-hidden border-y border-slate-200 print:hidden">
                        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 px-3 py-3">
                            <div class="flex items-baseline gap-3">
                                <h2 class="text-sm font-semibold text-slate-900">{{ __('payments.labels.title') }}</h2>
                                <span class="text-xs tabular-nums text-slate-500">{{ $paymentBreakdown['payments_count'] }}</span>
                            </div>

                            @if ($paymentBreakdown['payments_count'] > 0)
                                <button type="button" x-ref="paymentHistoryTrigger" @click="$dispatch('open-payment-history')"
                                    class="ml-auto crm-table-action-link">
                                    {{ __('payments.labels.history') }}
                                </button>
                            @endif
                        </div>

                        @if ($paymentBreakdown['payments_count'] > 0)
                            <div class="crm-table-scroll">
                                <table class="crm-table min-w-[620px] table-fixed">
                                    <colgroup>
                                        <col class="w-[13%]">
                                        <col class="w-[15%]">
                                        <col class="w-[22%]">
                                        <col class="w-[21%]">
                                        <col class="w-[29%]">
                                    </colgroup>
                                    <thead>
                                        <tr>
                                            <th>{{ __('payments.labels.date') }}</th>
                                            <th>{{ __('payments.labels.amount') }}</th>
                                            <th>{{ __('payments.labels.method') }}</th>
                                            <th>{{ __('invoices.index.status') }}</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                        @foreach ($paymentBreakdown['paymentRows'] as $paymentRow)
                                            @php
                                                $payment = $paymentsById->get($paymentRow['id']);
                                                $isCreditBalancePayment = in_array(
                                                    $paymentRow['id'],
                                                    $paymentSource['credit_balance_payment_ids'],
                                                    true
                                                );
                                                $shouldOpenTableCancellation =
                                                    $errors->has('cancel_reason') &&
                                                    (string) old('cancel_payment_id') === (string) $payment->id;
                                            @endphp
                                            <tbody x-data="{ cancelOpen: @js($shouldOpenTableCancellation), cancelSubmitting: false }"
                                                x-on:keydown.escape="cancelOpen = false">
                                            <tr>
                                                <td class="crm-table-date">
                                                    {{ $paymentRow['payment_date'] ? \Illuminate\Support\Carbon::parse($paymentRow['payment_date'])->format('d/m/Y') : '—' }}
                                                </td>
                                                <td class="crm-table-numeric font-semibold text-slate-900">{{ $formatMoney($paymentRow['amount']) }}</td>
                                                <td>
                                                    <span>{{ __('payments.methods.'.$paymentRow['payment_method']) }}</span>
                                                    @if (in_array($paymentRow['id'], $paymentSource['credit_balance_payment_ids'], true))
                                                        <span data-testid="invoice-payment-source-balance"
                                                            class="mt-1 inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700">
                                                            {{ __('invoices.credit.from_balance') }}
                                                        </span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @include('partials.badge', ['status' => $paymentRow['status'], 'label' => __('payments.statuses.'.$paymentRow['status'])])
                                                </td>
                                                <td data-testid="invoice-payment-actions-{{ $payment->id }}" class="px-1 text-right">
                                                    @if ($payment->status === 'pending')
                                                    <div class="flex flex-wrap justify-end gap-1.5 text-xs font-medium">
                                                            @can('confirm', $payment)
                                                                <form action="{{ route('payments.confirm', $payment) }}" method="POST"
                                                                    onsubmit="return confirm(@js(__('payments.confirm_message')))" >
                                                                    @csrf
                                                                    @method('PATCH')
                                                                    <button type="submit" data-testid="invoice-payment-confirm-action"
                                                                        class="inline-flex !h-9 !min-h-0 !w-24 shrink-0 items-center justify-center whitespace-nowrap !rounded-md border !px-0 !py-0 !text-sm !font-medium transition focus:outline-none focus:ring-2 focus:ring-offset-1 border-green-200 bg-green-50 text-green-700 hover:bg-green-100 focus:ring-green-500">
                                                                        {{ __('payments.actions.confirm_short') }}
                                                                    </button>
                                                                </form>
                                                            @endcan

                                                            @can('cancel', $payment)
                                                                @if (!$isCreditBalancePayment)
                                                                        <button type="button" data-testid="invoice-payment-cancel-action" x-show="!cancelOpen"
                                                                            class="inline-flex !h-9 !min-h-0 !w-24 shrink-0 items-center justify-center whitespace-nowrap !rounded-md border !px-0 !py-0 !text-sm !font-medium transition focus:outline-none focus:ring-2 focus:ring-offset-1 border-red-200 bg-red-50 text-red-700 hover:bg-red-100 focus:ring-red-500"
                                                                            @click="cancelOpen = true; $nextTick(() => $refs.tableCancelReason.focus())">
                                                                        {{ __('payments.actions.cancel_short') }}
                                                                    </button>
                                                                @endif
                                                            @endcan
                                                        </div>

                                                    @endif
                                                </td>
                                            </tr>
                                            @if ($payment->status === 'pending')
                                                @can('cancel', $payment)
                                                    @if (!$isCreditBalancePayment)
                                                        <tr x-show="cancelOpen" x-cloak
                                                            data-testid="invoice-payment-cancel-row-{{ $payment->id }}">
                                                        <td colspan="5" class="bg-slate-50/70 px-3 py-3">
                                                            <form action="{{ route('payments.cancel', $payment) }}" method="POST"
                                                                class="space-y-2 text-left"
                                                                x-on:submit="
                                                                    const reason = $event.currentTarget.elements.cancel_reason.value.trim();
                                                                    if (cancelSubmitting || reason === '' || !$event.currentTarget.checkValidity()) {
                                                                        $event.preventDefault();
                                                                        $event.currentTarget.reportValidity();
                                                                        return;
                                                                    }
                                                                    if (!confirm(@js(__('payments.cancel_message')))) {
                                                                        $event.preventDefault();
                                                                        return;
                                                                    }
                                                                    cancelSubmitting = true;
                                                                ">
                                                                @csrf
                                                                @method('PATCH')
                                                                <input type="hidden" name="cancel_payment_id" value="{{ $payment->id }}">
                                                                <label class="block text-xs font-semibold text-red-700" for="table_cancel_reason_{{ $payment->id }}">
                                                                    {{ __('payments.labels.cancel_reason') }}
                                                                </label>
                                                                <input id="table_cancel_reason_{{ $payment->id }}" name="cancel_reason"
                                                                    x-ref="tableCancelReason" required minlength="3" maxlength="1000"
                                                                    value="{{ $shouldOpenTableCancellation ? old('cancel_reason') : '' }}"
                                                                    placeholder="{{ __('payments.labels.cancel_reason_placeholder') }}"
                                                                    class="w-full rounded-md border border-red-200 bg-white px-2 py-1.5 text-xs text-gray-700 outline-none focus:border-red-400 focus:ring-1 focus:ring-red-300">
                                                                @if ($shouldOpenTableCancellation)
                                                                    @error('cancel_reason')
                                                                        <p class="text-left text-xs text-red-600">{{ $message }}</p>
                                                                    @enderror
                                                                @endif
                                                                <div class="flex flex-wrap justify-end gap-2">
                                                                    <button type="button" @click="cancelOpen = false"
                                                                        class="inline-flex h-7 items-center rounded-md border border-gray-200 bg-white px-2 py-1 text-[11px] font-medium text-gray-600 transition hover:bg-gray-50 hover:text-gray-900">
                                                                        {{ __('payments.labels.do_not_cancel') }}
                                                                    </button>
                                                                    <button type="submit" :disabled="cancelSubmitting"
                                                                        class="inline-flex h-7 items-center rounded-md border border-red-200 bg-red-50 px-1.5 py-1 text-[11px] font-medium text-red-700 transition hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-1">
                                                                        {{ __('payments.labels.confirm_cancellation') }}
                                                                    </button>
                                                                </div>
                                                            </form>
                                                        </td>
                                                        </tr>
                                                    @endif
                                                @endcan
                                            @endif
                                            </tbody>
                                        @endforeach
                                </table>
                            </div>
                        @else
                            <p class="px-3 py-4 text-sm text-slate-500">{{ __('payments.labels.no_payments') }}</p>
                        @endif
                    </section>
                @endcan

                {{-- Расчет итога --}}
                <div class="invoice-totals border-t border-gray-100 pt-6 flex flex-col items-end gap-2 text-sm text-gray-600">
                    @if ($invoice->vat_enabled)
                        <div class="flex justify-between w-64">
                            <span>{{ __('invoices.show.subtotal') }}</span>
                            <span class="font-semibold text-gray-900 font-mono">{{ $formatMoney($invoice->subtotal_amount) }}</span>
                        </div>
                        <div class="flex justify-between w-64">
                            <span>{{ __('invoices.show.vat', ['rate' => $invoiceVatRateLabel]) }}</span>
                            <span class="font-semibold text-gray-900 font-mono">{{ $formatMoney($invoice->vat_amount) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between w-64">
                        <span>{{ __('invoices.show.total_invoice') }}</span>

                        <span class="font-bold text-gray-900 font-mono">
                            {{ $formatMoney($invoice->total_amount) }}
                        </span>
                    </div>

                    <div class="flex justify-between w-64 text-green-600">
                        <span>{{ __('invoices.show.paid') }}:</span>

                        <span class="font-bold font-mono">
                            {{ $formatMoney($invoice->applied_amount) }}
                        </span>
                    </div>

                    @if ($paymentAvailability['pending_minor'] > 0)
                        <div class="flex justify-between w-64 text-amber-600">
                            <span>{{ __('invoices.show.pending_payment') }}</span>

                            <span class="font-bold font-mono">
                                {{ $formatMoney($paymentAvailability['pending_minor'] / 100) }}
                            </span>
                        </div>
                    @endif

                    @can('viewAny', \App\Models\Payment::class)
                        @if ($paymentSource['credit_balance_applied_minor'] > 0)
                            <div class="w-64 text-right text-xs font-medium text-blue-700">
                                {{ __('invoices.show.from_balance') }} {{ $formatMoney($paymentSource['credit_balance_applied_amount']) }}
                            </div>
                        @endif
                    @endcan

                    @if ($invoice->overpayment_amount > 0)
                        <div class="flex justify-between w-64 text-blue-600">
                            <span>{{ __('invoices.show.overpayment') }}</span>

                            <span class="font-bold font-mono">
                                {{ $formatMoney($invoice->overpayment_amount) }}
                            </span>
                        </div>
                    @endif

                    <div
                        class="flex justify-between w-64 border-t border-gray-100 pt-2 text-base
                        {{ $remainingColor }}">

                        <span class="font-semibold">
                            {{ __('invoices.show.remaining_to_pay') }}
                        </span>

                        <span class="font-bold font-mono">
                            {{ $formatMoney($invoice->remaining_amount) }}
                        </span>
                    </div>
                </div>

                @can('issue', $invoice)
                    @if ($invoice->status === 'draft')
                        <div data-testid="invoice-issue-action-area"
                            class="crm-print-hide mt-5 flex justify-end border-t border-slate-200 pt-4 print:hidden">
                            <form action="{{ route('invoices.issue', $invoice) }}" method="POST" class="w-full sm:w-auto">
                                @csrf
                                <button type="submit"
                                    class="inline-flex w-full items-center justify-center rounded bg-blue-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-blue-700 sm:w-auto">
                                    {{ __('invoices.actions.issue') }}
                                </button>
                            </form>
                        </div>
                    @endif
                @endcan

                @can('cancel', $invoice)
                    @if ($invoice->status === 'issued' && ! $hasPayments)
                        <div class="crm-print-hide mt-4 border-t border-slate-200 pt-3 print:hidden">
                            <form action="{{ route('invoices.cancel', $invoice) }}" method="POST"
                                onsubmit="return confirm(@js(__('invoices.actions.cancel_confirm')))" >
                                @csrf
                                @method('PATCH')
                                <button type="submit"
                                    class="text-xs font-semibold text-red-600 transition hover:text-red-700">
                                    {{ __('invoices.actions.cancel_invoice') }}
                                </button>
                            </form>
                        </div>
                    @endif
                @endcan

                {{-- Примечание продавца --}}
                @if (filled($invoice->comment))
                    <div class="invoice-comment mt-6 pt-4 border-t border-gray-100 text-sm break-words">
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">{{ __('invoices.show.comment') }}</div>
                        <p class="text-gray-600 whitespace-pre-line">{{ $invoice->comment }}</p>
                    </div>
                @endif

            </div>

        </div>

        {{-- Правая боковая колонка: Регистрация оплат и дополнительные действия --}}
        <div class="invoice-sidebar crm-print-hide space-y-6 print:hidden">

            {{-- Ручное применение Credit Balance --}}
            @if ($canApplyCredit && $creditBalanceMinor > 0)
                <div x-data="{
                        creditOpen: @js((bool) session('credit_dialog_open')),
                        creditSubmitting: false,
                        openCredit() {
                            this.creditOpen = true;
                            document.body.style.overflow = 'hidden';
                            this.$nextTick(() => this.$refs.creditAmount?.focus());
                        },
                        closeCredit() {
                            this.creditOpen = false;
                            document.body.style.overflow = '';
                            this.$nextTick(() => this.$refs.creditTrigger?.focus());
                        }
                    }"
                    x-init="if (creditOpen) { document.body.style.overflow = 'hidden'; $nextTick(() => $refs.creditAmount?.focus()); }"
                    x-on:keydown.escape.window="if (creditOpen) closeCredit()">
                    <div class="rounded-xl border border-blue-200 bg-blue-50 p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold text-blue-900">{{ __('invoices.credit.title') }}</h3>
                                <p class="mt-1 font-mono text-lg font-bold text-blue-800">
                                    {{ $formatMoney($creditBalanceMinor / 100) }}
                                </p>
                            </div>
                        </div>

                        @if ($creditMaximumMinor > 0)
                            <button type="button" x-ref="creditTrigger" @click="openCredit()"
                                class="mt-4 w-full rounded-lg bg-blue-600 px-3 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-400 focus:ring-offset-1">
                                {{ __('invoices.credit.pay_from_balance') }}
                            </button>
                        @else
                            <button type="button" disabled
                                class="mt-4 w-full cursor-not-allowed rounded-lg bg-blue-300 px-3 py-2.5 text-sm font-medium text-white opacity-80">
                                {{ __('invoices.credit.pay_from_balance') }}
                            </button>
                            @if ($paymentAvailability['pending_minor'] > 0)
                                <p class="mt-2 text-xs leading-5 text-blue-800">
                                    {{ __('invoices.credit.reserved') }}
                                </p>
                            @endif
                        @endif
                    </div>

                    @if ($creditMaximumMinor > 0 || session('credit_dialog_open'))
                        <div x-show="creditOpen" x-cloak x-transition.opacity
                            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4 print:hidden"
                            role="dialog" aria-modal="true" aria-labelledby="credit-dialog-title">
                            <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-2xl" @click.stop>
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <h3 id="credit-dialog-title" class="text-base font-semibold text-slate-900">
                                            {{ __('invoices.credit.payment_from_balance') }}
                                        </h3>
                                        <p class="mt-1 text-xs text-slate-500">{{ __('invoices.credit.invoice', ['number' => $invoice->invoice_number]) }}</p>
                                    </div>
                                    <button type="button" @click="closeCredit()"
                                        class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                                        aria-label="{{ __('invoices.credit.close') }}">&times;</button>
                                </div>

                                <div class="mt-4 space-y-2 rounded-lg bg-blue-50 p-3 text-sm text-blue-900">
                                    <div class="flex justify-between gap-3">
                                        <span>{{ __('invoices.credit.company_balance') }}</span>
                                        <strong class="font-mono">{{ $formatMoney($creditBalanceMinor / 100) }}</strong>
                                    </div>
                                    <div class="flex justify-between gap-3">
                                        <span>{{ __('invoices.credit.invoice_debt') }}</span>
                                        <strong class="font-mono">{{ $formatMoney($paymentAvailability['remaining_minor'] / 100) }}</strong>
                                    </div>
                                    @if ($paymentAvailability['pending_minor'] > 0)
                                        <div class="flex justify-between gap-3">
                                            <span>{{ __('invoices.credit.pending') }}</span>
                                            <strong class="font-mono">{{ $formatMoney($paymentAvailability['pending_minor'] / 100) }}</strong>
                                        </div>
                                    @endif
                                    <div class="flex justify-between gap-3 border-t border-blue-200 pt-2">
                                        <span>{{ __('invoices.credit.maximum') }}</span>
                                        <strong class="font-mono">{{ $formatMoney($creditMaximumMinor / 100) }}</strong>
                                    </div>
                                </div>

                                <form action="{{ route('invoices.apply-credit', $invoice) }}" method="POST" class="mt-4 space-y-4"
                                    @submit="creditSubmitting = true">
                                    @csrf
                                    <input type="hidden" name="expected_credit_balance_minor" value="{{ $creditBalanceMinor }}">
                                    <input type="hidden" name="expected_available_minor" value="{{ $paymentAvailability['available_minor'] }}">
                                    <div>
                                        <label for="credit_amount" class="block text-xs font-semibold tracking-wide text-slate-500">
                                            {{ __('invoices.credit.amount') }}
                                        </label>
                                        <input type="number" name="amount" id="credit_amount" x-ref="creditAmount"
                                            value="{{ old('amount', number_format($creditMaximumMinor / 100, 2, '.', '')) }}"
                                            required min="0.01" max="{{ number_format($creditMaximumMinor / 100, 2, '.', '') }}" step="0.01"
                                            class="mt-1 w-full rounded-lg border @error('credit_amount') border-red-300 @else border-slate-200 @enderror px-3 py-2 text-sm font-mono outline-none transition focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                        @error('credit_amount')
                                            <p class="mt-1 text-xs text-red-600" role="alert">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                        <button type="button" @click="closeCredit()"
                                            class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100">
                                            {{ __('invoices.credit.cancel') }}
                                        </button>
                                        <button type="submit" :disabled="creditSubmitting"
                                            class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">
                                            {{ __('invoices.credit.pay') }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Форма добавления оплаты --}}
            @can('create', [\App\Models\Payment::class, $invoice])
            @if (in_array($invoice->status, ['issued', 'partially_paid']) && $invoice->remaining_amount > 0)
                <div class="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                    <h3
                        class="font-bold text-gray-900 mb-4 pb-2 border-b border-gray-100 text-sm uppercase tracking-wider text-gray-500">
                        {{ __('payments.actions.register') }}</h3>

                    <form action="{{ route('payments.store', $invoice) }}" method="POST" class="space-y-4">
                        @csrf

                        <div>
                            <label for="payment_date"
                                class="block text-xs font-semibold text-gray-500 uppercase mb-1">{{ __('payments.labels.date') }} <span
                                    class="text-red-500">*</span></label>
                            <x-form.date-input name="payment_date" id="payment_date"
                                :value="old('payment_date', date('Y-m-d'))" required />
                        </div>

                        <div>
                            <label for="amount" class="block text-xs font-semibold text-gray-500 uppercase mb-1">{{ __('payments.labels.amount') }} (₼) <span class="text-red-500">*</span></label>
                            <input type="number" name="amount" id="amount"
                                value="{{ session('credit_dialog_open') ? $paymentAvailability['available_amount'] : old('amount', $paymentAvailability['available_amount']) }}" required step="0.01"
                                min="0.01" @disabled($paymentAvailability['available_minor'] === 0)
                                class="w-full px-3 py-2 border @error('amount') border-red-300 @else border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition font-mono"
                                placeholder="0.00">
                            <p class="text-[10px] text-gray-400 mt-1">{{ __('invoices.show.remaining_to_pay') }}
                                {{ $formatMoney($paymentAvailability['remaining_minor'] / 100) }}</p>
                            <p class="text-[10px] text-gray-400 mt-1">{{ __('payments.labels.available_for_new') }}
                                {{ $formatMoney($paymentAvailability['available_amount']) }}</p>
                            @if ($paymentAvailability['available_minor'] === 0 && $paymentAvailability['pending_minor'] > 0)
                                <p class="mt-2 text-xs leading-5 text-amber-700">
                                    {{ __('invoices.credit.reserved') }}<br>
                                    {{ __('payments.labels.pending_action_hint') }}
                                </p>
                            @endif
                            @error('amount')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="payment_method"
                                class="block text-xs font-semibold text-gray-500 uppercase mb-1">{{ __('payments.labels.method') }} <span
                                    class="text-red-500">*</span></label>
                            <select name="payment_method" id="payment_method" required
                                class="w-full px-3 py-2 border @error('payment_method') border-red-300 @else border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                                <option value="transfer" {{ old('payment_method') === 'transfer' ? 'selected' : '' }}>
                                    {{ __('payments.form_methods.transfer') }}</option>
                                <option value="card" {{ old('payment_method') === 'card' ? 'selected' : '' }}>{{ __('payments.form_methods.card') }}</option>
                                <option value="cash" {{ old('payment_method') === 'cash' ? 'selected' : '' }}>{{ __('payments.form_methods.cash') }}</option>
                            </select>
                            @error('payment_method')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="status_payment"
                                class="block text-xs font-semibold text-gray-500 uppercase mb-1">{{ __('payments.labels.status') }} <span
                                    class="text-red-500">*</span></label>
                            <select name="status" id="status_payment" required
                                class="w-full px-3 py-2 border @error('status') border-red-300 @else border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                                <option value="confirmed"
                                    {{ old('status', 'confirmed') === 'confirmed' ? 'selected' : '' }}>{{ __('payments.form_statuses.confirmed') }}</option>
                                <option value="pending" {{ old('status') === 'pending' ? 'selected' : '' }}>{{ __('payments.form_statuses.pending') }}</option>
                            </select>
                            @error('status')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="comment_payment"
                                class="block text-xs font-semibold text-gray-500 uppercase mb-1">{{ __('payments.labels.comment') }}</label>
                            <textarea name="comment" id="comment_payment" rows="3"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition resize-none"
                                placeholder="{{ __('payments.labels.payment_note_placeholder') }}">{{ old('comment') }}</textarea>
                            @error('comment')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="submit"
                            @disabled($paymentAvailability['available_minor'] === 0)
                            class="w-full py-2.5 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg text-sm transition shadow-sm disabled:cursor-not-allowed disabled:opacity-50">
                            {{ __('payments.actions.submit') }}
                        </button>
                    </form>
                </div>
            @endif
            @endcan

            @cannot('viewAny', \App\Models\Payment::class)
                @if ($actionablePayments !== [])
                    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="font-bold text-sm uppercase tracking-wider text-gray-500">
                            {{ __('payments.labels.available_actions') }}
                        </h3>

                        <div class="mt-4 space-y-3">
                            @foreach ($actionablePayments as $actionablePayment)
                                <div class="rounded-lg border border-gray-100 bg-gray-50 p-3 text-sm">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="font-medium text-gray-700">
                                            {{ __('payments.labels.payment_number', ['id' => $actionablePayment['id']]) }}
                                        </span>
                                        <span class="text-xs text-gray-500">
                                            {{ __('payments.statuses.'.$actionablePayment['status']) }}
                                        </span>
                                    </div>

                                    @if ($actionablePayment['can_confirm'])
                                        <form class="mt-3"
                                            action="{{ route('payments.confirm', $actionablePayment['id']) }}"
                                            method="POST">
                                            @csrf
                                            @method('PATCH')

                                            <button type="submit"
                                                class="inline-flex items-center rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-xs font-medium text-green-700 transition hover:bg-green-100">
                                                {{ __('payments.actions.confirm') }}
                                            </button>
                                        </form>
                                    @endif

                                    @if ($actionablePayment['can_cancel'])
                                        <form class="mt-3 space-y-2"
                                            action="{{ route('payments.cancel', $actionablePayment['id']) }}"
                                            method="POST">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="cancel_payment_id"
                                                value="{{ $actionablePayment['id'] }}">

                                            <label class="block text-xs font-medium text-gray-600"
                                                for="action_cancel_reason_{{ $actionablePayment['id'] }}">
                                                {{ __('payments.labels.cancel_reason') }}
                                            </label>
                                            <textarea id="action_cancel_reason_{{ $actionablePayment['id'] }}"
                                                name="cancel_reason" rows="2" required minlength="3" maxlength="1000"
                                                class="w-full resize-none rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 outline-none transition focus:border-red-300 focus:ring-1 focus:ring-red-200"></textarea>

                                            <button type="submit"
                                                class="text-xs font-medium text-red-600 transition hover:text-red-800">
                                                {{ __('payments.actions.cancel') }}
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endcannot

            {{-- Дополнительные детали и действия по платежам --}}
            @can('viewAny', \App\Models\Payment::class)
            @php
                $paymentHistoryShouldOpen = $errors->has('cancel_reason') && old('cancel_payment_id');
            @endphp
            <div x-data="{
                    paymentHistoryOpen: @js((bool) $paymentHistoryShouldOpen),
                    openPaymentHistory() {
                        this.paymentHistoryOpen = true;
                        document.body.style.overflow = 'hidden';
                        this.$nextTick(() => this.$refs.paymentHistoryClose.focus());
                    },
                    closePaymentHistory() {
                        this.paymentHistoryOpen = false;
                        document.body.style.overflow = '';
                        this.$nextTick(() => this.$refs.paymentHistoryTrigger?.focus());
                    },
                    trapPaymentHistoryFocus(event) {
                        const focusable = [...this.$refs.paymentHistoryDrawer.querySelectorAll(
                            `a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex='-1'])`
                        )].filter(element => element.offsetParent !== null);

                        if (focusable.length === 0) {
                            event.preventDefault();
                            return;
                        }

                        const first = focusable[0];
                        const last = focusable[focusable.length - 1];

                        if (event.shiftKey && document.activeElement === first) {
                            event.preventDefault();
                            last.focus();
                        } else if (! event.shiftKey && document.activeElement === last) {
                            event.preventDefault();
                            first.focus();
                        }
                    }
                }"
                x-init="if (paymentHistoryOpen) { document.body.style.overflow = 'hidden'; $nextTick(() => $refs.paymentHistoryClose.focus()); }"
                x-on:keydown.escape.window="if (paymentHistoryOpen) closePaymentHistory()"
                x-on:open-payment-history.window="openPaymentHistory()"
                class="invoice-payment-history crm-print-hide print:hidden">

                @if ($paymentBreakdown['payments_count'] > 0)
                    {{-- Drawer с дополнительными деталями платежей --}}
                    <div x-show="paymentHistoryOpen" x-cloak id="payment-history-drawer"
                        class="payment-history-drawer crm-print-hide fixed inset-0 z-50 print:hidden"
                        role="dialog" aria-modal="true" aria-labelledby="payment-history-title"
                        x-ref="paymentHistoryDrawer" x-on:keydown.tab="trapPaymentHistoryFocus($event)">
                        <div x-show="paymentHistoryOpen" x-transition.opacity
                            class="payment-history-backdrop crm-print-hide absolute inset-0 bg-gray-900/40 print:hidden" @click="closePaymentHistory()"></div>

                        <aside x-show="paymentHistoryOpen"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="translate-x-full"
                            x-transition:enter-end="translate-x-0"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="translate-x-0"
                            x-transition:leave-end="translate-x-full"
                            @click.stop
                            class="absolute inset-y-0 right-0 flex w-full max-w-[480px] flex-col overflow-x-hidden bg-white shadow-2xl sm:w-[min(480px,calc(100vw-2rem))]">
                            <header class="sticky top-0 z-10 flex shrink-0 items-start justify-between gap-4 border-b border-gray-200 bg-white px-5 py-4">
                                <div>
                                    <h3 id="payment-history-title" class="font-bold text-gray-900">{{ __('payments.labels.history') }}</h3>
                                    <p class="mt-0.5 text-xs text-gray-500">{{ __('invoices.credit.invoice', ['number' => $invoice->invoice_number]) }}</p>
                                    @if ($invoice->company)
                                        <p class="mt-0.5 text-xs text-gray-500">{{ $invoice->company->name }}</p>
                                    @endif
                                    <p class="mt-1 text-xs text-gray-400">{{ __('payments.labels.all_count', ['count' => $paymentBreakdown['payments_count']]) }}</p>
                                </div>
                                <button type="button" x-ref="paymentHistoryClose" @click="closePaymentHistory()"
                                    aria-label="{{ __('payments.labels.close_history') }}"
                                    class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </header>

                            <div class="min-h-0 flex-1 overflow-x-hidden overflow-y-auto px-4 py-4 sm:px-5">
                <div id="payment-history-list" class="space-y-3">
                    @forelse ($paymentBreakdown['paymentRows'] as $paymentRow)
                        @php
                            $payment = $paymentsById->get($paymentRow['id']);

                            /*
                             * Платёж, созданный из Credit Balance, нельзя отменять
                             * как обычный банковский/наличный платёж без точной
                             * ledger-связи. Сервер дополнительно проверяет источник.
                             */
                            $isCreditBalancePayment = in_array(
                                $paymentRow['id'],
                                $paymentSource['credit_balance_payment_ids'],
                                true
                            );

                            /*
                             * После ошибки валидации повторно открываем форму
                             * именно того платежа, который пользователь отменял.
                             */
                            $shouldOpenCancellation =
                                $errors->has('cancel_reason') &&
                                (string) old('cancel_payment_id') === (string) $payment->id;
                        @endphp

                        <div x-data="{ cancelOpen: @js($shouldOpenCancellation), allocationOpen: false, cancelSubmitting: false }"
                            x-on:keydown.escape="cancelOpen = false"
                            class="min-w-0 overflow-hidden rounded-lg border border-gray-200 p-4 text-sm">

                            <div class="flex items-center justify-between gap-3">
                                <span class="font-semibold font-mono text-gray-900">

                                    {{ $formatMoney($paymentRow['amount']) }}
                                </span>

                                @include('partials.badge', [
                                    'status' => $payment->status,
                                    'label' => __('payments.statuses.'.$payment->status),
                                ])
                            </div>

                            <div class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-gray-400">
                                <span>
                                    {{ $payment->payment_date ? \Illuminate\Support\Carbon::parse($payment->payment_date)->format('d/m/Y') : '—' }}
                                </span>

                                <span aria-hidden="true">·</span>

                                <span class="font-medium">
                                    {{ __('payments.methods.'.$paymentRow['payment_method']) }}
                                </span>
                            </div>

                            @if ($isCreditBalancePayment)
                                <div data-testid="payment-source-balance-history"
                                    class="mt-2 inline-flex items-center rounded-md bg-blue-50 px-2 py-1
                                           text-[11px] font-medium text-blue-700">

                                    {{ __('invoices.credit.from_balance') }}
                                </div>
                            @endif

                            @if ($payment->status === 'confirmed')
                                <div class="mt-3 space-y-1 text-xs text-gray-600">
                                    <div class="flex min-w-0 items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            {{ __('payments.labels.applied') }}
                                            <span class="font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                                {{ $formatMoney($paymentRow['applied_amount']) }}
                                            </span>
                                        </div>

                                        @if ($paymentRow['allocations'] !== [])
                                            <button type="button" @click="allocationOpen = !allocationOpen"
                                                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1"
                                                :aria-expanded="allocationOpen.toString()"
                                                :aria-label="allocationOpen ? @js(__('payments.labels.hide_allocation')) : @js(__('payments.labels.show_allocation'))"
                                                aria-controls="payment-allocation-{{ $paymentRow['id'] }}">
                                                <svg x-show="!allocationOpen" aria-hidden="true" class="h-4 w-4" fill="none"
                                                    stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="m6 9 6 6 6-6" />
                                                </svg>
                                                <svg x-show="allocationOpen" x-cloak aria-hidden="true" class="h-4 w-4" fill="none"
                                                    stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="m18 15-6-6-6 6" />
                                                </svg>
                                            </button>
                                        @endif
                                    </div>

                                    @if ($paymentRow['unallocated_amount'] !== '0.00')
                                        <div>
                                            {{ __('payments.labels.overpayment') }}
                                            <span class="font-semibold text-blue-700 tabular-nums whitespace-nowrap">
                                                {{ $formatMoney($paymentRow['unallocated_amount']) }}
                                            </span>
                                            <span class="block text-[11px] text-gray-400">{{ __('payments.labels.overpayment_hint') }}</span>
                                        </div>
                                    @endif
                                </div>

                                @if ($paymentRow['allocations'] !== [])
                                    <div class="mt-3">
                                        <div id="payment-allocation-{{ $paymentRow['id'] }}" x-show="allocationOpen" x-cloak
                                            class="mt-2 rounded-lg border border-gray-100 bg-gray-50 p-3">
                                            <div class="mb-2 text-xs font-semibold text-gray-700">{{ __('payments.labels.allocation') }}</div>

                                            <div class="divide-y divide-gray-200">
                                                @foreach ($paymentRow['allocations'] as $allocation)
                                                    <div class="flex items-start justify-between gap-4 py-2 first:pt-0 last:pb-0">
                                                        <div class="min-w-0">
                                                            <div class="text-xs font-medium text-gray-800 break-words">
                                                                {{ $allocation['line_description'] }}
                                                            </div>
                                                            @if ($allocation['line_type'] !== 'subscription' || $allocation['period_label'])
                                                                <div class="mt-0.5 break-words text-[11px] text-gray-500">
                                                                    @if ($allocation['line_type'] === 'subscription')
                                                                        {{ $allocation['period_label'] }}
                                                                    @else
                                                                        {{ $allocation['line_type'] === 'order' ? __('invoices.form.one_time') : __('invoices.form.manual_line') }}
                                                                    @endif
                                                                </div>
                                                            @endif
                                                        </div>
                                                        <span class="shrink-0 whitespace-nowrap text-xs font-semibold text-gray-900 tabular-nums">
                                                            {{ $formatMoney($allocation['allocated_amount']) }}
                                                        </span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @elseif ($payment->status === 'pending')
                                <p class="mt-3 text-xs text-gray-500">{{ __('payments.labels.pending_hint') }}</p>
                            @endif

                            @if ($payment->comment)
                                <p class="text-xs text-gray-500 italic mt-2 bg-gray-50 rounded-lg p-2">
                                    {{ $payment->comment }}
                                </p>
                            @endif

                            {{-- Данные отменённого платежа --}}
                            @if ($payment->status === 'cancelled')
                                <div data-testid="invoice-history-cancellation-{{ $payment->id }}" class="mt-2 text-xs text-red-600">
                                    <span>{{ __('payments.labels.cancelled_at') }}</span>
                                    {{ $payment->cancelled_at ? $displayDateTime->format($payment->cancelled_at, 'd/m/Y H:i') : '—' }}
                                    @if ($payment->cancel_reason)
                                        <span class="text-red-400"> · </span>
                                        <span>{{ __('payments.labels.reason') }} {{ $payment->cancel_reason }}</span>
                                    @endif
                                </div>
                            @endif

                            @if (in_array($payment->status, ['pending', 'confirmed'], true))
                                <div data-testid="invoice-history-actions-{{ $payment->id }}"
                                    class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-gray-100 pt-3 text-xs font-medium">
                                    @if ($payment->status === 'pending')
                                        @can('confirm', $payment)
                                            <form action="{{ route('payments.confirm', $payment) }}" method="POST"
                                                onsubmit="return confirm(@js(__('payments.confirm_message')))" >
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" data-testid="invoice-history-confirm-action"
                                                    class="inline-flex !h-9 !min-h-0 !w-24 shrink-0 items-center justify-center whitespace-nowrap !rounded-md border !px-0 !py-0 !text-sm !font-medium transition focus:outline-none focus:ring-2 focus:ring-offset-1 border-green-200 bg-green-50 text-green-700 hover:bg-green-100 focus:ring-green-500">
                                                    {{ __('payments.actions.confirm_short') }}
                                                </button>
                                            </form>
                                        @endcan
                                    @endif

                                    @can('cancel', $payment)
                                        @if (!$isCreditBalancePayment)
                                            <button type="button" data-testid="invoice-history-cancel-action" x-show="!cancelOpen"
                                                class="inline-flex !h-9 !min-h-0 !w-24 shrink-0 items-center justify-center whitespace-nowrap !rounded-md border !px-0 !py-0 !text-sm !font-medium transition focus:outline-none focus:ring-2 focus:ring-offset-1 border-red-200 bg-red-50 text-red-700 hover:bg-red-100 focus:ring-red-500"
                                                @click="cancelOpen = true; $nextTick(() => $refs.cancelReason.focus())">
                                                {{ __('payments.actions.cancel_short') }}
                                            </button>
                                        @endif
                                    @endcan
                                </div>
                            @endif

                            {{-- Отмена обычного ожидающего или подтверждённого платежа --}}
                            @can('cancel', $payment)
                            @if (in_array($payment->status, ['pending', 'confirmed'], true) && !$isCreditBalancePayment)
                                <form x-show="cancelOpen" x-cloak action="{{ route('payments.cancel', $payment) }}"
                                        method="POST"
                                        class="mt-3 space-y-2 border-t border-red-100 pt-3"
                                        x-on:submit="
                                            const reason = $event.currentTarget.elements.cancel_reason.value.trim();
                                            if (cancelSubmitting || reason === '' || !$event.currentTarget.checkValidity()) {
                                                $event.preventDefault();
                                                $event.currentTarget.reportValidity();
                                                return;
                                            }
                                            if (!confirm(@js(__('payments.cancel_message')))) {
                                                $event.preventDefault();
                                                return;
                                            }
                                            cancelSubmitting = true;
                                        ">

                                        @csrf
                                        @method('PATCH')

                                        <input type="hidden" name="cancel_payment_id" value="{{ $payment->id }}">

                                        <div>
                                            <label for="cancel_reason_{{ $payment->id }}"
                                                class="block text-xs font-semibold text-red-700 mb-1">

                                                {{ __('payments.labels.cancel_reason') }}
                                                <span class="text-red-500">*</span>
                                            </label>

                                            <textarea id="cancel_reason_{{ $payment->id }}" name="cancel_reason" rows="2" required minlength="3"
                                                maxlength="1000"
                                                x-ref="cancelReason"
                                                x-on:keydown.enter="
                                                    if (!$event.shiftKey) {
                                                        $event.preventDefault();
                                                        $event.currentTarget.form.requestSubmit();
                                                    }
                                                "
                                                class="w-full resize-none rounded-lg border
                                                    {{ $shouldOpenCancellation ? 'border-red-300' : 'border-red-200' }}
                                                    bg-white px-3 py-2 text-sm text-gray-700
                                                    outline-none transition
                                                    focus:border-red-400 focus:ring-1 focus:ring-red-300"
                                                placeholder="{{ __('payments.labels.cancel_reason_example') }}">{{ $shouldOpenCancellation ? old('cancel_reason') : '' }}</textarea>

                                            @if ($shouldOpenCancellation)
                                                @error('cancel_reason')
                                                    <p class="mt-1 text-xs text-red-600">
                                                        {{ $message }}
                                                    </p>
                                                @enderror
                                            @endif
                                        </div>

                                        <div class="flex flex-wrap justify-end gap-2">
                                            <button type="button" @click="cancelOpen = false"
                                                class="inline-flex h-7 items-center rounded-md border border-gray-200 bg-white px-2 py-1 text-[11px] font-medium text-gray-600 transition hover:bg-gray-50 hover:text-gray-900">

                                                {{ __('payments.labels.do_not_cancel') }}
                                            </button>

                                            <button type="submit" :disabled="cancelSubmitting"
                                                class="inline-flex h-7 items-center rounded-md border border-red-200 bg-red-50 px-1.5 py-1 text-[11px] font-medium text-red-700 transition hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-1">

                                                {{ __('payments.labels.confirm_cancellation') }}
                                            </button>
                                        </div>
                                    </form>
                            @endif
                            @endcan
                        </div>
                    @empty
                        <p class="text-sm text-gray-400 py-1">
                            {{ __('payments.labels.no_history') }}
                        </p>
                    @endforelse
                </div>
                            </div>
                        </aside>
                    </div>
                @endif
            </div>
            @endcan

        </div>

    </div>

@endsection
