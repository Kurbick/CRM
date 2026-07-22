@extends('layouts.app')

@section('title', $company->name)

@section('content')

    {{-- Навигация и заголовок --}}
    <div class="mb-6">
        <a href="{{ $returnContext['url'] }}"
            class="text-sm text-gray-500 hover:text-gray-900 transition flex items-center gap-1.5 mb-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            {{ $returnContext['label'] }}
        </a>

        <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-4">
            <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-2xl font-bold leading-tight text-gray-900">{{ $company->name }}</h1>
                        <span data-testid="company-status" class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $company->status === 'active' ? 'bg-green-100 text-green-700' : ($company->status === 'suspended' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600') }}">
                            {{ $company->status === 'active' ? 'Активна' : ($company->status === 'suspended' ? 'Приостановлена' : 'В архиве') }}
                        </span>
                    </div>
                    <p class="text-sm text-gray-500 mt-1 flex items-center gap-2">
                        <span>{{ $company->type === 'company' ? 'Юридическое лицо' : 'Индивидуальный предприниматель' }}</span>
                        @if ($company->short_name)
                            <span class="text-gray-300">|</span>
                            <span>{{ $company->short_name }}</span>
                        @endif
                    </p>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ route('companies.edit', ['company' => $company, 'origin' => 'show']) }}"
                    class="inline-flex items-center text-sm border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 px-4 py-2 rounded-lg font-medium transition shadow-sm">
                    <svg class="w-4 h-4 mr-1.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                    </svg>
                    Редактировать
                </a>

                <form action="{{ route('companies.destroy', $company) }}" method="POST"
                    onsubmit="return confirm('Вы уверены, что хотите удалить эту компанию? Действие необратимо.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                        class="inline-flex items-center text-sm bg-red-50 hover:bg-red-100 text-red-700 px-4 py-2 rounded-lg font-medium transition border border-red-200">
                        <svg class="w-4 h-4 mr-1.5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                        Удалить
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Финансы и детализация задолженности --}}
    <section class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8 overflow-hidden">
        <div class="px-5 py-5">
            <h2 class="font-bold text-gray-900">Финансы</h2>
            <dl class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3 mt-4">
                <div class="rounded-lg bg-gray-50 px-3 py-3">
                    <dt class="text-xs font-medium text-gray-500">Выставлено</dt>
                    <dd class="font-semibold text-gray-900 mt-1">{{ number_format($stats['total_invoiced'], 2) }} ₼</dd>
                </div>
                <div class="rounded-lg bg-green-50 px-3 py-3">
                    <dt class="text-xs font-medium text-green-600">Оплачено</dt>
                    <dd class="font-semibold text-green-700 mt-1">{{ number_format($stats['total_paid'], 2) }} ₼</dd>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-3">
                    <dt class="text-xs font-medium text-gray-500">Общий долг</dt>
                    <dd class="font-semibold text-gray-900 mt-1">{{ number_format($stats['total_debt'], 2) }} ₼</dd>
                </div>
                <div data-testid="overdue-summary"
                    class="rounded-lg px-3 py-3 {{ $overdueRemaining !== '0.00' ? 'bg-red-50' : 'bg-gray-50' }}">
                    <dt class="text-xs font-medium {{ $overdueRemaining !== '0.00' ? 'text-red-600' : 'text-gray-500' }}">Просрочено</dt>
                    <dd class="font-semibold mt-1 {{ $overdueRemaining !== '0.00' ? 'text-red-700' : 'text-gray-900' }}">
                        {{ $overdueRemaining }} ₼
                    </dd>
                </div>
                @if ($stats['credit_balance'] > 0)
                    <div class="rounded-lg bg-blue-50 px-3 py-3">
                        <dt class="text-xs font-medium text-blue-600">Баланс компании</dt>
                        <dd class="font-semibold text-blue-700 mt-1">{{ number_format($stats['credit_balance'], 2) }} ₼</dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- Задолженности по строкам выставленных инвойсов --}}
        <div class="border-t border-gray-100">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="font-bold text-gray-900">Задолженности</h2>
            </div>

        @if ($stats['total_debt'] <= 0)
            <p class="px-5 py-6 text-sm text-gray-500">У компании нет задолженности.</p>
        @else
            <div class="divide-y divide-gray-100">
                <section class="px-5 py-5">
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">По подпискам</h3>
                @if ($subscriptionPeriodDebtGroups === [])
                    <p class="text-sm text-gray-500">Задолженностей нет.</p>
                @else
                @foreach ($subscriptionPeriodDebtGroups as $subscriptionDebt)
                    <div class="{{ !$loop->first ? 'mt-6 pt-5 border-t border-gray-100' : '' }}">
                        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2 mb-3">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900">{{ $subscriptionDebt['subscription_title'] }}</h3>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    Неоплаченных периодов: {{ $subscriptionDebt['totals']['unpaid_period_count'] }}
                                </p>
                            </div>
                            <div class="text-xs sm:text-right">
                                <p class="font-semibold text-gray-700">Остаток: {{ $subscriptionDebt['totals']['remaining'] }} ₼</p>
                                @if ($subscriptionDebt['totals']['overdue_remaining'] !== '0.00')
                                    <p class="font-semibold text-red-600 mt-0.5">Просрочено: {{ $subscriptionDebt['totals']['overdue_remaining'] }} ₼</p>
                                @endif
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[940px] table-fixed text-sm text-left">
                                <colgroup><col class="w-[15%]"><col class="w-[13%]"><col class="w-[12%]"><col class="w-[12%]"><col class="w-[12%]"><col class="w-[14%]"><col class="w-[22%]"></colgroup>
                                <thead>
                                    <tr class="border-b border-gray-100 text-xs uppercase text-gray-400">
                                        <th class="px-3 pb-2 text-left font-semibold">Период</th>
                                        <th class="px-3 pb-2 text-left font-semibold">Инвойс</th>
                                        <th class="px-3 pb-2 text-left font-semibold">Сумма</th>
                                        <th class="px-3 pb-2 text-left font-semibold">Оплачено</th>
                                        <th class="px-3 pb-2 text-left font-semibold">Остаток</th>
                                        <th class="px-3 pb-2 text-left font-semibold">Срок оплаты</th>
                                        <th class="px-3 pb-2 text-left font-semibold">Статус</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50 text-gray-700">
                                    @foreach ($subscriptionDebt['periods'] as $period)
                                        <tr>
                                            <td class="px-3 py-3 text-left whitespace-nowrap font-medium text-gray-900">{{ $period['period_label'] }}</td>
                                            <td class="px-3 py-3 text-left whitespace-nowrap font-mono text-xs">
                                                <a href="{{ route('invoices.show', ['invoice' => $period['invoice_id'], 'origin' => 'company', 'tab' => 'invoices']) }}"
                                                    class="text-blue-600 hover:underline">
                                                    {{ $period['invoice_number'] }}
                                                </a>
                                            </td>
                                            <td class="px-3 py-3 text-left tabular-nums whitespace-nowrap">{{ $period['total'] }} ₼</td>
                                            <td class="px-3 py-3 text-left tabular-nums whitespace-nowrap text-green-700">{{ $period['allocated'] }} ₼</td>
                                            <td class="px-3 py-3 text-left tabular-nums whitespace-nowrap font-semibold {{ $period['is_overdue'] ? 'text-red-600' : 'text-gray-900' }}">{{ $period['remaining'] }} ₼</td>
                                            <td class="px-3 py-3 text-left whitespace-nowrap">
                                                {{ $period['due_date_label'] }}
                                            </td>
                                            <td class="px-3 py-3 text-left whitespace-nowrap">
                                                @if ($period['is_overdue'])
                                                    <span class="inline-flex rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-700">
                                                        Просрочено на {{ $period['days_overdue'] }} дн.
                                                    </span>
                                                @elseif ($period['payment_status'] === 'partially_paid')
                                                    <span class="inline-flex rounded-full bg-orange-100 px-2.5 py-0.5 text-xs font-medium text-orange-700">
                                                        Частично оплачено
                                                    </span>
                                                @else
                                                    <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                                                        К оплате
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
                @endif
                </section>

                <section class="px-5 py-5">
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">По разовым услугам</h3>
                    @if ($oneTimeServiceDebtLines === [])
                        <p class="text-sm text-gray-500">Задолженностей нет.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[940px] table-fixed text-sm text-left">
                                <colgroup><col class="w-[22%]"><col class="w-[13%]"><col class="w-[12%]"><col class="w-[12%]"><col class="w-[12%]"><col class="w-[14%]"><col class="w-[15%]"></colgroup>
                                <thead><tr class="border-b border-gray-100 text-xs uppercase text-gray-400">
                                    <th class="px-3 pb-2 text-left font-semibold">Услуга</th><th class="px-3 pb-2 text-left font-semibold">Инвойс</th>
                                    <th class="px-3 pb-2 text-left font-semibold">Сумма</th><th class="px-3 pb-2 text-left font-semibold">Оплачено</th>
                                    <th class="px-3 pb-2 text-left font-semibold">Остаток</th><th class="px-3 pb-2 text-left font-semibold">Срок оплаты</th>
                                    <th class="px-3 pb-2 text-left font-semibold">Статус</th>
                                </tr></thead>
                                <tbody class="divide-y divide-gray-100 text-gray-700">
                                @foreach ($oneTimeServiceDebtLines as $line)
                                    <tr>
                                        <td class="px-3 py-3 text-left font-medium text-gray-900">{{ $line['service_title'] }}</td>
                                        <td class="px-3 py-3 text-left whitespace-nowrap font-mono text-xs"><a class="text-blue-600 hover:underline" href="{{ route('invoices.show', ['invoice' => $line['invoice_id'], 'origin' => 'company', 'tab' => 'invoices']) }}">{{ $line['invoice_number'] }}</a></td>
                                        <td class="px-3 py-3 text-left tabular-nums whitespace-nowrap">{{ $line['total'] }} ₼</td>
                                        <td class="px-3 py-3 text-left tabular-nums whitespace-nowrap text-green-700">{{ $line['allocated'] }} ₼</td>
                                        <td class="px-3 py-3 text-left tabular-nums whitespace-nowrap font-semibold {{ $line['is_overdue'] ? 'text-red-600' : 'text-gray-900' }}">{{ $line['remaining'] }} ₼</td>
                                        <td class="px-3 py-3 text-left whitespace-nowrap">{{ $line['due_date_label'] }}</td>
                                        <td class="px-3 py-3 text-left whitespace-nowrap">
                                            @if ($line['is_overdue']) <span class="inline-flex rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-700">Просрочено на {{ $line['days_overdue'] }} дн.</span>
                                            @elseif ($line['payment_status'] === 'partially_paid') <span class="inline-flex rounded-full bg-orange-100 px-2.5 py-0.5 text-xs font-medium text-orange-700">Частично оплачено</span>
                                            @else <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">К оплате</span> @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            </div>
        @endif

        @if ($subscriptionPeriodDebts['anomalies'] !== [])
            <div class="mx-5 mb-5 rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-800">
                Есть строки подписок без корректно указанного расчётного периода: {{ $subscriptionPeriodDebtAnomalyCount }}.
            </div>
        @endif
        </div>
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Левая колонка: Основная информация --}}
        <div class="space-y-6">
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h3
                    class="font-bold text-gray-900 mb-4 pb-2 border-b border-gray-100 text-sm uppercase tracking-wider text-gray-500">
                    Основная информация</h3>

                <dl class="space-y-4 text-sm">
                    <div>
                        <dt class="text-xs font-medium text-gray-400 uppercase">Режим счетов</dt>
                        <dd class="mt-0.5 text-gray-900">
                            {{ $company->invoice_mode === 'separate' ? 'Раздельный (по заказам)' : 'Сводный (месячный)' }}
                        </dd>
                    </div>

                    @if ($company->voen)
                        <div data-testid="company-voen">
                            <dt class="text-xs font-medium text-gray-400 uppercase">VÖEN (ИНН)</dt>
                            <dd class="mt-0.5 font-semibold font-mono text-gray-900">{{ $company->voen }}</dd>
                        </div>
                    @endif
                    @if ($company->email)
                        <div data-testid="company-email">
                            <dt class="text-xs font-medium text-gray-400 uppercase">Электронная почта</dt>
                            <dd class="mt-0.5"><a href="mailto:{{ $company->email }}" class="text-blue-600 hover:underline">{{ $company->email }}</a></dd>
                        </div>
                    @endif
                    @if ($company->phone)
                        <div data-testid="company-phone">
                            <dt class="text-xs font-medium text-gray-400 uppercase">Телефон</dt>
                            <dd class="mt-0.5"><a href="tel:{{ $company->phone }}" class="text-gray-900 hover:text-blue-600">{{ $company->phone }}</a></dd>
                        </div>
                    @endif
                    @if ($company->website)
                        <div data-testid="company-website">
                            <dt class="text-xs font-medium text-gray-400 uppercase">Сайт</dt>
                            <dd class="mt-0.5">
                                <a href="{{ $company->website }}" target="_blank" rel="noopener noreferrer"
                                    class="text-blue-600 hover:underline inline-flex items-center gap-1">
                                    {{ $company->website }}
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                    </svg>
                                </a>
                            </dd>
                        </div>
                    @endif
                    @if ($company->legal_address)
                        <div data-testid="company-legal-address">
                            <dt class="text-xs font-medium text-gray-400 uppercase">Юридический адрес</dt>
                            <dd class="mt-0.5 text-gray-900">{{ $company->legal_address }}</dd>
                        </div>
                    @endif
                    @if ($company->actual_address)
                        <div data-testid="company-actual-address">
                            <dt class="text-xs font-medium text-gray-400 uppercase">Фактический адрес</dt>
                            <dd class="mt-0.5 text-gray-900">{{ $company->actual_address }}</dd>
                        </div>
                    @endif
                </dl>

                @if ($company->bank_name || $company->iban || $company->bank_code || $company->bank_voen || $company->swift)
                    <div data-testid="company-bank-details" class="mt-5 pt-5 border-t border-gray-100">
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-4">Банковские реквизиты</h4>
                        <dl class="space-y-4 text-sm">
                            @if ($company->bank_name)
                                <div><dt class="text-xs font-medium text-gray-400 uppercase">Банк</dt><dd class="mt-0.5 text-gray-900 font-medium">{{ $company->bank_name }}</dd></div>
                            @endif
                            @if ($company->iban)
                                <div><dt class="text-xs font-medium text-gray-400 uppercase">IBAN</dt><dd class="mt-0.5 font-mono text-gray-900 text-xs break-all">{{ $company->iban }}</dd></div>
                            @endif
                            @if ($company->bank_code)
                                <div><dt class="text-xs font-medium text-gray-400 uppercase">Код банка</dt><dd class="mt-0.5 font-mono text-gray-900">{{ $company->bank_code }}</dd></div>
                            @endif
                            @if ($company->swift)
                                <div><dt class="text-xs font-medium text-gray-400 uppercase">SWIFT</dt><dd class="mt-0.5 font-mono text-gray-900">{{ $company->swift }}</dd></div>
                            @endif
                            @if ($company->bank_voen)
                                <div><dt class="text-xs font-medium text-gray-400 uppercase">VÖEN банка</dt><dd class="mt-0.5 font-mono text-gray-900">{{ $company->bank_voen }}</dd></div>
                            @endif
                        </dl>
                    </div>
                @endif
            </div>

            {{-- Карточка: Комментарий --}}
            @if ($company->comment)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h3
                        class="font-bold text-gray-900 mb-2 pb-2 border-b border-gray-100 text-sm uppercase tracking-wider text-gray-500">
                        Примечание</h3>
                    <p class="text-sm text-gray-700 whitespace-pre-line">{{ $company->comment }}</p>
                </div>
            @endif

        </div>

        {{-- Правая колонка: Интерактивные табы (AlpineJS) --}}
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden"
            x-data="{
                tab: @js($activeTab),
                selectTab(value) {
                    this.tab = value;
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', value);
                    window.history.replaceState({}, '', url);
                }
            }">

            {{-- Заголовки табов --}}
            <div class="border-b border-gray-200 bg-gray-50/50 px-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <nav class="-mb-px flex gap-4 overflow-x-auto" aria-label="Tabs">
                    <button @click="selectTab('contacts')"
                        :class="tab === 'contacts' ? 'border-blue-600 text-blue-600' :
                            'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                        Контакты ({{ $company->contacts->count() }})
                    </button>
                    <button @click="selectTab('contracts')"
                        :class="tab === 'contracts' ? 'border-blue-600 text-blue-600' :
                            'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                        Договоры ({{ $company->contracts->count() }})
                    </button>
                    <button @click="selectTab('invoices')"
                        :class="tab === 'invoices' ? 'border-blue-600 text-blue-600' :
                            'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                        Инвойсы ({{ $company->invoices->count() }})
                    </button>
                    <button @click="selectTab('payments')"
                        :class="tab === 'payments' ? 'border-blue-600 text-blue-600' :
                            'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                        Платежи ({{ $company->payments->count() }})
                    </button>
                </nav>
                <div class="pb-3 sm:pb-0 sm:pl-4 shrink-0">
                    <a x-show="tab === 'contacts'" href="{{ route('companies.contacts.create', ['company' => $company, 'origin' => 'company', 'tab' => 'contacts']) }}"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700 transition">
                        <span aria-hidden="true">+</span> Контакт
                    </a>
                    <a x-show="tab === 'contracts'" x-cloak href="{{ route('companies.contracts.create', ['company' => $company, 'origin' => 'company', 'tab' => 'contracts']) }}"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700 transition">
                        <span aria-hidden="true">+</span> Договор
                    </a>
                </div>
            </div>

            {{-- Таб: Контакты --}}
            <div x-show="tab === 'contacts'" class="p-5">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="border-b border-gray-100 text-gray-400 pb-2">
                                <th class="pb-3 pr-4 font-semibold text-xs uppercase">Контакт</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Роль</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Телефон и e-mail</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Комментарий</th>
                                <th class="pb-3 font-semibold text-xs uppercase"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50 text-gray-700">
                            @forelse($company->contacts as $contact)
                                <tr>
                                    <td class="py-3">
                                        <div class="font-medium text-gray-900">{{ $contact->first_name }}
                                            {{ $contact->last_name }}</div>
                                        @if ($contact->position)
                                            <div class="text-xs text-gray-400 mt-0.5">{{ $contact->position }}</div>
                                        @endif
                                    </td>
                                    <td class="py-3">
                                        <span
                                            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
                                            {{ $contact->role ?? 'Контакт' }}
                                        </span>
                                    </td>
                                    <td class="py-3">
                                        @if ($contact->phone)
                                            <div class="text-xs"><a href="tel:{{ $contact->phone }}" class="text-gray-900 hover:text-blue-600">{{ $contact->phone }}</a></div>
                                        @endif
                                        @if ($contact->email)
                                            <div class="text-xs mt-0.5"><a href="mailto:{{ $contact->email }}" class="text-blue-600 hover:underline">{{ $contact->email }}</a></div>
                                        @endif
                                    </td>
                                    <td class="py-3 text-xs text-gray-400">{{ $contact->comment ?? '—' }}</td>
                                    <td class="py-3 text-right">
                                        <a href="{{ route('contacts.edit', ['contact' => $contact, 'origin' => 'company', 'tab' => 'contacts']) }}"
                                            class="text-gray-400 hover:text-blue-600 text-xs transition">Редакт.</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-6 text-center text-gray-400">
                                        <p>Контакты отсутствуют.</p>
                                        <p class="text-xs mt-1">Добавьте контактное лицо компании.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Таб: Договоры --}}
            <div x-show="tab === 'contracts'" x-cloak class="p-5">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="border-b border-gray-100 text-gray-400 pb-2">
                                <th class="pb-3 font-semibold text-xs uppercase">Номер договора</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Дата начала</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Дата окончания</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Статус</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Предметы договора</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50 text-gray-700">
                            @forelse($company->contracts as $contract)
                                <tr>
                                    <td class="py-3 font-medium font-mono"><a href="{{ route('contracts.show', ['contract' => $contract, 'origin' => 'company', 'tab' => 'contracts']) }}" class="text-blue-600 hover:underline">{{ $contract->contract_number }}</a>
                                    </td>
                                    <td class="py-3 text-gray-600">{{ $contract->start_date?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="py-3 text-gray-400">
                                        {{ $contract->end_date?->format('d/m/Y') ?? 'Бессрочный' }}</td>
                                    <td class="py-3">
                                        @include('partials.badge', [
                                            'status' => $contract->effective_status,
                                        ])
                                    </td>
                                    <td class="py-3 text-xs text-gray-600">{{ $contract->orders_count + $contract->subscriptions_count }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-6 text-center text-gray-400">
                                        У компании пока нет договоров.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Таб: Инвойсы --}}
            <div x-show="tab === 'invoices'" x-cloak class="p-5">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="border-b border-gray-100 text-gray-400 pb-2">
                                <th class="pb-3 font-semibold text-xs uppercase">Номер счета</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Выставлен / Срок</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Сумма</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Оплачено / Остаток</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Статус</th>
                                <th class="pb-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50 text-gray-700">
                            @forelse($company->invoices as $invoice)
                                <tr class="hover:bg-gray-50/50 transition">
                                    <td class="py-3 font-medium font-mono">
                                        <a href="{{ route('invoices.show', ['invoice' => $invoice, 'origin' => 'company', 'tab' => 'invoices']) }}" class="text-blue-600 hover:underline">{{ $invoice->invoice_number }}</a>
                                    </td>
                                    <td class="py-3">
                                        <div class="text-gray-900 text-xs">{{ $invoice->issue_date ? \Illuminate\Support\Carbon::parse($invoice->issue_date)->format('d/m/Y') : '—' }}</div>
                                        <div class="text-red-500 text-xs mt-0.5 font-medium flex items-center gap-1">
                                            <span>до {{ $invoice->due_date ? \Illuminate\Support\Carbon::parse($invoice->due_date)->format('d/m/Y') : '—' }}</span>
                                            @if ($invoice->is_overdue)
                                                <span
                                                    class="bg-red-100 text-red-800 text-[10px] px-1 py-0.2 rounded">Просрочен</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="py-3 font-semibold text-gray-900">
                                        {{ number_format($invoice->total_amount, 2) }} ₼
                                    </td>
                                    <td class="py-3 text-xs">
                                        <div class="text-green-600 font-medium">Оплачено:
                                            {{ number_format($invoice->applied_amount, 2) }} ₼</div>
                                        @if ($invoice->overpayment_amount > 0)
                                            <div class="text-blue-600 font-medium mt-0.5">Переплата:
                                                {{ number_format($invoice->overpayment_amount, 2) }} ₼</div>
                                        @endif
                                        @if ($invoice->remaining_amount > 0)
                                            <div class="text-red-500 font-medium mt-0.5">Долг:
                                                {{ number_format($invoice->remaining_amount, 2) }} ₼</div>
                                        @endif
                                    </td>
                                    <td class="py-3">
                                        @include('partials.badge', ['status' => $invoice->status])
                                    </td>
                                    <td class="py-3 text-right">
                                        <a href="{{ route('invoices.show', ['invoice' => $invoice, 'origin' => 'company', 'tab' => 'invoices']) }}"
                                            class="text-blue-600 hover:text-blue-800 font-semibold">
                                            Открыть →
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-6 text-center text-gray-400">У компании пока нет инвойсов.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Таб: Платежи --}}
            <div x-show="tab === 'payments'" x-cloak class="p-5">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="border-b border-gray-100 text-gray-400 pb-2">
                                <th class="pb-3 font-semibold text-xs uppercase">Дата платежа</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Счет (Инвойс)</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Сумма платежа</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Статус</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Транзакция / Описание</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50 text-gray-700">
                            @forelse($company->payments as $payment)
                                <tr>
                                    <td class="py-3 font-medium text-gray-900">
                                        {{ $payment->payment_date ? \Illuminate\Support\Carbon::parse($payment->payment_date)->format('d/m/Y') : '—' }}
                                    </td>
                                    <td class="py-3 font-mono text-xs">
                                        @if ($payment->invoice)
                                            <a href="{{ route('invoices.show', ['invoice' => $payment->invoice, 'origin' => 'company', 'tab' => 'payments']) }}"
                                                class="text-blue-600 hover:underline">
                                                {{ $payment->invoice->invoice_number }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="py-3 font-semibold text-green-600">
                                        + {{ number_format($payment->amount, 2) }} ₼
                                    </td>
                                    <td class="py-3">
                                        @include('partials.badge', ['status' => $payment->status])
                                    </td>
                                    <td class="py-3 text-xs text-gray-500">
                                        <div class="font-mono">{{ $payment->transaction_reference ?? '—' }}</div>
                                        @if ($payment->comment)
                                            <div class="text-gray-400 mt-0.5 italic">{{ $payment->comment }}</div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-6 text-center text-gray-400">Платежи отсутствуют.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </div>

@endsection
