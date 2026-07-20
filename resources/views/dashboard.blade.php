@extends('layouts.app')

@section('title', 'Дашборд')

@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Дашборд</h1>
        <p class="text-sm text-gray-500 mt-1">Общая статистика по системе</p>
    </div>

    {{-- Карточки статистики --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">

        <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Общий долг</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($overview['total_debt'], 2) }} ₼</p>
            <p class="text-xs text-gray-400 mt-1">Выставлено: {{ number_format($overview['total_invoiced'], 2) }} ₼</p>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Оплачено</p>
            <p class="text-2xl font-bold text-green-600 mt-1">{{ number_format($overview['total_paid'], 2) }} ₼</p>
            <p class="text-xs text-gray-400 mt-1">Всего платежей</p>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Просрочено</p>
            <p class="text-2xl font-bold text-red-600 mt-1">{{ $overview['overdue_count'] }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ number_format($overview['overdue_amount'], 2) }} ₼</p>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Подписки</p>
            <p class="text-2xl font-bold text-blue-600 mt-1">{{ $overview['active_subscriptions'] }}</p>
            <p class="text-xs text-gray-400 mt-1">Активных компаний: {{ $overview['active_companies'] }}</p>
        </div>

    </div>

    {{-- Таблица компаний --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h2 class="font-semibold text-gray-800">Компании</h2>
            <a href="{{ route('companies.create') }}"
               class="text-sm bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
                + Добавить
            </a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-left">
                        <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">Компания</th>
                        <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">Статус</th>
                        <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">Долг</th>
                        <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">Последний платёж</th>
                        <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">След. оплата</th>
                        <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse($companies as $company)
                        <tr class="hover:bg-gray-50 transition">
                            <td class="px-6 py-4">
                                <div class="font-medium text-gray-900">{{ $company['name'] }}</div>
                                @if($company['has_overdue'])
                                    <div class="text-xs text-red-500 mt-0.5">⚠ Есть просрочка</div>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                @include('partials.badge', ['status' => $company['status']])
                            </td>
                            <td class="px-6 py-4">
                                <span class="{{ $company['total_debt'] > 0 ? 'text-red-600 font-semibold' : 'text-gray-400' }}">
                                    {{ number_format($company['total_debt'], 2) }} ₼
                                </span>
                            </td>
                            <td class="px-6 py-4 text-gray-500">
                                {{ $company['last_payment_date'] ? \Illuminate\Support\Carbon::parse($company['last_payment_date'])->format('d/m/Y') : '—' }}
                            </td>
                            <td class="px-6 py-4 text-gray-500">
                                @if($company['next_due_date'])
                                    {{ \Illuminate\Support\Carbon::parse($company['next_due_date'])->format('d/m/Y') }}
                                    <span class="text-gray-400">({{ number_format($company['next_due_amount'], 2) }} ₼)</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('companies.show', $company['id']) }}"
                                   class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                    Открыть →
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-gray-400">
                                Компаний пока нет.
                                <a href="{{ route('companies.create') }}" class="text-blue-600 hover:underline">Добавить первую</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@endsection
