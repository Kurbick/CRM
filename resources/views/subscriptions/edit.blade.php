@extends('layouts.app')

@section('title', 'Редактирование подписки')

@section('content')
    @php($scheduleLocked = $subscription->invoice_lines_count > 0)

    <div class="mb-5">
        <a href="{{ $backUrl }}" class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 transition hover:text-slate-900">
            <span aria-hidden="true">←</span>
            Назад к договору
        </a>
        <h1 class="mt-3 text-xl font-semibold text-slate-900">Редактирование подписки</h1>
        <p class="mt-1 text-sm text-slate-500">
            Договор <span class="font-mono font-medium text-slate-700">{{ $contract->contract_number }}</span>
            <span class="mx-1 text-slate-300">·</span>
            {{ $contract->company->name }}
        </p>
    </div>

    <form action="{{ route('subscriptions.update', $subscription) }}" method="POST" class="max-w-4xl">
        @csrf
        @method('PUT')

        <div data-testid="subscription-form-workspace" class="overflow-hidden border-y border-slate-200 bg-white">
            <section class="px-4 py-5 sm:px-5">
                <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Основная информация</h2>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label for="title" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Название <span class="text-red-500">*</span></label>
                        <input type="text" name="title" id="title" value="{{ old('title', $subscription->title ?? $subscription->serviceType?->name) }}" placeholder="Например: техническая поддержка" maxlength="255" required
                            class="w-full @error('title') border-red-300 @else border-gray-200 @enderror">
                        @error('title')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="start_date" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Дата начала <span class="text-red-500">*</span></label>
                        @if ($scheduleLocked)
                            <input type="hidden" name="start_date" value="{{ old('start_date', $subscription->start_date->toDateString()) }}">
                        @endif
                        <x-form.date-input name="start_date" :value="old('start_date', $subscription->start_date)" required :disabled="$scheduleLocked" />
                    </div>

                    <div>
                        <label for="amount" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Сумма (₼) <span class="text-red-500">*</span></label>
                        <input type="number" name="amount" id="amount" value="{{ old('amount', $subscription->amount) }}" step="0.01" min="0" required
                            class="w-full font-mono @error('amount') border-red-300 @else border-gray-200 @enderror">
                        @error('amount')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </section>

            <section class="border-t border-slate-200 px-4 py-5 sm:px-5">
                <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">График оплаты</h2>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label for="billing_period" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Период <span class="text-red-500">*</span></label>
                        <select name="billing_period" id="billing_period" required @disabled($scheduleLocked)
                            class="w-full @error('billing_period') border-red-300 @else border-gray-200 @enderror"
                            onchange="document.getElementById('custom_period_wrapper').classList.toggle('hidden', this.value !== 'custom')">
                            <option value="monthly" @selected(old('billing_period', $subscription->billing_period) === 'monthly')>Ежемесячно</option>
                            <option value="quarterly" @selected(old('billing_period', $subscription->billing_period) === 'quarterly')>Ежеквартально</option>
                            <option value="semiannual" @selected(old('billing_period', $subscription->billing_period) === 'semiannual')>Раз в полгода</option>
                            <option value="annual" @selected(old('billing_period', $subscription->billing_period) === 'annual')>Ежегодно</option>
                            <option value="custom" @selected(old('billing_period', $subscription->billing_period) === 'custom')>Свой вариант</option>
                        </select>
                        @if ($scheduleLocked)
                            <input type="hidden" name="billing_period" value="{{ $subscription->billing_period }}">
                        @endif
                        @error('billing_period')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="payment_terms" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Срок оплаты (дней) <span class="text-red-500">*</span></label>
                        <input type="number" name="payment_terms" id="payment_terms" value="{{ old('payment_terms', $subscription->payment_terms) }}" min="1" max="365" required
                            class="w-full font-mono @error('payment_terms') border-red-300 @else border-gray-200 @enderror">
                        @error('payment_terms')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div id="custom_period_wrapper" class="{{ old('billing_period', $subscription->billing_period) === 'custom' ? '' : 'hidden' }} md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Свой период <span class="text-red-500">*</span></label>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <input type="number" name="custom_interval_value" value="{{ old('custom_interval_value', $subscription->custom_interval_value) }}" min="1" max="3650" placeholder="Количество" @disabled($scheduleLocked)
                                class="w-full @error('custom_interval_value') border-red-300 @else border-gray-200 @enderror">
                            <select name="custom_interval_unit" @disabled($scheduleLocked) class="w-full @error('custom_interval_unit') border-red-300 @else border-gray-200 @enderror">
                                <option value="day" @selected(old('custom_interval_unit', $subscription->custom_interval_unit) === 'day')>дней</option>
                                <option value="month" @selected(old('custom_interval_unit', $subscription->custom_interval_unit) === 'month')>месяцев</option>
                                <option value="year" @selected(old('custom_interval_unit', $subscription->custom_interval_unit) === 'year')>лет</option>
                            </select>
                        </div>
                        @if ($scheduleLocked && $subscription->billing_period === 'custom')
                            <input type="hidden" name="custom_interval_value" value="{{ $subscription->custom_interval_value }}">
                            <input type="hidden" name="custom_interval_unit" value="{{ $subscription->custom_interval_unit }}">
                        @endif
                        @error('custom_interval_value')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                        @error('custom_interval_unit')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="status" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Статус <span class="text-red-500">*</span></label>
                        <select name="status" id="status" required class="w-full @error('status') border-red-300 @else border-gray-200 @enderror">
                            <option value="active" @selected(old('status', $subscription->status) === 'active')>Активна</option>
                            <option value="suspended" @selected(old('status', $subscription->status) === 'suspended')>Приостановлена</option>
                            <option value="completed" @selected(old('status', $subscription->status) === 'completed')>Завершена</option>
                            <option value="cancelled" @selected(old('status', $subscription->status) === 'cancelled')>Отменена</option>
                        </select>
                        @error('status')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                @if ($scheduleLocked)
                    <p class="mt-4 border-l-2 border-slate-300 pl-3 text-sm text-slate-500">График нельзя изменить после добавления подписки в счёт.</p>
                @endif
            </section>

            <section class="border-t border-slate-200 px-4 py-5 sm:px-5">
                <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Комментарий</h2>
                <div class="mt-4">
                    <label for="comment" class="sr-only">Комментарий</label>
                    <textarea name="comment" id="comment" rows="3" class="w-full @error('comment') border-red-300 @else border-gray-200 @enderror">{{ old('comment', $subscription->comment) }}</textarea>
                    @error('comment')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>
            </section>

            <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 px-4 py-4 sm:px-5">
                <button type="submit" class="bg-blue-600">Сохранить</button>
                <a href="{{ $backUrl }}" class="border border-gray-200">Отмена</a>
            </div>
        </div>
    </form>
@endsection
