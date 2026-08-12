<style>
    .crm-form-scope input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([aria-hidden=true]),
    .crm-form-scope select,
    .crm-form-scope textarea {
        min-height: 2.5rem;
        border: 1px solid #cbd5e1;
        border-radius: 0.375rem;
        background-color: #fff;
        color: #0f172a;
        font-size: 0.875rem;
        padding: 0.5rem 0.75rem;
        outline: none;
        box-shadow: none;
        transition: border-color 150ms ease, box-shadow 150ms ease, background-color 150ms ease;
    }

    .crm-form-scope textarea {
        min-height: 5rem;
        resize: vertical;
    }

    .crm-form-scope input.crm-control-with-action {
        padding-right: 2.75rem;
    }

    .crm-form-scope input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([aria-hidden=true]).crm-control-with-leading-icon {
        padding-left: 2.5rem;
    }

    .crm-form-scope .crm-filter-neutral {
        color: #64748b;
    }

    .crm-form-scope .crm-filter-selected {
        color: #0f172a;
    }

    .crm-form-scope input.crm-filter-neutral::placeholder {
        color: #94a3b8;
        opacity: 1;
    }

    .crm-form-scope select {
        appearance: none;
        padding-right: 2.5rem;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364758b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 0.75rem center;
        background-size: 1rem;
    }

    .crm-form-scope input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([aria-hidden=true]):focus,
    .crm-form-scope select:focus,
    .crm-form-scope textarea:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 2px rgb(37 99 235 / 0.16);
    }

    .crm-form-scope input:disabled,
    .crm-form-scope select:disabled,
    .crm-form-scope textarea:disabled {
        cursor: not-allowed;
        border-color: #cbd5e1;
        background-color: #f1f5f9;
        color: #64748b;
        opacity: 1;
    }

    .crm-form-scope input[readonly],
    .crm-form-scope textarea[readonly] {
        border-color: #cbd5e1;
        background-color: #f8fafc;
        color: #475569;
    }

    .crm-form-scope input.border-red-300,
    .crm-form-scope select.border-red-300,
    .crm-form-scope textarea.border-red-300,
    .crm-form-scope input[aria-invalid="true"] {
        border-color: #fca5a5;
    }

    .crm-form-scope input.border-red-300:focus,
    .crm-form-scope select.border-red-300:focus,
    .crm-form-scope textarea.border-red-300:focus,
    .crm-form-scope input[aria-invalid="true"]:focus {
        border-color: #ef4444;
        box-shadow: 0 0 0 2px rgb(239 68 68 / 0.14);
    }

    .crm-form-scope input[type=checkbox],
    .crm-form-scope input[type=radio] {
        width: 1rem;
        min-height: 1rem;
        height: 1rem;
        padding: 0;
        border: 1px solid #94a3b8;
        border-radius: 0.25rem;
        accent-color: #2563eb;
        background-color: #fff;
    }

    .crm-form-scope input[type=radio] {
        border-radius: 9999px;
    }

    .crm-form-scope button.bg-blue-600,
    .crm-form-scope a.bg-blue-600,
    .crm-form-scope button.bg-gray-800 {
        display: inline-flex;
        min-height: 2.5rem;
        align-items: center;
        justify-content: center;
        gap: 0.375rem;
        border: 1px solid #2563eb;
        border-radius: 0.375rem;
        background-color: #2563eb;
        color: #fff;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        font-weight: 600;
        line-height: 1.25rem;
        box-shadow: none;
        transition: background-color 150ms ease, border-color 150ms ease, box-shadow 150ms ease;
    }

    .crm-form-scope button.bg-blue-600:hover,
    .crm-form-scope a.bg-blue-600:hover,
    .crm-form-scope button.bg-gray-800:hover {
        border-color: #1d4ed8;
        background-color: #1d4ed8;
    }

    .crm-form-scope button.bg-blue-600:focus-visible,
    .crm-form-scope a.bg-blue-600:focus-visible,
    .crm-form-scope button.bg-gray-800:focus-visible {
        outline: none;
        box-shadow: 0 0 0 2px #fff, 0 0 0 4px rgb(37 99 235 / 0.45);
    }

    .crm-form-scope button.bg-green-600,
    .crm-form-scope a.bg-green-600 {
        display: inline-flex;
        min-height: 2.5rem;
        align-items: center;
        justify-content: center;
        gap: 0.375rem;
        border: 1px solid #16a34a;
        border-radius: 0.375rem;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        font-weight: 600;
        line-height: 1.25rem;
        box-shadow: none;
        transition: background-color 150ms ease, border-color 150ms ease, box-shadow 150ms ease;
    }

    .crm-form-scope button.bg-green-600:focus-visible,
    .crm-form-scope a.bg-green-600:focus-visible {
        outline: none;
        box-shadow: 0 0 0 2px #fff, 0 0 0 4px rgb(22 163 74 / 0.35);
    }

    .crm-form-scope button.border-gray-200:not(.text-left),
    .crm-form-scope button.border-gray-300:not(.text-left),
    .crm-form-scope a.border-gray-200,
    .crm-form-scope a.border-gray-300 {
        display: inline-flex;
        min-height: 2.5rem;
        align-items: center;
        justify-content: center;
        gap: 0.375rem;
        border: 1px solid #cbd5e1;
        border-radius: 0.375rem;
        background-color: #fff;
        color: #334155;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        font-weight: 600;
        line-height: 1.25rem;
        box-shadow: none;
        transition: background-color 150ms ease, border-color 150ms ease, box-shadow 150ms ease;
    }

    .crm-form-scope button.border-gray-200:not(.text-left):hover,
    .crm-form-scope button.border-gray-300:not(.text-left):hover,
    .crm-form-scope a.border-gray-200:hover,
    .crm-form-scope a.border-gray-300:hover {
        background-color: #f8fafc;
    }

    .crm-form-scope button.border-gray-200:not(.text-left):focus-visible,
    .crm-form-scope button.border-gray-300:not(.text-left):focus-visible,
    .crm-form-scope a.border-gray-200:focus-visible,
    .crm-form-scope a.border-gray-300:focus-visible {
        outline: none;
        box-shadow: 0 0 0 2px #fff, 0 0 0 4px rgb(100 116 139 / 0.35);
    }

    .crm-form-scope button.border-red-200:not(.text-left),
    .crm-form-scope button.border-red-300:not(.text-left),
    .crm-form-scope a.border-red-200,
    .crm-form-scope a.border-red-300 {
        display: inline-flex;
        min-height: 2.5rem;
        align-items: center;
        justify-content: center;
        gap: 0.375rem;
        border: 1px solid #fca5a5;
        border-radius: 0.375rem;
        background-color: #fff;
        color: #b91c1c;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        font-weight: 600;
        line-height: 1.25rem;
        box-shadow: none;
        transition: background-color 150ms ease, border-color 150ms ease, box-shadow 150ms ease;
    }

    .crm-form-scope button.border-red-200:not(.text-left):hover,
    .crm-form-scope button.border-red-300:not(.text-left):hover,
    .crm-form-scope a.border-red-200:hover,
    .crm-form-scope a.border-red-300:hover {
        background-color: #fef2f2;
    }

    .crm-form-scope button.border-red-200:not(.text-left):focus-visible,
    .crm-form-scope button.border-red-300:not(.text-left):focus-visible,
    .crm-form-scope a.border-red-200:focus-visible,
    .crm-form-scope a.border-red-300:focus-visible {
        outline: none;
        box-shadow: 0 0 0 2px #fff, 0 0 0 4px rgb(239 68 68 / 0.35);
    }
</style>
