@extends('layouts.app')

@section('title', 'Добавить предмет договора')

@section('content')

    <div class="mb-6">
        <a href="{{ route('contracts.show', $contract) }}"
            class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 transition">
            ← Назад к договору
        </a>

        <h1 class="text-2xl font-bold text-gray-900 mt-3">
            Добавить предмет договора
        </h1>

        <p class="text-sm text-gray-500 mt-1">
            Договор
            <span class="font-mono font-medium text-gray-700">
                {{ $contract->contract_number }}
            </span>

            <span class="mx-1 text-gray-300">•</span>

            {{ $contract->company->name }}
        </p>
    </div>

    <div x-data="{
        subjectType: '{{ old('subject_type', 'one_time') }}',
        billingPeriod: '{{ old('billing_period', 'monthly') }}'
    }" class="bg-white rounded-xl border border-gray-200 shadow-sm max-w-3xl">
        <div class="px-6 py-4 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800">
                Основная информация
            </h2>

            <p class="text-sm text-gray-500 mt-1">
                Выберите тип и заполните данные предмета договора.
            </p>
        </div>

        <form method="POST" action="{{ route('contracts.subjects.store', $contract) }}" class="px-6 pb-6 pt-0 space-y-6">
            @csrf

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-2">
                    Тип <span class="text-red-500">*</span>
                </label>

                <div class="flex flex-col sm:flex-row gap-5">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="subject_type" value="one_time" x-model="subjectType"
                            class="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500">

                        <span class="text-sm font-medium text-gray-700">
                            Разовая услуга
                        </span>
                    </label>

                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="subject_type" value="subscription" x-model="subjectType"
                            class="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500">

                        <span class="text-sm font-medium text-gray-700">
                            Подписка
                        </span>
                    </label>
                </div>

                @error('subject_type')
                    <p class="text-xs text-red-500 mt-1">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                    Название <span class="text-red-500">*</span>
                </label>

                <input type="text" name="title" value="{{ old('title') }}"
                    placeholder="Например: настройка локальной сети" maxlength="255"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                    required>

                @error('title')
                    <p class="text-xs text-red-500 mt-1">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Разовая услуга --}}
            <div x-show="subjectType === 'one_time'" x-cloak class="space-y-4">
                <div class="border-t border-gray-100 pt-0">
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                            Дата заказа <span class="text-red-500">*</span>
                        </label>

                        <input type="date" name="order_date" value="{{ old('order_date', now()->toDateString()) }}"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">

                        @error('order_date')
                            <p class="text-xs text-red-500 mt-1">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                            Срок выполнения
                        </label>

                        <input type="date" name="deadline" value="{{ old('deadline') }}"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">

                        @error('deadline')
                            <p class="text-xs text-red-500 mt-1">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                        Стоимость <span class="text-red-500">*</span>
                    </label>

                    <input type="number" name="price" value="{{ old('price') }}" min="0" step="0.01"
                        placeholder="0.00"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">

                    @error('price')
                        <p class="text-xs text-red-500 mt-1">
                            {{ $message }}
                        </p>
                    @enderror
                </div>
            </div>

            {{-- Подписка --}}
            <div x-show="subjectType === 'subscription'" x-cloak class="space-y-4">
                <div class="border-t border-gray-100 pt-0">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                            Дата начала <span class="text-red-500">*</span>
                        </label>

                        <input type="date" name="start_date" value="{{ old('start_date', now()->toDateString()) }}"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">

                        @error('start_date')
                            <p class="text-xs text-red-500 mt-1">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                            Стоимость за период <span class="text-red-500">*</span>
                        </label>

                        <input type="number" name="amount" value="{{ old('amount') }}" min="0" step="0.01"
                            placeholder="0.00"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">

                        @error('amount')
                            <p class="text-xs text-red-500 mt-1">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                        Период оплаты <span class="text-red-500">*</span>
                    </label>

                    <select name="billing_period" x-model="billingPeriod"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">
                        <option value="monthly">Ежемесячно</option>
                        <option value="quarterly">Ежеквартально</option>
                        <option value="semiannual">Раз в полгода</option>
                        <option value="annual">Ежегодно</option>
                        <option value="custom">Другой период</option>
                    </select>

                    @error('billing_period')
                        <p class="text-xs text-red-500 mt-1">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div x-show="billingPeriod === 'custom'" x-cloak>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                        Свой период <span class="text-red-500">*</span>
                    </label>

                    <input type="text" name="billing_period_custom" value="{{ old('billing_period_custom') }}"
                        placeholder="Например: каждые 45 дней" maxlength="255"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">

                    @error('billing_period_custom')
                        <p class="text-xs text-red-500 mt-1">
                            {{ $message }}
                        </p>
                    @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 border-t border-gray-100 pt-5">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                        Срок оплаты, дней
                    </label>

                    <input type="number" name="payment_terms" value="{{ old('payment_terms') }}" min="1"
                        max="365" placeholder="Например: 10"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">

                    @error('payment_terms')
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
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                    placeholder="Дополнительные условия или примечание">{{ old('comment') }}</textarea>

                @error('comment')
                    <p class="text-xs text-red-500 mt-1">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-6 py-2.5 rounded-lg transition">
                    Сохранить
                </button>

                <a href="{{ route('contracts.show', $contract) }}"
                    class="px-6 py-2.5 border border-gray-200 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-50 transition">
                    Отмена
                </a>
            </div>
        </form>
    </div>

@endsection
