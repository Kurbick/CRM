@extends('layouts.app')

@section('title', $user->name)

@section('content')
    @php
        $isSelf = auth()->id() === $user->id;
        $displayDateTime = app(\App\Support\DisplayDateTime::class);
        $currentRole = $user->roles->first();
        $roleLabel = fn ($role) => match ($role?->name) {
            \App\Support\Access\SystemRole::Administrator->value => __('admin.access.system_roles.administrator'),
            \App\Support\Access\SystemRole::Accountant->value => __('admin.access.system_roles.accountant'),
            \App\Support\Access\SystemRole::Viewer->value => __('admin.access.system_roles.viewer'),
            default => $role?->display_name,
        };
        $openUser = count($errors->getBag('updateUser')->all()) > 0 || old('_section') === 'user';
        $openRole = count($errors->getBag('updateRole')->all()) > 0 || old('_section') === 'role';
        $openPassword = count($errors->getBag('resetPassword')->all()) > 0 || old('_section') === 'password';
    @endphp

    <div class="mx-auto max-w-4xl" x-data="{ userOpen: @js($openUser), roleOpen: @js($openRole), passwordOpen: @js($openPassword) }">
        <div class="mb-8 border-b border-gray-200 pb-6">
            <a href="{{ route('admin.users.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-900">{{ __('admin.users.back') }}</a>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-bold text-gray-900">{{ $user->name }}</h1>
                        <x-admin.users.status-badge :active="$user->is_active" />
                    </div>
                    <p class="mt-1 text-sm text-gray-500">{{ $user->email }}</p>
                </div>
                <x-admin.users.role-badge :role="$currentRole" />
            </div>
        </div>

        <div class="space-y-8">
            <section data-user-details>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.users.sections.general') }}</h2>
                    @can('users.update')
                        <button type="button" class="text-sm font-medium text-blue-700 hover:text-blue-800" x-on:click="userOpen = !userOpen" x-bind:aria-expanded="userOpen.toString()">{{ __('admin.users.edit_title') }}</button>
                    @endcan
                </div>
                <dl class="grid gap-x-8 gap-y-4 border-y border-gray-200 py-5 text-sm sm:grid-cols-2" data-user-detail-grid>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.users.fields.name') }}</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ $user->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.users.fields.email') }}</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ $user->email }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.users.fields.group') }}</dt>
                        <dd class="mt-1"><x-admin.users.role-badge :role="$currentRole" /></dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.users.fields.last_login') }}</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ $displayDateTime->format($user->last_login_at, 'd.m.Y H:i') ?? __('admin.users.fields.never_logged_in') }}</dd>
                    </div>
                </dl>

                @can('users.update')
                    <div x-show="userOpen" x-cloak class="border-b border-gray-200 py-6">
                        @include('admin.users._form', [
                            'mode' => 'edit',
                            'action' => route('admin.users.update', $user),
                            'cancelUrl' => route('admin.users.edit', $user),
                            'user' => $user,
                            'roles' => $roles,
                        ])
                    </div>
                @endcan
            </section>

            <section data-user-role>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.users.fields.group') }}</h2>
                    @can('users.assign_role')
                        @unless ($isSelf)
                            <button type="button" class="text-sm font-medium text-blue-700 hover:text-blue-800" x-on:click="roleOpen = !roleOpen" x-bind:aria-expanded="roleOpen.toString()">{{ __('admin.users.actions.change') }}</button>
                        @endunless
                    @endcan
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-y border-gray-200 py-5 text-sm">
                    <div>
                        <p class="text-gray-500">{{ __('admin.users.current_group') }}</p>
                        <div class="mt-1"><x-admin.users.role-badge :role="$currentRole" /></div>
                    </div>
                    @can('users.assign_role')
                        @if ($isSelf)
                            <p data-user-self-restriction class="text-sm text-gray-500">{{ __('admin.users.messages.self_group') }}</p>
                        @endif
                    @endcan
                </div>
                @can('users.assign_role')
                    @unless ($isSelf)
                        <div x-show="roleOpen" x-cloak class="border-b border-gray-200 py-6">
                            <form method="POST" action="{{ route('admin.users.role.update', $user) }}" class="max-w-xl space-y-4" data-user-role-form>
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="_section" value="role">
                                <div>
                                    <label for="role_id" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('admin.users.fields.new_group') }}</label>
                                    <select id="role_id" name="role_id" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                        @foreach ($roles as $role)
                                            <option value="{{ $role->id }}" @selected(old('role_id', $currentRole?->id) == $role->id)>{{ $roleLabel($role) }}</option>
                                        @endforeach
                                    </select>
                                    @error('role_id', 'updateRole')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </div>
                                <div class="flex flex-wrap justify-end gap-3">
                                    <button type="button" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50" x-on:click="roleOpen = false">{{ __('admin.users.actions.cancel') }}</button>
                                    <button class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">{{ __('admin.users.actions.update_group') }}</button>
                                </div>
                            </form>
                        </div>
                    @endunless
                @endcan
            </section>

            <section data-user-status>
                <div class="mb-4">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.users.sections.account') }}</h2>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-4 border-y border-gray-200 py-5 text-sm">
                    <div>
                        <p class="text-gray-500">{{ __('admin.users.account_status') }}</p>
                        <div class="mt-1"><x-admin.users.status-badge :active="$user->is_active" /></div>
                    </div>
                    @if ($isSelf && $user->is_active)
                            <p data-user-self-restriction class="text-sm text-gray-500">{{ __('admin.users.messages.self_deactivate') }}</p>
                    @elseif ($user->is_active)
                        @can('users.deactivate')
                            <form method="POST" action="{{ route('admin.users.deactivate', $user) }}" onsubmit="return confirm(@js(__('admin.users.messages.deactivate_confirm')))" data-user-status-form>
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="_section" value="status">
                                <button class="rounded-lg border border-red-300 px-4 py-2.5 text-sm font-medium text-red-700 hover:bg-red-50">{{ __('admin.users.actions.deactivate') }}</button>
                            </form>
                        @endcan
                    @else
                        @can('users.activate')
                            <form method="POST" action="{{ route('admin.users.activate', $user) }}" data-user-status-form>
                                @csrf
                                @method('PATCH')
                                <button class="rounded-lg border border-green-300 px-4 py-2.5 text-sm font-medium text-green-700 hover:bg-green-50">{{ __('admin.users.actions.activate') }}</button>
                            </form>
                        @endcan
                    @endif
                </div>
                @error('status')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
            </section>

            <section data-user-security>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.users.sections.security') }}</h2>
                    @can('users.reset_password')
                        @unless ($isSelf)
                            <button type="button" class="text-sm font-medium text-blue-700 hover:text-blue-800" x-on:click="passwordOpen = !passwordOpen" x-bind:aria-expanded="passwordOpen.toString()">{{ __('admin.users.password.reset') }}</button>
                        @endunless
                    @endcan
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-y border-gray-200 py-5 text-sm">
                    <div>
                        <p class="text-gray-500">{{ __('admin.users.password.temporary') }}</p>
                        @if ($user->must_change_password)
                            <span class="mt-1 inline-flex items-center rounded-full bg-yellow-100 px-2.5 py-0.5 text-xs font-medium text-yellow-700">{{ __('admin.users.password.required_change') }}</span>
                        @else
                            <p class="mt-1 font-medium text-gray-900">{{ __('admin.users.password.not_required') }}</p>
                        @endif
                    </div>
                    @can('users.reset_password')
                        @if ($isSelf)
                            <p data-user-self-restriction class="text-sm text-gray-500">{{ __('admin.users.password.self_help') }} <a class="font-medium underline" href="{{ route('password.change') }}">{{ __('admin.users.password.change_link') }}</a> {{ __('admin.users.password.settings_menu') }}</p>
                        @endif
                    @endcan
                </div>
                @can('users.reset_password')
                    @unless ($isSelf)
                        <div x-show="passwordOpen" x-cloak class="border-b border-gray-200 py-6">
                            <form method="POST" action="{{ route('admin.users.password.update', $user) }}" class="max-w-xl space-y-4" data-user-password-form>
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="_section" value="password">
                                <x-forms.password-input name="password" :label="__('admin.users.password.new_temporary')" autocomplete="new-password" errorBag="resetPassword" required />
                                <x-forms.password-input name="password_confirmation" :label="__('admin.users.password.temporary_confirmation')" autocomplete="new-password" errorBag="resetPassword" required />
                                <p class="text-xs text-gray-500">{{ __('admin.users.password.requirements') }}</p>
                                <div class="flex flex-wrap justify-end gap-3">
                                    <button type="button" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50" x-on:click="passwordOpen = false">{{ __('admin.users.actions.cancel') }}</button>
                                    <button class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">{{ __('admin.users.password.set_temporary') }}</button>
                                </div>
                            </form>
                        </div>
                    @endunless
                @endcan
            </section>
        </div>
    </div>
@endsection
