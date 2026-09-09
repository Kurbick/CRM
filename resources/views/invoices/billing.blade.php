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
            <form id="billing-period-form" method="GET" action="{{ route('invoices.billing.preview') }}" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                <input type="hidden" name="tab" value="pending">
                <div class="w-full sm:max-w-xs">
                    <label for="billing-month" class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ __('invoices.billing_page.month') }}
                    </label>
                    <select id="billing-month" name="month"
                        onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()"
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
                        onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()"
                        class="block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                </div>
            </form>
        </section>

        @php
            $pendingRows = collect($preview['rows'])
                ->filter(fn (array $row): bool => in_array($row['queue_status'], ['pending', 'scheduled', 'missed'], true))
                ->values();
            $eligiblePendingRows = $pendingRows
                ->filter(fn (array $row): bool => $row['eligible_for_draft_creation'])
                ->values();
            $draftRows = collect($preview['rows'])
                ->filter(fn (array $row): bool => $row['queue_status'] === 'draft')
                ->values();
            $selectionValues = $eligiblePendingRows
                ->pluck('identity')
                ->values()
                ->all();
            $draftSelectionValues = $draftRows
                ->pluck('invoice_id')
                ->map(fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
            $activeTab = request()->query('tab') === 'drafts' ? 'drafts' : 'pending';
        @endphp

        <div x-data="{
            activeTab: @js($activeTab),
            allOccurrences: @js($selectionValues),
            allInvoices: @js($canIssue ? $draftSelectionValues : []),
            selectedOccurrences: [],
            selectedInvoices: []
        }">
            <form id="billing-create-drafts-form" method="POST"
                action="{{ route('invoices.billing.drafts', ['month' => $period->month, 'year' => $period->year]) }}">
                @csrf
            </form>
            @if ($canIssue)
                <form id="billing-issue-invoices-form" method="POST"
                    action="{{ route('invoices.billing.issue', ['month' => $period->month, 'year' => $period->year]) }}">
                    @csrf
                </form>
            @endif

            <section class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-4 py-4 sm:px-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <h2 class="text-base font-semibold text-slate-900">
                            {{ __('invoices.billing_page.preview_title', ['period' => $period->locale(app()->getLocale())->translatedFormat('F Y')]) }}
                        </h2>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-600">
                            <span>{{ __('invoices.billing_page.total_rows') }}: <strong class="text-slate-900">{{ $preview['planned_count'] }}</strong></span>
                            <span>{{ __('invoices.billing_page.row_status.pending') }}: <strong class="text-slate-900">{{ $eligiblePendingRows->count() }}</strong></span>
                            <span>{{ __('invoices.billing_page.drafts_created') }}: <strong class="text-slate-900">{{ $draftRows->count() }}</strong></span>
                            <span>{{ __('invoices.billing_page.summary_total') }}: <strong class="text-slate-900">{{ $preview['planned_total_display'] }}</strong></span>
                        </div>
                    </div>
                    <p class="mt-1 text-sm text-slate-500">{{ __('invoices.billing_page.read_only') }}</p>
                </div>

                <div role="tablist" aria-label="{{ __('invoices.billing_page.preview_title', ['period' => $period->locale(app()->getLocale())->translatedFormat('F Y')]) }}" class="flex border-b border-slate-200 px-4 sm:px-5">
                    <a href="{{ route('invoices.billing.preview', ['month' => $period->month, 'year' => $period->year, 'tab' => 'pending']) }}"
                        role="tab"
                        x-bind:aria-selected="activeTab === 'pending'"
                        x-bind:class="activeTab === 'pending' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'"
                        class="border-b-2 px-1 py-3 text-sm font-semibold transition sm:px-2">
                        {{ __('invoices.billing_page.queue_status.pending') }} <span class="tabular-nums">{{ $eligiblePendingRows->count() }}</span>
                    </a>
                    <a href="{{ route('invoices.billing.preview', ['month' => $period->month, 'year' => $period->year, 'tab' => 'drafts']) }}"
                        role="tab"
                        x-bind:aria-selected="activeTab === 'drafts'"
                        x-bind:class="activeTab === 'drafts' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'"
                        class="ml-5 border-b-2 px-1 py-3 text-sm font-semibold transition sm:px-2">
                        {{ __('invoices.billing_page.drafts_created') }} <span class="tabular-nums">{{ $draftRows->count() }}</span>
                    </a>
                </div>

                <div x-show="activeTab === 'pending'" x-cloak>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th scope="col" class="w-12 px-4 py-3 sm:px-5">
                                        <input type="checkbox"
                                            aria-label="{{ __('invoices.billing_page.select_all') }}"
                                            x-bind:disabled="allOccurrences.length === 0"
                                            x-bind:checked="allOccurrences.length > 0 && selectedOccurrences.length === allOccurrences.length"
                                            x-effect="$el.indeterminate = selectedOccurrences.length > 0 && selectedOccurrences.length < allOccurrences.length"
                                            x-on:change="selectedOccurrences = $event.target.checked ? [...allOccurrences] : []"
                                            class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                                    </th>
                                    <th scope="col" class="px-4 py-3 sm:px-5">{{ __('invoices.billing_page.company') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('invoices.billing_page.contract') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('invoices.billing_page.description') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('invoices.billing_page.period') }}</th>
                                    <th scope="col" class="whitespace-nowrap px-4 py-3 text-right">{{ __('invoices.billing_page.subtotal') }}</th>
                                    <th scope="col" class="whitespace-nowrap px-4 py-3 text-right">{{ __('invoices.billing_page.vat') }}</th>
                                    <th scope="col" class="whitespace-nowrap px-4 py-3 text-right">{{ __('invoices.billing_page.total') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse ($pendingRows as $row)
                                    <tr class="text-slate-700">
                                        <td class="px-4 py-3 sm:px-5">
                                            <input type="checkbox"
                                                form="billing-create-drafts-form"
                                                name="selected_occurrences[]"
                                                value="{{ $row['identity'] }}"
                                                aria-label="{{ $row['description'] }} — {{ $row['period'] }}"
                                                @disabled(! $row['eligible_for_draft_creation'])
                                                x-model="selectedOccurrences"
                                                class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-500 disabled:cursor-not-allowed disabled:opacity-50">
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-900 sm:px-5">{{ $row['company'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">{{ $row['contract'] }}</td>
                                        <td class="px-4 py-3">{{ $row['description'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">
                                            {{ $row['period'] }}
                                            @if ($row['queue_status'] === 'pending')
                                                <span class="mt-0.5 block text-xs font-medium text-blue-700">{{ __('invoices.billing_page.row_status.pending') }}</span>
                                            @elseif ($row['queue_status'] === 'scheduled')
                                                <span class="mt-0.5 block whitespace-normal text-xs font-medium text-amber-700">
                                                    {{ __('invoices.billing_page.row_status.scheduled', ['date' => $row['scheduled_billing_date_display']]) }}
                                                </span>
                                            @elseif ($row['queue_status'] === 'missed')
                                                @php
                                                    $missedOccurrences = $row['missed_occurrences'] ?? [];
                                                    $firstMissedOccurrence = $missedOccurrences[0] ?? null;
                                                    $firstMissedPeriod = $firstMissedOccurrence === null
                                                        ? null
                                                        : \Carbon\CarbonImmutable::parse($firstMissedOccurrence['period_start'])
                                                            ->locale(app()->getLocale())
                                                            ->translatedFormat('F Y');
                                                    $firstMissedTargetPeriod = $firstMissedOccurrence === null
                                                        ? null
                                                        : __('invoices.billing_page.missed_popover.target_months.'.$firstMissedOccurrence['month'])
                                                            .' '.$firstMissedOccurrence['year'];
                                                @endphp
                                                @if ($firstMissedOccurrence !== null)
                                                    <span class="group relative mt-0.5 block">
                                                        <a href="{{ route('invoices.billing.preview', ['tab' => 'pending', 'month' => $firstMissedOccurrence['month'], 'year' => $firstMissedOccurrence['year']]) }}"
                                                            aria-describedby="missed-occurrence-{{ md5($row['identity']) }}"
                                                            data-testid="missed-occurrence-link"
                                                            class="block whitespace-normal text-left text-xs font-medium text-rose-700 underline decoration-dotted underline-offset-2 transition hover:text-rose-900 focus:outline-none focus:ring-2 focus:ring-rose-400 focus:ring-offset-2">
                                                            {{ $row['missed_count'] > 1
                                                                ? __('invoices.billing_page.row_status.missed_multiple', ['count' => $row['missed_count']])
                                                                : __('invoices.billing_page.row_status.missed') }}
                                                        </a>
                                                        <span id="missed-occurrence-{{ md5($row['identity']) }}"
                                                            role="tooltip"
                                                            data-testid="missed-occurrence-popover"
                                                            class="pointer-events-none invisible absolute bottom-full left-0 z-20 mb-2 w-72 rounded-md border border-slate-200 bg-white p-3 text-left text-xs font-normal text-slate-700 opacity-0 shadow-lg transition group-hover:visible group-hover:opacity-100 group-focus-within:visible group-focus-within:opacity-100">
                                                            <span class="block font-semibold text-slate-900">
                                                                {{ $row['missed_count'] > 1
                                                                    ? __('invoices.billing_page.missed_popover.title_multiple')
                                                                    : __('invoices.billing_page.missed_popover.title') }}
                                                            </span>
                                                            <span class="mt-2 block space-y-1">
                                                                @foreach ($missedOccurrences as $missedOccurrence)
                                                                    <span class="block whitespace-nowrap">{{ $missedOccurrence['period_display'] }}</span>
                                                                @endforeach
                                                            </span>
                                                            <span class="mt-2 block font-medium text-blue-700">
                                                                {{ $row['missed_count'] > 1
                                                                    ? __('invoices.billing_page.missed_popover.earliest', ['period' => $firstMissedPeriod])
                                                                    : __('invoices.billing_page.missed_popover.navigate', ['period' => $firstMissedTargetPeriod]) }}
                                                            </span>
                                                        </span>
                                                    </span>
                                                @else
                                                    <span class="mt-0.5 block whitespace-normal text-xs font-medium text-rose-700">
                                                        {{ $row['missed_count'] > 1
                                                            ? __('invoices.billing_page.row_status.missed_multiple', ['count' => $row['missed_count']])
                                                            : __('invoices.billing_page.row_status.missed') }}
                                                    </span>
                                                @endif
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ $row['subtotal_display'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ $row['vat_display'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums">{{ $row['total_display'] }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="px-4 py-10 text-center text-sm text-slate-500">
                                            {{ __('invoices.billing_page.empty', ['period' => $period->locale(app()->getLocale())->translatedFormat('F Y')]) }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="flex items-center justify-between gap-3 border-t border-slate-200 px-4 py-4 sm:px-5">
                        <p class="text-sm text-slate-500">
                            <span x-text="selectedOccurrences.length"></span> {{ __('invoices.billing_page.selected') }}
                        </p>
                        <button type="submit" form="billing-create-drafts-form"
                            x-bind:disabled="selectedOccurrences.length === 0"
                            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
                            {{ __('invoices.billing_page.create_drafts') }}
                        </button>
                    </div>
                </div>

                <div x-show="activeTab === 'drafts'" x-cloak>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th scope="col" class="w-12 px-4 py-3 sm:px-5">
                                        @if ($canIssue)
                                            <input type="checkbox"
                                                aria-label="{{ __('invoices.billing_page.select_all') }}"
                                                x-bind:disabled="allInvoices.length === 0"
                                                x-bind:checked="allInvoices.length > 0 && selectedInvoices.length === allInvoices.length"
                                                x-effect="$el.indeterminate = selectedInvoices.length > 0 && selectedInvoices.length < allInvoices.length"
                                                x-on:change="selectedInvoices = $event.target.checked ? [...allInvoices] : []"
                                                class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                                        @endif
                                    </th>
                                    <th scope="col" class="px-4 py-3 sm:px-5">{{ __('invoices.billing_page.company') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('invoices.billing_page.contract') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('invoices.billing_page.description') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('invoices.billing_page.period') }}</th>
                                    <th scope="col" class="whitespace-nowrap px-4 py-3 text-right">{{ __('invoices.billing_page.subtotal') }}</th>
                                    <th scope="col" class="whitespace-nowrap px-4 py-3 text-right">{{ __('invoices.billing_page.vat') }}</th>
                                    <th scope="col" class="whitespace-nowrap px-4 py-3 text-right">{{ __('invoices.billing_page.total') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse ($draftRows as $row)
                                    <x-tables.clickable-row
                                        :url="route('invoices.show', [
                                            'invoice' => $row['invoice_id'],
                                            'billing_preview' => 1,
                                            'month' => $period->month,
                                            'year' => $period->year,
                                            'tab' => 'drafts',
                                        ])"
                                        :label="$row['description'].' — '.$row['period']"
                                        class="text-slate-700 cursor-pointer transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600">
                                        <td class="px-4 py-3 sm:px-5">
                                            @if ($canIssue)
                                                <input type="checkbox"
                                                    form="billing-issue-invoices-form"
                                                    name="selected_invoices[]"
                                                    value="{{ $row['invoice_id'] }}"
                                                    aria-label="{{ $row['description'] }} — {{ $row['period'] }}"
                                                    x-model="selectedInvoices"
                                                    x-on:click.stop
                                                    class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-900 sm:px-5">{{ $row['company'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">{{ $row['contract'] }}</td>
                                        <td class="px-4 py-3">{{ $row['description'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">
                                            {{ $row['period'] }}
                                            <span class="mt-0.5 block text-xs font-medium text-slate-500">{{ __('invoices.billing_page.row_status.draft') }}</span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ $row['subtotal_display'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ $row['vat_display'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums">{{ $row['total_display'] }}</td>
                                    </x-tables.clickable-row>
                                @empty
                                    <tr>
                                        <td colspan="8" class="px-4 py-10 text-center text-sm text-slate-500">
                                            {{ __('invoices.billing_page.empty_drafts', ['period' => $period->locale(app()->getLocale())->translatedFormat('F Y')]) }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="flex items-center justify-between gap-3 border-t border-slate-200 px-4 py-4 sm:px-5">
                        <p class="text-sm text-slate-500">
                            <span x-text="selectedInvoices.length"></span> {{ __('invoices.billing_page.selected') }}
                        </p>
                        @if ($canIssue)
                            <button type="submit" form="billing-issue-invoices-form"
                                x-bind:disabled="selectedInvoices.length === 0"
                                class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
                                {{ __('invoices.billing_page.issue_invoices') }}
                            </button>
                        @endif
                    </div>
                </div>
            </section>
        </div>

    </div>
@endsection
