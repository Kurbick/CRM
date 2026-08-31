@extends('layouts.app')

@section('title', __('contracts.show.title', ['number' => $contract->contract_number]))

@section('content')

    @php
        $displayDateTime = app(\App\Support\DisplayDateTime::class);
        $periods = [
            'monthly' => __('subscriptions.monthly'),
            'quarterly' => __('subscriptions.quarterly'),
            'semiannual' => __('subscriptions.semiannual'),
            'annual' => __('subscriptions.annual'),
            'custom' => __('contracts.show.other_period'),
        ];

        $documentTypes = [
            'original' => __('contracts.documents.original'),
            'signed' => __('contracts.documents.signed'),
            'other' => __('contracts.documents.other'),
        ];

        $contractStatus = match ($contract->effective_status) {
            'active' => [
                'label' => __('contracts.statuses.active'),
                'dot' => 'bg-green-500',
                'text' => 'text-green-700',
            ],
            'terminated' => [
                'label' => __('contracts.statuses.terminated'),
                'dot' => 'bg-red-500',
                'text' => 'text-red-700',
            ],
            'expired' => [
                'label' => __('contracts.statuses.expired'),
                'dot' => 'bg-red-500',
                'text' => 'text-red-700',
            ],
            default => [
                'label' => $contract->effective_status,
                'dot' => 'bg-slate-400',
                'text' => 'text-slate-600',
            ],
        };

        $services = collect();

        foreach ($contract->orders as $order) {
            $services->push([
                'id' => $order->id,
                'type' => 'order',
                'type_name' => __('contracts.subjects.order_type'),
                'service_name' => $order->title ?? ($order->serviceType?->name ?? __('contracts.show.service_not_specified')),
                'date' => $order->order_date,
                'period' => null,
                'amount' => $order->price,
                'status' => $order->status,
                'edit_route' => route('orders.edit', $order),
                'subject' => $order,
                'can_delete' => ! $order->invoice_lines_exists,
            ]);
        }

        foreach ($contract->subscriptions as $subscription) {
            $services->push([
                'id' => $subscription->id,
                'type' => 'subscription',
                'type_name' => __('contracts.subjects.subscription_type'),
                'service_name' => $subscription->title ?? ($subscription->serviceType?->name ?? __('contracts.show.service_not_specified')),
                'date' => $subscription->start_date,
                'period' =>
                    $subscription->billing_period === 'custom'
                        ? ($subscription->custom_interval_value && $subscription->custom_interval_unit
                            ? $subscription->custom_interval_value.' '.match ($subscription->custom_interval_unit) {
                                'day' => __('contracts.show.day_short'),
                                'month' => __('contracts.show.month_short'),
                                'year' => __('contracts.show.year_short'),
                            }
                            : __('contracts.show.interval_not_set'))
                        : $periods[$subscription->billing_period] ?? $subscription->billing_period,
                'amount' => $subscription->amount,
                'status' => $subscription->status,
                'edit_route' => route('subscriptions.edit', $subscription),
                'subject' => $subscription,
                'can_delete' => ! $subscription->invoice_lines_exists,
            ]);
        }

        $services = $services->sortByDesc('date');
    @endphp

    {{-- Компактный заголовок договора --}}
    <div data-testid="contract-entity-header" class="mb-5">
        @php
            $canReturnToCompany = $companyContext['active'];
        @endphp
        <a href="{{ $canReturnToCompany ? $companyContext['company_url'] : route('contracts.index') }}"
            class="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 transition hover:text-slate-900">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            {{ $canReturnToCompany ? $companyContext['label'] : __('contracts.actions.back_to_contracts') }}
        </a>

        <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="truncate font-mono text-xl font-semibold leading-tight text-slate-900">
                        {{ $contract->contract_number }}
                    </h1>
                    @include('partials.badge', [
                        'status' => $contract->effective_status,
                        'label' => $contractStatus['label'],
                    ])
                </div>

                <div class="mt-1 text-xs text-slate-500">
                    @can('view', $contract->company)
                        <a href="{{ route('companies.show', $contract->company) }}"
                            class="font-medium text-blue-600 transition hover:text-blue-800 hover:underline">
                            {{ $contract->company->name }}
                        </a>
                    @else
                        <span>{{ $contract->company->name }}</span>
                    @endcan
                </div>
                @if ($organization)
                    <div class="mt-2 text-xs text-slate-500"><span class="font-medium text-slate-600">{{ __('organizations.form.issuer') }}:</span> {{ $organization->name }}</div>
                @endif
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-1">
                @if ($contract->status === 'active' && $contract->company->status === 'active')
                    @can(\App\Support\Access\PermissionName::InvoicesCreate->value)
                        <a href="{{ route('invoices.create', ['contract_id' => $contract]) }}" class="crm-light-action">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                            </svg>
                            {{ __('contracts.actions.create_invoice') }}
                        </a>
                    @endcan
                @endif
                @can('update', $contract)
                    @if ($contract->status === 'active' && $contract->company->status === 'active' && auth()->user()?->can(\App\Support\Access\PermissionName::InvoicesCreate->value))
                        <span aria-hidden="true" class="text-slate-300">|</span>
                    @endif
                    <a href="{{ route('contracts.edit', ['contract' => $contract, 'edit_origin' => 'show', ...$companyContext['query']]) }}"
                        class="crm-light-action">
                        {{ __('contracts.actions.edit') }}
                    </a>
                @endcan

                @can('delete', $contract)
                    @if ($contractCanBeDeleted)
                        <form action="{{ route('contracts.destroy', $contract) }}" method="POST"
                            onsubmit="return confirm('{{ __('contracts.actions.delete_confirm') }}')">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                class="inline-flex items-center rounded px-1.5 py-1 text-xs font-semibold text-red-600 transition hover:bg-red-50 hover:text-red-700">
                                {{ __('contracts.actions.delete') }}
                            </button>
                        </form>
                    @endif
                @endcan
            </div>
        </div>
    </div>

    {{-- Жизненный цикл договора --}}
    <section data-testid="contract-lifecycle" class="mb-5 border-y border-slate-200 bg-white">
        <div class="grid gap-3 px-4 py-3 sm:grid-cols-[auto_minmax(2rem,1fr)_auto_minmax(2rem,1fr)_auto] sm:items-center">
            <div>
                <p class="text-sm font-semibold tabular-nums text-slate-900">
                    {{ \Carbon\Carbon::parse($contract->start_date)->format('d/m/Y') }}
                </p>
                <p class="mt-0.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ __('contracts.show.start_date') }}</p>
            </div>

            <div aria-hidden="true" class="hidden h-px bg-slate-200 sm:block"></div>

            <div data-testid="contract-lifecycle-status"
                class="flex items-center gap-1.5 text-xs font-semibold {{ $contractStatus['text'] }} sm:justify-center">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 sm:hidden">{{ __('contracts.show.status') }}</span>
                <span aria-hidden="true" class="h-1.5 w-1.5 rounded-full {{ $contractStatus['dot'] }}"></span>
                <span>{{ $contractStatus['label'] }}</span>
            </div>

            <div aria-hidden="true" class="hidden h-px bg-slate-200 sm:block"></div>

            <div class="sm:text-right">
                <p class="text-sm font-semibold tabular-nums text-slate-900">
                    {{ $contract->end_date ? \Carbon\Carbon::parse($contract->end_date)->format('d/m/Y') : __('contracts.show.indefinite') }}
                </p>
                @if ($contract->end_date)
                    <p class="mt-0.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ __('contracts.show.end_date') }}</p>
                @endif
            </div>
        </div>
    </section>

    {{-- Рабочая область договора --}}
    <div data-testid="contract-workspace" data-layout="full" class="flex min-w-0 flex-col gap-5">

            {{-- Документы --}}
            @if ($canUploadDocuments || $canReadDocumentMetadata)
                <section data-testid="contract-documents" x-data="{
            uploadOpen: {{ $errors->has('document') || $errors->has('document_type') || $errors->has('comment') ? 'true' : 'false' }}
                }" class="order-3 overflow-hidden border-y border-slate-200 bg-white">
                    <div class="flex flex-col gap-2 border-b border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <h2 class="text-sm font-semibold text-slate-900">{{ __('contracts.documents.title') }}</h2>

                            @if ($canReadDocumentMetadata)
                                <p class="text-xs tabular-nums text-slate-500">{{ __('contracts.show.documents_count', ['count' => $contract->documents->count()]) }}</p>
                            @endif
                        </div>

                        @can('create', [\App\Models\ContractDocument::class, $contract])
                            <button type="button" @click="uploadOpen = !uploadOpen" class="crm-light-action">
                                <span x-text="uploadOpen ? @js(__('contracts.documents.hide_form')) : @js(__('contracts.documents.upload'))">
                                    {{ __('contracts.documents.upload') }}
                                </span>
                            </button>
                        @endcan
                    </div>

        {{-- Форма загрузки --}}
                    @can('create', [\App\Models\ContractDocument::class, $contract])
                        <div x-show="uploadOpen" x-cloak class="border-b border-slate-200 bg-slate-50/70 px-4 py-4">
                            <form action="{{ route('contracts.documents.store', $contract) }}" method="POST"
                enctype="multipart/form-data">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                    {{-- Тип документа --}}
                    <div class="md:col-span-4">
                        <label for="document_type" class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                            {{ __('contracts.documents.type') }} <span class="text-red-500">*</span>
                        </label>

                        <select id="document_type" name="document_type"
                            class="w-full px-3 py-2.5 border border-gray-200 rounded-lg
                                   text-sm bg-white focus:border-blue-500
                                   focus:ring-1 focus:ring-blue-500 outline-none transition"
                            required>
                            <option value="original" @selected(old('document_type') === 'original')>
                                {{ __('contracts.documents.original') }}
                            </option>

                            <option value="signed" @selected(old('document_type', 'signed') === 'signed')>
                                {{ __('contracts.documents.signed') }}
                            </option>

                            <option value="other" @selected(old('document_type') === 'other')>
                                {{ __('contracts.documents.other') }}
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
                            {{ __('contracts.documents.file') }} <span class="text-red-500">*</span>
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
                            {{ __('contracts.documents.help') }}
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
                            {{ __('contracts.fields.comment') }}
                        </label>

                        <input id="document_comment" type="text" name="comment" value="{{ old('comment') }}"
                            maxlength="1000" placeholder="{{ __('contracts.documents.comment_placeholder') }}"
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
                        {{ __('contracts.documents.upload_action') }}
                    </button>

                    <button type="button" @click="uploadOpen = false"
                        class="px-5 py-2.5 border border-gray-200 text-gray-600
                               text-sm font-medium rounded-lg hover:bg-gray-50 transition">
                        {{ __('contracts.actions.cancel') }}
                    </button>
                </div>
                            </form>
                        </div>
                    @endcan

                    {{-- Список документов --}}
                    @if ($canReadDocumentMetadata)
                        @if ($contract->documents->isNotEmpty())
                            <div class="crm-table-scroll">
                                <table class="crm-table min-w-[720px] table-fixed">
                                    <colgroup>
                                        <col class="w-[36%]">
                                        <col class="w-[18%]">
                                        <col class="w-[18%]">
                                        <col class="w-[10%]">
                                        <col class="w-[18%]">
                                    </colgroup>
                                    <thead>
                                        <tr>
                                            <th>{{ __('contracts.documents.file') }}</th>
                                            <th>{{ __('contracts.documents.type') }}</th>
                                            <th>{{ __('contracts.documents.uploaded') }}</th>
                                            <th>{{ __('contracts.documents.size') }}</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($contract->documents as $document)
                                            @php
                                                if ($document->file_size) {
                                                    $documentSize =
                                                        $document->file_size >= 1048576
                                                            ? number_format($document->file_size / 1048576, 2).' '.__('contracts.documents.megabytes')
                                                            : number_format($document->file_size / 1024, 0).' '.__('contracts.documents.kilobytes');
                                                } else {
                                                    $documentSize = null;
                                                }
                                            @endphp
                                            <tr>
                                                <td>
                                                    <div class="crm-table-primary truncate">{{ $document->original_name }}</div>
                                                    @if ($document->comment)
                                                        <div class="crm-table-secondary mt-0.5 truncate">{{ $document->comment }}</div>
                                                    @endif
                                                </td>
                                                <td class="whitespace-nowrap">
                                                    <span class="crm-badge crm-badge-neutral">
                                                        {{ $documentTypes[$document->document_type] ?? __('contracts.documents.other') }}
                                                    </span>
                                                </td>
                                                <td class="crm-table-date">{{ $displayDateTime->format($document->created_at, 'd/m/Y H:i') }}</td>
                                                <td class="crm-table-number">{{ $documentSize ?? '—' }}</td>
                                                <td class="crm-table-actions">
                                                    <div class="inline-flex items-center gap-2">
                                                        @can('download', $document)
                                                            <a href="{{ route('contract-documents.download', $document) }}"
                                                                class="crm-table-icon-action crm-table-icon-action-primary"
                                                                aria-label="{{ __('contracts.documents.download') }}" title="{{ __('contracts.documents.download_title') }}">
                                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4v10m0 0-4-4m4 4 4-4M5 20h14" />
                                                                </svg>
                                                            </a>
                                                        @endcan

                                                        @can('delete', $document)
                                                            <form class="inline-flex m-0 p-0" action="{{ route('contract-documents.destroy', $document) }}" method="POST"
                                                                onsubmit="return confirm('{{ __('contracts.documents.delete_confirm') }}')">
                                                                @csrf
                                                                @method('DELETE')
                                                                <button type="submit" class="crm-table-icon-action crm-table-icon-action-danger"
                                                                    aria-label="{{ __('contracts.documents.delete') }}" title="{{ __('contracts.documents.delete_title') }}">
                                                                    <svg fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                                                                        <path d="M4 7h16" />
                                                                        <path d="M9 7V4h6v3" />
                                                                        <path d="M7 7l1 13h8l1-13" />
                                                                        <path d="M10 11v5" />
                                                                        <path d="M14 11v5" />
                                                                    </svg>
                                                                </button>
                                                            </form>
                                                        @endcan
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="px-4 py-5 text-sm text-slate-500">{{ __('contracts.documents.empty') }}</p>
                        @endif
                    @endif
                </section>
            @endif

            @if (filled($contract->comment))
                <div data-testid="contract-comment"
                    class="order-2 flex items-start gap-3 border-y border-blue-100 bg-blue-50/40 px-4 py-3">
                    <span aria-hidden="true"
                        class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-blue-100 text-blue-700">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                d="M7 8h10M7 12h6m-8 8 2.5-3H19a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h1v3Z" />
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ __('contracts.fields.comment') }}</p>
                        <p class="mt-0.5 whitespace-pre-line text-sm leading-5 text-slate-700">{{ $contract->comment }}</p>
                    </div>
                </div>
            @endif

            {{-- Предмет договора --}}
            <section data-testid="contract-subjects"
                class="order-1 overflow-hidden border-y border-slate-200 bg-white">
                <div class="flex flex-col gap-2 border-b border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <h2 class="text-sm font-semibold text-slate-900">{{ __('contracts.subjects.title') }}</h2>
                        <p class="text-xs tabular-nums text-slate-500">
                            {{ __('contracts.subjects.orders_count', ['count' => $contract->orders->count()]) }}
                            <span class="mx-1 text-slate-300">·</span>
                            {{ __('contracts.subjects.subscriptions_count', ['count' => $contract->subscriptions->count()]) }}
                        </p>
                    </div>

                    @if (\Illuminate\Support\Facades\Gate::allows('create', [\App\Models\Order::class, $contract]) || \Illuminate\Support\Facades\Gate::allows('create', [\App\Models\Subscription::class, $contract]))
                        <a href="{{ route('contracts.subjects.create', $contract) }}" class="crm-light-action">
                            {{ __('contracts.subjects.add') }}
                        </a>
                    @endif
                </div>

                @if ($services->isNotEmpty())
                    <div class="crm-table-scroll">
                        <table data-testid="contract-subjects-table" class="crm-table w-full table-fixed">
                            <colgroup>
                                <col data-column="type" class="w-[9%]">
                                <col data-column="name" class="w-[39%]">
                                <col data-column="date" class="w-[11%]">
                                <col data-column="period" class="w-[10%]">
                                <col data-column="amount" class="w-[10%]">
                                <col data-column="status" class="w-[11%]">
                                <col data-column="actions" class="w-[10%]">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>{{ __('contracts.subjects.table_type') }}</th>
                                    <th>{{ __('contracts.subjects.table_name') }}</th>
                                    <th>{{ __('contracts.subjects.table_date') }}</th>
                                    <th>{{ __('contracts.subjects.table_period') }}</th>
                                    <th>{{ __('contracts.subjects.table_amount') }}</th>
                                    <th>{{ __('contracts.subjects.table_status') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($services as $service)
                                    <tr>
                                        <td class="whitespace-nowrap">
                                            @include('partials.badge', [
                                                'status' => $service['type'] === 'order' ? 'one_time' : 'subscription',
                                                'label' => $service['type_name'],
                                            ])
                                        </td>
                                        <td class="crm-table-primary">{{ $service['service_name'] }}</td>
                                        <td class="crm-table-date">
                                            {{ $service['date'] ? \Carbon\Carbon::parse($service['date'])->format('d/m/Y') : '—' }}
                                        </td>
                                        <td class="whitespace-nowrap">{{ $service['period'] ?? '—' }}</td>
                                        <td class="crm-table-numeric font-medium text-slate-900">
                                            {{ number_format((float) $service['amount'], 2) }} ₼
                                        </td>
                                        <td class="whitespace-nowrap">@include('partials.badge', [
                                            'status' => $service['status'],
                                            'label' => $service['type'] === 'order'
                                                ? __('orders.statuses.'.$service['status'])
                                                : __('subscriptions.statuses.'.$service['status']),
                                        ])</td>
                                        <td class="crm-table-actions">
                                            <div class="inline-flex items-center gap-2">
                                                @can('update', $service['subject'])
                                                    <a href="{{ $service['edit_route'] }}" class="crm-table-icon-action crm-table-icon-action-primary"
                                                        aria-label="{{ __('contracts.subjects.edit') }}" title="{{ __('contracts.actions.edit') }}">
                                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="m15.232 5.232 3.536 3.536M4 20l4.5-1L18.768 8.732a2.5 2.5 0 0 0-3.536-3.536L4.5 15.5 4 20Z" />
                                                        </svg>
                                                    </a>
                                                @endcan

                                                @can('delete', $service['subject'])
                                                    @if ($service['can_delete'])
                                                        <form class="inline-flex m-0 p-0" action="{{ $service['type'] === 'order' ? route('orders.destroy', $service['subject']) : route('subscriptions.destroy', $service['subject']) }}"
                                                            method="POST" onsubmit="return confirm('{{ __('contracts.subjects.delete_confirm') }}')">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="crm-table-icon-action crm-table-icon-action-danger"
                                                                aria-label="{{ __('contracts.subjects.delete') }}" title="{{ __('contracts.documents.delete_title') }}">
                                                                <svg fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                                                                    <path d="M4 7h16" />
                                                                    <path d="M9 7V4h6v3" />
                                                                    <path d="M7 7l1 13h8l1-13" />
                                                                    <path d="M10 11v5" />
                                                                    <path d="M14 11v5" />
                                                                </svg>
                                                            </button>
                                                        </form>
                                                    @endif
                                                @endcan
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="px-4 py-5 text-sm text-slate-500">{{ __('contracts.subjects.services') }}</p>
                @endif
            </section>
    </div>

@endsection
