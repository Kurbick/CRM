@extends('layouts.app')

@section('title', 'Добавить пользователя')

@section('content')
    <div class="mx-auto max-w-3xl">
        <div class="mb-6">
            <a href="{{ route('admin.users.index') }}" class="mb-2 inline-block text-sm text-gray-500 hover:text-gray-900">← Назад к пользователям</a>
            <h1 class="text-2xl font-bold text-gray-900">Добавить пользователя</h1>
        </div>
        <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-5 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            <div><label for="name" class="mb-1 block text-sm font-medium text-gray-700">Имя</label><input id="name" name="name" value="{{ old('name') }}" required class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">@error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div><label for="email" class="mb-1 block text-sm font-medium text-gray-700">Email</label><input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">@error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div><label for="role_id" class="mb-1 block text-sm font-medium text-gray-700">Группа</label><select id="role_id" name="role_id" required class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm"><option value="">Выберите группу</option>@foreach ($roles as $role)<option value="{{ $role->id }}" @selected(old('role_id') == $role->id)>{{ $role->display_name }}{{ $role->description ? ' — '.$role->description : '' }}</option>@endforeach</select>@error('role_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <x-forms.password-input name="password" label="Временный пароль" autocomplete="new-password" required />
            <x-forms.password-input name="password_confirmation" label="Подтверждение пароля" autocomplete="new-password" required />
            <p class="text-xs text-gray-500">Не менее 12 символов, включая заглавную и строчную буквы, цифру и специальный символ.</p>
            <div class="flex justify-end gap-3 border-t border-gray-100 pt-5"><a href="{{ route('admin.users.index') }}" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700">Отмена</a><button class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">Добавить пользователя</button></div>
        </form>
    </div>
@endsection
