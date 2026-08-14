@extends('layouts.app')

@section('title', 'Добавить предмет договора')

@section('content')
    <div class="mb-5">
        <a href="{{ $backUrl }}"
            class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 transition hover:text-slate-900">
            <span aria-hidden="true">←</span>
            Назад к договору
        </a>

        <h1 class="mt-3 text-xl font-semibold text-slate-900">Добавить предмет договора</h1>

        <p class="mt-1 text-sm text-slate-500">
            Договор <span class="font-mono font-medium text-slate-700">{{ $contract->contract_number }}</span>
            <span class="mx-1 text-slate-300">·</span>
            {{ $contract->company->name }}
        </p>
    </div>

    <section data-testid="contract-subject-selector" class="max-w-4xl border-y border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-4 py-4 sm:px-5">
            <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Тип предмета</h2>
        </div>

        <div class="grid grid-cols-1 divide-y divide-slate-200 sm:grid-cols-2 sm:divide-x sm:divide-y-0">
            @if ($canCreateOrder)
                <a href="{{ route('contracts.orders.create', $contract) }}"
                    class="px-4 py-4 text-sm font-medium text-slate-900 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500 sm:px-5">
                    Разовая услуга
                </a>
            @endif

            @if ($canCreateSubscription)
                <a href="{{ route('contracts.subscriptions.create', $contract) }}"
                    class="px-4 py-4 text-sm font-medium text-slate-900 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500 sm:px-5">
                    Подписка
                </a>
            @endif
        </div>
    </section>
@endsection
