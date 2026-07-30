@extends('layouts.app')

@section('title', 'Редактировать подписку')

@section('content')

    @php($scheduleLocked = $subscription->invoice_lines_count > 0)

    <div class="mb-6">
        <a href="{{ $backUrl }}" class="text-sm text-gray-500 hover:text-gray-700">
            ← Назад
        </a>

        <h1 class="text-2xl font-bold text-gray-900 mt-2">
            Редактировать подписку
        </h1>

        <p class="text-sm text-gray-500">
            Договор:
            {{ $contract->contract_number }}
            —
            {{ $contract->company->name }}
        </p>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 max-w-2xl">
        <form action="{{ route('subscriptions.update', $subscription) }}" method="POST" class="space-y-4">
            @csrf
            @method('PUT')

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                    Название <span class="text-red-500">*</span>
                </label>

                <input type="text" name="title"
                    value="{{ old('title', $subscription->title ?? $subscription->serviceType?->name) }}"
                    placeholder="Например: техническая поддержка" maxlength="255"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                    required>

                @error('title')
                    <p class="text-xs text-red-500 mt-1">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                    Дата начала <span class="text-red-500">*</span>
                </label>

                @if ($scheduleLocked)
                    <input type="hidden" name="start_date" value="{{ old('start_date', $subscription->start_date->toDateString()) }}">
                @endif
                <x-form.date-input name="start_date" :value="old('start_date', $subscription->start_date)" required :disabled="$scheduleLocked" />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                        Период <span class="text-red-500">*</span>
                    </label>

                    <select name="billing_period" id="billing_period"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                        onchange="
                            document
                                .getElementById('custom_period_wrapper')
                                .classList
                                .toggle('hidden', this.value !== 'custom')
                        "
                        required @disabled($scheduleLocked)>
                        <option value="monthly"
                            {{ old('billing_period', $subscription->billing_period) === 'monthly' ? 'selected' : '' }}>
                            Ежемесячно
                        </option>

                        <option value="quarterly"
                            {{ old('billing_period', $subscription->billing_period) === 'quarterly' ? 'selected' : '' }}>
                            Ежеквартально
                        </option>

                        <option value="semiannual"
                            {{ old('billing_period', $subscription->billing_period) === 'semiannual' ? 'selected' : '' }}>
                            Раз в полгода
                        </option>

                        <option value="annual"
                            {{ old('billing_period', $subscription->billing_period) === 'annual' ? 'selected' : '' }}>
                            Ежегодно
                        </option>

                        <option value="custom"
                            {{ old('billing_period', $subscription->billing_period) === 'custom' ? 'selected' : '' }}>
                            Свой вариант
                        </option>
                    </select>
                    @if ($scheduleLocked)
                        <input type="hidden" name="billing_period" value="{{ $subscription->billing_period }}">
                    @endif

                    @error('billing_period')
                        <p class="text-xs text-red-500 mt-1">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                        Сумма (₼) <span class="text-red-500">*</span>
                    </label>

                    <input type="number" name="amount" value="{{ old('amount', $subscription->amount) }}" step="0.01"
                        min="0"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:border-blue-500 outline-none transition"
                        required>

                    @error('amount')
                        <p class="text-xs text-red-500 mt-1">
                            {{ $message }}
                        </p>
                    @enderror
                </div>
            </div>

            <div id="custom_period_wrapper"
                class="{{ old('billing_period', $subscription->billing_period) === 'custom' ? '' : 'hidden' }}">
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                    Свой период <span class="text-red-500">*</span>
                </label>

                <div class="grid grid-cols-2 gap-2">
                    <input type="number" name="custom_interval_value"
                        value="{{ old('custom_interval_value', $subscription->custom_interval_value) }}"
                        min="1" max="3650" placeholder="Количество" @disabled($scheduleLocked)
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition disabled:bg-gray-100">
                    <select name="custom_interval_unit" @disabled($scheduleLocked)
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition disabled:bg-gray-100">
                        <option value="day" {{ old('custom_interval_unit', $subscription->custom_interval_unit) === 'day' ? 'selected' : '' }}>дней</option>
                        <option value="month" {{ old('custom_interval_unit', $subscription->custom_interval_unit) === 'month' ? 'selected' : '' }}>месяцев</option>
                        <option value="year" {{ old('custom_interval_unit', $subscription->custom_interval_unit) === 'year' ? 'selected' : '' }}>лет</option>
                    </select>
                </div>
                @if ($scheduleLocked && $subscription->billing_period === 'custom')
                    <input type="hidden" name="custom_interval_value" value="{{ $subscription->custom_interval_value }}">
                    <input type="hidden" name="custom_interval_unit" value="{{ $subscription->custom_interval_unit }}">
                @endif

                @error('custom_interval_value')
                    <p class="text-xs text-red-500 mt-1">
                        {{ $message }}
                    </p>
                @enderror
                @error('custom_interval_unit')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>

            @if ($scheduleLocked)
                <p class="text-sm text-gray-500">График нельзя изменить после добавления подписки в счёт.</p>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                        Срок оплаты (дней) <span class="text-red-500">*</span>
                    </label>

                    <input type="number" name="payment_terms"
                        value="{{ old('payment_terms', $subscription->payment_terms) }}" min="1" max="365"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:border-blue-500 outline-none transition"
                        required>

                    @error('payment_terms')
                        <p class="text-xs text-red-500 mt-1">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                        Статус <span class="text-red-500">*</span>
                    </label>

                    <select name="status"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                        required>
                        <option value="active" {{ old('status', $subscription->status) === 'active' ? 'selected' : '' }}>
                            Активна
                        </option>

                        <option value="suspended"
                            {{ old('status', $subscription->status) === 'suspended' ? 'selected' : '' }}>
                            Приостановлена
                        </option>

                        <option value="completed"
                            {{ old('status', $subscription->status) === 'completed' ? 'selected' : '' }}>
                            Завершена
                        </option>

                        <option value="cancelled"
                            {{ old('status', $subscription->status) === 'cancelled' ? 'selected' : '' }}>
                            Отменена
                        </option>
                    </select>

                    @error('status')
                        <p class="text-xs text-red-500 mt-1">
                            {{ $message }}
                        </p>
                    @enderror
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                    Комментарий
                </label>

                <textarea name="comment" rows="3"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">{{ old('comment', $subscription->comment) }}</textarea>

                @error('comment')
                    <p class="text-xs text-red-500 mt-1">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-6 py-2.5 rounded-lg transition">
                    Сохранить
                </button>

                <a href="{{ $backUrl }}"
                    class="px-6 py-2.5 border border-gray-200 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-50 transition">
                    Отмена
                </a>
            </div>
        </form>
    </div>

@endsection
