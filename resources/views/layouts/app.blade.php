<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'CRM') — IT Company</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    @include('components.forms.styles')
    @include('components.tables.styles')
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

<body class="min-h-screen bg-slate-100 text-slate-900" x-data="{ sidebarOpen: false }">
    @php
        $authorizedLandingUrl ??= app(\App\Support\Navigation\AuthorizedLandingPage::class)
            ->url(auth()->user());
    @endphp

    @include('components.layout.topbar')

    <div class="flex min-h-[calc(100vh-3.5rem)]">
        @include('components.layout.sidebar')

        <div class="flex min-w-0 flex-1 flex-col">
            <div x-show="sidebarOpen" x-cloak x-on:click="sidebarOpen = false"
                class="fixed inset-0 top-14 z-20 bg-slate-950/30 lg:hidden" aria-hidden="true"></div>

            @if (session('success'))
                <div x-data="{ visible: true }" x-init="setTimeout(() => visible = false, 3000)"
                    x-show="visible" x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-300"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    class="crm-flash-message crm-print-hide px-4 pt-5 sm:px-6 lg:px-8">
                    <div class="relative flex items-start justify-between gap-4 border border-green-200 bg-green-50 px-4 py-3 pr-10 text-sm text-green-800"
                        role="status" aria-live="polite">
                        {{ session('success') }}
                        <button type="button" x-on:click="visible = false" aria-label="{{ __('navigation.shell.close_message') }}"
                            class="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-lg leading-none text-green-700 transition hover:bg-green-100 focus:outline-none focus:ring-2 focus:ring-green-500">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                </div>
            @endif

            @if (session('error'))
                <div class="crm-flash-message crm-print-hide px-4 pt-5 sm:px-6 lg:px-8">
                    <div class="border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        {{ session('error') }}
                    </div>
                </div>
            @endif

            <main class="crm-main crm-form-scope w-full flex-1 px-4 py-6 sm:px-6 lg:px-8">
                @yield('content')
            </main>

            <footer class="crm-global-footer crm-print-hide border-t border-slate-200 bg-white">
                <div class="px-4 py-4 sm:px-6 lg:px-8">
                    <p class="text-xs text-slate-400">CRM IT Company © {{ date('Y') }}</p>
                </div>
            </footer>
        </div>
    </div>
</body>

</html>
