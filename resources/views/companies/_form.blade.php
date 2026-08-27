<div data-testid="company-form-workspace" class="overflow-hidden border-y border-slate-200 bg-white">
    <section class="px-4 py-5 sm:px-5">
        <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('companies.form.basic_information') }}</h2>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('companies.form.full_name') }} <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="name" value="{{ old('name', $company?->name) }}" required
                    class="w-full @error('name') border-red-300 @else border-gray-200 @enderror"
                    placeholder="{{ __('companies.form.full_name_placeholder') }}">
                @error('name')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="short_name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('companies.form.short_name') }}</label>
                <input type="text" name="short_name" id="short_name" value="{{ old('short_name', $company?->short_name) }}"
                    class="w-full @error('short_name') border-red-300 @else border-gray-200 @enderror"
                    placeholder="{{ __('companies.form.short_name_placeholder') }}">
                @error('short_name')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="type" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('companies.form.counterparty_type') }} <span class="text-red-500">*</span></label>
                <select name="type" id="type" required class="w-full @error('type') border-red-300 @else border-gray-200 @enderror">
                    <option value="company" @selected(old('type', $company?->type ?? 'company') === 'company')>{{ __('companies.form.legal_entity') }}</option>
                    <option value="individual" @selected(old('type', $company?->type) === 'individual')>{{ __('companies.form.individual') }}</option>
                </select>
                @error('type')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="voen" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('companies.form.voen') }}</label>
                <input type="text" name="voen" id="voen" value="{{ old('voen', $company?->voen) }}"
                    class="w-full font-mono @error('voen') border-red-300 @else border-gray-200 @enderror"
                    placeholder="1234567890">
                @error('voen')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('companies.form.email') }}</label>
                <input type="email" name="email" id="email" value="{{ old('email', $company?->email) }}"
                    class="w-full @error('email') border-red-300 @else border-gray-200 @enderror"
                    placeholder="info@client.com">
                @error('email')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="phone" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('companies.form.phone') }}</label>
                <input type="text" name="phone" id="phone" value="{{ old('phone', $company?->phone) }}"
                    class="w-full @error('phone') border-red-300 @else border-gray-200 @enderror"
                    placeholder="+994 (50) 123-45-67">
                @error('phone')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="website" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('companies.form.website') }}</label>
                <input type="text" name="website" id="website" value="{{ old('website', $company?->website) }}"
                    class="w-full @error('website') border-red-300 @else border-gray-200 @enderror"
                    placeholder="https://client.com">
                @error('website')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="status" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('companies.form.status') }} <span class="text-red-500">*</span></label>
                <select name="status" id="status" required class="w-full @error('status') border-red-300 @else border-gray-200 @enderror">
                    <option value="active" @selected(old('status', $company?->status ?? 'active') === 'active')>{{ __('companies.statuses.active') }}</option>
                    <option value="suspended" @selected(old('status', $company?->status) === 'suspended')>{{ __('companies.statuses.suspended') }}</option>
                    <option value="archived" @selected(old('status', $company?->status) === 'archived')>{{ __('companies.statuses.archived') }}</option>
                </select>
                @error('status')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </section>

    <section class="border-t border-slate-200 px-4 py-5 sm:px-5">
        <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('companies.form.contact_data') }}</h2>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="md:col-span-2">
                <label for="legal_address" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('companies.form.legal_address') }}</label>
                <input type="text" name="legal_address" id="legal_address" value="{{ old('legal_address', $company?->legal_address) }}"
                    class="w-full @error('legal_address') border-red-300 @else border-gray-200 @enderror"
                    placeholder="{{ __('companies.form.legal_address_placeholder') }}">
                @error('legal_address')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div class="md:col-span-2">
                <label for="actual_address" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('companies.form.actual_address') }}</label>
                <input type="text" name="actual_address" id="actual_address" value="{{ old('actual_address', $company?->actual_address) }}"
                    class="w-full @error('actual_address') border-red-300 @else border-gray-200 @enderror"
                    placeholder="{{ __('companies.form.actual_address_placeholder') }}">
                @error('actual_address')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </section>

    <section class="border-t border-slate-200 px-4 py-5 sm:px-5">
        <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('companies.form.banking') }}</h2>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="md:col-span-2">
                <label for="bank_name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('companies.form.bank_name') }}</label>
                <input type="text" name="bank_name" id="bank_name" value="{{ old('bank_name', $company?->bank_name) }}"
                    class="w-full @error('bank_name') border-red-300 @else border-gray-200 @enderror"
                    placeholder="Kapital Bank OJSC">
                @error('bank_name')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div class="md:col-span-2">
                <label for="iban" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('companies.form.iban') }}</label>
                <input type="text" name="iban" id="iban" value="{{ old('iban', $company?->iban) }}"
                    class="w-full font-mono @error('iban') border-red-300 @else border-gray-200 @enderror"
                    placeholder="AZ00X00000000000000000000000">
                @error('iban')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="bank_code" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('companies.form.bank_code') }}</label>
                <input type="text" name="bank_code" id="bank_code" value="{{ old('bank_code', $company?->bank_code) }}"
                    class="w-full font-mono @error('bank_code') border-red-300 @else border-gray-200 @enderror"
                    placeholder="123456">
                @error('bank_code')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="bank_voen" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('companies.form.bank_voen') }}</label>
                <input type="text" name="bank_voen" id="bank_voen" value="{{ old('bank_voen', $company?->bank_voen) }}"
                    class="w-full font-mono @error('bank_voen') border-red-300 @else border-gray-200 @enderror"
                    placeholder="9876543210">
                @error('bank_voen')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="swift" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('companies.form.swift') }}</label>
                <input type="text" name="swift" id="swift" value="{{ old('swift', $company?->swift) }}"
                    class="w-full font-mono @error('swift') border-red-300 @else border-gray-200 @enderror"
                    placeholder="KAPBBA22">
                @error('swift')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </section>

    <section class="border-t border-slate-200 px-4 py-5 sm:px-5">
        <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('companies.form.comment') }}</h2>

        <div class="mt-4">
            <label for="comment" class="sr-only">{{ __('companies.form.comment') }}</label>
            <textarea name="comment" id="comment" rows="4"
                class="w-full @error('comment') border-red-300 @else border-gray-200 @enderror"
                placeholder="{{ __('companies.form.comment_placeholder') }}">{{ old('comment', $company?->comment) }}</textarea>
            @error('comment')
                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
            @enderror
        </div>
    </section>

    <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 px-4 py-4 sm:px-5">
        <button type="submit" class="bg-blue-600">{{ __('companies.form.save') }}</button>
        <a href="{{ $cancelUrl }}" class="border border-gray-200">{{ __('companies.form.cancel') }}</a>
    </div>
</div>
