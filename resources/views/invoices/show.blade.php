@extends('layouts.app')

@section('title', 'Инвойс ' . $invoice->invoice_number)

@section('content')

    {{-- Верхний заголовок и действия --}}
    <div class="mb-6 flex flex-col sm:flex-row justify-between sm:items-center gap-4 print:hidden">
        <div>
            <a href="{{ route('invoices.index') }}"
                class="text-sm text-gray-500 hover:text-gray-900 transition flex items-center gap-1.5 mb-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Назад к списку
            </a>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold text-gray-900">Инвойс {{ $invoice->invoice_number }}</h1>
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

        <div class="flex items-center gap-2">
            {{-- Кнопка Печать --}}
            <button onclick="window.print()"
                class="inline-flex items-center text-sm border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 px-4 py-2 rounded-lg font-medium transition shadow-sm">
                <svg class="w-4 h-4 mr-1.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                </svg>
                Печать
            </button>

            @if ($invoice->status === 'draft')
                <a href="{{ route('invoices.edit', $invoice) }}"
                    class="px-4 py-2 border border-gray-200 text-gray-600
               text-sm font-medium rounded-lg hover:bg-gray-50 transition">

                    Редактировать
                </a>

                <form action="{{ route('invoices.issue', $invoice) }}" method="POST"
                    onsubmit="return confirm('Выставить этот инвойс? После этого свободное редактирование будет недоступно.')">

                    @csrf

                    <button type="submit"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-700
                   text-white text-sm font-medium rounded-lg transition">

                        Выставить счёт
                    </button>
                </form>

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
            @if ($invoice->status === 'issued')
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

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Основной документ инвойса (2/3 ширины) --}}
        <div class="lg:col-span-2 space-y-6 print:w-full print:col-span-3">

            {{-- Печатный бланк счета --}}
            <div
                class="bg-white rounded-xl border border-gray-200 shadow-sm p-8 md:p-12 relative overflow-hidden print:border-none print:shadow-none print:p-0">

                {{-- Верхняя декоративная полоса (скрывается при печати) --}}
                <div class="absolute top-0 left-0 right-0 h-1.5 bg-blue-600 print:hidden"></div>

                {{-- Шапка бланка --}}
                <div class="flex flex-col md:flex-row justify-between gap-6 pb-8 border-b border-gray-100 mb-8">

                    {{-- Данные продавца (Мы) --}}
                    <div class="space-y-1">
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Поставщик услуг</div>
                        <h2 class="text-lg font-bold text-gray-900">{{ $invoice->seller_name ?? 'IT Solutions MMC' }}</h2>
                        <div class="text-sm text-gray-600 font-mono">VÖEN: {{ $invoice->seller_voen ?? '9900123456' }}
                        </div>
                        <div class="text-sm text-gray-600 mt-2">
                            <span class="font-medium text-gray-800">Банк:</span>
                            {{ $invoice->seller_bank_name ?? 'Pasha Bank OJSC' }}
                        </div>
                        <div class="text-sm text-gray-600 font-mono break-all">
                            <span class="font-medium text-gray-800">IBAN (H/h):</span>
                            {{ $invoice->seller_iban ?? 'AZ00PRCB0000000000000000000' }}
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-sm text-gray-600">
                            <div><span class="font-medium text-gray-800">SWIFT:</span> <span
                                    class="font-mono">{{ $invoice->seller_swift ?? 'PAHBAZ2D' }}</span></div>
                            <div><span class="font-medium text-gray-800">Kod:</span> <span
                                    class="font-mono">{{ $invoice->seller_bank_code ?? '505050' }}</span></div>
                        </div>
                        @if ($invoice->seller_bank_voen)
                            <div class="text-sm text-gray-600 font-mono">
                                <span class="font-medium text-gray-800">Bank VÖEN:</span> {{ $invoice->seller_bank_voen }}
                            </div>
                        @endif
                    </div>

                    {{-- Метаданные инвойса --}}
                    <div class="space-y-2 md:text-right md:self-start">
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Документ</div>
                        <h2 class="text-xl font-bold text-gray-900 font-mono">{{ $invoice->invoice_number }}</h2>

                        <div class="text-sm text-gray-600">
                            <span class="font-medium text-gray-800">Дата счета:</span> {{ $invoice->issue_date }}
                        </div>
                        <div class="text-sm text-gray-600">
                            <span class="font-medium text-gray-800">Срок оплаты:</span> {{ $invoice->due_date }}
                        </div>
                        @if ($invoice->period_start && $invoice->period_end)
                            <div class="text-sm text-gray-600">
                                <span class="font-medium text-gray-800">Расчетный период:</span>
                                <div class="text-xs text-gray-500 font-mono mt-0.5">{{ $invoice->period_start }} —
                                    {{ $invoice->period_end }}</div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Получатель счета (Плательщик) --}}
                <div
                    class="bg-gray-50 rounded-xl p-5 mb-8 grid grid-cols-1 md:grid-cols-2 gap-4 print:bg-gray-100 print:rounded-none">
                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Плательщик (Ödəyici)
                        </div>
                        <h3 class="font-bold text-gray-900">{{ $invoice->payer_name }}</h3>
                        <div class="text-sm text-gray-600 font-mono mt-0.5">VÖEN: {{ $invoice->payer_voen ?? '—' }}</div>
                        <div class="text-xs text-gray-400 mt-2">
                            Связано с аккаунтом: <a href="{{ route('companies.show', $invoice->company_id) }}"
                                class="text-blue-600 hover:underline print:text-gray-900 font-medium">{{ $invoice->company->name }}</a>
                        </div>
                    </div>
                    <div class="md:text-right md:self-center">
                        @if ($invoice->contract_reference)
                            <div class="text-sm text-gray-600">
                                <span class="font-medium text-gray-800">Основание (Договор):</span>
                                <div class="font-mono text-gray-900 font-semibold mt-0.5">
                                    {{ $invoice->contract_reference }}</div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Таблица позиций (Lines) --}}
                <div class="mb-8">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr
                                class="border-b border-gray-200 text-gray-400 font-semibold uppercase tracking-wider text-xs pb-3">
                                <th class="pb-3 w-10">#</th>
                                <th class="pb-3">Описание услуги / товара</th>
                                <th class="pb-3 text-right pr-4">Сумма (₼)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700">
                            @foreach ($invoice->lines as $index => $line)
                                <tr>
                                    <td class="py-4 font-medium text-gray-400">{{ $index + 1 }}</td>
                                    <td class="py-4">
                                        <div class="font-semibold text-gray-900">{{ $line->description }}</div>
                                    </td>
                                    <td class="py-4 text-right font-semibold text-gray-900 font-mono pr-4">
                                        {{ number_format($line->amount, 2) }} ₼
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Расчет итога --}}
                <div class="border-t border-gray-100 pt-6 flex flex-col items-end gap-2 text-sm text-gray-600">
                    <div class="flex justify-between w-64">
                        <span>Итого сумма счета:</span>

                        <span class="font-bold text-gray-900 font-mono">
                            {{ number_format($invoice->total_amount, 2) }} ₼
                        </span>
                    </div>

                    <div class="flex justify-between w-64 text-green-600">
                        <span>Оплачено:</span>

                        <span class="font-bold font-mono">
                            - {{ number_format($invoice->applied_amount, 2) }} ₼
                        </span>
                    </div>

                    @if ($invoice->overpayment_amount > 0)
                        <div class="flex justify-between w-64 text-blue-600">
                            <span>Переплата:</span>

                            <span class="font-bold font-mono">
                                {{ number_format($invoice->overpayment_amount, 2) }} ₼
                            </span>
                        </div>
                    @endif

                    <div
                        class="flex justify-between w-64 border-t border-gray-100 pt-2 text-base
        {{ $invoice->remaining_amount > 0 ? 'text-red-600' : 'text-gray-900' }}">

                        <span class="font-semibold">
                            К оплате (Остаток):
                        </span>

                        <span class="font-bold font-mono">
                            {{ number_format($invoice->remaining_amount, 2) }} ₼
                        </span>
                    </div>
                </div>

                {{-- Примечание продавца --}}
                @if ($invoice->comment)
                    <div class="mt-12 pt-6 border-t border-gray-100 text-sm">
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Примечания
                            поставщика</div>
                        <p class="text-gray-600 italic whitespace-pre-line">{{ $invoice->comment }}</p>
                    </div>
                @endif

            </div>

        </div>

        {{-- Правая боковая колонка: Регистрация оплат и история (скрывается при печати) --}}
        <div class="space-y-6 print:hidden">

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
                            <input type="date" name="payment_date" id="payment_date"
                                value="{{ old('payment_date', date('Y-m-d')) }}" required
                                class="w-full px-3 py-2 border @error('payment_date') border-red-300 @else border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                            @error('payment_date')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
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
                                {{ number_format($invoice->remaining_amount, 2) }} ₼</p>
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
                                    {{ old('status', 'confirmed') === 'confirmed' ? 'selected' : '' }}>Проведен /
                                    Подтвержден</option>
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
                            Провести платеж
                        </button>
                    </form>
                </div>
            @endif

            {{-- История платежей --}}
            <div class="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                <h3
                    class="font-bold text-gray-900 mb-4 pb-2 border-b border-gray-100 text-sm uppercase tracking-wider text-gray-500">
                    История платежей
                </h3>

                <div class="space-y-4">
                    @forelse ($invoice->payments as $payment)
                        @php
                            /*
                             * Платёж, автоматически созданный из Credit Balance,
                             * нельзя отменять как обычный банковский/наличный платёж.
                             * Сервер дополнительно проверяет это в PaymentController.
                             */
                            $isCreditBalancePayment = str_starts_with(
                                (string) $payment->comment,
                                'Автоматически применён Credit Balance',
                            );

                            /*
                             * После ошибки валидации повторно открываем форму
                             * именно того платежа, который пользователь отменял.
                             */
                            $shouldOpenCancellation =
                                $errors->has('cancel_reason') &&
                                (string) old('cancel_payment_id') === (string) $payment->id;
                        @endphp

                        <div x-data="{ cancelOpen: @js($shouldOpenCancellation) }"
                            class="border-b border-gray-100 last:border-0 pb-4 last:pb-0 text-sm">

                            <div class="flex items-center justify-between gap-3">
                                <span
                                    class="font-semibold font-mono
                                        {{ $payment->status === 'cancelled' ? 'text-gray-400 line-through' : 'text-gray-900' }}">

                                    {{ number_format($payment->amount, 2) }} ₼
                                </span>

                                @include('partials.badge', [
                                    'status' => $payment->status,
                                ])
                            </div>

                            <div class="flex justify-between gap-3 text-xs text-gray-400 mt-1">
                                <span>
                                    {{ $payment->payment_date }}
                                </span>

                                <span class="font-medium">
                                    @if ($payment->payment_method === 'transfer')
                                        Безналичный
                                    @elseif ($payment->payment_method === 'card')
                                        Карта
                                    @else
                                        Наличные
                                    @endif
                                </span>
                            </div>

                            @if ($isCreditBalancePayment)
                                <div
                                    class="mt-2 inline-flex items-center rounded-md bg-blue-50 px-2 py-1
                                           text-[11px] font-medium text-blue-700">

                                    Оплата из Credit Balance
                                </div>
                            @endif

                            @if ($payment->comment)
                                <p class="text-xs text-gray-500 italic mt-2 bg-gray-50 rounded-lg p-2">
                                    {{ $payment->comment }}
                                </p>
                            @endif

                            {{-- Данные отменённого платежа --}}
                            @if ($payment->status === 'cancelled')
                                <div class="mt-3 rounded-lg border border-red-100 bg-red-50 p-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-xs font-semibold text-red-700">
                                            Платёж отменён
                                        </span>

                                        @if ($payment->cancelled_at)
                                            <span class="text-[11px] text-red-500">
                                                {{ \Illuminate\Support\Carbon::parse($payment->cancelled_at)->format('Y-m-d H:i') }}
                                            </span>
                                        @endif
                                    </div>

                                    @if ($payment->cancel_reason)
                                        <p class="mt-1.5 text-xs text-red-700 whitespace-pre-line">
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

                            {{-- Отмена обычного подтверждённого платежа --}}
                            @if ($payment->status === 'confirmed' && !$isCreditBalancePayment)
                                <div class="mt-3">
                                    <button type="button" @click="cancelOpen = !cancelOpen"
                                        class="text-xs font-medium text-red-600 hover:text-red-800 transition">

                                        <span x-show="!cancelOpen">
                                            Отменить платёж
                                        </span>

                                        <span x-show="cancelOpen" x-cloak>
                                            Закрыть форму отмены
                                        </span>
                                    </button>

                                    <form x-show="cancelOpen" x-cloak action="{{ route('payments.cancel', $payment) }}"
                                        method="POST"
                                        class="mt-3 space-y-3 rounded-lg border border-red-100 bg-red-50 p-3"
                                        onsubmit="return confirm('Отменить этот платёж? Он останется в истории, а суммы инвойса и Credit Balance будут пересчитаны.')">

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

                                        <div class="flex justify-end gap-2">
                                            <button type="button" @click="cancelOpen = false"
                                                class="px-3 py-2 text-xs font-medium text-gray-600
                                                       hover:text-gray-900 transition">

                                                Не отменять
                                            </button>

                                            <button type="submit"
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
                        <p class="text-sm text-gray-400 text-center py-4">
                            Платежей по счету пока не зарегистрировано.
                        </p>
                    @endforelse
                </div>
            </div>

        </div>

    </div>

@endsection
