@php
    $formatMoney = static function ($amount): string {
        return number_format((float) $amount, 2, ',', ' ') . ' ₼';
    };
    $formatDate = static fn ($date): string => $date
        ? \Illuminate\Support\Carbon::parse($date)->format('d'.'.'.'m'.'.'.'Y')
        : '—';
    $vatRateLabel = filled($invoice->vat_rate)
        ? rtrim(rtrim((string) $invoice->vat_rate, '0'), '.')
        : '—';
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('invoices.print.heading') }} — {{ $invoice->invoice_number }}</title>
    <style>
        * { box-sizing: border-box; }

        html,
        body { margin: 0; min-height: 100%; }

        body {
            background: #fff;
            color: #000;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12pt;
            line-height: 1.15;
        }

        .invoice-paper {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 12.7mm 19.05mm;
            background: #fff;
        }

        .word-document {
            display: flex;
            flex-direction: column;
            width: 169.9mm;
            max-width: 100%;
            min-height: 271.6mm;
            margin: 0;
        }

        .document-grid {
            display: grid;
            grid-template-columns: 23.86mm 76.12mm 3.77mm 22.24mm 43.96mm;
            width: 169.9mm;
            max-width: 100%;
        }

        .header-row {
            min-height: 29.97mm;
            align-items: start;
        }

        .logo-block {
            grid-column: 1 / 4;
            position: relative;
            min-height: 29.97mm;
        }

        .logo-block img {
            display: block;
            width: 59.84mm;
            height: auto;
            max-width: 100%;
        }

        .invoice-meta {
            grid-column: 4 / 6;
            align-self: start;
            text-align: right;
        }

        .invoice-heading {
            margin: 0 0 4mm;
            color: #595959;
            font-size: 26pt;
            font-weight: 700;
            line-height: 1;
        }

        .meta-line {
            margin: 0;
            font-size: 12pt;
            line-height: 1.35;
            white-space: nowrap;
        }

        .seller-row {
            min-height: 12.68mm;
            align-items: start;
        }

        .seller-block {
            grid-column: 1 / 4;
            padding-top: 5mm;
            padding-bottom: 4mm;
            font-size: 12pt;
            font-weight: 700;
            line-height: 1.25;
        }

        .seller-name,
        .seller-legal,
        .seller-tax { display: block; }

        .seller-tax { margin-top: .4mm; }

        .details-row {
            min-height: 21.5mm;
            align-items: start;
        }

        .bank-block {
            grid-column: 1 / 3;
            display: grid;
            grid-template-columns: 23.86mm 76.12mm;
            align-content: start;
            font-size: 10pt;
            line-height: 1.25;
        }

        .bank-line { display: contents; }

        .bank-label,
        .bank-value,
        .buyer-label,
        .buyer-value {
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .bank-label { padding-right: 1mm; }

        .buyer-block {
            grid-column: 4 / 6;
            display: grid;
            grid-template-columns: 24.5mm 41.7mm;
            align-content: start;
            font-size: 10pt;
            line-height: 1.35;
        }

        .buyer-label {
            font-weight: 700;
            white-space: nowrap;
            overflow-wrap: normal;
        }

        .items-wrap {
            width: 168.65mm;
            max-width: 100%;
            margin-top: 15mm;
        }

        .items-table {
            width: 168.65mm;
            max-width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 12pt;
            line-height: 1.15;
        }

        .items-table col.number { width: 14.46mm; }
        .items-table col.description { width: 121.27mm; }
        .items-table col.amount { width: 32.91mm; }

        .items-table th,
        .items-table td {
            padding: 1mm 1.5mm;
            border: 1px solid #a6a6a6;
            vertical-align: middle;
        }

        .items-table thead th {
            padding: 1mm;
            border-color: #03b7eb;
            background: #82d2f5;
            font-weight: 700;
            text-align: center;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .items-table tbody tr,
        .items-table tfoot tr {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .items-table tbody td:first-child { text-align: center; }
        .items-table td:last-child { text-align: right; white-space: nowrap; }
        .line-description { overflow-wrap: anywhere; }
        .invoice-empty-row {
            height: 9mm;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .invoice-empty-row > td {
            height: 9mm;
            min-height: 9mm;
            padding: 0;
            font-size: 12pt;
            line-height: 12pt;
            color: transparent;
        }

        .invoice-empty-row > td::before {
            content: "\00a0";
        }

        .items-table tfoot td {
            height: 9mm;
            min-height: 9mm;
            padding: 0 1.5mm;
            line-height: 12pt;
            vertical-align: middle;
            font-weight: 700;
        }

        .items-table tfoot td:first-child { font-weight: 400; }
        .items-table tfoot td:nth-child(2) { text-align: right; }
        .items-table tfoot td:last-child { text-align: right; }

        .signature {
            display: grid;
            grid-template-columns: 82.45mm 44.98mm;
            grid-template-rows: 4mm 5mm 5mm 4mm;
            width: 127.5mm;
            max-width: 100%;
            margin-top: auto;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .signature-director {
            grid-column: 1;
            grid-row: 2;
            align-self: center;
            font-size: 12pt;
        }

        .signature-line {
            grid-column: 2;
            grid-row: 2;
            align-self: end;
            border-bottom: 1px solid #000;
        }

        .signature-stamp {
            grid-column: 2;
            grid-row: 3;
            text-align: right;
            font-size: 10pt;
        }

        .invoice-footer {
            margin-top: 14mm;
            font-size: 10pt;
            font-weight: 700;
            text-align: center;
        }

        @page {
            size: A4 portrait;
            margin: 12.7mm 19.05mm;
        }

        @media print {
            html,
            body { background: #fff; }

            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .invoice-paper {
                width: auto;
                min-height: 0;
                margin: 0;
                padding: 0;
            }

            .items-table thead { display: table-header-group; }
            .items-table tfoot { display: table-row-group; }
        }

        @media screen and (max-width: 210mm) {
            .invoice-paper {
                width: 100%;
                min-height: 0;
                padding: 12.7mm 19.05mm;
            }
        }
    </style>
</head>

<body>
    <main class="invoice-paper" data-testid="invoice-print-document">
        <div class="word-document">
            <header class="document-grid header-row">
                <div class="logo-block" data-logo-asset="images/zeroline-logo.png">
                    <img src="{{ asset('images/zeroline-logo.png') }}" alt="ZeroLine">
                </div>

                <section class="invoice-meta" aria-label="{{ __('invoices.print.heading') }}">
                    <h1 class="invoice-heading">{{ __('invoices.print.heading') }}</h1>
                    <p class="meta-line">{{ __('invoices.print.issue_date') }}: {{ $formatDate($invoice->issue_date) }}</p>
                    <p class="meta-line">{{ __('invoices.print.invoice_number') }}: {{ $invoice->invoice_number }}</p>
                </section>
            </header>

            <section class="document-grid seller-row" aria-label="{{ __('invoices.print.supplier') }}">
                <div class="seller-block">
                    @if (filled($seller['name']))
                        <span class="seller-name">{{ $seller['name'] }}</span>
                    @endif
                    @if (filled($seller['legal_name']) && $seller['legal_name'] !== $seller['name'])
                        <span class="seller-legal">{{ $seller['legal_name'] }}</span>
                    @endif
                    @if (filled($seller['voen']))
                        <span class="seller-tax">{{ __('invoices.print.voen') }}: {{ $seller['voen'] }}</span>
                    @endif
                </div>
            </section>

            <section class="document-grid details-row">
                <div class="bank-block" aria-label="{{ __('invoices.print.supplier') }}">
                    @foreach ([
                        ['label' => __('invoices.print.account_short'), 'value' => $seller['iban']],
                        ['label' => __('invoices.print.bank_short'), 'value' => $seller['bank_name']],
                        ['label' => __('invoices.print.voen_short'), 'value' => $seller['bank_voen']],
                        ['label' => __('invoices.print.correspondent_short'), 'value' => $seller['correspondent_account']],
                        ['label' => __('invoices.print.bank_code_short'), 'value' => $seller['bank_code']],
                        ['label' => __('invoices.print.swift_short'), 'value' => $seller['swift']],
                    ] as $bankLine)
                        @if (filled($bankLine['value']))
                            <div class="bank-line">
                                <span class="bank-label">{{ $bankLine['label'] }}</span>
                                <span class="bank-value">{{ $bankLine['value'] }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>

                <div class="buyer-block" aria-label="{{ __('invoices.print.buyer') }}">
                    @if (filled($buyer['name'] ?? null))
                        <span class="buyer-label">{{ __('invoices.print.buyer') }}:</span>
                        <span class="buyer-value">{{ $buyer['name'] }}</span>
                    @endif
                    @if (filled($buyer['voen'] ?? null))
                        <span class="buyer-label">{{ __('invoices.print.voen') }}:</span>
                        <span class="buyer-value">{{ $buyer['voen'] }}</span>
                    @endif
                    @if (filled($buyer['phone'] ?? null))
                        <span class="buyer-label">{{ __('invoices.print.phone') }}:</span>
                        <span class="buyer-value">{{ $buyer['phone'] }}</span>
                    @endif
                </div>
            </section>

            <section class="items-wrap" aria-label="{{ __('invoices.print.description') }}">
                <table class="items-table">
                    <colgroup>
                        <col class="number">
                        <col class="description">
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
                                <td>{{ $index + 1 }}</td>
                                <td class="line-description">{{ $line['description'] }}</td>
                                <td>{{ $formatMoney($line['amount']) }}</td>
                            </tr>
                        @endforeach
                        @for ($index = 0; $index < $emptyRowCount; $index++)
                            <tr class="invoice-empty-row" aria-hidden="true"><td></td><td></td><td></td></tr>
                        @endfor
                    </tbody>
                    <tfoot data-canonical-subtotal="{{ $invoice->subtotal_amount }}" data-canonical-vat="{{ $invoice->vat_amount }}" data-canonical-total="{{ $invoice->total_amount }}">
                        @if ($invoice->vat_enabled)
                            <tr>
                                <td></td>
                                <td>{{ __('invoices.print.subtotal') }}</td>
                                <td>{{ $formatMoney($invoice->subtotal_amount) }}</td>
                            </tr>
                            <tr>
                                <td></td>
                                <td>{{ __('invoices.print.vat', ['rate' => $vatRateLabel]) }}</td>
                                <td>{{ $formatMoney($invoice->vat_amount) }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td></td>
                            <td>{{ __('invoices.print.total') }}</td>
                            <td>{{ $formatMoney($invoice->total_amount) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </section>

            <section class="signature">
                <div class="signature-director">{{ __('invoices.print.director') }}: {{ __('invoices.print.signature_placeholder') }}</div>
                <div class="signature-line" aria-hidden="true"></div>
                <div class="signature-stamp">{{ __('invoices.print.stamp') }}</div>
            </section>

            <footer class="invoice-footer">{{ __('invoices.print.footer') }}</footer>
        </div>
    </main>
</body>

</html>
