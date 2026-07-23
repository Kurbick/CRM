@extends('layouts.app')

@section('title', 'Счёт ' . $invoice->invoice_number)

@section('content')
    @php
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
    @endphp

    {{-- Верхний заголовок и действия --}}
    <div class="invoice-page-header crm-print-hide mb-6 flex flex-col sm:flex-row justify-between sm:items-center gap-4 print:hidden">
        <div>
            <a href="{{ $companyContext['active'] ? $companyContext['company_url'] : route('invoices.index') }}"
                class="text-sm text-gray-500 hover:text-gray-900 transition flex items-center gap-1.5 mb-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                {{ $companyContext['active'] ? $companyContext['label'] : 'Назад к списку' }}
            </a>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold text-gray-900">Счёт {{ $invoice->invoice_number }}</h1>
                @include('partials.badge', ['status' => $invoice->status])
            </div>
        </div>

        @error('issue')
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                <p class="text-sm text-red-700">
                    {{ $message }}
                </p>
            </div>
        @enderror

        @error('cancel')
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                <p class="text-sm text-red-700">
                    {{ $message }}
                </p>
            </div>
        @enderror

        @error('payment_confirm')
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                <p class="text-sm text-red-700">
                    {{ $message }}
                </p>
            </div>
        @enderror

        <div class="flex flex-wrap items-center gap-5">
            {{-- Кнопка Печать --}}
            <button type="button" onclick="window.print()"
                class="inline-flex items-center text-sm border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 px-4 py-2 rounded-lg font-medium transition shadow-sm">
                <svg class="w-4 h-4 mr-1.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                </svg>
                Печать
            </button>

            <div class="flex items-center gap-2">
                @if ($invoice->status === 'draft')
                    <a href="{{ route('invoices.edit', $invoice) }}{{ $companyContext['active'] ? '?'.http_build_query($companyContext['query']) : '' }}"
                        class="px-4 py-2 border border-gray-200 text-gray-600
                   text-sm font-medium rounded-lg hover:bg-gray-50 transition">

                        Редактировать
                    </a>

                    <form action="{{ route('invoices.destroy', $invoice) }}" method="POST"
                        onsubmit="return confirm('Вы уверены, что хотите удалить этот счет? Действие необратимо.')">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                            class="inline-flex items-center text-sm bg-red-50 hover:bg-red-100 text-red-700 px-4 py-2 rounded-lg font-medium transition border border-red-200">
                            <svg class="w-4 h-4 mr-1.5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                            Удалить
                        </button>
                    </form>
                @endif
                @if ($invoice->status === 'issued' && $invoice->payments->isEmpty())
                    <form action="{{ route('invoices.cancel', $invoice) }}" method="POST"
                        onsubmit="return confirm('Отменить выставленный инвойс? График подписок будет восстановлен.')">

                        @csrf
                        @method('PATCH')

                        <button type="submit"
                            class="inline-flex items-center px-4 py-2
                       rounded-lg border border-red-200
                       bg-red-50 text-sm font-medium text-red-700
                       hover:bg-red-100 transition">

                            Отменить счёт
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="invoice-screen-grid grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Основной документ инвойса (2/3 ширины) --}}
        <div class="invoice-document-column lg:col-span-2 print:w-full print:col-span-3">

            {{-- Печатный бланк счета --}}
            <div
                class="invoice-document bg-white rounded-xl border border-gray-200 shadow-sm p-6 md:p-8 relative overflow-hidden print:border-none print:shadow-none print:p-0">

                {{-- Верхняя декоративная полоса (скрывается при печати) --}}
                <div class="crm-print-hide absolute top-0 left-0 right-0 h-1.5 bg-blue-600 print:hidden"></div>

                {{-- Шапка бланка --}}
                <div class="flex flex-col md:flex-row justify-between gap-5 pb-6 border-b border-gray-100 mb-6">

                    {{-- Данные продавца (Мы) --}}
                    <div class="space-y-1">
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Поставщик услуг</div>
                        <h2 class="text-lg font-bold text-gray-900">{{ $invoice->seller_name ?? 'IT Solutions MMC' }}</h2>
                        <div class="text-sm text-gray-600 font-mono">VÖEN: {{ $invoice->seller_voen ?? '9900123456' }}
                        </div>
                        @if ($invoice->seller_bank_name ?? 'Pasha Bank OJSC')
                            <div class="text-sm text-gray-600 mt-1.5">
                                <span class="font-medium text-gray-800">Банк:</span>
                                {{ $invoice->seller_bank_name ?? 'Pasha Bank OJSC' }}
                            </div>
                        @endif
                        @if ($invoice->seller_iban ?? 'AZ00PRCB0000000000000000000')
                            <div class="text-sm text-gray-600 break-words [overflow-wrap:anywhere]">
                                <span class="font-medium text-gray-800">IBAN:</span>
                                <span class="font-mono">{{ $invoice->seller_iban ?? 'AZ00PRCB0000000000000000000' }}</span>
                            </div>
                        @endif
                        @if (($invoice->seller_swift ?? 'PAHBAZ2D') || ($invoice->seller_bank_code ?? '505050'))
                            <div class="grid grid-cols-2 gap-2 text-sm text-gray-600">
                            @if ($invoice->seller_swift ?? 'PAHBAZ2D')
                                <div><span class="font-medium text-gray-800">SWIFT:</span> <span
                                        class="font-mono">{{ $invoice->seller_swift ?? 'PAHBAZ2D' }}</span></div>
                            @endif
                            @if ($invoice->seller_bank_code ?? '505050')
                                <div><span class="font-medium text-gray-800">Код банка:</span> <span
                                        class="font-mono">{{ $invoice->seller_bank_code ?? '505050' }}</span></div>
                            @endif
                            </div>
                        @endif
                        @if ($invoice->seller_bank_voen)
                            <div class="text-sm text-gray-600 font-mono">
                                <span class="font-medium text-gray-800">VÖEN банка:</span> {{ $invoice->seller_bank_voen }}
                            </div>
                        @endif
                    </div>

                    {{-- Метаданные инвойса --}}
                    <div class="space-y-2 md:text-right md:self-start">
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Счёт</div>
                        <h2 class="text-xl font-bold text-gray-900 font-mono">{{ $invoice->invoice_number }}</h2>

                        <div class="text-sm text-gray-600">
                            <span class="font-medium text-gray-800">Дата выставления:</span> {{ $invoice->issue_date ? \Illuminate\Support\Carbon::parse($invoice->issue_date)->format('d/m/Y') : '—' }}
                        </div>
                        <div class="text-sm text-gray-600">
                            <span class="font-medium text-gray-800">Срок оплаты:</span> {{ $invoice->due_date ? \Illuminate\Support\Carbon::parse($invoice->due_date)->format('d/m/Y') : '—' }}
                        </div>
                        @if ($invoice->period_start && $invoice->period_end)
                            <div class="text-sm text-gray-600">
                                <span class="font-medium text-gray-800">Расчетный период:</span>
                                <div class="text-xs text-gray-500 font-mono mt-0.5">{{ \Illuminate\Support\Carbon::parse($invoice->period_start)->format('d/m/Y') }} —
                                    {{ \Illuminate\Support\Carbon::parse($invoice->period_end)->format('d/m/Y') }}</div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Получатель счета (Плательщик) --}}
                <div
                    class="invoice-payer bg-gray-50 rounded-lg px-4 py-3.5 mb-6 grid grid-cols-1 md:grid-cols-2 gap-3 print:bg-gray-100 print:rounded-none">
                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Плательщик</div>
                        <h3 class="font-bold text-gray-900">{{ $invoice->payer_name ?: 'Не указан' }}</h3>
                        @if (trim((string) $invoice->payer_voen) !== '')
                            <div class="text-sm text-gray-600 font-mono mt-0.5">VÖEN: {{ $invoice->payer_voen }}</div>
                        @endif
                    </div>
                    <div class="md:text-right md:self-center">
                        @if ($invoice->contract_reference)
                            <div class="text-sm text-gray-600">
                                <span class="font-medium text-gray-800">Договор:</span>
                                <span class="font-mono text-gray-900 font-semibold">{{ $invoice->contract_reference }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Таблица позиций (Lines) --}}
                <div class="mb-6">
                    <div class="overflow-x-auto print:overflow-visible">
                    <table class="w-full table-auto text-left text-sm">
                        <thead>
                            <tr
                                class="border-b border-gray-200 text-gray-400 font-semibold uppercase tracking-wider text-xs pb-3">
                                <th class="w-8 pb-3 pr-2">№</th>
                                <th class="pb-3 pr-4">Позиция</th>
                                <th class="invoice-print-only hidden pb-3 pr-4">Описание / тип</th>
                                <th class="invoice-print-only hidden pb-3 pr-4">Расчётный период</th>
                                <th class="pb-3 text-right pr-4">Сумма</th>
                                <th class="crm-print-hide pb-3 text-right pr-4 print:hidden">Оплачено</th>
                                <th class="crm-print-hide pb-3 text-right pr-4 print:hidden">Остаток</th>
                                <th class="crm-print-hide pb-3 print:hidden">Статус</th>
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
                                            <div class="crm-print-hide mt-0.5 text-xs text-gray-500 break-words">{{ $line['type_label'] }}</div>
                                        @endif
                                    </td>
                                    <td class="invoice-print-only hidden py-4 pr-4 text-xs text-gray-600">
                                        {{ $line['type'] === 'subscription' ? '' : $line['type_label'] }}
                                    </td>
                                    <td class="invoice-print-only hidden py-4 pr-4 text-xs text-gray-600">
                                        {{ $line['period_label'] ?: '—' }}
                                    </td>
                                    <td class="py-4 text-right font-semibold text-gray-900 font-mono pr-4">
                                        <span class="whitespace-nowrap tabular-nums">{{ $formatMoney($line['amount']) }}</span>
                                    </td>
                                    <td class="crm-print-hide py-4 text-right font-semibold text-green-600 font-mono pr-4 print:hidden">
                                        <span class="whitespace-nowrap tabular-nums">{{ $formatMoney($line['paid_amount']) }}</span>
                                    </td>
                                    <td class="crm-print-hide py-4 text-right font-semibold text-gray-900 font-mono pr-4 print:hidden">
                                        <span class="whitespace-nowrap tabular-nums">{{ $formatMoney($line['remaining_amount']) }}</span>
                                    </td>
                                    <td class="crm-print-hide py-4 print:hidden">
                                        <span @class([
                                            'inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium',
                                            'bg-green-50 text-green-700' => $line['payment_state'] === 'paid',
                                            'bg-amber-50 text-amber-700' => $line['payment_state'] === 'partially_paid',
                                            'bg-gray-100 text-gray-600' => $line['payment_state'] === 'unpaid',
                                        ])>
                                            {{ $line['payment_state_label'] }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                </div>

                {{-- Расчет итога --}}
                <div class="invoice-totals border-t border-gray-100 pt-6 flex flex-col items-end gap-2 text-sm text-gray-600">
                    <div class="flex justify-between w-64">
                        <span>Итоговая сумма счёта:</span>

                        <span class="font-bold text-gray-900 font-mono">
                            {{ $formatMoney($invoice->total_amount) }}
                        </span>
                    </div>

                    <div class="flex justify-between w-64 text-green-600">
                        <span>Оплачено:</span>

                        <span class="font-bold font-mono">
                            {{ $formatMoney($invoice->applied_amount) }}
                        </span>
                    </div>

                    @if ($paymentSource['credit_balance_applied_minor'] > 0)
                        <div class="w-64 text-right text-xs text-gray-400">
                            Из баланса: {{ $formatMoney($paymentSource['credit_balance_applied_amount']) }}
                        </div>
                    @endif

                    @if ($invoice->overpayment_amount > 0)
                        <div class="flex justify-between w-64 text-blue-600">
                            <span>Переплата:</span>

                            <span class="font-bold font-mono">
                                {{ $formatMoney($invoice->overpayment_amount) }}
                            </span>
                        </div>
                    @endif

                    <div
                        class="flex justify-between w-64 border-t border-gray-100 pt-2 text-base
                        {{ $remainingColor }}">

                        <span class="font-semibold">
                            Остаток к оплате:
                        </span>

                        <span class="font-bold font-mono">
                            {{ $formatMoney($invoice->remaining_amount) }}
                        </span>
                    </div>
                </div>

                @if ($invoice->status === 'draft')
                    <div class="crm-print-hide mt-4 flex justify-end print:hidden">
                        <form action="{{ route('invoices.issue', $invoice) }}" method="POST"
                            class="w-64 max-w-full"
                            onsubmit="return confirm('Выставить этот инвойс? После этого свободное редактирование будет недоступно.')">

                            @csrf

                            <button type="submit"
                                class="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-blue-700">

                                Выставить счёт
                            </button>
                        </form>
                    </div>
                @endif

                {{-- Примечание продавца --}}
                @if (filled($invoice->comment))
                    <div class="invoice-comment mt-6 pt-4 border-t border-gray-100 text-sm break-words">
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Комментарий</div>
                        <p class="text-gray-600 whitespace-pre-line">{{ $invoice->comment }}</p>
                    </div>
                @endif

            </div>

        </div>

        {{-- Правая боковая колонка: Регистрация оплат и история (скрывается при печати) --}}
        <div class="invoice-sidebar crm-print-hide space-y-6 print:hidden">

            {{-- Форма добавления оплаты --}}
            @if (in_array($invoice->status, ['issued', 'partially_paid']) && $invoice->remaining_amount > 0)
                <div class="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                    <h3
                        class="font-bold text-gray-900 mb-4 pb-2 border-b border-gray-100 text-sm uppercase tracking-wider text-gray-500">
                        Зарегистрировать платеж</h3>

                    <form action="{{ route('payments.store', $invoice) }}" method="POST" class="space-y-4">
                        @csrf

                        <div>
                            <label for="payment_date"
                                class="block text-xs font-semibold text-gray-500 uppercase mb-1">Дата платежа <span
                                    class="text-red-500">*</span></label>
                            <x-form.date-input name="payment_date" id="payment_date"
                                :value="old('payment_date', date('Y-m-d'))" required />
                        </div>

                        <div>
                            <label for="amount" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Сумма
                                (₼) <span class="text-red-500">*</span></label>
                            <input type="number" name="amount" id="amount"
                                value="{{ old('amount', $invoice->remaining_amount) }}" required step="0.01"
                                min="0.01"
                                class="w-full px-3 py-2 border @error('amount') border-red-300 @else border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition font-mono"
                                placeholder="0.00">
                            <p class="text-[10px] text-gray-400 mt-1">Остаток к оплате:
                                {{ $formatMoney($invoice->remaining_amount) }}</p>
                            @error('amount')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="payment_method"
                                class="block text-xs font-semibold text-gray-500 uppercase mb-1">Метод оплаты <span
                                    class="text-red-500">*</span></label>
                            <select name="payment_method" id="payment_method" required
                                class="w-full px-3 py-2 border @error('payment_method') border-red-300 @else border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                                <option value="transfer" {{ old('payment_method') === 'transfer' ? 'selected' : '' }}>
                                    Безналичный перевод (Банк)</option>
                                <option value="card" {{ old('payment_method') === 'card' ? 'selected' : '' }}>Банковская
                                    карта</option>
                                <option value="cash" {{ old('payment_method') === 'cash' ? 'selected' : '' }}>Наличные
                                </option>
                            </select>
                            @error('payment_method')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="status_payment"
                                class="block text-xs font-semibold text-gray-500 uppercase mb-1">Статус платежа <span
                                    class="text-red-500">*</span></label>
                            <select name="status" id="status_payment" required
                                class="w-full px-3 py-2 border @error('status') border-red-300 @else border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                                <option value="confirmed"
                                    {{ old('status', 'confirmed') === 'confirmed' ? 'selected' : '' }}>Проведён /
                                    подтверждён</option>
                                <option value="pending" {{ old('status') === 'pending' ? 'selected' : '' }}>Ожидает
                                    подтверждения</option>
                            </select>
                            @error('status')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="comment_payment"
                                class="block text-xs font-semibold text-gray-500 uppercase mb-1">Примечание к
                                платежу</label>
                            <textarea name="comment" id="comment_payment" rows="3"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition resize-none"
                                placeholder="Номер платежного поручения, имя плательщика и т.д...">{{ old('comment') }}</textarea>
                            @error('comment')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="submit"
                            class="w-full py-2.5 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg text-sm transition shadow-sm">
                            Провести платёж
                        </button>
                    </form>
                </div>
            @endif

            {{-- История платежей --}}
            @php
                $paymentHistoryShouldOpen = $errors->has('cancel_reason') && old('cancel_payment_id');
                $latestPayment = $paymentBreakdown['latest_payment'];
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
                    }
                }"
                x-init="if (paymentHistoryOpen) { document.body.style.overflow = 'hidden'; $nextTick(() => $refs.paymentHistoryClose.focus()); }"
                x-on:keydown.escape.window="if (paymentHistoryOpen) closePaymentHistory()"
                class="invoice-payment-history crm-print-hide print:hidden">

                {{-- Компактная карточка в основном потоке страницы --}}
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="font-bold text-sm uppercase tracking-wider text-gray-500">История платежей</h3>
                        <span class="inline-flex min-w-6 items-center justify-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                            {{ $paymentBreakdown['payments_count'] }}
                        </span>
                    </div>

                    @if ($latestPayment)
                        <div class="mt-4 text-sm">
                            <div class="text-xs text-gray-400">Последний платёж:</div>
                            <div class="mt-1 font-semibold text-gray-900">
                                <span class="whitespace-nowrap tabular-nums">{{ $formatMoney($latestPayment['amount']) }}</span>
                                <span class="font-normal text-gray-400">·</span>
                                <span>{{ $latestPayment['status'] === 'pending' ? 'Ожидает подтверждения' : $latestPayment['status_label'] }}</span>
                            </div>
                            @if ($latestPayment['payment_date'])
                                <div class="mt-0.5 text-xs text-gray-500">
                                    {{ \Illuminate\Support\Carbon::parse($latestPayment['payment_date'])->format('d/m/Y') }}
                                </div>
                            @endif
                        </div>

                        @if ($paymentBreakdown['pending_payments_count'] > 0)
                            <div class="mt-3 rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700">
                                Ожидают подтверждения: {{ $paymentBreakdown['pending_payments_count'] }}
                            </div>
                        @endif

                        @if ($paymentSource['credit_balance_applied_minor'] > 0)
                            <div class="mt-3 text-xs text-gray-400">
                                Из баланса: {{ $formatMoney($paymentSource['credit_balance_applied_amount']) }}
                            </div>
                        @endif

                        <button type="button" x-ref="paymentHistoryTrigger" @click="openPaymentHistory()"
                            aria-haspopup="dialog" aria-controls="payment-history-drawer"
                            class="mt-4 inline-flex w-full items-center justify-between border-t border-gray-100 pt-3 text-sm font-medium text-blue-600 transition hover:text-blue-800">
                            <span>Открыть историю</span>
                            <span aria-hidden="true">→</span>
                        </button>
                    @else
                        <p class="mt-3 text-sm text-gray-400">Платежей пока нет.</p>
                    @endif
                </div>

                @if ($paymentBreakdown['payments_count'] > 0)
                    {{-- Drawer с полной историей --}}
                    <div x-show="paymentHistoryOpen" x-cloak id="payment-history-drawer"
                        class="payment-history-drawer crm-print-hide fixed inset-0 z-50 print:hidden"
                        role="dialog" aria-modal="true" aria-labelledby="payment-history-title">
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
                                    <h3 id="payment-history-title" class="font-bold text-gray-900">История платежей</h3>
                                    <p class="mt-0.5 text-xs text-gray-500">Счёт {{ $invoice->invoice_number }}</p>
                                    <p class="mt-1 text-xs text-gray-400">Всего платежей: {{ $paymentBreakdown['payments_count'] }}</p>
                                </div>
                                <button type="button" x-ref="paymentHistoryClose" @click="closePaymentHistory()"
                                    aria-label="Закрыть историю платежей"
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
                             * Платёж, автоматически созданный из Credit Balance,
                             * нельзя отменять как обычный банковский/наличный платёж.
                             * Сервер дополнительно проверяет это в PaymentController.
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
                            class="min-w-0 overflow-hidden rounded-lg border border-gray-200 p-4 text-sm">

                            <div class="flex items-center justify-between gap-3">
                                <span
                                    class="font-semibold font-mono
                                        {{ $payment->status === 'cancelled' ? 'text-gray-400 line-through' : 'text-gray-900' }}">

                                    {{ $formatMoney($paymentRow['amount']) }}
                                </span>

                                @include('partials.badge', [
                                    'status' => $payment->status,
                                ])
                            </div>

                            <div class="flex justify-between gap-3 text-xs text-gray-400 mt-1">
                                <span>
                                    {{ $payment->payment_date ? \Illuminate\Support\Carbon::parse($payment->payment_date)->format('d/m/Y') : '—' }}
                                </span>

                                <span class="font-medium">
                                    {{ $paymentRow['payment_method_label'] }}
                                </span>
                            </div>

                            @if ($isCreditBalancePayment)
                                <div
                                    class="mt-2 inline-flex items-center rounded-md bg-blue-50 px-2 py-1
                                           text-[11px] font-medium text-blue-700">

                                    Из баланса
                                </div>
                            @endif

                            @if ($payment->status === 'confirmed')
                                <div class="mt-3 space-y-1 text-xs text-gray-600">
                                    <div class="flex min-w-0 items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            Применено к счёту:
                                            <span class="font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                                {{ $formatMoney($paymentRow['applied_amount']) }}
                                            </span>
                                        </div>

                                        @if ($paymentRow['allocations'] !== [])
                                            <button type="button" @click="allocationOpen = !allocationOpen"
                                                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1"
                                                :aria-expanded="allocationOpen.toString()"
                                                :aria-label="allocationOpen ? 'Скрыть распределение' : 'Показать распределение'"
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
                                            Переплата по платежу:
                                            <span class="font-semibold text-blue-700 tabular-nums whitespace-nowrap">
                                                {{ $formatMoney($paymentRow['unallocated_amount']) }}
                                            </span>
                                            <span class="block text-[11px] text-gray-400">Сумма сверх стоимости счёта</span>
                                        </div>
                                    @endif
                                </div>

                                @if ($paymentRow['allocations'] !== [])
                                    <div class="mt-3">
                                        <div id="payment-allocation-{{ $paymentRow['id'] }}" x-show="allocationOpen" x-cloak
                                            class="mt-2 rounded-lg border border-gray-100 bg-gray-50 p-3">
                                            <div class="mb-2 text-xs font-semibold text-gray-700">Текущее распределение</div>

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
                                                                        {{ $allocation['line_type_label'] }}
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
                                <p class="mt-3 text-xs text-gray-500">Будет распределён после подтверждения.</p>
                            @endif

                            @if ($payment->comment)
                                <p class="text-xs text-gray-500 italic mt-2 bg-gray-50 rounded-lg p-2">
                                    {{ $payment->comment }}
                                </p>
                            @endif

                            {{-- Данные отменённого платежа --}}
                            @if ($payment->status === 'cancelled')
                                <div class="mt-2 space-y-1 border-t border-red-100 pt-2 text-xs text-red-600">
                                    @if ($payment->cancelled_at)
                                        <p>
                                            Отменён: {{ \Illuminate\Support\Carbon::parse($payment->cancelled_at)->format('d/m/Y H:i') }}
                                        </p>
                                    @endif

                                    @if ($payment->cancel_reason)
                                        <p class="whitespace-pre-line">
                                            Причина: {{ $payment->cancel_reason }}
                                        </p>
                                    @endif
                                </div>
                            @endif

                            {{-- Подтверждение ожидающего платежа --}}
                            @if ($payment->status === 'pending')
                                <div class="mt-3">
                                    <form action="{{ route('payments.confirm', $payment) }}" method="POST"
                                        onsubmit="return confirm('Подтвердить этот платёж? После подтверждения сумма оплаты, статус инвойса и Credit Balance будут пересчитаны.')">

                                        @csrf
                                        @method('PATCH')

                                        <button type="submit"
                                            class="inline-flex items-center rounded-lg
                       border border-green-200 bg-green-50
                       px-3 py-2 text-xs font-medium text-green-700
                       hover:bg-green-100 transition">

                                            <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">

                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M5 13l4 4L19 7" />
                                            </svg>

                                            Подтвердить платёж
                                        </button>
                                    </form>
                                </div>
                            @endif

                            {{-- Отмена обычного ожидающего или подтверждённого платежа --}}
                            @if (in_array($payment->status, ['pending', 'confirmed'], true) && !$isCreditBalancePayment)
                                <div class="mt-3">
                                    <button type="button" x-show="!cancelOpen"
                                        @click="cancelOpen = true; $nextTick(() => $refs.cancelReason.focus())"
                                        class="text-xs font-medium text-red-600 hover:text-red-800 transition">

                                        Отменить платёж
                                    </button>

                                    <form x-show="cancelOpen" x-cloak action="{{ route('payments.cancel', $payment) }}"
                                        method="POST"
                                        class="mt-3 space-y-3 rounded-lg border border-red-100 bg-red-50 p-3"
                                        x-on:submit="
                                            const reason = $event.currentTarget.elements.cancel_reason.value.trim();
                                            if (cancelSubmitting || reason === '' || !$event.currentTarget.checkValidity()) {
                                                $event.preventDefault();
                                                $event.currentTarget.reportValidity();
                                                return;
                                            }
                                            if (!confirm('Отменить этот платёж? Он останется в истории, а суммы инвойса и Credit Balance будут пересчитаны.')) {
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

                                                Причина отмены
                                                <span class="text-red-500">*</span>
                                            </label>

                                            <textarea id="cancel_reason_{{ $payment->id }}" name="cancel_reason" rows="3" required minlength="3"
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
                                                placeholder="Например: платёж зарегистрирован ошибочно">{{ $shouldOpenCancellation ? old('cancel_reason') : '' }}</textarea>

                                            @if ($shouldOpenCancellation)
                                                @error('cancel_reason')
                                                    <p class="mt-1 text-xs text-red-600">
                                                        {{ $message }}
                                                    </p>
                                                @enderror
                                            @endif
                                        </div>

                                        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                            <button type="button" @click="cancelOpen = false"
                                                class="px-3 py-2 text-xs font-medium text-gray-600
                                                       hover:text-gray-900 transition">

                                                Не отменять
                                            </button>

                                            <button type="submit" :disabled="cancelSubmitting"
                                                class="rounded-lg bg-red-600 px-3 py-2 text-xs
                                                       font-medium text-white hover:bg-red-700 transition">

                                                Подтвердить отмену
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-400 py-1">
                            Платежей по счёту пока нет.
                        </p>
                    @endforelse
                </div>
                            </div>
                        </aside>
                    </div>
                @endif
            </div>

        </div>

    </div>

@endsection
