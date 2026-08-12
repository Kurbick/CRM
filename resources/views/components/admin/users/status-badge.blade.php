@props(['active'])

<span data-user-status-badge
    class="crm-badge {{ $active ? 'crm-badge-success' : 'crm-badge-danger' }}">
    {{ $active ? 'Активен' : 'Отключён' }}
</span>
