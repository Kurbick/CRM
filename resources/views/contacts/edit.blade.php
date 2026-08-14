@extends('layouts.app')

@section('title', 'Редактирование контакта')

@section('content')
    <div class="mb-5">
        <a href="{{ $backUrl }}" class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 transition hover:text-slate-900">
            <span aria-hidden="true">←</span>
            Назад
        </a>
        <h1 class="mt-3 text-xl font-semibold text-slate-900">Редактирование контакта</h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ trim($contact->first_name.' '.$contact->last_name) }}
            <span class="mx-1.5 text-slate-300">·</span>
            {{ $company->name }}
        </p>
    </div>

    <form action="{{ route('contacts.update', $contact) }}" method="POST" class="max-w-4xl">
        @csrf
        @method('PUT')
        @if ($companyContext['active'])
            <input type="hidden" name="origin" value="company">
            <input type="hidden" name="tab" value="contacts">
        @endif

        @include('contacts._form', [
            'contact' => $contact,
            'company' => $company,
            'cancelUrl' => $backUrl,
        ])
    </form>

    @can('delete', $contact)
        <form action="{{ route('contacts.destroy', $contact) }}" method="POST" class="mt-4 max-w-4xl border-t border-slate-200 pt-4"
            onsubmit="return confirm('Удалить контактное лицо?')">
            @csrf
            @method('DELETE')
            @if ($companyContext['active'])
                <input type="hidden" name="origin" value="company">
                <input type="hidden" name="tab" value="contacts">
            @endif
            <button type="submit" class="text-sm font-medium text-red-600 transition hover:text-red-800">
                Удалить контакт
            </button>
        </form>
    @endcan
@endsection
