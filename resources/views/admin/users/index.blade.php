@extends('layouts.app')

@section('title', 'Пользователи')

@section('content')
    @php
        $filtersActive = $search !== '' || $status !== '' || $role !== '';
        $sortUrl = fn (string $column) => route('admin.users.index', array_filter([
            'search' => $search, 'status' => $status, 'role' => $role, 'sort' => $column,
            'direction' => $sort === $column && $direction === 'asc' ? 'desc' : 'asc',
        ], fn ($value) => $value !== ''));
    @endphp

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Пользователи</h1>
            <p class="mt-1 text-sm text-gray-500">Управление внутренними учётными записями и доступом к CRM.</p>
        </div>
        @can('users.create')
            @can('users.assign_role')
                <a href="{{ route('admin.users.create') }}" class="crm-light-action">+ Добавить пользователя</a>
            @endcan
        @endcan
    </div>

    <form method="GET" action="{{ route('admin.users.index') }}" class="mb-6 flex flex-col gap-3 border-b border-gray-200 pb-5 lg:flex-row lg:items-center">
        <input type="search" name="search" value="{{ $search }}" placeholder="Имя или email..." class="min-w-0 flex-1 rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
        <select name="status" class="rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-blue-500 {{ $status === '' ? 'crm-filter-neutral' : '' }}">
            <option value="">Все статусы</option>
            <option value="active" @selected($status === 'active')>Активные</option>
            <option value="inactive" @selected($status === 'inactive')>Отключённые</option>
        </select>
        <select name="role" class="rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-blue-500 {{ $role === '' ? 'crm-filter-neutral' : '' }}">
            <option value="">Все группы</option>
            @foreach ($roles as $availableRole)
                <option value="{{ $availableRole->id }}" @selected((string) $role === (string) $availableRole->id)>{{ $availableRole->display_name }}</option>
            @endforeach
            <option value="none" @selected($role === 'none')>Без группы</option>
        </select>
        <div class="flex shrink-0 gap-2">
            <button class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Найти</button>
            @if ($filtersActive)
                <a href="{{ route('admin.users.index') }}" class="rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">Сбросить</a>
            @endif
        </div>
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="direction" value="{{ $direction }}">
    </form>

    <div class="crm-table-shell">
        <div class="crm-table-heading">
            <span class="crm-table-heading-title">Пользователи</span>
            <span class="crm-table-heading-count">{{ $users->total() }}</span>
        </div>
        <div class="crm-table-scroll">
            <table class="crm-table">
                <thead>
                    <tr>
                        <th>
                            <a href="{{ $sortUrl('name') }}" class="crm-table-sort">
                                Пользователь
                                <span class="crm-table-sort-indicator {{ $sort === 'name' ? 'crm-table-sort-indicator-active' : '' }}">{{ $sort === 'name' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                            </a>
                        </th>
                        <th>Группа</th>
                        <th>Статус</th>
                        <th>
                            <a href="{{ $sortUrl('last_login_at') }}" class="crm-table-sort">
                                Последний вход
                                <span class="crm-table-sort-indicator {{ $sort === 'last_login_at' ? 'crm-table-sort-indicator-active' : '' }}">{{ $sort === 'last_login_at' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                            </a>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <x-tables.clickable-row :url="route('admin.users.edit', $user)" :label="'Профиль пользователя '.$user->name">
                            <td><div class="crm-table-primary">{{ $user->name }}</div><div class="crm-table-secondary">{{ $user->email }}</div></td>
                            <td><x-admin.users.role-badge :role="$user->roles->first()" /></td>
                            <td><x-admin.users.status-badge :active="$user->is_active" /></td>
                            <td class="crm-table-date">{{ $user->last_login_at?->copy()->setTimezone('Asia/Baku')->translatedFormat('d.m.Y H:i') ?? 'Никогда' }}</td>
                        </x-tables.clickable-row>
                    @empty
                        <tr>
                            <td colspan="4" class="crm-table-empty">
                                <span class="crm-table-empty-message">Пользователи не найдены.</span>
                                @if ($filtersActive)
                                    <a href="{{ route('admin.users.index') }}" class="crm-table-empty-action">Сбросить фильтры</a>
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
