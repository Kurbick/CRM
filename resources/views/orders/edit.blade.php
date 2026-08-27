@extends('layouts.app')

@section('title', __('orders.edit_title'))

@section('content')
    <div class="mb-5">
        <a href="{{ $backUrl }}" class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 transition hover:text-slate-900">
            <span aria-hidden="true">←</span>
            {{ __('orders.back_to_contract') }}
        </a>
        <h1 class="mt-3 text-xl font-semibold text-slate-900">{{ __('orders.edit_title') }}</h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ __('contracts.fields.contract') }} <span class="font-mono font-medium text-slate-700">{{ $contract->contract_number }}</span>
            <span class="mx-1 text-slate-300">·</span>
            {{ $contract->company->name }}
        </p>
    </div>

    <form action="{{ route('orders.update', $order) }}" method="POST" class="max-w-4xl">
        @csrf
        @method('PUT')

        <div data-testid="one-time-service-form-workspace" class="overflow-hidden border-y border-slate-200 bg-white">
            <section class="px-4 py-5 sm:px-5">
                <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('orders.basic_information') }}</h2>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label for="title" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('orders.name') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="title" id="title" value="{{ old('title', $order->title ?? $order->serviceType?->name) }}" placeholder="{{ __('orders.name_placeholder') }}" maxlength="255" required
                            class="w-full @error('title') border-red-300 @else border-gray-200 @enderror">
                        @error('title')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="order_date" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('orders.date') }} <span class="text-red-500">*</span></label>
                        <x-form.date-input name="order_date" :value="old('order_date', $order->order_date)" required />
                    </div>

                    <div>
                        <label for="price" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('orders.amount') }} <span class="text-red-500">*</span></label>
                        <input type="number" name="price" id="price" value="{{ old('price', $order->price) }}" step="0.01" min="0" required
                            class="w-full font-mono @error('price') border-red-300 @else border-gray-200 @enderror">
                        @error('price')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="payment_terms" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('orders.payment_terms') }} <span class="text-red-500">*</span></label>
                        <input type="number" name="payment_terms" value="{{ old('payment_terms', $order->payment_terms) }}" id="payment_terms" min="0" max="3650" placeholder="{{ __('orders.payment_terms_placeholder') }}" required
                            class="w-full font-mono @error('payment_terms') border-red-300 @else border-gray-200 @enderror">
                        @error('payment_terms')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="status" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('orders.status') }} <span class="text-red-500">*</span></label>
                        <select name="status" id="status" required class="w-full @error('status') border-red-300 @else border-gray-200 @enderror">
                            <option value="in_progress" @selected(old('status', $order->status) === 'in_progress')>{{ __('orders.statuses.in_progress') }}</option>
                            <option value="completed" @selected(old('status', $order->status) === 'completed')>{{ __('orders.statuses.completed') }}</option>
                            <option value="cancelled" @selected(old('status', $order->status) === 'cancelled')>{{ __('orders.statuses.cancelled') }}</option>
                        </select>
                        @error('status')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </section>

            <section class="border-t border-slate-200 px-4 py-5 sm:px-5">
                <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('orders.comment') }}</h2>
                <div class="mt-4">
                    <label for="comment" class="sr-only">{{ __('orders.comment') }}</label>
                    <textarea name="comment" id="comment" rows="3" class="w-full @error('comment') border-red-300 @else border-gray-200 @enderror">{{ old('comment', $order->comment) }}</textarea>
                    @error('comment')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>
            </section>

            <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 px-4 py-4 sm:px-5">
                <button type="submit" class="bg-blue-600">{{ __('orders.save') }}</button>
                <a href="{{ $backUrl }}" class="border border-gray-200">{{ __('orders.cancel') }}</a>
            </div>
        </div>
    </form>
@endsection
