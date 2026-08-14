@php
    $selectedRole = old('role', $contact?->role);
@endphp

<div data-testid="contact-form-workspace" class="max-w-4xl overflow-hidden border-y border-slate-200 bg-white">
    <section class="px-4 py-5 sm:px-5">
        <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Основная информация</h2>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="md:col-span-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Компания</p>
                <p class="mt-1 text-sm font-medium text-slate-900">{{ $company->name }}</p>
            </div>

            <div>
                <label for="first_name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Имя <span class="text-red-500">*</span></label>
                <input type="text" name="first_name" id="first_name" value="{{ old('first_name', $contact?->first_name) }}" required
                    class="w-full @error('first_name') border-red-300 @else border-gray-200 @enderror">
                @error('first_name')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="last_name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Фамилия</label>
                <input type="text" name="last_name" id="last_name" value="{{ old('last_name', $contact?->last_name) }}"
                    class="w-full @error('last_name') border-red-300 @else border-gray-200 @enderror">
                @error('last_name')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div x-data="{ role: @js($selectedRole) }">
                <label for="role" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Роль</label>
                <select name="role" id="role" x-model="role" class="w-full @error('role') border-red-300 @else border-gray-200 @enderror">
                    <option value="">— Выберите —</option>
                    <option value="director" @selected($selectedRole === 'director')>Директор</option>
                    <option value="accountant" @selected($selectedRole === 'accountant')>Бухгалтер</option>
                    <option value="manager" @selected($selectedRole === 'manager')>Менеджер</option>
                    <option value="technical" @selected($selectedRole === 'technical')>Технический специалист</option>
                    <option value="other" @selected($selectedRole === 'other')>Другое</option>
                </select>
                @error('role')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror

                <div x-show="role === 'other'" x-cloak class="mt-3">
                    <label for="position" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Должность</label>
                    <input type="text" name="position" id="position" value="{{ old('position', $contact?->position) }}" placeholder="Укажите вручную..."
                        class="w-full @error('position') border-red-300 @else border-gray-200 @enderror">
                    @error('position')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>
    </section>

    <section class="border-t border-slate-200 px-4 py-5 sm:px-5">
        <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Контактные данные</h2>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="phone" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Телефон</label>
                <input type="text" name="phone" id="phone" value="{{ old('phone', $contact?->phone) }}"
                    class="w-full @error('phone') border-red-300 @else border-gray-200 @enderror">
                @error('phone')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Email</label>
                <input type="email" name="email" id="email" value="{{ old('email', $contact?->email) }}"
                    class="w-full @error('email') border-red-300 @else border-gray-200 @enderror">
                @error('email')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </section>

    <section class="border-t border-slate-200 px-4 py-5 sm:px-5">
        <h2 class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Комментарий</h2>
        <div class="mt-4">
            <label for="comment" class="sr-only">Комментарий</label>
            <textarea name="comment" id="comment" rows="3" class="w-full @error('comment') border-red-300 @else border-gray-200 @enderror">{{ old('comment', $contact?->comment) }}</textarea>
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
