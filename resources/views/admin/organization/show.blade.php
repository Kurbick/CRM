@extends('layouts.app')

@section('title', __('admin.organization.title'))

@section('content')
    @php
        $valueClass = fn ($value) => filled($value) ? 'text-gray-900' : 'text-gray-400';
        $value = fn ($value) => filled($value) ? $value : '—';
    @endphp

    <div class="mx-auto max-w-4xl">
        <div class="mb-8 flex flex-wrap items-start justify-between gap-4 border-b border-gray-200 pb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.organization.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ __('admin.organization.description') }}</p>
            </div>
            <a href="{{ route('admin.organization.edit') }}" class="crm-light-action">{{ __('admin.organization.actions.edit') }}</a>
        </div>

        <div class="space-y-8" data-organization-show>
            <section>
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.organization.sections.general') }}</h2>
                <dl class="grid gap-x-8 gap-y-4 border-y border-gray-200 py-5 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-500">{{ __('admin.organization.fields.name') }}</dt>
                        <dd class="mt-1 font-medium {{ $valueClass($organization?->name) }}" @if (blank($organization?->name)) data-organization-empty-value @endif>{{ $value($organization?->name) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">VÖEN</dt>
                        <dd class="mt-1 font-medium {{ $valueClass($organization?->voen) }}" @if (blank($organization?->voen)) data-organization-empty-value @endif>{{ $value($organization?->voen) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.organization.fields.invoice_number_code') }}</dt>
                        <dd class="mt-1 font-medium {{ $valueClass($organization?->invoice_number_code) }}" @if (blank($organization?->invoice_number_code)) data-organization-empty-value @endif>{{ $value($organization?->invoice_number_code) }}</dd>
                    </div>
                </dl>
            </section>

            <section>
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.organization.sections.banking') }}</h2>
                <dl class="grid gap-x-8 gap-y-4 border-y border-gray-200 py-5 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-500">{{ __('admin.organization.fields.bank') }}</dt>
                        <dd class="mt-1 font-medium {{ $valueClass($organization?->bank_name) }}" @if (blank($organization?->bank_name)) data-organization-empty-value @endif>{{ $value($organization?->bank_name) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.organization.fields.iban') }}</dt>
                        <dd class="mt-1 font-medium {{ $valueClass($organization?->iban) }}" @if (blank($organization?->iban)) data-organization-empty-value @endif>{{ $value($organization?->iban) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.organization.fields.bank_code') }}</dt>
                        <dd class="mt-1 font-medium {{ $valueClass($organization?->bank_code) }}" @if (blank($organization?->bank_code)) data-organization-empty-value @endif>{{ $value($organization?->bank_code) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.organization.fields.bank_voen') }}</dt>
                        <dd class="mt-1 font-medium {{ $valueClass($organization?->bank_voen) }}" @if (blank($organization?->bank_voen)) data-organization-empty-value @endif>{{ $value($organization?->bank_voen) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.organization.fields.swift') }}</dt>
                        <dd class="mt-1 font-medium {{ $valueClass($organization?->swift) }}" @if (blank($organization?->swift)) data-organization-empty-value @endif>{{ $value($organization?->swift) }}</dd>
                    </div>
                </dl>
            </section>
        </div>
    </div>
@endsection
