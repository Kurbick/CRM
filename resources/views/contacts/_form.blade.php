@php
    $selectedRole = old('role', $contact?->role);
@endphp

<div data-testid="contact-form-workspace" class="max-w-4xl overflow-hidden border-y border-slate-200 bg-white">
    <section class="px-4 py-5 sm:px-5">
        <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('contacts.sections.basic_information') }}</h2>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="md:col-span-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('contacts.fields.company') }}</p>
                <p class="mt-1 text-sm font-medium text-slate-900">{{ $company->name }}</p>
            </div>

            <div>
                <label for="first_name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('contacts.fields.first_name') }} <span class="text-red-500">*</span></label>
                <input type="text" name="first_name" id="first_name" value="{{ old('first_name', $contact?->first_name) }}" required
                    class="w-full @error('first_name') border-red-300 @else border-gray-200 @enderror">
                @error('first_name')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="last_name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('contacts.fields.last_name') }}</label>
                <input type="text" name="last_name" id="last_name" value="{{ old('last_name', $contact?->last_name) }}"
                    class="w-full @error('last_name') border-red-300 @else border-gray-200 @enderror">
                @error('last_name')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div x-data="{ role: @js($selectedRole) }">
                <label for="role" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('contacts.fields.role') }}</label>
                <select name="role" id="role" x-model="role" class="w-full @error('role') border-red-300 @else border-gray-200 @enderror">
                    <option value="">{{ __('contacts.fields.role_select') }}</option>
                    <option value="director" @selected($selectedRole === 'director')>{{ __('contacts.roles.director') }}</option>
                    <option value="accountant" @selected($selectedRole === 'accountant')>{{ __('contacts.roles.accountant') }}</option>
                    <option value="manager" @selected($selectedRole === 'manager')>{{ __('contacts.roles.manager') }}</option>
                    <option value="technical" @selected($selectedRole === 'technical')>{{ __('contacts.roles.technical') }}</option>
                    <option value="other" @selected($selectedRole === 'other')>{{ __('contacts.roles.other') }}</option>
                </select>
                @error('role')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror

                <div x-show="role === 'other'" x-cloak class="mt-3">
                    <label for="position" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('contacts.fields.position') }}</label>
                    <input type="text" name="position" id="position" value="{{ old('position', $contact?->position) }}" placeholder="{{ __('contacts.fields.position_placeholder') }}"
                        class="w-full @error('position') border-red-300 @else border-gray-200 @enderror">
                    @error('position')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>
    </section>

    <section class="border-t border-slate-200 px-4 py-5 sm:px-5">
        <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('contacts.sections.contact_data') }}</h2>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="phone" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('contacts.fields.phone') }}</label>
                <input type="text" name="phone" id="phone" value="{{ old('phone', $contact?->phone) }}"
                    class="w-full @error('phone') border-red-300 @else border-gray-200 @enderror">
                @error('phone')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('contacts.fields.email') }}</label>
                <input type="email" name="email" id="email" value="{{ old('email', $contact?->email) }}"
                    class="w-full @error('email') border-red-300 @else border-gray-200 @enderror">
                @error('email')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </section>

    <section class="border-t border-slate-200 px-4 py-5 sm:px-5">
        <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('contacts.sections.comment') }}</h2>
        <div class="mt-4">
            <label for="comment" class="sr-only">{{ __('contacts.sections.comment') }}</label>
            <textarea name="comment" id="comment" rows="3" class="w-full @error('comment') border-red-300 @else border-gray-200 @enderror">{{ old('comment', $contact?->comment) }}</textarea>
            @error('comment')
                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
            @enderror
        </div>
    </section>

    <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 px-4 py-4 sm:px-5">
        <button type="submit" class="bg-blue-600">{{ __('contacts.actions.save') }}</button>
        <a href="{{ $cancelUrl }}" class="border border-gray-200">{{ __('contacts.actions.cancel') }}</a>
    </div>

</div>
