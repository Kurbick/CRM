@extends('layouts.app')

@section('title', __('admin.access.groups'))

@section('content')
    @php
        $createOpen = $errors->getBag('createRole')->any() || old('_section') === 'create';
        $systemRoleDescription = fn ($role) => match ($role->name) {
            \App\Support\Access\SystemRole::Administrator->value => __('admin.access.system_roles.administrator_description'),
            \App\Support\Access\SystemRole::Accountant->value => __('admin.access.system_roles.accountant_description'),
            \App\Support\Access\SystemRole::Viewer->value => __('admin.access.system_roles.viewer_description'),
            default => $role->description,
        };
    @endphp

    <div x-data="{ createOpen: @js($createOpen) }">
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.access.groups') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ __('admin.access.roles_description') }}</p>
            </div>
            @can('roles.create')
                <button type="button" aria-controls="create-role-content" x-bind:aria-expanded="createOpen.toString()"
                    x-on:click="createOpen = !createOpen"
                    class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    + {{ __('admin.access.add_group') }}
                    <svg aria-hidden="true" class="ml-2 h-4 w-4 transition-transform" x-bind:class="{ 'rotate-180': createOpen }" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6" /></svg>
                </button>
            @endcan
        </div>

        @can('roles.create')
            <section data-create-role-accordion data-initial-open="{{ $createOpen ? 'true' : 'false' }}"
                id="create-role-content" x-show="createOpen" x-cloak
                class="mb-5 overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="flex items-center border-b border-gray-100 px-5 py-3">
                    <h2 class="font-medium text-gray-900">{{ __('admin.access.add_group') }}</h2>
                    <button type="button" class="ml-auto rounded p-1 text-gray-400 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        x-on:click="createOpen = false" aria-label="{{ __('admin.access.close_create') }}">
                        <svg aria-hidden="true" class="h-4 w-4 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6" /></svg>
                    </button>
                </div>
                <form method="POST" action="{{ route('admin.roles.store') }}" class="space-y-4 px-5 py-5">
                    @csrf
                    <input type="hidden" name="_section" value="create">
                    <div>
                        <label for="create-display-name" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.access.fields.group_name') }}</label>
                        <input id="create-display-name" name="display_name" value="{{ old('display_name') }}" required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        @error('display_name', 'createRole')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="create-description" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.access.fields.description') }}</label>
                        <textarea id="create-description" name="description" rows="3"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">{{ old('description') }}</textarea>
                        @error('description', 'createRole')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-100 pt-4">
                        <button type="button" x-on:click="createOpen = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">{{ __('admin.access.actions.cancel') }}</button>
                        <button class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">{{ __('admin.access.actions.create_group') }}</button>
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
                        <span class="ml-auto text-xs text-gray-500 sm:text-sm">{{ __('admin.access.counts.users') }} {{ $role->users_count }}</span>
                        <span class="text-xs text-gray-500 sm:text-sm">{{ __('admin.access.counts.permissions') }} {{ $role->permissions_count }}</span>
                        <svg aria-hidden="true" class="h-4 w-4 shrink-0 text-gray-400 transition-transform" x-bind:class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6" /></svg>
                    </button>

                    <div id="role-{{ $role->id }}-content" x-show="open" x-cloak class="border-t border-gray-100 px-5 py-5">
                        <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(16rem,1fr)]">
                            <div>
                                <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('admin.access.basic_data') }}</h3>
                                @can('roles.update')
                                    <form method="POST" action="{{ route('admin.roles.update', $role) }}" class="space-y-4">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="_section" value="role-{{ $role->id }}">
                                        <div>
                                        <label for="display-name-{{ $role->id }}" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.access.fields.group_name') }}</label>
                                            <input id="display-name-{{ $role->id }}" name="display_name" value="{{ $useOld ? old('display_name') : $role->display_name }}" required class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                                            @error('display_name', $bag)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                        </div>
                                        <div>
                                        <label for="description-{{ $role->id }}" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.access.fields.description') }}</label>
                                            <textarea id="description-{{ $role->id }}" name="description" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">{{ $useOld ? old('description') : $role->description }}</textarea>
                                            @error('description', $bag)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                        </div>
                                        <button class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">{{ __('admin.access.actions.save_changes') }}</button>
                                    </form>
                                @else
                                    <dl class="space-y-3 text-sm">
                                        <div><dt class="text-gray-500">{{ __('admin.access.fields.name') }}</dt><dd class="font-medium text-gray-900">{{ $role->display_name }}</dd></div>
                                        <div><dt class="text-gray-500">{{ __('admin.access.fields.description') }}</dt><dd class="text-gray-700">{{ $systemRoleDescription($role) ?: __('admin.access.not_specified') }}</dd></div>
                                    </dl>
                                @endcan
                            </div>

                            <div class="space-y-5 lg:border-l lg:border-gray-100 lg:pl-6">
                                <div>
                                    <h3 class="mb-2 text-sm font-semibold text-gray-900">{{ __('admin.access.counts.usage') }}</h3>
                                    <dl class="space-y-1 text-sm text-gray-600">
                                        <div class="flex justify-between gap-4"><dt>{{ __('admin.access.counts.users') }}</dt><dd class="font-medium text-gray-900">{{ $role->users_count }}</dd></div>
                                        <div class="flex justify-between gap-4"><dt>{{ __('admin.access.counts.assigned_permissions') }}</dt><dd class="font-medium text-gray-900">{{ $role->permissions_count }}</dd></div>
                                        <div class="flex justify-between gap-4"><dt>{{ __('admin.access.counts.type') }}</dt><dd class="font-medium text-gray-900">{{ $role->is_system ? __('admin.access.statuses.system_group') : __('admin.access.statuses.custom_group') }}</dd></div>
                                    </dl>
                                    <p class="mt-3 text-xs text-gray-500">{{ __('admin.access.messages.registry_help') }}</p>
                                </div>

                                <div class="border-t border-gray-100 pt-4">
                                    @if ($role->is_system)
                                        <p class="text-sm text-gray-500">{{ __('admin.access.messages.system_delete_warning') }}</p>
                                    @elseif ($role->users_count > 0)
                                        <p class="text-sm text-gray-500">{{ __('admin.access.messages.assigned_warning') }}</p>
                                    @else
                                        @can('roles.delete')
                                            <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" onsubmit="return confirm(@js(__('admin.access.messages.delete_confirm')))" >
                                                @csrf
                                                @method('DELETE')
                                                <button class="rounded-lg border border-red-300 px-4 py-2.5 text-sm font-medium text-red-700 hover:bg-red-50">{{ __('admin.access.actions.delete_group') }}</button>
                                            </form>
                                        @endcan
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            @empty
                <p class="px-5 py-10 text-center text-sm text-gray-500">{{ __('admin.access.empty') }}</p>
            @endforelse
        </div>
    </div>
@endsection
