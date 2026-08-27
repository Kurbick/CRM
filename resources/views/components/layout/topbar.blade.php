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
