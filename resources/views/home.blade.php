@extends('layouts.app')

@section('title', 'Главная')

@section('content')
    <div class="mx-auto max-w-2xl rounded-xl border border-gray-200 bg-white p-8 shadow-sm">
        <h1 class="text-2xl font-bold text-gray-900">Вы вошли в систему</h1>

        @unless ($hasReadableSection)
            <p class="mt-3 text-sm text-gray-600">
                У вас нет прав для просмотра разделов. Обратитесь к администратору
            </p>
        @else
            <p class="mt-3 text-sm text-gray-600">
                Выберите доступный раздел в навигации.
            </p>
        @endunless
    </div>
@endsection
