<header class="crm-global-navigation crm-print-hide sticky top-0 z-40 h-14 border-b border-slate-800 bg-slate-900 text-white">
    <div class="flex h-full items-center justify-between px-4 sm:px-6">
        <div class="flex min-w-0 items-center gap-3">
            <button type="button" aria-label="{{ __('navigation.shell.mobile_menu') }}" aria-controls="crm-sidebar"
                x-on:click="sidebarOpen = !sidebarOpen"
                class="rounded-md p-2 text-slate-300 transition hover:bg-slate-800 hover:text-white focus:outline-none focus:ring-2 focus:ring-slate-400 lg:hidden">
                <svg class="h-5 w-5" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 7h16M4 12h16M4 17h16" />
                </svg>
            </button>

            <a href="{{ $authorizedLandingUrl }}" class="flex items-center gap-2" aria-label="{{ __('navigation.shell.home_aria') }}">
                <span class="flex h-8 w-8 items-center justify-center rounded-md bg-white text-xs font-bold text-slate-900">CR</span>
                <span class="text-sm font-semibold tracking-wide text-white">CRM</span>
            </a>
        </div>

        <div class="flex items-center gap-2">
            @if (($activeOrganizations ?? collect())->count() > 1)
                <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
                    <button type="button" x-on:click="open = !open" x-bind:aria-expanded="open.toString()"
                        aria-haspopup="menu" aria-label="{{ __('organizations.switcher.current') }}"
                        class="flex max-w-44 items-center gap-1 rounded-md px-2 py-1.5 text-left text-xs font-medium text-slate-200 transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400">
                        <span class="truncate">{{ $activeOrganization?->name ?? __('organizations.switcher.choose') }}</span>
                        <svg class="h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="m7 10 5 5 5-5" />
                        </svg>
                    </button>
                    <div x-show="open" x-cloak x-transition role="menu"
                        class="absolute right-0 top-full z-50 mt-2 w-48 overflow-hidden rounded-md border border-slate-200 bg-white py-1 shadow-lg">
                        @foreach ($activeOrganizations as $organization)
                            <form method="POST" action="{{ route('organization-context.update') }}">
                                @csrf
                                <input type="hidden" name="organization_id" value="{{ $organization->id }}">
                                <input type="hidden" name="return" value="{{ request()->fullUrl() }}">
                                <button type="submit" role="menuitem"
                                    class="block w-full px-4 py-2 text-left text-sm transition hover:bg-slate-50 {{ $activeOrganization?->is($organization) ? 'font-semibold text-slate-900' : 'text-slate-600' }}">
                                    {{ $organization->name }}
                                </button>
                            </form>
                        @endforeach
                    </div>
                </div>
            @elseif (($activeOrganizations ?? collect())->count() === 0)
                <span class="max-w-44 truncate px-2 text-xs font-medium text-slate-300" aria-label="{{ __('organizations.switcher.current') }}">
                    {{ __('organizations.switcher.none_available') }}
                </span>
            @elseif ($activeOrganization)
                <span class="max-w-44 truncate px-2 text-xs font-medium text-slate-200" aria-label="{{ __('organizations.switcher.current') }}">{{ $activeOrganization->name }}</span>
            @endif

            @php($currentLocale = app()->getLocale())
            <div class="flex items-center gap-1 border-r border-slate-700 pr-2" aria-label="RU AZ">
                @foreach (['ru' => 'RU', 'az' => 'AZ'] as $locale => $label)
                    <form method="POST" action="{{ route('locale.update') }}">
                        @csrf
                        <input type="hidden" name="locale" value="{{ $locale }}">
                        <button type="submit" aria-pressed="{{ $currentLocale === $locale ? 'true' : 'false' }}"
                            class="rounded px-1.5 py-1 text-[11px] font-semibold tracking-wide transition {{ $currentLocale === $locale ? 'bg-slate-700 text-white' : 'text-slate-400 hover:bg-slate-800 hover:text-white' }}">
                            {{ $label }}
                        </button>
                    </form>
                @endforeach
            </div>

            @include('components.layout.user-menu')
        </div>
    </div>
</header>
