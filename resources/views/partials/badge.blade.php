{{-- Использование: @include('partials.badge', ['status' => $company->status]) --}}
@php
    $colors = [
        'active'        => 'bg-green-100 text-green-700',
        'suspended'     => 'bg-yellow-100 text-yellow-700',
        'archived'      => 'bg-gray-100 text-gray-500',
        'draft'         => 'bg-gray-100 text-gray-600',
        'issued'        => 'bg-blue-100 text-blue-700',
        'partially_paid'=> 'bg-orange-100 text-orange-700',
        'paid'          => 'bg-green-100 text-green-700',
        'cancelled'     => 'bg-red-100 text-red-600',
        'pending'       => 'bg-yellow-100 text-yellow-700',
        'confirmed'     => 'bg-green-100 text-green-700',
        'in_progress'   => 'bg-blue-100 text-blue-700',
        'completed'     => 'bg-green-100 text-green-700',
        'one_time'      => 'bg-purple-100 text-purple-700',
        'subscription'  => 'bg-blue-100 text-blue-700',
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
        'pending'       => 'Ожидает',
        'confirmed'     => 'Подтверждён',
        'in_progress'   => 'В работе',
        'completed'     => 'Завершён',
        'one_time'      => 'Разовая',
        'subscription'  => 'Подписка',
    ];

    $color = $colors[$status] ?? 'bg-gray-100 text-gray-600';
    $label = $labels[$status] ?? $status;
@endphp

<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $color }}">
    {{ $label }}
</span>