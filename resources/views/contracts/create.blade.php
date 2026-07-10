@extends('layouts.app')
@section('title', 'Добавить договор')
@section('content')

<div class="mb-6">
    <a href="{{ route('companies.show', $company) }}" class="text-sm text-gray-500 hover:text-gray-700">← Назад к компании</a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">Добавить договор</h1>
    <p class="text-sm text-gray-500">{{ $company->name }}</p>
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 max-w-2xl">
    <form action="{{ route('companies.contracts.store', $company) }}" method="POST" class="space-y-4">
        @csrf

        <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Номер договора <span class="text-red-500">*</span></label>
            <input type="text" name="contract_number" value="{{ old('contract_number') }}"
                   placeholder="CTR-2026-001"
                   class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:border-blue-500 outline-none transition"
                   required>
            @error('contract_number')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Дата начала <span class="text-red-500">*</span></label>
                <input type="date" name="start_date" value="{{ old('start_date') }}"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                       required>
                @error('start_date')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Дата окончания</label>
                <input type="date" name="end_date" value="{{ old('end_date') }}"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">
                @error('end_date')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Статус <span class="text-red-500">*</span></label>
            <select name="status" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition" required>
                <option value="active" {{ old('status') === 'active' ? 'selected' : '' }}>Активный</option>
                <option value="expired" {{ old('status') === 'expired' ? 'selected' : '' }}>Истёк</option>
                <option value="terminated" {{ old('status') === 'terminated' ? 'selected' : '' }}>Расторгнут</option>
            </select>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Комментарий</label>
            <textarea name="comment" rows="3"
                      class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">{{ old('comment') }}</textarea>
        </div>

        <div class="flex gap-3 pt-2">
            <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-6 py-2.5 rounded-lg transition">
                Сохранить договор
            </button>
            <a href="{{ route('companies.show', $company) }}"
               class="px-6 py-2.5 border border-gray-200 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-50 transition">
                Отмена
            </a>
        </div>
    </form>
</div>

@endsection