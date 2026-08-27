@extends('layouts.app')

@section('title', __('contracts.subjects.user_title'))

@section('content')
    <div class="mb-5">
        <a href="{{ $backUrl }}"
            class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 transition hover:text-slate-900">
            <span aria-hidden="true">←</span>
            {{ __('contracts.actions.back_to_contract') }}
        </a>

        <h1 class="mt-3 text-xl font-semibold text-slate-900">{{ __('contracts.subjects.user_title') }}</h1>

        <p class="mt-1 text-sm text-slate-500">
            {{ __('contracts.fields.contract') }} <span class="font-mono font-medium text-slate-700">{{ $contract->contract_number }}</span>
            <span class="mx-1 text-slate-300">·</span>
            {{ $contract->company->name }}
        </p>
    </div>

    <section data-testid="contract-subject-selector" class="max-w-4xl border-y border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-4 py-4 sm:px-5">
            <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('contracts.subjects.type') }}</h2>
        </div>

        <div class="grid grid-cols-2 gap-2 p-3 sm:p-4">
            @if ($canCreateOrder)
                <a href="{{ route('contracts.orders.create', $contract) }}" data-testid="contract-subject-order-option"
                    class="group flex h-[68px] cursor-pointer items-center gap-3 rounded-md border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 transition hover:border-blue-400 hover:bg-blue-50/50 focus-visible:border-blue-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-1 active:border-blue-600 active:bg-blue-50">
                    <span data-testid="contract-subject-order-icon" aria-hidden="true"
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 transition group-hover:bg-blue-100 group-hover:text-blue-700 group-focus-visible:bg-blue-100 group-focus-visible:text-blue-700 group-active:bg-blue-100">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <rect x="3" y="7" width="18" height="13" rx="2" stroke-width="2" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8 7V5.5A1.5 1.5 0 0 1 9.5 4h5A1.5 1.5 0 0 1 16 5.5V7M3 12h18M10 12v2h4v-2" />
                        </svg>
                    </span>
                    <span class="min-w-0 flex-1 text-left font-semibold leading-tight group-hover:text-blue-700">{{ __('contracts.subjects.order_type') }}</span>
                    <span data-testid="contract-subject-choice-indicator" aria-hidden="true"
                        class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full border-2 border-slate-300 bg-white transition group-hover:border-blue-500 group-focus-visible:border-blue-600 group-active:border-blue-600">
                        <span class="h-1.5 w-1.5 scale-0 rounded-full bg-blue-600 transition-transform group-active:scale-100"></span>
                    </span>
                </a>
            @endif

            @if ($canCreateSubscription)
                <a href="{{ route('contracts.subscriptions.create', $contract) }}" data-testid="contract-subject-subscription-option"
                    class="group flex h-[68px] cursor-pointer items-center gap-3 rounded-md border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 transition hover:border-blue-400 hover:bg-blue-50/50 focus-visible:border-blue-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-1 active:border-blue-600 active:bg-blue-50">
                    <span data-testid="contract-subject-subscription-icon" aria-hidden="true"
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 transition group-hover:bg-blue-100 group-hover:text-blue-700 group-focus-visible:bg-blue-100 group-focus-visible:text-blue-700 group-active:bg-blue-100">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M7 3v3M17 3v3M4 8h16M6 5h12a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M16.5 13.5H19V11m0 2.5a4 4 0 1 0 .25 4.2" />
                        </svg>
                    </span>
                    <span class="min-w-0 flex-1 text-left font-semibold leading-tight group-hover:text-blue-700">{{ __('contracts.subjects.subscription_type') }}</span>
                    <span data-testid="contract-subject-choice-indicator" aria-hidden="true"
                        class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full border-2 border-slate-300 bg-white transition group-hover:border-blue-500 group-focus-visible:border-blue-600 group-active:border-blue-600">
                        <span class="h-1.5 w-1.5 scale-0 rounded-full bg-blue-600 transition-transform group-active:scale-100"></span>
                    </span>
                </a>
            @endif
        </div>
    </section>
@endsection
