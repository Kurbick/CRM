@extends('layouts.app')

@section('title', 'Добавить предмет договора')

@section('content')
    <div class="mb-6">
        <a href="{{ $backUrl }}"
            class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 transition">
            ← Назад
        </a>

        <h1 class="text-2xl font-bold text-gray-900 mt-3">
            Добавить предмет договора
        </h1>

        <p class="text-sm text-gray-500 mt-1">
            Договор <span class="font-mono font-medium text-gray-700">{{ $contract->contract_number }}</span>
            <span class="mx-1 text-gray-300">•</span>
            {{ $contract->company->name }}
        </p>
    </div>

    <div class="w-full max-w-2xl rounded-lg border border-gray-200 bg-white">
        <div class="border-b border-gray-100 px-5 py-4">
            <h2 class="font-semibold text-gray-800">Выберите тип предмета</h2>
        </div>

        <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2">
            @if ($canCreateOrder)
                <a href="{{ route('contracts.orders.create', $contract) }}"
                    class="flex min-h-16 items-center rounded-lg border border-slate-200 bg-slate-50/40 px-4 py-3 transition-colors hover:border-blue-300 hover:bg-blue-50/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                    <span class="font-semibold text-gray-900">Разовая услуга</span>
                </a>
            @endif

            @if ($canCreateSubscription)
                <a href="{{ route('contracts.subscriptions.create', $contract) }}"
                    class="flex min-h-16 items-center rounded-lg border border-slate-200 bg-slate-50/40 px-4 py-3 transition-colors hover:border-blue-300 hover:bg-blue-50/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                    <span class="font-semibold text-gray-900">Подписка</span>
                </a>
            @endif
        </div>
    </div>
@endsection
