@props(['url' => null, 'label' => null])

<tr
    {{ $attributes->class(['crm-clickable-row' => $url]) }}
    @if ($url)
        data-row-url="{{ $url }}"
        tabindex="0"
        aria-label="{{ $label ?? __('common.table.open_row') }}"
        x-data="{
            navigate(event) {
                if (event.target.closest('a,button,input,select,textarea,label,summary,[role=button],[role=link],[contenteditable=true],[data-row-click-ignore]')) return;
                if (event.type === 'keydown') event.preventDefault();
                window.location.assign(@js($url));
            }
        }"
        x-on:click="navigate($event)"
        x-on:keydown.enter="navigate($event)"
        x-on:keydown.space="navigate($event)"
    @endif
>
    {{ $slot }}
</tr>
