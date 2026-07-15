@extends('layouts.app')
@section('title', 'Выставить счёт')
@section('content')

<div class="mb-6">
    <a href="{{ route('invoices.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Назад к инвойсам</a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">Выставить счёт</h1>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Левая колонка — основная форма --}}
    <div class="lg:col-span-2 space-y-4">

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <h2 class="font-semibold text-gray-800 mb-4">Клиент и договор</h2>

            <form id="invoice-form" action="{{ route('invoices.store') }}" method="POST">
                @csrf

                {{-- Компания --}}
                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Компания <span class="text-red-500">*</span></label>
                    <select name="company_id" id="company_id"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                            required>
                        <option value="">— Выберите компанию —</option>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}" {{ old('company_id') == $company->id ? 'selected' : '' }}>
                                {{ $company->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('company_id')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Контракт --}}
                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Договор <span class="text-red-500">*</span></label>
                    <select name="contract_id" id="contract_id"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                            required disabled>
                        <option value="">— Сначала выберите компанию —</option>
                    </select>
                </div>

                {{-- Номер инвойса и даты --}}
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Номер счёта <span class="text-red-500">*</span></label>
                        <input type="text" name="invoice_number"
                               value="{{ old('invoice_number', 'INV-' . strtoupper(substr(uniqid(), -6))) }}"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:border-blue-500 outline-none transition"
                               required>
                        @error('invoice_number')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Дата выставления <span class="text-red-500">*</span></label>
                        <input type="date" name="issue_date" id="issue_date"
                               value="{{ old('issue_date', now()->toDateString()) }}"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                               required>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Срок оплаты <span class="text-red-500">*</span></label>
                        <input type="date" name="due_date" id="due_date"
                               value="{{ old('due_date') }}"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                               required>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Статус</label>
                        <select name="status"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">
                            <option value="draft">Черновик</option>
                            <option value="issued" selected>Выставлен</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Период с</label>
                        <input type="date" name="period_start" value="{{ old('period_start') }}"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Период по</label>
                        <input type="date" name="period_end" value="{{ old('period_end') }}"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">
                    </div>
                </div>

                {{-- Реквизиты --}}
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Плательщик</label>
                        <input type="text" name="payer_name" id="payer_name" value="{{ old('payer_name') }}"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">VÖEN плательщика</label>
                        <input type="text" name="payer_voen" id="payer_voen" value="{{ old('payer_voen') }}"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:border-blue-500 outline-none transition">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Ссылка на договор (Müqavilə №)</label>
                    <input type="text" name="contract_reference" id="contract_reference" value="{{ old('contract_reference') }}"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:border-blue-500 outline-none transition">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Комментарий</label>
                    <textarea name="comment" rows="2"
                              class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">{{ old('comment') }}</textarea>
                </div>

        </div>

        {{-- Позиции инвойса --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <h2 class="font-semibold text-gray-800 mb-4">Позиции счёта</h2>

            {{-- Список позиций из контракта --}}
            <div id="contract-items" class="hidden mb-4">
                <p class="text-xs text-gray-500 mb-2">Выберите позиции из договора:</p>
                <div id="items-list" class="space-y-2"></div>
            </div>

            {{-- Строки инвойса --}}
            <div id="lines-container" class="space-y-3 mb-4">
                <div class="line-item grid grid-cols-12 gap-2 items-start">
                    <div class="col-span-7">
                        <input type="text" name="lines[0][description]"
                               placeholder="Описание услуги..."
                               value="{{ old('lines.0.description') }}"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                               required>
                    </div>
                    <div class="col-span-3">
                        <input type="number" name="lines[0][amount]"
                               placeholder="0.00" step="0.01" min="0"
                               value="{{ old('lines.0.amount') }}"
                               class="line-amount w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:border-blue-500 outline-none transition"
                               required>
                    </div>
                    <div class="col-span-2 flex items-center justify-center pt-1">
                        <button type="button" onclick="removeLine(this)"
                                class="text-red-400 hover:text-red-600 text-xs transition">✕</button>
                    </div>
                </div>
            </div>

            <button type="button" onclick="addLine()"
                    class="text-sm text-blue-600 hover:text-blue-800 font-medium transition">
                + Добавить строку
            </button>
        </div>

        {{-- Кнопки --}}
        <div class="flex gap-3">
            <button type="submit" form="invoice-form"
                    class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-6 py-2.5 rounded-lg transition">
                Выставить счёт
            </button>
            <a href="{{ route('invoices.index') }}"
               class="px-6 py-2.5 border border-gray-200 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-50 transition">
                Отмена
            </a>
        </div>

            </form>
    </div>

    {{-- Правая колонка — итог --}}
    <div class="space-y-4">
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 sticky top-4">
            <h2 class="font-semibold text-gray-800 mb-4">Итог</h2>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between text-gray-500">
                    <span>Позиций:</span>
                    <span id="lines-count">1</span>
                </div>
                <div class="border-t border-gray-100 pt-2 flex justify-between font-semibold text-gray-900 text-lg">
                    <span>Итого:</span>
                    <span id="total-amount">0.00 ₼</span>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
    let lineIndex = 1;

    // Загрузка контрактов при выборе компании
    document.getElementById('company_id').addEventListener('change', function() {
        const companyId = this.value;
        const contractSelect = document.getElementById('contract_id');
        const payerName = document.getElementById('payer_name');
        const payerVoen = document.getElementById('payer_voen');

        // Находим выбранную компанию и подставляем реквизиты
        const option = this.options[this.selectedIndex];
        if (option.dataset.name) {
            payerName.value = option.dataset.name;
            payerVoen.value = option.dataset.voen || '';
        }

        if (!companyId) {
            contractSelect.disabled = true;
            contractSelect.innerHTML = '<option value="">— Сначала выберите компанию —</option>';
            return;
        }

        fetch(`/ajax/companies/${companyId}/contracts`)
            .then(r => r.json())
            .then(contracts => {
                contractSelect.disabled = false;
                contractSelect.innerHTML = '<option value="">— Выберите договор —</option>';
                contracts.forEach(c => {
                    contractSelect.innerHTML += `<option value="${c.id}" data-number="${c.contract_number}">${c.contract_number}</option>`;
                });
            });
    });

    // Загрузка позиций при выборе контракта
    document.getElementById('contract_id').addEventListener('change', function() {
        const contractId = this.value;
        const option = this.options[this.selectedIndex];

        if (option.dataset.number) {
            document.getElementById('contract_reference').value = option.dataset.number;
        }

        if (!contractId) {
            document.getElementById('contract-items').classList.add('hidden');
            return;
        }

        fetch(`/ajax/contracts/${contractId}/items`)
            .then(r => r.json())
            .then(data => {
                const itemsList = document.getElementById('items-list');
                itemsList.innerHTML = '';

                const allItems = [
                    ...data.orders.map(i => ({...i, label: '📋 ' + i.description})),
                    ...data.subscriptions.map(i => ({...i, label: '🔄 ' + i.description})),
                ];

                if (allItems.length === 0) {
                    itemsList.innerHTML = '<p class="text-xs text-gray-400">Нет доступных позиций в этом договоре.</p>';
                } else {
                    allItems.forEach(item => {
                        itemsList.innerHTML += `
                            <label class="flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50 cursor-pointer border border-gray-100">
                                <input type="checkbox" class="item-checkbox"
                                       data-description="${item.label}"
                                       data-amount="${item.amount}"
                                       data-type="${item.type}"
                                       data-id="${item.id}"
                                       data-terms="${item.terms || 14}"
                                       onchange="toggleItem(this)">
                                <span class="text-sm text-gray-700 flex-1">${item.label}</span>
                                <span class="text-sm font-mono font-medium text-gray-900">${parseFloat(item.amount).toFixed(2)} ₼</span>
                            </label>`;
                    });
                }

                document.getElementById('contract-items').classList.remove('hidden');
            });
    });

    // Добавить позицию из контракта в строки инвойса
    function toggleItem(checkbox) {
        if (checkbox.checked) {
            addLineWithData(checkbox.dataset.description, checkbox.dataset.amount, checkbox.dataset.type, checkbox.dataset.id, checkbox.dataset.terms);
        } else {
            removeLineByData(checkbox.dataset.description);
        }
        recalculate();
    }

    function addLineWithData(description, amount, type, id, terms) {
        const container = document.getElementById('lines-container');
        const div = document.createElement('div');
        div.className = 'line-item grid grid-cols-12 gap-2 items-start';
        div.dataset.description = description;

        const typeField = type === 'subscription' ? `<input type="hidden" name="lines[${lineIndex}][subscription_id]" value="${id}">` :
                                                     `<input type="hidden" name="lines[${lineIndex}][order_id]" value="${id}">`;

        div.innerHTML = `
            ${typeField}
            <div class="col-span-7">
                <input type="text" name="lines[${lineIndex}][description]"
                       value="${description}"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                       required>
            </div>
            <div class="col-span-3">
                <input type="number" name="lines[${lineIndex}][amount]"
                       value="${parseFloat(amount).toFixed(2)}"
                       step="0.01" min="0"
                       class="line-amount w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:border-blue-500 outline-none transition"
                       required>
            </div>
            <div class="col-span-2 flex items-center justify-center pt-1">
                <button type="button" onclick="removeLine(this)" class="text-red-400 hover:text-red-600 text-xs transition">✕</button>
            </div>`;

        container.appendChild(div);
        lineIndex++;

        // Автоматически ставим due_date по payment_terms
        if (terms) {
            const issueDate = document.getElementById('issue_date').value;
            if (issueDate) {
                const due = new Date(issueDate);
                due.setDate(due.getDate() + parseInt(terms));
                document.getElementById('due_date').value = due.toISOString().split('T')[0];
            }
        }

        recalculate();
    }

    function removeLineByData(description) {
        document.querySelectorAll('.line-item').forEach(item => {
            if (item.dataset.description === description) item.remove();
        });
        recalculate();
    }

    function addLine() {
        const container = document.getElementById('lines-container');
        const div = document.createElement('div');
        div.className = 'line-item grid grid-cols-12 gap-2 items-start';
        div.innerHTML = `
            <div class="col-span-7">
                <input type="text" name="lines[${lineIndex}][description]"
                       placeholder="Описание услуги..."
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                       required>
            </div>
            <div class="col-span-3">
                <input type="number" name="lines[${lineIndex}][amount]"
                       placeholder="0.00" step="0.01" min="0"
                       class="line-amount w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:border-blue-500 outline-none transition"
                       required>
            </div>
            <div class="col-span-2 flex items-center justify-center pt-1">
                <button type="button" onclick="removeLine(this)" class="text-red-400 hover:text-red-600 text-xs transition">✕</button>
            </div>`;
        container.appendChild(div);
        lineIndex++;
    }

    function removeLine(btn) {
        const lines = document.querySelectorAll('.line-item');
        if (lines.length > 1) {
            btn.closest('.line-item').remove();
            recalculate();
        }
    }

    function recalculate() {
        let total = 0;
        document.querySelectorAll('.line-amount').forEach(input => {
            total += parseFloat(input.value || 0);
        });
        document.getElementById('total-amount').textContent = total.toFixed(2) + ' ₼';
        document.getElementById('lines-count').textContent = document.querySelectorAll('.line-item').length;
    }

    // Пересчёт при изменении суммы вручную
    document.getElementById('lines-container').addEventListener('input', function(e) {
        if (e.target.classList.contains('line-amount')) recalculate();
    });
</script>

@endsection