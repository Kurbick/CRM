@extends('layouts.app')

@section('title', 'Редактировать счет')

@section('content')

    <div class="mb-6">
        <a href="{{ route('invoices.show', $invoice) }}"
            class="text-sm text-gray-500 hover:text-gray-900 transition flex items-center gap-1.5 mb-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Назад к просмотру
        </a>
        <h1 class="text-2xl font-bold text-gray-900">Редактировать счет</h1>
        <p class="text-sm text-gray-500 mt-1">Редактирование параметров и позиций счета</p>
    </div>

    <form action="{{ route('invoices.update', $invoice) }}" method="POST" x-data="{
        lines: @js(
    old(
        'lines',
        $invoice->lines
            ->map(
                fn($line) => [
                    'id' => $line->id,
                    'description' => $line->description,
                    'amount' => $line->amount,
                    'subscription_id' => $line->subscription_id,
                    'order_id' => $line->order_id,
                    'period_start' => $line->period_start?->toDateString(),
                    'period_end' => $line->period_end?->toDateString(),
                ],
            )
            ->values()
            ->all(),
    ),
),
    
        addLine() {
            this.lines.push({
                id: null,
                description: '',
                amount: '',
                subscription_id: null,
                order_id: null,
                period_start: null,
                period_end: null,
            });
        },
    
        removeLine(index) {
            if (this.lines.length <= 1) {
                return;
            }
    
            this.lines.splice(index, 1);
        },
    
        total() {
            return this.lines.reduce((sum, line) => {
                const amount = parseFloat(line.amount);
    
                return sum + (Number.isFinite(amount) ? amount : 0);
            }, 0);
        }
    }">

        @csrf
        @method('PUT')

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            {{-- Основная информация и строки инвойса --}}
            <div class="lg:col-span-2 space-y-6">

                {{-- Карточка: Реквизиты документа --}}
                <div class="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-100">Параметры инвойса
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                                Компания
                            </label>

                            {{-- Компания отображается, но менять её нельзя --}}
                            <select
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm
               bg-gray-100 text-gray-600 cursor-not-allowed"
                                disabled>

                                @foreach ($companies as $company)
                                    <option value="{{ $company->id }}"
                                        {{ $invoice->company_id == $company->id ? 'selected' : '' }}>
                                        {{ $company->name }}
                                    </option>
                                @endforeach
                            </select>

                            {{-- Disabled-поля не отправляются, поэтому передаём ID скрыто --}}
                            <input type="hidden" name="company_id" value="{{ $invoice->company_id }}">

                            @error('company_id')
                                <p class="text-xs text-red-500 mt-1">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <div>
                            <label for="invoice_number"
                                class="block text-xs font-semibold text-gray-500 uppercase mb-1">Номер счета <span
                                    class="text-red-500">*</span></label>
                            <input type="text" name="invoice_number" id="invoice_number"
                                value="{{ old('invoice_number', $invoice->invoice_number) }}" required
                                class="w-full px-3 py-2 border @error('invoice_number') border-red-300 @else border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition font-mono"
                                placeholder="INV-2026-001">
                            @error('invoice_number')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="issue_date" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Дата
                                выставления <span class="text-red-500">*</span></label>
                            <input type="date" name="issue_date" id="issue_date"
                                value="{{ old('issue_date', $invoice->issue_date) }}" required
                                class="w-full px-3 py-2 border @error('issue_date') border-red-300 @else border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                            @error('issue_date')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="due_date" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Оплатить
                                до (Due Date) <span class="text-red-500">*</span></label>
                            <input type="date" name="due_date" id="due_date"
                                value="{{ old('due_date', $invoice->due_date) }}" required
                                class="w-full px-3 py-2 border @error('due_date') border-red-300 @else border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                            @error('due_date')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="period_start"
                                class="block text-xs font-semibold text-gray-500 uppercase mb-1">Период (начало)</label>
                            <input type="date" name="period_start" id="period_start"
                                value="{{ old('period_start', $invoice->period_start) }}"
                                class="w-full px-3 py-2 border @error('period_start') border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                            @error('period_start')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="period_end" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Период
                                (конец)</label>
                            <input type="date" name="period_end" id="period_end"
                                value="{{ old('period_end', $invoice->period_end) }}"
                                class="w-full px-3 py-2 border @error('period_end') border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                            @error('period_end')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                    </div>
                </div>

                {{-- Карточка: Строки счета (Invoice Lines) --}}
                {{-- Карточка: Строки счёта --}}
                <div class="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">

                    <div class="flex items-center justify-between gap-3 mb-4 pb-2 border-b border-gray-100">
                        <div>
                            <h2 class="text-base font-semibold text-gray-900">
                                Позиции счёта
                            </h2>

                            <p class="text-xs text-gray-500 mt-1">
                                Измените существующие позиции или добавьте ручную строку.
                            </p>
                        </div>

                        <button type="button" x-on:click="addLine()"
                            class="text-sm font-medium text-blue-600 hover:text-blue-800 transition">

                            + Добавить позицию
                        </button>
                    </div>

                    <div class="space-y-3">
                        <template x-for="(line, index) in lines" :key="line.id ?? `new-${index}`">

                            <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">

                                <input type="hidden" :name="'lines[' + index + '][id]'" :value="line.id ?? ''">
                                <input type="hidden" :name="'lines[' + index + '][subscription_id]'"
                                    :value="line.subscription_id ?? ''">
                                <input type="hidden" :name="'lines[' + index + '][order_id]'"
                                    :value="line.order_id ?? ''">
                                <input type="hidden" :name="'lines[' + index + '][period_start]'"
                                    :value="line.period_start ?? ''">
                                <input type="hidden" :name="'lines[' + index + '][period_end]'"
                                    :value="line.period_end ?? ''">

                                <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">

                                    <div class="md:col-span-8">
                                        <label class="block text-xs font-medium text-gray-500 mb-1">
                                            Описание услуги / товара
                                        </label>

                                        <input type="text" :name="'lines[' + index + '][description]'"
                                            x-model="line.description" placeholder="Описание"
                                            class="w-full px-3 py-2 border border-gray-200 rounded-lg
                                   text-sm focus:border-blue-500 outline-none transition"
                                            required>
                                    </div>

                                    <div class="md:col-span-3">
                                        <label class="block text-xs font-medium text-gray-500 mb-1">
                                            Сумма (₼)
                                        </label>

                                        <input type="number" :name="'lines[' + index + '][amount]'" x-model="line.amount"
                                            min="0.01" step="0.01" placeholder="0.00"
                                            class="w-full px-3 py-2 border border-gray-200 rounded-lg
                                   text-sm font-mono focus:border-blue-500
                                   outline-none transition"
                                            required>
                                    </div>

                                    <div class="md:col-span-1">
                                        <button type="button" x-on:click="removeLine(index)" :disabled="lines.length <= 1"
                                            class="w-full px-3 py-2 rounded-lg border border-red-200
                                   text-red-500 hover:bg-red-50 transition
                                   disabled:opacity-40 disabled:cursor-not-allowed">

                                            ✕
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    @error('lines')
                        <p class="text-sm text-red-600 mt-3">
                            {{ $message }}
                        </p>
                    @enderror

                    <div class="mt-5 pt-4 border-t border-gray-100 flex justify-end">
                        <div class="text-right">
                            <p class="text-xs font-semibold uppercase text-gray-500">
                                Итоговая сумма
                            </p>

                            <p class="text-xl font-semibold text-gray-900 mt-1">
                                <span x-text="total().toFixed(2)"></span> ₼
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Карточка: Реквизиты сторон (Наша компания и Клиент) --}}
                <div class="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-100">Реквизиты сторон
                        (для печатного инвойса)</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                        {{-- Продавец (Мы) --}}
                        <div class="space-y-3">
                            <h3 class="font-semibold text-sm text-gray-700">Продавец (Мы)</h3>

                            <div>
                                <label for="seller_name"
                                    class="block text-[10px] font-semibold text-gray-500 uppercase mb-0.5">Наименование
                                    нашей компании</label>
                                <input type="text" name="seller_name" id="seller_name"
                                    value="{{ old('seller_name', $invoice->seller_name) }}"
                                    class="w-full px-3 py-1.5 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                            </div>

                            <div>
                                <label for="seller_voen"
                                    class="block text-[10px] font-semibold text-gray-500 uppercase mb-0.5">Наш VÖEN</label>
                                <input type="text" name="seller_voen" id="seller_voen"
                                    value="{{ old('seller_voen', $invoice->seller_voen) }}"
                                    class="w-full px-3 py-1.5 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition font-mono">
                            </div>

                            <div>
                                <label for="seller_bank_name"
                                    class="block text-[10px] font-semibold text-gray-500 uppercase mb-0.5">Наш банк</label>
                                <input type="text" name="seller_bank_name" id="seller_bank_name"
                                    value="{{ old('seller_bank_name', $invoice->seller_bank_name) }}"
                                    class="w-full px-3 py-1.5 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                            </div>

                            <div>
                                <label for="seller_iban"
                                    class="block text-[10px] font-semibold text-gray-500 uppercase mb-0.5">Наш IBAN</label>
                                <input type="text" name="seller_iban" id="seller_iban"
                                    value="{{ old('seller_iban', $invoice->seller_iban) }}"
                                    class="w-full px-3 py-1.5 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition font-mono">
                            </div>

                            <div class="grid grid-cols-3 gap-2">
                                <div class="col-span-2">
                                    <label for="seller_bank_voen"
                                        class="block text-[10px] font-semibold text-gray-500 uppercase mb-0.5">VÖEN
                                        Банка</label>
                                    <input type="text" name="seller_bank_voen" id="seller_bank_voen"
                                        value="{{ old('seller_bank_voen', $invoice->seller_bank_voen) }}"
                                        class="w-full px-3 py-1.5 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition font-mono">
                                </div>
                                <div>
                                    <label for="seller_bank_code"
                                        class="block text-[10px] font-semibold text-gray-500 uppercase mb-0.5">Kod</label>
                                    <input type="text" name="seller_bank_code" id="seller_bank_code"
                                        value="{{ old('seller_bank_code', $invoice->seller_bank_code) }}"
                                        class="w-full px-3 py-1.5 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition font-mono">
                                </div>
                            </div>

                            <div>
                                <label for="seller_swift"
                                    class="block text-[10px] font-semibold text-gray-500 uppercase mb-0.5">SWIFT</label>
                                <input type="text" name="seller_swift" id="seller_swift"
                                    value="{{ old('seller_swift', $invoice->seller_swift) }}"
                                    class="w-full px-3 py-1.5 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition font-mono">
                            </div>
                        </div>

                        {{-- Покупатель (Клиент) --}}
                        <div class="space-y-3">
                            <h3 class="font-semibold text-sm text-gray-700">Покупатель (Клиент)</h3>

                            <div>
                                <label for="payer_name"
                                    class="block text-[10px] font-semibold text-gray-500 uppercase mb-0.5">Наименование
                                    клиента <span class="text-red-500">*</span></label>
                                <input type="text" name="payer_name" id="payer_name" required x-model="payerName"
                                    class="w-full px-3 py-1.5 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                                @error('payer_name')
                                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="payer_voen"
                                    class="block text-[10px] font-semibold text-gray-500 uppercase mb-0.5">VÖEN
                                    клиента</label>
                                <input type="text" name="payer_voen" id="payer_voen" x-model="payerVoen"
                                    class="w-full px-3 py-1.5 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition font-mono">
                                @error('payer_voen')
                                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="contract_reference"
                                    class="block text-[10px] font-semibold text-gray-500 uppercase mb-0.5">Договор
                                    (основание) №</label>
                                <input type="text" name="contract_reference" id="contract_reference"
                                    value="{{ old('contract_reference', $invoice->contract_reference) }}"
                                    class="w-full px-3 py-1.5 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"
                                    placeholder="например, CTR-2026-001">
                            </div>
                        </div>

                    </div>
                </div>

            </div>

            {{-- Боковая колонка: Статус и Примечания --}}
            <div class="space-y-6">

                {{-- Карточка: Статус инвойса --}}
                <div class="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">

                    <h2 class="text-base font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-100">
                        Статус счёта
                    </h2>

                    {{-- При обычном редактировании инвойс остаётся черновиком --}}
                    <input type="hidden" name="status" value="draft">

                    <div class="rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3">

                        <p class="text-sm font-semibold text-yellow-800">
                            Черновик
                        </p>

                        <p class="text-xs text-yellow-700 mt-1">
                            Сейчас можно изменить данные и позиции черновика.
                            Выставление счёта будет выполняться отдельной кнопкой
                            после сохранения изменений.
                        </p>
                    </div>

                    @error('status')
                        <p class="text-xs text-red-500 mt-2">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                {{-- Карточка: Примечания --}}
                <div class="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-100">Примечания</h2>

                    <div>
                        <label for="comment"
                            class="block text-xs font-semibold text-gray-500 uppercase mb-1">Комментарий</label>
                        <textarea name="comment" id="comment" rows="6"
                            class="w-full px-3 py-2 border @error('comment') border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition resize-none"
                            placeholder="Дополнительные комментарии или условия оплаты...">{{ old('comment', $invoice->comment) }}</textarea>
                        @error('comment')
                            <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                {{-- Кнопки действий --}}
                <div class="flex flex-col gap-2">
                    <button type="submit"
                        class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg text-sm transition shadow-sm">
                        Сохранить изменения
                    </button>
                    <a href="{{ route('invoices.show', $invoice) }}"
                        class="w-full py-2.5 border border-gray-200 hover:bg-gray-50 text-gray-700 font-medium rounded-lg text-sm transition text-center">
                        Отмена
                    </a>
                </div>

            </div>

        </div>
    </form>

@endsection
