@extends('layouts.app')

@section('title', $company->name)

@section('content')

    @php
        $companyDebtColumnWidths = ['w-[22%]', 'w-[13%]', 'w-[12%]', 'w-[12%]', 'w-[12%]', 'w-[14%]', 'w-[15%]'];
    @endphp

    {{-- Компактный заголовок сущности --}}
    <div data-testid="company-entity-header" class="mb-5">
        <a href="{{ $returnContext['url'] }}"
            class="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 transition hover:text-slate-900">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            {{ $returnContext['label'] }}
        </a>

        <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="truncate text-xl font-semibold leading-tight text-slate-900">{{ $company->name }}</h1>
                    <span data-testid="company-status" class="crm-badge crm-badge-{{ $company->status === 'active' ? 'success' : ($company->status === 'suspended' ? 'warning' : 'neutral') }}">
                        {{ $company->status === 'active' ? 'Активна' : ($company->status === 'suspended' ? 'Приостановлена' : 'В архиве') }}
                    </span>
                </div>
                <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                    <span>{{ $company->type === 'company' ? 'Юридическое лицо' : 'Индивидуальный предприниматель' }}</span>
                    @if ($company->short_name)
                        <span class="text-slate-300">·</span>
                        <span>{{ $company->short_name }}</span>
                    @endif
                </p>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-1">
                @can('update', $company)
                    <a href="{{ route('companies.edit', ['company' => $company, 'origin' => 'show']) }}"
                        class="crm-light-action">
                        <svg class="w-4 h-4 mr-1.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                        </svg>
                        Редактировать
                    </a>
                @endcan

                @can('delete', $company)
                    @if ($companyCanBeDeleted)
                        <form action="{{ route('companies.destroy', $company) }}" method="POST"
                            onsubmit="return confirm('Вы уверены, что хотите удалить эту компанию? Действие необратимо.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                class="inline-flex items-center rounded px-1.5 py-1 text-xs font-semibold text-red-600 transition hover:bg-red-50 hover:text-red-700">
                                <svg class="w-4 h-4 mr-1.5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                                Удалить
                            </button>
                        </form>
                    @endif
                @endcan
            </div>
        </div>
    </div>

    {{-- Финансы и детализация задолженности --}}
    @can('viewFinancials', $company)
    <section data-testid="company-financial-summary" class="mb-5 overflow-hidden border-y border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Финансы</h2>
        </div>
        <div>
            <dl class="grid divide-x divide-slate-200 {{ $stats['credit_balance'] > 0 ? 'grid-cols-2 sm:grid-cols-4 xl:grid-cols-5' : 'grid-cols-2 sm:grid-cols-4' }}">
                <div class="px-4 py-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Выставлено</dt>
                    <dd class="mt-1 font-semibold tabular-nums text-slate-900">{{ number_format($stats['total_invoiced'], 2) }} ₼</dd>
                </div>
                <div class="px-4 py-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Оплачено</dt>
                    <dd class="mt-1 font-semibold tabular-nums text-green-700">{{ number_format($stats['total_paid'], 2) }} ₼</dd>
                </div>
                <div class="px-4 py-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Общий долг</dt>
                    <dd class="mt-1 font-semibold tabular-nums {{ $stats['total_debt'] > 0 ? 'text-red-600' : 'text-slate-900' }}">{{ number_format($stats['total_debt'], 2) }} ₼</dd>
                </div>
                <div data-testid="overdue-summary"
                    class="border-l-2 px-4 py-3 {{ $overdueRemaining !== '0.00' ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-gray-50' }}">
                    <dt class="text-[11px] font-semibold uppercase tracking-wide {{ $overdueRemaining !== '0.00' ? 'text-red-600' : 'text-slate-500' }}">Просрочено</dt>
                    <dd class="mt-1 font-semibold tabular-nums {{ $overdueRemaining !== '0.00' ? 'text-red-700' : 'text-slate-900' }}">
                        {{ $overdueRemaining }} ₼
                    </dd>
                </div>
                @if ($stats['credit_balance'] > 0)
                    <div class="px-4 py-3">
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Баланс компании</dt>
                        <dd class="mt-1 font-semibold tabular-nums text-blue-700">{{ number_format($stats['credit_balance'], 2) }} ₼</dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- Задолженности по строкам выставленных инвойсов --}}
        @can('viewAny', \App\Models\Invoice::class)
        <div class="border-t border-slate-200">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                <h2 class="text-sm font-semibold text-slate-900">Задолженности</h2>
            </div>

        @if ($stats['total_debt'] <= 0)
            <p class="px-4 py-5 text-sm text-slate-500">У компании нет задолженности.</p>
        @else
            <div class="divide-y divide-slate-200">
                <section class="px-4 py-4">
                    <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">По подпискам</h3>
                @if ($subscriptionPeriodDebtGroups === [])
                    <p class="text-sm text-slate-500">Задолженностей нет.</p>
                @else
                @foreach ($subscriptionPeriodDebtGroups as $subscriptionDebt)
                    <div class="{{ !$loop->first ? 'mt-5 border-t border-slate-200 pt-4' : '' }}">
                        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2 mb-3">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-900">{{ $subscriptionDebt['subscription_title'] }}</h3>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Неоплаченных периодов: {{ $subscriptionDebt['totals']['unpaid_period_count'] }}
                                </p>
                            </div>
                            @if ($subscriptionDebt['totals']['overdue_remaining'] !== '0.00')
                                <div class="text-xs sm:text-right">
                                    <p class="font-semibold text-red-600 mt-0.5">Просрочено: {{ $subscriptionDebt['totals']['overdue_remaining'] }} ₼</p>
                                </div>
                            @endif
                        </div>

                        <div class="crm-table-scroll">
                            <table data-testid="company-debt-table" class="crm-table min-w-[940px] table-fixed">
                                <colgroup>
                                    @foreach ($companyDebtColumnWidths as $width)
                                        <col class="{{ $width }}">
                                    @endforeach
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Период</th>
                                        <th>Инвойс</th>
                                        <th>Сумма</th>
                                        <th>Оплачено</th>
                                        <th>Остаток</th>
                                        <th>Срок оплаты</th>
                                        <th>Статус</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($subscriptionDebt['periods'] as $period)
                                        <x-tables.clickable-row :url="route('invoices.show', ['invoice' => $period['invoice_id'], 'origin' => 'company', 'tab' => 'invoices'])" :label="'Открыть инвойс '.$period['invoice_number']">
                                            <td class="crm-table-primary whitespace-nowrap">{{ $period['period_label'] }}</td>
                                            <td class="whitespace-nowrap font-mono text-xs">
                                                <a href="{{ route('invoices.show', ['invoice' => $period['invoice_id'], 'origin' => 'company', 'tab' => 'invoices']) }}"
                                                    class="text-blue-600 hover:underline">
                                                    {{ $period['invoice_number'] }}
                                                </a>
                                            </td>
                                            <td class="crm-table-number">{{ $period['total'] }} ₼</td>
                                            <td class="crm-table-number text-green-700">{{ $period['allocated'] }} ₼</td>
                                            <td class="crm-table-number font-semibold text-red-600">{{ $period['remaining'] }} ₼</td>
                                            <td class="whitespace-nowrap">
                                                {{ $period['due_date_label'] }}
                                            </td>
                                            <td class="whitespace-nowrap">
                                                @if ($period['is_overdue'])
                                                    <span class="inline-flex rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-700">
                                                        Просрочено на {{ $period['days_overdue'] }} дн.
                                                    </span>
                                                @elseif ($period['payment_status'] === 'partially_paid')
                                                    @include('partials.badge', ['status' => 'partially_paid'])
                                                @else
                                                    <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                                                        К оплате
                                                    </span>
                                                @endif
                                            </td>
                                        </x-tables.clickable-row>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
                @endif
                </section>

                <section class="px-4 py-4">
                    <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">По разовым услугам</h3>
                    @if ($oneTimeServiceDebtLines === [])
                        <p class="text-sm text-slate-500">Задолженностей нет.</p>
                    @else
                        <div class="crm-table-scroll">
                            <table data-testid="company-debt-table" class="crm-table min-w-[940px] table-fixed">
                                <colgroup>
                                    @foreach ($companyDebtColumnWidths as $width)
                                        <col class="{{ $width }}">
                                    @endforeach
                                </colgroup>
                                <thead><tr>
                                    <th>Услуга</th><th>Инвойс</th>
                                    <th>Сумма</th><th>Оплачено</th>
                                    <th>Остаток</th><th>Срок оплаты</th>
                                    <th>Статус</th>
                                </tr></thead>
                                <tbody>
                                @foreach ($oneTimeServiceDebtLines as $line)
                                    <x-tables.clickable-row :url="route('invoices.show', ['invoice' => $line['invoice_id'], 'origin' => 'company', 'tab' => 'invoices'])" :label="'Открыть инвойс '.$line['invoice_number']">
                                        <td class="crm-table-primary">{{ $line['service_title'] }}</td>
                                        <td class="whitespace-nowrap font-mono text-xs"><a class="text-blue-600 hover:underline" href="{{ route('invoices.show', ['invoice' => $line['invoice_id'], 'origin' => 'company', 'tab' => 'invoices']) }}">{{ $line['invoice_number'] }}</a></td>
                                        <td class="crm-table-number">{{ $line['total'] }} ₼</td>
                                        <td class="crm-table-number text-green-700">{{ $line['allocated'] }} ₼</td>
                                        <td class="crm-table-number font-semibold text-red-600">{{ $line['remaining'] }} ₼</td>
                                        <td class="whitespace-nowrap">{{ $line['due_date_label'] }}</td>
                                        <td class="whitespace-nowrap">
                                            @if ($line['is_overdue']) <span class="inline-flex rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-700">Просрочено на {{ $line['days_overdue'] }} дн.</span>
                                            @elseif ($line['payment_status'] === 'partially_paid')
                                                @include('partials.badge', ['status' => 'partially_paid'])
                                            @else <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">К оплате</span> @endif
                                        </td>
                                    </x-tables.clickable-row>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            </div>
        @endif

        @if ($subscriptionPeriodDebts['anomalies'] !== [])
            <div class="mx-4 mb-4 border border-yellow-200 bg-yellow-50 px-3 py-2 text-sm text-yellow-800">
                Есть строки подписок без корректно указанного расчётного периода: {{ $subscriptionPeriodDebtAnomalyCount }}.
            </div>
        @endif
        </div>
        @endcan
    </section>
    @endcan

    {{-- Основная информация --}}
    <section data-testid="company-information" class="mb-5 overflow-hidden border-y border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Основная информация</h2>
        </div>

        <dl class="grid grid-cols-1 gap-x-8 px-4 sm:grid-cols-2">
                    @if ($company->voen)
                        <div data-testid="company-voen" class="border-b border-slate-100 py-3">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">VÖEN (ИНН)</dt>
                            <dd class="mt-1 font-mono text-sm font-semibold text-slate-900">{{ $company->voen }}</dd>
                        </div>
                    @endif
                    @if ($company->email)
                        <div data-testid="company-email" class="border-b border-slate-100 py-3">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Электронная почта</dt>
                            <dd class="mt-1 text-sm"><a href="mailto:{{ $company->email }}" class="text-blue-600 hover:underline">{{ $company->email }}</a></dd>
                        </div>
                    @endif
                    @if ($company->phone)
                        <div data-testid="company-phone" class="border-b border-slate-100 py-3">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Телефон</dt>
                            <dd class="mt-1 text-sm"><a href="tel:{{ $company->phone }}" class="text-slate-900 hover:text-blue-600">{{ $company->phone }}</a></dd>
                        </div>
                    @endif
                    @if ($company->website)
                        <div data-testid="company-website" class="border-b border-slate-100 py-3">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Сайт</dt>
                            <dd class="mt-1 text-sm">
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
                        <div data-testid="company-legal-address" class="border-b border-slate-100 py-3">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Юридический адрес</dt>
                            <dd class="mt-1 text-sm text-slate-900">{{ $company->legal_address }}</dd>
                        </div>
                    @endif
                    @if ($company->actual_address)
                        <div data-testid="company-actual-address" class="border-b border-slate-100 py-3">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Фактический адрес</dt>
                            <dd class="mt-1 text-sm text-slate-900">{{ $company->actual_address }}</dd>
                        </div>
                    @endif
        </dl>

                @if ($company->bank_name || $company->iban || $company->bank_code || $company->bank_voen || $company->swift)
                    <div data-testid="company-bank-details" class="border-t border-slate-200 px-4 py-3">
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Банковские реквизиты</h3>
                        <dl class="grid grid-cols-1 gap-x-8 sm:grid-cols-2">
                            @if ($company->bank_name)
                                <div class="py-2"><dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Банк</dt><dd class="mt-1 text-sm font-medium text-slate-900">{{ $company->bank_name }}</dd></div>
                            @endif
                            @if ($company->iban)
                                <div class="py-2"><dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">IBAN</dt><dd class="mt-1 break-all font-mono text-xs text-slate-900">{{ $company->iban }}</dd></div>
                            @endif
                            @if ($company->bank_code)
                                <div class="py-2"><dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Код банка</dt><dd class="mt-1 font-mono text-sm text-slate-900">{{ $company->bank_code }}</dd></div>
                            @endif
                            @if ($company->swift)
                                <div class="py-2"><dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">SWIFT</dt><dd class="mt-1 font-mono text-sm text-slate-900">{{ $company->swift }}</dd></div>
                            @endif
                            @if ($company->bank_voen)
                                <div class="py-2"><dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">VÖEN банка</dt><dd class="mt-1 font-mono text-sm text-slate-900">{{ $company->bank_voen }}</dd></div>
                            @endif
                        </dl>
                    </div>
                @endif

                @if ($company->comment)
                    <div class="border-t border-slate-200 px-4 py-3">
                        <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Примечание</h3>
                        <p class="whitespace-pre-line text-sm text-slate-700">{{ $company->comment }}</p>
                    </div>
                @endif

    </section>

    {{-- Связанные разделы --}}
    <div data-testid="company-tabs" class="overflow-hidden border-y border-slate-200 bg-white"
            x-data="{
                tab: @js($activeTab),
                selectTab(value) {
                    this.tab = value;
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', value);
                    window.history.replaceState({}, '', url);
                }
            }">

            {{-- Компактная навигация по связанным данным --}}
            <div class="flex flex-col gap-2 border-b border-slate-200 px-4 sm:flex-row sm:items-center sm:justify-between">
                <nav class="-mb-px flex min-w-0 gap-5 overflow-x-auto" aria-label="Tabs">
                    <button @click="selectTab('contacts')"
                        :class="tab === 'contacts' ? 'border-blue-600 text-blue-600' :
                            'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'"
                        class="whitespace-nowrap border-b-2 px-1 py-3 text-xs font-semibold transition">
                        Контакты ({{ $company->contacts->count() }})
                    </button>
                    @can('viewAny', \App\Models\Contract::class)
                        <button @click="selectTab('contracts')"
                            :class="tab === 'contracts' ? 'border-blue-600 text-blue-600' :
                                'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'"
                            class="whitespace-nowrap border-b-2 px-1 py-3 text-xs font-semibold transition">
                            Договоры ({{ $company->contracts->count() }})
                        </button>
                    @endcan
                    @can('viewFinancials', $company)
                        @can('viewAny', \App\Models\Invoice::class)
                            <button @click="selectTab('invoices')"
                                :class="tab === 'invoices' ? 'border-blue-600 text-blue-600' :
                                    'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'"
                                class="whitespace-nowrap border-b-2 px-1 py-3 text-xs font-semibold transition">
                                Инвойсы ({{ $company->invoices->count() }})
                            </button>
                        @endcan
                        @can('viewAny', \App\Models\Payment::class)
                            <button @click="selectTab('payments')"
                                :class="tab === 'payments' ? 'border-blue-600 text-blue-600' :
                                    'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'"
                                class="whitespace-nowrap border-b-2 px-1 py-3 text-xs font-semibold transition">
                                Платежи ({{ $company->payments->count() }})
                            </button>
                        @endcan
                    @endcan
                    <a href="?{{ http_build_query(\App\Support\CompanyPageContext::query('activity')) }}"
                        :class="tab === 'activity' ? 'border-blue-600 text-blue-600' :
                            'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'"
                        class="whitespace-nowrap border-b-2 px-1 py-3 text-xs font-semibold transition">
                        Активность
                    </a>
                </nav>
                <div class="flex shrink-0 items-center gap-2 pb-2 sm:pb-0 sm:pl-4">
                    <form x-show="tab === 'activity'" x-cloak method="GET" class="shrink-0">
                        <input type="hidden" name="tab" value="activity">
                        <select name="activity_category" onchange="this.form.submit()"
                            class="rounded border border-slate-300 bg-white py-1.5 pl-2 pr-8 text-xs font-medium text-slate-700 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                            aria-label="Фильтр активности">
                            <option value="">Все события</option>
                            @foreach (\App\Support\CompanyActivityCategory::cases() as $category)
                                @if ($category !== \App\Support\CompanyActivityCategory::Company)
                                <option value="{{ $category->value }}" @selected($activityCategory?->value === $category->value)>
                                    {{ match ($category) {
                                        \App\Support\CompanyActivityCategory::Contacts => 'Контакты',
                                        \App\Support\CompanyActivityCategory::Contracts => 'Договоры',
                                        \App\Support\CompanyActivityCategory::Invoices => 'Инвойсы',
                                        \App\Support\CompanyActivityCategory::Payments => 'Платежи',
                                        \App\Support\CompanyActivityCategory::Documents => 'Документы',
                                        default => 'Компания',
                                    } }}
                                </option>
                                @endif
                            @endforeach
                        </select>
                    </form>
                    @can('create', [\App\Models\CompanyContact::class, $company])
                        <a x-show="tab === 'contacts'" href="{{ route('companies.contacts.create', ['company' => $company, 'origin' => 'company', 'tab' => 'contacts']) }}"
                            class="crm-light-action">
                            <span aria-hidden="true">+</span> Контакт
                        </a>
                    @endcan
                    @can('create', \App\Models\Contract::class)
                        @can('viewAny', \App\Models\Contract::class)
                            <a x-show="tab === 'contracts'" x-cloak href="{{ route('companies.contracts.create', ['company' => $company, 'origin' => 'company', 'tab' => 'contracts']) }}"
                                class="crm-light-action">
                                <span aria-hidden="true">+</span> Договор
                            </a>
                        @else
                            <a href="{{ route('companies.contracts.create', ['company' => $company]) }}"
                                class="crm-light-action">
                                <span aria-hidden="true">+</span> Договор
                            </a>
                        @endcan
                    @endcan
                    @if ($company->status === 'active')
                        @can(\App\Support\Access\PermissionName::InvoicesCreate->value)
                            <a x-show="tab === 'invoices'" x-cloak href="{{ route('invoices.create', ['company_id' => $company]) }}"
                                class="crm-light-action">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                                </svg>
                                Выставить счёт
                            </a>
                        @endcan
                    @endif
                </div>
            </div>

            {{-- Таб: Активность --}}
            <div x-show="tab === 'activity'" x-cloak class="p-4 sm:p-5">
                @if ($activityEvents->isEmpty())
                    <div class="px-1 py-8 text-center">
                        <p class="text-sm font-medium text-slate-700">Событий пока нет.</p>
                        <p class="mt-1 text-xs text-slate-500">Новые действия по компании будут появляться здесь.</p>
                    </div>
                @else
                    <div class="relative overflow-hidden" data-testid="company-activity-timeline">
                        <div class="pointer-events-none absolute bottom-0 left-[14px] top-0 w-px bg-slate-200"></div>
                        @foreach ($activityEvents as $event)
                            <div data-testid="activity-row" class="relative grid min-h-[40px] grid-cols-[28px_minmax(110px,1fr)_minmax(0,1fr)] items-center gap-x-3 border-b border-slate-100 py-1.5 last:border-b-0 sm:grid-cols-[28px_155px_minmax(220px,1.2fr)_minmax(170px,1fr)_100px] sm:gap-x-3">
                                <div class="relative flex items-center justify-center">
                                    <x-activity.icon :type="$event['icon']" :tone="$event['tone']" />
                                </div>
                                <time class="text-xs tabular-nums text-slate-500">{{ $event['time_label'] }}</time>
                                <div class="min-w-0">
                                    @if ($event['subject_url'])
                                        <a href="{{ $event['subject_url'] }}" class="text-sm font-medium text-slate-900 hover:text-blue-600 hover:underline">{{ $event['title'] }}</a>
                                    @else
                                        <p class="text-sm font-medium text-slate-900">{{ $event['title'] }}</p>
                                    @endif
                                </div>
                                <div class="hidden min-w-0 truncate text-xs text-slate-500 sm:block">
                                    @if ($event['context_url'] && $event['context'])
                                        <a href="{{ $event['context_url'] }}" class="hover:text-blue-600 hover:underline">{{ $event['context'] }}</a>
                                    @else
                                        {{ $event['context'] ?? '—' }}
                                    @endif
                                </div>
                                <div class="hidden truncate text-right text-xs text-slate-500 sm:block" title="{{ $event['actor_label'] }}">
                                    {{ $event['actor_label'] }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @if ($activityPage->hasMorePages())
                        <div class="pt-4 text-center">
                            <a href="{{ $activityPage->nextPageUrl() }}" class="text-xs font-semibold text-blue-600 hover:text-blue-700 hover:underline">Показать ещё</a>
                        </div>
                    @endif
                @endif
            </div>

            {{-- Таб: Контакты --}}
            <div x-show="tab === 'contacts'" class="p-4 sm:p-5">
                <div class="crm-table-scroll">
                    <table class="crm-table">
                        <thead>
                            <tr>
                                <th>Контакт</th>
                                <th>Роль</th>
                                <th>Телефон и e-mail</th>
                                <th>Комментарий</th>
                                <th class="crm-table-actions"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($company->contacts as $contact)
                                <tr>
                                    <td>
                                        <div class="crm-table-primary">{{ $contact->first_name }}
                                            {{ $contact->last_name }}</div>
                                        @if ($contact->position)
                                            <div class="crm-table-secondary mt-0.5">{{ $contact->position }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="crm-badge crm-badge-neutral">
                                            {{ $contact->role ?? 'Контакт' }}
                                        </span>
                                    </td>
                                    <td>
                                        @if ($contact->phone)
                                            <div class="text-xs"><a href="tel:{{ $contact->phone }}" class="text-gray-900 hover:text-blue-600">{{ $contact->phone }}</a></div>
                                        @endif
                                        @if ($contact->email)
                                            <div class="text-xs mt-0.5"><a href="mailto:{{ $contact->email }}" class="text-blue-600 hover:underline">{{ $contact->email }}</a></div>
                                        @endif
                                    </td>
                                    <td class="text-xs text-slate-500">{{ $contact->comment ?? '—' }}</td>
                                    <td class="crm-table-actions">
                                        <div class="inline-flex items-center gap-3">
                                            @can('update', $contact)
                                                <a href="{{ route('contacts.edit', ['contact' => $contact, 'origin' => 'company', 'tab' => 'contacts']) }}"
                                                    class="crm-table-action-link">Редакт.</a>
                                            @endcan
                                            @can('delete', $contact)
                                                <form action="{{ route('contacts.destroy', $contact) }}" method="POST"
                                                    onsubmit="return confirm('Удалить контактное лицо?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <input type="hidden" name="origin" value="company">
                                                    <input type="hidden" name="tab" value="contacts">
                                                    <button type="submit" class="text-xs font-semibold text-red-600 transition hover:text-red-700">
                                                        Удалить
                                                    </button>
                                                </form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="crm-table-empty">
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
            @can('viewAny', \App\Models\Contract::class)
            <div x-show="tab === 'contracts'" x-cloak class="p-4 sm:p-5">
                <div class="crm-table-scroll">
                    <table class="crm-table">
                        <thead>
                            <tr>
                                <th>Номер договора</th>
                                <th>Дата начала</th>
                                <th>Дата окончания</th>
                                <th>Статус</th>
                                <th>Предметы договора</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($company->contracts as $contract)
                                <x-tables.clickable-row :url="route('contracts.show', ['contract' => $contract, 'origin' => 'company', 'tab' => 'contracts'])" :label="'Открыть договор '.$contract->contract_number">
                                    <td class="crm-table-number"><a href="{{ route('contracts.show', ['contract' => $contract, 'origin' => 'company', 'tab' => 'contracts']) }}" class="crm-table-primary-link">{{ $contract->contract_number }}</a>
                                    </td>
                                    <td class="crm-table-date">{{ $contract->start_date?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="crm-table-date">
                                        {{ $contract->end_date?->format('d/m/Y') ?? 'Бессрочный' }}</td>
                                    <td>
                                        @include('partials.badge', [
                                            'status' => $contract->effective_status,
                                        ])
                                    </td>
                                    <td class="crm-table-number">{{ $contract->orders_count + $contract->subscriptions_count }}</td>
                                </x-tables.clickable-row>
                            @empty
                                <tr>
                                    <td colspan="5" class="crm-table-empty">
                                        У компании пока нет договоров.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @endcan

            {{-- Таб: Инвойсы --}}
            @can('viewFinancials', $company)
            @can('viewAny', \App\Models\Invoice::class)
            <div x-show="tab === 'invoices'" x-cloak class="p-4 sm:p-5">
                <div class="crm-table-scroll">
                    <table class="crm-table">
                        <thead>
                            <tr>
                                <th>Номер счета</th>
                                <th>Расчётный период</th>
                                <th>Выставлен / срок</th>
                                <th>Сумма</th>
                                <th>Оплачено / Остаток</th>
                                <th>Статус</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($company->invoices as $invoice)
                                @php($paymentSource = $invoicePaymentSources->get($invoice->id))
                                <x-tables.clickable-row :url="route('invoices.show', ['invoice' => $invoice, 'origin' => 'company', 'tab' => 'invoices'])" :label="'Открыть инвойс '.$invoice->invoice_number">
                                    <td class="crm-table-number">
                                        <a href="{{ route('invoices.show', ['invoice' => $invoice, 'origin' => 'company', 'tab' => 'invoices']) }}" class="crm-table-primary-link">{{ $invoice->invoice_number }}</a>
                                    </td>
                                    <td>
                                        @php($billingPeriod = $invoiceBillingPeriods->get($invoice->id))
                                        <div class="text-xs text-slate-900">{{ $billingPeriod['label'] }}</div>
                                        @if ($billingPeriod['count_label'])
                                            <div class="mt-0.5 text-[11px] text-slate-500">{{ $billingPeriod['count_label'] }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="text-xs text-slate-900">{{ $invoice->issue_date ? \Illuminate\Support\Carbon::parse($invoice->issue_date)->format('d/m/Y') : '—' }}</div>
                                        <div class="mt-0.5 flex items-center gap-1 text-xs font-medium text-slate-500">
                                            <span>до {{ $invoice->due_date ? \Illuminate\Support\Carbon::parse($invoice->due_date)->format('d/m/Y') : '—' }}</span>
                                            @if ($invoice->is_overdue)
                                                <span
                                                    class="crm-badge crm-badge-danger">Просрочен</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="crm-table-numeric font-semibold text-slate-900">
                                        {{ number_format($invoice->total_amount, 2) }} ₼
                                    </td>
                                    <td class="text-xs">
                                        <div class="text-green-600 font-medium">Оплачено:
                                            {{ number_format($invoice->applied_amount, 2) }} ₼</div>
                                        @if ($paymentSource['credit_balance_applied_minor'] > 0)
                                            <div class="text-blue-600 font-medium mt-0.5">Из баланса: {{ number_format((float) $paymentSource['credit_balance_applied_amount'], 2) }} ₼</div>
                                        @endif
                                        @if ($invoice->overpayment_amount > 0)
                                            <div class="text-blue-600 font-medium mt-0.5">Переплата:
                                                {{ number_format($invoice->overpayment_amount, 2) }} ₼</div>
                                        @endif
                                        @if ((float) ($invoice->pending_amount ?? 0) > 0)
                                            <div class="text-amber-600 font-medium mt-0.5">Ожидает подтверждения: {{ number_format((float) $invoice->pending_amount, 2) }} ₼</div>
                                        @endif
                                        @if ($invoice->remaining_amount > 0)
                                            <div class="text-red-500 font-medium mt-0.5">Долг:
                                                {{ number_format($invoice->remaining_amount, 2) }} ₼</div>
                                        @endif
                                    </td>
                                    <td>
                                        @include('partials.badge', ['status' => $invoice->status])
                                    </td>
                                </x-tables.clickable-row>
                            @empty
                                <tr>
                                    <td colspan="6" class="crm-table-empty">У компании пока нет инвойсов.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @endcan
            @endcan

            {{-- Таб: Платежи --}}
            @can('viewFinancials', $company)
            @can('viewAny', \App\Models\Payment::class)
            <div x-show="tab === 'payments'" x-cloak class="p-4 sm:p-5">
                <div class="crm-table-scroll">
                    <table class="crm-table">
                        <thead>
                            <tr>
                                <th>Дата платежа</th>
                                <th>Счет (Инвойс)</th>
                                <th>Сумма платежа</th>
                                <th>Способ</th>
                                <th>Статус</th>
                                <th>Транзакция / Описание</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($company->payments as $payment)
                                <tr>
                                    <td class="crm-table-date font-medium text-slate-900">
                                        {{ $payment->payment_date ? \Illuminate\Support\Carbon::parse($payment->payment_date)->format('d/m/Y') : '—' }}
                                    </td>
                                    <td class="font-mono text-xs">
                                        @can('viewAny', \App\Models\Invoice::class)
                                            @if ($payment->invoice)
                                                <a href="{{ route('invoices.show', ['invoice' => $payment->invoice, 'origin' => 'company', 'tab' => 'payments']) }}"
                                                    class="text-blue-600 hover:underline">
                                                    {{ $payment->invoice->invoice_number }}
                                                </a>
                                            @else
                                                —
                                            @endif
                                        @else
                                            —
                                        @endcan
                                    </td>
                                    <td class="crm-table-numeric font-semibold text-green-600">
                                        + {{ number_format($payment->amount, 2) }} ₼
                                    </td>
                                    <td class="text-sm text-slate-700">
                                        {{ match ($payment->payment_method) {
                                            'transfer' => 'Безналичный',
                                            'card' => 'Карта',
                                            'cash' => 'Наличные',
                                            default => $payment->payment_method,
                                        } }}
                                    </td>
                                    <td>
                                        @include('partials.badge', ['status' => $payment->status])
                                    </td>
                                    <td class="text-xs text-slate-500">
                                        <div class="font-mono">{{ $payment->transaction_reference ?? '—' }}</div>
                                        @if ($payment->comment)
                                            <div class="text-gray-400 mt-0.5 italic">{{ $payment->comment }}</div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="crm-table-empty">Платежи отсутствуют.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @endcan
            @endcan

        </div>

@endsection
