@extends('layouts.app')

@section('title', 'Наша организация')

@section('content')
    @php
        $valueClass = fn ($value) => filled($value) ? 'text-gray-900' : 'text-gray-400';
        $value = fn ($value) => filled($value) ? $value : '—';
    @endphp

    <div class="mx-auto max-w-4xl">
        <div class="mb-8 flex flex-wrap items-start justify-between gap-4 border-b border-gray-200 pb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Наша организация</h1>
                <p class="mt-1 text-sm text-gray-500">Реквизиты организации, используемые в новых инвойсах.</p>
            </div>
            <a href="{{ route('admin.organization.edit') }}" class="crm-light-action">Редактировать</a>
        </div>

        <div class="space-y-8" data-organization-show>
            <section>
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">Основная информация</h2>
                <dl class="grid gap-x-8 gap-y-4 border-y border-gray-200 py-5 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-500">Название</dt>
                        <dd class="mt-1 font-medium {{ $valueClass($organization?->name) }}" @if (blank($organization?->name)) data-organization-empty-value @endif>{{ $value($organization?->name) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">VÖEN</dt>
                        <dd class="mt-1 font-medium {{ $valueClass($organization?->voen) }}" @if (blank($organization?->voen)) data-organization-empty-value @endif>{{ $value($organization?->voen) }}</dd>
                    </div>
                </dl>
            </section>

            <section>
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">Банковские реквизиты</h2>
                <dl class="grid gap-x-8 gap-y-4 border-y border-gray-200 py-5 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-500">Банк</dt>
                        <dd class="mt-1 font-medium {{ $valueClass($organization?->bank_name) }}" @if (blank($organization?->bank_name)) data-organization-empty-value @endif>{{ $value($organization?->bank_name) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">IBAN / счёт</dt>
                        <dd class="mt-1 font-medium {{ $valueClass($organization?->iban) }}" @if (blank($organization?->iban)) data-organization-empty-value @endif>{{ $value($organization?->iban) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Код банка</dt>
                        <dd class="mt-1 font-medium {{ $valueClass($organization?->bank_code) }}" @if (blank($organization?->bank_code)) data-organization-empty-value @endif>{{ $value($organization?->bank_code) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">VÖEN банка</dt>
                        <dd class="mt-1 font-medium {{ $valueClass($organization?->bank_voen) }}" @if (blank($organization?->bank_voen)) data-organization-empty-value @endif>{{ $value($organization?->bank_voen) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">SWIFT</dt>
                        <dd class="mt-1 font-medium {{ $valueClass($organization?->swift) }}" @if (blank($organization?->swift)) data-organization-empty-value @endif>{{ $value($organization?->swift) }}</dd>
                    </div>
                </dl>
            </section>
        </div>
    </div>
@endsection
