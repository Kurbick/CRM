@extends('layouts.app')

@section('title', 'Доступ')

@section('content')
    @php
        $editable = auth()->user()->can('access_permissions.update') && ! $immutable;
        $canCreateRoles = auth()->user()->can('roles.create');
        $canUpdateRoles = auth()->user()->can('roles.update');
        $canDeleteRoles = auth()->user()->can('roles.delete');
        $createOpen = $errors->getBag('createRole')->any() || old('_section') === 'create';
        $renameBag = 'updateRole-'.$selectedRole->id;
        $renameOpen = $errors->getBag($renameBag)->any() || old('_section') === 'role-'.$selectedRole->id;
        $selectedValues = old('permissions', $managedAssigned);
        $selectedValues = is_array($selectedValues) ? array_values($selectedValues) : $managedAssigned;
    @endphp

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900">Доступ</h1>
        <p class="mt-1 text-sm text-slate-500">Управление группами пользователей и их правами.</p>
    </div>

    @if ($unknownPermissions->isNotEmpty())
        <div data-unknown-permissions class="mb-5 border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p>У группы есть права, отсутствующие в текущем каталоге. Они будут сохранены без изменений.</p>
            <ul class="mt-2 list-inside list-disc font-mono text-xs text-amber-700">
                @foreach ($unknownPermissions as $permission)
                    <li>{{ $permission->name }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($errors->any() && ! $errors->getBag('createRole')->any() && ! $errors->getBag($renameBag)->any())
        <div class="mb-5 border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="overflow-hidden border border-slate-200 bg-white lg:grid lg:h-[calc(100vh-10rem)] lg:grid-cols-[minmax(14.5rem,17rem)_minmax(0,1fr)]">
        <aside class="min-h-0 border-b border-slate-200 lg:overflow-y-auto lg:border-b-0 lg:border-r" aria-label="Группы пользователей"
            x-data="{ createOpen: @js($createOpen) }">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Группы</h2>
                <span class="text-xs text-slate-400">{{ $roles->count() }}</span>
            </div>

            <div class="divide-y divide-slate-100">
                @foreach ($roles as $role)
                    @php
                        $isSelected = $selectedRole->is($role);
                        $metadata = $role->is_system
                            ? 'Системная · '.$role->users_count.' '.trans_choice('пользователь|пользователя|пользователей', $role->users_count)
                            : $role->users_count.' '.trans_choice('пользователь|пользователя|пользователей', $role->users_count);
                    @endphp
                    <a href="{{ route('admin.access-permissions.index', ['role' => $role->id]) }}"
                        class="block px-4 py-3 transition {{ $isSelected ? 'bg-slate-100' : 'hover:bg-slate-50' }}"
                        @if ($isSelected) aria-current="page" @endif>
                        <span class="block truncate text-sm font-medium {{ $isSelected ? 'text-slate-950' : 'text-slate-700' }}">{{ $role->display_name }}</span>
                        <span class="mt-0.5 block text-xs text-slate-500">{{ $metadata }}</span>
                    </a>
                @endforeach
            </div>

            @if ($canCreateRoles)
                <div class="border-t border-slate-200 p-3">
                    <button type="button" x-show="! createOpen" x-on:click="createOpen = true"
                        class="inline-flex items-center text-sm font-medium text-blue-700 transition hover:text-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        + Создать группу
                    </button>

                    <form method="POST" action="{{ route('admin.roles.store') }}" x-show="createOpen" x-cloak class="space-y-3">
                        @csrf
                        <input type="hidden" name="_section" value="create">
                        <div>
                            <label for="create-display-name" class="mb-1 block text-xs font-medium text-slate-600">Название группы</label>
                            <input id="create-display-name" name="display_name" value="{{ old('display_name') }}" required
                                class="w-full border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            @error('display_name', 'createRole')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="flex items-center justify-end gap-3">
                            <button type="button" x-on:click="createOpen = false" class="text-sm text-slate-600 hover:text-slate-900">Отмена</button>
                            <button class="bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">Создать</button>
                        </div>
                    </form>
                </div>
            @endif
        </aside>

        <section class="flex min-h-0 min-w-0 flex-col">
            <div class="shrink-0 flex flex-wrap items-start gap-3 border-b border-slate-200 px-5 py-4 sm:px-6">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="truncate text-lg font-semibold text-slate-900">{{ $selectedRole->display_name }}</h2>
                        @if ($selectedRole->is_system)
                            <span class="crm-badge crm-badge-neutral">Системная</span>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ $selectedRole->users_count }} {{ trans_choice('пользователь|пользователя|пользователей', $selectedRole->users_count) }}
                        <span class="px-1 text-slate-300">·</span>
                        {{ count($managedAssigned) }} из {{ count(\App\Support\Access\PermissionRegistry::names()) }} прав
                    </p>
                </div>

                @if ($canManageRoles && ! $selectedRole->is_system && ($canUpdateRoles || $canDeleteRoles))
                    <div class="relative ml-auto" x-data="{ menuOpen: false, renameOpen: @js($renameOpen) }" x-on:click.outside="menuOpen = false">
                        <button type="button" x-on:click="menuOpen = ! menuOpen" x-bind:aria-expanded="menuOpen.toString()"
                            class="rounded p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            aria-label="Действия с группой">
                            <svg aria-hidden="true" class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm0 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm0 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z" /></svg>
                        </button>
                        <div x-show="menuOpen" x-cloak class="absolute right-0 z-10 mt-1 w-44 border border-slate-200 bg-white py-1 shadow-lg">
                            @if ($canUpdateRoles)
                                <button type="button" x-on:click="renameOpen = true; menuOpen = false" class="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">Переименовать</button>
                            @endif
                            @if ($canDeleteRoles && $selectedRole->users_count === 0)
                                <form method="POST" action="{{ route('admin.roles.destroy', $selectedRole) }}" onsubmit="return confirm('Группа будет удалена. Продолжить?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="block w-full px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50">Удалить группу</button>
                                </form>
                            @endif
                        </div>

                        @if ($canUpdateRoles)
                            <form method="POST" action="{{ route('admin.roles.update', $selectedRole) }}" x-show="renameOpen" x-cloak
                                class="absolute right-0 z-10 mt-1 w-72 border border-slate-200 bg-white p-4 shadow-lg">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="_section" value="role-{{ $selectedRole->id }}">
                                <input type="hidden" name="description" value="{{ $selectedRole->description }}">
                                <label for="rename-display-name" class="mb-1 block text-xs font-medium text-slate-600">Название группы</label>
                                <input id="rename-display-name" name="display_name" value="{{ $renameOpen ? old('display_name', $selectedRole->display_name) : $selectedRole->display_name }}" required
                                    class="w-full border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                                @error('display_name', $renameBag)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                <div class="mt-3 flex justify-end gap-3">
                                    <button type="button" x-on:click="renameOpen = false" class="text-sm text-slate-600 hover:text-slate-900">Отмена</button>
                                    <button class="bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">Сохранить</button>
                                </div>
                            </form>
                        @endif
                    </div>
                @endif
            </div>

            @if (! $selectedRole->is_system && $selectedRole->users_count > 0 && $canDeleteRoles)
                <p class="border-b border-slate-100 px-5 py-2 text-xs text-slate-500 sm:px-6">Группа назначена пользователям и не может быть удалена.</p>
            @endif

            @if ($editable)
                <form method="POST" action="{{ route('admin.access-permissions.update', $selectedRole) }}" x-data="{ selected: @js($selectedValues) }"
                    class="flex min-h-0 flex-1 flex-col">
                    @csrf
                    @method('PUT')
            @else
                <div x-data="{ selected: @js($selectedValues) }" class="flex min-h-0 flex-1 flex-col">
            @endif

                <div data-permission-scroll-area class="min-h-0 flex-1 px-5 py-5 lg:overflow-y-auto sm:px-6">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Права доступа</h3>
                        @if (! $editable && ! $immutable)
                            <span class="text-xs text-slate-500">Только просмотр</span>
                        @endif
                    </div>

                    <div data-permission-workspace class="divide-y divide-slate-200 border-y border-slate-200">
                        @foreach ($categories as $category)
                            @php
                                $categorySlugs = array_map(fn (array $permission) => $permission['name']->value, $category['permissions']);
                                $categorySelected = count(array_intersect($categorySlugs, $selectedValues));
                            @endphp
                            <section x-data="{ slugs: @js($categorySlugs) }" data-permission-category="{{ $category['module'] }}" class="py-4">
                                <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2">
                                    <h4 class="text-sm font-semibold text-slate-900">{{ $category['label'] }}</h4>
                                    <span class="text-xs text-slate-500" x-text="selected.filter(permission => slugs.includes(permission)).length + ' из ' + slugs.length">{{ $categorySelected }} из {{ count($categorySlugs) }}</span>
                                    @if ($editable)
                                        <label class="ml-auto inline-flex items-center gap-2 text-xs text-slate-600">
                                            <input type="checkbox" data-category-select-all
                                                x-bind:checked="slugs.every(permission => selected.includes(permission))"
                                                x-effect="$el.indeterminate = slugs.some(permission => selected.includes(permission)) && ! slugs.every(permission => selected.includes(permission))"
                                                x-on:change="selected = slugs.every(permission => selected.includes(permission)) ? selected.filter(permission => ! slugs.includes(permission)) : [...new Set([...selected, ...slugs])]"
                                                class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                            Выбрать всё
                                        </label>
                                    @endif
                                </div>
                                <div class="grid gap-x-6 gap-y-2 sm:grid-cols-2 xl:grid-cols-3">
                                    @foreach ($category['permissions'] as $permission)
                                        <label class="flex min-h-8 items-center gap-3 py-1 text-sm text-slate-700 {{ $editable ? 'cursor-pointer hover:text-slate-950' : 'cursor-default' }}">
                                            <input type="checkbox" name="permissions[]" value="{{ $permission['name']->value }}"
                                                x-model="selected" @checked(in_array($permission['name']->value, $selectedValues, true)) @disabled(! $editable)
                                                class="h-4 w-4 shrink-0 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                            <span>{{ $permission['label'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>

                @if ($editable)
                    <div data-permission-actions class="flex shrink-0 justify-end border-t border-slate-200 bg-white px-5 py-3 sm:px-6">
                        <button class="bg-blue-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">Сохранить</button>
                    </div>
                </form>
                @else
                </div>
                @endif
        </section>
    </div>
@endsection
