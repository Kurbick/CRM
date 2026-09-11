@php
    $periodLabels = [
        'this_month' => __('dashboard.period.options.this_month'),
        '3m' => __('dashboard.period.options.3m'),
        '6m' => __('dashboard.period.options.6m'),
        '1y' => __('dashboard.period.options.1y'),
        'all' => __('dashboard.period.options.all'),
        'custom' => __('dashboard.period.options.custom'),
    ];
    $presetPeriods = [
        'this_month' => __('dashboard.period.options.this_month'),
        '3m' => __('dashboard.period.options.3m'),
        '6m' => __('dashboard.period.options.6m'),
        '1y' => __('dashboard.period.options.1y'),
        'all' => __('dashboard.period.options.all'),
    ];
@endphp

<div data-testid="{{ $testIdPrefix }}-period-selector" class="relative shrink-0"
    x-data="{ open: false, customOpen: false }"
    x-on:click.outside="open = false; customOpen = false"
    x-on:keydown.escape.window="open = false; customOpen = false; $nextTick(() => $refs.periodTrigger.focus())">
    <button type="button"
        data-testid="{{ $testIdPrefix }}-period-trigger"
        aria-haspopup="menu"
        aria-expanded="false"
        x-bind:aria-expanded="(open || customOpen).toString()"
        x-on:click="open = !open; customOpen = false"
        x-ref="periodTrigger"
        class="inline-flex items-center gap-1.5 px-1 py-0.5 text-xs font-medium text-slate-500 transition hover:bg-slate-50 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 focus-visible:ring-offset-2">
        <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
            <rect x="3.5" y="5" width="17" height="15.5" rx="2" stroke-width="1.7" />
            <path stroke-linecap="round" stroke-width="1.7" d="M7.5 3.5v3M16.5 3.5v3M3.5 9.5h17" />
        </svg>
        <span data-testid="{{ $testIdPrefix }}-period-active-label">{{ $periodLabels[$period['key']] }}</span>
        <svg class="h-3 w-3 transition" x-bind:class="(open || customOpen) ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.51a.75.75 0 0 1-1.08 0l-4.25-4.51a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
        </svg>
    </button>

    <div x-show="open" x-cloak x-transition
        data-testid="{{ $testIdPrefix }}-period-menu"
        role="menu"
        aria-label="{{ __('dashboard.period.trigger') }}"
        class="absolute right-0 top-full z-50 mt-2 w-44 overflow-hidden rounded-md border border-slate-200 bg-white py-1 text-left shadow-lg">
        @foreach ($presetPeriods as $periodKey => $periodLabel)
            @php($periodUrlParameters = [...$routeParameters, 'period' => $periodKey])
            <a href="{{ route($routeName, $periodUrlParameters) }}"
                data-testid="{{ $testIdPrefix }}-period-option-{{ $periodKey }}"
                role="menuitem"
                @if ($period['key'] === $periodKey) aria-current="page" @endif
                class="flex w-full items-center justify-between gap-3 px-3 py-2 text-xs transition hover:bg-slate-50 focus:bg-slate-50 focus:outline-none {{ $period['key'] === $periodKey ? 'font-medium text-blue-700' : 'text-slate-700' }}">
                <span>{{ $periodLabel }}</span>
                @if ($period['key'] === $periodKey)
                    <span aria-hidden="true" class="text-blue-600">✓</span>
                @endif
            </a>
        @endforeach

        <div class="my-1 border-t border-slate-100"></div>
        <button type="button"
            data-testid="{{ $testIdPrefix }}-period-option-custom"
            role="menuitem"
            aria-haspopup="dialog"
            x-on:click="open = false; customOpen = true; $nextTick(() => $refs.customPanel.querySelector('input:not([type=hidden])')?.focus())"
            class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-xs transition hover:bg-slate-50 focus:bg-slate-50 focus:outline-none {{ $period['key'] === 'custom' ? 'font-medium text-blue-700' : 'text-slate-700' }}">
            <span>{{ __('dashboard.period.options.custom') }}</span>
            @if ($period['key'] === 'custom')
                <span aria-hidden="true" class="text-blue-600">✓</span>
            @endif
        </button>
    </div>

    <div x-show="customOpen" x-cloak x-transition x-ref="customPanel"
        data-testid="{{ $testIdPrefix }}-period-custom-panel"
        role="dialog"
        aria-label="{{ __('dashboard.period.options.custom') }}"
        class="absolute right-0 top-full z-50 mt-2 w-72 rounded-md border border-slate-200 bg-white p-3 text-left shadow-lg">
        <form method="get" action="{{ route($routeName, $routeParameters) }}" class="flex flex-wrap items-end gap-2">
            <input type="hidden" name="period" value="custom">
            <label class="text-left text-[11px] font-medium text-slate-500">
                <span class="mb-1 block">{{ __('dashboard.period.from') }}</span>
                <x-form.date-input name="date_from" :value="$period['from'] ?? null" required class="!h-8 !min-h-0 !w-32 !rounded !px-2 !py-1 !pr-8 !text-xs" />
            </label>
            <label class="text-left text-[11px] font-medium text-slate-500">
                <span class="mb-1 block">{{ __('dashboard.period.to') }}</span>
                <x-form.date-input name="date_to" :value="$period['to'] ?? null" required class="!h-8 !min-h-0 !w-32 !rounded !px-2 !py-1 !pr-8 !text-xs" />
            </label>
            <button type="submit" class="h-8 px-2.5 text-xs font-medium text-blue-700 transition hover:bg-blue-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                {{ __('dashboard.period.apply') }}
            </button>
        </form>
    </div>
</div>
