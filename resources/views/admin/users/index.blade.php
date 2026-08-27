@extends('layouts.app')

@section('title', __('admin.users.title'))

@section('content')
    @php
        $filtersActive = $search !== '' || $status !== '' || $role !== '';
        $displayDateTime = app(\App\Support\DisplayDateTime::class);
        $roleLabel = fn ($availableRole) => match ($availableRole->name) {
            \App\Support\Access\SystemRole::Administrator->value => __('admin.access.system_roles.administrator'),
            \App\Support\Access\SystemRole::Accountant->value => __('admin.access.system_roles.accountant'),
            \App\Support\Access\SystemRole::Viewer->value => __('admin.access.system_roles.viewer'),
            default => $availableRole->display_name,
        };
        $sortUrl = fn (string $column) => route('admin.users.index', array_filter([
            'search' => $search, 'status' => $status, 'role' => $role, 'sort' => $column,
            'direction' => $sort === $column && $direction === 'asc' ? 'desc' : 'asc',
        ], fn ($value) => $value !== ''));
    @endphp

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.users.title') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ __('admin.users.description') }}</p>
        </div>
        @can('users.create')
            @can('users.assign_role')
                <a href="{{ route('admin.users.create') }}" class="crm-light-action">+ {{ __('admin.users.actions.add') }}</a>
            @endcan
        @endcan
    </div>

    <form method="GET" action="{{ route('admin.users.index') }}" class="mb-6 flex flex-col gap-3 border-b border-gray-200 pb-5 lg:flex-row lg:items-center">
        <input type="search" name="search" value="{{ $search }}" placeholder="{{ __('admin.users.filters.search_placeholder') }}" class="min-w-0 flex-1 rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
        <select name="status" class="rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-blue-500 {{ $status === '' ? 'crm-filter-neutral' : '' }}">
            <option value="">{{ __('admin.users.filters.all_statuses') }}</option>
            <option value="active" @selected($status === 'active')>{{ __('admin.users.filters.active') }}</option>
            <option value="inactive" @selected($status === 'inactive')>{{ __('admin.users.filters.inactive') }}</option>
        </select>
        <select name="role" class="rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-blue-500 {{ $role === '' ? 'crm-filter-neutral' : '' }}">
            <option value="">{{ __('admin.users.filters.all_groups') }}</option>
            @foreach ($roles as $availableRole)
                <option value="{{ $availableRole->id }}" @selected((string) $role === (string) $availableRole->id)>{{ $roleLabel($availableRole) }}</option>
            @endforeach
            <option value="none" @selected($role === 'none')>{{ __('admin.users.filters.no_group') }}</option>
        </select>
        <div class="flex shrink-0 gap-2">
            <button class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.users.filters.search') }}</button>
            @if ($filtersActive)
                <a href="{{ route('admin.users.index') }}" class="rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('admin.users.filters.reset') }}</a>
            @endif
        </div>
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="direction" value="{{ $direction }}">
    </form>

    <div class="crm-table-shell">
        <div class="crm-table-heading">
            <span class="crm-table-heading-title">{{ __('admin.users.title') }}</span>
            <span class="crm-table-heading-count">{{ $users->total() }}</span>
        </div>
        <div class="crm-table-scroll">
            <table class="crm-table">
                <thead>
                    <tr>
                        <th>
                            <a href="{{ $sortUrl('name') }}" class="crm-table-sort">
                                {{ __('admin.users.table.user') }}
                                <span class="crm-table-sort-indicator {{ $sort === 'name' ? 'crm-table-sort-indicator-active' : '' }}">{{ $sort === 'name' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                            </a>
                        </th>
                        <th>{{ __('admin.users.table.group') }}</th>
                        <th>{{ __('admin.users.table.status') }}</th>
                        <th>
                            <a href="{{ $sortUrl('last_login_at') }}" class="crm-table-sort">
                                {{ __('admin.users.table.last_login') }}
                                <span class="crm-table-sort-indicator {{ $sort === 'last_login_at' ? 'crm-table-sort-indicator-active' : '' }}">{{ $sort === 'last_login_at' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                            </a>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <x-tables.clickable-row :url="route('admin.users.edit', $user)" :label="__('admin.users.table.profile', ['name' => $user->name])">
                            <td><div class="crm-table-primary">{{ $user->name }}</div><div class="crm-table-secondary">{{ $user->email }}</div></td>
                            <td><x-admin.users.role-badge :role="$user->roles->first()" /></td>
                            <td><x-admin.users.status-badge :active="$user->is_active" /></td>
                            <td class="crm-table-date">{{ $displayDateTime->format($user->last_login_at, 'd.m.Y H:i') ?? __('admin.users.fields.never_logged_in') }}</td>
                        </x-tables.clickable-row>
                    @empty
                        <tr>
                            <td colspan="4" class="crm-table-empty">
                                <span class="crm-table-empty-message">{{ __('admin.users.table.empty') }}</span>
                                @if ($filtersActive)
                                    <a href="{{ route('admin.users.index') }}" class="crm-table-empty-action">{{ __('admin.users.filters.reset_filters') }}</a>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div class="crm-table-footer">{{ $users->links() }}</div>
        @endif
    </div>
@endsection
