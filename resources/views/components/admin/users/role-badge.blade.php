@props(['role' => null])

@php
    $tone = match ($role?->name) {
        'administrator', 'accountant' => 'info',
        default => 'neutral',
    };
@endphp

<span data-user-role-badge
    class="crm-badge crm-badge-{{ $tone }}">
    {{ $role?->display_name ?? 'Без группы' }}
</span>
