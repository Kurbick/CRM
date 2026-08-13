@extends('layouts.app')

@section('title', 'Редактирование компании')

@section('content')
    @php
        $backLabel = $returnContext['origin'] === 'show'
            ? 'Назад к компании'
            : $returnContext['label'];
    @endphp

    <div class="mb-5">
        <a href="{{ $returnContext['url'] }}" class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 transition hover:text-slate-900">
            <span aria-hidden="true">←</span>
            {{ $backLabel }}
        </a>
        <h1 class="mt-3 text-xl font-semibold text-slate-900">Редактирование компании</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $company->name }}</p>
    </div>

    <form action="{{ route('companies.update', $company) }}" method="POST" class="max-w-5xl">
        @csrf
        @method('PUT')
        @foreach ($returnContext['hidden'] as $contextName => $contextValue)
            <input type="hidden" name="{{ $contextName }}" value="{{ $contextValue }}">
        @endforeach

        @include('companies._form', [
            'company' => $company,
            'cancelUrl' => $returnContext['url'],
        ])
    </form>
@endsection
