@extends('layouts.app')

@section('title', __('organizations.admin.index_title'))

@section('content')
    <div class="mx-auto max-w-5xl">
        <div class="mb-8 flex flex-wrap items-start justify-between gap-4 border-b border-gray-200 pb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('organizations.admin.index_title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ __('organizations.admin.index_description') }}</p>
            </div>
            <a href="{{ route('admin.organizations.create') }}" class="crm-light-action">{{ __('organizations.admin.actions.create') }}</a>
        </div>

        <div class="divide-y divide-gray-200 border-y border-gray-200 bg-white">
            @forelse ($organizations as $organization)
                <a href="{{ route('admin.organizations.show', $organization) }}" class="flex flex-wrap items-center justify-between gap-4 px-4 py-4 transition hover:bg-slate-50 focus:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:px-5">
                    <div class="min-w-0">
                        <div class="font-medium text-blue-700">{{ $organization->name }}</div>
                        <div class="mt-1 flex flex-wrap gap-2 text-xs text-gray-500">
                            <span>{{ $organization->is_active ? __('organizations.statuses.active') : __('organizations.statuses.inactive') }}</span>
                            @if ($organization->invoice_number_code)<span>· {{ $organization->invoice_number_code }}</span>@endif
                        </div>
                    </div>
                </a>
            @empty
                <p class="px-5 py-8 text-sm text-gray-500">{{ __('organizations.admin.empty') }}</p>
            @endforelse
        </div>
    </div>
@endsection
