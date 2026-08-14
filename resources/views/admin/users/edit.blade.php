@extends('layouts.app')

@section('title', $user->name)

@section('content')
    @php
        $isSelf = auth()->id() === $user->id;
        $currentRole = $user->roles->first();
        $openUser = count($errors->getBag('updateUser')->all()) > 0 || old('_section') === 'user';
        $openRole = count($errors->getBag('updateRole')->all()) > 0 || old('_section') === 'role';
        $openPassword = count($errors->getBag('resetPassword')->all()) > 0 || old('_section') === 'password';
    @endphp

    <div class="mx-auto max-w-4xl" x-data="{ userOpen: @js($openUser), roleOpen: @js($openRole), passwordOpen: @js($openPassword) }">
        <div class="mb-8 border-b border-gray-200 pb-6">
            <a href="{{ route('admin.users.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-900">← Назад к пользователям</a>
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
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Основная информация</h2>
                    @can('users.update')
                        <button type="button" class="text-sm font-medium text-blue-700 hover:text-blue-800" x-on:click="userOpen = !userOpen" x-bind:aria-expanded="userOpen.toString()">Изменить данные</button>
                    @endcan
                </div>
                <dl class="grid gap-x-8 gap-y-4 border-y border-gray-200 py-5 text-sm sm:grid-cols-2" data-user-detail-grid>
                    <div>
                        <dt class="text-gray-500">Имя</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ $user->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Email</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ $user->email }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Группа</dt>
                        <dd class="mt-1"><x-admin.users.role-badge :role="$currentRole" /></dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Последний вход</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ $user->last_login_at?->copy()->setTimezone('Asia/Baku')->translatedFormat('d.m.Y H:i') ?? 'Не входил' }}</dd>
                    </div>
                </dl>

                @can('users.update')
                    <div x-show="userOpen" x-cloak class="border-b border-gray-200 py-6">
                        @include('admin.users._form', [
                            'mode' => 'edit',
                            'action' => route('admin.users.update', $user),
                            'user' => $user,
                            'roles' => $roles,
                        ])
                    </div>
                @endcan
            </section>

            <section data-user-role>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Группа</h2>
                    @can('users.assign_role')
                        @unless ($isSelf)
                            <button type="button" class="text-sm font-medium text-blue-700 hover:text-blue-800" x-on:click="roleOpen = !roleOpen" x-bind:aria-expanded="roleOpen.toString()">Изменить</button>
                        @endunless
                    @endcan
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-y border-gray-200 py-5 text-sm">
                    <div>
                        <p class="text-gray-500">Текущая группа</p>
                        <div class="mt-1"><x-admin.users.role-badge :role="$currentRole" /></div>
                    </div>
                    @can('users.assign_role')
                        @if ($isSelf)
                            <p data-user-self-restriction class="text-sm text-gray-500">Изменить собственную группу может другой администратор.</p>
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
                                    <label for="role_id" class="mb-1.5 block text-sm font-medium text-gray-700">Новая группа</label>
                                    <select id="role_id" name="role_id" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                        @foreach ($roles as $role)
                                            <option value="{{ $role->id }}" @selected(old('role_id', $currentRole?->id) == $role->id)>{{ $role->display_name }}</option>
                                        @endforeach
                                    </select>
                                    @error('role_id', 'updateRole')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </div>
                                <div class="flex flex-wrap justify-end gap-3">
                                    <button type="button" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50" x-on:click="roleOpen = false">Отмена</button>
                                    <button class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">Обновить группу</button>
                                </div>
                            </form>
                        </div>
                    @endunless
                @endcan
            </section>

            <section data-user-status>
                <div class="mb-4">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Учётная запись</h2>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-4 border-y border-gray-200 py-5 text-sm">
                    <div>
                        <p class="text-gray-500">Статус учётной записи</p>
                        <div class="mt-1"><x-admin.users.status-badge :active="$user->is_active" /></div>
                    </div>
                    @if ($isSelf && $user->is_active)
                        <p data-user-self-restriction class="text-sm text-gray-500">Отключить собственную учётную запись может другой администратор.</p>
                    @elseif ($user->is_active)
                        @can('users.deactivate')
                            <form method="POST" action="{{ route('admin.users.deactivate', $user) }}" onsubmit="return confirm('Отключённый пользователь потеряет доступ к CRM. Продолжить?')" data-user-status-form>
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="_section" value="status">
                                <button class="rounded-lg border border-red-300 px-4 py-2.5 text-sm font-medium text-red-700 hover:bg-red-50">Отключить</button>
                            </form>
                        @endcan
                    @else
                        @can('users.activate')
                            <form method="POST" action="{{ route('admin.users.activate', $user) }}" data-user-status-form>
                                @csrf
                                @method('PATCH')
                                <button class="rounded-lg border border-green-300 px-4 py-2.5 text-sm font-medium text-green-700 hover:bg-green-50">Активировать</button>
                            </form>
                        @endcan
                    @endif
                </div>
                @error('status')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
            </section>

            <section data-user-security>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Безопасность</h2>
                    @can('users.reset_password')
                        @unless ($isSelf)
                            <button type="button" class="text-sm font-medium text-blue-700 hover:text-blue-800" x-on:click="passwordOpen = !passwordOpen" x-bind:aria-expanded="passwordOpen.toString()">Сбросить пароль</button>
                        @endunless
                    @endcan
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-y border-gray-200 py-5 text-sm">
                    <div>
                        <p class="text-gray-500">Временный пароль</p>
                        @if ($user->must_change_password)
                            <span class="mt-1 inline-flex items-center rounded-full bg-yellow-100 px-2.5 py-0.5 text-xs font-medium text-yellow-700">Требуется смена</span>
                        @else
                            <p class="mt-1 font-medium text-gray-900">Не требуется</p>
                        @endif
                    </div>
                    @can('users.reset_password')
                        @if ($isSelf)
                            <p data-user-self-restriction class="text-sm text-gray-500">Для своей учётной записи используйте пункт <a class="font-medium underline" href="{{ route('password.change') }}">«Сменить пароль»</a> в меню настроек.</p>
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
                                <x-forms.password-input name="password" label="Новый временный пароль" autocomplete="new-password" errorBag="resetPassword" required />
                                <x-forms.password-input name="password_confirmation" label="Подтверждение временного пароля" autocomplete="new-password" errorBag="resetPassword" required />
                                <p class="text-xs text-gray-500">Не менее 12 символов, включая заглавную и строчную буквы, цифру и специальный символ.</p>
                                <div class="flex flex-wrap justify-end gap-3">
                                    <button type="button" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50" x-on:click="passwordOpen = false">Отмена</button>
                                    <button class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">Установить временный пароль</button>
                                </div>
                            </form>
                        </div>
                    @endunless
                @endcan
            </section>
        </div>
    </div>
@endsection
