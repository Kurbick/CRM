@extends('layouts.app')

@section('title', __('invoices.billing_page.title'))

@section('content')
    <div class="mx-auto max-w-6xl">
        <a href="{{ route('dashboard') }}"
            data-testid="billing-back-dashboard"
            class="mb-3 inline-flex items-center gap-1 text-sm font-medium text-slate-500 transition hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2">
            {{ __('invoices.billing_page.back_to_dashboard') }}
        </a>

        <header class="border-b border-slate-200 pb-5">
            <h1 class="text-2xl font-semibold text-slate-900">{{ __('invoices.billing_page.title') }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ __('invoices.billing_page.subtitle') }}</p>
        </header>

        <section class="mt-6 rounded-lg border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <form method="GET" action="{{ route('invoices.billing.preview') }}" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                <div class="w-full sm:max-w-xs">
                    <label for="billing-month" class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ __('invoices.billing_page.month') }}
                    </label>
                    <select id="billing-month" name="month"
                        class="block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                        @foreach ($months as $month)
                            <option value="{{ $month['value'] }}" @selected((int) $period->month === $month['value'])>
                                {{ $month['label'] }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="w-full sm:max-w-xs">
                    <label for="billing-year" class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ __('invoices.billing_page.year') }}
                    </label>
                    <input id="billing-year" name="year" type="number" value="{{ $period->year }}" min="2000" max="2100"
                        class="block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                </div>
                <button type="submit"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                    {{ __('invoices.billing_page.find') }}
                </button>
            </form>
        </section>

        @php
            $selectionValues = collect($preview['rows'])
                ->filter(fn (array $row): bool => $row['eligible_for_draft_creation'])
                ->pluck('identity')
                ->values()
                ->all();
        @endphp

        <form method="POST"
            action="{{ route('invoices.billing.drafts', ['month' => $period->month, 'year' => $period->year]) }}"
            x-data="{ allOccurrences: @js($selectionValues), selectedOccurrences: @js($selectionValues) }">
            @csrf

            <section class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-4 py-4 sm:px-5">
                <h2 class="text-base font-semibold text-slate-900">
                    {{ __('invoices.billing_page.preview_title', ['period' => $period->locale(app()->getLocale())->translatedFormat('F Y')]) }}
                </h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('invoices.billing_page.read_only') }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="w-12 px-4 py-3 sm:px-5">
                                <input type="checkbox"
                                    aria-label="{{ __('invoices.billing_page.select_all') }}"
                                    x-bind:checked="allOccurrences.length > 0 && selectedOccurrences.length === allOccurrences.length"
                                    x-on:change="selectedOccurrences = $event.target.checked ? [...allOccurrences] : []"
                                    class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                            </th>
                            <th scope="col" class="px-4 py-3 sm:px-5">{{ __('invoices.billing_page.company') }}</th>
                            <th scope="col" class="px-4 py-3">{{ __('invoices.billing_page.contract') }}</th>
                            <th scope="col" class="px-4 py-3">{{ __('invoices.billing_page.description') }}</th>
                            <th scope="col" class="px-4 py-3">{{ __('invoices.billing_page.period') }}</th>
                            <th scope="col" class="px-4 py-3">{{ __('invoices.billing_page.status') }}</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3 text-right">{{ __('invoices.billing_page.subtotal') }}</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3 text-right">{{ __('invoices.billing_page.vat') }}</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3 text-right">{{ __('invoices.billing_page.total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($preview['rows'] as $row)
                            <x-tables.clickable-row
                                :url="$row['queue_status'] === 'draft' ? route('invoices.show', [
                                    'invoice' => $row['invoice_id'],
                                    'billing_preview' => 1,
                                    'month' => $period->month,
                                    'year' => $period->year,
                                ]) : null"
                                :label="$row['description'].' — '.$row['period']"
                                :class="$row['queue_status'] === 'draft'
                                    ? 'text-slate-700 cursor-pointer transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600'
                                    : 'text-slate-700'">
                                <td class="px-4 py-3 sm:px-5">
                                    @if ($row['eligible_for_draft_creation'])
                                        <input type="checkbox"
                                            name="selected_occurrences[]"
                                            value="{{ $row['identity'] }}"
                                            aria-label="{{ $row['description'] }} — {{ $row['period'] }}"
                                            x-model="selectedOccurrences"
                                            class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-900 sm:px-5">{{ $row['company'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $row['contract'] }}</td>
                                <td class="px-4 py-3">{{ $row['description'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $row['period'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    {{ $row['queue_status'] === 'draft'
                                        ? __('invoices.billing_page.queue_status.draft')
                                        : __('invoices.billing_page.queue_status.pending') }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ $row['subtotal_display'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ $row['vat_display'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums">{{ $row['total_display'] }}</td>
                            </x-tables.clickable-row>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-10 text-center text-sm text-slate-500">
                                    {{ __('invoices.billing_page.empty', ['period' => $period->locale(app()->getLocale())->translatedFormat('F Y')]) }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot class="border-t border-slate-200 bg-slate-50 text-sm">
                        <tr>
                            <th colspan="6" scope="row" class="px-4 py-3 text-left font-semibold text-slate-900 sm:px-5">
                                {{ trans_choice('invoices.billing_page.total_invoices', $preview['count'], ['count' => $preview['count']]) }}
                            </th>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums">{{ $preview['subtotal_display'] }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums">{{ $preview['vat_display'] }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums">{{ $preview['total_display'] }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            </section>

            <div class="flex flex-col gap-3 border-t border-slate-200 py-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-slate-500">
                    <span x-text="selectedOccurrences.length"></span>
                    {{ __('invoices.billing_page.selected') }}
                </p>
                <button type="submit"
                    x-bind:disabled="selectedOccurrences.length === 0"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
                    {{ __('invoices.billing_page.create_drafts') }}
                    <span x-show="selectedOccurrences.length > 0">
                        (<span x-text="selectedOccurrences.length"></span>)
                    </span>
                </button>
            </div>
        </form>

    </div>
@endsection
