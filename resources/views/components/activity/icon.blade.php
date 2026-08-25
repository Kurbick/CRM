@php
    $toneClasses = [
        'green' => 'text-green-600',
        'amber' => 'text-amber-600',
        'red' => 'text-red-600',
        'blue' => 'text-blue-600',
        'slate' => 'text-slate-500',
    ];
    $color = $toneClasses[$tone] ?? $toneClasses['slate'];
@endphp

<span class="relative z-10 inline-flex h-[22px] w-[22px] items-center justify-center rounded-full bg-white {{ $color }}" aria-hidden="true">
    @switch($type)
        @case('payment-confirmed')
            <svg class="h-[22px] w-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                <path d="m9 12.75 2.25 2.25L15 9.75" />
            </svg>
            @break
        @case('payment')
            <svg class="h-[22px] w-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                <path d="M12 7.5V12l3 1.5" />
            </svg>
            @break
        @case('payment-cancelled')
            <svg class="h-[22px] w-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                <path d="m9.5 9.5 5 5" />
                <path d="m14.5 9.5-5 5" />
            </svg>
            @break
        @case('credit-applied')
            <svg class="h-[22px] w-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3.75" y="5.25" width="16.5" height="13.5" rx="2" />
                <path d="M3.75 9.25h16.5M8 14h.01M12 14h4" />
            </svg>
            @break
        @case('invoice')
            <svg class="h-[22px] w-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6.75 3.75h7.5l3 3v13.5H6.75V3.75Z" />
                <path d="M14.25 3.75v3h3" />
                <path d="M9 11h6" />
                <path d="M9 14h6" />
                <path d="M9 17h4" />
            </svg>
            @break
        @case('invoice-cancelled')
        @case('invoice-deleted')
            <svg class="h-[22px] w-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6.75 3.75h7.5l3 3v13.5H6.75V3.75Z" />
                <path d="M14.25 3.75v3h3" />
                <path d="m9.5 12.5 5 5M14.5 12.5l-5 5" />
            </svg>
            @break
        @case('document')
            <svg class="h-[22px] w-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l9.193-9.193a3 3 0 1 1 4.243 4.243l-9.193 9.193a1.5 1.5 0 0 1-2.122-2.122l7.693-7.693" />
            </svg>
            @break
        @case('subject')
            <svg class="h-[22px] w-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                <path d="M12 8.5v7" />
                <path d="M8.5 12h7" />
            </svg>
            @break
        @case('subject-updated')
            <svg class="h-[22px] w-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="m15.232 5.232 3.536 3.536M4 20l4.5-1L18.768 8.732a2.5 2.5 0 0 0-3.536-3.536L4.5 15.5 4 20Z" />
            </svg>
            @break
        @case('subject-deleted')
        @case('document-deleted')
            <svg class="h-[22px] w-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l9.193-9.193a3 3 0 1 1 4.243 4.243l-9.193 9.193a1.5 1.5 0 1 1-2.122-2.122l7.693-7.693" />
                <path d="m15.5 15.5 4 4M19.5 15.5l-4 4" />
            </svg>
            @break
        @case('contact')
            <svg class="h-[22px] w-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21a8 8 0 0 0-16 0" />
                <circle cx="12" cy="7" r="4" />
            </svg>
            @break
        @case('contract')
            <svg class="h-[22px] w-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6.75 3.75h7.5l3 3v13.5H6.75V3.75Z" />
                <path d="M14.25 3.75v3h3" />
                <path d="m9 14 2 2 4-4" />
            </svg>
            @break
        @case('contract-deleted')
            <svg class="h-[22px] w-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6.75 3.75h7.5l3 3v13.5H6.75V3.75Z" />
                <path d="M14.25 3.75v3h3" />
                <path d="m9.5 12.5 5 5M14.5 12.5l-5 5" />
            </svg>
            @break
        @default
            <svg class="h-[22px] w-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 6.75h16M4 12h16M4 17.25h16" />
            </svg>
    @endswitch
</span>
