@props(['role' => null])

@php
    $color = match ($role?->name) {
        'administrator' => 'bg-purple-100 text-purple-700',
        'accountant' => 'bg-blue-100 text-blue-700',
        'viewer' => 'bg-gray-100 text-gray-700',
        default => 'bg-gray-100 text-gray-600',
    };
@endphp

<span data-user-role-badge
    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $color }}">
    {{ $role?->display_name ?? 'Без группы' }}
</span>
