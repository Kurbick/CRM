@extends('layouts.app')
@section('title', 'Редактировать контакт')
@section('content')

<div class="mb-6">
    <a href="{{ route('companies.show', $company) }}" class="text-sm text-gray-500 hover:text-gray-700">← Назад к компании</a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">Редактировать контакт</h1>
    <p class="text-sm text-gray-500">{{ $company->name }}</p>
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 max-w-2xl">
    <form action="{{ route('contacts.update', $contact) }}" method="POST" class="space-y-4">
        @csrf
        @method('PUT')

        {{-- Имя и Фамилия --}}
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Имя <span class="text-red-500">*</span></label>
                <input type="text" name="first_name" value="{{ old('first_name', $contact->first_name) }}"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                       required>
                @error('first_name')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Фамилия</label>
                <input type="text" name="last_name" value="{{ old('last_name', $contact->last_name) }}"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">
            </div>
        </div>

        {{-- Должность и Телефон --}}
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Должность</label>
                <select name="role" id="role"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition"
                        onchange="document.getElementById('custom_role').classList.toggle('hidden', this.value !== 'other')">
                    <option value="">— Выберите —</option>
                    <option value="director" {{ old('role', $contact->role) === 'director' ? 'selected' : '' }}>Директор</option>
                    <option value="accountant" {{ old('role', $contact->role) === 'accountant' ? 'selected' : '' }}>Бухгалтер</option>
                    <option value="manager" {{ old('role', $contact->role) === 'manager' ? 'selected' : '' }}>Менеджер</option>
                    <option value="technical" {{ old('role', $contact->role) === 'technical' ? 'selected' : '' }}>Технический специалист</option>
                    <option value="other" {{ old('role', $contact->role) === 'other' ? 'selected' : '' }}>Другое</option>
                </select>
                <input type="text" name="position" id="custom_role"
                       value="{{ old('position', $contact->position) }}"
                       placeholder="Укажите вручную..."
                       class="{{ old('role', $contact->role) === 'other' ? '' : 'hidden' }} w-full mt-2 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Телефон</label>
                <input type="text" name="phone" value="{{ old('phone', $contact->phone) }}"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">
            </div>
        </div>

        {{-- Email --}}
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Email</label>
                <input type="email" name="email" value="{{ old('email', $contact->email) }}"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">
            </div>
        </div>

        {{-- Комментарий --}}
        <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Комментарий</label>
            <textarea name="comment" rows="3"
                      class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 outline-none transition">{{ old('comment', $contact->comment) }}</textarea>
        </div>

        {{-- Кнопки --}}
        <div class="flex gap-3 pt-2">
            <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-6 py-2.5 rounded-lg transition">
                Сохранить
            </button>
            <a href="{{ route('companies.show', $company) }}"
               class="px-6 py-2.5 border border-gray-200 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-50 transition">
                Отмена
            </a>
        </div>
    </form>
</div>

@endsection