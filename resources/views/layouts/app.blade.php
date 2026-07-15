<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'CRM') — IT Company</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    {{-- Alpine.js — для интерактивности (модалки, дропдауны) без написания JS --}}
</head>
<body class="bg-gray-50 text-gray-900">

    {{-- Навигация --}}
    <nav class="bg-white border-b border-gray-200 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16 items-center">

                {{-- Логотип --}}
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                    <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center">
                        <span class="text-white font-bold text-sm">CR</span>
                    </div>
                    <span class="font-semibold text-gray-800 text-lg">CRM</span>
                </a>

                {{-- Навигационные ссылки --}}
                <div class="flex items-center gap-6">
                    <a href="{{ route('dashboard') }}"
                       class="text-sm font-medium {{ request()->routeIs('dashboard') ? 'text-blue-600' : 'text-gray-500 hover:text-gray-900' }}">
                        Дашборд
                    </a>
                    <a href="{{ route('companies.index') }}"
                       class="text-sm font-medium {{ request()->routeIs('companies.*') ? 'text-blue-600' : 'text-gray-500 hover:text-gray-900' }}">
                        Компании
                    </a>
                    <a href="{{ route('invoices.index') }}"
                       class="text-sm font-medium {{ request()->routeIs('invoices.*') ? 'text-blue-600' : 'text-gray-500 hover:text-gray-900' }}">
                        Инвойсы
                    </a>
                    <a href="{{ route('contracts.index') }}"
                        class="text-sm font-medium {{ request()->routeIs('contracts.*') ? 'text-blue-600' : 'text-gray-500 hover:text-gray-900' }}">
                        Договора
                    </a>
                </div>

            </div>
        </div>
    </nav>

    {{-- Flash сообщения --}}
    @if(session('success'))
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
            <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 text-sm">
                {{ session('success') }}
            </div>
        </div>
    @endif

    @if(session('error'))
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
            <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 text-sm">
                {{ session('error') }}
            </div>
        </div>
    @endif

    {{-- Основной контент --}}
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        @yield('content')
    </main>

    {{-- Footer --}}
    <footer class="border-t border-gray-200 mt-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <p class="text-xs text-gray-400 text-center">CRM IT Company © {{ date('Y') }}</p>
        </div>
    </footer>

</body>
</html>