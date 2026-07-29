@extends('layouts.app')

@section('title', 'Дашборд')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Дашборд</h1>
        <p class="mt-1 text-sm text-gray-500">Общая статистика по системе</p>
    </div>

    @unless ($hasDomainBlocks)
        <div class="rounded-xl border border-gray-200 bg-white p-8 text-center shadow-sm">
            <p class="text-sm text-gray-600">Для просмотра показателей у вас нет необходимых прав</p>
        </div>
    @endunless

    @if ($hasDomainBlocks)
        <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @if ($abilities['global_debt'])
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Общий долг</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($overview['total_debt'], 2) }} ₼</p>
                </div>
            @endif

            @if ($abilities['invoices'])
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Выставлено</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($overview['total_invoiced'], 2) }} ₼</p>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Просрочено</p>
                    <p class="mt-1 text-2xl font-bold text-red-600">{{ $overview['overdue_count'] }}</p>
                    <p class="mt-1 text-xs text-gray-400">{{ number_format($overview['overdue_amount'], 2) }} ₼</p>
                </div>
            @endif

            @if ($abilities['payments'])
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Оплачено</p>
                    <p class="mt-1 text-2xl font-bold text-green-600">{{ number_format($overview['total_paid'], 2) }} ₼</p>
                    <p class="mt-1 text-xs text-gray-400">Всего платежей</p>
                </div>
            @endif

            @if ($abilities['contracts'])
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Подписки</p>
                    <p class="mt-1 text-2xl font-bold text-blue-600">{{ $overview['active_subscriptions'] }}</p>
                </div>
            @endif

            @if ($abilities['companies'])
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Активные компании</p>
                    <p class="mt-1 text-2xl font-bold text-blue-600">{{ $overview['active_companies'] }}</p>
                </div>
            @endif
        </div>
    @endif

    @if ($abilities['companies'])
        @php
            $companyColumnCount = 2
                + ($abilities['company_debt'] ? 1 : 0)
                + ($abilities['company_payments'] ? 1 : 0)
                + ($abilities['company_invoices'] ? 1 : 0)
                + 1;
        @endphp

        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                <h2 class="font-semibold text-gray-800">Компании</h2>
                @if ($abilities['create_companies'])
                    <a href="{{ route('companies.create') }}"
                        class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white transition hover:bg-blue-700">
                        + Добавить
                    </a>
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-left">
                            <th class="px-6 py-3 text-xs font-medium uppercase text-gray-500">Компания</th>
                            <th class="px-6 py-3 text-xs font-medium uppercase text-gray-500">Статус</th>
                            @if ($abilities['company_debt'])
                                <th class="px-6 py-3 text-xs font-medium uppercase text-gray-500">Долг</th>
                            @endif
                            @if ($abilities['company_payments'])
                                <th class="px-6 py-3 text-xs font-medium uppercase text-gray-500">Последний платёж</th>
                            @endif
                            @if ($abilities['company_invoices'])
                                <th class="px-6 py-3 text-xs font-medium uppercase text-gray-500">След. оплата</th>
                            @endif
                            <th class="px-6 py-3 text-xs font-medium uppercase text-gray-500"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @forelse ($companies as $company)
                            <tr class="transition hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-gray-900">{{ $company['name'] }}</div>
                                    @if ($abilities['company_invoices'] && $company['has_overdue'])
                                        <div class="mt-0.5 text-xs text-red-500">⚠ Есть просрочка</div>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    @include('partials.badge', ['status' => $company['status']])
                                </td>
                                @if ($abilities['company_debt'])
                                    <td class="px-6 py-4">
                                        <span class="{{ $company['total_debt'] > 0 ? 'font-semibold text-red-600' : 'text-gray-400' }}">
                                            {{ number_format($company['total_debt'], 2) }} ₼
                                        </span>
                                    </td>
                                @endif
                                @if ($abilities['company_payments'])
                                    <td class="px-6 py-4 text-gray-500">
                                        {{ $company['last_payment_date'] ? \Illuminate\Support\Carbon::parse($company['last_payment_date'])->format('d/m/Y') : '—' }}
                                    </td>
                                @endif
                                @if ($abilities['company_invoices'])
                                    <td class="px-6 py-4 text-gray-500">
                                        @if ($company['next_due_date'])
                                            {{ \Illuminate\Support\Carbon::parse($company['next_due_date'])->format('d/m/Y') }}
                                            <span class="text-gray-400">({{ number_format($company['next_due_amount'], 2) }} ₼)</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                @endif
                                <td class="px-6 py-4 text-right">
                                    @can('view', $company['model'])
                                        <a href="{{ route('companies.show', $company['model']) }}"
                                            class="text-sm font-medium text-blue-600 hover:text-blue-800">
                                            Открыть →
                                        </a>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $companyColumnCount }}" class="px-6 py-12 text-center text-gray-400">
                                    Компаний пока нет.
                                    @if ($abilities['create_companies'])
                                        <a href="{{ route('companies.create') }}" class="text-blue-600 hover:underline">Добавить первую</a>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
