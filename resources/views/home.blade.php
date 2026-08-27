@extends('layouts.app')

@section('title', __('home.title'))

@section('content')
    <div class="mx-auto max-w-3xl">
        <header class="border-b border-slate-200 pb-4">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('home.title') }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ __('home.description') }}</p>
        </header>

        @unless ($hasReadableSection)
            <section data-testid="home-fallback" class="border-b border-slate-200 py-5">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('home.access') }}</h2>
                <p class="mt-3 text-sm text-slate-900">{{ __('home.welcome') }}</p>
                <p class="mt-1 text-sm text-slate-500">
                    {{ __('home.no_sections') }}
                </p>
                <p class="mt-3 text-sm text-slate-500">
                    {{ __('home.admin_guidance') }}
                </p>
            </section>
        @else
            <section data-testid="home-navigation-guidance" class="border-b border-slate-200 py-5">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('home.available_sections') }}</h2>
                <p class="mt-3 text-sm text-slate-500">{{ __('home.navigation_guidance') }}</p>
            </section>
        @endunless
    </div>
@endsection
