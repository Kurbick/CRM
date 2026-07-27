@extends('layouts.app')

@section('title', 'Группы')

@section('content')
    @php
        $createOpen = $errors->getBag('createRole')->any() || old('_section') === 'create';
    @endphp

    <div x-data="{ createOpen: @js($createOpen) }">
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Группы</h1>
                <p class="mt-1 text-sm text-gray-500">Настройка групп пользователей и их назначения в CRM.</p>
            </div>
            @can('roles.create')
                <button type="button" aria-controls="create-role-content" x-bind:aria-expanded="createOpen.toString()"
                    x-on:click="createOpen = !createOpen"
                    class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    + Добавить группу
                    <svg aria-hidden="true" class="ml-2 h-4 w-4 transition-transform" x-bind:class="{ 'rotate-180': createOpen }" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6" /></svg>
                </button>
            @endcan
        </div>

        @can('roles.create')
            <section data-create-role-accordion data-initial-open="{{ $createOpen ? 'true' : 'false' }}"
                id="create-role-content" x-show="createOpen" x-cloak
                class="mb-5 overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="flex items-center border-b border-gray-100 px-5 py-3">
                    <h2 class="font-medium text-gray-900">Добавить группу</h2>
                    <button type="button" class="ml-auto rounded p-1 text-gray-400 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        x-on:click="createOpen = false" aria-label="Закрыть форму добавления">
                        <svg aria-hidden="true" class="h-4 w-4 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6" /></svg>
                    </button>
                </div>
                <form method="POST" action="{{ route('admin.roles.store') }}" class="space-y-4 px-5 py-5">
                    @csrf
                    <input type="hidden" name="_section" value="create">
                    <div>
                        <label for="create-display-name" class="mb-1 block text-sm font-medium text-gray-700">Название группы</label>
                        <input id="create-display-name" name="display_name" value="{{ old('display_name') }}" required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        @error('display_name', 'createRole')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="create-description" class="mb-1 block text-sm font-medium text-gray-700">Описание</label>
                        <textarea id="create-description" name="description" rows="3"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">{{ old('description') }}</textarea>
                        @error('description', 'createRole')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-100 pt-4">
                        <button type="button" x-on:click="createOpen = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">Отмена</button>
                        <button class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">Создать группу</button>
                    </div>
                </form>
            </section>
        @endcan

        <div class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            @forelse ($roles as $role)
                @php
                    $bag = 'updateRole-'.$role->id;
                    $roleOpen = $errors->getBag($bag)->any() || old('_section') === 'role-'.$role->id || (string) session('openRole') === (string) $role->id;
                    $useOld = old('_section') === 'role-'.$role->id;
                @endphp
                <section x-data="{ open: @js($roleOpen) }" data-role-accordion="{{ $role->id }}" data-initial-open="{{ $roleOpen ? 'true' : 'false' }}">
                    <button type="button" aria-controls="role-{{ $role->id }}-content" x-bind:aria-expanded="open.toString()" x-on:click="open = !open"
                        class="flex w-full flex-wrap items-center gap-2 px-5 py-4 text-left transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500">
                        <x-admin.users.role-badge :role="$role" />
                        <span class="ml-auto text-xs text-gray-500 sm:text-sm">Пользователей: {{ $role->users_count }}</span>
                        <span class="text-xs text-gray-500 sm:text-sm">Прав: {{ $role->permissions_count }}</span>
                        <svg aria-hidden="true" class="h-4 w-4 shrink-0 text-gray-400 transition-transform" x-bind:class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6" /></svg>
                    </button>

                    <div id="role-{{ $role->id }}-content" x-show="open" x-cloak class="border-t border-gray-100 px-5 py-5">
                        <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(16rem,1fr)]">
                            <div>
                                <h3 class="mb-3 text-sm font-semibold text-gray-900">Основные данные</h3>
                                @can('roles.update')
                                    <form method="POST" action="{{ route('admin.roles.update', $role) }}" class="space-y-4">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="_section" value="role-{{ $role->id }}">
                                        <div>
                                            <label for="display-name-{{ $role->id }}" class="mb-1 block text-sm font-medium text-gray-700">Название группы</label>
                                            <input id="display-name-{{ $role->id }}" name="display_name" value="{{ $useOld ? old('display_name') : $role->display_name }}" required class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                                            @error('display_name', $bag)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                        </div>
                                        <div>
                                            <label for="description-{{ $role->id }}" class="mb-1 block text-sm font-medium text-gray-700">Описание</label>
                                            <textarea id="description-{{ $role->id }}" name="description" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">{{ $useOld ? old('description') : $role->description }}</textarea>
                                            @error('description', $bag)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                        </div>
                                        <button class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">Сохранить изменения</button>
                                    </form>
                                @else
                                    <dl class="space-y-3 text-sm">
                                        <div><dt class="text-gray-500">Название</dt><dd class="font-medium text-gray-900">{{ $role->display_name }}</dd></div>
                                        <div><dt class="text-gray-500">Описание</dt><dd class="text-gray-700">{{ $role->description ?: 'Не указано' }}</dd></div>
                                    </dl>
                                @endcan
                            </div>

                            <div class="space-y-5 lg:border-l lg:border-gray-100 lg:pl-6">
                                <div>
                                    <h3 class="mb-2 text-sm font-semibold text-gray-900">Использование</h3>
                                    <dl class="space-y-1 text-sm text-gray-600">
                                        <div class="flex justify-between gap-4"><dt>Пользователей</dt><dd class="font-medium text-gray-900">{{ $role->users_count }}</dd></div>
                                        <div class="flex justify-between gap-4"><dt>Назначенных прав</dt><dd class="font-medium text-gray-900">{{ $role->permissions_count }}</dd></div>
                                        <div class="flex justify-between gap-4"><dt>Тип</dt><dd class="font-medium text-gray-900">{{ $role->is_system ? 'Системная группа' : 'Пользовательская группа' }}</dd></div>
                                    </dl>
                                    <p class="mt-3 text-xs text-gray-500">Настройка прав доступа будет доступна в отдельном разделе.</p>
                                </div>

                                <div class="border-t border-gray-100 pt-4">
                                    @if ($role->is_system)
                                        <p class="text-sm text-gray-500">Системную группу нельзя удалить.</p>
                                    @elseif ($role->users_count > 0)
                                        <p class="text-sm text-gray-500">Группа назначена пользователям и не может быть удалена.</p>
                                    @else
                                        @can('roles.delete')
                                            <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" onsubmit="return confirm('Группа будет удалена. Продолжить?')">
                                                @csrf
                                                @method('DELETE')
                                                <button class="rounded-lg border border-red-300 px-4 py-2.5 text-sm font-medium text-red-700 hover:bg-red-50">Удалить группу</button>
                                            </form>
                                        @endcan
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            @empty
                <p class="px-5 py-10 text-center text-sm text-gray-500">Группы не найдены.</p>
            @endforelse
        </div>
    </div>
@endsection
