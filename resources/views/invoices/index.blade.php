@extends('layouts.app')

@section('title', 'Инвойсы')

@section('content')

    <div class="mb-6 flex flex-col sm:flex-row justify-between sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Инвойсы</h1>
            <p class="text-sm text-gray-500 mt-1">Управление счетами на оплату, отслеживание долгов и статусов</p>
        </div>
        <div>
            <a href="{{ route('invoices.create') }}"
                class="inline-flex items-center text-sm bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-lg transition shadow-sm font-medium">
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
            <!-- Поиск -->
            <div class="flex-1 relative">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400 pointer-events-none">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </span>
                <input type="text" name="search" value="{{ request('search') }}"
                    placeholder="Поиск по номеру счета или плательщику..."
                    class="w-full pl-10 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
            </div>

            <!-- Фильтр по компании -->
            <div class="w-full md:w-56">
                <select name="company_id" onchange="this.form.submit()"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                    <option value="">Все компании</option>
                    @foreach ($companies as $company)
                        <option value="{{ $company->id }}" {{ request('company_id') == $company->id ? 'selected' : '' }}>
                            {{ $company->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Фильтр по статусу -->
            <div class="w-full md:w-44">
                <select name="status" onchange="this.form.submit()"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                    <option value="">Все статусы</option>
                    <option value="draft" {{ request('status') === 'draft' ? 'selected' : '' }}>Черновик</option>
                    <option value="issued" {{ request('status') === 'issued' ? 'selected' : '' }}>Выставлен</option>
                    <option value="partially_paid" {{ request('status') === 'partially_paid' ? 'selected' : '' }}>Частично
                        оплачен</option>
                    <option value="paid" {{ request('status') === 'paid' ? 'selected' : '' }}>Оплачен</option>
                    <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Отменен</option>
                </select>
            </div>

            <!-- Просроченные -->
            <div class="flex items-center gap-2">
                <input type="checkbox" name="overdue" id="overdue" value="1" onchange="this.form.submit()"
                    {{ request('overdue') ? 'checked' : '' }}
                    class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <label for="overdue"
                    class="text-sm font-medium text-gray-700 cursor-pointer select-none">Просроченные</label>
            </div>

            <!-- Кнопки -->
            <div class="flex gap-2">
                <button type="submit"
                    class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition">
                    Найти
                </button>
                @if (request('search') || request('status') || request('company_id') || request('overdue'))
                    <a href="{{ route('invoices.index') }}"
                        class="px-4 py-2 border border-gray-200 hover:bg-gray-50 text-gray-500 text-sm font-medium rounded-lg transition text-center">
                        Сбросить
                    </a>
                @endif
            </div>
        </form>
    </div>

    {{-- Список инвойсов --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left bg-gray-50 text-gray-500">
                        <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider">Номер счета</th>
                        <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider">Плательщик / Компания</th>
                        <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider">Дата выставления</th>
                        <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider">Срок оплаты</th>
                        <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider">Сумма счета</th>
                        <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider">Оплачено / Остаток</th>
                        <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider">Статус</th>
                        <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-gray-700">
                    @forelse($invoices as $invoice)
                        <tr class="hover:bg-gray-50/50 transition">
                            <td class="px-6 py-4 font-mono font-semibold text-gray-900">
                                {{ $invoice->invoice_number }}
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-gray-900">{{ $invoice->payer_name }}</div>
                                <div class="text-xs text-gray-400 mt-0.5">
                                    Компания: <a href="{{ route('companies.show', $invoice->company_id) }}"
                                        class="text-blue-600 hover:underline">{{ $invoice->company->name }}</a>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-gray-600">
                                {{ $invoice->issue_date }}
                            </td>
                            <td class="px-6 py-4">
                                <div class="{{ $invoice->is_overdue ? 'text-red-600 font-semibold' : 'text-gray-600' }}">
                                    {{ $invoice->due_date }}
                                </div>
                                @if ($invoice->is_overdue)
                                    <div class="text-[10px] text-red-500 font-medium uppercase tracking-wider mt-0.5">⚠️
                                        Просрочен</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 font-semibold text-gray-900">
                                {{ number_format($invoice->total_amount, 2) }} ₼
                            </td>
                            <td class="px-6 py-4 text-xs">
                                <div class="text-green-600 font-medium">Оплачено:
                                    {{ number_format($invoice->paid_amount, 2) }} ₼</div>
                                @if ($invoice->remaining_amount > 0)
                                    <div class="text-red-500 font-medium mt-0.5">Долг:
                                        {{ number_format($invoice->remaining_amount, 2) }} ₼</div>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                @include('partials.badge', ['status' => $invoice->status])
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('invoices.show', $invoice) }}"
                                        class="text-blue-600 hover:text-blue-800 text-sm font-semibold transition">
                                        Открыть →
                                    </a>
                                    @if (in_array($invoice->status, ['draft', 'issued']))
                                        <a href="{{ route('invoices.edit', $invoice) }}"
                                            class="text-gray-400 hover:text-gray-600 transition" title="Редактировать">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                            </svg>
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-gray-400">
                                Счетов не найдено.
                                @if (request('search') || request('status') || request('company_id') || request('overdue'))
                                    <a href="{{ route('invoices.index') }}"
                                        class="text-blue-600 hover:underline ml-1">Сбросить фильтры</a>
                                @else
                                    <a href="{{ route('invoices.create') }}"
                                        class="text-blue-600 hover:underline ml-1">Выставить первый счет</a>
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
