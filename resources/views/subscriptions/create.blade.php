@extends('layouts.app')

@section('title', __('subscriptions.title'))

@section('content')
    <div class="mb-5">
        <a href="{{ route('contracts.subjects.create', $contract) }}" class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 transition hover:text-slate-900">
            <span aria-hidden="true">←</span>
            {{ __('subscriptions.back') }}
        </a>
        <h1 class="mt-3 text-xl font-semibold text-slate-900">{{ __('subscriptions.title') }}</h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ __('contracts.fields.contract') }} <span class="font-mono font-medium text-slate-700">{{ $contract->contract_number }}</span>
            <span class="mx-1 text-slate-300">·</span>
            {{ $contract->company->name }}
        </p>
    </div>

    <form action="{{ route('contracts.subscriptions.store', $contract) }}" method="POST" class="max-w-4xl">
        @csrf

        <div data-testid="subscription-form-workspace" class="overflow-hidden border-y border-slate-200 bg-white">
            <section class="px-4 py-5 sm:px-5">
                <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('subscriptions.basic_information') }}</h2>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label for="service_name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('subscriptions.name') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="service_name" id="service_name" value="{{ old('service_name') }}" placeholder="{{ __('subscriptions.name_placeholder_create') }}" maxlength="255" required
                            class="w-full @error('service_name') border-red-300 @else border-gray-200 @enderror">
                        @error('service_name')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="start_date" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('subscriptions.start_date') }} <span class="text-red-500">*</span></label>
                        <x-form.date-input name="start_date" :value="old('start_date', now()->toDateString())" required />
                    </div>

                    <div>
                        <label for="amount" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('subscriptions.amount') }} <span class="text-red-500">*</span></label>
                        <input type="number" name="amount" id="amount" value="{{ old('amount') }}" step="0.01" min="0" required
                            class="w-full font-mono @error('amount') border-red-300 @else border-gray-200 @enderror">
                        @error('amount')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </section>

            <section class="border-t border-slate-200 px-4 py-5 sm:px-5">
                <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('subscriptions.payment_schedule') }}</h2>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label for="billing_period" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('subscriptions.period') }} <span class="text-red-500">*</span></label>
                        <select name="billing_period" id="billing_period" required
                            class="w-full @error('billing_period') border-red-300 @else border-gray-200 @enderror"
                            onchange="document.getElementById('custom_interval_fields').classList.toggle('hidden', this.value !== 'custom')">
                            <option value="monthly" @selected(old('billing_period') === 'monthly')>{{ __('subscriptions.monthly') }}</option>
                            <option value="quarterly" @selected(old('billing_period') === 'quarterly')>{{ __('subscriptions.quarterly') }}</option>
                            <option value="semiannual" @selected(old('billing_period') === 'semiannual')>{{ __('subscriptions.semiannual') }}</option>
                            <option value="annual" @selected(old('billing_period') === 'annual')>{{ __('subscriptions.annual') }}</option>
                            <option value="custom" @selected(old('billing_period') === 'custom')>{{ __('subscriptions.custom') }}</option>
                        </select>
                        @error('billing_period')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror

                        <div id="custom_interval_fields" class="{{ old('billing_period') === 'custom' ? '' : 'hidden' }} mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <input type="number" name="custom_interval_value" value="{{ old('custom_interval_value') }}" min="1" max="3650" placeholder="{{ __('subscriptions.quantity') }}"
                                class="w-full @error('custom_interval_value') border-red-300 @else border-gray-200 @enderror">
                            <select name="custom_interval_unit" class="w-full @error('custom_interval_unit') border-red-300 @else border-gray-200 @enderror">
                                <option value="day" @selected(old('custom_interval_unit') === 'day')>{{ __('subscriptions.days') }}</option>
                                <option value="month" @selected(old('custom_interval_unit') === 'month')>{{ __('subscriptions.months') }}</option>
                                <option value="year" @selected(old('custom_interval_unit') === 'year')>{{ __('subscriptions.years') }}</option>
                            </select>
                        </div>
                        @error('custom_interval_value')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                        @error('custom_interval_unit')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="payment_terms" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('subscriptions.payment_terms') }} <span class="text-red-500">*</span></label>
                        <input type="number" name="payment_terms" id="payment_terms" value="{{ old('payment_terms', 30) }}" min="1" max="365" required
                            class="w-full font-mono @error('payment_terms') border-red-300 @else border-gray-200 @enderror">
                        @error('payment_terms')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="status" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('subscriptions.status') }} <span class="text-red-500">*</span></label>
                        <select name="status" id="status" required class="w-full @error('status') border-red-300 @else border-gray-200 @enderror">
                            <option value="active" @selected(old('status') === 'active')>{{ __('subscriptions.statuses.active') }}</option>
                            <option value="suspended" @selected(old('status') === 'suspended')>{{ __('subscriptions.statuses.suspended') }}</option>
                            <option value="completed" @selected(old('status') === 'completed')>{{ __('subscriptions.statuses.completed') }}</option>
                            <option value="cancelled" @selected(old('status') === 'cancelled')>{{ __('subscriptions.statuses.cancelled') }}</option>
                        </select>
                        @error('status')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </section>

            <section class="border-t border-slate-200 px-4 py-5 sm:px-5">
                <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('subscriptions.comment') }}</h2>
                <div class="mt-4">
                    <label for="comment" class="sr-only">{{ __('subscriptions.comment') }}</label>
                    <textarea name="comment" id="comment" rows="3" class="w-full @error('comment') border-red-300 @else border-gray-200 @enderror">{{ old('comment') }}</textarea>
                    @error('comment')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>
            </section>

            <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 px-4 py-4 sm:px-5">
                <button type="submit" class="bg-blue-600">{{ __('subscriptions.save') }}</button>
                <a href="{{ route('contracts.subjects.create', $contract) }}" class="border border-gray-200">{{ __('subscriptions.cancel') }}</a>
            </div>
        </div>
    </form>
@endsection
