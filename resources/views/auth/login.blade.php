@extends('layouts.guest')

@section('title', 'Вход')

@section('content')
    <div class="text-center">
        <h1 class="text-2xl font-bold text-gray-900">Вход</h1>
        <p class="mx-auto mt-1 max-w-sm text-sm text-gray-500">Введите данные внутренней учётной записи.</p>
    </div>

    @if ($errors->any())
        <div class="mt-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            Не удалось выполнить вход. Проверьте email и пароль.
        </div>
    @endif

    @if (session('error'))
        <div class="mt-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4">
        @csrf
        <div>
            <label for="email" class="mb-1 block text-sm font-medium text-gray-700">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                autocomplete="username"
                class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
        </div>
        <x-forms.password-input name="password" label="Пароль" autocomplete="current-password" required />
        <label class="flex items-center gap-2 text-sm text-gray-600">
            <input name="remember" type="checkbox" value="1" class="rounded border-gray-300 text-blue-600">
            Запомнить меня
        </label>
        <div class="flex justify-center">
            <button type="submit" class="min-w-36 rounded-lg bg-blue-600 px-6 py-2.5 text-sm font-medium text-white hover:bg-blue-700">
                Войти
            </button>
        </div>
    </form>
@endsection
