<!DOCTYPE html>
{{-- Phase 6.4 — Arabic-text rendering fix.
     The previous template used Helvetica/Arial which DomPDF resolves to
     PostScript core fonts that have NO Arabic glyphs — every Arabic
     codepoint rendered as `?`. This template:
       1. Declares lang="ar" + dir="rtl" so DomPDF's HTML5 parser routes
          the document through its bidi handler.
       2. Registers the locally-bundled Cairo font (storage/fonts/) via
          @font-face. Cairo includes Arabic + Latin glyphs as separate
          subset files; we register both under one family. DejaVu Sans
          (bundled with DomPDF) is the final fallback for any glyph
          neither Cairo subset covers — it also has Arabic coverage.
       3. Applies the Arabic-aware font stack on body so every text
          block inherits it. Numbers, SKUs, and barcodes keep their
          monospace styling. --}}
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>Shipping label — {{ $order->order_number }}</title>
    <style>
        /* Local Cairo (Arabic + Latin subsets, regular + bold).
           Files live in storage/fonts/ and are loaded via absolute
           filesystem path; enable_remote = false in dompdf.php means
           we cannot use HTTP URLs for fonts. */
        @font-face {
            font-family: 'Cairo';
            font-weight: normal;
            font-style: normal;
            src: url('{{ storage_path('fonts/Cairo-Arabic-Regular.ttf') }}') format('truetype');
        }
        @font-face {
            font-family: 'Cairo';
            font-weight: bold;
            font-style: normal;
            src: url('{{ storage_path('fonts/Cairo-Arabic-Bold.ttf') }}') format('truetype');
        }
        @font-face {
            font-family: 'CairoLatin';
            font-weight: normal;
            font-style: normal;
            src: url('{{ storage_path('fonts/Cairo-Latin-Regular.ttf') }}') format('truetype');
        }
        @font-face {
            font-family: 'CairoLatin';
            font-weight: bold;
            font-style: normal;
            src: url('{{ storage_path('fonts/Cairo-Latin-Bold.ttf') }}') format('truetype');
        }

        /* 4x6 inches @ 72dpi = 288 x 432 points (set in PHP via setPaper) */
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 8px;
            /* Arabic glyphs come from Cairo; Latin & digits fall back
               to CairoLatin first, then DejaVu Sans (DomPDF bundled,
               also has Arabic coverage as a safety net). */
            font-family: 'CairoLatin', 'Cairo', 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: #111;
        }
        .row { display: block; margin-bottom: 4px; }
        .header { border-bottom: 2px solid #111; padding-bottom: 4px; margin-bottom: 6px; }
        .header h1 { margin: 0; font-size: 16px; }
        .header .small { font-size: 9px; color: #555; }
        .tracking {
            /* Tracking numbers stay monospace + LTR; DejaVu Sans Mono
               is bundled and renders ASCII reliably. */
            font-family: 'DejaVu Sans Mono', Courier, monospace;
            direction: ltr;
            unicode-bidi: embed;
            font-size: 14px;
            font-weight: bold;
            background: #111;
            color: #fff;
            padding: 4px 6px;
            margin: 4px 0;
            text-align: center;
            letter-spacing: 1px;
        }
        .barcode {
            text-align: center;
            margin: 6px 0;
            font-family: 'Libre Barcode 128', 'Code 128', monospace;
            font-size: 32px;
            letter-spacing: -1px;
        }
        .barcode-fallback {
            text-align: center;
            font-family: 'DejaVu Sans Mono', Courier, monospace;
            direction: ltr;
            unicode-bidi: embed;
            font-size: 10px;
            border: 1px solid #111;
            padding: 4px;
            margin: 4px 0;
        }
        .label { text-transform: uppercase; font-size: 8px; color: #888; }
        .value { font-size: 11px; }
        .box { border: 1px solid #111; padding: 5px; margin-bottom: 5px; }
        .totals { margin-top: 8px; border-top: 1px dashed #555; padding-top: 4px; font-size: 10px; }
        .totals .total { font-weight: bold; font-size: 13px; }
        .footer { margin-top: 8px; font-size: 8px; color: #666; text-align: center; }
        .pill { display: inline-block; padding: 1px 5px; border: 1px solid #111; border-radius: 8px; font-size: 8px; }
        /* Numbers / SKUs that should not be reordered by RTL embedding. */
        .ltr { direction: ltr; unicode-bidi: embed; display: inline-block; }
        .sku { font-family: 'DejaVu Sans Mono', Courier, monospace; font-size: 9px; direction: ltr; unicode-bidi: embed; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ config('app.name') }}</h1>
        <span class="small">Order <span class="ltr">{{ $order->order_number }}</span> · <span class="ltr">{{ now()->format('Y-m-d H:i') }}</span></span>
        @if($order->customer_risk_level === 'High')
            <span class="pill" style="float:left;border-color:#a00;color:#a00;">HIGH RISK</span>
        @endif
    </div>

    <div class="tracking">{{ $tracking }}</div>

    <div class="barcode-fallback">|||| {{ $barcode_value }} ||||</div>

    <div class="box">
        <div class="label">Ship to</div>
        <div class="value" style="font-weight:bold;">{{ $order->customer_name }}</div>
        <div class="value">{{ $order->customer_address }}</div>
        <div class="value">{{ $order->city }}{{ $order->governorate ? ', '.$order->governorate : '' }}</div>
        <div class="value">{{ $order->country }}</div>
        <div class="value">📞 <span class="ltr">{{ $order->customer_phone }}</span></div>
    </div>

    <div class="box">
        <div class="label">Carrier</div>
        <div class="value">{{ $shipment->shippingCompany?->name ?? '—' }}</div>
    </div>

    <div class="box">
        <div class="label">Items ({{ $order->items->count() }})</div>
        @foreach($order->items as $item)
            <div class="row">
                <span class="sku">{{ $item->sku }}</span>
                · <span class="ltr">{{ $item->quantity }}×</span> {{ \Illuminate\Support\Str::limit($item->product_name, 40) }}
            </div>
        @endforeach
    </div>

    <div class="totals">
        <div class="row">Subtotal: {{ $currency_symbol }}<span class="ltr">{{ number_format((float) $order->subtotal, 2) }}</span></div>
        @if((float) $order->shipping_amount > 0)
            <div class="row">Shipping: {{ $currency_symbol }}<span class="ltr">{{ number_format((float) $order->shipping_amount, 2) }}</span></div>
        @endif
        <div class="row total">
            COD due: {{ $currency_symbol }}<span class="ltr">{{ number_format((float) $order->cod_amount, 2) }}</span>
        </div>
    </div>

    @if($order->notes)
        <div class="box">
            <div class="label">Customer notes</div>
            <div class="value">{{ \Illuminate\Support\Str::limit($order->notes, 120) }}</div>
        </div>
    @endif

    <div class="footer">
        Generated <span class="ltr">{{ now()->toDateTimeString() }}</span> · <span class="ltr">{{ $qr_value }}</span>
    </div>
</body>
</html>
