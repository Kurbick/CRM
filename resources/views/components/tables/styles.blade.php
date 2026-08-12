<style>
    .crm-table-shell {
        overflow: hidden;
        border: 1px solid #e2e8f0;
        border-radius: 0.375rem;
        background: #fff;
    }

    .crm-table-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        border-bottom: 1px solid #e2e8f0;
        padding: 0.75rem 1rem;
    }

    .crm-table-heading-title {
        color: #0f172a;
        font-size: 0.875rem;
        font-weight: 600;
    }

    .crm-table-heading-count {
        color: #64748b;
        font-size: 0.75rem;
        font-variant-numeric: tabular-nums;
    }

    .crm-table-scroll {
        overflow-x: auto;
    }

    .crm-table {
        width: 100%;
        min-width: 100%;
        border-collapse: collapse;
        color: #334155;
        font-size: 0.875rem;
    }

    .crm-table th {
        border-bottom: 1px solid #e2e8f0;
        background: #f8fafc;
        padding: 0.625rem 0.75rem;
        color: #64748b;
        font-size: 0.6875rem;
        font-weight: 600;
        letter-spacing: 0.05em;
        line-height: 1rem;
        text-align: left;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .crm-table td {
        border-bottom: 1px solid #f1f5f9;
        padding: 0.625rem 0.75rem;
        vertical-align: middle;
    }

    .crm-table tbody tr {
        transition: background-color 150ms ease;
    }

    .crm-table tbody tr:hover {
        background: #f8fafc;
    }

    .crm-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .crm-table-primary,
    .crm-table-primary-link {
        color: #0f172a;
        font-weight: 600;
    }

    .crm-table-primary-link {
        transition: color 150ms ease;
    }

    .crm-table-primary-link:hover {
        color: #2563eb;
    }

    .crm-table-secondary {
        color: #64748b;
        font-size: 0.75rem;
    }

    .crm-table-date,
    .crm-table-number,
    .crm-table-numeric {
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }

    .crm-table-date {
        color: #475569;
    }

    .crm-table-numeric {
        text-align: right;
    }

    .crm-table-actions {
        text-align: right;
        white-space: nowrap;
    }

    .crm-table-action-link {
        color: #2563eb;
        font-size: 0.75rem;
        font-weight: 600;
        transition: color 150ms ease;
    }

    .crm-table-action-link:hover {
        color: #1d4ed8;
    }

    .crm-light-action {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        border-radius: 0.25rem;
        color: #2563eb;
        font-size: 0.875rem;
        font-weight: 600;
        line-height: 1.25rem;
        padding: 0.25rem 0.375rem;
        transition: background-color 150ms ease, color 150ms ease, box-shadow 150ms ease;
    }

    .crm-light-action:hover {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .crm-light-action:focus-visible {
        outline: none;
        box-shadow: 0 0 0 2px #fff, 0 0 0 4px rgb(37 99 235 / 0.35);
    }

    .crm-table-sort {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        transition: color 150ms ease;
    }

    .crm-table-sort:hover {
        color: #2563eb;
    }

    .crm-table-sort-indicator {
        color: #cbd5e1;
        font-size: 0.75rem;
        line-height: 1rem;
    }

    .crm-table-sort-indicator-active {
        color: #2563eb;
    }

    .crm-table-empty {
        padding: 2.25rem 1rem;
        color: #64748b;
        text-align: center;
    }

    .crm-table-empty-message {
        display: inline;
    }

    .crm-table-empty-action {
        margin-left: 0.375rem;
        color: #2563eb;
        font-weight: 600;
    }

    .crm-table-empty-action:hover {
        text-decoration: underline;
    }

    .crm-table-footer {
        border-top: 1px solid #e2e8f0;
        background: #f8fafc;
        padding: 0.75rem 1rem;
    }

    .crm-badge {
        display: inline-flex;
        align-items: center;
        border: 1px solid transparent;
        border-radius: 9999px;
        padding: 0.125rem 0.5rem;
        font-size: 0.6875rem;
        font-weight: 600;
        line-height: 1rem;
        white-space: nowrap;
    }

    .crm-badge-neutral {
        border-color: #e2e8f0;
        background: #f1f5f9;
        color: #475569;
    }

    .crm-badge-info {
        border-color: #bfdbfe;
        background: #eff6ff;
        color: #1d4ed8;
    }

    .crm-badge-success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #15803d;
    }

    .crm-badge-warning {
        border-color: #fde68a;
        background: #fffbeb;
        color: #b45309;
    }

    .crm-badge-danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }
</style>
