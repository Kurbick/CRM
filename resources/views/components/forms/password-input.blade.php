@props([
    'name',
    'label',
    'id' => null,
    'autocomplete' => null,
    'required' => false,
    'autofocus' => false,
    'placeholder' => null,
    'errorBag' => 'default',
])

@php
    $inputId = $id ?: $name;
    $error = $errors->getBag($errorBag)->first($name);
    $errorId = $inputId.'-error';
@endphp

<div x-data="{ visible: false }">
    <label for="{{ $inputId }}" class="mb-1 block text-sm font-medium text-gray-700">{{ $label }}</label>
    <div class="relative">
        <input
            {{ $attributes->merge([
                'class' => 'w-full rounded-lg border px-3 py-2.5 pr-11 text-sm outline-none transition '.($error
                    ? 'border-red-300 focus:border-red-500 focus:ring-1 focus:ring-red-500'
                    : 'border-gray-300 focus:border-blue-500 focus:ring-1 focus:ring-blue-500'),
            ]) }}
            id="{{ $inputId }}"
            name="{{ $name }}"
            type="password"
            x-bind:type="visible ? 'text' : 'password'"
            @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
            @if ($required) required @endif
            @if ($autofocus) autofocus @endif
            @if ($error) aria-invalid="true" aria-describedby="{{ $errorId }}" @endif
        >
        <button type="button"
            class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-lg text-gray-400 transition hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500"
            x-on:click="visible = !visible"
            x-bind:aria-label="visible ? 'Скрыть пароль' : 'Показать пароль'"
            x-bind:aria-pressed="visible.toString()">
            <svg x-show="!visible" class="h-5 w-5" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.25 12s3.5-6 9.75-6 9.75 6 9.75 6-3.5 6-9.75 6-9.75-6-9.75-6Z" />
                <circle cx="12" cy="12" r="2.75" stroke-width="1.8" />
            </svg>
            <svg x-show="visible" x-cloak class="h-5 w-5" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3l18 18M10.6 6.1A10.7 10.7 0 0 1 12 6c6.25 0 9.75 6 9.75 6a16.8 16.8 0 0 1-2.3 3.05M6.25 7.2C3.65 9.05 2.25 12 2.25 12S5.75 18 12 18c1.25 0 2.4-.24 3.45-.64M9.95 9.95a2.9 2.9 0 0 0 4.1 4.1" />
            </svg>
        </button>
    </div>
    @if ($error)
        <p id="{{ $errorId }}" class="mt-1 text-xs text-red-600">{{ $error }}</p>
    @endif
</div>
