@extends('layouts.app')

@section('title', 'Главная')

@section('content')
    <div class="mx-auto max-w-3xl">
        <header class="border-b border-slate-200 pb-4">
            <h1 class="text-xl font-semibold text-slate-900">Главная</h1>
            <p class="mt-1 text-sm text-slate-500">Безопасная стартовая страница</p>
        </header>

        @unless ($hasReadableSection)
            <section data-testid="home-fallback" class="border-b border-slate-200 py-5">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Доступ</h2>
                <p class="mt-3 text-sm text-slate-900">Добро пожаловать в CRM.</p>
                <p class="mt-1 text-sm text-slate-500">
                    Ваша учётная запись активна, но для неё пока нет доступных рабочих разделов.
                </p>
                <p class="mt-3 text-sm text-slate-500">
                    Обратитесь к администратору, если вам требуется дополнительный доступ.
                </p>
            </section>
        @else
            <section data-testid="home-navigation-guidance" class="border-b border-slate-200 py-5">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Доступные разделы</h2>
                <p class="mt-3 text-sm text-slate-500">Выберите доступный раздел в навигации.</p>
            </section>
        @endunless
    </div>
@endsection
