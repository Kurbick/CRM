@extends('layouts.app')

@section('title', 'Договор ' . $contract->contract_number)

@section('content')

    @php
        $periods = [
            'monthly' => 'Ежемесячно',
            'quarterly' => 'Ежеквартально',
            'semiannual' => 'Раз в полгода',
            'annual' => 'Ежегодно',
            'custom' => 'Другой период',
        ];

        $documentTypes = [
            'original' => 'Исходник',
            'signed' => 'Подписанный договор',
            'other' => 'Другой документ',
        ];

        $services = collect();

        foreach ($contract->orders as $order) {
            $services->push([
                'id' => $order->id,
                'type' => 'order',
                'type_name' => 'Разовая',
                'service_name' => $order->title ?? ($order->serviceType?->name ?? 'Услуга не указана'),
                'date' => $order->order_date,
                'period' => null,
                'amount' => $order->price,
                'status' => $order->status,
                'edit_route' => route('orders.edit', $order),
            ]);
        }

        foreach ($contract->subscriptions as $subscription) {
            $services->push([
                'id' => $subscription->id,
                'type' => 'subscription',
                'type_name' => 'Подписка',
                'service_name' => $subscription->title ?? ($subscription->serviceType?->name ?? 'Услуга не указана'),
                'date' => $subscription->start_date,
                'period' =>
                    $subscription->billing_period === 'custom'
                        ? $subscription->billing_period_custom ?? 'Другой период'
                        : $periods[$subscription->billing_period] ?? $subscription->billing_period,
                'amount' => $subscription->amount,
                'status' => $subscription->status,
                'edit_route' => route('subscriptions.edit', $subscription),
            ]);
        }

        $services = $services->sortByDesc('date');
    @endphp

    {{-- Заголовок страницы --}}
    <div class="mb-6">
        <a href="{{ route('contracts.index') }}"
            class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 transition">
            ← Назад к договорам
        </a>

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mt-3">
            <h1 class="text-2xl font-bold text-gray-900 font-mono">
                {{ $contract->contract_number }}
            </h1>

            <div class="flex items-center gap-3">
                @include('partials.badge', [
                    'status' => $contract->effective_status,
                ])

                <a href="{{ route('contracts.edit', $contract) }}"
                    class="text-sm border border-gray-200 hover:bg-gray-50 text-gray-600
                          px-4 py-2 rounded-lg transition">
                    Редактировать
                </a>
            </div>
        </div>
    </div>

    {{-- Основная информация --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6">
        <div class="px-6 py-4 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800">
                Основная информация
            </h2>
        </div>

        <div class="px-6 py-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <div>
                    <p class="text-xs font-semibold text-gray-400 uppercase">
                        Компания
                    </p>

                    <a href="{{ route('companies.show', $contract->company) }}"
                        class="inline-block mt-2 text-sm font-medium text-blue-600
                              hover:text-blue-800 hover:underline transition">
                        {{ $contract->company->name }}
                    </a>
                </div>

                <div>
                    <p class="text-xs font-semibold text-gray-400 uppercase">
                        Дата начала
                    </p>

                    <p class="mt-2 text-sm text-gray-700">
                        {{ \Carbon\Carbon::parse($contract->start_date)->format('d.m.Y') }}
                    </p>
                </div>

                <div>
                    <p class="text-xs font-semibold text-gray-400 uppercase">
                        Дата окончания
                    </p>

                    <p class="mt-2 text-sm text-gray-700">
                        @if ($contract->end_date)
                            {{ \Carbon\Carbon::parse($contract->end_date)->format('d.m.Y') }}
                        @else
                            Бессрочный
                        @endif
                    </p>
                </div>
            </div>

            @if (filled($contract->comment))
                <div class="mt-5 pt-5 border-t border-gray-100">
                    <p class="text-xs font-semibold text-gray-400 uppercase">
                        Комментарий
                    </p>

                    <p class="mt-2 text-sm text-gray-700 whitespace-pre-line">
                        {{ $contract->comment }}
                    </p>
                </div>
            @endif
        </div>
    </div>

    {{-- Документы договора --}}
    <div x-data="{
        uploadOpen: {{ $errors->has('document') || $errors->has('document_type') || $errors->has('comment') ? 'true' : 'false' }}
    }" class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6">
        {{-- Заголовок блока --}}
        <div class="px-6 py-5 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h2 class="font-semibold text-gray-800">
                    Документы договора
                </h2>

                <p class="text-sm text-gray-500 mt-1">
                    Прикреплённых файлов: {{ $contract->documents->count() }}
                </p>
            </div>

            <button type="button" @click="uploadOpen = !uploadOpen"
                class="inline-flex items-center justify-center text-sm font-medium
                       border border-gray-200 hover:bg-gray-50 text-gray-700
                       px-4 py-2.5 rounded-lg transition">
                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>

                <span x-text="uploadOpen ? 'Скрыть форму' : 'Загрузить документ'">
                    Загрузить документ
                </span>
            </button>
        </div>

        {{-- Форма загрузки --}}
        <div x-show="uploadOpen" x-cloak class="px-6 py-5 bg-gray-50/50 border-b border-gray-100">
            <form action="{{ route('contracts.documents.store', $contract) }}" method="POST"
                enctype="multipart/form-data">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                    {{-- Тип документа --}}
                    <div class="md:col-span-4">
                        <label for="document_type" class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                            Тип документа <span class="text-red-500">*</span>
                        </label>

                        <select id="document_type" name="document_type"
                            class="w-full px-3 py-2.5 border border-gray-200 rounded-lg
                                   text-sm bg-white focus:border-blue-500
                                   focus:ring-1 focus:ring-blue-500 outline-none transition"
                            required>
                            <option value="original" @selected(old('document_type') === 'original')>
                                Исходник договора
                            </option>

                            <option value="signed" @selected(old('document_type', 'signed') === 'signed')>
                                Подписанный договор
                            </option>

                            <option value="other" @selected(old('document_type') === 'other')>
                                Другой документ
                            </option>
                        </select>

                        @error('document_type')
                            <p class="text-xs text-red-500 mt-1">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    {{-- Файл --}}
                    <div class="md:col-span-8">
                        <label for="document" class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                            Файл <span class="text-red-500">*</span>
                        </label>

                        <input id="document" type="file" name="document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                            class="block w-full border border-gray-200 rounded-lg
                                   bg-white text-sm text-gray-600
                                   file:mr-4 file:border-0 file:border-r
                                   file:border-gray-200 file:bg-gray-50
                                   file:px-4 file:py-2.5 file:text-sm
                                   file:font-medium file:text-gray-700
                                   hover:file:bg-gray-100 transition"
                            required>

                        <p class="text-xs text-gray-400 mt-1">
                            PDF, DOC, DOCX, JPG или PNG. Максимальный размер — 10 МБ.
                        </p>

                        @error('document')
                            <p class="text-xs text-red-500 mt-1">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    {{-- Комментарий --}}
                    <div class="md:col-span-12">
                        <label for="document_comment" class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                            Комментарий
                        </label>

                        <input id="document_comment" type="text" name="comment" value="{{ old('comment') }}"
                            maxlength="1000" placeholder="Например: подписанная версия от 16.07.2026"
                            class="w-full px-3 py-2.5 border border-gray-200 rounded-lg
                                   text-sm bg-white focus:border-blue-500
                                   focus:ring-1 focus:ring-blue-500 outline-none transition">

                        @error('comment')
                            <p class="text-xs text-red-500 mt-1">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                </div>

                <div class="flex items-center gap-3 mt-4">
                    <button type="submit"
                        class="bg-blue-600 hover:bg-blue-700 text-white text-sm
                               font-medium px-5 py-2.5 rounded-lg transition">
                        Загрузить
                    </button>

                    <button type="button" @click="uploadOpen = false"
                        class="px-5 py-2.5 border border-gray-200 text-gray-600
                               text-sm font-medium rounded-lg hover:bg-gray-50 transition">
                        Отмена
                    </button>
                </div>
            </form>
        </div>

        {{-- Список документов --}}
        @if ($contract->documents->isNotEmpty())
            <div class="divide-y divide-gray-100">
                @foreach ($contract->documents as $document)
                    @php
                        if ($document->file_size) {
                            $documentSize =
                                $document->file_size >= 1048576
                                    ? number_format($document->file_size / 1048576, 2) . ' МБ'
                                    : number_format($document->file_size / 1024, 0) . ' КБ';
                        } else {
                            $documentSize = null;
                        }
                    @endphp

                    <div class="px-6 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div class="flex items-start gap-3 min-w-0">
                            {{-- Иконка файла --}}
                            <div
                                class="flex-shrink-0 w-10 h-10 rounded-lg bg-blue-50
                                        text-blue-600 flex items-center justify-center">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M14 2H6a2 2 0 0 0-2 2v16a2 2
                                                             0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M14 2v6h6" />
                                </svg>
                            </div>

                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate">
                                    {{ $document->original_name }}
                                </p>

                                <div class="flex flex-wrap items-center gap-2 mt-1">
                                    <span
                                        class="inline-flex px-2 py-0.5 rounded-full
                                                 bg-gray-100 text-gray-600 text-xs font-medium">
                                        {{ $documentTypes[$document->document_type] ?? 'Другой документ' }}
                                    </span>

                                    @if ($documentSize)
                                        <span class="text-xs text-gray-400">
                                            {{ $documentSize }}
                                        </span>
                                    @endif

                                    <span class="text-xs text-gray-400">
                                        {{ $document->created_at->format('d.m.Y H:i') }}
                                    </span>
                                </div>

                                @if ($document->comment)
                                    <p class="text-xs text-gray-500 mt-2">
                                        {{ $document->comment }}
                                    </p>
                                @endif
                            </div>
                        </div>

                        {{-- Действия --}}
                        <div class="flex items-center gap-3 flex-shrink-0 sm:ml-4">
                            <a href="{{ route('contract-documents.download', $document) }}"
                                class="inline-flex items-center text-sm font-medium
                                       text-blue-600 hover:text-blue-800 transition">
                                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 0 0 2 2h12a2
                                                             2 0 0 0 2-2v-2M8 12l4 4
                                                             4-4m-4 4V4" />
                                </svg>

                                Скачать
                            </a>

                            <form action="{{ route('contract-documents.destroy', $document) }}" method="POST"
                                onsubmit="return confirm('Удалить этот документ?')">
                                @csrf
                                @method('DELETE')

                                <button type="submit"
                                    class="text-sm font-medium text-red-500
                                           hover:text-red-700 transition">
                                    Удалить
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Предмет договора --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div
            class="px-6 py-5 border-b border-gray-100
                    flex flex-col sm:flex-row sm:items-center
                    sm:justify-between gap-4">

            <div>
                <h2 class="font-semibold text-gray-800">
                    Предмет договора
                </h2>

                <p class="text-sm text-gray-500 mt-1">
                    Разовых: {{ $contract->orders->count() }}

                    <span class="mx-1 text-gray-300">•</span>

                    Подписок: {{ $contract->subscriptions->count() }}
                </p>
            </div>

            <a href="{{ route('contracts.subjects.create', $contract) }}"
                class="inline-flex items-center justify-center text-sm font-medium
                      bg-blue-600 hover:bg-blue-700 text-white
                      px-4 py-2.5 rounded-lg transition">
                + Добавить
            </a>
        </div>

        @if ($services->isNotEmpty())
            {{-- Таблица услуг --}}
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50 text-left">
                            <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">
                                Услуга
                            </th>

                            <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">
                                Тип
                            </th>

                            <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">
                                Дата
                            </th>

                            <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">
                                Период
                            </th>

                            <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">
                                Сумма
                            </th>

                            <th class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">
                                Статус
                            </th>

                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @foreach ($services as $service)
                            <tr class="hover:bg-gray-50/50 transition">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-gray-900">
                                        {{ $service['service_name'] }}
                                    </div>
                                </td>

                                <td class="px-6 py-4">
                                    @if ($service['type'] === 'order')
                                        <span
                                            class="inline-flex px-2.5 py-1 rounded-full
                                                     text-xs font-medium
                                                     bg-gray-100 text-gray-700">
                                            Разовая
                                        </span>
                                    @else
                                        <span
                                            class="inline-flex px-2.5 py-1 rounded-full
                                                     text-xs font-medium
                                                     bg-purple-100 text-purple-700">
                                            Подписка
                                        </span>
                                    @endif
                                </td>

                                <td class="px-6 py-4 text-gray-600">
                                    @if ($service['date'])
                                        {{ \Carbon\Carbon::parse($service['date'])->format('d.m.Y') }}
                                    @else
                                        —
                                    @endif
                                </td>

                                <td class="px-6 py-4 text-gray-600">
                                    {{ $service['period'] ?? '—' }}
                                </td>

                                <td class="px-6 py-4 font-mono font-medium text-gray-900">
                                    {{ number_format((float) $service['amount'], 2) }} ₼
                                </td>

                                <td class="px-6 py-4">
                                    @include('partials.badge', [
                                        'status' => $service['status'],
                                    ])
                                </td>

                                <td class="px-6 py-4 text-right">
                                    <a href="{{ $service['edit_route'] }}"
                                        class="text-gray-400 hover:text-blue-600
                                              text-xs transition">
                                        Редактировать
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            {{-- Пустое состояние --}}
            <div class="px-6 py-14 text-center">
                <div
                    class="mx-auto w-14 h-14 rounded-xl border-2 border-gray-200
                            flex items-center justify-center text-gray-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor" stroke-width="1.5">

                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.75h16.5m-15 0 1.5-4.5h10.5
                                                 l1.5 4.5m-13.5 0v7.5a1.5 1.5 0 0 0
                                                 1.5 1.5h10.5a1.5 1.5 0 0 0
                                                 1.5-1.5v-7.5m-9 4.5h3" />
                    </svg>
                </div>

                <h3 class="mt-5 text-base font-semibold text-gray-900">
                    Предмет договора пока не добавлен
                </h3>

                <p class="mt-2 text-sm text-gray-500">
                    Добавьте разовую услугу или подписку.
                </p>
            </div>
        @endif
    </div>

@endsection
