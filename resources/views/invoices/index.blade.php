@extends('layouts.app')

@section('title', 'Инвойсы')

@section('content')

    @php
        $currentSort = in_array(request('sort'), ['issue_date', 'due_date'], true)
            ? request('sort')
            : 'issue_date';
        $currentDirection = in_array(request('direction'), ['asc', 'desc'], true)
            ? request('direction')
            : 'desc';
        $preservedFilters = request()->only(['search', 'company_id', 'status', 'overdue']);

        $sortUrl = function (string $column) use ($currentSort, $currentDirection, $preservedFilters): string {
            $direction = $currentSort === $column && $currentDirection === 'desc' ? 'asc' : 'desc';

            return route('invoices.index', array_merge($preservedFilters, [
                'sort' => $column,
                'direction' => $direction,
            ]));
        };
    @endphp

    <div class="mb-6 flex flex-col sm:flex-row justify-between sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">
                Инвойсы
            </h1>

            <p class="text-sm text-gray-500 mt-1">
                Управление счетами на оплату, отслеживание долгов и статусов
            </p>
        </div>

        <div>
            <a href="{{ route('invoices.create') }}"
                class="inline-flex items-center text-sm bg-blue-600 hover:bg-blue-700
                       text-white px-4 py-2.5 rounded-lg transition shadow-sm font-medium">

                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">

                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                </svg>

                Выставить счет
            </a>
        </div>
    </div>

    {{-- Фильтры и поиск --}}
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm mb-6">
        <form action="{{ route('invoices.index') }}" method="GET" class="flex flex-col md:flex-row md:items-center gap-4">

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

                <input type="text" name="search" value="{{ request('search') }}"
                    placeholder="Номер, компания, плательщик или договор..."
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

                <div class="relative">
                    <input type="text" x-model="query" x-on:focus="open = true" x-on:click="open = true"
                        x-on:input="selectedId = ''; open = true"
                        x-on:keydown.enter.prevent="if (filteredCompanies.length > 0) selectCompany(filteredCompanies[0])"
                        placeholder="Все компании" autocomplete="off"
                        class="w-full px-3 py-2 pr-16 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">

                    <button type="button" x-show="query.length > 0" x-cloak x-on:click="clearCompany()"
                        class="absolute inset-y-0 right-8 flex items-center px-2 text-gray-400 hover:text-red-500 transition"
                        title="Сбросить компанию">✕</button>

                    <button type="button" x-on:click="open = !open"
                        class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600 transition"
                        tabindex="-1">
                        <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>
                </div>

                <div x-show="open" x-cloak x-transition
                    class="absolute z-30 mt-1 w-full max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg">
                    <button type="button" x-on:click="clearCompany()"
                        class="w-full px-3 py-2.5 text-left text-sm text-gray-600 hover:bg-gray-50 transition">Все компании</button>
                    <div class="border-t border-gray-100"></div>
                    <template x-for="company in filteredCompanies" :key="company.id">
                        <button type="button" x-on:click="selectCompany(company)"
                            class="w-full px-3 py-2.5 text-left text-sm hover:bg-blue-50 hover:text-blue-700 transition"
                            :class="String(company.id) === selectedId ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-700'">
                            <span x-text="company.name"></span>
                        </button>
                    </template>
                    <div x-show="filteredCompanies.length === 0" class="px-3 py-4 text-center text-sm text-gray-400">
                        Компании не найдены
                    </div>
                </div>
            </div>

            {{-- Фильтр по статусу --}}
            <div class="relative w-full md:w-44" x-data="{
                open: false,
                selectedStatus: @js((string) request('status', '')),
                statuses: [
                    { value: '', label: 'Все статусы' },
                    { value: 'draft', label: 'Черновик' },
                    { value: 'issued', label: 'Выставлен' },
                    { value: 'partially_paid', label: 'Частично оплачен' },
                    { value: 'paid', label: 'Оплачен' },
                    { value: 'cancelled', label: 'Отменён' },
                ],
                get selectedLabel() {
                    return this.statuses.find(item => item.value === this.selectedStatus)?.label ?? 'Все статусы';
                },
                selectStatus(status) {
                    this.selectedStatus = status.value;
                    this.open = false;
                    this.$nextTick(() => this.$root.closest('form').requestSubmit());
                }
            }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
                <input type="hidden" name="status" x-model="selectedStatus">
                <button type="button" x-on:click="open = !open"
                    class="relative w-full px-3 py-2 pr-10 border border-gray-200 rounded-lg bg-white text-left text-sm text-gray-700 hover:border-gray-300 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                    <span x-text="selectedLabel" :class="selectedStatus ? 'text-gray-700' : 'text-gray-400'"></span>
                    <span class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400">
                        <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </span>
                </button>
                <div x-show="open" x-cloak x-transition
                    class="absolute z-30 mt-1 w-full overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg">
                    <template x-for="status in statuses" :key="status.value">
                        <button type="button" x-on:click="selectStatus(status)"
                            class="flex w-full items-center justify-between px-3 py-2.5 text-left text-sm transition hover:bg-blue-50 hover:text-blue-700"
                            :class="status.value === selectedStatus ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-700'">
                            <span x-text="status.label"></span>
                            <svg x-show="status.value === selectedStatus" class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                            </svg>
                        </button>
                    </template>
                </div>
            </div>

            {{-- Просроченные --}}
            <div class="flex items-center gap-2">
                <input type="checkbox" name="overdue" id="overdue" value="1" onchange="this.form.submit()"
                    {{ request('overdue') ? 'checked' : '' }}
                    class="h-4 w-4 rounded border-gray-300
                           text-blue-600 focus:ring-blue-500">

                <label for="overdue"
                    class="text-sm font-medium text-gray-700
                           cursor-pointer select-none">

                    Просроченные
                </label>
            </div>

            {{-- Кнопки --}}
            <div class="flex gap-2">
                <button type="submit"
                    class="px-4 py-2 bg-gray-100 hover:bg-gray-200
                           text-gray-700 text-sm font-medium rounded-lg transition">

                    Найти
                </button>

                @if (request('search') || request('status') || request('company_id') || request('overdue') || request('sort') || request('direction'))
                    <a href="{{ route('invoices.index') }}"
                        class="px-4 py-2 border border-gray-200 hover:bg-gray-50
                               text-gray-500 text-sm font-medium rounded-lg
                               transition text-center">

                        Сбросить
                    </a>
                @endif
            </div>
        </form>
    </div>

    {{-- Список инвойсов --}}
    <div class="bg-white rounded-xl border border-gray-200
                shadow-sm overflow-hidden">

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left
                               bg-gray-50 text-gray-500">

                        <th
                            class="px-6 py-3.5 text-xs font-semibold
                                   uppercase tracking-wider">
                            Номер счета
                        </th>

                        <th
                            class="px-6 py-3.5 text-xs font-semibold
                                   uppercase tracking-wider">
                            Плательщик / Компания
                        </th>

                        <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider">
                            <a href="{{ $sortUrl('issue_date') }}"
                                class="inline-flex items-center gap-1.5 hover:text-blue-600 transition"
                                title="{{ $currentSort === 'issue_date' && $currentDirection === 'desc' ? 'Показать сначала старые даты' : 'Показать сначала новые даты' }}">
                                <span>Дата выставления</span>
                                <span class="{{ $currentSort === 'issue_date' ? 'text-blue-600' : 'text-gray-300' }}">
                                    {{ $currentSort === 'issue_date' ? ($currentDirection === 'asc' ? '↑' : '↓') : '↕' }}
                                </span>
                            </a>
                        </th>

                        <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider">
                            <a href="{{ $sortUrl('due_date') }}"
                                class="inline-flex items-center gap-1.5 hover:text-blue-600 transition"
                                title="{{ $currentSort === 'due_date' && $currentDirection === 'desc' ? 'Показать сначала ранние сроки' : 'Показать сначала поздние сроки' }}">
                                <span>Срок оплаты</span>
                                <span class="{{ $currentSort === 'due_date' ? 'text-blue-600' : 'text-gray-300' }}">
                                    {{ $currentSort === 'due_date' ? ($currentDirection === 'asc' ? '↑' : '↓') : '↕' }}
                                </span>
                            </a>
                        </th>

                        <th
                            class="px-6 py-3.5 text-xs font-semibold
                                   uppercase tracking-wider">
                            Сумма счета
                        </th>

                        <th
                            class="px-6 py-3.5 text-xs font-semibold
                                   uppercase tracking-wider">
                            Оплачено / Остаток
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
                    @forelse ($invoices as $invoice)
                        @php
                            $paidAmount = (float) ($invoice->confirmed_paid_amount ?? 0);
                            $appliedAmount = min((float) $invoice->total_amount, $paidAmount);
                            $overpaymentAmount = max(0, $paidAmount - (float) $invoice->total_amount);
                            $remainingAmount = max(0, (float) $invoice->total_amount - $paidAmount);
                        @endphp
                        <tr class="hover:bg-gray-50/50 transition">

                            {{-- Номер --}}
                            <td class="px-6 py-4 font-mono font-semibold text-gray-900">
                                {{ $invoice->invoice_number }}
                            </td>

                            {{-- Плательщик и компания --}}
                            <td class="px-6 py-4">
                                <div class="font-medium text-gray-900">
                                    {{ $invoice->payer_name }}
                                </div>

                                <div class="text-xs text-gray-400 mt-0.5">
                                    Компания:

                                    <a href="{{ route('companies.show', $invoice->company_id) }}"
                                        class="text-blue-600 hover:underline">

                                        {{ $invoice->company->name }}
                                    </a>
                                </div>
                            </td>

                            {{-- Дата выставления --}}
                            <td class="px-6 py-4 text-gray-600">
                                {{ \Illuminate\Support\Carbon::parse($invoice->issue_date)->format('d.m.Y') }}
                            </td>

                            {{-- Срок оплаты --}}
                            <td class="px-6 py-4">
                                <div
                                    class="{{ $invoice->is_overdue ? 'text-red-600 font-semibold' : 'text-gray-600' }}">

                                    {{ \Illuminate\Support\Carbon::parse($invoice->due_date)->format('d.m.Y') }}
                                </div>

                                @if ($invoice->is_overdue)
                                    <div
                                        class="text-[10px] text-red-500 font-medium
                                                uppercase tracking-wider mt-0.5">

                                        ⚠️ Просрочен
                                    </div>
                                @endif
                            </td>

                            {{-- Сумма --}}
                            <td class="px-6 py-4 font-semibold text-gray-900">
                                {{ number_format($invoice->total_amount, 2) }} ₼
                            </td>

                            {{-- Оплата, переплата и долг --}}
                            <td class="px-6 py-4 text-xs">
                                <div class="text-green-600 font-medium">
                                    Оплачено:
                                    {{ number_format($appliedAmount, 2) }} ₼
                                </div>

                                @if ($overpaymentAmount > 0)
                                    <div class="text-blue-600 font-medium mt-0.5">
                                        Переплата:
                                        {{ number_format($overpaymentAmount, 2) }} ₼
                                    </div>
                                @endif

                                @if ($remainingAmount > 0)
                                    <div class="text-red-500 font-medium mt-0.5">
                                        Долг:
                                        {{ number_format($remainingAmount, 2) }} ₼
                                    </div>
                                @endif
                            </td>

                            {{-- Статус --}}
                            <td class="px-6 py-4">
                                @include('partials.badge', [
                                    'status' => $invoice->status,
                                ])
                            </td>

                            {{-- Действия --}}
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('invoices.show', $invoice) }}"
                                        class="text-blue-600 hover:text-blue-800
                                               text-sm font-semibold transition">

                                        Открыть →
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-gray-400">

                                Счетов не найдено.

                                @if (request('search') || request('status') || request('company_id') || request('overdue') || request('sort') || request('direction'))
                                    <a href="{{ route('invoices.index') }}" class="text-blue-600 hover:underline ml-1">

                                        Сбросить фильтры
                                    </a>
                                @else
                                    <a href="{{ route('invoices.create') }}" class="text-blue-600 hover:underline ml-1">

                                        Выставить первый счет
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Пагинация --}}
        @if ($invoices->hasPages())
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50/50">
                {{ $invoices->links() }}
            </div>
        @endif
    </div>

@endsection
