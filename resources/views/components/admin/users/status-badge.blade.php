@props(['active'])

<span data-user-status-badge
    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-600' }}">
    {{ $active ? 'Активен' : 'Отключён' }}
</span>
