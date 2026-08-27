@extends('layouts.app')

@section('title', __('contracts.form.new_title'))

@section('content')
    <div class="mb-5">
        <a href="{{ $backUrl }}" class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 transition hover:text-slate-900">
            <span aria-hidden="true">←</span>
            {{ __('contracts.actions.back_to_contracts') }}
        </a>
        <h1 class="mt-3 text-xl font-semibold text-slate-900">{{ __('contracts.form.new_title') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $company?->name ?? __('contracts.fields.choose_company_for_new') }}</p>
    </div>

    <form action="{{ route('contracts.store') }}" method="POST" class="max-w-4xl">
        @csrf
        @if ($companyContext['active'] ?? false)
            <input type="hidden" name="origin" value="company">
            <input type="hidden" name="tab" value="contracts">
        @endif

        <div data-testid="contract-form-workspace" class="overflow-hidden border-y border-slate-200 bg-white">
            <section class="px-4 py-5 sm:px-5">
                <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('contracts.fields.basic_information') }}</h2>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label for="company_id" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('contracts.fields.company') }} <span class="text-red-500">*</span></label>

                        @if ($company)
                            <input type="hidden" name="company_id" value="{{ $company->id }}">
                            <p class="py-2 text-sm font-medium text-slate-900">{{ $company->name }}</p>
                        @else
                            <select name="company_id" id="company_id" required class="w-full @error('company_id') border-red-300 @else border-gray-200 @enderror">
                                <option value="">{{ __('contracts.fields.choose_company') }}</option>
                                @foreach ($companies as $companyItem)
                                    <option value="{{ $companyItem->id }}" @selected(old('company_id', $company?->id) == $companyItem->id)>
                                        {{ $companyItem->name }}
                                    </option>
                                @endforeach
                            </select>
                        @endif

                        @error('company_id')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="md:col-span-2">
                        <label for="contract_number" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('contracts.fields.number') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="contract_number" id="contract_number" value="{{ old('contract_number') }}" placeholder="CTR-2026-001" required
                            class="w-full font-mono @error('contract_number') border-red-300 @else border-gray-200 @enderror">
                        @error('contract_number')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="start_date" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('contracts.fields.start_date') }} <span class="text-red-500">*</span></label>
                        <x-form.date-input name="start_date" :value="old('start_date')" required />
                    </div>

                    <div>
                        <label for="end_date" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('contracts.fields.end_date') }}</label>
                        <x-form.date-input name="end_date" :value="old('end_date')" />
                    </div>

                    <div>
                        <label for="status" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('contracts.fields.status') }} <span class="text-red-500">*</span></label>
                        <select name="status" id="status" required class="w-full @error('status') border-red-300 @else border-gray-200 @enderror">
                            <option value="active" @selected(old('status', 'active') === 'active')>{{ __('contracts.statuses.active_form') }}</option>
                            <option value="terminated" @selected(old('status') === 'terminated')>{{ __('contracts.statuses.terminated') }}</option>
                        </select>
                        @error('status')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </section>

            <section class="border-t border-slate-200 px-4 py-5 sm:px-5">
                <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('contracts.fields.comment') }}</h2>
                <div class="mt-4">
                    <label for="comment" class="sr-only">{{ __('contracts.fields.comment') }}</label>
                    <textarea name="comment" id="comment" rows="3" class="w-full @error('comment') border-red-300 @else border-gray-200 @enderror">{{ old('comment') }}</textarea>
                    @error('comment')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>
            </section>

            <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 px-4 py-4 sm:px-5">
                <button type="submit" class="bg-blue-600">{{ __('contracts.actions.save') }}</button>
                <a href="{{ $backUrl }}" class="border border-gray-200">{{ __('contracts.actions.cancel') }}</a>
            </div>
        </div>
    </form>
@endsection
