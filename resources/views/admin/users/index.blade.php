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
                <a href="{{ route('admin.users.create') }}" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700">+ Добавить пользователя</a>
            @endcan
        @endcan
    </div>

    <form method="GET" action="{{ route('admin.users.index') }}" class="mb-6 grid gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm md:grid-cols-4">
        <input type="search" name="search" value="{{ $search }}" placeholder="Имя или email" class="rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
        <select name="status" class="rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-blue-500">
            <option value="">Все статусы</option>
            <option value="active" @selected($status === 'active')>Активные</option>
            <option value="inactive" @selected($status === 'inactive')>Отключённые</option>
        </select>
        <select name="role" class="rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-blue-500">
            <option value="">Все группы</option>
            @foreach ($roles as $availableRole)
                <option value="{{ $availableRole->id }}" @selected((string) $role === (string) $availableRole->id)>{{ $availableRole->display_name }}</option>
            @endforeach
            <option value="none" @selected($role === 'none')>Без группы</option>
        </select>
        <div class="flex gap-2">
            <button class="flex-1 rounded-lg bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-900">Применить</button>
            @if ($filtersActive)
                <a href="{{ route('admin.users.index') }}" class="rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">Сбросить</a>
            @endif
        </div>
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="direction" value="{{ $direction }}">
    </form>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3"><a href="{{ $sortUrl('name') }}">Пользователь</a></th>
                        <th class="px-4 py-3">Группа</th>
                        <th class="px-4 py-3">Статус</th>
                        <th class="px-4 py-3"><a href="{{ $sortUrl('last_login_at') }}">Последний вход</a></th>
                        <th class="px-4 py-3 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($users as $user)
                        <tr>
                            <td class="px-4 py-3"><div class="font-medium text-gray-900">{{ $user->name }}</div><div class="text-xs text-gray-500">{{ $user->email }}</div></td>
                            <td class="px-4 py-3"><x-admin.users.role-badge :role="$user->roles->first()" /></td>
                            <td class="px-4 py-3"><x-admin.users.status-badge :active="$user->is_active" /></td>
                            <td class="px-4 py-3 text-gray-600">{{ $user->last_login_at?->translatedFormat('d.m.Y H:i') ?? 'Никогда' }}</td>
                            <td class="px-4 py-3 text-right"><a href="{{ route('admin.users.edit', $user) }}" class="font-medium text-blue-600 hover:text-blue-800">Открыть</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500">Пользователи не найдены.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div class="border-t border-gray-100 px-4 py-3">{{ $users->links() }}</div>
        @endif
    </div>
@endsection
