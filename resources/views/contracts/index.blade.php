@extends('layouts.app')

@section('title', 'Договоры')

@section('content')

    @php
        $currentSortBy = request('sort_by', 'start_date');
        $currentSortDirection = request('sort_direction', 'desc');

        $startSortDirection = $currentSortBy === 'start_date' && $currentSortDirection === 'desc' ? 'asc' : 'desc';

        $endSortDirection = $currentSortBy === 'end_date' && $currentSortDirection === 'desc' ? 'asc' : 'desc';

        $preservedFilters = request()->except(['page', 'sort_by', 'sort_direction']);

        $startSortUrl = route(
            'contracts.index',
            array_merge($preservedFilters, [
                'sort_by' => 'start_date',
                'sort_direction' => $startSortDirection,
            ]),
        );

        $endSortUrl = route(
            'contracts.index',
            array_merge($preservedFilters, [
                'sort_by' => 'end_date',
                'sort_direction' => $endSortDirection,
            ]),
        );
    @endphp

    {{-- Заголовок страницы --}}
    <div class="mb-6 flex flex-col sm:flex-row justify-between sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">
                Договоры
            </h1>

            <p class="text-sm text-gray-500 mt-1">
                Управление договорами компаний, сроками действия и статусами
            </p>
        </div>

        <div>
            <a href="{{ route('contracts.create') }}"
                class="inline-flex items-center text-sm bg-blue-600 hover:bg-blue-700
                      text-white px-4 py-2.5 rounded-lg transition shadow-sm font-medium">

                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">

                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                </svg>

                Создать договор
            </a>
        </div>
    </div>

    {{-- Фильтры и поиск --}}
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm mb-6">

        <form action="{{ route('contracts.index') }}" method="GET"
            class="flex flex-col md:flex-row md:items-center gap-4">

            {{-- Сохраняем сортировку при поиске и фильтрации --}}
            <input type="hidden" name="sort_by" value="{{ request('sort_by', 'start_date') }}">

            <input type="hidden" name="sort_direction" value="{{ request('sort_direction', 'desc') }}">

            {{-- Поиск --}}
            <div class="flex-1 relative">

                <span
                    class="absolute inset-y-0 left-0 pl-3 flex items-center
                             text-gray-400 pointer-events-none">

                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">

                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0
                                                                                                                                                 7 7 0 0114 0z" />
                    </svg>
                </span>

                <input type="text" name="search" value="{{ request('search') }}"
                    placeholder="Поиск по номеру договора или компании..."
                    class="w-full pl-10 pr-4 py-2 border border-gray-200
                              rounded-lg text-sm focus:border-blue-500
                              focus:ring-1 focus:ring-blue-500
                              outline-none transition">
            </div>

            {{-- Фильтр по компании с поиском --}}
            <div class="relative w-full md:w-64" x-data="{
                open: false,
            
                selectedId: @js((string) request('company_id', '')),
            
                query: @js($companies->firstWhere('id', (int) request('company_id'))?->name ?? ''),
            
                companies: @js(
    $companies
        ->map(
            fn($company) => [
                'id' => $company->id,
                'name' => $company->name,
            ],
        )
        ->values()
        ->all(),
),
            
                get filteredCompanies() {
                    const search = this.query
                        .trim()
                        .toLowerCase();
            
                    if (!search) {
                        return this.companies;
                    }
            
                    return this.companies.filter(company =>
                        company.name
                        .toLowerCase()
                        .startsWith(search)
                    );
                },
            
                selectCompany(company) {
                    this.selectedId = String(company.id);
                    this.query = company.name;
                    this.open = false;
            
                    this.$nextTick(() => {
                        this.$root
                            .closest('form')
                            .requestSubmit();
                    });
                },
            
                clearCompany() {
                    this.selectedId = '';
                    this.query = '';
                    this.open = false;
            
                    this.$nextTick(() => {
                        this.$root
                            .closest('form')
                            .requestSubmit();
                    });
                }
            }" x-on:click.outside="open = false"
                x-on:keydown.escape.window="open = false">

                {{-- Реальное значение для Laravel --}}
                <input type="hidden" name="company_id" x-model="selectedId">

                <div class="relative">
                    <input type="text" x-model="query" x-on:focus="open = true" x-on:click="open = true"
                        x-on:input="
                selectedId = '';
                open = true;
            "
                        x-on:keydown.enter.prevent="
                if (filteredCompanies.length > 0) {
                    selectCompany(filteredCompanies[0]);
                }
            "
                        placeholder="Все компании" autocomplete="off"
                        class="w-full px-3 py-2 pr-16 border border-gray-200
                   rounded-lg text-sm focus:border-blue-500
                   focus:ring-1 focus:ring-blue-500
                   outline-none transition">

                    {{-- Очистить --}}
                    <button type="button" x-show="query.length > 0" x-cloak x-on:click="clearCompany()"
                        class="absolute inset-y-0 right-8 flex items-center
                   px-2 text-gray-400 hover:text-red-500 transition"
                        title="Сбросить компанию">

                        ✕
                    </button>

                    {{-- Стрелка списка --}}
                    <button type="button" x-on:click="open = !open"
                        class="absolute inset-y-0 right-0 flex items-center
                   px-3 text-gray-400 hover:text-gray-600 transition"
                        tabindex="-1">

                        <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': open }" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">

                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>
                </div>

                {{-- Выпадающий список --}}
                <div x-show="open" x-cloak x-transition
                    class="absolute z-30 mt-1 w-full max-h-64 overflow-y-auto
               rounded-lg border border-gray-200 bg-white shadow-lg">

                    {{-- Все компании --}}
                    <button type="button" x-on:click="clearCompany()"
                        class="w-full px-3 py-2.5 text-left text-sm
                   text-gray-600 hover:bg-gray-50 transition">

                        Все компании
                    </button>

                    <div class="border-t border-gray-100"></div>

                    <template x-for="company in filteredCompanies" :key="company.id">

                        <button type="button" x-on:click="selectCompany(company)"
                            class="w-full px-3 py-2.5 text-left text-sm
                       hover:bg-blue-50 hover:text-blue-700 transition"
                            :class="String(company.id) === selectedId ?
                                'bg-blue-50 text-blue-700 font-medium' :
                                'text-gray-700'">

                            <span x-text="company.name"></span>
                        </button>
                    </template>

                    {{-- Ничего не найдено --}}
                    <div x-show="filteredCompanies.length === 0" class="px-3 py-4 text-center text-sm text-gray-400">

                        Компании не найдены
                    </div>
                </div>
            </div>

            {{-- Фильтр по статусу --}}
            <div class="relative w-full md:w-44" x-data="{
                open: false,
            
                selectedStatus: @js((string) request('status', '')),
            
                statuses: [{
                        value: '',
                        label: 'Все статусы',
                    },
                    {
                        value: 'active',
                        label: 'Активный',
                    },
                    {
                        value: 'expired',
                        label: 'Истёк',
                    },
                    {
                        value: 'terminated',
                        label: 'Расторгнут',
                    },
                ],
            
                get selectedLabel() {
                    const status = this.statuses.find(
                        item => item.value === this.selectedStatus
                    );
            
                    return status ?
                        status.label :
                        'Все статусы';
                },
            
                selectStatus(status) {
                    this.selectedStatus = status.value;
                    this.open = false;
            
                    this.$nextTick(() => {
                        this.$root
                            .closest('form')
                            .requestSubmit();
                    });
                }
            }" x-on:click.outside="open = false"
                x-on:keydown.escape.window="open = false">

                {{-- Значение, отправляемое в Laravel --}}
                <input type="hidden" name="status" x-model="selectedStatus">

                {{-- Поле выбора --}}
                <button type="button" x-on:click="open = !open"
                    class="relative w-full px-3 py-2 pr-10
               border border-gray-200 rounded-lg bg-white
               text-left text-sm text-gray-700
               hover:border-gray-300
               focus:border-blue-500 focus:ring-1 focus:ring-blue-500
               outline-none transition">

                    <span x-text="selectedLabel"
                        :class="selectedStatus
                            ?
                            'text-gray-700' :
                            'text-gray-400'">
                    </span>

                    <span class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400">
                        <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': open }" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">

                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </span>
                </button>

                {{-- Выпадающее окно --}}
                <div x-show="open" x-cloak x-transition
                    class="absolute z-30 mt-1 w-full overflow-hidden
               rounded-lg border border-gray-200
               bg-white shadow-lg">

                    <template x-for="status in statuses" :key="status.value">

                        <button type="button" x-on:click="selectStatus(status)"
                            class="flex w-full items-center justify-between
                       px-3 py-2.5 text-left text-sm transition
                       hover:bg-blue-50 hover:text-blue-700"
                            :class="status.value === selectedStatus ?
                                'bg-blue-50 text-blue-700 font-medium' :
                                'text-gray-700'">

                            <span x-text="status.label"></span>

                            {{-- Отметка выбранного статуса --}}
                            <svg x-show="status.value === selectedStatus" class="w-4 h-4 text-blue-600" fill="none"
                                stroke="currentColor" viewBox="0 0 24 24">

                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                    d="M5 13l4 4L19 7" />
                            </svg>
                        </button>
                    </template>
                </div>
            </div>

            {{-- Кнопки --}}
            <div class="flex gap-2">

                <button type="submit"
                    class="px-4 py-2 bg-gray-100 hover:bg-gray-200
                               text-gray-700 text-sm font-medium rounded-lg
                               transition">
                    Найти
                </button>

                @if (request('search') || request('status') || request('company_id') || request('sort_by') || request('sort_direction'))
                    <a href="{{ route('contracts.index') }}"
                        class="px-4 py-2 border border-gray-200 hover:bg-gray-50
                              text-gray-500 text-sm font-medium rounded-lg
                              transition text-center">
                        Сбросить
                    </a>
                @endif
            </div>
        </form>
    </div>

    {{-- Список договоров --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">

        <div class="overflow-x-auto">

            <table class="w-full text-sm">

                <thead>
                    <tr class="border-b border-gray-200 text-left
                               bg-gray-50 text-gray-500">

                        <th
                            class="px-6 py-3.5 text-xs font-semibold
                                   uppercase tracking-wider">
                            Номер договора
                        </th>

                        <th
                            class="px-6 py-3.5 text-xs font-semibold
                                   uppercase tracking-wider">
                            Компания
                        </th>

                        <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">
                            <a href="{{ $startSortUrl }}"
                                class="inline-flex items-center gap-1.5 hover:text-blue-600 transition"
                                title="{{ $currentSortBy === 'start_date' && $currentSortDirection === 'desc'
                                    ? 'Показать сначала старые даты'
                                    : 'Показать сначала новые даты' }}">

                                <span>Дата начала</span>

                                <span class="{{ $currentSortBy === 'start_date' ? 'text-blue-600' : 'text-gray-300' }}">

                                    @if ($currentSortBy === 'start_date')
                                        {{ $currentSortDirection === 'asc' ? '↑' : '↓' }}
                                    @else
                                        ↕
                                    @endif
                                </span>
                            </a>
                        </th>

                        <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">
                            <a href="{{ $endSortUrl }}"
                                class="inline-flex items-center gap-1.5 hover:text-blue-600 transition"
                                title="{{ $currentSortBy === 'end_date' && $currentSortDirection === 'desc'
                                    ? 'Показать сначала ранние даты окончания'
                                    : 'Показать сначала поздние даты окончания' }}">

                                <span>Дата окончания</span>

                                <span class="{{ $currentSortBy === 'end_date' ? 'text-blue-600' : 'text-gray-300' }}">

                                    @if ($currentSortBy === 'end_date')
                                        {{ $currentSortDirection === 'asc' ? '↑' : '↓' }}
                                    @else
                                        ↕
                                    @endif
                                </span>
                            </a>
                        </th>

                        <th
                            class="px-6 py-3.5 text-xs font-semibold
                                   uppercase tracking-wider">
                            Статус
                        </th>

                        <th
                            class="px-6 py-3.5 text-xs font-semibold
                                   uppercase tracking-wider">
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100 text-gray-700">

                    @forelse($contracts as $contract)

                        <tr class="hover:bg-gray-50/50 transition">

                            {{-- Номер договора --}}
                            <td class="px-6 py-4">

                                <a href="{{ route('contracts.show', $contract) }}"
                                    class="font-mono font-semibold text-gray-900
                                          hover:text-blue-600 transition">

                                    {{ $contract->contract_number }}
                                </a>
                            </td>

                            {{-- Компания --}}
                            <td class="px-6 py-4">

                                <a href="{{ route('companies.show', ['company' => $contract->company, 'return_url' => request()->fullUrl()]) }}"
                                    class="font-medium text-gray-900
                                          hover:text-blue-600 transition">

                                    {{ $contract->company->name }}
                                </a>
                            </td>

                            {{-- Дата начала --}}
                            <td class="px-6 py-4 text-gray-600">
                                {{ $contract->start_date->format('d/m/Y') }}
                            </td>

                            {{-- Дата окончания --}}
                            <td class="px-6 py-4">

                                @if ($contract->end_date)
                                    <div
                                        class="{{ $contract->end_date->lt(today()) ? 'text-red-600 font-semibold' : 'text-gray-600' }}">

                                        {{ $contract->end_date->format('d/m/Y') }}
                                    </div>

                                    @if ($contract->end_date->lt(today()))
                                        <div
                                            class="text-[10px] text-red-500 font-medium
                       uppercase tracking-wider mt-0.5">
                                            Срок истёк
                                        </div>
                                    @endif
                                @else
                                    <span class="text-gray-400">
                                        Бессрочный
                                    </span>
                                @endif

                            </td>

                            {{-- Статус --}}
                            <td class="px-6 py-4">

                                @include('partials.badge', [
                                    'status' => $contract->effective_status,
                                ])
                            </td>

                            {{-- Действия --}}
                            <td class="px-6 py-4 text-right">

                                <div class="flex items-center justify-end gap-3">

                                    <a href="{{ route('contracts.show', $contract) }}"
                                        class="text-blue-600 hover:text-blue-800
                                              text-sm font-semibold transition">
                                        Открыть →
                                    </a>

                                    <a href="{{ route('contracts.edit', $contract) }}"
                                        class="text-gray-400 hover:text-gray-600 transition" title="Редактировать">

                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">

                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M15.232 5.232l3.536 3.536
                                                                                                                                                                     m-2.036-5.036a2.5 2.5 0
                                                                                                                                                                     113.536 3.536L6.5 21.036
                                                                                                                                                                     H3v-3.572L16.732 3.732z" />
                                        </svg>
                                    </a>
                                </div>
                            </td>
                        </tr>

                    @empty

                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-gray-400">

                                Договоров не найдено.

                                @if (request('search') || request('status') || request('company_id') || request('sort_by') || request('sort_direction'))
                                    <a href="{{ route('contracts.index') }}" class="text-blue-600 hover:underline ml-1">
                                        Сбросить фильтры
                                    </a>
                                @else
                                    <a href="{{ route('contracts.create') }}" class="text-blue-600 hover:underline ml-1">
                                        Создать первый договор
                                    </a>
                                @endif
                            </td>
                        </tr>

                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Пагинация --}}
        @if ($contracts->hasPages())
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50/50">
                {{ $contracts->links() }}
            </div>
        @endif
    </div>

@endsection
