@props(['active'])

<span data-user-status-badge
    class="crm-badge {{ $active ? 'crm-badge-success' : 'crm-badge-danger' }}">
    {{ $active ? __('admin.users.statuses.active') : __('admin.users.statuses.inactive') }}
</span>
