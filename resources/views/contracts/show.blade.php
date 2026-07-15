@extends('layouts.app')
@section('title', 'Договор ' . $contract->contract_number)
@section('content')

<div class="mb-6">
    <a href="{{ route('contracts.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Назад к договорам</a>
    <div class="flex items-center justify-between mt-2">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 font-mono">{{ $contract->contract_number }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                <a href="{{ route('companies.show', $contract->company) }}" class="text-blue-600 hover:underline">
                    {{ $contract->company->name }}
                </a>
                <span class="mx-2 text-gray-300">|</span>
                {{ $contract->start_date }} — {{ $contract->end_date ?? 'Бессрочный' }}
            </p>
        </div>
        <div class="flex items-center gap-3">
            @include('partials.badge', ['status' => $contract->status])
            <a href="{{ route('contracts.edit', $contract) }}"
               class="text-sm border border-gray-200 hover:bg-gray-50 text-gray-600 px-4 py-2 rounded-lg transition">
                Редактировать
            </a>
        </div>
    </div>
</div>

{{-- Комментарий --}}
@if($contract->comment)
    <div class="bg-yellow-50 border border-yellow-200 rounded-lg px-4 py-3 text-sm text-yellow-800 mb-6">
        {{ $contract->comment }}
    </div>
@endif

{{-- Заказы --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
        <h2 class="font-semibold text-gray-800">Разовые заказы</h2>
        <a href="{{ route('contracts.orders.create', $contract) }}"
           class="text-sm bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
            + Добавить заказ
        </a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 bg-gray-50 text-left">
                    <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">Услуга</th>
                    <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">Дата заказа</th>
                    <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">Дедлайн</th>
                    <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">Сумма</th>
                    <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">Статус</th>
                    <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($contract->orders as $order)
                    <tr class="hover:bg-gray-50/50 transition">
                        <td class="px-6 py-4 font-medium text-gray-900">{{ $order->serviceType->name }}</td>
                        <td class="px-6 py-4 text-gray-600">{{ $order->order_date }}</td>
                        <td class="px-6 py-4 text-gray-400">{{ $order->deadline ?? '—' }}</td>
                        <td class="px-6 py-4 font-mono font-medium">{{ number_format($order->price, 2) }} ₼</td>
                        <td class="px-6 py-4">
                            @include('partials.badge', ['status' => $order->status])
                        </td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('orders.edit', $order) }}"
                               class="text-gray-400 hover:text-blue-600 text-xs transition">Редакт.</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-gray-400">
                            Заказов пока нет.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Подписки --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
        <h2 class="font-semibold text-gray-800">Подписки</h2>
        <a href="{{ route('contracts.subscriptions.create', $contract) }}"
           class="text-sm bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
            + Добавить подписку
        </a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 bg-gray-50 text-left">
                    <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">Услуга</th>
                    <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">Период</th>
                    <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">Сумма</th>
                    <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">След. списание</th>
                    <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">Статус</th>
                    <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($contract->subscriptions as $subscription)
                    <tr class="hover:bg-gray-50/50 transition">
                        <td class="px-6 py-4 font-medium text-gray-900">{{ $subscription->serviceType->name }}</td>
                        <td class="px-6 py-4 text-gray-600">
                            @php
                                $periods = [
                                    'monthly' => 'Ежемесячно',
                                    'quarterly' => 'Ежеквартально',
                                    'semiannual' => 'Раз в полгода',
                                    'annual' => 'Ежегодно',
                                ];
                            @endphp
                            {{ $periods[$subscription->billing_period] ?? $subscription->billing_period }}
                        </td>
                        <td class="px-6 py-4 font-mono font-medium">{{ number_format($subscription->amount, 2) }} ₼</td>
                        <td class="px-6 py-4 text-gray-600">{{ $subscription->next_billing_date }}</td>
                        <td class="px-6 py-4">
                            @include('partials.badge', ['status' => $subscription->status])
                        </td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('subscriptions.edit', $subscription) }}"
                               class="text-gray-400 hover:text-blue-600 text-xs transition">Редакт.</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-gray-400">
                            Подписок пока нет.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection