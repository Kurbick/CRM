@extends('layouts.app')

@section('title', __('contacts.titles.new'))

@section('content')
    <div class="mb-5">
        <a href="{{ $backUrl }}" class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 transition hover:text-slate-900">
            <span aria-hidden="true">←</span>
            {{ __('contacts.navigation.back') }}
        </a>
        <h1 class="mt-3 text-xl font-semibold text-slate-900">{{ __('contacts.titles.new') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ __('contacts.navigation.company_contact', ['company' => $company->name]) }}</p>
    </div>

    <form action="{{ route('companies.contacts.store', $company) }}" method="POST" class="max-w-4xl">
        @csrf
        @if ($companyContext['active'])
            <input type="hidden" name="origin" value="company">
            <input type="hidden" name="tab" value="contacts">
        @endif

        @include('contacts._form', [
            'contact' => null,
            'company' => $company,
            'cancelUrl' => $backUrl,
        ])
    </form>
@endsection
