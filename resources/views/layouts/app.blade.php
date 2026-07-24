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
    <style>
        [x-cloak] {
            display: none !important;
        }

        @media print {
            html,
            body {
                background: #fff !important;
            }

            .crm-print-hide {
                display: none !important;
            }

            .crm-main {
                width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .invoice-screen-grid {
                display: block !important;
            }

            .invoice-document-column,
            .invoice-document {
                width: 100% !important;
                max-width: none !important;
            }

            .invoice-document {
                border: 0 !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                overflow: visible !important;
                padding: 0 !important;
            }

            .invoice-print-only {
                display: table-cell !important;
            }

            .invoice-line-row,
            .invoice-payer,
            .invoice-totals,
            .invoice-comment {
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }
    </style>
</head>

<body class="bg-gray-50 text-gray-900">

    {{-- Навигация --}}
    <nav class="crm-global-navigation crm-print-hide bg-white border-b border-gray-200 shadow-sm">
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
                <div class="flex items-center gap-2">

                    <a href="{{ route('dashboard') }}"
                        class="px-3 py-2 rounded-lg text-sm font-medium transition
                {{ request()->routeIs('dashboard')
                    ? 'bg-blue-50 text-blue-600'
                    : 'text-gray-500 hover:bg-gray-100 hover:text-gray-900' }}">
                        Дашборд
                    </a>

                    <a href="{{ route('companies.index') }}"
                        class="px-3 py-2 rounded-lg text-sm font-medium transition
                {{ request()->routeIs('companies.*')
                    ? 'bg-blue-50 text-blue-600'
                    : 'text-gray-500 hover:bg-gray-100 hover:text-gray-900' }}">
                        Компании
                    </a>

                    <a href="{{ route('contracts.index') }}"
                        class="px-3 py-2 rounded-lg text-sm font-medium transition
                {{ request()->routeIs('contracts.*')
                    ? 'bg-blue-50 text-blue-600'
                    : 'text-gray-500 hover:bg-gray-100 hover:text-gray-900' }}">
                        Договоры
                    </a>

                    <a href="{{ route('invoices.index') }}"
                        class="px-3 py-2 rounded-lg text-sm font-medium transition
                {{ request()->routeIs('invoices.*')
                    ? 'bg-blue-50 text-blue-600'
                    : 'text-gray-500 hover:bg-gray-100 hover:text-gray-900' }}">
                        Инвойсы
                    </a>

                    <div class="relative ml-3 flex items-center gap-1.5" x-data="{ open: false }"
                        x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
                        <span class="text-sm text-gray-600">{{ auth()->user()->name }}</span>
                        <button type="button" aria-label="Настройки" aria-haspopup="menu"
                            x-bind:aria-expanded="open.toString()" x-on:click="open = !open"
                            class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <svg class="h-5 w-5" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                    d="M9.6 3.2h4.8l.55 2.15c.5.2.98.47 1.4.8l2.12-.62 2.4 4.15-1.58 1.52c.04.27.06.53.06.8s-.02.53-.06.8l1.58 1.52-2.4 4.15-2.12-.62c-.42.33-.9.6-1.4.8l-.55 2.15H9.6l-.55-2.15a7.5 7.5 0 0 1-1.4-.8l-2.12.62-2.4-4.15 1.58-1.52A5.4 5.4 0 0 1 4.65 12c0-.27.02-.53.06-.8L3.13 9.68l2.4-4.15 2.12.62c.42-.33.9-.6 1.4-.8L9.6 3.2Z" />
                                <circle cx="12" cy="12" r="2.75" stroke-width="1.8" />
                            </svg>
                        </button>

                        <div x-show="open" x-cloak x-transition role="menu"
                            class="absolute right-0 top-full z-50 mt-2 w-48 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                            <a href="{{ route('password.change') }}" role="menuitem"
                                class="block px-4 py-2.5 text-sm text-gray-700 transition hover:bg-gray-50 focus:bg-gray-50 focus:outline-none">
                                Сменить пароль
                            </a>
                            <div class="my-1 border-t border-gray-100"></div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" role="menuitem"
                                    class="block w-full px-4 py-2.5 text-left text-sm text-red-600 transition hover:bg-red-50 focus:bg-red-50 focus:outline-none">
                                    Выйти
                                </button>
                            </form>
                        </div>
                    </div>

                </div>

            </div>
        </div>
    </nav>

    {{-- Flash сообщения --}}
    @if (session('success'))
        <div class="crm-flash-message crm-print-hide max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
            <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 text-sm">
                {{ session('success') }}
            </div>
        </div>
    @endif

    @if (session('error'))
        <div class="crm-flash-message crm-print-hide max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
            <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 text-sm">
                {{ session('error') }}
            </div>
        </div>
    @endif

    {{-- Основной контент --}}
    <main class="crm-main max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        @yield('content')
    </main>

    {{-- Footer --}}
    <footer class="crm-global-footer crm-print-hide border-t border-gray-200 mt-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <p class="text-xs text-gray-400 text-center">CRM IT Company © {{ date('Y') }}</p>
        </div>
    </footer>

</body>

</html>
