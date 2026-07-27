@extends('layouts.app')

@section('title', 'Права доступа')

@section('content')
    @php
        $editable = auth()->user()->can('access_permissions.update') && ! $immutable;
        $selectedValues = old('permissions', $managedAssigned);
        $selectedValues = is_array($selectedValues) ? array_values($selectedValues) : $managedAssigned;
    @endphp

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Права доступа</h1>
        <p class="mt-1 text-sm text-gray-500">Настройка разрешений для групп пользователей CRM.</p>
    </div>

    <form method="GET" action="{{ route('admin.access-permissions.index') }}" x-data class="mb-5 flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:items-end">
        <div class="min-w-0 flex-1">
            <label for="role" class="mb-1 block text-sm font-medium text-gray-700">Группа</label>
            <select id="role" name="role" x-on:change="$el.form.submit()" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                @foreach ($roles as $role)
                    <option value="{{ $role->id }}" @selected($selectedRole->is($role))>{{ $role->display_name }}</option>
                @endforeach
            </select>
        </div>
        <button class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Показать</button>
    </form>

    <div class="mb-5 flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3">
        <x-admin.users.role-badge :role="$selectedRole" />
        <span class="text-sm text-gray-500">Пользователей: {{ $selectedRole->users_count }}</span>
        <span class="text-sm text-gray-500">Назначенных прав: {{ count($managedAssigned) }} из {{ count(\App\Support\Access\PermissionRegistry::names()) }}</span>
    </div>

    @if ($immutable)
        <div class="mb-5 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
            <span class="mr-2 inline-flex rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-700">Полный доступ</span>
            Группа „Администратор“ всегда имеет полный доступ. Её права управляются системой и не могут быть изменены.
        </div>
    @endif

    @if ($unknownPermissions->isNotEmpty())
        <div data-unknown-permissions class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p>У группы есть права, отсутствующие в текущем каталоге. Они будут сохранены без изменений.</p>
            <ul class="mt-2 list-inside list-disc font-mono text-xs text-amber-700">
                @foreach ($unknownPermissions as $permission)
                    <li>{{ $permission->name }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ $errors->first() }}
        </div>
    @endif

    @if ($editable)
        <form method="POST" action="{{ route('admin.access-permissions.update', $selectedRole) }}" x-data="{ selected: @js($selectedValues) }">
            @csrf
            @method('PUT')
    @else
        <div x-data="{ selected: @js($selectedValues) }">
    @endif

        <div data-permission-matrix class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            @foreach ($categories as $category)
                @php
                    $categorySlugs = array_map(fn (array $permission) => $permission['name']->value, $category['permissions']);
                    $categorySelected = count(array_intersect($categorySlugs, $selectedValues));
                    $contentId = 'permission-category-'.$category['module'];
                @endphp
                <section x-data="{ open: false, slugs: @js($categorySlugs) }" data-permission-category="{{ $category['module'] }}">
                    <div class="flex items-center gap-2">
                        <button type="button" aria-controls="{{ $contentId }}" x-bind:aria-expanded="open.toString()" x-on:click="open = !open"
                            class="flex min-w-0 flex-1 items-center gap-3 px-5 py-4 text-left transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500">
                            <span class="truncate font-medium text-gray-900">{{ $category['label'] }}</span>
                            <span class="ml-auto whitespace-nowrap text-sm text-gray-500" x-text="selected.filter(permission => slugs.includes(permission)).length + ' из ' + slugs.length">{{ $categorySelected }} из {{ count($categorySlugs) }}</span>
                            <svg aria-hidden="true" class="h-4 w-4 shrink-0 text-gray-400 transition-transform" x-bind:class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6" /></svg>
                        </button>
                        @if ($editable)
                            <label class="mr-4 inline-flex shrink-0 items-center gap-2 text-xs text-gray-600">
                                <input type="checkbox" data-category-select-all
                                    x-bind:checked="slugs.every(permission => selected.includes(permission))"
                                    x-effect="$el.indeterminate = selected.some(permission => slugs.includes(permission)) && ! slugs.every(permission => selected.includes(permission))"
                                    x-on:change="selected = slugs.every(permission => selected.includes(permission)) ? selected.filter(permission => ! slugs.includes(permission)) : [...new Set([...selected, ...slugs])]"
                                    class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                Выбрать всё
                            </label>
                        @endif
                    </div>
                    <div id="{{ $contentId }}" x-show="open" x-cloak class="border-t border-gray-100 px-5 py-4">
                        <div class="grid gap-3 md:grid-cols-2">
                            @foreach ($category['permissions'] as $permission)
                                <label class="flex items-start gap-3 rounded-lg px-2 py-2 hover:bg-gray-50">
                                    <input type="checkbox" name="permissions[]" value="{{ $permission['name']->value }}"
                                        x-model="selected" @checked(in_array($permission['name']->value, $selectedValues, true)) @disabled(! $editable)
                                        class="mt-0.5 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span class="text-sm text-gray-700">{{ $permission['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endforeach
        </div>

        @if ($editable)
            <div class="mt-5 flex justify-end">
                <button class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">Сохранить права</button>
            </div>
        </form>
        @else
        </div>
        @endif
@endsection
