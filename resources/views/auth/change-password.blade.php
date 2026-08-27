@extends('layouts.guest')

@section('title', auth()->user()->mustChangePassword() ? __('auth.password.set_title') : __('auth.password.change_title'))

@section('content')
    @if (auth()->user()->mustChangePassword())
        <h1 class="text-2xl font-bold text-gray-900">{{ __('auth.password.set_title') }}</h1>
        <p class="mt-2 text-sm text-gray-600">{{ __('auth.password.set_description') }}</p>
    @else
        <h1 class="text-2xl font-bold text-gray-900">{{ __('auth.password.change_title') }}</h1>
    @endif
    <form method="POST" action="{{ route('user-password.update') }}" class="mt-6 space-y-4">
        @csrf
        @method('PUT')
        @unless (auth()->user()->mustChangePassword())
            <x-forms.password-input name="current_password" :label="__('auth.password.current')"
                autocomplete="current-password" error-bag="updatePassword" required />
        @endunless
        <div>
            <x-forms.password-input name="password" :label="__('auth.password.new')"
                autocomplete="new-password" error-bag="updatePassword" required />
            <p class="mt-2 text-xs leading-5 text-gray-500">
                {{ __('auth.password.requirements') }}
            </p>
        </div>
        <x-forms.password-input name="password_confirmation" :label="__('auth.password.confirmation')"
            autocomplete="new-password" error-bag="updatePassword" required />
        <button type="submit" class="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">
            {{ auth()->user()->mustChangePassword() ? __('auth.password.set_submit') : __('auth.password.change_submit') }}
        </button>
    </form>

    @unless (auth()->user()->mustChangePassword())
        <a href="{{ $returnUrl }}" class="mt-3 block w-full rounded-lg border border-gray-300 px-4 py-2.5 text-center text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('auth.password.back') }}</a>
    @endunless

    <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
        @csrf
        <button type="submit" class="text-sm font-medium text-gray-500 hover:text-gray-800">{{ __('auth.logout') }}</button>
    </form>
@endsection
