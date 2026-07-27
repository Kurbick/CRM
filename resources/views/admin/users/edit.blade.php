@extends('layouts.app')

@section('title', $user->name)

@section('content')
    @php
        $isSelf = auth()->id() === $user->id;
        $currentRole = $user->roles->first();
        $openUser = count($errors->getBag('updateUser')->all()) > 0 || old('_section') === 'user';
        $openRole = count($errors->getBag('updateRole')->all()) > 0 || old('_section') === 'role';
        $openStatus = count($errors->getBag('default')->get('status')) > 0 || old('_section') === 'status' || session()->has('error');
        $openPassword = count($errors->getBag('resetPassword')->all()) > 0 || old('_section') === 'password';
    @endphp

    <div class="mx-auto max-w-4xl">
        <div class="mb-6">
            <a href="{{ route('admin.users.index') }}" class="mb-2 inline-block text-sm text-gray-500 hover:text-gray-900">← Назад к пользователям</a>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold text-gray-900">{{ $user->name }}</h1>
                <x-admin.users.status-badge :active="$user->is_active" />
            </div>
            <p class="mt-1 text-sm text-gray-500">{{ $user->email }}</p>
        </div>

        <div class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <section x-data="{ open: @js($openUser) }" data-accordion-section="user" data-initial-open="{{ $openUser ? 'true' : 'false' }}">
                <button type="button" class="flex w-full items-center gap-4 px-5 py-4 text-left transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500"
                    x-on:click="open = !open" x-bind:aria-expanded="open.toString()" aria-controls="user-details-content">
                    <span class="font-medium text-gray-900">Основные данные</span>
                    <span class="ml-auto truncate text-sm text-gray-500">{{ $user->email }}</span>
                    <svg aria-hidden="true" class="h-4 w-4 shrink-0 text-gray-400 transition-transform" x-bind:class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6" /></svg>
                </button>
                <div id="user-details-content" x-show="open" x-cloak class="border-t border-gray-100 px-5 py-5">
                    @can('users.update')
                        <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-4">@csrf @method('PUT')<input type="hidden" name="_section" value="user">
                            <div><label for="name" class="mb-1 block text-sm font-medium text-gray-700">Имя</label><input id="name" name="name" value="{{ old('name', $user->name) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">@error('name', 'updateUser')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                            <div><label for="email" class="mb-1 block text-sm font-medium text-gray-700">Email</label><input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">@error('email', 'updateUser')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                            <button class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">Сохранить данные</button>
                        </form>
                    @else
                        <dl class="grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-gray-500">Имя</dt><dd class="font-medium">{{ $user->name }}</dd></div><div><dt class="text-gray-500">Email</dt><dd class="font-medium">{{ $user->email }}</dd></div></dl>
                    @endcan
                </div>
            </section>

            <section x-data="{ open: @js($openRole) }" data-accordion-section="role" data-initial-open="{{ $openRole ? 'true' : 'false' }}">
                <button type="button" class="flex w-full items-center gap-4 px-5 py-4 text-left transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500"
                    x-on:click="open = !open" x-bind:aria-expanded="open.toString()" aria-controls="user-role-content">
                    <span class="font-medium text-gray-900">Группа</span><span class="ml-auto"><x-admin.users.role-badge :role="$currentRole" /></span>
                    <svg aria-hidden="true" class="h-4 w-4 shrink-0 text-gray-400 transition-transform" x-bind:class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6" /></svg>
                </button>
                <div id="user-role-content" x-show="open" x-cloak class="border-t border-gray-100 px-5 py-5">
                    @can('users.assign_role')
                        @if ($isSelf)
                            <p class="text-sm text-amber-700">Изменить собственную группу может другой администратор.</p>
                        @else
                            <form method="POST" action="{{ route('admin.users.role.update', $user) }}" class="max-w-xl space-y-3">@csrf @method('PUT')<input type="hidden" name="_section" value="role"><select name="role_id" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">@foreach ($roles as $role)<option value="{{ $role->id }}" @selected(old('role_id', $currentRole?->id) == $role->id)>{{ $role->display_name }}</option>@endforeach</select>@error('role_id', 'updateRole')<p class="text-xs text-red-600">{{ $message }}</p>@enderror<button class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white">Обновить группу</button></form>
                        @endif
                    @else
                        <x-admin.users.role-badge :role="$currentRole" />
                    @endcan
                </div>
            </section>

            <section x-data="{ open: @js($openStatus) }" data-accordion-section="status" data-initial-open="{{ $openStatus ? 'true' : 'false' }}">
                <button type="button" class="flex w-full items-center gap-4 px-5 py-4 text-left transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500"
                    x-on:click="open = !open" x-bind:aria-expanded="open.toString()" aria-controls="user-status-content">
                    <span class="font-medium text-gray-900">Статус учётной записи</span><span class="ml-auto"><x-admin.users.status-badge :active="$user->is_active" /></span>
                    <svg aria-hidden="true" class="h-4 w-4 shrink-0 text-gray-400 transition-transform" x-bind:class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6" /></svg>
                </button>
                <div id="user-status-content" x-show="open" x-cloak class="border-t border-gray-100 px-5 py-5">
                    @if ($isSelf && $user->is_active)
                        <p class="text-sm text-amber-700">Отключить собственную учётную запись может другой администратор.</p>
                    @elseif ($user->is_active)
                        @can('users.deactivate')<form method="POST" action="{{ route('admin.users.deactivate', $user) }}" onsubmit="return confirm('Отключённый пользователь потеряет доступ к CRM. Продолжить?')">@csrf @method('PATCH')<input type="hidden" name="_section" value="status"><button class="rounded-lg border border-red-300 px-4 py-2.5 text-sm font-medium text-red-700 hover:bg-red-50">Отключить пользователя</button></form>@endcan
                    @else
                        @can('users.activate')<form method="POST" action="{{ route('admin.users.activate', $user) }}">@csrf @method('PATCH')<button class="rounded-lg bg-green-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-green-700">Активировать пользователя</button></form>@endcan
                    @endif
                </div>
            </section>

            <section x-data="{ open: @js($openPassword) }" data-accordion-section="password" data-initial-open="{{ $openPassword ? 'true' : 'false' }}">
                <button type="button" class="flex w-full items-center gap-4 px-5 py-4 text-left transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500"
                    x-on:click="open = !open" x-bind:aria-expanded="open.toString()" aria-controls="user-password-content">
                    <span class="font-medium text-gray-900">Временный пароль</span>
                    <span class="ml-auto">@if ($user->must_change_password)<span class="inline-flex items-center rounded-full bg-yellow-100 px-2.5 py-0.5 text-xs font-medium text-yellow-700">Требуется смена</span>@else<span class="text-sm text-gray-500">Не требуется</span>@endif</span>
                    <svg aria-hidden="true" class="h-4 w-4 shrink-0 text-gray-400 transition-transform" x-bind:class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6" /></svg>
                </button>
                <div id="user-password-content" x-show="open" x-cloak class="border-t border-gray-100 px-5 py-5">
                    @can('users.reset_password')
                        @if ($isSelf)
                            <p class="text-sm text-amber-700">Для своей учётной записи используйте пункт <a class="font-medium underline" href="{{ route('password.change') }}">«Сменить пароль»</a> в меню настроек.</p>
                        @else
                            <form method="POST" action="{{ route('admin.users.password.update', $user) }}" class="max-w-xl space-y-4">@csrf @method('PUT')<input type="hidden" name="_section" value="password"><x-forms.password-input name="password" label="Новый временный пароль" autocomplete="new-password" errorBag="resetPassword" required /><x-forms.password-input name="password_confirmation" label="Подтверждение временного пароля" autocomplete="new-password" errorBag="resetPassword" required /><p class="text-xs text-gray-500">Не менее 12 символов, включая заглавную и строчную буквы, цифру и специальный символ.</p><button class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white">Установить временный пароль</button></form>
                        @endif
                    @endcan
                </div>
            </section>
        </div>
    </div>
@endsection
