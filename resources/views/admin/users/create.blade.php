@extends('layouts.app')

@section('title', __('admin.users.create_title'))

@section('content')
    <div class="mx-auto max-w-4xl">
        <div class="mb-6">
            <a href="{{ route('admin.users.index') }}" class="mb-2 inline-block text-sm text-gray-500 hover:text-gray-900">{{ __('admin.users.back') }}</a>
            <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.users.create_title') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ __('admin.users.create_description') }}</p>
        </div>
        <div class="border-t border-gray-200 pt-6">
            @include('admin.users._form', [
                'mode' => 'create',
                'action' => route('admin.users.store'),
                'cancelUrl' => route('admin.users.index'),
                'user' => null,
                'roles' => $roles,
            ])
        </div>
    </div>
@endsection
