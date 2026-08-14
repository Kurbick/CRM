@extends('layouts.app')

@section('title', 'Редактирование инвойса')

@section('content')
@php
    $storedLines = $invoice->lines->keyBy('id');
    $defaultLines = $invoice->lines->map(fn ($line) => [
        'id' => $line->id,
        'description' => $line->description,
        'amount' => $line->amount,
        'subscription_id' => $line->subscription_id,
        'order_id' => $line->order_id,
        'period_start' => $line->period_start?->toDateString(),
        'period_end' => $line->period_end?->toDateString(),
    ])->all();
    $editLines = collect(array_values(old('lines', $defaultLines)))
        ->map(function ($line) use ($storedLines) {
            $storedLine = !empty($line['id'])
                ? $storedLines->get((int) $line['id'])
                : null;

            $line['payment_terms'] = $storedLine?->subscription?->payment_terms
                ?? $storedLine?->order?->payment_terms;

            return $line;
        })
        ->values();
@endphp
<div class="mb-5">
    <a href="{{ route('invoices.show', ['invoice' => $invoice, ...$companyContext['query']]) }}" class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 transition hover:text-slate-900">
        <span aria-hidden="true">←</span>
        Назад к инвойсу
    </a>
    <h1 class="mt-3 text-xl font-semibold text-slate-900">Редактирование инвойса</h1>
    <p class="mt-1 text-sm text-slate-500"><span class="font-mono">{{ $invoice->invoice_number }}</span><span class="mx-1.5 text-slate-300">·</span>{{ $invoice->company?->name ?? $invoice->payer_name }}</p>
</div>

@if ($editability['has_pending_payments'])
    <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        По инвойсу есть платёж, ожидающий подтверждения. После изменения инвойса сумма зарегистрированного платежа не изменится.
    </div>
@endif

