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

        <div class="crm-table-shell">
            <div class="crm-table-heading">
                <span class="crm-table-heading-title">Компании</span>
                @if ($abilities['create_companies'])
                    <a href="{{ route('companies.create') }}"
                        class="crm-light-action">
                        + Добавить
                    </a>
                @endif
            </div>

            <div class="crm-table-scroll">
                <table class="crm-table">
                    <thead>
                        <tr>
                            <th>Компания</th>
                            <th>Статус</th>
                            @if ($abilities['company_debt'])
                                <th>Долг</th>
                            @endif
                            @if ($abilities['company_payments'])
                                <th>Последний платёж</th>
                            @endif
                            @if ($abilities['company_invoices'])
                                <th>След. оплата</th>
                            @endif
                            <th class="crm-table-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($companies as $company)
                            <tr>
                                <td>
                                    @can('view', $company['model'])
                                        <a href="{{ route('companies.show', $company['model']) }}"
                                            class="crm-table-primary-link">
                                            {{ $company['name'] }}
                                        </a>
                                    @else
                                        <span class="crm-table-primary">{{ $company['name'] }}</span>
                                    @endcan
                                    @if ($abilities['company_invoices'] && $company['has_overdue'])
                                        <div class="crm-table-secondary mt-0.5 text-red-500">⚠ Есть просрочка</div>
                                    @endif
                                </td>
                                <td>
                                    @include('partials.badge', ['status' => $company['status']])
                                </td>
                                @if ($abilities['company_debt'])
                                    <td class="crm-table-numeric">
                                        <span class="{{ $company['total_debt'] > 0 ? 'font-semibold text-red-600' : 'text-slate-400' }}">
                                            {{ number_format($company['total_debt'], 2) }} ₼
                                        </span>
                                    </td>
                                @endif
                                @if ($abilities['company_payments'])
                                    <td class="crm-table-date">
                                        {{ $company['last_payment_date'] ? \Illuminate\Support\Carbon::parse($company['last_payment_date'])->format('d/m/Y') : '—' }}
                                    </td>
                                @endif
                                @if ($abilities['company_invoices'])
                                    <td class="crm-table-date">
                                        @if ($company['next_due_date'])
                                            {{ \Illuminate\Support\Carbon::parse($company['next_due_date'])->format('d/m/Y') }}
                                            <span class="crm-table-secondary">({{ number_format($company['next_due_amount'], 2) }} ₼)</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                @endif
                                <td class="crm-table-actions">
                                    @can('view', $company['model'])
                                        <a href="{{ route('companies.show', $company['model']) }}" class="crm-table-action-link">Открыть →</a>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $companyColumnCount }}" class="crm-table-empty">
                                    <span class="crm-table-empty-message">Компаний пока нет.</span>
                                    @if ($abilities['create_companies'])
                                        <a href="{{ route('companies.create') }}" class="crm-table-empty-action">Добавить первую</a>
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
