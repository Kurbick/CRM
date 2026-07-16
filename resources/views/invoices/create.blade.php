@extends('layouts.app')
@section('title', 'Выставить счёт')
@section('content')

    <div class="mb-6">
        <a href="{{ route('invoices.index') }}" class="text-sm text-gray-500 hover:text-gray-700">
            ← Назад к инвойсам
        </a>

        <h1 class="text-2xl font-bold text-gray-900 mt-2">
            Выставить счёт
        </h1>
    </div>

    <form id="invoice-form" action="{{ route('invoices.store') }}" method="POST">

        @csrf

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            {{-- Левая колонка --}}
            <div class="lg:col-span-2 space-y-4">

                {{-- Клиент и договор --}}
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">

                    <h2 class="font-semibold text-gray-800 mb-4">
                        Клиент и договор
                    </h2>

                    {{-- Компания --}}
                    <div class="mb-4">
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                            Компания
                            <span class="text-red-500">*</span>
                        </label>

                        <select name="company_id" id="company_id"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm
                               focus:border-blue-500 outline-none transition"
                            required>

                            <option value="">
                                — Выберите компанию —
                            </option>

                            @foreach ($companies as $company)
                                <option value="{{ $company->id }}"
                                    {{ old('company_id') == $company->id ? 'selected' : '' }}>
                                    {{ $company->name }}
                                </option>
                            @endforeach
                        </select>

                        @error('company_id')
                            <p class="text-xs text-red-500 mt-1">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    {{-- Договор --}}
                    <div class="mb-4">
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                            Договор
                            <span class="text-red-500">*</span>
                        </label>

                        <select name="contract_id" id="contract_id"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm
                               focus:border-blue-500 outline-none transition"
                            required disabled>

                            <option value="">
                                — Сначала выберите компанию —
                            </option>
                        </select>

                        @error('contract_id')
                            <p class="text-xs text-red-500 mt-1">
                                {{ $message }}
                            </p>
                        @enderror

                        <div id="contract-info"
                            class="hidden mt-3 rounded-lg border border-blue-100
                               bg-blue-50 px-4 py-3">

                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-blue-500">
                                        Выбранный договор
                                    </p>

                                    <p id="contract-info-number" class="mt-1 text-sm font-semibold text-gray-900">
                                    </p>
                                </div>

                                <div class="text-sm text-gray-600">
                                    <span id="contract-info-start"></span>
                                    <span class="mx-1">—</span>
                                    <span id="contract-info-end"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Номер и дата выставления --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                                Номер счёта
                                <span class="text-red-500">*</span>
                            </label>

                            <input type="text" name="invoice_number"
                                value="{{ old('invoice_number', 'INV-' . strtoupper(substr(uniqid(), -6))) }}"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm
                                   font-mono focus:border-blue-500 outline-none transition"
                                required>

                            @error('invoice_number')
                                <p class="text-xs text-red-500 mt-1">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                                Дата выставления
                                <span class="text-red-500">*</span>
                            </label>

                            <input type="date" name="issue_date" id="issue_date"
                                value="{{ old('issue_date', now()->toDateString()) }}"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm
                                   focus:border-blue-500 outline-none transition"
                                required>

                            @error('issue_date')
                                <p class="text-xs text-red-500 mt-1">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>
                    </div>

                    {{-- Срок оплаты и статус --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                                Срок оплаты
                                <span class="text-red-500">*</span>
                            </label>

                            <input type="date" name="due_date" id="due_date" value="{{ old('due_date') }}"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm
                                   focus:border-blue-500 outline-none transition"
                                required>

                            @error('due_date')
                                <p class="text-xs text-red-500 mt-1">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                                Статус
                            </label>

                            <select name="status"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm
                                   focus:border-blue-500 outline-none transition">

                                <option value="draft" {{ old('status') === 'draft' ? 'selected' : '' }}>
                                    Черновик
                                </option>

                                <option value="issued" {{ old('status', 'issued') === 'issued' ? 'selected' : '' }}>
                                    Выставлен
                                </option>
                            </select>
                        </div>
                    </div>

                    {{-- Общий период — временно оставляем --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                                Период с
                            </label>

                            <input type="date" name="period_start" value="{{ old('period_start') }}"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm
                                   focus:border-blue-500 outline-none transition">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                                Период по
                            </label>

                            <input type="date" name="period_end" value="{{ old('period_end') }}"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm
                                   focus:border-blue-500 outline-none transition">
                        </div>
                    </div>

                    {{-- Реквизиты --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                                Плательщик
                            </label>

                            <input type="text" name="payer_name" id="payer_name" value="{{ old('payer_name') }}"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm
                                   focus:border-blue-500 outline-none transition">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                                VÖEN плательщика
                            </label>

                            <input type="text" name="payer_voen" id="payer_voen" value="{{ old('payer_voen') }}"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm
                                   font-mono focus:border-blue-500 outline-none transition">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                            Ссылка на договор (Müqavilə №)
                        </label>

                        <input type="text" name="contract_reference" id="contract_reference"
                            value="{{ old('contract_reference') }}"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm
                               font-mono focus:border-blue-500 outline-none transition">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                            Комментарий
                        </label>

                        <textarea name="comment" rows="2"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm
                               focus:border-blue-500 outline-none transition">{{ old('comment') }}</textarea>
                    </div>
                </div>

                {{-- Позиции счёта --}}
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">

                    <div class="flex items-center justify-between gap-3 mb-4">
                        <div>
                            <h2 class="font-semibold text-gray-800">
                                Позиции счёта
                            </h2>

                            <p class="text-xs text-gray-500 mt-1">
                                Выберите предметы договора или добавьте ручную позицию.
                            </p>
                        </div>
                    </div>

                    {{-- Позиции выбранного договора --}}
                    <div id="contract-items" class="hidden mb-5">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">
                            Доступные позиции договора
                        </p>

                        <div id="items-list" class="space-y-2"></div>
                    </div>

                    {{-- Пустое состояние --}}
                    <div id="lines-empty"
                        class="rounded-lg border border-dashed border-gray-200
                           px-4 py-5 text-center">

                        <p class="text-sm font-medium text-gray-700">
                            Позиции ещё не выбраны
                        </p>

                        <p class="text-xs text-gray-500 mt-1">
                            Выберите услугу из договора или добавьте строку вручную.
                        </p>
                    </div>

                    {{-- Строки счёта --}}
                    <div id="lines-container" class="space-y-3"></div>

                    <p id="lines-error" class="hidden text-sm text-red-600 mt-3">
                        Добавьте хотя бы одну заполненную позицию счёта.
                    </p>

                    @error('lines')
                        <p class="text-sm text-red-600 mt-3">
                            {{ $message }}
                        </p>
                    @enderror

                    <button type="button" id="add-manual-line"
                        class="mt-4 text-sm text-blue-600 hover:text-blue-800
                           font-medium transition">

                        + Добавить строку
                    </button>
                </div>

                {{-- Кнопки --}}
                <div class="flex gap-3">
                    <button type="submit"
                        class="bg-blue-600 hover:bg-blue-700 text-white text-sm
                           font-medium px-6 py-2.5 rounded-lg transition">

                        Выставить счёт
                    </button>

                    <a href="{{ route('invoices.index') }}"
                        class="px-6 py-2.5 border border-gray-200 text-gray-600
                           text-sm font-medium rounded-lg hover:bg-gray-50 transition">

                        Отмена
                    </a>
                </div>
            </div>

            {{-- Правая колонка — итог --}}
            <div class="space-y-4">
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 sticky top-4">

                    <h2 class="font-semibold text-gray-800 mb-4">
                        Итог
                    </h2>

                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between text-gray-500">
                            <span>Позиций:</span>
                            <span id="lines-count">0</span>
                        </div>

                        <div
                            class="border-t border-gray-100 pt-2 flex justify-between
                                font-semibold text-gray-900 text-lg">

                            <span>Итого:</span>
                            <span id="total-amount">0.00 ₼</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </form>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            let lineIndex = 0;

            const companySelect = document.getElementById('company_id');
            const contractSelect = document.getElementById('contract_id');

            const contractItems = document.getElementById('contract-items');
            const itemsList = document.getElementById('items-list');

            const linesContainer = document.getElementById('lines-container');
            const linesEmpty = document.getElementById('lines-empty');
            const linesError = document.getElementById('lines-error');

            const linesCount = document.getElementById('lines-count');
            const totalAmount = document.getElementById('total-amount');

            const payerName = document.getElementById('payer_name');
            const payerVoen = document.getElementById('payer_voen');
            const contractReference = document.getElementById('contract_reference');

            const contractInfo = document.getElementById('contract-info');
            const contractInfoNumber = document.getElementById('contract-info-number');
            const contractInfoStart = document.getElementById('contract-info-start');
            const contractInfoEnd = document.getElementById('contract-info-end');

            const invoiceForm = document.getElementById('invoice-form');

            const oldContractId = @json(old('contract_id'));
            const oldLines = Object.values(@json(old('lines', [])) || {});

            const billingPeriodLabels = {
                monthly: 'Ежемесячно',
                quarterly: 'Ежеквартально',
                semiannual: 'Раз в полгода',
                annual: 'Раз в год',
                custom: 'Свой период',
            };

            function formatDate(value) {
                if (!value) {
                    return 'Бессрочный';
                }

                const parts = value.split('-');

                if (parts.length !== 3) {
                    return value;
                }

                return `${parts[2]}.${parts[1]}.${parts[0]}`;
            }

            function getSourceKey(type, id) {
                if (!type || !id) {
                    return '';
                }

                return `${type}:${id}`;
            }

            function parseLocalDate(value) {
                if (!value) {
                    return null;
                }

                const normalizedValue = String(value).slice(0, 10);
                const parts = normalizedValue.split('-');

                if (parts.length !== 3) {
                    return null;
                }

                return new Date(
                    Number(parts[0]),
                    Number(parts[1]) - 1,
                    Number(parts[2])
                );
            }

            function formatDateForInput(date) {
                if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
                    return '';
                }

                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');

                return `${year}-${month}-${day}`;
            }

            function addMonthsClamped(date, months) {
                const originalDay = date.getDate();

                const result = new Date(
                    date.getFullYear(),
                    date.getMonth(),
                    1
                );

                result.setMonth(result.getMonth() + months);

                const lastDayOfTargetMonth = new Date(
                    result.getFullYear(),
                    result.getMonth() + 1,
                    0
                ).getDate();

                result.setDate(
                    Math.min(originalDay, lastDayOfTargetMonth)
                );

                return result;
            }

            function calculateSubscriptionPeriod(
                billingPeriod,
                nextBillingDate,
                subscriptionStartDate
            ) {
                const monthsByPeriod = {
                    monthly: 1,
                    quarterly: 3,
                    semiannual: 6,
                    annual: 12,
                };

                const startValue =
                    nextBillingDate || subscriptionStartDate || '';

                if (!startValue) {
                    return {
                        start: '',
                        end: '',
                    };
                }

                if (billingPeriod === 'custom') {
                    return {
                        start: startValue,
                        end: '',
                    };
                }

                const months = monthsByPeriod[billingPeriod];

                if (!months) {
                    return {
                        start: startValue,
                        end: '',
                    };
                }

                const startDate = parseLocalDate(startValue);

                if (!startDate) {
                    return {
                        start: '',
                        end: '',
                    };
                }

                const nextPeriodStart = addMonthsClamped(
                    startDate,
                    months
                );

                const endDate = new Date(nextPeriodStart);
                endDate.setDate(endDate.getDate() - 1);

                return {
                    start: formatDateForInput(startDate),
                    end: formatDateForInput(endDate),
                };
            }

            function hasLineWithSourceKey(sourceKey) {
                return Array.from(
                    linesContainer.querySelectorAll('.line-item')
                ).some(function(line) {
                    return line.dataset.sourceKey === sourceKey;
                });
            }

            function createLine({
                description = '',
                amount = '',
                type = '',
                id = '',
                sourceKey = '',
                billingPeriod = '',
                paymentTerms = '',
                nextBillingDate = '',
                subscriptionStartDate = '',
                periodStart = '',
                periodEnd = '',
            } = {}) {
                if (sourceKey && hasLineWithSourceKey(sourceKey)) {
                    return;
                }

                const row = document.createElement('div');

                row.className =
                    'line-item rounded-lg border border-gray-200 bg-gray-50 p-3';

                row.dataset.sourceKey = sourceKey;
                row.dataset.paymentTerms = paymentTerms || '';
                row.dataset.billingPeriod = billingPeriod || '';
                row.dataset.nextBillingDate = nextBillingDate || '';
                row.dataset.subscriptionStartDate =
                    subscriptionStartDate || '';

                let hiddenSourceField = '';

                if (type === 'subscription') {
                    hiddenSourceField = `
            <input type="hidden"
                name="lines[${lineIndex}][subscription_id]"
                value="${id}">
        `;
                }

                if (type === 'order') {
                    hiddenSourceField = `
            <input type="hidden"
                name="lines[${lineIndex}][order_id]"
                value="${id}">
        `;
                }

                let positionLabel = 'Ручная позиция';

                if (type === 'order') {
                    positionLabel = 'Разовая услуга';
                }

                if (type === 'subscription') {
                    const periodLabel =
                        billingPeriodLabels[billingPeriod] || 'Подписка';

                    positionLabel = `Подписка · ${periodLabel}`;
                }

                const calculatedPeriod =
                    calculateSubscriptionPeriod(
                        billingPeriod,
                        nextBillingDate,
                        subscriptionStartDate
                    );

                const resolvedPeriodStart =
                    periodStart || calculatedPeriod.start;

                const resolvedPeriodEnd =
                    periodEnd || calculatedPeriod.end;

                const isSubscription = type === 'subscription';
                const isCustomPeriod = billingPeriod === 'custom';

                const periodCalculatedAutomatically =
                    isSubscription &&
                    !isCustomPeriod &&
                    resolvedPeriodStart &&
                    resolvedPeriodEnd;

                let periodFields = '';

                if (isSubscription) {
                    let periodHint =
                        'Укажите расчётный период вручную.';

                    if (isCustomPeriod) {
                        periodHint =
                            'Для собственного графика период указывается вручную.';
                    } else if (periodCalculatedAutomatically) {
                        periodHint =
                            'Период рассчитан автоматически по графику подписки.';
                    } else {
                        periodHint =
                            'Период не удалось определить автоматически — укажите даты вручную.';
                    }

                    const readonlyAttribute =
                        periodCalculatedAutomatically ?
                        'readonly' :
                        '';

                    const readonlyClass =
                        periodCalculatedAutomatically ?
                        'bg-gray-100 text-gray-600' :
                        'bg-white';

                    periodFields = `
            <div class="mt-3 pt-3 border-t border-gray-200">
                <div class="flex items-center justify-between gap-3 mb-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Расчётный период
                    </p>

                    <p class="text-[11px] text-gray-400">
                        ${periodHint}
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">
                            Период с
                        </label>

                        <input type="date"
                            name="lines[${lineIndex}][period_start]"
                            value="${resolvedPeriodStart}"
                            class="line-period-start w-full px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none transition ${readonlyClass}"
                            ${readonlyAttribute}
                            required>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">
                            Период по
                        </label>

                        <input type="date"
                            name="lines[${lineIndex}][period_end]"
                            value="${resolvedPeriodEnd}"
                            min="${resolvedPeriodStart}"
                            class="line-period-end w-full px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none transition ${readonlyClass}"
                            ${readonlyAttribute}
                            required>
                    </div>
                </div>
            </div>
        `;
                }

                row.innerHTML = `
        ${hiddenSourceField}

        <div class="flex items-center justify-between gap-3 mb-2">
            <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                ${positionLabel}
            </span>

            <button type="button"
                class="remove-line-button text-red-400 hover:text-red-600 text-sm transition">
                ✕
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
            <div class="md:col-span-8">
                <label class="block text-xs font-medium text-gray-500 mb-1">
                    Описание
                </label>

                <input type="text"
                    name="lines[${lineIndex}][description]"
                    value=""
                    placeholder="Описание услуги"
                    class="line-description w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                    required>
            </div>

            <div class="md:col-span-4">
                <label class="block text-xs font-medium text-gray-500 mb-1">
                    Сумма (₼)
                </label>

                <input type="number"
                    name="lines[${lineIndex}][amount]"
                    value=""
                    placeholder="0.00"
                    step="0.01"
                    min="0.01"
                    class="line-amount w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:border-blue-500 outline-none transition"
                    required>
            </div>
        </div>

        ${periodFields}
    `;

                const descriptionInput =
                    row.querySelector('.line-description');

                const amountInput =
                    row.querySelector('.line-amount');

                descriptionInput.value = description || '';

                amountInput.value =
                    amount !== '' && amount !== null ?
                    Number(amount).toFixed(2) :
                    '';

                const periodStartInput =
                    row.querySelector('.line-period-start');

                const periodEndInput =
                    row.querySelector('.line-period-end');

                if (periodStartInput && periodEndInput) {
                    periodStartInput.addEventListener(
                        'change',
                        function() {
                            periodEndInput.min = this.value;

                            if (
                                periodEndInput.value &&
                                periodEndInput.value < this.value
                            ) {
                                periodEndInput.value = '';
                            }
                        }
                    );
                }

                linesContainer.appendChild(row);

                lineIndex++;
                recalculate();

                if (!description) {
                    descriptionInput.focus();
                }
            }

            function removeLineBySourceKey(sourceKey) {
                linesContainer
                    .querySelectorAll('.line-item')
                    .forEach(function(line) {
                        if (line.dataset.sourceKey === sourceKey) {
                            line.remove();
                        }
                    });

                recalculate();
            }

            function clearLines() {
                linesContainer.innerHTML = '';
                linesError.classList.add('hidden');

                recalculate();
            }

            function clearContractInformation() {
                contractInfo.classList.add('hidden');

                contractInfoNumber.textContent = '';
                contractInfoStart.textContent = '';
                contractInfoEnd.textContent = '';

                if (payerName) {
                    payerName.value = '';
                }

                if (payerVoen) {
                    payerVoen.value = '';
                }

                if (contractReference) {
                    contractReference.value = '';
                }
            }

            function showContractInformation(contract) {
                contractInfoNumber.textContent =
                    contract.contract_number || '';

                contractInfoStart.textContent =
                    formatDate(contract.start_date);

                contractInfoEnd.textContent =
                    formatDate(contract.end_date);

                contractInfo.classList.remove('hidden');

                if (payerName) {
                    payerName.value = contract.company?.name || '';
                }

                if (payerVoen) {
                    payerVoen.value = contract.company?.voen || '';
                }

                if (contractReference) {
                    contractReference.value =
                        contract.contract_number || '';
                }
            }

            function renderContractItems(data) {
                itemsList.innerHTML = '';

                const allItems = [
                    ...data.orders,
                    ...data.subscriptions,
                ];

                if (allItems.length === 0) {
                    itemsList.innerHTML = `
                    <p class="text-sm text-gray-400">
                        В этом договоре нет доступных позиций.
                    </p>
                `;

                    contractItems.classList.remove('hidden');

                    return;
                }

                allItems.forEach(function(item) {
                    const sourceKey =
                        getSourceKey(item.type, item.id);

                    const label = document.createElement('label');

                    label.className =
                        'flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 cursor-pointer border border-gray-200 transition';

                    const checkbox = document.createElement('input');

                    checkbox.type = 'checkbox';
                    checkbox.className =
                        'item-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500';

                    checkbox.dataset.sourceKey = sourceKey;
                    checkbox.dataset.description =
                        item.description || '';

                    checkbox.dataset.amount =
                        item.amount || 0;

                    checkbox.dataset.type =
                        item.type || '';

                    checkbox.dataset.id =
                        item.id || '';

                    checkbox.dataset.paymentTerms =
                        item.payment_terms ?? item.terms ?? '';

                    checkbox.dataset.billingPeriod =
                        item.billing_period || '';

                    checkbox.dataset.nextBillingDate =
                        item.next_billing_date || '';

                    checkbox.dataset.subscriptionStartDate =
                        item.start_date || '';

                    checkbox.checked =
                        hasLineWithSourceKey(sourceKey);

                    const textWrapper =
                        document.createElement('div');

                    textWrapper.className = 'flex-1 min-w-0';

                    const title =
                        document.createElement('p');

                    title.className =
                        'text-sm font-medium text-gray-800';

                    title.textContent =
                        item.description || 'Без названия';

                    textWrapper.appendChild(title);

                    if (item.type === 'subscription') {
                        const period =
                            document.createElement('p');

                        period.className =
                            'text-xs text-gray-500 mt-0.5';

                        period.textContent =
                            billingPeriodLabels[item.billing_period] ||
                            'Подписка';

                        textWrapper.appendChild(period);
                    }

                    const amount =
                        document.createElement('span');

                    amount.className =
                        'text-sm font-mono font-semibold text-gray-900 whitespace-nowrap';

                    amount.textContent =
                        `${Number(item.amount || 0).toFixed(2)} ₼`;

                    label.appendChild(checkbox);
                    label.appendChild(textWrapper);
                    label.appendChild(amount);

                    itemsList.appendChild(label);
                });

                contractItems.classList.remove('hidden');
            }

            async function loadContracts(
                companyId,
                selectedContractId = '',
                preserveLines = false
            ) {
                contractSelect.disabled = true;

                contractSelect.innerHTML =
                    '<option value="">Загрузка договоров...</option>';

                if (!companyId) {
                    contractSelect.innerHTML =
                        '<option value="">— Сначала выберите компанию —</option>';

                    contractItems.classList.add('hidden');
                    itemsList.innerHTML = '';

                    return;
                }

                try {
                    const response = await fetch(
                        `/ajax/companies/${companyId}/contracts`
                    );

                    if (!response.ok) {
                        throw new Error('Не удалось загрузить договоры.');
                    }

                    const contracts = await response.json();

                    contractSelect.innerHTML =
                        '<option value="">— Выберите договор —</option>';

                    contracts.forEach(function(contract) {
                        const option =
                            document.createElement('option');

                        option.value = contract.id;
                        option.textContent =
                            contract.contract_number;

                        contractSelect.appendChild(option);
                    });

                    contractSelect.disabled = false;

                    if (selectedContractId) {
                        contractSelect.value =
                            String(selectedContractId);

                        await loadContractItems(
                            selectedContractId,
                            preserveLines
                        );
                    }
                } catch (error) {
                    contractSelect.innerHTML =
                        '<option value="">Ошибка загрузки договоров</option>';

                    console.error(error);
                }
            }

            async function loadContractItems(
                contractId,
                preserveLines = false
            ) {
                contractItems.classList.add('hidden');
                itemsList.innerHTML = '';

                clearContractInformation();

                if (!preserveLines) {
                    clearLines();
                }

                if (!contractId) {
                    return;
                }

                itemsList.innerHTML = `
                <p class="text-sm text-gray-400">
                    Загрузка позиций...
                </p>
            `;

                contractItems.classList.remove('hidden');

                try {
                    const response = await fetch(
                        `/ajax/contracts/${contractId}/items`
                    );

                    if (!response.ok) {
                        throw new Error(
                            'Не удалось загрузить позиции договора.'
                        );
                    }

                    const data = await response.json();

                    showContractInformation(data.contract);
                    renderContractItems(data);
                } catch (error) {
                    itemsList.innerHTML = `
                    <p class="text-sm text-red-500">
                        Не удалось загрузить позиции договора.
                    </p>
                `;

                    console.error(error);
                }
            }

            function recalculate() {
                let total = 0;
                let validLinesCount = 0;

                linesContainer
                    .querySelectorAll('.line-item')
                    .forEach(function(line) {
                        const description =
                            line.querySelector('.line-description')
                            ?.value.trim() || '';

                        const amount =
                            Number(
                                line.querySelector('.line-amount')
                                ?.value || 0
                            );

                        if (description && amount > 0) {
                            total += amount;
                            validLinesCount++;
                        }
                    });

                linesCount.textContent =
                    validLinesCount;

                totalAmount.textContent =
                    `${total.toFixed(2)} ₼`;

                const hasAnyRows =
                    linesContainer.querySelectorAll('.line-item')
                    .length > 0;

                linesEmpty.classList.toggle(
                    'hidden',
                    hasAnyRows
                );
            }

            companySelect.addEventListener(
                'change',
                async function() {
                    clearLines();
                    clearContractInformation();

                    contractItems.classList.add('hidden');
                    itemsList.innerHTML = '';

                    await loadContracts(this.value);
                }
            );

            contractSelect.addEventListener(
                'change',
                async function() {
                    await loadContractItems(this.value);
                }
            );

            itemsList.addEventListener(
                'change',
                function(event) {
                    const checkbox =
                        event.target.closest('.item-checkbox');

                    if (!checkbox) {
                        return;
                    }

                    if (checkbox.checked) {
                        createLine({
                            description: checkbox.dataset.description,

                            amount: checkbox.dataset.amount,

                            type: checkbox.dataset.type,

                            id: checkbox.dataset.id,

                            sourceKey: checkbox.dataset.sourceKey,

                            billingPeriod: checkbox.dataset.billingPeriod,

                            paymentTerms: checkbox.dataset.paymentTerms,

                            nextBillingDate: checkbox.dataset.nextBillingDate,

                            subscriptionStartDate: checkbox.dataset.subscriptionStartDate,
                        });
                    } else {
                        removeLineBySourceKey(
                            checkbox.dataset.sourceKey
                        );
                    }
                }
            );

            linesContainer.addEventListener(
                'click',
                function(event) {
                    const button =
                        event.target.closest(
                            '.remove-line-button'
                        );

                    if (!button) {
                        return;
                    }

                    const line =
                        button.closest('.line-item');

                    const sourceKey =
                        line.dataset.sourceKey;

                    line.remove();

                    if (sourceKey) {
                        itemsList
                            .querySelectorAll('.item-checkbox')
                            .forEach(function(checkbox) {
                                if (
                                    checkbox.dataset.sourceKey ===
                                    sourceKey
                                ) {
                                    checkbox.checked = false;
                                }
                            });
                    }

                    recalculate();
                }
            );

            linesContainer.addEventListener(
                'input',
                function(event) {
                    if (
                        event.target.classList.contains(
                            'line-description'
                        ) ||
                        event.target.classList.contains(
                            'line-amount'
                        )
                    ) {
                        recalculate();
                    }
                }
            );

            document
                .getElementById('add-manual-line')
                .addEventListener('click', function() {
                    createLine();
                });

            invoiceForm.addEventListener(
                'submit',
                function(event) {
                    const validLines =
                        Array.from(
                            linesContainer.querySelectorAll(
                                '.line-item'
                            )
                        ).filter(function(line) {
                            const description =
                                line.querySelector(
                                    '.line-description'
                                )?.value.trim();

                            const amount =
                                Number(
                                    line.querySelector(
                                        '.line-amount'
                                    )?.value || 0
                                );

                            return description && amount > 0;
                        });

                    if (validLines.length === 0) {
                        event.preventDefault();

                        linesError.classList.remove('hidden');

                        linesError.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center',
                        });
                    }
                }
            );

            oldLines.forEach(function(line) {
                let type = '';
                let id = '';

                if (line.subscription_id) {
                    type = 'subscription';
                    id = line.subscription_id;
                }

                if (line.order_id) {
                    type = 'order';
                    id = line.order_id;
                }

                createLine({
                    description: line.description || '',
                    amount: line.amount || '',
                    type: type,
                    id: id,
                    sourceKey: getSourceKey(type, id),
                    periodStart: line.period_start || '',
                    periodEnd: line.period_end || '',
                });
            });

            if (companySelect.value) {
                loadContracts(
                    companySelect.value,
                    oldContractId,
                    true
                );
            }

            recalculate();
        });
    </script>

@endsection