<form method="POST" action="{{ route('invoices.update', $invoice) }}" x-data="{
    lines: @js($editLines),
    issueDate: @js(old('issue_date', \Illuminate\Support\Carbon::parse($invoice->issue_date)->toDateString())),
    dueDate: @js(old('due_date', \Illuminate\Support\Carbon::parse($invoice->due_date)->toDateString())),
    dueDateWasAutomatic: false,
    get paymentTerms() { return this.lines.filter(line => line.order_id || line.subscription_id).map(line => line.payment_terms).filter(terms => terms !== null && terms !== '').map(Number).filter(terms => Number.isInteger(terms) && terms >= 0 && terms <= 3650) },
    get hasAutomaticPaymentTerms() { return this.paymentTerms.length > 0 },
    get minimumPaymentTerms() { return this.hasAutomaticPaymentTerms ? Math.min(...this.paymentTerms) : null },
    get hasDifferentPaymentTerms() { return new Set(this.paymentTerms).size > 1 },
    get dueDateHint() { if (!this.hasAutomaticPaymentTerms) return 'Для выбранных позиций срок оплаты не задан'; if (this.hasDifferentPaymentTerms) return `У позиций разные условия оплаты. Использован минимальный срок: ${this.minimumPaymentTerms} дней`; return `Автоматически рассчитано: ${this.minimumPaymentTerms} календарных дней` },
    init() { this.recalculateDueDate() },
    removeLine(index) { this.lines.splice(index, 1); this.recalculateDueDate() },
    issueDateChanged() { if (this.hasAutomaticPaymentTerms) this.recalculateDueDate() },
    recalculateDueDate() { if (!this.hasAutomaticPaymentTerms) { if (this.dueDateWasAutomatic) this.dueDate = ''; this.dueDateWasAutomatic = false; return } this.dueDateWasAutomatic = true; if (!this.issueDate) { this.dueDate = ''; return } const date = this.parseDate(this.issueDate); date.setDate(date.getDate() + this.minimumPaymentTerms); this.dueDate = this.inputDate(date) },
    total() { return this.lines.reduce((sum, line) => sum + (Number.parseFloat(line.amount) || 0), 0) },
    money(value) { return `${(Number.parseFloat(value) || 0).toFixed(2)} ₼` },
    type(line) { return line.subscription_id ? 'Подписка' : (line.order_id ? 'Разовая услуга' : 'Ручная позиция') },
    parseDate(value) { const [y, m, d] = value.split('-').map(Number); return new Date(y, m - 1, d) },
    inputDate(date) { return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}` },
    formatDate(value) { if (!value) return '—'; const [y,m,d] = value.slice(0,10).split('-'); return `${d}/${m}/${y}` }
}" x-init="init()">
    @if ($companyContext['active'])
        <input type="hidden" name="origin" value="company"><input type="hidden" name="tab" value="{{ $companyContext['tab'] }}">
    @endif
    @csrf
    @method('PUT')

    <div data-testid="invoice-edit-form-workspace" class="max-w-5xl overflow-hidden border-y border-slate-200 bg-white">
        <section class="px-4 py-5 sm:px-5">
            <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Основная информация</h2>
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="text-sm text-slate-700">
                    <span class="text-slate-500">Компания:</span>
                    {{ $invoice->company?->name ?? $invoice->payer_name }}
                </div>
                <div class="text-sm text-slate-700">
                    <span class="text-slate-500">Договор:</span>
                    № {{ $invoice->contract?->contract_number ?? $invoice->contract_reference }}
                    @if ($invoice->contract)
                        <p class="mt-1 text-xs text-slate-500">
                            Срок действия:
                            @if ($invoice->contract->end_date)
                                {{ $invoice->contract->start_date?->format('d/m/Y') }} — {{ $invoice->contract->end_date->format('d/m/Y') }}
                            @else
                                с {{ $invoice->contract->start_date?->format('d/m/Y') }}, бессрочный
                            @endif
                        </p>
                    @endif
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Номер счёта <span class="text-red-500">*</span></label>
                    <input name="invoice_number" value="{{ old('invoice_number', $invoice->invoice_number) }}" required class="w-full font-mono @error('invoice_number') border-red-300 @else border-gray-200 @enderror">
                    @error('invoice_number') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Дата выставления <span class="text-red-500">*</span></label>
                    <x-form.date-input name="issue_date" x-model="issueDate" x-on:change="issueDateChanged()" required />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Оплатить до <span class="text-red-500">*</span></label>
                    <x-form.date-input name="due_date" x-model="dueDate" dynamic-readonly="hasAutomaticPaymentTerms" required />
                    <p class="mt-1 text-xs text-gray-500" x-text="dueDateHint"></p>
                </div>
            </div>
        </section>

        <section class="border-t border-slate-200 px-4 py-5 sm:px-5">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">Позиции счета</h2>
                    <p class="mt-1 text-xs text-gray-500">Измените описание или сумму ручных позиций.</p>
            </div>
            <div x-show="lines.length" class="mt-4 border-y border-slate-200">
                <template x-for="(line, index) in lines" :key="line.id ?? `new-${index}`">
                    <div class="border-b border-slate-200 px-3 py-4 last:border-b-0">
                        <input type="hidden" :name="`lines[${index}][id]`" :value="line.id || ''">
                        <input type="hidden" :name="`lines[${index}][subscription_id]`" :value="line.subscription_id || ''">
                        <input type="hidden" :name="`lines[${index}][order_id]`" :value="line.order_id || ''">
                        <input type="hidden" :name="`lines[${index}][period_start]`" :value="line.period_start || ''">
                        <input type="hidden" :name="`lines[${index}][period_end]`" :value="line.period_end || ''">
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-medium text-gray-500" x-text="type(line)"></p>
                                <p x-show="line.subscription_id" class="mt-0.5 text-xs text-gray-500">Расчётный период: <span x-text="`${formatDate(line.period_start)} — ${formatDate(line.period_end)}`"></span></p>
                            </div>
                            <button type="button" x-show="@js($invoice->status !== 'issued') || (!line.subscription_id && !line.order_id)"
                                x-on:click="removeLine(index)" class="text-xs font-semibold text-red-600 transition hover:text-red-700">Удалить</button>
                        </div>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-12">
                            <div class="sm:col-span-8">
                                <label class="mb-1 block text-xs font-medium text-slate-500">Описание</label>
                                <input :name="`lines[${index}][description]`" x-model="line.description" required maxlength="255" class="w-full border-gray-200">
                            </div>
                            <div class="sm:col-span-4">
                                <label class="mb-1 block text-xs font-medium text-slate-500">Сумма (₼)</label>
                                <template x-if="line.order_id || line.subscription_id">
                                    <p class="py-2 font-mono text-sm font-semibold text-slate-900" x-text="money(line.amount)"></p>
                                </template>
                                <template x-if="!line.order_id && !line.subscription_id">
                                    <input type="number" :name="`lines[${index}][amount]`" x-model="line.amount" required min="0.01" step="0.01" class="w-full font-mono border-gray-200">
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
            @error('lines') <p class="mt-3 text-sm text-red-600">{{ $message }}</p> @enderror
            @foreach ($errors->get('lines.*') as $messages) @foreach ($messages as $message) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @endforeach @endforeach
        </section>

        <section class="border-t border-slate-200 px-4 py-5 sm:px-5">
            <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Дополнительно</h2>
            <label for="comment" class="sr-only">Комментарий</label>
            <textarea name="comment" id="comment" rows="3" class="mt-4 w-full border-gray-200">{{ old('comment', $invoice->comment) }}</textarea>
            @error('comment') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </section>

        <div class="flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 px-4 py-4 sm:px-5">
            <div class="text-sm text-slate-600">
                <span class="mr-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Итого</span>
                <span class="font-semibold text-slate-900"><span x-text="total().toFixed(2)"></span> ₼</span>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" :disabled="!lines.length" class="bg-blue-600 disabled:cursor-not-allowed disabled:opacity-50">Сохранить</button>
                <a href="{{ route('invoices.show', ['invoice' => $invoice, ...$companyContext['query']]) }}" class="border border-gray-200">Отмена</a>
            </div>
        </div>
    </div>
</form>
@endsection
