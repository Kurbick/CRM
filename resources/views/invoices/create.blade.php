@extends('layouts.app')

@section('title', 'Новый инвойс')

@section('content')
@php
    $oldSubscriptionOccurrences = $oldSubscriptionOccurrences ?? collect();
    $companyOptions = $companies->map(fn ($company) => [
        'id' => (string) $company->id,
        'name' => $company->name,
    ])->values();
    $oldCompanyId = (string) old('company_id', $prefilledCompany?->id ?? '');
    $oldCompanyName = $companies->firstWhere('id', (int) $oldCompanyId)?->name ?? '';
    $oldContractId = (string) old('contract_id', $prefilledContract?->id ?? '');
    $oldLines = collect(old('lines', []))
        ->filter(fn ($line): bool => is_array($line)
            && (filled($line['subscription_id'] ?? null) || filled($line['order_id'] ?? null)))
        ->map(function (array $line) use ($oldSubscriptionAmounts, $oldSubscriptionOccurrences): array {
            $subscriptionId = $line['subscription_id'] ?? null;

            if (is_numeric($subscriptionId) && $oldSubscriptionAmounts->has((int) $subscriptionId)) {
                // Display only: persistence still canonicalizes the amount in CreateInvoice.
                $line['amount'] = $oldSubscriptionAmounts->get((int) $subscriptionId);
                $line['stale_occurrences'] = $oldSubscriptionOccurrences->get((int) $subscriptionId, []);
            }

            return $line;
        })
        ->values()
        ->all();
    $lineErrors = collect($errors->getMessages())
        ->filter(fn ($messages, $key): bool => preg_match('/^lines\.\d+\.(?:period_count|subscription_id)$/', $key) === 1)
        ->map(fn (array $messages): string => $messages[0])
        ->all();
    $formErrors = collect($errors->getMessages())
        ->reject(fn ($messages, $key): bool => preg_match('/^lines\.\d+\.(?:period_count|subscription_id)$/', $key) === 1)
        ->flatten()
        ->unique()
        ->values();
    $defaultInvoiceNumber = 'INV-' . strtoupper(substr(uniqid(), -6));
    $defaultIssueDate = now()->toDateString();
@endphp

<div class="mb-5">
    <a href="{{ $backUrl }}" class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 transition hover:text-slate-900">
        <span aria-hidden="true">←</span>
        {{ $backLabel }}
    </a>
    <h1 class="mt-3 text-xl font-semibold text-slate-900">Новый инвойс</h1>
</div>

