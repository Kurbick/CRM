@props([
    'name',
    'id' => null,
    'value' => null,
    'required' => false,
    'disabled' => false,
    'readonly' => false,
    'min' => null,
    'max' => null,
    'dynamicName' => null,
    'dynamicId' => null,
    'dynamicMin' => null,
    'dynamicReadonly' => null,
    'submit' => true,
])

@php
    $fieldId = $id ?: str_replace(['[', ']', '.'], ['_', '', '_'], $name);
    $initialValue = $value instanceof \DateTimeInterface
        ? $value->format('Y-m-d')
        : ($value ? substr((string) $value, 0, 10) : '');
    $errorBag = $errors ?? new \Illuminate\Support\ViewErrorBag();
    $dynamicRequired = $attributes->get(':required') ?? $attributes->get('x-bind:required');
    $dynamicDisabled = $attributes->get(':disabled') ?? $attributes->get('x-bind:disabled');
@endphp

<div
    x-data="{
        iso: @js($initialValue),
        display: '',
        error: '',
        showError: false,
        required: @js((bool) $required),
        disabled: @js((bool) $disabled),
        readonly: @js((bool) $readonly),
        availabilityReady: false,
        minimum: @js($min),
        maximum: @js($max),
        init() {
            this.display = this.toDisplay(this.iso);
            this.$watch('iso', value => {
                if (value || this.toIso(this.display)) this.display = this.toDisplay(value);
                this.validate(false);
            });
        },
        syncAvailability(required, disabled, minimum = null, readonly = false) {
            const becameDisabled = this.availabilityReady && !this.disabled && disabled;
            this.required = required;
            this.disabled = disabled;
            this.minimum = minimum;
            this.readonly = readonly;
            if (becameDisabled) {
                this.iso = '';
                this.display = '';
                this.error = '';
                this.showError = false;
                this.$refs.visible.setCustomValidity('');
            } else if (disabled) {
                this.error = '';
                this.showError = false;
                this.$refs.visible.setCustomValidity('');
            } else {
                this.validate(false);
            }
            this.availabilityReady = true;
        },
        toDisplay(value) {
            const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
            return match ? `${match[3]}/${match[2]}/${match[1]}` : '';
        },
        toIso(value) {
            const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec((value || '').trim());
            if (!match) return '';
            const day = Number(match[1]);
            const month = Number(match[2]);
            const year = Number(match[3]);
            const leap = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
            const daysInMonth = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
            if (month < 1 || month > 12 || day < 1 || day > daysInMonth[month - 1]) return '';
            return `${match[3]}-${match[2]}-${match[1]}`;
        },
        typed(event) {
            this.display = event.target.value;
            const parsed = this.toIso(this.display);
            this.iso = parsed;
            this.validate(this.showError || Boolean(this.display));
        },
        blur() {
            const parsed = this.toIso(this.display);
            if (parsed) this.display = this.toDisplay(parsed);
            this.validate(true);
        },
        invalid(event) {
            event.preventDefault();
            this.validate(true);
            this.$refs.visible.focus();
        },
        validate(show) {
            const message = this.validationMessage(this.toIso(this.display));
            this.error = message;
            this.showError = show && Boolean(message);
            this.$refs.visible.setCustomValidity(message);
        },
        validationMessage(parsed) {
            if (!this.display && this.required) return 'Введите дату.';
            if (this.display && !parsed) return 'Введите корректную дату в формате дд/мм/гггг.';
            if (parsed && this.minimum && parsed < this.minimum) return `Дата должна быть не раньше ${this.toDisplay(this.minimum)}.`;
            if (parsed && this.maximum && parsed > this.maximum) return `Дата должна быть не позже ${this.toDisplay(this.maximum)}.`;
            return '';
        },
        picked(event) {
            this.iso = event.target.value || '';
            this.display = this.toDisplay(this.iso);
            this.validate(false);
            this.$refs.visible.focus();
        }
    }"
    x-modelable="iso"
    x-effect="syncAvailability(
        Boolean({{ $dynamicRequired ?: ($required ? 'true' : 'false') }}),
        Boolean({{ $dynamicDisabled ?: ($disabled ? 'true' : 'false') }}),
        {{ $dynamicMin ?: \Illuminate\Support\Js::from($min) }},
        Boolean({{ $dynamicReadonly ?: ($readonly ? 'true' : 'false') }})
    )"
    {{ $attributes->whereStartsWith('x-model') }}
>
    @if ($submit)
        <input type="hidden" @if ($dynamicName) x-bind:name="disabled ? null : {{ $dynamicName }}" @else name="{{ $name }}" @endif x-model="iso" x-bind:disabled="disabled">
    @endif

    <div class="relative">
        <input
            type="text"
            @if ($dynamicId) x-bind:id="{{ $dynamicId }}" @else id="{{ $fieldId }}" @endif
            x-ref="visible"
            x-model="display"
            x-on:input="typed($event)"
            x-on:blur="blur($event)"
            x-on:invalid="invalid($event)"
            placeholder="дд/мм/гггг"
            inputmode="numeric"
            autocomplete="off"
            x-bind:required="required"
            x-bind:disabled="disabled"
            x-bind:readonly="readonly"
            {{ $attributes->except(['x-model', 'x-data', ':required', 'x-bind:required', ':disabled', 'x-bind:disabled', ':readonly', 'x-bind:readonly'])->class([
                'w-full rounded-lg border px-3 py-2 pr-10 text-sm outline-none transition focus:border-blue-500 focus:ring-1 focus:ring-blue-500',
                'border-red-300' => $errorBag->has($name),
                'border-gray-200' => !$errorBag->has($name),
                'bg-gray-100 text-gray-500' => $disabled || $readonly,
            ]) }}
        >

        @unless ($readonly)
            <button type="button" x-show="!disabled && !readonly" x-on:click="$refs.calendar.showPicker ? $refs.calendar.showPicker() : $refs.calendar.click()"
                class="absolute inset-y-0 right-0 flex items-center justify-center px-3 text-gray-400 transition hover:text-gray-600"
                aria-label="Открыть календарь">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M8 2v4m8-4v4M3 10h18M5 4h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2z" />
                </svg>
            </button>
            <input x-ref="calendar" type="date" :value="iso" x-on:change="picked($event)"
                form="{{ $fieldId }}_detached_calendar"
                x-bind:min="minimum || null" @if ($max) max="{{ $max }}" @endif
                class="pointer-events-none absolute h-px w-px opacity-0" tabindex="-1" aria-hidden="true">
        @endunless
    </div>

    <p x-show="showError" x-cloak x-text="error" class="mt-1 text-xs text-red-500"></p>

    @if ($errorBag->has($name))
        <p class="mt-1 text-xs text-red-500">{{ $errorBag->first($name) }}</p>
    @endif
</div>
