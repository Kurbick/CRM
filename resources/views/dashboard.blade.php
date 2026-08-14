@extends('layouts.app')

@section('title', 'Дашборд')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Дашборд</h1>
        <p class="mt-1 text-sm text-gray-500">Общая статистика по системе</p>
    </div>

    @unless ($hasDomainBlocks)
        <section data-testid="dashboard-neutral-fallback" class="border-y border-slate-200 bg-white px-4 py-5 sm:px-5">
            <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Доступ</h2>
            <p class="mt-3 text-sm text-slate-500">Для просмотра показателей у вас нет необходимых прав</p>
        </section>
    @endunless

    @if ($hasDomainBlocks)
        <div data-testid="dashboard-financial-summary" class="mb-8 overflow-hidden border-y border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-4 py-3 sm:px-5">
                <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Финансы</h2>
            </div>

            <div class="grid grid-cols-1 divide-y divide-slate-200 sm:grid-cols-2 sm:divide-x sm:divide-y-0 lg:grid-cols-4">
            @if ($abilities['global_debt'])
                <div data-testid="dashboard-financial-debt" class="px-4 py-4 sm:px-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Общий долг</p>
                    <p class="mt-1 text-xl font-semibold {{ $overview['total_debt'] > 0 ? 'text-red-600' : 'text-slate-900' }}">{{ number_format($overview['total_debt'], 2) }} ₼</p>
                </div>
            @endif

            @if ($abilities['invoices'])
                <div data-testid="dashboard-financial-invoiced" class="px-4 py-4 sm:px-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Выставлено</p>
                    <p class="mt-1 text-xl font-semibold text-slate-900">{{ number_format($overview['total_invoiced'], 2) }} ₼</p>
                </div>

                <div data-testid="dashboard-financial-overdue" class="px-4 py-4 sm:px-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Просрочено</p>
                    <p class="mt-1 text-xl font-semibold {{ $overview['overdue_count'] > 0 ? 'text-red-600' : 'text-slate-900' }}">{{ $overview['overdue_count'] }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ number_format($overview['overdue_amount'], 2) }} ₼</p>
                </div>
            @endif

            @if ($abilities['payments'])
                <div data-testid="dashboard-financial-paid" class="px-4 py-4 sm:px-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Оплачено</p>
                    <p class="mt-1 text-xl font-semibold text-green-600">{{ number_format($overview['total_paid'], 2) }} ₼</p>
                    <p class="mt-1 text-xs text-slate-400">Всего платежей</p>
                </div>
            @endif
            </div>

            @if ($abilities['companies'] || $abilities['contracts'])
                <div data-testid="dashboard-secondary-counters" class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-slate-200 px-4 py-3 text-sm sm:px-5">
                    @if ($abilities['companies'])
                        <div class="flex items-baseline gap-2">
                            <span class="text-xs uppercase tracking-wide text-slate-500">Активные компании</span>
                            <span class="font-semibold tabular-nums text-slate-900">{{ $overview['active_companies'] }}</span>
                        </div>
                    @endif

                    @if ($abilities['contracts'])
                        <div class="flex items-baseline gap-2">
                            <span class="text-xs uppercase tracking-wide text-slate-500">Подписки</span>
                            <span class="font-semibold tabular-nums text-slate-900">{{ $overview['active_subscriptions'] }}</span>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endif

    @if ($abilities['companies'])
        @php
            $companyColumnCount = 2
                + ($abilities['company_debt'] ? 1 : 0)
                + ($abilities['company_payments'] ? 1 : 0)
                + ($abilities['company_invoices'] ? 1 : 0);
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
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($companies as $company)
                            @php
                                $companyShowUrl = \Illuminate\Support\Facades\Gate::allows('view', $company['model'])
                                    ? route('companies.show', $company['model'])
                                    : null;
                            @endphp
                            <x-tables.clickable-row :url="$companyShowUrl" :label="'Открыть компанию '.$company['name']">
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
                            </x-tables.clickable-row>
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
