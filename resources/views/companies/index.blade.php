@extends('layouts.app')

@section('title', 'Компании')

@section('content')
    @php
        $preservedFilters = array_filter([
            'search' => $search,
            'status' => $status,
        ], fn ($value) => $value !== '');
        $sortUrl = function (string $column) use ($sort, $direction, $preservedFilters): string {
            $nextDirection = $sort === $column && $direction === 'asc' ? 'desc' : 'asc';

            return route('companies.index', [
                ...$preservedFilters,
                'sort' => $column,
                'direction' => $nextDirection,
            ]);
        };
        $editContext = array_filter([
            'origin' => 'index',
            'search' => $search,
            'status' => $status,
            'sort' => $sort,
            'direction' => $direction,
            'page' => $companies->currentPage() > 1 ? $companies->currentPage() : null,
        ], fn ($value) => $value !== null && $value !== '');
    @endphp

    <div class="mb-6 flex flex-col sm:flex-row justify-between sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Компании</h1>
            <p class="text-sm text-gray-500 mt-1">Управление клиентами и реквизитами</p>
        </div>
        @can('create', \App\Models\Company::class)
            <a href="{{ route('companies.create') }}"
                class="crm-light-action">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                </svg>
                Добавить компанию
            </a>
        @endcan
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm mb-6">
        <form action="{{ route('companies.index') }}" method="GET"
            class="flex flex-col lg:flex-row lg:items-center gap-4">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">

            <div class="flex-1 relative" x-data="{
                query: @js($search),
                results: [],
                open: false,
                loading: false,
                timer: null,
                requestNumber: 0,
                controller: null,
                endpoint: @js(route('companies.autocomplete')),
                schedule() {
                    clearTimeout(this.timer);
                    this.results = [];
                    if (this.query.trim().length < 2) {
                        this.open = false;
                        this.controller?.abort();
                        return;
                    }
                    this.timer = setTimeout(() => this.search(), 300);
                },
                async search() {
                    const query = this.query.trim();
                    if (query.length < 2) return;
                    this.controller?.abort();
                    this.controller = new AbortController();
                    const requestNumber = ++this.requestNumber;
                    this.loading = true;
                    try {
                        const response = await fetch(`${this.endpoint}?q=${encodeURIComponent(query)}`, {
                            headers: { 'Accept': 'application/json' },
                            signal: this.controller.signal,
                        });
                        if (!response.ok) throw new Error('Autocomplete request failed');
                        const results = await response.json();
                        if (requestNumber === this.requestNumber) {
                            this.results = results;
                            this.open = true;
                        }
                    } catch (error) {
                        if (error.name !== 'AbortError' && requestNumber === this.requestNumber) {
                            this.results = [];
                            this.open = false;
                        }
                    } finally {
                        if (requestNumber === this.requestNumber) this.loading = false;
                    }
                },
                select(company) {
                    this.query = company.name;
                    this.open = false;
                    this.$nextTick(() => this.$root.closest('form').requestSubmit());
                },
                submitOrSelect() {
                    if (this.open && this.results.length > 0) {
                        this.select(this.results[0]);
                    } else {
                        this.$root.closest('form').requestSubmit();
                    }
                },
            }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400 pointer-events-none z-10">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </span>
                <input type="text" name="search" x-model="query" x-on:input="schedule()"
                    x-on:keydown.enter.prevent="submitOrSelect()" x-on:focus="if (results.length) open = true"
                    value="{{ $search }}" autocomplete="off"
                    placeholder="Поиск по названию, краткому имени или VÖEN…"
                    class="crm-control-with-leading-icon w-full pl-10 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">

                <div x-show="open" x-cloak
                    class="absolute z-30 mt-1 w-full overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg">
                    <template x-for="company in results" :key="company.id">
                        <button type="button" x-on:click="select(company)"
                            class="block w-full px-3 py-2.5 text-left hover:bg-blue-50 transition">
                            <span class="block text-sm font-medium text-gray-900" x-text="company.name"></span>
                            <span class="block text-xs text-gray-500 mt-0.5">
                                <span x-text="company.type_label"></span>
                                <template x-if="company.voen">
                                    <span> · VÖEN <span x-text="company.voen"></span></span>
                                </template>
                            </span>
                        </button>
                    </template>
                    <div x-show="!loading && results.length === 0" class="px-3 py-3 text-sm text-gray-400">
                        Компании не найдены
                    </div>
                </div>
            </div>

            <div class="relative w-full lg:w-48" x-data="{
                open: false,
                selectedStatus: @js($status),
                statuses: [
                    { value: '', label: 'Все' },
                    { value: 'active', label: 'Активные' },
                    { value: 'suspended', label: 'Приостановленные' },
                    { value: 'archived', label: 'Архивные' },
                ],
                get selectedLabel() {
                    return this.statuses.find(status => status.value === this.selectedStatus)?.label ?? 'Все';
                },
                selectStatus(status) {
                    this.selectedStatus = status.value;
                    this.open = false;
                    this.$nextTick(() => this.$root.closest('form').requestSubmit());
                },
            }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
                <input type="hidden" name="status" x-model="selectedStatus">
                <button type="button" x-on:click="open = !open"
                    class="relative w-full px-3 py-2 pr-10 border border-gray-200 rounded-lg bg-white text-left text-sm text-gray-700 hover:border-gray-300 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                    <span x-text="selectedLabel"
                        :class="selectedStatus ? 'crm-filter-selected' : 'crm-filter-neutral'"></span>
                    <svg class="absolute right-3 top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
                <div x-show="open" x-cloak
                    class="absolute z-30 mt-1 w-full overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg">
                    <template x-for="statusOption in statuses" :key="statusOption.value">
                        <button type="button" x-on:click="selectStatus(statusOption)"
                            class="flex w-full items-center justify-between px-3 py-2.5 text-left text-sm transition hover:bg-blue-50 hover:text-blue-700"
                            :class="statusOption.value === selectedStatus ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-700'">
                            <span x-text="statusOption.label"></span>
                            <svg x-show="statusOption.value === selectedStatus" class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                            </svg>
                        </button>
                    </template>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row gap-2">
                <button type="submit"
                    class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition">
                    Найти
                </button>
                @if ($search !== '' || $status !== '' || request()->filled('sort') || request()->filled('direction'))
                    <a href="{{ route('companies.index') }}"
                        class="px-4 py-2 border border-gray-200 hover:bg-gray-50 text-gray-500 text-sm font-medium rounded-lg transition text-center">
                        Сбросить
                    </a>
                @endif
            </div>
        </form>
    </div>

    <div class="crm-table-shell">
        <div class="crm-table-heading">
            <span class="crm-table-heading-title">Компании</span>
            <span class="crm-table-heading-count">{{ $companies->total() }}</span>
        </div>
        <div class="crm-table-scroll">
            <table class="crm-table">
                <thead>
                    <tr>
                        <th>
                            <a href="{{ $sortUrl('name') }}" class="crm-table-sort">
                                Компания / тип
                                <span class="crm-table-sort-indicator {{ $sort === 'name' ? 'crm-table-sort-indicator-active' : '' }}">{{ $sort === 'name' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                            </a>
                        </th>
                        <th>VÖEN</th>
                        <th>Контакты</th>
                        @if ($canViewFinancials)
                            <th data-testid="company-debt-column">
                                <a href="{{ $sortUrl('debt') }}" class="crm-table-sort">
                                    Долг
                                    <span class="crm-table-sort-indicator {{ $sort === 'debt' ? 'crm-table-sort-indicator-active' : '' }}">{{ $sort === 'debt' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                                </a>
                            </th>
                        @endif
                        <th>Статус</th>
                        <th class="crm-table-actions"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($companies as $company)
                        <x-tables.clickable-row :url="route('companies.show', ['company' => $company, 'return_url' => $companyIndexReturnUrl])" :label="'Открыть компанию '.$company->name">
                            <td>
                                <a href="{{ route('companies.show', $company) }}"
                                    class="crm-table-primary-link">
                                    {{ $company->name }}
                                </a>
                                <div class="crm-table-secondary mt-0.5">
                                    {{ $company->type === 'company' ? 'Юридическое лицо' : 'Индивидуальный предприниматель' }}
                                    @if ($company->short_name)
                                        <span class="text-slate-300 mx-1">|</span>{{ $company->short_name }}
                                    @endif
                                </div>
                            </td>
                            <td class="crm-table-number">{{ $company->voen ?? '—' }}</td>
                            <td>
                                <div class="text-slate-700 text-xs">{{ $company->email ?? '—' }}</div>
                                <div class="crm-table-secondary mt-0.5">{{ $company->phone ?? '—' }}</div>
                            </td>
                            @if ($canViewFinancials)
                                <td data-testid="company-debt-value" class="crm-table-numeric">
                                    <span class="{{ $company->total_debt > 0 ? 'text-red-600 font-semibold' : 'text-slate-400 font-medium' }}">
                                        {{ number_format($company->total_debt, 2) }} ₼
                                    </span>
                                </td>
                            @endif
                            <td>
                                @include('partials.badge', [
                                    'status' => $company->status,
                                    'label' => match ($company->status) {
                                        'active' => 'Активна',
                                        'suspended' => 'Приостановлена',
                                        'archived' => 'В архиве',
                                        default => $company->status,
                                    },
                                ])
                            </td>
                            <td class="crm-table-actions">
                                <div class="flex items-center justify-end gap-3">
                                    @can('update', $company)
                                        <a href="{{ route('companies.edit', ['company' => $company, ...$editContext]) }}"
                                            class="text-gray-400 hover:text-gray-600 transition" title="Редактировать">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                            </svg>
                                        </a>
                                    @endcan
                                </div>
                            </td>
                        </x-tables.clickable-row>
                    @empty
                        <tr>
                            <td colspan="{{ $canViewFinancials ? 6 : 5 }}" class="crm-table-empty">
                                <span class="crm-table-empty-message">Компаний не найдено.</span>
                                @if ($search !== '' || $status !== '')
                                    <a href="{{ route('companies.index') }}" class="crm-table-empty-action">Сбросить фильтры</a>
                                @else
                                    @can('create', \App\Models\Company::class)
                                        <a href="{{ route('companies.create') }}" class="crm-table-empty-action">Добавить первую компанию</a>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($companies->hasPages())
            <div class="crm-table-footer">
                {{ $companies->links() }}
            </div>
        @endif
    </div>
@endsection
