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

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm max-w-3xl">
        <div class="px-6 py-5 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800">Выберите тип предмета</h2>
            <p class="text-sm text-gray-500 mt-1">Данные будут заполнены на следующем шаге.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 p-6">
            @if ($canCreateOrder)
                <a href="{{ route('contracts.orders.create', $contract) }}"
                    class="block rounded-xl border border-gray-200 p-5 hover:border-blue-300 hover:bg-blue-50/50 transition">
                    <span class="block font-semibold text-gray-900">Разовая услуга</span>
                    <span class="block text-sm text-gray-500 mt-1">Однократная работа или услуга по договору.</span>
                </a>
            @endif

            @if ($canCreateSubscription)
                <a href="{{ route('contracts.subscriptions.create', $contract) }}"
                    class="block rounded-xl border border-gray-200 p-5 hover:border-blue-300 hover:bg-blue-50/50 transition">
                    <span class="block font-semibold text-gray-900">Подписка</span>
                    <span class="block text-sm text-gray-500 mt-1">Регулярная услуга с расчётным периодом.</span>
                </a>
            @endif
        </div>
    </div>
@endsection
