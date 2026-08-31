@extends('layouts.app')

@section('title', __('organizations.switcher.current'))

@section('content')
    <div class="max-w-2xl border-y border-slate-200 bg-white px-5 py-8 sm:px-6">
        <h1 class="text-xl font-semibold text-slate-900">
            {{ $title ?? ($state === 'none' ? __('organizations.guard.no_active') : __('organizations.switcher.choose')) }}
        </h1>
        <p class="mt-2 text-sm text-slate-600">
            {{ $message ?? ($state === 'none' ? __('organizations.guard.no_active_help') : __('organizations.guard.select_required')) }}
        </p>

        @if ($state === 'none')
            @if ($canManageOrganizations)
                <a href="{{ route('admin.organizations.index') }}" class="mt-5 inline-flex text-sm font-medium text-blue-700 hover:text-blue-900">
                    {{ __('organizations.guard.manage_link') }}
                </a>
            @else
                <p class="mt-5 text-sm text-slate-500">{{ __('organizations.guard.contact_admin') }}</p>
            @endif
        @endif
    </div>
@endsection
