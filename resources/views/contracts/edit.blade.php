@extends('layouts.app')

@section('title', 'Редактирование договора')

@section('content')
    <div class="mb-5">
        <a href="{{ $returnContext['url'] }}" class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 transition hover:text-slate-900">
            <span aria-hidden="true">←</span>
            Назад к договору
        </a>
        <h1 class="mt-3 text-xl font-semibold text-slate-900">Редактирование договора</h1>
        <p class="mt-1 text-sm text-slate-500">
            <span class="font-mono font-medium text-slate-700">{{ $contract->contract_number }}</span>
            <span class="mx-1 text-slate-300">·</span>
            {{ $company->name }}
        </p>
    </div>

    <form action="{{ route('contracts.update', $contract) }}" method="POST" class="max-w-4xl">
        @csrf
        @method('PUT')
        @foreach ($returnContext['hidden'] as $contextName => $contextValue)
            <input type="hidden" name="{{ $contextName }}" value="{{ $contextValue }}">
        @endforeach

        <div data-testid="contract-form-workspace" class="overflow-hidden border-y border-slate-200 bg-white">
            <section class="px-4 py-5 sm:px-5">
                <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Основная информация</h2>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label for="contract_number" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Номер договора <span class="text-red-500">*</span></label>
                        <input type="text" name="contract_number" id="contract_number" value="{{ old('contract_number', $contract->contract_number) }}" required
                            class="w-full font-mono @error('contract_number') border-red-300 @else border-gray-200 @enderror">
                        @error('contract_number')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="start_date" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Дата начала <span class="text-red-500">*</span></label>
                        <x-form.date-input name="start_date" :value="old('start_date', $contract->start_date?->format('Y-m-d'))" required />
                    </div>

                    <div>
                        <label for="end_date" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Дата окончания</label>
                        <x-form.date-input name="end_date" :value="old('end_date', $contract->end_date?->format('Y-m-d'))" />
                    </div>

                    <div>
                        <label for="status" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Статус <span class="text-red-500">*</span></label>
                        <select name="status" id="status" required class="w-full @error('status') border-red-300 @else border-gray-200 @enderror">
                            <option value="active" @selected(old('status', $contract->status) === 'active')>Активный</option>
                            <option value="terminated" @selected(old('status', $contract->status) === 'terminated')>Расторгнут</option>
                        </select>
                        @error('status')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </section>

            <section class="border-t border-slate-200 px-4 py-5 sm:px-5">
                <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Комментарий</h2>
                <div class="mt-4">
                    <label for="comment" class="sr-only">Комментарий</label>
                    <textarea name="comment" id="comment" rows="3" class="w-full @error('comment') border-red-300 @else border-gray-200 @enderror">{{ old('comment', $contract->comment) }}</textarea>
                    @error('comment')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>
            </section>

            <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 px-4 py-4 sm:px-5">
                <button type="submit" class="bg-blue-600">Сохранить</button>
                <a href="{{ $returnContext['url'] }}" class="border border-gray-200">Отмена</a>
            </div>
        </div>
    </form>
@endsection
