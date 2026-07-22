@extends('layouts.app')
@section('title', 'Редактировать договор')
@section('content')

    <div class="mb-6">
        <a href="{{ route('contracts.show', ['contract' => $contract, ...$companyContext['query']]) }}" class="text-sm text-gray-500 hover:text-gray-700">
            ← Назад к договору
        </a>
        <h1 class="text-2xl font-bold text-gray-900 mt-2">Редактировать договор</h1>
        <p class="text-sm text-gray-500">{{ $company->name }}</p>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 max-w-2xl">
        <form action="{{ route('contracts.update', $contract) }}" method="POST" class="space-y-4">
            @csrf
            @method('PUT')
            @if ($companyContext['active'])
                <input type="hidden" name="origin" value="company"><input type="hidden" name="tab" value="contracts">
            @endif

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Номер договора <span
                        class="text-red-500">*</span></label>
                <input type="text" name="contract_number"
                    value="{{ old('contract_number', $contract->contract_number) }}"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:border-blue-500 outline-none transition"
                    required>
                @error('contract_number')
                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Дата начала <span
                            class="text-red-500">*</span></label>
                    <x-form.date-input name="start_date" :value="old('start_date', $contract->start_date?->format('Y-m-d'))" required />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Дата окончания</label>
                    <x-form.date-input name="end_date" :value="old('end_date', $contract->end_date?->format('Y-m-d'))" />
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Статус <span
                        class="text-red-500">*</span></label>
                <select name="status"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                    required>
                    <option value="active" {{ old('status', $contract->status) === 'active' ? 'selected' : '' }}>Активный
                    </option>
                    <option value="terminated" {{ old('status', $contract->status) === 'terminated' ? 'selected' : '' }}>
                        Расторгнут</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Комментарий</label>
                <textarea name="comment" rows="3"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">{{ old('comment', $contract->comment) }}</textarea>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-6 py-2.5 rounded-lg transition">
                    Сохранить
                </button>
                <a href="{{ route('contracts.show', ['contract' => $contract, ...$companyContext['query']]) }}"
                    class="px-6 py-2.5 border border-gray-200 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-50 transition">
                    Отмена
                </a>
            </div>
        </form>
    </div>

@endsection
