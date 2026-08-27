<div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
    <button x-ref="userMenuButton" type="button" aria-label="{{ __('auth.settings') }}" aria-haspopup="menu"
        x-bind:aria-expanded="open.toString()" x-on:click="open = !open"
        class="flex items-center gap-2 rounded-md px-2 py-1.5 text-left transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2 focus:ring-offset-slate-900">
        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-700 text-xs font-semibold text-slate-100" aria-hidden="true">
            {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
        </span>
        <span class="hidden max-w-40 truncate text-sm font-medium text-slate-100 sm:block">{{ auth()->user()->name }}</span>
        <svg class="h-4 w-4 text-slate-400" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="m7 10 5 5 5-5" />
        </svg>
    </button>

    <div x-show="open" x-cloak x-transition role="menu"
        class="absolute right-0 top-full z-50 mt-2 w-48 overflow-hidden rounded-md border border-slate-200 bg-white py-1 shadow-lg">
        <a href="{{ route('password.change') }}" role="menuitem"
            class="block px-4 py-2.5 text-sm text-slate-700 transition hover:bg-slate-50 focus:bg-slate-50 focus:outline-none">
            {{ __('auth.change_password') }}
        </a>
        <div class="my-1 border-t border-slate-100"></div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" role="menuitem"
                class="block w-full px-4 py-2.5 text-left text-sm text-red-600 transition hover:bg-red-50 focus:bg-red-50 focus:outline-none">
                {{ __('auth.logout') }}
            </button>
        </form>
    </div>
</div>
