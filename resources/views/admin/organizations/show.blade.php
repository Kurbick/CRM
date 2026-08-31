@extends('layouts.app')

@section('title', $organization->name)

@section('content')
    <div class="mx-auto max-w-4xl">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 pb-5">
            <a href="{{ route('admin.organizations.index') }}" class="text-sm text-gray-500 hover:text-gray-900">{{ $organizationCount === 1 ? __('organizations.admin.back_to_organization') : __('organizations.admin.back') }}</a>
            <a href="{{ route('admin.organizations.edit', $organization) }}" class="crm-light-action">{{ __('organizations.admin.actions.edit') }}</a>
        </div>

        @php($value = fn ($value) => filled($value) ? $value : '—')
        <div class="space-y-8" data-organization-show>
            <section>
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('organizations.admin.sections.general') }}</h2>
                <dl class="grid gap-x-8 gap-y-4 border-y border-gray-200 py-5 text-sm sm:grid-cols-2">
                    <div><dt class="text-gray-500">{{ __('organizations.admin.fields.organization_name') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $value($organization->name) }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('organizations.admin.fields.legal_name') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $value($organization->legal_name) }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('organizations.admin.fields.voen') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $value($organization->voen) }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('organizations.admin.fields.invoice_number_code') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $value($organization->invoice_number_code) }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('organizations.admin.fields.active') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $organization->is_active ? __('organizations.statuses.active') : __('organizations.statuses.inactive') }}</dd></div>
                </dl>
            </section>

            <section>
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('organizations.admin.sections.banking') }}</h2>
                <dl class="grid gap-x-8 gap-y-4 border-y border-gray-200 py-5 text-sm sm:grid-cols-2">
                    <div><dt class="text-gray-500">{{ __('organizations.admin.fields.bank') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $value($organization->bank_name) }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('organizations.admin.fields.iban') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $value($organization->iban) }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('organizations.admin.fields.bank_voen') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $value($organization->bank_voen) }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('organizations.admin.fields.bank_correspondent_account') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $value($organization->bank_correspondent_account) }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('organizations.admin.fields.bank_code') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $value($organization->bank_code) }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('organizations.admin.fields.swift') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $value($organization->swift) }}</dd></div>
                </dl>
            </section>

            <section>
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('organizations.admin.sections.tax') }}</h2>
                <dl class="grid gap-x-8 gap-y-4 border-y border-gray-200 py-5 text-sm sm:grid-cols-2">
                    <div><dt class="text-gray-500">{{ __('organizations.admin.fields.is_vat_payer') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $organization->is_vat_payer ? __('organizations.admin.values.vat_yes') : __('organizations.admin.values.vat_no') }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('organizations.admin.fields.vat_rate') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $organization->is_vat_payer && filled($organization->vat_rate) ? $organization->vat_rate.'%' : '—' }}</dd></div>
                </dl>
            </section>
        </div>

        <div class="mt-6 flex flex-wrap justify-end gap-2">
            @if ($organization->is_active)
                <form method="POST" action="{{ route('admin.organizations.deactivate', $organization) }}">@csrf @method('PATCH')<button class="crm-light-action" type="submit">{{ __('organizations.admin.actions.deactivate') }}</button></form>
            @elseif (! $organization->is_active)
                <form method="POST" action="{{ route('admin.organizations.activate', $organization) }}">@csrf @method('PATCH')<button class="crm-light-action" type="submit">{{ __('organizations.admin.actions.activate') }}</button></form>
            @endif
            @if (! $organization->contracts()->exists() && ! $organization->invoices()->exists() && ! $organization->invoiceNumberCounters()->exists() && ! $organization->creditBalances()->exists())
                <form method="POST" action="{{ route('admin.organizations.destroy', $organization) }}" onsubmit="return confirm('{{ __('organizations.admin.actions.delete') }}?')">@csrf @method('DELETE')<button class="crm-light-action text-red-600" type="submit">{{ __('organizations.admin.actions.delete') }}</button></form>
            @endif
        </div>
    </div>
@endsection
