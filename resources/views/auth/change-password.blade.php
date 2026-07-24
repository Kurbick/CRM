@extends('layouts.guest')

@section('title', 'Смена пароля')

@section('content')
    <h1 class="text-2xl font-bold text-gray-900">Смена пароля</h1>
    @if (auth()->user()->mustChangePassword())
        <p class="mt-2 text-sm text-gray-600">Для продолжения работы установите новый пароль.</p>
    @endif
    <form method="POST" action="{{ route('user-password.update') }}" class="mt-6 space-y-4">
        @csrf
        @method('PUT')
        <x-forms.password-input name="current_password" label="Текущий пароль"
            autocomplete="current-password" error-bag="updatePassword" required />
        <div>
            <x-forms.password-input name="password" label="Новый пароль"
                autocomplete="new-password" error-bag="updatePassword" required />
            <p class="mt-2 text-xs leading-5 text-gray-500">
                Не менее 12 символов, включая заглавную и строчную буквы, цифру и специальный символ.
            </p>
        </div>
        <x-forms.password-input name="password_confirmation" label="Подтверждение нового пароля"
            autocomplete="new-password" error-bag="updatePassword" required />
        <button type="submit" class="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">Изменить пароль</button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
        @csrf
        <button type="submit" class="text-sm font-medium text-gray-500 hover:text-gray-800">Выйти</button>
    </form>
@endsection
