@extends('layouts.app')
@section('title', 'Редактировать подписку')
@section('content')

<div class="mb-6">
    <a href="{{ route('contracts.show', $contract) }}" class="text-sm text-gray-500 hover:text-gray-700">← Назад к договору</a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">Редактировать подписку</h1>
    <p class="text-sm text-gray-500">Договор: {{ $contract->contract_number }} — {{ $contract->company->name }}</p>
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 max-w-2xl">
    <form action="{{ route('subscriptions.update', $subscription) }}" method="POST" class="space-y-4">
        @csrf
        @method('PUT')

        <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Услуга <span class="text-red-500">*</span></label>
            <select name="service_type_id"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                    required>
                <option value="">— Выберите услугу —</option>
                @foreach($serviceTypes as $serviceType)
                    <option value="{{ $serviceType->id }}" {{ old('service_type_id', $subscription->service_type_id) == $serviceType->id ? 'selected' : '' }}>
                        {{ $serviceType->name }} 
                    </option>
                @endforeach
            </select>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Дата начала <span class="text-red-500">*</span></label>
                <input type="date" name="start_date" value="{{ old('start_date', $subscription->start_date) }}"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                       required>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">След. списание <span class="text-red-500">*</span></label>
                <input type="date" name="next_billing_date" value="{{ old('next_billing_date', $subscription->next_billing_date) }}"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                       required>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Период <span class="text-red-500">*</span></label>
            <select name="billing_period" id="billing_period"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                    onchange="document.getElementById('custom_period').classList.toggle('hidden', this.value !== 'custom')"
                    required>
                <option value="monthly" {{ old('billing_period') === 'monthly' ? 'selected' : '' }}>Ежемесячно</option>
                <option value="quarterly" {{ old('billing_period') === 'quarterly' ? 'selected' : '' }}>Ежеквартально</option>
                <option value="semiannual" {{ old('billing_period') === 'semiannual' ? 'selected' : '' }}>Раз в полгода</option>
                <option value="annual" {{ old('billing_period') === 'annual' ? 'selected' : '' }}>Ежегодно</option>
                <option value="custom" {{ old('billing_period') === 'custom' ? 'selected' : '' }}>Свой вариант</option>
            </select>
            <input type="text" name="billing_period_custom" id="custom_period"
                value="{{ old('billing_period_custom') }}"
                placeholder="Например: каждые 2 месяца..."
                class="hidden w-full mt-2 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Сумма (₼) <span class="text-red-500">*</span></label>
                <input type="number" name="amount" value="{{ old('amount', $subscription->amount) }}"
                       step="0.01" min="0"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:border-blue-500 outline-none transition"
                       required>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Срок оплаты (дней)</label>
                <input type="number" name="payment_terms" value="{{ old('payment_terms', $subscription->payment_terms) }}"
                       min="1" max="365"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:border-blue-500 outline-none transition">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Статус <span class="text-red-500">*</span></label>
                <select name="status"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                        required>
                    <option value="active" {{ old('status', $subscription->status) === 'active' ? 'selected' : '' }}>Активна</option>
                    <option value="suspended" {{ old('status', $subscription->status) === 'suspended' ? 'selected' : '' }}>Приостановлена</option>
                    <option value="completed" {{ old('status', $subscription->status) === 'completed' ? 'selected' : '' }}>Завершена</option>
                    <option value="cancelled" {{ old('status', $subscription->status) === 'cancelled' ? 'selected' : '' }}>Отменена</option>
                </select>
            </div>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Комментарий</label>
            <textarea name="comment" rows="3"
                      class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">{{ old('comment', $subscription->comment) }}</textarea>
        </div>

        <div class="flex gap-3 pt-2">
            <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-6 py-2.5 rounded-lg transition">
                Сохранить
            </button>
            <a href="{{ route('contracts.show', $contract) }}"
               class="px-6 py-2.5 border border-gray-200 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-50 transition">
                Отмена
            </a>
        </div>
    </form>
</div>

@endsection