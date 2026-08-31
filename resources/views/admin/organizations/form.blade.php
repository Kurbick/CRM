@extends('layouts.app')

@section('title', $organization ? __('organizations.admin.edit_title') : __('organizations.admin.create_title'))

@section('content')
    <div class="mx-auto max-w-4xl">
        <div class="mb-6">
            <a href="{{ $organization ? route('admin.organizations.show', $organization) : route('admin.organizations.index') }}" class="mb-2 inline-block text-sm text-gray-500 hover:text-gray-900">{{ $organization ? __('organizations.admin.back_to_organization') : __('organizations.admin.back') }}</a>
            <h1 class="text-2xl font-bold text-gray-900">{{ $organization ? __('organizations.admin.edit_title') : __('organizations.admin.create_title') }}</h1>
        </div>

        <form method="POST" action="{{ $organization ? route('admin.organizations.update', $organization) : route('admin.organizations.store') }}" class="space-y-6 border-t border-gray-200 pt-6">
            @csrf
            @if ($organization) @method('PUT') @endif
            <section class="border-b border-gray-200 pb-6">
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('organizations.admin.sections.general') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label for="name" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('organizations.admin.fields.organization_name') }}</label><input id="name" name="name" value="{{ old('name', $organization?->name) }}" required class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">@error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div><label for="legal_name" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('organizations.admin.fields.legal_name') }}</label><input id="legal_name" name="legal_name" value="{{ old('legal_name', $organization?->legal_name) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">@error('legal_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div><label for="voen" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('organizations.admin.fields.voen') }}</label><input id="voen" name="voen" value="{{ old('voen', $organization?->voen) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm"></div>
                    <div><label for="invoice_number_code" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('organizations.admin.fields.invoice_number_code') }}</label><input id="invoice_number_code" name="invoice_number_code" value="{{ old('invoice_number_code', $organization?->invoice_number_code) }}" @required(! $organization) maxlength="12" pattern="[A-Za-z0-9]+" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm uppercase"><p class="mt-1 text-xs text-gray-500">{{ __('organizations.admin.help.invoice_number_code') }}</p>@error('invoice_number_code')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                </div>
            </section>
            <section class="border-b border-gray-200 pb-6">
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('organizations.admin.sections.banking') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach (['bank_name' => 'bank', 'iban' => 'iban', 'bank_voen' => 'bank_voen', 'bank_correspondent_account' => 'bank_correspondent_account', 'bank_code' => 'bank_code', 'swift' => 'swift'] as $field => $label)
                        <div><label for="{{ $field }}" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('organizations.admin.fields.'.$label) }}</label><input id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $organization?->{$field}) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">@error($field)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    @endforeach
                </div>
            </section>
            <section class="border-b border-gray-200 pb-6" x-data="{ vatPayer: @js((bool) old('is_vat_payer', $organization?->is_vat_payer ?? false)) }">
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('organizations.admin.sections.tax') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="flex items-center gap-2 sm:col-span-2">
                        <input type="hidden" name="is_vat_payer" value="0">
                        <input id="is_vat_payer" name="is_vat_payer" value="1" type="checkbox" x-model="vatPayer" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <label for="is_vat_payer" class="text-sm font-medium text-gray-700">{{ __('organizations.admin.fields.is_vat_payer') }}</label>
                    </div>
                    <div>
                        <label for="vat_rate" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('organizations.admin.fields.vat_rate') }}</label>
                        <input id="vat_rate" name="vat_rate" type="number" min="0.01" max="100" step="0.01" value="{{ old('vat_rate', $organization?->vat_rate) }}" x-bind:disabled="! vatPayer" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm disabled:bg-gray-100 disabled:text-gray-400">
                        @error('vat_rate')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>
            <div class="flex flex-wrap justify-end gap-3"><a href="{{ $organization ? route('admin.organizations.show', $organization) : route('admin.organizations.index') }}" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm">{{ __('organizations.admin.actions.cancel') }}</a><button type="submit" class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm text-white">{{ __('organizations.admin.actions.save') }}</button></div>
        </form>
    </div>
@endsection
