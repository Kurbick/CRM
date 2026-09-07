@extends('layouts.app')

@section('title', __('invoices.billing_page.drafts_created'))

@section('content')
    <div class="mx-auto max-w-6xl">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('invoices.billing.preview', ['month' => $period->month, 'year' => $period->year]) }}"
                class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 transition hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2">
                {{ __('invoices.billing_page.result_back_to_preview') }}
            </a>
            <a href="{{ route('dashboard') }}"
                class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 transition hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2">
                {{ __('invoices.billing_page.back_to_dashboard') }}
            </a>
        </div>

        <header class="border-b border-slate-200 pb-5">
            <h1 class="text-2xl font-semibold text-slate-900">{{ __('invoices.billing_page.drafts_created') }}</h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ __('invoices.billing_page.result_subtitle', ['period' => $period->locale(app()->getLocale())->translatedFormat('F Y')]) }}
            </p>
        </header>

        <section data-testid="billing-run-result" class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-4 py-4 sm:px-5">
                <div class="flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-600">
                    <span>{{ __('invoices.billing_page.created') }}: <strong class="text-slate-900">{{ count($created) }}</strong></span>
                    <span>{{ __('invoices.billing_page.skipped') }}: <strong class="text-slate-900">{{ count($skipped) }}</strong></span>
                </div>
            </div>

            @if ($created !== [])
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3 sm:px-5">{{ __('invoices.billing_page.invoice_number') }}</th>
                                <th class="px-4 py-3">{{ __('invoices.billing_page.company') }}</th>
                                <th class="px-4 py-3">{{ __('invoices.billing_page.contract') }}</th>
                                <th class="px-4 py-3">{{ __('invoices.billing_page.period') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('invoices.billing_page.total') }}</th>
                                <th class="px-4 py-3">{{ __('invoices.billing_page.status') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('invoices.billing_page.action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($created as $draft)
                                <tr class="text-slate-700">
                                    <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-900 sm:px-5">{{ $draft['invoice_number'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ $draft['company'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ $draft['contract'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ $draft['period'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ $draft['total_display'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ __('invoices.statuses.'.$draft['status']) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        <a href="{{ route('invoices.show', ['invoice' => $draft['id'], 'billing_result' => 1]) }}"
                                            class="font-medium text-slate-600 hover:text-slate-950">
                                            {{ __('invoices.billing_page.open') }}
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="px-4 py-10 text-center text-sm text-slate-500 sm:px-5">
                    {{ __('invoices.billing_page.result_empty') }}
                </p>
            @endif

            @if ($skipped !== [])
                <div class="border-t border-slate-200 px-4 py-4 sm:px-5">
                    <ul class="space-y-2 text-sm text-slate-600">
                        @foreach ($skipped as $item)
                            <li>
                                {{ $item['company'] ?? '' }}
                                @if (! empty($item['contract']))
                                    — {{ $item['contract'] }}
                                @endif
                                @if (! empty($item['period']))
                                    — {{ $item['period'] }}
                                @endif
                                <span class="text-slate-500">
                                    {{ ($item['reason'] ?? null) === 'already_invoiced' ? __('invoices.billing_page.already_exists') : __('invoices.billing_page.not_current') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>
    </div>
@endsection
