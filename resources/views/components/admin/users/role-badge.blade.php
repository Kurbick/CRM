@props(['role' => null])

@php
    $tone = match ($role?->name) {
        'administrator', 'accountant' => 'info',
        default => 'neutral',
    };
    $label = match ($role?->name) {
        \App\Support\Access\SystemRole::Administrator->value => __('admin.access.system_roles.administrator'),
        \App\Support\Access\SystemRole::Accountant->value => __('admin.access.system_roles.accountant'),
        \App\Support\Access\SystemRole::Viewer->value => __('admin.access.system_roles.viewer'),
        default => $role?->display_name ?? __('admin.users.filters.no_group'),
    };
@endphp

<span data-user-role-badge
    class="crm-badge crm-badge-{{ $tone }}">
    {{ $label }}
</span>