<form method="POST" action="{{ route('invoices.store') }}"
    x-data="invoiceCreateForm({
        companies: @js($companyOptions),
        selectedCompanyId: @js($oldCompanyId),
        companyQuery: @js($oldCompanyName),
        selectedContractId: @js($oldContractId),
        oldLines: @js($oldLines),
        lineErrors: @js($lineErrors),
        invoiceNumber: @js(old('invoice_number', '')),
        issueDate: @js(old('issue_date', '')),
        dueDate: @js(old('due_date', '')),
        comment: @js(old('comment', '')),
        defaultInvoiceNumber: @js($defaultInvoiceNumber),
        defaultIssueDate: @js($defaultIssueDate),
        hasOldInput: @js(session()->hasOldInput()),
        hasOldDueDate: @js(old('due_date') !== null),
        contractsUrl: @js(route('ajax.contracts', ['company' => '__COMPANY__'])),
        itemsUrl: @js(route('ajax.items', ['contract' => '__CONTRACT__'])),
    })" x-init="init()" x-on:submit="if (!lines.length) { $event.preventDefault(); linesError = true }">
    @csrf

    <div data-testid="invoice-create-form-workspace" class="max-w-5xl overflow-visible border-y border-slate-200 bg-white">
        <section class="px-4 py-5 sm:px-5">
            <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Основная информация</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="relative" x-on:click.outside="companyOpen = false" x-on:keydown.escape.window="companyOpen = false">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Компания <span class="text-red-500">*</span></label>
                        <input type="hidden" name="company_id" x-model="selectedCompanyId">
                        <div class="relative">
                            <input type="text" x-model="companyQuery" autocomplete="off" placeholder="Начните вводить название"
                                x-on:focus="companyOpen = true" x-on:click="companyOpen = true"
                                x-on:input="companyTyped()"
                                x-on:keydown.enter.prevent="filteredCompanies.length && selectCompany(filteredCompanies[0])"
                                class="w-full pr-16 @error('company_id') border-red-300 @else border-gray-200 @enderror">
                            <button type="button" x-show="companyQuery" x-cloak x-on:click="clearCompany()"
                                class="absolute inset-y-0 right-8 flex items-center px-2 text-gray-400 transition hover:text-red-500" aria-label="Очистить компанию">✕</button>
                            <button type="button" x-on:click="companyOpen = !companyOpen"
                                class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 transition hover:text-gray-600"
                                aria-label="Открыть список компаний" aria-haspopup="listbox" x-bind:aria-expanded="companyOpen">
                                <svg class="h-4 w-4 transition-transform duration-200" :class="{ 'rotate-180': companyOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                        </div>
                        <div x-show="companyOpen" x-cloak x-transition role="listbox" class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto border border-slate-200 bg-white shadow-lg">
                            <template x-for="company in filteredCompanies" :key="company.id">
                                <button type="button" x-on:click="selectCompany(company)" class="block w-full px-3 py-2.5 text-left text-sm hover:bg-blue-50">
                                    <span x-text="company.name"></span>
                                </button>
                            </template>
                            <p x-show="!filteredCompanies.length" class="px-3 py-3 text-sm text-gray-400">Компании не найдены</p>
                        </div>
                        @error('company_id') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                <div x-show="selectedCompanyId" x-cloak data-step="contract" class="relative"
                        x-on:click.outside="contractOpen = false" x-on:keydown.escape.window="contractOpen = false">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Договор <span class="text-red-500">*</span></label>
                        <input type="hidden" name="contract_id" x-model="selectedContractId">
                        <button type="button" x-on:click="contractOpen = !contractOpen"
                            :disabled="!selectedCompanyId || loadingContracts"
                            class="relative flex min-h-10 w-full items-center rounded-md border bg-white px-3 pr-10 text-left text-sm text-slate-700 outline-none transition hover:border-slate-400 focus:border-blue-600 focus:ring-2 focus:ring-blue-600/20 @error('contract_id') border-red-300 @else border-slate-300 @enderror disabled:cursor-not-allowed disabled:border-slate-300 disabled:bg-slate-100 disabled:text-slate-500"
                            aria-haspopup="listbox" x-bind:aria-expanded="contractOpen" aria-label="Выбрать договор">
                            <span x-text="selectedContract ? contractLabel(selectedContract) : (loadingContracts ? 'Загрузка договоров…' : 'Выберите договор')"
                                :class="selectedContract ? 'text-gray-700' : 'text-gray-400'"></span>
                            <span class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400">
                                <svg class="h-4 w-4 transition-transform duration-200" :class="{ 'rotate-180': contractOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </span>
                        </button>
                        <div x-show="contractOpen" x-cloak x-transition role="listbox"
                            class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto border border-slate-200 bg-white shadow-lg">
                            <template x-for="contract in contracts" :key="contract.id">
                                <button type="button" role="option" x-on:click="selectContract(contract)"
                                    x-bind:aria-selected="String(contract.id) === String(selectedContractId)"
                                    class="block w-full px-3 py-2.5 text-left text-sm transition hover:bg-blue-50 hover:text-blue-700"
                                    :class="String(contract.id) === String(selectedContractId) ? 'bg-blue-50 font-medium text-blue-700' : 'text-gray-700'">
                                    <span x-text="contractLabel(contract)"></span>
                                </button>
                            </template>
                            <p x-show="!contracts.length && !loadingContracts" class="px-3 py-3 text-sm text-gray-400">Договоры не найдены</p>
                        </div>
                        <p x-show="selectedContract" x-cloak class="mt-1.5 text-xs text-gray-500">
                            Срок действия: <span x-text="contractDates(selectedContract)"></span>
                        </p>
                        @error('contract_id') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
            </div>

            <div x-show="selectedContractId" x-cloak data-step="invoice-details" class="mt-5 border-t border-slate-200 pt-5">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Номер счёта <span class="text-red-500">*</span></label>
                        <input name="invoice_number" x-model="invoiceNumber" required
                            class="w-full font-mono @error('invoice_number') border-red-300 @else border-gray-200 @enderror">
                        @error('invoice_number') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Дата выставления <span class="text-red-500">*</span></label>
                        <x-form.date-input name="issue_date" x-model="issueDate" x-on:change="issueDateChanged()" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Оплатить до <span class="text-red-500">*</span></label>
                        <x-form.date-input name="due_date" x-model="dueDate" x-on:input="dueDateIsManual = true"
                            dynamic-readonly="hasAutomaticPaymentTerms" required />
                        <p class="mt-1 text-xs text-gray-500" x-text="dueDateHint"></p>
                    </div>
                </div>
            </div>
        </section>

        <section x-show="selectedContractId" x-cloak data-step="invoice-lines" class="border-t border-slate-200 px-4 py-5 sm:px-5">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">Позиции счета</h2>
                <p class="mt-1 text-xs text-gray-500">Выберите услуги по договору.</p>
            </div>

            <div class="mt-4">
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Услуги по договору</h3>
                <p x-show="loadingItems" class="text-sm text-gray-500">Загрузка услуг…</p>
                <p x-show="!loadingItems && !availableItems.length" class="text-sm text-gray-500">В договоре нет услуг для добавления</p>
                <div class="divide-y divide-slate-100 border-y border-slate-200">
                    <template x-for="item in availableItems" :key="itemKey(item)">
                        <label class="flex cursor-pointer items-start gap-3 px-3 py-3 transition hover:bg-slate-50">
                            <input type="checkbox" class="mt-1 rounded border-gray-300 text-blue-600" :checked="isSelected(item)" :disabled="item.type === 'subscription' && !item.selectable" x-on:change="toggleItem(item, $event.target.checked)">
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-gray-800" x-text="item.description"></span>
                                <span class="block text-xs text-gray-500" x-text="itemSubtitle(item)"></span>
                            </span>
                            <span class="crm-table-numeric whitespace-nowrap text-sm font-semibold text-gray-800" x-text="money(item.amount)"></span>
                        </label>
                    </template>
                </div>
            </div>

            <div x-show="lines.length" x-cloak class="mt-5">
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Добавлено в счёт</h3>
                <div class="border-y border-slate-200">
                    <template x-for="(line, index) in lines" :key="line.key">
                        <div class="border-b border-slate-200 px-3 py-4 last:border-b-0">
                            <input type="hidden" :name="`lines[${index}][subscription_id]`" :value="line.subscription_id || ''">
                            <input type="hidden" :name="`lines[${index}][order_id]`" :value="line.order_id || ''">
                            <input type="hidden" :name="line.subscription_id ? `lines[${index}][period_count]` : null" :value="line.period_count || 1">
                            <input type="hidden" :name="line.subscription_id ? `lines[${index}][expected_period_start]` : null" :value="line.expected_period_start || ''">
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs font-medium text-gray-500" x-text="lineType(line)"></p>
                                    <p x-show="line.subscription_id" class="mt-0.5 text-xs text-gray-500">
                                        <span x-text="`${formatDate(subscriptionRange(line)[0])} — ${formatDate(subscriptionRange(line)[1])}`"></span>
                                    </p>
                                </div>
                                <button type="button" x-on:click="removeLine(index)" class="text-xs font-semibold text-red-600 transition hover:text-red-700" aria-label="Удалить позицию">Удалить</button>
                            </div>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-12">
                                <div class="sm:col-span-8">
                                    <label class="mb-1 block text-xs font-medium text-slate-500">Описание</label>
                                    <input :name="`lines[${index}][description]`" x-model="line.description" required maxlength="255"
                                        class="w-full border-gray-200">
                                </div>
                                <div class="sm:col-span-4">
                                    <label class="mb-1 block text-xs font-medium text-slate-500">Сумма (₼)</label>
                                    <p class="py-2 font-mono text-sm font-semibold text-slate-900" x-text="money(lineTotal(line))"></p>
                                </div>
                            </div>
                            <div x-show="line.subscription_id" class="mt-3 flex flex-wrap items-center gap-3 text-xs text-slate-600">
                                <label class="font-medium">Расчётные периоды
                                    <input type="number" min="1" :max="maximumPeriodCount(line)" x-model.number="line.period_count" x-on:change="normalisePeriodCount(line)" class="ml-2 w-16 border-gray-200 py-1 text-sm">
                                </label>
                                <span x-text="periodSummary(line)"></span>
                            </div>
                            <p x-show="lineError(index, 'period_count') || lineError(index, 'subscription_id')" class="mt-2 text-xs text-red-600" role="alert" x-text="lineError(index, 'period_count') || lineError(index, 'subscription_id')"></p>
                        </div>
                    </template>
                </div>
            </div>
            <p x-show="linesError" class="mt-3 text-sm text-red-600">Добавьте хотя бы одну позицию счёта.</p>
            @if ($formErrors->isNotEmpty())
                <div class="mt-3 space-y-1 text-sm text-red-600" role="alert">
                    @foreach ($formErrors as $message)
                        <p>{{ $message }}</p>
                    @endforeach
                </div>
            @endif
        </section>

        <section x-show="selectedContractId" x-cloak data-step="invoice-comment" class="border-t border-slate-200 px-4 py-5 sm:px-5">
            <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Дополнительно</h2>
            <label for="comment" class="sr-only">Комментарий</label>
            <textarea name="comment" id="comment" rows="3" x-model="comment" class="mt-4 w-full border-gray-200"></textarea>
            @error('comment') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </section>

        <div x-show="selectedContractId" x-cloak class="flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 px-4 py-4 sm:px-5">
            <div class="text-sm text-slate-600">
                <span class="mr-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Итого</span>
                <span class="font-semibold text-slate-900" x-text="money(total)">0.00 ₼</span>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" :disabled="!selectedCompanyId || !selectedContractId || !lines.length" class="bg-blue-600 disabled:cursor-not-allowed disabled:opacity-50">Сохранить черновик</button>
                <a href="{{ $backUrl }}" class="border border-gray-200">Отмена</a>
            </div>
        </div>
    </div>
