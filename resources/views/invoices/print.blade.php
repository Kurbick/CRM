@php
    $formatMoney = static function ($amount): string {
        return number_format((float) $amount, 2, ',', ' ') . ' ₼';
    };
    $formatDate = static fn ($date): string => $date
        ? \Illuminate\Support\Carbon::parse($date)->format('d/m/Y')
        : '—';
    $vatRateLabel = filled($invoice->vat_rate)
        ? rtrim(rtrim((string) $invoice->vat_rate, '0'), '.')
        : '—';
    $buyerName = $buyer['name'] ?? null;
    $contractNumber = $invoice->contract?->contract_number ?: $invoice->contract_reference;
    $logoAvailable = is_file(public_path('images/zeroline-logo.svg'));
    $emptyRowCount = max(0, 10 - count($printLines));
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('invoices.print.heading') }} — {{ $invoice->invoice_number }}</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #182b3b;
            --muted: #5e7182;
            --line: #b8c9d6;
            --blue: #dceefa;
            --blue-strong: #6fa9d0;
        }

        * { box-sizing: border-box; }

        html, body { margin: 0; min-height: 100%; }

        body {
            background: #eef3f7;
            color: var(--ink);
            font-family: Arial, "Helvetica Neue", sans-serif;
            font-size: 12px;
            line-height: 1.45;
        }

        .print-toolbar {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            max-width: 210mm;
            margin: 0 auto;
            padding: 16px 0;
        }

        .print-toolbar a,
        .print-toolbar button {
            border: 1px solid #cbd5df;
            border-radius: 4px;
            background: #fff;
            color: #334e62;
            cursor: pointer;
            font: inherit;
            padding: 7px 12px;
            text-decoration: none;
        }

        .invoice-paper {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 24px;
            padding: 15mm 14mm 12mm;
            background: #fff;
            box-shadow: 0 8px 30px rgb(35 55 70 / 10%);
        }

        .invoice-header {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 18mm;
            align-items: start;
            border-bottom: 2px solid var(--blue-strong);
            padding-bottom: 8mm;
        }

        .logo-frame {
            display: flex;
            align-items: center;
            min-height: 19mm;
            margin-bottom: 4mm;
        }

        .logo-frame img {
            display: block;
            width: auto;
            max-width: 48mm;
            max-height: 19mm;
        }

        .logo-fallback {
            color: #193b56;
            font-size: 25px;
            font-weight: 800;
            letter-spacing: -.06em;
        }

        .issuer-name {
            font-size: 17px;
            font-weight: 700;
            line-height: 1.2;
        }

        .issuer-legal,
        .issuer-tax,
        .bank-list,
        .meta-list,
        .buyer-details {
            color: var(--muted);
        }

        .issuer-legal { margin-top: 2px; font-size: 11px; }
        .issuer-tax { margin-top: 4px; font-family: Consolas, monospace; font-size: 11px; }
        .bank-list { display: grid; gap: 1px; margin-top: 5mm; font-size: 10.5px; }
        .bank-list strong, .meta-list strong, .buyer-details strong { color: var(--ink); font-weight: 600; }
        .bank-value { overflow-wrap: anywhere; }

        .invoice-meta { min-width: 48mm; text-align: right; }
        .invoice-heading { margin: 0 0 7mm; color: #1e4967; font-size: 25px; font-weight: 700; letter-spacing: .06em; }
        .meta-list { display: grid; gap: 4px; font-size: 11px; }
        .meta-list div { display: grid; grid-template-columns: auto auto; gap: 7px; justify-content: end; }
        .meta-value { color: var(--ink); font-family: Consolas, monospace; }

        .party-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12mm;
            margin: 8mm 0 9mm;
            padding: 5mm 6mm;
            border: 1px solid #c6d9e6;
            background: #f8fbfd;
        }

        .party { min-width: 0; }
        .party + .party { border-left: 1px solid #c6d9e6; padding-left: 12mm; }
        .section-label { color: #52758d; font-size: 10px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        .party h2 { margin: 2mm 0 1mm; font-size: 14px; line-height: 1.25; }
        .buyer-details { display: grid; gap: 1px; font-size: 11px; }

        .items-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .items-table col.number { width: 11mm; }
        .items-table col.amount { width: 36mm; }
        .items-table th,
        .items-table td { border: 1px solid var(--line); }
        .items-table thead th {
            padding: 3mm 3mm;
            background: var(--blue);
            color: #274b63;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
            text-align: left;
        }
        .items-table thead th:first-child,
        .items-table tbody td:first-child { text-align: center; }
        .items-table thead th:last-child,
        .items-table tbody td:last-child { text-align: right; }
        .items-table tbody td { min-height: 10mm; padding: 3mm; vertical-align: top; }
        .items-table tbody tr { break-inside: avoid; page-break-inside: avoid; }
        .line-description { overflow-wrap: anywhere; font-size: 11.5px; }
        .line-number { color: #688296; font-family: Consolas, monospace; }
        .line-amount { color: var(--ink); font-family: Consolas, monospace; font-size: 11.5px; white-space: nowrap; }
        .empty-row td { height: 9mm; padding: 0; }
        .totals-row td { padding: 0; border-top: 2px solid var(--blue-strong); }
        .totals { display: grid; gap: 2mm; width: 84mm; margin: 0 0 0 auto; padding: 5mm 3mm 4mm; }
        .total-line { display: flex; justify-content: space-between; gap: 8mm; color: var(--muted); }
        .total-value { color: var(--ink); font-family: Consolas, monospace; white-space: nowrap; }
        .total-grand { margin-top: 1mm; padding-top: 3mm; border-top: 1px solid var(--line); color: var(--ink); font-size: 14px; font-weight: 700; }

        .signature {
            display: grid;
            grid-template-columns: 1fr 48mm;
            gap: 20mm;
            align-items: end;
            margin-top: 17mm;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .signature-title { font-size: 12px; }
        .signature-line { width: 62mm; margin-top: 13mm; border-bottom: 1px solid #6b7e8b; }
        .signature-stamp { text-align: center; color: var(--muted); font-size: 11px; }
        .signature-stamp::before { content: ""; display: block; height: 17mm; margin-bottom: 2mm; border: 1px solid #b9c9d3; }

        .invoice-footer {
            margin-top: 18mm;
            padding-top: 4mm;
            border-top: 1px solid #d6e0e7;
            color: #52758d;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .1em;
            text-align: center;
        }

        @page { size: A4 portrait; margin: 12mm; }

        @media print {
            html, body { background: #fff; }
            body { font-size: 12px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .print-toolbar { display: none !important; }
            .invoice-paper { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
            .invoice-header, .party-grid, .items-table, .signature, .invoice-footer { break-inside: avoid; page-break-inside: avoid; }
            .items-table thead { display: table-header-group; }
            .items-table tfoot { display: table-row-group; }
        }

        @media screen and (max-width: 220mm) {
            .print-toolbar { padding-right: 16px; padding-left: 16px; }
            .invoice-paper { width: calc(100% - 32px); padding: 36px 28px; }
        }

        @media screen and (max-width: 640px) {
            .invoice-header, .party-grid, .signature { grid-template-columns: 1fr; gap: 6mm; }
            .invoice-meta { text-align: left; }
            .meta-list div { justify-content: start; }
            .party + .party { border-left: 0; border-top: 1px solid #c6d9e6; padding-top: 5mm; padding-left: 0; }
            .totals { width: 100%; }
        }
    </style>
</head>

<body>
    <nav class="print-toolbar" aria-label="{{ __('invoices.print.controls') }}">
        <a href="{{ route('invoices.show', $invoice) }}">{{ __('invoices.actions.back_to_invoice') }}</a>
        <button type="button" onclick="window.print()">{{ __('invoices.actions.print') }}</button>
    </nav>

    <main class="invoice-paper" data-testid="invoice-print-document">
        <header class="invoice-header">
            <section aria-label="{{ __('invoices.print.supplier') }}">
                <div class="logo-frame" data-logo-asset="images/zeroline-logo.svg">
                    @if ($logoAvailable)
                        <img src="{{ asset('images/zeroline-logo.svg') }}" alt="ZeroLine">
                    @else
                        <div class="logo-fallback" aria-label="ZeroLine">ZeroLine</div>
                    @endif
                </div>
                @if (filled($seller['name']))
                    <div class="issuer-name">{{ $seller['name'] }}</div>
                @endif
                @if (filled($seller['legal_name']) && $seller['legal_name'] !== $seller['name'])
                    <div class="issuer-legal"><strong>{{ __('invoices.print.legal_name') }}:</strong> {{ $seller['legal_name'] }}</div>
                @endif
                @if (filled($seller['voen']))
                    <div class="issuer-tax"><strong>{{ __('invoices.print.voen') }}:</strong> {{ $seller['voen'] }}</div>
                @endif

                <div class="bank-list">
                    @if (filled($seller['iban']))
                        <div><strong>{{ __('invoices.print.account') }}:</strong> <span class="bank-value">{{ $seller['iban'] }}</span></div>
                    @endif
                    @if (filled($seller['bank_name']))
                        <div><strong>{{ __('invoices.print.bank') }}:</strong> <span class="bank-value">{{ $seller['bank_name'] }}</span></div>
                    @endif
                    @if (filled($seller['bank_voen']))
                        <div><strong>{{ __('invoices.print.bank_voen') }}:</strong> <span class="bank-value">{{ $seller['bank_voen'] }}</span></div>
                    @endif
                    @if (filled($seller['correspondent_account']))
                        <div><strong>{{ __('invoices.print.correspondent_account') }}:</strong> <span class="bank-value">{{ $seller['correspondent_account'] }}</span></div>
                    @endif
                    @if (filled($seller['bank_code']))
                        <div><strong>{{ __('invoices.print.bank_code') }}:</strong> <span class="bank-value">{{ $seller['bank_code'] }}</span></div>
                    @endif
                    @if (filled($seller['swift']))
                        <div><strong>{{ __('invoices.print.swift') }}:</strong> <span class="bank-value">{{ $seller['swift'] }}</span></div>
                    @endif
                </div>
            </section>

            <section class="invoice-meta" aria-label="{{ __('invoices.print.heading') }}">
                <h1 class="invoice-heading">{{ __('invoices.print.heading') }}</h1>
                <div class="meta-list">
                    <div><strong>{{ __('invoices.print.issue_date') }}</strong><span class="meta-value">{{ $formatDate($invoice->issue_date) }}</span></div>
                    <div><strong>{{ __('invoices.print.invoice_number') }}</strong><span class="meta-value">{{ $invoice->invoice_number }}</span></div>
                </div>
            </section>
        </header>

        <section class="party-grid">
            <div class="party">
                <div class="section-label">{{ __('invoices.print.buyer') }}</div>
                <h2>{{ $buyerName ?: __('invoices.print.not_specified') }}</h2>
                <div class="buyer-details">
                    @if (filled($buyer['voen'] ?? null))
                        <div><strong>{{ __('invoices.print.voen') }}:</strong> {{ $buyer['voen'] }}</div>
                    @endif
                    @if (filled($buyer['phone'] ?? null))
                        <div><strong>{{ __('invoices.print.phone') }}:</strong> {{ $buyer['phone'] }}</div>
                    @endif
                </div>
            </div>
            @if (filled($contractNumber))
                <div class="party">
                    <div class="section-label">{{ __('invoices.print.contract') }}</div>
                    <h2 class="meta-value">{{ $contractNumber }}</h2>
                </div>
            @endif
        </section>

        <section aria-label="{{ __('invoices.print.description') }}">
            <table class="items-table">
                <colgroup>
                    <col class="number">
                    <col>
                    <col class="amount">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">{{ __('invoices.print.number') }}</th>
                        <th scope="col">{{ __('invoices.print.description') }}</th>
                        <th scope="col">{{ __('invoices.print.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($printLines as $index => $line)
                        <tr>
                            <td class="line-number">{{ $index + 1 }}</td>
                            <td class="line-description">{{ $line['description'] }}</td>
                            <td class="line-amount">{{ $formatMoney($line['amount']) }}</td>
                        </tr>
                    @endforeach
                    @for ($index = 0; $index < $emptyRowCount; $index++)
                        <tr class="empty-row" aria-hidden="true"><td></td><td></td><td></td></tr>
                    @endfor
                </tbody>
                <tfoot>
                    <tr class="totals-row">
                        <td colspan="3">
                            <div class="totals" data-canonical-subtotal="{{ $invoice->subtotal_amount }}" data-canonical-vat="{{ $invoice->vat_amount }}" data-canonical-total="{{ $invoice->total_amount }}">
                                <div class="total-line"><span>{{ __('invoices.print.subtotal') }}</span><span class="total-value">{{ $formatMoney($invoice->subtotal_amount) }}</span></div>
                                @if ($invoice->vat_enabled)
                                    <div class="total-line"><span>{{ __('invoices.print.vat', ['rate' => $vatRateLabel]) }}</span><span class="total-value">{{ $formatMoney($invoice->vat_amount) }}</span></div>
                                @endif
                                <div class="total-line total-grand"><span>{{ __('invoices.print.total') }}</span><span class="total-value">{{ $formatMoney($invoice->total_amount) }}</span></div>
                            </div>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </section>

        <section class="signature">
            <div>
                <div class="signature-title">{{ __('invoices.print.director') }}: {{ __('invoices.print.signature_placeholder') }}</div>
                <div class="signature-line"></div>
            </div>
            <div class="signature-stamp">{{ __('invoices.print.stamp') }}</div>
        </section>

        <footer class="invoice-footer">{{ __('invoices.print.footer') }}</footer>
    </main>
</body>

</html>
