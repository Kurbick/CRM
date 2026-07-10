@extends('layouts.app')

@section('title', $company->name)

@section('content')

    {{-- Навигация и заголовок --}}
    <div class="mb-6">
        <a href="{{ route('companies.index') }}" class="text-sm text-gray-500 hover:text-gray-900 transition flex items-center gap-1.5 mb-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Назад к списку
        </a>
        
        <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-4">
            <div class="flex items-center gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ $company->name }}</h1>
                    <p class="text-sm text-gray-500 mt-1 flex items-center gap-2">
                        <span>{{ $company->type === 'company' ? 'Юридическое лицо' : 'Индивидуальный предприниматель' }}</span>
                        @if($company->short_name)
                            <span class="text-gray-300">|</span>
                            <span>{{ $company->short_name }}</span>
                        @endif
                    </p>
                </div>
                <div class="self-start sm:self-center">
                    @include('partials.badge', ['status' => $company->status])
                </div>
            </div>
            
            <div class="flex items-center gap-2">
                <a href="{{ route('companies.edit', $company) }}"
                   class="inline-flex items-center text-sm border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 px-4 py-2 rounded-lg font-medium transition shadow-sm">
                    <svg class="w-4 h-4 mr-1.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
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
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                        Удалить
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Сводная статистика по компании --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Долг</p>
            <p class="text-2xl font-bold mt-1.5 {{ $stats['total_debt'] > 0 ? 'text-red-600' : 'text-gray-900' }}">
                {{ number_format($stats['total_debt'], 2) }} ₼
            </p>
        </div>
        
        <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Всего выставлено</p>
            <p class="text-2xl font-bold text-gray-900 mt-1.5">{{ number_format($stats['total_invoiced'], 2) }} ₼</p>
            <p class="text-xs text-gray-400 mt-1">По всем активным счетам (без учета отмененных)</p>
        </div>
        
        {{-- Credit Balance — переплата клиента --}}
        @php $creditAmount = $company->creditBalance?->amount ?? 0; @endphp
        @if($creditAmount > 0)
        <div class="bg-blue-50 rounded-xl border border-blue-200 p-5 shadow-sm">
            <p class="text-xs font-semibold text-blue-500 uppercase tracking-wide">Кредитный баланс</p>
            <p class="text-2xl font-bold text-blue-700 mt-1.5">{{ number_format($creditAmount, 2) }} ₼</p>
            <p class="text-xs text-blue-400 mt-1">Будет применён к следующему инвойсу автоматически</p>
        </div>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        {{-- Левая колонка: Детали компании --}}
        <div class="space-y-6">
            
            {{-- Карточка: Реквизиты --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h3 class="font-bold text-gray-900 mb-4 pb-2 border-b border-gray-100 text-sm uppercase tracking-wider text-gray-500">Детали контрагента</h3>
                
                <dl class="space-y-4 text-sm">
                    <div>
                        <dt class="text-xs font-medium text-gray-400 uppercase">VÖEN (ИНН)</dt>
                        <dd class="mt-0.5 font-semibold font-mono text-gray-900">{{ $company->voen ?? '—' }}</dd>
                    </div>
                    
                    <div>
                        <dt class="text-xs font-medium text-gray-400 uppercase">Режим счетов</dt>
                        <dd class="mt-0.5 text-gray-900">
                            {{ $company->invoice_mode === 'separate' ? 'Раздельный (по заказам)' : 'Сводный (месячный)' }}
                        </dd>
                    </div>
                    
                    <div>
                        <dt class="text-xs font-medium text-gray-400 uppercase">Электронная почта</dt>
                        <dd class="mt-0.5 text-gray-900">
                            @if($company->email)
                                <a href="mailto:{{ $company->email }}" class="text-blue-600 hover:underline">{{ $company->email }}</a>
                            @else
                                —
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium text-gray-400 uppercase">Телефон</dt>
                        <dd class="mt-0.5 text-gray-900">{{ $company->phone ?? '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium text-gray-400 uppercase">Сайт</dt>
                        <dd class="mt-0.5 text-gray-900">
                            @if($company->website)
                                <a href="{{ $company->website }}" target="_blank" rel="noopener" class="text-blue-600 hover:underline inline-flex items-center gap-1">
                                    {{ $company->website }}
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                    </svg>
                                </a>
                            @else
                                —
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium text-gray-400 uppercase">Юридический адрес</dt>
                        <dd class="mt-0.5 text-gray-900">{{ $company->legal_address ?? '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium text-gray-400 uppercase">Фактический адрес</dt>
                        <dd class="mt-0.5 text-gray-900">{{ $company->actual_address ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Карточка: Банк --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h3 class="font-bold text-gray-900 mb-4 pb-2 border-b border-gray-100 text-sm uppercase tracking-wider text-gray-500">Банковские реквизиты</h3>
                
                @if($company->bank_name || $company->iban || $company->bank_code || $company->bank_voen || $company->swift)
                    <dl class="space-y-4 text-sm">
                        <div>
                            <dt class="text-xs font-medium text-gray-400 uppercase">Банк</dt>
                            <dd class="mt-0.5 text-gray-900 font-medium">{{ $company->bank_name ?? '—' }}</dd>
                        </div>
                        
                        <div>
                            <dt class="text-xs font-medium text-gray-400 uppercase">IBAN (Расчетный счет)</dt>
                            <dd class="mt-0.5 font-mono text-gray-900 text-xs break-all">{{ $company->iban ?? '—' }}</dd>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <dt class="text-xs font-medium text-gray-400 uppercase">Код (Kod)</dt>
                                <dd class="mt-0.5 font-mono text-gray-900">{{ $company->bank_code ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-gray-400 uppercase">SWIFT</dt>
                                <dd class="mt-0.5 font-mono text-gray-900">{{ $company->swift ?? '—' }}</dd>
                            </div>
                        </div>

                        <div>
                            <dt class="text-xs font-medium text-gray-400 uppercase">VÖEN Банка</dt>
                            <dd class="mt-0.5 font-mono text-gray-900">{{ $company->bank_voen ?? '—' }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="text-sm text-gray-400 text-center py-4">Банковские реквизиты не заполнены.</p>
                @endif
            </div>

            {{-- Карточка: Комментарий --}}
            @if($company->comment)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h3 class="font-bold text-gray-900 mb-2 pb-2 border-b border-gray-100 text-sm uppercase tracking-wider text-gray-500">Примечание</h3>
                    <p class="text-sm text-gray-700 whitespace-pre-line">{{ $company->comment }}</p>
                </div>
            @endif

        </div>

        {{-- Правая колонка: Интерактивные табы (AlpineJS) --}}
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden" x-data="{ tab: 'contacts' }">
            
            {{-- Заголовки табов --}}
            <div class="border-b border-gray-200 bg-gray-50/50 px-6">
                <nav class="-mb-px flex gap-6" aria-label="Tabs">
                    <button @click="tab = 'contacts'"
                            :class="tab === 'contacts' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                        Контакты ({{ $company->contacts->count() }})
                    </button>
                    <button @click="tab = 'contracts'"
                            :class="tab === 'contracts' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                        Договоры ({{ $company->contracts->count() }})
                    </button>
                    <button @click="tab = 'invoices'"
                            :class="tab === 'invoices' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                        Инвойсы ({{ $company->invoices->count() }})
                    </button>
                    <button @click="tab = 'payments'"
                            :class="tab === 'payments' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                        Платежи ({{ $company->payments->count() }})
                    </button>
                </nav>
            </div>

          {{-- Таб: Контакты --}}
            <div x-show="tab === 'contacts'" class="space-y-4">
                <div class="flex justify-end mb-3">
                    <a href="{{ route('companies.contacts.create', $company) }}"
                    class="inline-flex items-center text-sm bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition font-medium">
                        + Добавить контакт
                    </a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="border-b border-gray-100 text-gray-400 pb-2">
                                <th class="pb-3 font-semibold text-xs uppercase">Имя / Должность</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Роль</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Телефон / E-mail</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Комментарий</th>
                                <th class="pb-3 font-semibold text-xs uppercase"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50 text-gray-700">
                            @forelse($company->contacts as $contact)
                                <tr>
                                    <td class="py-3">
                                        <div class="font-medium text-gray-900">{{ $contact->first_name }} {{ $contact->last_name }}</div>
                                        <div class="text-xs text-gray-400 mt-0.5">{{ $contact->position ?? '—' }}</div>
                                    </td>
                                    <td class="py-3">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
                                            {{ $contact->role ?? 'Контакт' }}
                                        </span>
                                    </td>
                                    <td class="py-3">
                                        <div class="text-xs text-gray-900">{{ $contact->phone ?? '—' }}</div>
                                        <div class="text-xs text-gray-400 mt-0.5">{{ $contact->email ?? '—' }}</div>
                                    </td>
                                    <td class="py-3 text-xs text-gray-400">{{ $contact->comment ?? '—' }}</td>
                                    <td class="py-3 text-right">
                                        <a href="{{ route('contacts.edit', $contact) }}"
                                        class="text-gray-400 hover:text-blue-600 text-xs transition">Редакт.</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-8 text-center text-gray-400">
                                        Контакты отсутствуют.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Таб: Договоры --}}
            <div x-show="tab === 'contracts'" class="space-y-4">
                <div class="flex justify-end mb-3">
                    <a href="{{ route('companies.contracts.create', $company) }}"
                    class="inline-flex items-center text-sm bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition font-medium">
                        + Добавить договор
                    </a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="border-b border-gray-100 text-gray-400 pb-2">
                                <th class="pb-3 font-semibold text-xs uppercase">Номер договора</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Дата начала</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Дата окончания</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Статус</th>
                                <th class="pb-3 font-semibold text-xs uppercase">Документ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50 text-gray-700">
                            @forelse($company->contracts as $contract)
                                <tr>
                                    <td class="py-3 font-medium text-gray-900 font-mono">{{ $contract->contract_number }}</td>
                                    <td class="py-3 text-gray-600">{{ $contract->start_date }}</td>
                                    <td class="py-3 text-gray-400">{{ $contract->end_date ?? 'Бессрочный' }}</td>
                                    <td class="py-3">
                                        @include('partials.badge', ['status' => $contract->status])
                                    </td>
                                    <td class="py-3 text-xs">
                                        @if($contract->signed_document)
                                            <a href="#" class="text-blue-600 hover:underline">Скачать документ</a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-8 text-center text-gray-400">
                                        Договоры отсутствуют.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

                {{-- Таб: Инвойсы --}}
                <div x-show="tab === 'invoices'" class="space-y-4">
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
                                        <td class="py-3 font-medium text-gray-900 font-mono">
                                            {{ $invoice->invoice_number }}
                                        </td>
                                        <td class="py-3">
                                            <div class="text-gray-900 text-xs">{{ $invoice->issue_date }}</div>
                                            <div class="text-red-500 text-xs mt-0.5 font-medium flex items-center gap-1">
                                                <span>до {{ $invoice->due_date }}</span>
                                                @if($invoice->is_overdue)
                                                    <span class="bg-red-100 text-red-800 text-[10px] px-1 py-0.2 rounded">Просрочен</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="py-3 font-semibold text-gray-900">
                                            {{ number_format($invoice->total_amount, 2) }} ₼
                                        </td>
                                        <td class="py-3 text-xs">
                                            <div class="text-green-600 font-medium">Оплачено: {{ number_format($invoice->paid_amount, 2) }} ₼</div>
                                            @if($invoice->remaining_amount > 0)
                                                <div class="text-red-500 font-medium mt-0.5">Долг: {{ number_format($invoice->remaining_amount, 2) }} ₼</div>
                                            @endif
                                        </td>
                                        <td class="py-3">
                                            @include('partials.badge', ['status' => $invoice->status])
                                        </td>
                                        <td class="py-3 text-right">
                                            <a href="{{ route('invoices.show', $invoice) }}" class="text-blue-600 hover:text-blue-800 font-semibold">
                                                Открыть →
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="py-8 text-center text-gray-400">Инвойсы отсутствуют.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Таб: Платежи --}}
                <div x-show="tab === 'payments'" class="space-y-4">
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
                                            {{ $payment->payment_date }}
                                        </td>
                                        <td class="py-3 font-mono text-xs">
                                            @if($payment->invoice)
                                                <a href="{{ route('invoices.show', $payment->invoice) }}" class="text-blue-600 hover:underline">
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
                                            @if($payment->comment)
                                                <div class="text-gray-400 mt-0.5 italic">{{ $payment->comment }}</div>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="py-8 text-center text-gray-400">Платежи отсутствуют.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
            
        </div>

    </div>

@endsection
