@extends('layouts.app')

@section('title', 'Добавить договор')

@section('content')

    <div class="mb-6">
        <a href="{{ ($companyContext['active'] ?? false) ? $companyContext['company_url'] : ($company ? route('companies.show', $company) : route('contracts.index')) }}"
            class="text-sm text-gray-500 hover:text-gray-700">
            ← {{ $company ? 'Назад к компании' : 'Назад к договорам' }}
        </a>

        <h1 class="text-2xl font-bold text-gray-900 mt-2">
            Добавить договор
        </h1>

        <p class="text-sm text-gray-500">
            {{ $company?->name ?? 'Выберите компанию для нового договора' }}
        </p>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 max-w-2xl">
        <form action="{{ route('contracts.store') }}" method="POST" class="space-y-4">

            @csrf
            @if ($companyContext['active'] ?? false)
                <input type="hidden" name="origin" value="company"><input type="hidden" name="tab" value="contracts">
            @endif

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                    Компания <span class="text-red-500">*</span>
                </label>

                @if ($company)
                    <input type="hidden" name="company_id" value="{{ $company->id }}">
                    <p class="text-sm font-medium text-gray-900">{{ $company->name }}</p>
                @else
                <select name="company_id"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                    required>
                    <option value="">Выберите компанию</option>

                    @foreach ($companies as $companyItem)
                        <option value="{{ $companyItem->id }}" @selected(old('company_id', $company?->id) == $companyItem->id)>
                            {{ $companyItem->name }}
                        </option>
                    @endforeach
                </select>
                @endif

                @error('company_id')
                    <p class="text-xs text-red-500 mt-1">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                    Номер договора <span class="text-red-500">*</span>
                </label>

                <input type="text" name="contract_number" value="{{ old('contract_number') }}" placeholder="CTR-2026-001"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:border-blue-500 outline-none transition"
                    required>

                @error('contract_number')
                    <p class="text-xs text-red-500 mt-1">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                        Дата начала <span class="text-red-500">*</span>
                    </label>

                    <x-form.date-input name="start_date" :value="old('start_date')" required />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                        Дата окончания
                    </label>

                    <x-form.date-input name="end_date" :value="old('end_date')" />
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                    Статус <span class="text-red-500">*</span>
                </label>

                <select name="status"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                    required>
                    <option value="active" @selected(old('status', 'active') === 'active')>
                        Активный
                    </option>

                    <option value="terminated" @selected(old('status') === 'terminated')>
                        Расторгнут
                    </option>
                </select>

                @error('status')
                    <p class="text-xs text-red-500 mt-1">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                    Комментарий
                </label>

                <textarea name="comment" rows="3"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">{{ old('comment') }}</textarea>

                @error('comment')
                    <p class="text-xs text-red-500 mt-1">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-6 py-2.5 rounded-lg transition">
                    Сохранить договор
                </button>

                <a href="{{ ($companyContext['active'] ?? false) ? $companyContext['company_url'] : ($company ? route('companies.show', $company) : route('contracts.index')) }}"
                    class="px-6 py-2.5 border border-gray-200 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-50 transition">
                    Отмена
                </a>
            </div>
        </form>
    </div>

@endsection
