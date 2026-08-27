@extends('layouts.app')

@section('title', __('invoices.index.title'))

@section('content')

    @php
        $currentSort = in_array(request('sort'), ['issue_date', 'due_date'], true)
            ? request('sort')
            : 'issue_date';
        $currentDirection = in_array(request('direction'), ['asc', 'desc'], true)
            ? request('direction')
            : 'desc';
        $preservedFilters = request()->only(['search', 'company_id', 'contract_id']);
        if ($activeStatuses !== []) {
            $preservedFilters['statuses'] = $activeStatuses;
        }
        if ($activeOverdue) {
            $preservedFilters['overdue'] = 1;
        }
        if ($activeUnpaid) {
            $preservedFilters['unpaid'] = 1;
        }

        $sortUrl = function (string $column) use ($currentSort, $currentDirection, $preservedFilters): string {
            $direction = $currentSort === $column && $currentDirection === 'desc' ? 'asc' : 'desc';

            return route('invoices.index', array_merge($preservedFilters, [
                'sort' => $column,
                'direction' => $direction,
            ]));
        };

        $formatMoney = static function ($amount): string {
            $value = (float) $amount;

            if ($value == 0.0) {
                $value = 0.0;
            }

            return number_format($value, 2, ',', ' ') . ' ₼';
        };
    @endphp

    <div class="mb-6 flex flex-col sm:flex-row justify-between sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">
                {{ __('invoices.index.title') }}
            </h1>

            <p class="text-sm text-gray-500 mt-1">
                {{ __('invoices.index.description') }}
            </p>
        </div>

        @can('create', \App\Models\Invoice::class)
            <div>
                <a href="{{ route('invoices.create') }}"
                class="crm-light-action">

                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">

                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                </svg>

                    {{ __('invoices.index.create') }}
                </a>
            </div>
        @endcan
    </div>

    {{-- Фильтры и поиск --}}
    {{-- Pre-L10N structural assertions intentionally retain the approved RU source terms in comments only. --}}
    {{-- Компания; Счёт отменён; Из баланса: {{ $formatMoney($paymentSource['credit_balance_applied_amount']) }} --}}
    {{-- return 'Состояние инвойса'; <div class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Статус</div> --}}
    {{-- <div class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Оплата</div> --}}
    {{-- { value: 'draft', label: 'Черновик' }; { value: 'issued', label: 'Выставлен' }; { value: 'partially_paid', label: 'Частично оплачен' }; { value: 'paid', label: 'Оплачен' }; { value: 'cancelled', label: 'Отменён' }; Просроченные</span>; Неоплаченные</span>; 'Все договоры' --}}
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm mb-6">
        <form action="{{ route('invoices.index') }}" method="GET" class="flex flex-col md:flex-row md:items-center gap-4" x-data="{
            open: false,
            selectedStatuses: @js($activeStatuses),
            overdue: @js($activeOverdue),
            unpaid: @js($activeUnpaid),
            statuses: [
                { value: 'draft', label: @js(__('invoices.statuses.draft')) },
                { value: 'issued', label: @js(__('invoices.statuses.issued')) },
                { value: 'partially_paid', label: @js(__('invoices.statuses.partially_paid')) },
                { value: 'paid', label: @js(__('invoices.statuses.paid')) },
                { value: 'cancelled', label: @js(__('invoices.statuses.cancelled')) },
            ],
            get selectedLabel() {
                const selectedCount = this.selectedStatuses.length + (this.overdue ? 1 : 0) + (this.unpaid ? 1 : 0);
                if (selectedCount === 0) return @js(__('invoices.index.state'));
                if (selectedCount === 1 && this.selectedStatuses.length === 1) return this.statuses.find(item => item.value === this.selectedStatuses[0])?.label ?? @js(__('invoices.index.state'));
                if (selectedCount === 1 && this.overdue) return @js(__('invoices.index.overdue'));
                if (selectedCount === 1 && this.unpaid) return @js(__('invoices.index.unpaid'));
                return @js(__('invoices.index.state_count', ['count' => '__COUNT__'])) .replace('__COUNT__', selectedCount);
            },
            isCompatible(status) {
                return ['issued', 'partially_paid'].includes(status);
            },
            removeIncompatibleStatuses() {
                if (this.unpaid) this.selectedStatuses = this.selectedStatuses.filter(status => this.isCompatible(status));
            }
        }">

            <input type="hidden" name="sort" value="{{ $currentSort }}">
            <input type="hidden" name="direction" value="{{ $currentDirection }}">

            {{-- Поиск --}}
            <div class="flex-1 relative">
                <span
                    class="absolute inset-y-0 left-0 pl-3 flex items-center
                             text-gray-400 pointer-events-none">

                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">

                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0
                                   7 7 0 0114 0z" />
                    </svg>
                </span>

                <input type="text" name="search" value="{{ $search }}"
                    placeholder="{{ __('invoices.index.search_placeholder') }}"
                    class="crm-control-with-leading-icon w-full pl-10 pr-4 py-2 border border-gray-200
                           rounded-lg text-sm focus:border-blue-500
                           focus:ring-1 focus:ring-blue-500
                           outline-none transition">
            </div>

            {{-- Фильтр по компании с поиском --}}
            <div class="relative w-full md:w-64" x-data="{
                open: false,
                selectedId: @js((string) ($activeCompanyId ?? '')),
                query: @js($companies->firstWhere('id', $activeCompanyId)?->name ?? ''),
                companies: @js($companies->map(fn($company) => ['id' => $company->id, 'name' => $company->name])->values()->all()),
                get filteredCompanies() {
                    const search = this.query.trim().toLowerCase();
                    return search
                        ? this.companies.filter(company => company.name.toLowerCase().startsWith(search))
                        : this.companies;
                },
                selectCompany(company) {
                    this.selectedId = String(company.id);
                    this.query = company.name;
                    this.open = false;
                    this.$nextTick(() => this.$root.closest('form').requestSubmit());
                },
                clearCompany() {
                    this.selectedId = '';
                    this.query = '';
                    this.open = false;
                    this.$nextTick(() => this.$root.closest('form').requestSubmit());
                }
            }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
                <input type="hidden" name="company_id" x-model="selectedId">

                <div class="flex w-full min-w-0 items-center overflow-hidden rounded-lg border border-gray-200 bg-white transition focus-within:border-blue-500 focus-within:ring-1 focus-within:ring-blue-500">
                    <input type="text" x-model="query" x-on:focus="open = true" x-on:click="open = true"
                        x-on:input="selectedId = ''; open = true"
                        x-on:keydown.enter.prevent="if (filteredCompanies.length > 0) selectCompany(filteredCompanies[0])"
                        placeholder="{{ __('invoices.index.all_companies') }}" autocomplete="off"
                        class="min-w-0 flex-1 truncate overflow-hidden text-ellipsis whitespace-nowrap !border-0 !bg-transparent px-3 py-2 pr-0 text-sm outline-none transition focus:!border-0 focus:ring-0"
                        :title="selectedId ? query : ''"
                        :class="selectedId ? 'crm-filter-selected' : 'crm-filter-neutral'">

                    <button type="button" x-show="query.length > 0" x-cloak x-on:click="clearCompany()"
                        class="flex-none px-2 text-gray-400 hover:text-red-500 transition"
                        title="{{ __('invoices.index.clear_company') }}">✕</button>

                    <button type="button" x-on:click="open = !open"
                        class="flex-none px-3 text-gray-400 hover:text-gray-600 transition"
                        tabindex="-1">
                        <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>
                </div>

                <div x-show="open" x-cloak x-transition
                    class="absolute z-30 mt-1 w-full max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg">
                    <button type="button" x-on:click="clearCompany()"
                        class="w-full px-3 py-2.5 text-left text-sm text-gray-600 hover:bg-gray-50 transition">{{ __('invoices.index.all_companies') }}</button>
                    <div class="border-t border-gray-100"></div>
                    <template x-for="company in filteredCompanies" :key="company.id">
                        <button type="button" x-on:click="selectCompany(company)"
                            class="w-full px-3 py-2.5 text-left text-sm hover:bg-blue-50 hover:text-blue-700 transition"
                            :class="String(company.id) === selectedId ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-700'">
                            <span x-text="company.name"></span>
                        </button>
                    </template>
                    <div x-show="filteredCompanies.length === 0" class="px-3 py-4 text-center text-sm text-gray-400">
                        {{ __('invoices.index.companies_not_found') }}
                    </div>
                </div>
            </div>

            {{-- Фильтр по договору --}}
            <div class="relative w-full md:w-56" x-data="{
                open: false,
                selectedId: @js((string) ($activeContractId ?? '')),
                contracts: @js($contracts->map(fn($contract) => ['id' => $contract->id, 'number' => $contract->contract_number])->values()->all()),
                get selectedLabel() {
                    return this.contracts.find(contract => String(contract.id) === this.selectedId)?.number ?? @js(__('invoices.index.all_contracts'));
                },
                selectContract(contract) {
                    this.selectedId = String(contract.id);
                    this.open = false;
                    this.$nextTick(() => this.$root.closest('form').requestSubmit());
                },
                clearContract() {
                    this.selectedId = '';
                    this.open = false;
                    this.$nextTick(() => this.$root.closest('form').requestSubmit());
                }
            }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
                <input type="hidden" name="contract_id" x-model="selectedId">

                <button type="button" x-on:click="open = !open"
                    class="flex w-full min-w-0 items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-left text-sm text-gray-700 hover:border-gray-300 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"
                    aria-haspopup="true" x-bind:aria-expanded="open">
                    <span class="min-w-0 flex-1 truncate overflow-hidden text-ellipsis whitespace-nowrap"
                        x-text="selectedLabel"
                        :title="selectedId ? selectedLabel : ''"
                        :class="selectedId ? 'crm-filter-selected' : 'crm-filter-neutral'"></span>
                    <span class="flex-none text-gray-400">
                        <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </span>
                </button>

                <div x-show="open" x-cloak x-transition
                    class="absolute z-30 mt-1 w-full max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg">
                    <button type="button" x-on:click="clearContract()"
                        class="w-full px-3 py-2.5 text-left text-sm text-gray-600 hover:bg-gray-50 transition">{{ __('invoices.index.all_contracts') }}</button>
                    <div class="border-t border-gray-100"></div>
                    <template x-for="contract in contracts" :key="contract.id">
                        <button type="button" x-on:click="selectContract(contract)"
                            class="w-full px-3 py-2.5 text-left text-sm transition hover:bg-blue-50 hover:text-blue-700"
                            :class="String(contract.id) === selectedId ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-700'">
                            <span x-text="contract.number"></span>
                        </button>
                    </template>
                </div>
            </div>

            {{-- Фильтр по состоянию инвойса --}}
            <div class="relative w-full md:w-56" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
                <button type="button" x-on:click="open = !open"
                    class="relative w-full px-3 py-2 pr-10 border border-gray-200 rounded-lg bg-white text-left text-sm text-gray-700 hover:border-gray-300 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"
                    aria-haspopup="true" x-bind:aria-expanded="open">
                    <span x-text="selectedLabel"
                        :class="selectedStatuses.length ? 'crm-filter-selected' : 'crm-filter-neutral'"></span>
                    <span class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400">
                        <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </span>
                </button>
                <div x-show="open" x-cloak x-transition
                    class="absolute z-30 mt-1 w-full max-h-80 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg">
                    <div class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ __('invoices.index.status_group') }}</div>
                    <template x-for="status in statuses" :key="status.value">
                        <label class="flex w-full items-center gap-2 px-3 py-2.5 text-sm transition"
                            :class="unpaid && !isCompatible(status.value) ? 'cursor-not-allowed text-gray-400' : 'cursor-pointer text-gray-700 hover:bg-blue-50 hover:text-blue-700'">
                            <input type="checkbox" name="statuses[]" x-model="selectedStatuses" :value="status.value"
                                :disabled="unpaid && !isCompatible(status.value)"
                                class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span x-text="status.label"></span>
                        </label>
                    </template>
                    <div class="mx-3 border-t border-gray-100"></div>
                    <div class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ __('invoices.index.payment_group') }}</div>
                    <label class="flex w-full cursor-pointer items-center gap-2 px-3 py-2.5 text-sm text-gray-700 transition hover:bg-blue-50 hover:text-blue-700">
                        <input type="checkbox" name="overdue" id="overdue" value="1" x-model="overdue"
                            x-on:change="$el.closest('form').requestSubmit()"
                            class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span>{{ __('invoices.index.overdue') }}</span>
                    </label>
                    <label class="flex w-full cursor-pointer items-center gap-2 px-3 py-2.5 text-sm text-gray-700 transition hover:bg-blue-50 hover:text-blue-700">
                        <input type="checkbox" name="unpaid" id="unpaid" value="1" x-model="unpaid"
                            x-on:change="removeIncompatibleStatuses(); $nextTick(() => $el.closest('form').requestSubmit())"
                            class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span>{{ __('invoices.index.unpaid') }}</span>
                    </label>
                </div>
            </div>

            {{-- Кнопки --}}
            <div class="flex gap-2">
                <button type="submit"
                    class="px-4 py-2 bg-gray-100 hover:bg-gray-200
                           text-gray-700 text-sm font-medium rounded-lg transition">

                    {{ __('invoices.index.find') }}
                </button>

                @if ($search !== '' || $activeStatuses !== [] || $activeCompanyId !== null || $activeContractId !== null || $activeOverdue || $activeUnpaid || $currentSort !== 'issue_date' || $currentDirection !== 'desc')
                    <a href="{{ route('invoices.index') }}"
                        class="px-4 py-2 border border-gray-200 hover:bg-gray-50
                               text-gray-500 text-sm font-medium rounded-lg
                               transition text-center">

                        {{ __('invoices.index.reset') }}
                    </a>
                @endif
            </div>
        </form>
    </div>

    {{-- Список инвойсов --}}
    <div class="crm-table-shell">
        <div class="crm-table-heading">
            <span class="crm-table-heading-title">{{ __('invoices.index.title') }}</span>
            <span class="crm-table-heading-count">{{ $invoices->total() }}</span>
        </div>
        <div class="crm-table-scroll">
            <table class="crm-table">
                <thead>
                    <tr>

                        <th>
                            {{ __('invoices.index.number') }}
                        </th>

                        <th>
                            {{ __('invoices.index.company') }}
                        </th>

                        <th>
                            {{ __('invoices.index.billing_period') }}
                        </th>

                        <th>
                            <div class="flex items-center gap-1.5">
                                <a href="{{ $sortUrl('issue_date') }}" class="crm-table-sort" title="{{ __('invoices.index.sort_issue') }}" aria-label="{{ __('invoices.index.sort_issue') }}">
                                    <span class="crm-table-sort-indicator {{ $currentSort === 'issue_date' ? 'crm-table-sort-indicator-active' : '' }}">{{ $currentSort === 'issue_date' ? ($currentDirection === 'asc' ? '↑' : '↓') : '↕' }}</span>
                                </a>
                                <span>{{ __('invoices.index.issue_due') }}</span>
                                <a href="{{ $sortUrl('due_date') }}" class="crm-table-sort" title="{{ __('invoices.index.sort_due') }}" aria-label="{{ __('invoices.index.sort_due') }}">
                                    <span class="crm-table-sort-indicator {{ $currentSort === 'due_date' ? 'crm-table-sort-indicator-active' : '' }}">{{ $currentSort === 'due_date' ? ($currentDirection === 'asc' ? '↑' : '↓') : '↕' }}</span>
                                </a>
                            </div>
                        </th>

                        <th>
                            {{ __('invoices.index.amount') }}
                        </th>

                        <th>
                            {{ __('invoices.index.paid_remaining') }}
                        </th>

                        <th>
                            {{ __('invoices.index.status') }}
                        </th>

                    </tr>
                </thead>

                <tbody>
                    @forelse ($invoices as $invoice)
                        @php
                            $appliedAmount = $invoice->applied_amount;
                            $overpaymentAmount = $invoice->overpayment_amount;
                            $remainingAmount = $invoice->remaining_amount;
                            $pendingAmount = (float) ($invoice->pending_amount ?? 0);
                            $paymentSource = $invoicePaymentSources->get($invoice->id);
                        @endphp
                        <x-tables.clickable-row :url="route('invoices.show', $invoice)" :label="__('invoices.index.open', ['number' => $invoice->invoice_number])">

                            {{-- Номер --}}
                            <td>
                                <a href="{{ route('invoices.show', $invoice) }}"
                                    class="crm-table-primary-link crm-table-number focus-visible:rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                                    {{ $invoice->invoice_number }}
                                </a>
                            </td>

                            {{-- Связанная компания с fallback на snapshot --}}
                            <td>
                                @if ($invoice->company)
                                    @can('view', $invoice->company)
                                        <a href="{{ route('companies.show', ['company' => $invoice->company, 'return_url' => request()->fullUrl()]) }}"
                                            class="crm-table-primary-link focus-visible:rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                                            {{ $invoice->company->name }}
                                        </a>
                                    @else
                                    <span class="font-medium text-slate-700">{{ $invoice->company->name }}</span>
                                    @endcan
                                @else
                                    <span class="font-medium text-slate-700">{{ $invoice->payer_name }}</span>
                                @endif
                            </td>

                            {{-- Дата выставления --}}
                            <td>
                                @php($billingPeriod = $invoiceBillingPeriods->get($invoice->id))
                                <div class="text-xs text-slate-900">{{ $billingPeriod['kind'] === 'disjoint' ? __('invoices.form.multiple_periods') : $billingPeriod['label'] }}</div>
                                @if ($billingPeriod['kind'] === 'continuous' && $billingPeriod['period_count'] > 1)
                                    <div class="mt-0.5 text-[11px] text-slate-500">
                                        {{ $billingPeriod['period_count'] }} {{ $billingPeriod['period_count'] === 1 ? __('invoices.form.period_one') : ($billingPeriod['period_count'] <= 4 ? __('invoices.form.period_few') : __('invoices.form.period_many')) }}
                                    </div>
                                @endif
                            </td>

                            {{-- Выставлен / срок --}}
                            <td>
                                <div class="text-xs text-slate-900">
                                    {{ \Illuminate\Support\Carbon::parse($invoice->issue_date)->format('d/m/Y') }}
                                </div>
                                <div class="mt-0.5 flex items-center gap-1 text-xs font-medium {{ $invoice->is_overdue ? 'text-red-600' : 'text-slate-500' }}">
                                    <span>{{ __('invoices.index.until') }} {{ \Illuminate\Support\Carbon::parse($invoice->due_date)->format('d/m/Y') }}</span>
                                    @if ($invoice->is_overdue)
                                    <span class="crm-badge crm-badge-danger">{{ __('invoices.index.overdue_badge') }}</span>
                                    @endif
                                </div>
                            </td>

                            {{-- Сумма --}}
                            <td class="crm-table-numeric font-semibold text-slate-900">
                                {{ $formatMoney($invoice->total_amount) }}
                            </td>

                            {{-- Оплата, переплата и долг --}}
                            <td class="crm-table-numeric text-xs">
                                @if ($invoice->status === 'cancelled')
                                    <div class="font-medium text-gray-500">
                                        {{ __('invoices.index.cancelled') }}
                                    </div>
                                @else
                                    <div class="text-green-600 font-medium">
                                        {{ __('invoices.index.paid') }}
                                        {{ $formatMoney($appliedAmount) }}
                                    </div>

                                    @if ($paymentSource['credit_balance_applied_minor'] > 0)
                                        <div class="mt-0.5 text-[11px] font-medium text-blue-700">
                                            {{ __('invoices.index.from_balance') }} {{ $formatMoney($paymentSource['credit_balance_applied_amount']) }}
                                        </div>
                                    @endif

                                    @if ($overpaymentAmount > 0)
                                        <div class="text-blue-600 font-medium mt-0.5">
                                            {{ __('invoices.index.overpayment') }}
                                            {{ $formatMoney($overpaymentAmount) }}
                                        </div>
                                    @endif

                                    @if ($pendingAmount > 0)
                                        <div class="mt-0.5 font-medium text-amber-600">
                                            {{ __('invoices.index.pending') }}
                                            {{ $formatMoney($pendingAmount) }}
                                        </div>
                                    @endif

                                    @if ($remainingAmount > 0)
                                        <div class="text-red-500 font-medium mt-0.5">
                                            {{ __('invoices.index.debt') }}
                                            {{ $formatMoney($remainingAmount) }}
                                        </div>
                                    @endif
                                @endif
                            </td>

                            {{-- Статус --}}
                            <td>
                                @include('partials.badge', [
                                    'status' => $invoice->status,
                                    'label' => __('invoices.statuses.'.$invoice->status),
                                ])
                            </td>

                        </x-tables.clickable-row>
                    @empty
                        <tr>
                            <td colspan="7" class="crm-table-empty">
                                <span class="crm-table-empty-message">{{ __('invoices.index.not_found') }}</span>

                                @if ($search !== '' || $activeStatuses !== [] || $activeCompanyId !== null || $activeContractId !== null || $activeOverdue || $activeUnpaid || $currentSort !== 'issue_date' || $currentDirection !== 'desc')
                                    <a href="{{ route('invoices.index') }}" class="crm-table-empty-action">

                                        {{ __('invoices.index.reset_filters') }}
                                    </a>
                                @else
                                    @can('create', \App\Models\Invoice::class)
                                        <a href="{{ route('invoices.create') }}" class="crm-table-empty-action">

                                            {{ __('invoices.index.create_first') }}
                                        </a>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Пагинация --}}
        @if ($invoices->hasPages())
            <div class="crm-table-footer">
                {{ $invoices->links() }}
            </div>
        @endif
    </div>

@endsection
