@extends('layouts.app')

@section('title', 'Редактировать счёт')

@section('content')
<div class="mb-6">
    <a href="{{ route('invoices.show', $invoice) }}" class="text-sm text-gray-500 hover:text-gray-700">← Назад к просмотру</a>
    <h1 class="mt-2 text-2xl font-bold text-gray-900">Редактировать счёт</h1>
</div>

<form method="POST" action="{{ route('invoices.update', $invoice) }}" x-data="{
    lines: @js(array_values(old('lines', $invoice->lines->map(fn ($line) => [
        'id' => $line->id,
        'description' => $line->description,
        'amount' => $line->amount,
        'subscription_id' => $line->subscription_id,
        'order_id' => $line->order_id,
        'period_start' => $line->period_start?->toDateString(),
        'period_end' => $line->period_end?->toDateString(),
    ])->all()))),
    addLine() { this.lines.push({ id: null, description: '', amount: '', subscription_id: null, order_id: null, period_start: null, period_end: null }) },
    removeLine(index) { this.lines.splice(index, 1) },
    total() { return this.lines.reduce((sum, line) => sum + (Number.parseFloat(line.amount) || 0), 0) },
    type(line) { return line.subscription_id ? 'Подписка' : (line.order_id ? 'Разовая услуга' : 'Ручная позиция') },
    formatDate(value) { if (!value) return '—'; const [y,m,d] = value.slice(0,10).split('-'); return `${d}/${m}/${y}` }
}">
    @csrf
    @method('PUT')

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="mb-3 font-semibold text-gray-800">Компания и договор</h2>
                <div class="space-y-1 text-sm text-gray-700">
                    <p><span class="text-gray-500">Компания:</span> {{ $invoice->company?->name ?? $invoice->payer_name }}</p>
                    <p><span class="text-gray-500">Договор:</span> № {{ $invoice->contract?->contract_number ?? $invoice->contract_reference }}</p>
                    @if ($invoice->contract)
                        <p class="text-xs text-gray-500">
                            Срок действия:
                            @if ($invoice->contract->end_date)
                                {{ $invoice->contract->start_date?->format('d/m/Y') }} — {{ $invoice->contract->end_date->format('d/m/Y') }}
                            @else
                                с {{ $invoice->contract->start_date?->format('d/m/Y') }}, бессрочный
                            @endif
                        </p>
                    @endif
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="mb-4 font-semibold text-gray-800">Данные счёта</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase text-gray-500">Номер счёта <span class="text-red-500">*</span></label>
                        <input name="invoice_number" value="{{ old('invoice_number', $invoice->invoice_number) }}" required class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm font-mono outline-none focus:border-blue-500">
                        @error('invoice_number') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase text-gray-500">Дата выставления <span class="text-red-500">*</span></label>
                        <x-form.date-input name="issue_date" :value="old('issue_date', \Illuminate\Support\Carbon::parse($invoice->issue_date)->toDateString())" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase text-gray-500">Оплатить до <span class="text-red-500">*</span></label>
                        <x-form.date-input name="due_date" :value="old('due_date', \Illuminate\Support\Carbon::parse($invoice->due_date)->toDateString())" required />
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div><h2 class="font-semibold text-gray-800">Позиции счёта</h2><p class="mt-1 text-xs text-gray-500">Измените описание или сумму либо добавьте ручную позицию.</p></div>
                    <button type="button" x-on:click="addLine()" class="whitespace-nowrap text-sm font-medium text-blue-600 hover:text-blue-800">+ Добавить ручную позицию</button>
                </div>
                <div x-show="lines.length" class="mt-4 space-y-3">
                    <template x-for="(line, index) in lines" :key="line.id ?? `new-${index}`">
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <input type="hidden" :name="`lines[${index}][id]`" :value="line.id || ''">
                            <input type="hidden" :name="`lines[${index}][subscription_id]`" :value="line.subscription_id || ''">
                            <input type="hidden" :name="`lines[${index}][order_id]`" :value="line.order_id || ''">
                            <input type="hidden" :name="`lines[${index}][period_start]`" :value="line.period_start || ''">
                            <input type="hidden" :name="`lines[${index}][period_end]`" :value="line.period_end || ''">
                            <div class="mb-2 flex items-center justify-between">
                                <div><p class="text-xs font-medium text-gray-500" x-text="type(line)"></p><p x-show="line.subscription_id" class="text-xs text-gray-500">Расчётный период: <span x-text="`${formatDate(line.period_start)} — ${formatDate(line.period_end)}`"></span></p></div>
                                <button type="button" x-on:click="removeLine(index)" class="text-sm text-red-500 hover:text-red-700">Удалить</button>
                            </div>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-12">
                                <div class="sm:col-span-8"><label class="mb-1 block text-xs text-gray-500">Описание</label><input :name="`lines[${index}][description]`" x-model="line.description" required maxlength="255" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-blue-500"></div>
                                <div class="sm:col-span-4"><label class="mb-1 block text-xs text-gray-500">Сумма (₼)</label><input type="number" :name="`lines[${index}][amount]`" x-model="line.amount" required min="0.01" step="0.01" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-blue-500"></div>
                            </div>
                        </div>
                    </template>
                </div>
                @error('lines') <p class="mt-3 text-sm text-red-600">{{ $message }}</p> @enderror
                @foreach ($errors->get('lines.*') as $messages) @foreach ($messages as $message) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @endforeach @endforeach
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <label class="mb-1 block text-xs font-semibold uppercase text-gray-500">Комментарий</label>
                <textarea name="comment" rows="3" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-blue-500">{{ old('comment', $invoice->comment) }}</textarea>
                @error('comment') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </section>
        </div>

        <aside class="h-fit rounded-xl border border-gray-200 bg-white p-5 shadow-sm lg:sticky lg:top-6">
            <h2 class="font-semibold text-gray-800">Итог</h2>
            <div class="mt-4 flex justify-between text-sm text-gray-600"><span>Позиции:</span><span x-text="lines.length"></span></div>
            <div class="mt-3 flex justify-between border-t border-gray-100 pt-3 font-semibold"><span>Итого:</span><span><span x-text="total().toFixed(2)"></span> ₼</span></div>
            <button type="submit" :disabled="!lines.length" class="mt-5 w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">Сохранить изменения</button>
        </aside>
    </div>
</form>
@endsection
