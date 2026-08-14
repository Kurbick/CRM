@extends('layouts.app')

@section('title', 'Редактирование организации')

@section('content')
    <div class="mx-auto max-w-4xl">
        <div class="mb-6">
            <a href="{{ route('admin.organization.show') }}" class="mb-2 inline-block text-sm text-gray-500 hover:text-gray-900">← Назад к организации</a>
            <h1 class="text-2xl font-bold text-gray-900">Редактирование организации</h1>
            <p class="mt-1 text-sm text-gray-500">Реквизиты организации, используемые в новых инвойсах.</p>
        </div>

        <form method="POST" action="{{ route('admin.organization.update') }}" class="space-y-6 border-t border-gray-200 pt-6" data-organization-edit-form>
            @csrf
            @method('PUT')

            <section class="border-b border-gray-200 pb-6">
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">Основная информация</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="name" class="mb-1.5 block text-sm font-medium text-gray-700">Название организации</label>
                        <input id="name" name="name" type="text" value="{{ old('name', $organization?->name) }}" required class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="voen" class="mb-1.5 block text-sm font-medium text-gray-700">VÖEN</label>
                        <input id="voen" name="voen" type="text" value="{{ old('voen', $organization?->voen) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        @error('voen')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>

            <section class="border-b border-gray-200 pb-6">
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">Банковские реквизиты</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="bank_name" class="mb-1.5 block text-sm font-medium text-gray-700">Банк</label>
                        <input id="bank_name" name="bank_name" type="text" value="{{ old('bank_name', $organization?->bank_name) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        @error('bank_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="iban" class="mb-1.5 block text-sm font-medium text-gray-700">IBAN / счёт</label>
                        <input id="iban" name="iban" type="text" value="{{ old('iban', $organization?->iban) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        @error('iban')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="bank_code" class="mb-1.5 block text-sm font-medium text-gray-700">Код банка</label>
                        <input id="bank_code" name="bank_code" type="text" value="{{ old('bank_code', $organization?->bank_code) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        @error('bank_code')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="bank_voen" class="mb-1.5 block text-sm font-medium text-gray-700">VÖEN банка</label>
                        <input id="bank_voen" name="bank_voen" type="text" value="{{ old('bank_voen', $organization?->bank_voen) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        @error('bank_voen')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="swift" class="mb-1.5 block text-sm font-medium text-gray-700">SWIFT</label>
                        <input id="swift" name="swift" type="text" value="{{ old('swift', $organization?->swift) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        @error('swift')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap items-center justify-end gap-3 pt-1">
                <a href="{{ route('admin.organization.show') }}" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Отмена</a>
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">Сохранить</button>
            </div>
        </form>
    </div>
@endsection
