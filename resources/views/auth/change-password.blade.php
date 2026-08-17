@extends('layouts.guest')

@section('title', auth()->user()->mustChangePassword() ? 'Установите новый пароль' : 'Смена пароля')

@section('content')
    @if (auth()->user()->mustChangePassword())
        <h1 class="text-2xl font-bold text-gray-900">Установите новый пароль</h1>
        <p class="mt-2 text-sm text-gray-600">Для продолжения работы установите новый пароль.</p>
    @else
        <h1 class="text-2xl font-bold text-gray-900">Смена пароля</h1>
    @endif
    <form method="POST" action="{{ route('user-password.update') }}" class="mt-6 space-y-4">
        @csrf
        @method('PUT')
        @unless (auth()->user()->mustChangePassword())
            <x-forms.password-input name="current_password" label="Текущий пароль"
                autocomplete="current-password" error-bag="updatePassword" required />
        @endunless
        <div>
            <x-forms.password-input name="password" label="Новый пароль"
                autocomplete="new-password" error-bag="updatePassword" required />
            <p class="mt-2 text-xs leading-5 text-gray-500">
                Не менее 12 символов, включая заглавную и строчную буквы, цифру и специальный символ.
            </p>
        </div>
        <x-forms.password-input name="password_confirmation" label="Подтверждение нового пароля"
            autocomplete="new-password" error-bag="updatePassword" required />
        <button type="submit" class="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">
            {{ auth()->user()->mustChangePassword() ? 'Установить новый пароль' : 'Изменить пароль' }}
        </button>
    </form>

    @unless (auth()->user()->mustChangePassword())
        <a href="{{ $returnUrl }}" class="mt-3 block w-full rounded-lg border border-gray-300 px-4 py-2.5 text-center text-sm font-medium text-gray-700 hover:bg-gray-50">← Вернуться в CRM</a>
    @endunless

    <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
        @csrf
        <button type="submit" class="text-sm font-medium text-gray-500 hover:text-gray-800">Выйти</button>
    </form>
@endsection
