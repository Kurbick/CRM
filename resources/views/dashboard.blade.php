@extends('layouts.app')

@section('title', __('dashboard.title'))

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">{{ __('dashboard.title') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ __('dashboard.description') }}</p>
    </div>

    @unless ($hasDomainBlocks)
        <section data-testid="dashboard-neutral-fallback" class="border-y border-slate-200 bg-white px-4 py-5 sm:px-5">
            <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('dashboard.access') }}</h2>
            <p class="mt-3 text-sm text-slate-500">{{ __('dashboard.no_permission') }}</p>
        </section>
    @endunless

    @if ($hasDomainBlocks)
        <div data-testid="dashboard-financial-summary" class="mb-8 overflow-hidden border-y border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-4 py-3 sm:px-5">
                <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('dashboard.sections.financial') }}</h2>
            </div>

            <div class="grid grid-cols-1 divide-y divide-slate-200 sm:grid-cols-2 sm:divide-x sm:divide-y-0 lg:grid-cols-4">
            @if ($abilities['global_debt'])
                <div data-testid="dashboard-financial-debt" class="px-4 py-4 sm:px-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('dashboard.metrics.total_debt') }}</p>
                    <p class="mt-1 text-xl font-semibold {{ $overview['total_debt'] > 0 ? 'text-red-600' : 'text-slate-900' }}">{{ number_format($overview['total_debt'], 2) }} ₼</p>
                </div>
            @endif

            @if ($abilities['invoices'])
                <div data-testid="dashboard-financial-invoiced" class="px-4 py-4 sm:px-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('dashboard.metrics.invoiced') }}</p>
                    <p class="mt-1 text-xl font-semibold text-slate-900">{{ number_format($overview['total_invoiced'], 2) }} ₼</p>
                </div>

                <div data-testid="dashboard-financial-overdue" class="px-4 py-4 sm:px-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('dashboard.metrics.overdue') }}</p>
                    <p class="mt-1 text-xl font-semibold {{ $overview['overdue_count'] > 0 ? 'text-red-600' : 'text-slate-900' }}">{{ $overview['overdue_count'] }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ number_format($overview['overdue_amount'], 2) }} ₼</p>
                </div>
            @endif

            @if ($abilities['payments'])
                <div data-testid="dashboard-financial-paid" class="px-4 py-4 sm:px-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('dashboard.metrics.paid') }}</p>
                    <p class="mt-1 text-xl font-semibold text-green-600">{{ number_format($overview['total_paid'], 2) }} ₼</p>
                    <p class="mt-1 text-xs text-slate-400">{{ __('dashboard.metrics.total_payments') }}</p>
                </div>
            @endif
            </div>

            @if ($abilities['companies'] || $abilities['contracts'])
                <div data-testid="dashboard-secondary-counters" class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-slate-200 px-4 py-3 text-sm sm:px-5">
                    @if ($abilities['companies'])
                        <div class="flex items-baseline gap-2">
                            <span class="text-xs uppercase tracking-wide text-slate-500">{{ __('dashboard.metrics.active_companies') }}</span>
                            <span class="font-semibold tabular-nums text-slate-900">{{ $overview['active_companies'] }}</span>
                        </div>
                    @endif

                    @if ($abilities['contracts'])
                        <div class="flex items-baseline gap-2">
                            <span class="text-xs uppercase tracking-wide text-slate-500">{{ __('dashboard.metrics.subscriptions') }}</span>
                            <span class="font-semibold tabular-nums text-slate-900">{{ $overview['active_subscriptions'] }}</span>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        @if ($abilities['billing'])
            <section data-testid="dashboard-billing-attention" class="mb-8">
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                    {{ __('dashboard.sections.attention') }}
                </h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <a href="{{ route('invoices.billing') }}"
                        data-testid="dashboard-billing-card"
                        class="group flex w-full max-w-md items-center gap-4 rounded-lg border border-slate-200 bg-white px-4 py-4 shadow-sm transition hover:border-slate-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2 sm:px-5">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-amber-50 text-amber-700" aria-hidden="true">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3.75h10v16.5l-5-2.75-5 2.75V3.75Zm3 4h4m-4 4h4m-4 4h2" />
                            </svg>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-slate-900">{{ __('dashboard.billing.title') }}</span>
                            <span class="mt-1 block text-2xl font-semibold tabular-nums text-slate-950">{{ $billingSummary['preview']['count'] }}</span>
                            <span class="block text-sm text-slate-500">
                                @if ($billingSummary['preview']['count'] > 0)
                                    {{ trans_choice('dashboard.billing.count', $billingSummary['preview']['count'], ['count' => $billingSummary['preview']['count'], 'period' => now()->locale(app()->getLocale())->translatedFormat('F Y')]) }}
                                    · {{ $billingSummary['preview']['total_display'] }}
                                @else
                                    {{ __('dashboard.billing.empty', ['period' => now()->locale(app()->getLocale())->translatedFormat('F Y')]) }}
                                @endif
                            </span>
                        </span>
                        <span class="text-xl text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-slate-600" aria-hidden="true">›</span>
                    </a>
                </div>
            </section>
        @endif
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
                <span class="crm-table-heading-title">{{ __('dashboard.sections.companies') }}</span>
                @if ($abilities['create_companies'])
                    <a href="{{ route('companies.create') }}"
                        class="crm-light-action">
                        {{ __('dashboard.actions.add_company') }}
                    </a>
                @endif
            </div>

            <div class="crm-table-scroll">
                <table class="crm-table">
                    <thead>
                        <tr>
                            <th>{{ __('dashboard.table.company') }}</th>
                            <th>{{ __('dashboard.table.status') }}</th>
                            @if ($abilities['company_debt'])
                                <th>{{ __('dashboard.table.debt') }}</th>
                            @endif
                            @if ($abilities['company_payments'])
                                <th>{{ __('dashboard.table.last_payment') }}</th>
                            @endif
                            @if ($abilities['company_invoices'])
                                <th>{{ __('dashboard.table.next_payment') }}</th>
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
                            <x-tables.clickable-row :url="$companyShowUrl" :label="__('dashboard.table.open_company', ['name' => $company['name']])">
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
                                        <div class="crm-table-secondary mt-0.5 text-red-500">{{ __('dashboard.table.overdue') }}</div>
                                    @endif
                                </td>
                                <td>
                                    @include('partials.badge', [
                                        'status' => $company['status'],
                                        'label' => match ($company['status']) {
                                            'active' => __('companies.statuses.active'),
                                            'inactive' => __('companies.statuses.inactive'),
                                            'suspended' => __('companies.statuses.suspended'),
                                            'archived' => __('companies.statuses.archived'),
                                            default => null,
                                        },
                                    ])
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
                                    <span class="crm-table-empty-message">{{ __('dashboard.empty.companies') }}</span>
                                    @if ($abilities['create_companies'])
                                        <a href="{{ route('companies.create') }}" class="crm-table-empty-action">{{ __('dashboard.actions.add_first_company') }}</a>
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
