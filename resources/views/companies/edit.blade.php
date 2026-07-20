@extends('layouts.app')

@section('title', 'Редактировать компанию')

@section('content')

    <div class="mb-6">
        <a href="{{ route('companies.show', ['company' => $company, 'return_url' => $returnContext['is_contextual'] ? $returnContext['url'] : null]) }}" class="text-sm text-gray-500 hover:text-gray-900 transition flex items-center gap-1.5 mb-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Назад к просмотру
        </a>
        <h1 class="text-2xl font-bold text-gray-900">Редактировать компанию</h1>
        <p class="text-sm text-gray-500 mt-1">Изменение реквизитов и настроек контрагента</p>
    </div>

    <form action="{{ route('companies.update', $company) }}" method="POST" class="space-y-6">
        @csrf
        @method('PUT')
        @if ($returnContext['is_contextual'])
            <input type="hidden" name="return_url" value="{{ $returnContext['url'] }}">
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            {{-- Основная информация --}}
            <div class="lg:col-span-2 space-y-6">
                
                {{-- Карточка: Общие данные --}}
                <div class="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-100">Общие сведения</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        
                        <div>
                            <label for="type" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Тип контрагента <span class="text-red-500">*</span></label>
                            <select name="type" id="type" required
                                    class="w-full px-3 py-2 border @error('type') border-red-300 @else border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                                <option value="company" {{ old('type', $company->type) === 'company' ? 'selected' : '' }}>Юридическое лицо</option>
                                <option value="individual" {{ old('type', $company->type) === 'individual' ? 'selected' : '' }}>Индивидуальный предприниматель</option>
                            </select>
                            @error('type')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="name" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Полное наименование <span class="text-red-500">*</span></label>
                            <input type="text" name="name" id="name" value="{{ old('name', $company->name) }}" required
                                   class="w-full px-3 py-2 border @error('name') border-red-300 @else border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"
                                   placeholder="ООО «Глобал Технолоджис»">
                            @error('name')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="short_name" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Краткое имя (для интерфейса)</label>
                            <input type="text" name="short_name" id="short_name" value="{{ old('short_name', $company->short_name) }}"
                                   class="w-full px-3 py-2 border @error('short_name') border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"
                                   placeholder="Глобал Технолоджис">
                            @error('short_name')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="voen" class="block text-xs font-semibold text-gray-500 uppercase mb-1">VÖEN (ИНН)</label>
                            <input type="text" name="voen" id="voen" value="{{ old('voen', $company->voen) }}"
                                   class="w-full px-3 py-2 border @error('voen') border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition font-mono"
                                   placeholder="1234567890">
                            @error('voen')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                    </div>
                </div>

                {{-- Карточка: Контактные данные --}}
                <div class="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-100">Контакты и адреса</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="email" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Эл. почта</label>
                            <input type="email" name="email" id="email" value="{{ old('email', $company->email) }}"
                                   class="w-full px-3 py-2 border @error('email') border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"
                                   placeholder="info@client.com">
                            @error('email')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="phone" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Телефон</label>
                            <input type="text" name="phone" id="phone" value="{{ old('phone', $company->phone) }}"
                                   class="w-full px-3 py-2 border @error('phone') border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"
                                   placeholder="+994 (50) 123-45-67">
                            @error('phone')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="website" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Сайт</label>
                            <input type="text" name="website" id="website" value="{{ old('website', $company->website) }}"
                                   class="w-full px-3 py-2 border @error('website') border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"
                                   placeholder="https://client.com">
                            @error('website')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="md:col-span-2">
                            <label for="legal_address" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Юридический адрес</label>
                            <input type="text" name="legal_address" id="legal_address" value="{{ old('legal_address', $company->legal_address) }}"
                                   class="w-full px-3 py-2 border @error('legal_address') border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"
                                   placeholder="г. Баку, Насиминский р-н, ул. Низами, д. 100">
                            @error('legal_address')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="md:col-span-2">
                            <label for="actual_address" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Фактический адрес</label>
                            <input type="text" name="actual_address" id="actual_address" value="{{ old('actual_address', $company->actual_address) }}"
                                   class="w-full px-3 py-2 border @error('actual_address') border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"
                                   placeholder="г. Баку, Сабаильский р-н, пр. Нефтяников, д. 45">
                            @error('actual_address')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                {{-- Карточка: Банковские реквизиты --}}
                <div class="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-100">Банковские реквизиты</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label for="bank_name" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Название банка</label>
                            <input type="text" name="bank_name" id="bank_name" value="{{ old('bank_name', $company->bank_name) }}"
                                   class="w-full px-3 py-2 border @error('bank_name') border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"
                                   placeholder="Kapital Bank OJSC">
                            @error('bank_name')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="md:col-span-2">
                            <label for="iban" class="block text-xs font-semibold text-gray-500 uppercase mb-1">IBAN (Расчетный счет)</label>
                            <input type="text" name="iban" id="iban" value="{{ old('iban', $company->iban) }}"
                                   class="w-full px-3 py-2 border @error('iban') border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition font-mono"
                                   placeholder="AZ00X00000000000000000000000">
                            @error('iban')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="bank_code" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Код банка (Kod)</label>
                            <input type="text" name="bank_code" id="bank_code" value="{{ old('bank_code', $company->bank_code) }}"
                                   class="w-full px-3 py-2 border @error('bank_code') border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition font-mono"
                                   placeholder="123456">
                            @error('bank_code')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="bank_voen" class="block text-xs font-semibold text-gray-500 uppercase mb-1">VÖEN/ИНН Банка</label>
                            <input type="text" name="bank_voen" id="bank_voen" value="{{ old('bank_voen', $company->bank_voen) }}"
                                   class="w-full px-3 py-2 border @error('bank_voen') border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition font-mono"
                                   placeholder="9876543210">
                            @error('bank_voen')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="swift" class="block text-xs font-semibold text-gray-500 uppercase mb-1">SWIFT (B.I.C.)</label>
                            <input type="text" name="swift" id="swift" value="{{ old('swift', $company->swift) }}"
                                   class="w-full px-3 py-2 border @error('swift') border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition font-mono"
                                   placeholder="KAPBBA22">
                            @error('swift')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

            </div>

            {{-- Боковая колонка: Статус, настройки и комментарии --}}
            <div class="space-y-6">
                
                {{-- Карточка: Настройки контрагента --}}
                <div class="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-100">Настройки</h2>
                    
                    <div class="space-y-4">
                        <div>
                            <label for="status" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Статус <span class="text-red-500">*</span></label>
                            <select name="status" id="status" required
                                    class="w-full px-3 py-2 border @error('status') border-red-300 @else border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                                <option value="active" {{ old('status', $company->status) === 'active' ? 'selected' : '' }}>Активен</option>
                                <option value="suspended" {{ old('status', $company->status) === 'suspended' ? 'selected' : '' }}>Приостановлен</option>
                                <option value="archived" {{ old('status', $company->status) === 'archived' ? 'selected' : '' }}>В архиве</option>
                            </select>
                            @error('status')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="invoice_mode" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Режим инвойсов <span class="text-red-500">*</span></label>
                            <select name="invoice_mode" id="invoice_mode" required
                                    class="w-full px-3 py-2 border @error('invoice_mode') border-red-300 @else border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition">
                                <option value="separate" {{ old('invoice_mode', $company->invoice_mode) === 'separate' ? 'selected' : '' }}>Раздельный (по каждому заказу)</option>
                                <option value="consolidated" {{ old('invoice_mode', $company->invoice_mode) === 'consolidated' ? 'selected' : '' }}>Сводный (один за месяц)</option>
                            </select>
                            @error('invoice_mode')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                {{-- Карточка: Заметки --}}
                <div class="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-100">Примечания</h2>
                    
                    <div>
                        <label for="comment" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Комментарий</label>
                        <textarea name="comment" id="comment" rows="6"
                                  class="w-full px-3 py-2 border @error('comment') border-gray-200 @enderror rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition resize-none"
                                  placeholder="Дополнительная информация о клиенте...">{{ old('comment', $company->comment) }}</textarea>
                        @error('comment')
                            <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                {{-- Кнопки действий --}}
                <div class="flex flex-col gap-2">
                    <button type="submit"
                            class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg text-sm transition shadow-sm">
                        Сохранить изменения
                    </button>
                    <a href="{{ route('companies.show', ['company' => $company, 'return_url' => $returnContext['is_contextual'] ? $returnContext['url'] : null]) }}"
                       class="w-full py-2.5 border border-gray-200 hover:bg-gray-50 text-gray-700 font-medium rounded-lg text-sm transition text-center">
                        Отмена
                    </a>
                </div>

            </div>

        </div>
    </form>

@endsection
