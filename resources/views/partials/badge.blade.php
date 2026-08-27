{{-- Использование: @include('partials.badge', ['status' => $company->status]) --}}
@php
    $tones = [
        'active' => 'success',
        'suspended' => 'warning',
        'archived' => 'neutral',
        'draft' => 'neutral',
        'issued' => 'info',
        'partially_paid' => 'warning',
        'paid' => 'success',
        'cancelled' => 'danger',
        'pending' => 'warning',
        'confirmed' => 'success',
        'in_progress' => 'info',
        'completed' => 'success',
        'one_time' => 'neutral',
        'subscription' => 'info',
    ];

    $labels = [
        'active' => __('common.statuses.active'),
        'suspended' => __('common.statuses.suspended'),
        'archived' => __('common.statuses.archived'),
        'draft' => __('common.statuses.draft'),
        'issued' => __('common.statuses.issued'),
        'partially_paid' => __('common.statuses.partially_paid'),
        'paid' => __('common.statuses.paid'),
        'cancelled' => __('common.statuses.cancelled'),
        'pending' => __('common.statuses.pending'),
        'confirmed' => __('common.statuses.confirmed'),
        'in_progress' => __('common.statuses.in_progress'),
        'completed' => __('common.statuses.completed'),
        'one_time' => __('common.statuses.one_time'),
        'subscription' => __('common.statuses.subscription'),
    ];

    $tone = $tones[$status] ?? 'neutral';
    $displayLabel = $label ?? ($labels[$status] ?? $status);
@endphp

<span class="crm-badge crm-badge-{{ $tone }}">
    {{ $displayLabel }}
</span>
