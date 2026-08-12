@extends('layouts.app')

@section('title', 'Наша организация')

@section('content')
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Наша организация</h1>
            <p class="mt-1 text-sm text-gray-500">Реквизиты организации, от имени которой создаются новые инвойсы.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.organization.update') }}" class="max-w-3xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        @csrf
        @method('PUT')

        <div class="grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="name" class="block text-sm font-medium text-gray-700">Название организации</label>
                <input id="name" name="name" type="text" value="{{ old('name', $organization?->name) }}" required
                    class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="voen" class="block text-sm font-medium text-gray-700">VÖEN</label>
                <input id="voen" name="voen" type="text" value="{{ old('voen', $organization?->voen) }}"
                    class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('voen')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="bank_name" class="block text-sm font-medium text-gray-700">Банк</label>
                <input id="bank_name" name="bank_name" type="text" value="{{ old('bank_name', $organization?->bank_name) }}"
                    class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('bank_name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="iban" class="block text-sm font-medium text-gray-700">IBAN / счёт</label>
                <input id="iban" name="iban" type="text" value="{{ old('iban', $organization?->iban) }}"
                    class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('iban')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="bank_code" class="block text-sm font-medium text-gray-700">Код банка</label>
                <input id="bank_code" name="bank_code" type="text" value="{{ old('bank_code', $organization?->bank_code) }}"
                    class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('bank_code')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="bank_voen" class="block text-sm font-medium text-gray-700">VÖEN банка</label>
                <input id="bank_voen" name="bank_voen" type="text" value="{{ old('bank_voen', $organization?->bank_voen) }}"
                    class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('bank_voen')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="swift" class="block text-sm font-medium text-gray-700">SWIFT</label>
                <input id="swift" name="swift" type="text" value="{{ old('swift', $organization?->swift) }}"
                    class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('swift')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="mt-6 flex justify-end">
            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                Сохранить
            </button>
        </div>
    </form>
@endsection
