@extends('layouts.app')

@section('title', __('admin.organization.edit_title'))

@section('content')
    <div class="mx-auto max-w-4xl">
        <div class="mb-6">
            <a href="{{ route('admin.organization.show') }}" class="mb-2 inline-block text-sm text-gray-500 hover:text-gray-900">{{ __('admin.organization.back') }}</a>
            <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.organization.edit_title') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ __('admin.organization.description') }}</p>
        </div>

        <form method="POST" action="{{ route('admin.organization.update') }}" class="space-y-6 border-t border-gray-200 pt-6" data-organization-edit-form>
            @csrf
            @method('PUT')

            <section class="border-b border-gray-200 pb-6">
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.organization.sections.general') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="name" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('admin.organization.fields.organization_name') }}</label>
                        <input id="name" name="name" type="text" value="{{ old('name', $organization?->name) }}" required class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="voen" class="mb-1.5 block text-sm font-medium text-gray-700">VÖEN</label>
                        <input id="voen" name="voen" type="text" value="{{ old('voen', $organization?->voen) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        @error('voen')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="invoice_number_code" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('admin.organization.fields.invoice_number_code') }}</label>
                        <input id="invoice_number_code" name="invoice_number_code" type="text" value="{{ old('invoice_number_code', $organization?->invoice_number_code) }}" maxlength="12" pattern="[A-Za-z0-9]+" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm uppercase outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.organization.help.invoice_number_code') }}</p>
                        @error('invoice_number_code')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>

            <section class="border-b border-gray-200 pb-6">
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.organization.sections.banking') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="bank_name" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('admin.organization.fields.bank') }}</label>
                        <input id="bank_name" name="bank_name" type="text" value="{{ old('bank_name', $organization?->bank_name) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        @error('bank_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="iban" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('admin.organization.fields.iban') }}</label>
                        <input id="iban" name="iban" type="text" value="{{ old('iban', $organization?->iban) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        @error('iban')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="bank_code" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('admin.organization.fields.bank_code') }}</label>
                        <input id="bank_code" name="bank_code" type="text" value="{{ old('bank_code', $organization?->bank_code) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        @error('bank_code')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="bank_voen" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('admin.organization.fields.bank_voen') }}</label>
                        <input id="bank_voen" name="bank_voen" type="text" value="{{ old('bank_voen', $organization?->bank_voen) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        @error('bank_voen')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="swift" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('admin.organization.fields.swift') }}</label>
                        <input id="swift" name="swift" type="text" value="{{ old('swift', $organization?->swift) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        @error('swift')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap items-center justify-end gap-3 pt-1">
                <a href="{{ route('admin.organization.show') }}" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.organization.actions.cancel') }}</a>
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">{{ __('admin.organization.actions.save') }}</button>
            </div>
        </form>
    </div>
@endsection
