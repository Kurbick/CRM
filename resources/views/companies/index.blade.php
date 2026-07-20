@extends('layouts.app')

@section('title', 'Компании')

@section('content')

    <div class="mb-6 flex flex-col sm:flex-row justify-between sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Компании</h1>
            <p class="text-sm text-gray-500 mt-1">Управление клиентами, контрагентами и реквизитами</p>
        </div>
        <div>
            <a href="{{ route('companies.create') }}"
               class="inline-flex items-center text-sm bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-lg transition shadow-sm font-medium">
                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                </svg>
                Добавить компанию
            </a>
        </div>
    </div>

    {{-- Фильтры и поиск --}}
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm mb-6">
        <form action="{{ route('companies.index') }}" method="GET" class="flex flex-col md:flex-row md:items-center gap-4">
            <!-- Поиск -->
            <div class="flex-1 relative">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400 pointer-events-none">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </span>
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Поиск по названию, краткому имени или VÖEN..."
                       class="w-full pl-10 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
            </div>
            
            <!-- Фильтр по статусу -->
            <div class="w-full md:w-48">
                <select name="status" onchange="this.form.submit()"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                    <option value="">Все статусы</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Активные</option>
                    <option value="suspended" {{ request('status') === 'suspended' ? 'selected' : '' }}>Приостановленные</option>
                    <option value="archived" {{ request('status') === 'archived' ? 'selected' : '' }}>В архиве</option>
                </select>
            </div>
            
            <!-- Кнопки -->
            <div class="flex gap-2">
                <button type="submit"
                        class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition">
                    Найти
                </button>
                @if(request('search') || request('status'))
                    <a href="{{ route('companies.index') }}"
                       class="px-4 py-2 border border-gray-200 hover:bg-gray-50 text-gray-500 text-sm font-medium rounded-lg transition text-center">
                        Сбросить
                    </a>
                @endif
            </div>
        </form>
    </div>

    {{-- Список компаний --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left bg-gray-50 text-gray-500">
                        <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider">Компания / Тип</th>
                        <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider">VÖEN</th>
                        <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider">Контакты</th>
                        <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider">Долг</th>
                        <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider">Статус</th>
                        <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-gray-700">
                    @forelse($companies as $company)
                        <tr class="hover:bg-gray-50/50 transition">
                            <td class="px-6 py-4">
                                <div class="font-semibold text-gray-900">{{ $company->name }}</div>
                                <div class="text-xs text-gray-400 mt-0.5">
                                    @if($company->type === 'company')
                                        Юридическое лицо
                                    @else
                                        Индивидуальный предприниматель
                                    @endif
                                    @if($company->short_name)
                                        <span class="text-gray-300 mx-1">|</span> {{ $company->short_name }}
                                    @endif
                                </div>
                            </td>
                            <td class="px-6 py-4 font-mono text-xs text-gray-600">
                                {{ $company->voen ?? '—' }}
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-gray-900 text-xs">{{ $company->email ?? '—' }}</div>
                                <div class="text-gray-400 text-xs mt-0.5">{{ $company->phone ?? '—' }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="{{ $company->total_debt > 0 ? 'text-red-600 font-semibold' : 'text-gray-400 font-medium' }}">
                                    {{ number_format($company->total_debt, 2) }} ₼
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                @include('partials.badge', ['status' => $company->status])
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('companies.show', ['company' => $company, 'return_url' => request()->fullUrl()]) }}"
                                       class="text-blue-600 hover:text-blue-800 text-sm font-semibold transition">
                                        Открыть →
                                    </a>
                                    <a href="{{ route('companies.edit', $company) }}"
                                       class="text-gray-400 hover:text-gray-600 transition" title="Редактировать">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                        </svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                           <td colspan="6" class="px-6 py-12 text-center text-gray-400">
                               Компаний не найдено.
                               @if(request('search') || request('status'))
                                   <a href="{{ route('companies.index') }}" class="text-blue-600 hover:underline ml-1">Сбросить фильтры</a>
                               @else
                                   <a href="{{ route('companies.create') }}" class="text-blue-600 hover:underline ml-1">Добавить первую компанию</a>
                               @endif
                           </td>
                       </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        {{-- Пагинация --}}
        @if($companies->hasPages())
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50/50">
                {{ $companies->links() }}
            </div>
        @endif
    </div>

@endsection
