<!DOCTYPE html>
<html lang="{{ $locale ?? 'sk' }}">
<head>
    <meta charset="UTF-8">
    @php
        $primaryColor = $invoiceColor ?? $theme->primaryColor();
        $lightBg = $theme->lightBg();
        $lightBorder = $theme->lightBorder();
        $buyerCountry = $buyer['country_code'] ?? null;
        $currencySymbol = \App\Enums\Common\CurrencyEnum::tryFrom($invoice->currency)?->symbol() ?? $invoice->currency;
        $baseCurrencySymbol = \App\Enums\Common\CurrencyEnum::tryFrom($invoice->company->default_currency)?->symbol() ?? $invoice->company->default_currency;
    @endphp
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: #333;
            line-height: 1.4;
        }

        .container {
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }

        .header-left {
            width: 60%;
        }

        .header-right {
            width: 35%;
            text-align: right;
        }

        .logo {
            max-height: 60px;
            max-width: 200px;
            margin-bottom: 8px;
        }

        .invoice-title {
            font-size: 24px;
            font-weight: bold;
            color: {{ $primaryColor }};
            margin-bottom: 5px;
        }

        .invoice-number {
            font-size: 14px;
            color: #666;
        }

        .parties {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
        }

        .party {
            flex: 1;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
        }

        .party-label {
            font-size: 8px;
            text-transform: uppercase;
            color: #9ca3af;
            letter-spacing: 1px;
            margin-bottom: 6px;
        }

        .party-name {
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .dates-bank-row {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
        }

        .dates {
            width: 32%;
            padding: 12px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
        }

        .date-item {
            margin-bottom: 8px;
        }

        .date-item:last-child {
            margin-bottom: 0;
        }

        .date-label {
            font-size: 8px;
            text-transform: uppercase;
            color: #9ca3af;
        }

        .date-value {
            font-size: 11px;
            font-weight: bold;
        }

        .bank-info {
            flex: 1;
            padding: 12px;
            background: {{ $lightBg }};
            border: 1px solid{{ $lightBorder }};
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .bank-info-details {
            flex: 1;
        }

        .bank-info-title {
            font-size: 10px;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .bank-info-qr {
            margin-left: 16px;
            text-align: center;
        }

        .bank-info-qr img {
            width: 120px;
            height: 120px;
        }

        .bank-info-qr .qr-label {
            font-size: 7px;
            color: #6b7280;
            margin-top: 2px;
        }

        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        table.items th {
            background: {{ $primaryColor }};
            color: white;
            padding: 8px 6px;
            font-size: 9px;
            text-transform: uppercase;
            text-align: left;
        }

        table.items td {
            padding: 7px 6px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 10px;
        }

        table.items tr:nth-child(even) {
            background: #f9fafb;
        }

        .text-right {
            text-align: right;
        }

        .totals {
            width: 300px;
            margin-left: auto;
        }

        .totals table {
            width: 100%;
        }

        .totals td {
            padding: 5px 8px;
            font-size: 11px;
        }

        .totals .total-row {
            font-size: 14px;
            font-weight: bold;
            border-top: 2px solid{{ $primaryColor }};
        }

        .notes {
            margin-top: 15px;
            padding: 10px;
            background: #f9fafb;
            border-radius: 4px;
            font-size: 9px;
        }

        .signature {
            width: 200px;
            margin-left: auto;
            margin-top: 30px;
            text-align: center;
        }

        .signature img {
            max-height: 80px;
            max-width: 180px;
            margin-bottom: 4px;
        }

        .signature .sig-name {
            font-size: 10px;
            font-weight: bold;
            color: #333;
        }

        .signature .sig-company {
            font-size: 9px;
            color: #6b7280;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-left">
            <div class="invoice-title">{{ __('invoice.title') }}</div>
            <div class="invoice-number">{{ $invoice->invoice_number }}</div>
        </div>
        <div class="header-right">
            @if(!empty($logoBase64))
                <img src="{{ $logoBase64 }}" class="logo" alt="Logo">
            @endif
        </div>
    </div>

    <div class="parties">
        <div class="party">
            <div class="party-label">{{ __('invoice.supplier') }}</div>
            <div class="party-name">{{ $seller['name'] ?? '' }}</div>
            @if(!empty($seller['street']))
                <div>{{ $seller['street'] }}</div>
            @endif
            @if(!empty($seller['zip']) || !empty($seller['city']))
                <div>{{ $seller['zip'] ?? '' }} {{ $seller['city'] ?? '' }}</div>
            @endif
            @if(!empty($seller['business_number']))
                <div style="margin-top:4px">{{ __('invoice.ico') }}: {{ $seller['business_number'] }}</div>
            @endif
            @if(!empty($seller['tax_number']))
                <div>{{ __('invoice.dic') }}: {{ $seller['tax_number'] }}</div>
            @endif
            @if(!empty($seller['vat_number']))
                <div>{{ __('invoice.ic_dph') }}: {{ $seller['vat_number'] }}</div>
            @endif
            @if(!empty($seller['email']))
                <div style="margin-top:4px">{{ $seller['email'] }}</div>
            @endif
            @if(!empty($seller['phone']))
                <div>{{ $seller['phone'] }}</div>
            @endif
        </div>
        <div class="party">
            <div class="party-label">{{ __('invoice.buyer') }}</div>
            <div class="party-name">{{ $buyer['name'] ?? '' }}</div>
            @if(!empty($buyer['company_name']))
                <div>{{ $buyer['company_name'] }}</div>
            @endif
            @if(!empty($buyer['street']))
                <div>{{ $buyer['street'] }}</div>
            @endif
            @if(!empty($buyer['zip']) || !empty($buyer['city']))
                <div>{{ $buyer['zip'] ?? '' }} {{ $buyer['city'] ?? '' }}</div>
            @endif
            @if(!empty($buyer['business_number']))
                <div style="margin-top:4px">{{ __('invoice.ico') }}: {{ $buyer['business_number'] }}</div>
            @endif
            @if(!empty($buyer['tax_number']))
                <div>{{ __('invoice.dic') }}: {{ $buyer['tax_number'] }}</div>
            @endif
            @if(!empty($buyer['vat_number']))
                <div>{{ __('invoice.ic_dph') }}: {{ $buyer['vat_number'] }}</div>
            @endif
            @if(!empty($buyer['email']))
                <div style="margin-top:4px">{{ $buyer['email'] }}</div>
            @endif
            @if(!empty($buyer['phone']))
                <div>{{ $buyer['phone'] }}</div>
            @endif
            @if(!empty($buyer['web']))
                <div>{{ $buyer['web'] }}</div>
            @endif
        </div>
    </div>

    <div class="dates-bank-row">
        <div class="dates">
            <div class="date-item">
                <div class="date-label">{{ __('invoice.issue_date') }}</div>
                <div class="date-value">{{ $invoice->issue_date->format('d.m.Y') }}</div>
            </div>
            <div class="date-item">
                <div class="date-label">{{ __('invoice.due_date') }}</div>
                <div class="date-value">{{ $invoice->due_date->format('d.m.Y') }}</div>
            </div>
            @if($invoice->delivery_date)
                <div class="date-item">
                    <div class="date-label">{{ __('invoice.delivery_date') }}</div>
                    <div class="date-value">{{ $invoice->delivery_date->format('d.m.Y') }}</div>
                </div>
            @endif
            @if($invoice->payment_method)
                <div class="date-item">
                    <div class="date-label">{{ __('invoice.payment_method') }}</div>
                    <div class="date-value">{{ $invoice->payment_method->translation() }}</div>
                </div>
            @endif
            @if($invoice->order_number)
                <div class="date-item">
                    <div class="date-label">{{ __('invoice.order_number') }}</div>
                    <div class="date-value">{{ $invoice->order_number }}</div>
                </div>
            @endif
        </div>

        @if($invoice->payment_method === \App\Enums\Invoices\PaymentMethodEnum::BANK_TRANSFER && (!empty($seller['bank_iban']) || !empty($seller['bank_account_number'])))
            <div class="bank-info">
                <div class="bank-info-details">
                    <div class="bank-info-title">{{ __('invoice.bank_info') }}</div>
                    @if(!empty($seller['bank_name']))
                        <div>{{ __('invoice.bank_name') }}: {{ $seller['bank_name'] }}</div>
                    @endif
                    @if(!empty($seller['bank_account_number']))
                        <div>{{ __('invoice.account_number') }}: {{ $seller['bank_account_number'] }}</div>
                    @endif
                    @if(!empty($seller['bank_iban']))
                        <div>{{ __('invoice.iban') }}: {{ $seller['bank_iban'] }}</div>
                    @endif
                    @if(!empty($seller['bank_swift']))
                        <div>{{ __('invoice.swift') }}: {{ $seller['bank_swift'] }}</div>
                    @endif
                    <div>{{ __('invoice.variable_symbol') }}
                        : {{ preg_replace('/\D/', '', $invoice->invoice_number) }}</div>
                </div>
                @if(!empty($qrBase64))
                    <div class="bank-info-qr">
                        <img src="data:image/png;base64,{{ $qrBase64 }}" alt="QR">
                        <div class="qr-label">{{ $buyerCountry === 'SK' ? 'Pay by Square' : ($buyerCountry === 'CZ' ? 'QR Platba' : 'QR') }}</div>
                    </div>
                @endif
            </div>
        @endif
    </div>

    @if($textBeforeItems)
        <div style="margin-bottom: 12px; font-size: 10px; line-height: 1.5;">{!! $textBeforeItems !!}</div>
    @endif

    <table class="items">
        <thead>
        <tr>
            <th style="width:5%">#</th>
            <th>{{ __('invoice.description') }}</th>
            <th class="text-right" style="width:10%">{{ __('invoice.quantity') }}</th>
            <th style="width:8%">{{ __('invoice.unit') }}</th>
            <th class="text-right" style="width:12%">{{ __('invoice.unit_price') }}</th>
            @if($showVat)
                <th class="text-right" style="width:8%">{{ __('invoice.vat_percent') }}</th>
                <th class="text-right" style="width:10%">{{ __('invoice.vat') }}</th>
            @endif
            <th class="text-right" style="width:12%">{{ __('invoice.total') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($items as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item['description'] }}</td>
                <td class="text-right">{{ number_format((float)$item['quantity'], 2, ',', ' ') }}</td>
                <td>{{ $item['unit'] ?? '' }}</td>
                <td class="text-right">{{ number_format((float)$item['unit_price'], 2, ',', ' ') }} {{ $currencySymbol }}</td>
                @if($showVat)
                    <td class="text-right">{{ number_format((float)$item['vat_rate_value'], 0) }}%</td>
                    <td class="text-right">{{ number_format((float)$item['vat_amount'], 2, ',', ' ') }} {{ $currencySymbol }}</td>
                @endif
                <td class="text-right">{{ number_format((float)$item['total'], 2, ',', ' ') }} {{ $currencySymbol }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @if($textAfterItems)
        <div style="margin-bottom: 12px; font-size: 10px; line-height: 1.5;">{!! $textAfterItems !!}</div>
    @endif

    <div class="totals">
        <table>
            @if($showVat)
                <tr>
                    <td>{{ __('invoice.subtotal') }}:</td>
                    <td class="text-right">{{ number_format((float)$invoice->subtotal, 2, ',', ' ') }} {{ $currencySymbol }}</td>
                </tr>
                <tr>
                    <td>{{ __('invoice.vat_label') }}:</td>
                    <td class="text-right">{{ number_format((float)$invoice->vat_total, 2, ',', ' ') }} {{ $currencySymbol }}</td>
                </tr>
            @endif
            <tr class="total-row">
                <td>{{ __('invoice.total') }}:</td>
                <td class="text-right">{{ number_format((float)$invoice->total, 2, ',', ' ') }} {{ $currencySymbol }}</td>
            </tr>
        </table>
        @if($invoice->exchange_rate && $invoice->currency !== $invoice->company->default_currency)
            <table style="margin-top: 10px; border-top: 1px solid #e5e7eb; padding-top: 6px;">
                <tr>
                    <td colspan="2" style="font-size: 9px; color: #6b7280; padding-bottom: 4px;">
                        {{ __('invoice.exchange_rate') }}: 1 {{ $currencySymbol }}
                        = {{ number_format((float)$invoice->exchange_rate, 4, ',', ' ') }} {{ $baseCurrencySymbol }}
                    </td>
                </tr>
                @if($showVat)
                    <tr>
                        <td>{{ __('invoice.subtotal_base', ['currency' => $baseCurrencySymbol]) }}:</td>
                        <td class="text-right">{{ number_format((float)$invoice->subtotal_base, 2, ',', ' ') }} {{ $baseCurrencySymbol }}</td>
                    </tr>
                    <tr>
                        <td>{{ __('invoice.vat_base', ['currency' => $baseCurrencySymbol]) }}:</td>
                        <td class="text-right">{{ number_format((float)$invoice->vat_total_base, 2, ',', ' ') }} {{ $baseCurrencySymbol }}</td>
                    </tr>
                @endif
                <tr style="font-weight: bold;">
                    <td>{{ __('invoice.base_currency_totals', ['currency' => $baseCurrencySymbol]) }}:</td>
                    <td class="text-right">{{ number_format((float)$invoice->total_base, 2, ',', ' ') }} {{ $baseCurrencySymbol }}</td>
                </tr>
            </table>
        @endif
    </div>

    @if(!empty($signatureBase64))
        <div class="signature">
            <img src="{{ $signatureBase64 }}" alt="Signature">
            @if(!empty($seller['responsible_person']))
                <div class="sig-name">{{ $seller['responsible_person'] }}</div>
            @endif
            <div class="sig-company">{{ $seller['name'] ?? '' }}</div>
        </div>
    @endif

    @if($hasReverseCharge)
        <div style="margin-top: 15px; padding: 10px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 4px; font-size: 10px; font-weight: bold; color: #374151;">
            {{ __('invoice.reverse_charge_notice') }}<br>
            <span style="font-weight: normal; font-size: 9px;">{{ __('invoice.reverse_charge_law') }}</span>
        </div>
    @endif

    @if(!($seller['is_vat_payer'] ?? true))
        <div style="margin-top: 15px; padding: 10px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 4px; font-size: 10px; font-weight: bold; color: #374151;">
            {{ __('invoice.not_vat_payer_notice') }}
        </div>
    @endif

    @if($invoice->notes)
        <div class="notes">
            <strong>{{ __('invoice.notes') }}:</strong><br>
            {{ $invoice->notes }}
        </div>
    @endif
</div>
</body>
</html>
