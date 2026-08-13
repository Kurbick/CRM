<div data-testid="company-form-workspace" class="overflow-hidden border-y border-slate-200 bg-white">
    <section class="px-4 py-5 sm:px-5">
        <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Основная информация</h2>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Полное наименование <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="name" value="{{ old('name', $company?->name) }}" required
                    class="w-full @error('name') border-red-300 @else border-gray-200 @enderror"
                    placeholder="ООО «Глобал Технолоджис»">
                @error('name')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="short_name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Краткое имя</label>
                <input type="text" name="short_name" id="short_name" value="{{ old('short_name', $company?->short_name) }}"
                    class="w-full @error('short_name') border-red-300 @else border-gray-200 @enderror"
                    placeholder="Глобал Технолоджис">
                @error('short_name')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="type" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Тип контрагента <span class="text-red-500">*</span></label>
                <select name="type" id="type" required class="w-full @error('type') border-red-300 @else border-gray-200 @enderror">
                    <option value="company" @selected(old('type', $company?->type ?? 'company') === 'company')>Юридическое лицо</option>
                    <option value="individual" @selected(old('type', $company?->type) === 'individual')>Индивидуальный предприниматель</option>
                </select>
                @error('type')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="voen" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">VÖEN (ИНН)</label>
                <input type="text" name="voen" id="voen" value="{{ old('voen', $company?->voen) }}"
                    class="w-full font-mono @error('voen') border-red-300 @else border-gray-200 @enderror"
                    placeholder="1234567890">
                @error('voen')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">E-mail</label>
                <input type="email" name="email" id="email" value="{{ old('email', $company?->email) }}"
                    class="w-full @error('email') border-red-300 @else border-gray-200 @enderror"
                    placeholder="info@client.com">
                @error('email')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="phone" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Телефон</label>
                <input type="text" name="phone" id="phone" value="{{ old('phone', $company?->phone) }}"
                    class="w-full @error('phone') border-red-300 @else border-gray-200 @enderror"
                    placeholder="+994 (50) 123-45-67">
                @error('phone')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="website" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Сайт</label>
                <input type="text" name="website" id="website" value="{{ old('website', $company?->website) }}"
                    class="w-full @error('website') border-red-300 @else border-gray-200 @enderror"
                    placeholder="https://client.com">
                @error('website')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="status" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Статус <span class="text-red-500">*</span></label>
                <select name="status" id="status" required class="w-full @error('status') border-red-300 @else border-gray-200 @enderror">
                    <option value="active" @selected(old('status', $company?->status ?? 'active') === 'active')>Активна</option>
                    <option value="suspended" @selected(old('status', $company?->status) === 'suspended')>Приостановлена</option>
                    <option value="archived" @selected(old('status', $company?->status) === 'archived')>В архиве</option>
                </select>
                @error('status')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </section>

    <section class="border-t border-slate-200 px-4 py-5 sm:px-5">
        <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Контактные данные</h2>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="md:col-span-2">
                <label for="legal_address" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Юридический адрес</label>
                <input type="text" name="legal_address" id="legal_address" value="{{ old('legal_address', $company?->legal_address) }}"
                    class="w-full @error('legal_address') border-red-300 @else border-gray-200 @enderror"
                    placeholder="г. Баку, Насиминский р-н, ул. Низами, д. 100">
                @error('legal_address')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div class="md:col-span-2">
                <label for="actual_address" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Фактический адрес</label>
                <input type="text" name="actual_address" id="actual_address" value="{{ old('actual_address', $company?->actual_address) }}"
                    class="w-full @error('actual_address') border-red-300 @else border-gray-200 @enderror"
                    placeholder="г. Баку, Сабаильский р-н, пр. Нефтяников, д. 45">
                @error('actual_address')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </section>

    <section class="border-t border-slate-200 px-4 py-5 sm:px-5">
        <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Банковские реквизиты</h2>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="md:col-span-2">
                <label for="bank_name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Название банка</label>
                <input type="text" name="bank_name" id="bank_name" value="{{ old('bank_name', $company?->bank_name) }}"
                    class="w-full @error('bank_name') border-red-300 @else border-gray-200 @enderror"
                    placeholder="Kapital Bank OJSC">
                @error('bank_name')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div class="md:col-span-2">
                <label for="iban" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">IBAN (расчётный счёт)</label>
                <input type="text" name="iban" id="iban" value="{{ old('iban', $company?->iban) }}"
                    class="w-full font-mono @error('iban') border-red-300 @else border-gray-200 @enderror"
                    placeholder="AZ00X00000000000000000000000">
                @error('iban')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="bank_code" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Код банка (Kod)</label>
                <input type="text" name="bank_code" id="bank_code" value="{{ old('bank_code', $company?->bank_code) }}"
                    class="w-full font-mono @error('bank_code') border-red-300 @else border-gray-200 @enderror"
                    placeholder="123456">
                @error('bank_code')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="bank_voen" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">VÖEN банка</label>
                <input type="text" name="bank_voen" id="bank_voen" value="{{ old('bank_voen', $company?->bank_voen) }}"
                    class="w-full font-mono @error('bank_voen') border-red-300 @else border-gray-200 @enderror"
                    placeholder="9876543210">
                @error('bank_voen')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="swift" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">SWIFT (B.I.C.)</label>
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
        <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Комментарий</h2>

        <div class="mt-4">
            <label for="comment" class="sr-only">Комментарий</label>
            <textarea name="comment" id="comment" rows="4"
                class="w-full @error('comment') border-red-300 @else border-gray-200 @enderror"
                placeholder="Дополнительная информация о компании…">{{ old('comment', $company?->comment) }}</textarea>
            @error('comment')
                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
            @enderror
        </div>
    </section>

    <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 px-4 py-4 sm:px-5">
        <button type="submit" class="bg-blue-600">Сохранить</button>
        <a href="{{ $cancelUrl }}" class="border border-gray-200">Отмена</a>
    </div>
</div>