</form>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('invoiceCreateForm', config => ({
        ...config, companyOpen: false, contractOpen: false, contracts: [], availableItems: [], lines: [], loadingContracts: false, loadingItems: false, linesError: false,
        contractsRequestId: 0, itemsRequestId: 0,
        dueDateIsManual: config.hasOldInput && config.hasOldDueDate, dueDateWasAutomatic: false, restoring: true, previousContractId: config.selectedContractId,
        get filteredCompanies() { const q = this.companyQuery.trim().toLocaleLowerCase(); return q ? this.companies.filter(c => c.name.toLocaleLowerCase().startsWith(q)) : this.companies },
        get selectedContract() { return this.contracts.find(c => String(c.id) === String(this.selectedContractId)) || null },
        get total() { return this.lines.reduce((sum, line) => sum + this.lineTotal(line), 0) },
        get paymentTerms() { return this.lines.filter(line => line.order_id || line.subscription_id).map(line => line.payment_terms).filter(terms => terms !== null && terms !== '').map(Number).filter(terms => Number.isInteger(terms) && terms >= 0 && terms <= 3650) },
        get hasAutomaticPaymentTerms() { return this.paymentTerms.length > 0 },
        get minimumPaymentTerms() { return this.hasAutomaticPaymentTerms ? Math.min(...this.paymentTerms) : null },
        get hasDifferentPaymentTerms() { return new Set(this.paymentTerms).size > 1 },
        get dueDateHint() { if (!this.hasAutomaticPaymentTerms) return 'Для выбранных позиций срок оплаты не задан'; if (this.hasDifferentPaymentTerms) return `У позиций разные условия оплаты. Использован минимальный срок: ${this.minimumPaymentTerms} дней`; return `Автоматически рассчитано: ${this.minimumPaymentTerms} календарных дней` },
        async init() {
            this.lines = this.oldLines.map((line, i) => this.normaliseOldLine(line, i));
            if (this.selectedCompanyId) await this.loadContracts(true);
            this.restoring = false;
        },
        companyTyped() {
            if (this.selectedCompanyId) {
                const selected = this.companies.find(company => String(company.id) === this.selectedCompanyId);
                if (!this.confirmCompanyReset()) { this.companyQuery = selected?.name || ''; return }
                this.resetAll();
            }
            this.companyOpen = true;
        },
        async selectCompany(company) {
            if (String(company.id) === this.selectedCompanyId) { this.companyQuery = company.name; this.companyOpen = false; return }
            if (this.selectedCompanyId && !this.confirmCompanyReset()) return;
            this.resetAll();
            this.selectedCompanyId = String(company.id); this.companyQuery = company.name; this.companyOpen = false;
            await this.loadContracts(false);
        },
        clearCompany() { this.resetAll() },
        async selectContract(contract) {
            if (String(contract.id) === String(this.selectedContractId)) { this.contractOpen = false; return }
            this.selectedContractId = String(contract.id); this.contractOpen = false; await this.contractChanged()
        },
        confirmCompanyReset() {
            return !this.hasInvoiceState() || window.confirm('При смене компании все введённые данные счёта будут очищены. Продолжить?');
        },
        hasInvoiceState() {
            return Boolean(this.selectedContractId || this.invoiceNumber || this.issueDate || this.dueDate || this.comment || this.lines.length);
        },
        resetInvoiceState() {
            this.itemsRequestId++;
            this.selectedContractId = '';
            this.contractOpen = false;
            this.previousContractId = '';
            this.availableItems = [];
            this.lines = [];
            this.invoiceNumber = '';
            this.issueDate = '';
            this.dueDate = '';
            this.comment = '';
            this.dueDateIsManual = false;
            this.dueDateWasAutomatic = false;
            this.linesError = false;
            this.loadingItems = false;
        },
        resetAll() {
            this.contractsRequestId++;
            this.resetInvoiceState();
            this.selectedCompanyId = '';
            this.companyQuery = '';
            this.companyOpen = false;
            this.contracts = [];
            this.loadingContracts = false;
        },
        initialiseNewInvoice() {
            this.invoiceNumber = this.defaultInvoiceNumber;
            this.issueDate = this.defaultIssueDate;
            this.dueDate = '';
            this.comment = '';
            this.lines = [];
            this.availableItems = [];
            this.dueDateIsManual = false;
            this.dueDateWasAutomatic = false;
            this.linesError = false;
            this.recalculateDueDate();
        },
        async loadContracts(restore) {
            const requestId = ++this.contractsRequestId;
            const companyId = this.selectedCompanyId;
            this.loadingContracts = true;
            try {
                const response = await fetch(this.contractsUrl.replace('__COMPANY__', companyId), { headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error();
                const contracts = await response.json();
                if (requestId !== this.contractsRequestId || companyId !== this.selectedCompanyId) return;
                this.contracts = contracts;
                if (restore && this.selectedContractId && this.contracts.some(c => String(c.id) === String(this.selectedContractId))) {
                    this.previousContractId = this.selectedContractId;
                    if (!this.hasOldInput) this.initialiseNewInvoice();
                    await this.loadItems();
                } else if (this.selectedContractId) {
                    this.resetInvoiceState();
                }
            } catch (_) {
                if (requestId === this.contractsRequestId) { this.contracts = []; if (!restore) this.resetInvoiceState() }
            } finally {
                if (requestId === this.contractsRequestId) this.loadingContracts = false;
            }
        },
        async contractChanged() {
            if (this.restoring) return;
            const nextContractId = this.selectedContractId;
            this.resetInvoiceState();
            if (!nextContractId) return;
            this.selectedContractId = nextContractId;
            this.previousContractId = nextContractId;
            this.initialiseNewInvoice();
            await this.loadItems();
        },
        async loadItems() {
            const requestId = ++this.itemsRequestId;
            const contractId = this.selectedContractId;
            this.loadingItems = true;
            try {
                const response = await fetch(this.itemsUrl.replace('__CONTRACT__', contractId), { headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error();
                const data = await response.json();
                if (requestId !== this.itemsRequestId || contractId !== this.selectedContractId) return;
                this.availableItems = [...data.orders, ...data.subscriptions]; this.mergeOldMetadata(); this.recalculateDueDate();
            } catch (_) {
                if (requestId === this.itemsRequestId) this.availableItems = [];
            } finally {
                if (requestId === this.itemsRequestId) this.loadingItems = false;
            }
        },
        mergeOldMetadata() { this.lines.forEach(line => { const item = this.availableItems.find(i => this.itemKey(i) === line.key); if (item) { line.amount = item.amount; line.billing_period = item.billing_period || null; line.payment_terms = item.payment_terms ?? null; line.available_occurrences = item.available_occurrences || []; line.expected_period_start = line.expected_period_start || line.available_occurrences[0]?.period_start || null; this.normalisePeriodCount(line) } }) },
        normaliseOldLine(line) { const type = line.subscription_id ? 'subscription' : 'order'; const id = line.subscription_id || line.order_id; return { key: `${type}-${id}`, description: line.description || '', amount: line.amount || '', subscription_id: line.subscription_id || null, order_id: line.order_id || null, period_count: Number(line.period_count || 1), expected_period_start: line.expected_period_start || null, available_occurrences: [], stale_occurrences: line.stale_occurrences || [], billing_period: line.billing_period || null, payment_terms: null } },
        itemKey(item) { return `${item.type}-${item.id}` }, isSelected(item) { return this.lines.some(line => line.key === this.itemKey(item)) },
        lineError(index, field) { return this.lineErrors[`lines.${index}.${field}`] || null },
        toggleItem(item, checked) { const key = this.itemKey(item); if (checked && !this.lines.some(line => line.key === key)) this.lines.push(this.lineFromItem(item)); else if (!checked) this.lines = this.lines.filter(line => line.key !== key); this.afterLinesChanged() },
        lineFromItem(item) { return { key: this.itemKey(item), description: item.description, amount: item.amount, subscription_id: item.type === 'subscription' ? item.id : null, order_id: item.type === 'order' ? item.id : null, period_count: 1, expected_period_start: item.type === 'subscription' ? item.available_occurrences?.[0]?.period_start || null : null, available_occurrences: item.available_occurrences || [], billing_period: item.billing_period || null, payment_terms: item.payment_terms ?? null } },
        maximumPeriodCount(line) { return Math.max(line.available_occurrences?.length || 0, line.stale_occurrences?.length || 0, 1) },
        normalisePeriodCount(line) { if (!line.subscription_id) return; const maximum = this.maximumPeriodCount(line); line.period_count = Math.max(1, Math.min(maximum, Number.parseInt(line.period_count, 10) || 1)); this.afterLinesChanged() },
        subscriptionRange(line) { const occurrences = line.stale_occurrences?.length ? line.stale_occurrences : (line.available_occurrences || []); const count = Math.max(1, Math.min(Number(line.period_count) || 1, occurrences.length)); return occurrences.length ? [occurrences[0].period_start, occurrences[count - 1].period_end] : [null, null] },
        lineTotal(line) { return (Number.parseFloat(line.amount) || 0) * (line.subscription_id ? (Number(line.period_count) || 1) : 1) },
        periodSummary(line) { const count = Number(line.period_count) || 1; const noun = count === 1 ? 'расчётный период' : (count >= 2 && count <= 4 ? 'расчётных периода' : 'расчётных периодов'); return count === 1 ? `1 ${noun} · ${this.money(line.amount)}` : `${count} ${noun} × ${this.money(line.amount)} = ${this.money(this.lineTotal(line))}` },
        removeLine(index) { this.lines.splice(index, 1); this.afterLinesChanged() },
        afterLinesChanged() { this.linesError = false; this.recalculateDueDate() },
        issueDateChanged() { if (this.hasAutomaticPaymentTerms) this.recalculateDueDate() },
        recalculateDueDate() { if (!this.hasAutomaticPaymentTerms) { if (this.dueDateWasAutomatic) this.dueDate = ''; this.dueDateWasAutomatic = false; this.dueDateIsManual = true; return } this.dueDateIsManual = false; this.dueDateWasAutomatic = true; if (!this.issueDate) { this.dueDate = ''; return } const date = this.parseDate(this.issueDate); date.setDate(date.getDate() + this.minimumPaymentTerms); this.dueDate = this.inputDate(date) },
        contractLabel(c) { return `№ ${c.contract_number}` },
        contractDates(c) { return c.end_date ? `${this.formatDate(c.start_date)} — ${this.formatDate(c.end_date)}` : `с ${this.formatDate(c.start_date)}, бессрочный` },
        itemSubtitle(item) { if (item.type === 'order') return 'Разовая услуга'; if (!item.selectable) return item.unavailable_reason || 'Недоступна для выставления'; return `Подписка · ${{ monthly: 'ежемесячно', quarterly: 'ежеквартально', semiannual: 'раз в полгода', annual: 'ежегодно', custom: 'индивидуальный период' }[item.billing_period] || 'индивидуальный период'}` },
        lineType(line) { return line.subscription_id ? 'Подписка' : 'Разовая услуга' },
        money(value) { return `${(Number.parseFloat(value) || 0).toFixed(2)} ₼` },
        parseDate(value) { const [y, m, d] = value.split('-').map(Number); return new Date(y, m - 1, d) },
        inputDate(date) { return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}` },
        formatDate(value) { if (!value) return '—'; const [y, m, d] = value.slice(0, 10).split('-'); return `${d}/${m}/${y}` },
    }));
});
</script>
@endsection
