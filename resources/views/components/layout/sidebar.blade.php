@php
    $user = auth()->user();
    $isAdministrator = $user->hasRole(\App\Support\Access\SystemRole::Administrator->value);
    $showAdministration = $isAdministrator
        || $user->can('users.view')
        || $user->can('roles.view')
        || $user->can('access_permissions.view');
    $canAccessWorkspace = $user->can('roles.view') || $user->can('access_permissions.view');
    $accessWorkspaceUrl = $user->can('access_permissions.view')
        ? route('admin.access-permissions.index')
        : route('admin.roles.index');
    $accessWorkspaceActive = request()->routeIs('admin.roles.*', 'admin.access-permissions.*');

    $contractsActive = request()->routeIs(
        'contracts.*',
        'companies.contracts.*',
        'contract-documents.*',
        'orders.*',
        'subscriptions.*',
    );
    $invoicesActive = request()->routeIs('invoices.*', 'payments.*');
@endphp

<aside id="crm-sidebar"
    class="crm-sidebar crm-print-hide fixed inset-y-0 left-0 top-14 z-30 flex w-60 shrink-0 -translate-x-full flex-col border-r border-slate-200 bg-white transition-transform duration-200 lg:sticky lg:top-14 lg:h-[calc(100vh-3.5rem)] lg:translate-x-0"
    aria-label="Основная навигация"
    x-bind:class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">
    <div class="border-b border-slate-100 px-5 py-4 lg:hidden">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Навигация</p>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-5" aria-label="Разделы CRM">
        <div class="space-y-1">
            @can(\App\Support\Access\PermissionName::DashboardView->value)
                <a href="{{ route('dashboard') }}" @click="sidebarOpen = false"
                    class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition {{ request()->routeIs('dashboard') ? 'bg-slate-100 text-slate-950' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950' }}"
                    @if (request()->routeIs('dashboard')) aria-current="page" @endif>
                    <svg class="h-4 w-4 shrink-0 {{ request()->routeIs('dashboard') ? 'text-slate-900' : 'text-slate-400' }}" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 13h6V4H4v9Zm0 7h6v-4H4v4Zm10 0h6v-9h-6v9Zm0-16v4h6V4h-6Z" />
                    </svg>
                    <span>Дашборд</span>
                </a>
            @endcan

            @can('viewAny', \App\Models\Company::class)
                <a href="{{ route('companies.index') }}" @click="sidebarOpen = false"
                    class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition {{ request()->routeIs('companies.*', 'contacts.*') ? 'bg-slate-100 text-slate-950' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950' }}"
                    @if (request()->routeIs('companies.*', 'contacts.*')) aria-current="page" @endif>
                    <svg class="h-4 w-4 shrink-0 {{ request()->routeIs('companies.*', 'contacts.*') ? 'text-slate-900' : 'text-slate-400' }}" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 20h16M6 20V6.5L12 4l6 2.5V20M9 9h1m4 0h1M9 13h1m4 0h1M9 17h1m4 0h1" />
                    </svg>
                    <span>Компании</span>
                </a>
            @endcan

            @can('viewAny', \App\Models\Contract::class)
                <a href="{{ route('contracts.index') }}" @click="sidebarOpen = false"
                    class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition {{ $contractsActive ? 'bg-slate-100 text-slate-950' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950' }}"
                    @if ($contractsActive) aria-current="page" @endif>
                    <svg class="h-4 w-4 shrink-0 {{ $contractsActive ? 'text-slate-900' : 'text-slate-400' }}" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 3.75h9l3 3V20.25H6V3.75Zm9 0v3h3M9 11h6m-6 4h6" />
                    </svg>
                    <span>Договоры</span>
                </a>
            @endcan

            @can('viewAny', \App\Models\Invoice::class)
                <a href="{{ route('invoices.index') }}" @click="sidebarOpen = false"
                    class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition {{ $invoicesActive ? 'bg-slate-100 text-slate-950' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950' }}"
                    @if ($invoicesActive) aria-current="page" @endif>
                    <svg class="h-4 w-4 shrink-0 {{ $invoicesActive ? 'text-slate-900' : 'text-slate-400' }}" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 3.75h12v16.5l-3-1.8-3 1.8-3-1.8-3 1.8V3.75ZM9 8h6m-6 4h6m-6 4h3" />
                    </svg>
                    <span>Инвойсы</span>
                </a>
            @endcan
        </div>

        @if ($showAdministration)
            <div class="my-5 border-t border-slate-200"></div>
            <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">Администрирование</p>
            <div class="space-y-1">
                @if ($isAdministrator)
                    <a href="{{ route('admin.organization.show') }}" @click="sidebarOpen = false"
                        class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.organization.*') ? 'bg-slate-100 text-slate-950' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950' }}"
                        @if (request()->routeIs('admin.organization.*')) aria-current="page" @endif>
                        <svg class="h-4 w-4 shrink-0 {{ request()->routeIs('admin.organization.*') ? 'text-slate-900' : 'text-slate-400' }}" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 20h16M5.5 20V8.5L12 5l6.5 3.5V20M9 20v-5h6v5M9 10h.01M12 10h.01M15 10h.01" />
                        </svg>
                        <span>Наша организация</span>
                    </a>
                @endif

                @can('users.view')
                    <a href="{{ route('admin.users.index') }}" @click="sidebarOpen = false"
                        class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.users.*') ? 'bg-slate-100 text-slate-950' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950' }}"
                        @if (request()->routeIs('admin.users.*')) aria-current="page" @endif>
                        <svg class="h-4 w-4 shrink-0 {{ request()->routeIs('admin.users.*') ? 'text-slate-900' : 'text-slate-400' }}" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 19v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 17.5V19m6-8a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm5.5-5.5a2.5 2.5 0 0 1 0 5m1.5 3.5h.5a3.5 3.5 0 0 1 3.5 3.5V19" />
                        </svg>
                        <span>Пользователи</span>
                    </a>
                @endcan

                @if ($canAccessWorkspace)
                    <a href="{{ $accessWorkspaceUrl }}" @click="sidebarOpen = false"
                        class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition {{ $accessWorkspaceActive ? 'bg-slate-100 text-slate-950' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950' }}"
                        @if ($accessWorkspaceActive) aria-current="page" @endif>
                        <svg class="h-4 w-4 shrink-0 {{ $accessWorkspaceActive ? 'text-slate-900' : 'text-slate-400' }}" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3.5 19 6v5.1c0 4.45-2.97 7.76-7 9.4-4.03-1.64-7-4.95-7-9.4V6l7-2.5Zm0 4.2v4.1m0 3.5v.01" />
                        </svg>
                        <span>Доступ</span>
                    </a>
                @endif
            </div>
        @endif
    </nav>
</aside>
