@extends('layouts.guest')

@section('title', __('auth.login.title'))

@section('content')
    <div class="mb-4 flex justify-end" aria-label="RU AZ">
        <div class="flex items-center gap-1">
            @php($currentLocale = app()->getLocale())
            @foreach (['ru' => 'RU', 'az' => 'AZ'] as $locale => $label)
                <form method="POST" action="{{ route('locale.update') }}">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $locale }}">
                    <button type="submit" aria-pressed="{{ $currentLocale === $locale ? 'true' : 'false' }}"
                        class="rounded px-1.5 py-1 text-[11px] font-semibold tracking-wide transition {{ $currentLocale === $locale ? 'bg-slate-100 text-slate-900' : 'text-slate-400 hover:bg-slate-50 hover:text-slate-700' }}">
                        {{ $label }}
                    </button>
                </form>
            @endforeach
        </div>
    </div>

    <div class="text-center">
        <h1 class="text-2xl font-bold text-gray-900">{{ __('auth.login.title') }}</h1>
        <p class="mx-auto mt-1 max-w-sm text-sm text-gray-500">{{ __('auth.login.description') }}</p>
    </div>

    @if ($errors->any())
        <div class="mt-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ __('auth.login.error') }}
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
            <label for="email" class="mb-1 block text-sm font-medium text-gray-700">{{ __('auth.login.email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                autocomplete="username"
                class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
        </div>
        <x-forms.password-input name="password" :label="__('auth.login.password')" autocomplete="current-password" required />
        <label class="flex items-center gap-2 text-sm text-gray-600">
            <input name="remember" type="checkbox" value="1" class="rounded border-gray-300 text-blue-600">
            {{ __('auth.login.remember') }}
        </label>
        <div class="flex justify-center">
            <button type="submit" class="min-w-36 rounded-lg bg-blue-600 px-6 py-2.5 text-sm font-medium text-white hover:bg-blue-700">
                {{ __('auth.login.submit') }}
            </button>
        </div>
    </form>
@endsection
