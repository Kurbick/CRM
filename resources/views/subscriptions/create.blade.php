@extends('layouts.app')
@section('title', 'Добавить подписку')
@section('content')

<div class="mb-6">
    <a href="{{ $backUrl }}" class="text-sm text-gray-500 hover:text-gray-700">← Назад</a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">Добавить подписку</h1>
    <p class="text-sm text-gray-500">Договор: {{ $contract->contract_number }} — {{ $contract->company->name }}</p>
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 max-w-2xl">
    <form action="{{ route('contracts.subscriptions.store', $contract) }}" method="POST" class="space-y-4">
        @csrf

        <div>
        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
            Название услуги <span class="text-red-500">*</span>
        </label>

        <input
            type="text"
            name="service_name"
            value="{{ old('service_name') }}"
            placeholder="Например: ежемесячная техническая поддержка"
            maxlength="255"
            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
            required
        >

        @error('service_name')
            <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
        @enderror
        </div>

        <div>
        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
            Дата начала <span class="text-red-500">*</span>
        </label>

        <x-form.date-input name="start_date" :value="old('start_date', now()->toDateString())" required />
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Период <span class="text-red-500">*</span></label>
                <select name="billing_period" id="billing_period"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                    onchange="document.getElementById('custom_interval_fields').classList.toggle('hidden', this.value !== 'custom')"
                    required>
                    <option value="monthly" {{ old('billing_period') === 'monthly' ? 'selected' : '' }}>Ежемесячно</option>
                    <option value="quarterly" {{ old('billing_period') === 'quarterly' ? 'selected' : '' }}>Ежеквартально</option>
                    <option value="semiannual" {{ old('billing_period') === 'semiannual' ? 'selected' : '' }}>Раз в полгода</option>
                    <option value="annual" {{ old('billing_period') === 'annual' ? 'selected' : '' }}>Ежегодно</option>
                    <option value="custom" {{ old('billing_period') === 'custom' ? 'selected' : '' }}>Свой вариант</option>
                </select>
                <div id="custom_interval_fields" class="{{ old('billing_period') === 'custom' ? '' : 'hidden' }} mt-2 grid grid-cols-2 gap-2">
                    <input type="number" name="custom_interval_value" value="{{ old('custom_interval_value') }}"
                        min="1" max="3650" placeholder="Количество"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">
                    <select name="custom_interval_unit"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">
                        <option value="day" {{ old('custom_interval_unit') === 'day' ? 'selected' : '' }}>дней</option>
                        <option value="month" {{ old('custom_interval_unit') === 'month' ? 'selected' : '' }}>месяцев</option>
                        <option value="year" {{ old('custom_interval_unit') === 'year' ? 'selected' : '' }}>лет</option>
                    </select>
                </div>
                @error('custom_interval_value')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                @error('custom_interval_unit')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Сумма (₼) <span class="text-red-500">*</span></label>
                <input type="number" name="amount" value="{{ old('amount') }}"
                       step="0.01" min="0"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:border-blue-500 outline-none transition"
                       required>
                @error('amount')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Срок оплаты (дней) <span class="text-red-500">*</span></label>
                <input type="number" name="payment_terms" value="{{ old('payment_terms', 30) }}"
                       min="1" max="365"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:border-blue-500 outline-none transition"
                       required>
                @error('payment_terms')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Статус <span class="text-red-500">*</span></label>
                <select name="status"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                        required>
                    <option value="active" {{ old('status') === 'active' ? 'selected' : '' }}>Активна</option>
                    <option value="suspended" {{ old('status') === 'suspended' ? 'selected' : '' }}>Приостановлена</option>
                    <option value="completed" {{ old('status') === 'completed' ? 'selected' : '' }}>Завершена</option>
                    <option value="cancelled" {{ old('status') === 'cancelled' ? 'selected' : '' }}>Отменена</option>
                </select>
            </div>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Комментарий</label>
            <textarea name="comment" rows="3"
                      class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">{{ old('comment') }}</textarea>
        </div>

        <div class="flex gap-3 pt-2">
            <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-6 py-2.5 rounded-lg transition">
                Сохранить подписку
            </button>
            <a href="{{ $backUrl }}"
               class="px-6 py-2.5 border border-gray-200 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-50 transition">
                Отмена
            </a>
        </div>
    </form>
</div>

@endsection
