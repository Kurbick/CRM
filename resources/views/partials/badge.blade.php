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
        'active'        => 'Активен',
        'suspended'     => 'Приостановлен',
        'archived'      => 'Архив',
        'draft'         => 'Черновик',
        'issued'        => 'Выставлен',
        'partially_paid'=> 'Частично оплачен',
        'paid'          => 'Оплачен',
        'cancelled'     => 'Отменён',
        'pending'       => 'Ожидает подтверждения',
        'confirmed'     => 'Подтверждён',
        'in_progress'   => 'В работе',
        'completed'     => 'Завершён',
        'one_time'      => 'Разовая',
        'subscription'  => 'Подписка',
    ];

    $tone = $tones[$status] ?? 'neutral';
    $displayLabel = $label ?? ($labels[$status] ?? $status);
@endphp

<span class="crm-badge crm-badge-{{ $tone }}">
    {{ $displayLabel }}
</span>
