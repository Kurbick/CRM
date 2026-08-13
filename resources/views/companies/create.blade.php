@extends('layouts.app')

@section('title', 'Новая компания')

@section('content')
    <div class="mb-5">
        <a href="{{ $backUrl }}" class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 transition hover:text-slate-900">
            <span aria-hidden="true">←</span>
            Назад к компаниям
        </a>
        <h1 class="mt-3 text-xl font-semibold text-slate-900">Новая компания</h1>
        <p class="mt-1 text-sm text-slate-500">Создание профиля контрагента.</p>
    </div>

    <form action="{{ route('companies.store') }}" method="POST" class="max-w-5xl">
        @csrf

        @include('companies._form', [
            'company' => null,
            'cancelUrl' => $backUrl,
        ])
    </form>
@endsection
