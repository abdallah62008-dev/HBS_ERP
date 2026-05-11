<!DOCTYPE html>
{{-- Phase 6.5 — Shipping label rendered by mPDF (not DomPDF).

     mPDF has native UAX-#9 bidi and Arabic letter-shaping, so
     customer-facing Arabic text (names, addresses, notes) reads
     correctly without any PHP-side string manipulation.

     Document direction is LTR; per-element direction is controlled
     by the .rtl-text helper class. Numeric / Latin runs (order
     number, SKU, tracking, phone, dates, totals) stay LTR via the
     .ltr helper.

     Fonts (cairo + cairolatin) are registered by ShippingService
     in the Mpdf constructor — DO NOT add @font-face here.

     Page geometry (4×6 inches) is also set by Mpdf's `format`
     option — DO NOT add an @page rule here. --}}
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Shipping label — {{ $order->order_number }}</title>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: cairolatin, 'DejaVu Sans', sans-serif;
            font-size: 9px;
            line-height: 1.25;
            color: #111;
        }

        /* ===== Direction helpers ===== */
        .rtl-text {
            /* mPDF runs the bidi algorithm + Arabic shaping for any
               element whose direction is rtl. The Arabic font is
               DejaVu Sans (bundled with mPDF, full Arabic coverage
               via GSUB letter-joining tables). cairolatin is the
               Latin/digit fallback. */
            direction: rtl;
            text-align: right;
            font-family: dejavusans, cairolatin, sans-serif;
        }
        .ltr {
            direction: ltr;
            unicode-bidi: embed;
        }

        /* ===== Sections ===== */
        .row { display: block; margin: 0 0 1px 0; }

        .header {
            border-bottom: 1px solid #111;
            padding-bottom: 2px;
            margin-bottom: 3px;
        }
        .header h1 {
            margin: 0;
            font-size: 12px;
            line-height: 1.1;
            font-weight: bold;
        }
        .header .small { font-size: 8px; color: #555; }

        .tracking {
            font-family: 'DejaVu Sans Mono', monospace;
            direction: ltr;
            font-size: 11px;
            font-weight: bold;
            background: #111;
            color: #fff;
            padding: 2px 4px;
            margin: 2px 0;
            text-align: center;
            letter-spacing: 0.5px;
        }

        /* Order barcode block — uses mPDF's native <barcode> tag.
           The .barcode-text below the barcode shows the readable
           order number in monospace. */
        .order-barcode {
            text-align: center;
            margin: 1px 0 3px 0;
        }
        .order-barcode .label-row {
            font-size: 7px;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 1px;
        }
        .order-barcode .barcode-text {
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 1px;
            direction: ltr;
            line-height: 1.1;
            margin-top: 1px;
        }

        .label {
            text-transform: uppercase;
            font-size: 7px;
            color: #888;
            letter-spacing: 0.5px;
            margin-bottom: 0;
        }
        .value { font-size: 10px; }
        .box {
            border: 1px solid #111;
            padding: 2px 5px;
            margin-bottom: 2px;
        }

        /* Totals — force LTR end-to-end. The currency symbol "ج.م"
           is Arabic script; without an explicit LTR baseline on the
           row, mPDF's bidi algorithm reorders the row so the Arabic
           run pulls the English "Subtotal:" label to the right
           ("ج.م 3,000.00 :Subtotal"). Wrapping each row as an LTR
           paragraph + putting label + value in their own LTR spans
           pins the visual order. */
        .totals {
            direction: ltr;
            text-align: left;
            margin-top: 3px;
            border-top: 1px dashed #555;
            padding-top: 2px;
            font-size: 9px;
        }
        .totals .total-row {
            direction: ltr;
            unicode-bidi: embed;
            display: block;
            margin: 0 0 1px 0;
        }
        .totals .total-row.total {
            font-weight: bold;
            font-size: 11px;
        }
        .totals .total-label {
            direction: ltr;
            unicode-bidi: embed;
        }
        .totals .total-value {
            direction: ltr;
            unicode-bidi: embed;
        }

        .footer {
            margin-top: 2px;
            font-size: 7px;
            color: #666;
            text-align: center;
        }

        .pill {
            display: inline-block;
            padding: 1px 4px;
            border: 1px solid #111;
            border-radius: 7px;
            font-size: 7.5px;
        }
        .sku {
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 8.5px;
            direction: ltr;
        }
    </style>
</head>
<body>
    {{-- HEADER (LTR — Latin app name + ASCII order number + date) --}}
    <div class="header">
        <h1>{{ config('app.name') }}</h1>
        <span class="small">
            Order <span class="ltr">{{ $order->order_number }}</span>
            ·
            <span class="ltr">{{ now()->format('Y-m-d H:i') }}</span>
        </span>
        @if($order->customer_risk_level === 'High')
            <span class="pill" style="float:right;border-color:#a00;color:#a00;">HIGH RISK</span>
        @endif
    </div>

    {{-- TRACKING (carrier tracking number — LTR monospace) --}}
    <div class="tracking">{{ $tracking }}</div>

    {{-- ORDER BARCODE — real Code 128 barcode rendered natively by
         mPDF. The order number prints below as readable text. --}}
    <div class="order-barcode">
        <div class="label-row">Order barcode</div>
        <barcode code="{{ $order->order_number }}" type="C128A" size="0.7" height="0.5" />
        <div class="barcode-text">{{ $order->order_number }}</div>
    </div>

    {{-- SHIP-TO — Arabic content gets .rtl-text. mPDF auto-shapes
         Arabic letters and runs UAX-#9 bidi. Phone stays LTR. --}}
    <div class="box">
        <div class="label">Ship to</div>
        <div class="value rtl-text" style="font-weight:bold;">{{ $order->customer_name }}</div>
        <div class="value rtl-text">{{ $order->customer_address }}</div>
        <div class="value rtl-text">{{ $order->city }}{{ $order->governorate ? ', '.$order->governorate : '' }}</div>
        <div class="value rtl-text">{{ $order->country }}</div>
        <div class="value">Tel: <span class="ltr">{{ $order->customer_phone }}</span></div>
    </div>

    {{-- CARRIER — may be Arabic name. --}}
    <div class="box">
        <div class="label">Carrier</div>
        <div class="value rtl-text">{{ $shipment->shippingCompany?->name ?? '—' }}</div>
    </div>

    {{-- ITEMS — SKU stays LTR; product name may be Arabic. --}}
    <div class="box">
        <div class="label">Items ({{ $order->items->count() }})</div>
        @foreach($order->items as $item)
            <div class="row">
                <span class="sku">{{ $item->sku }}</span>
                · <span class="ltr">{{ $item->quantity }}×</span>
                <span class="rtl-text" style="display:inline;">{{ \Illuminate\Support\Str::limit($item->product_name, 40) }}</span>
            </div>
        @endforeach
    </div>

    {{-- TOTALS — LTR block; each row is an explicit (label, value)
         pair so the Arabic currency symbol can't reorder the row. --}}
    <div class="totals ltr">
        <div class="total-row">
            <span class="total-label">Subtotal:</span>
            <span class="total-value">{{ number_format((float) $order->subtotal, 2) }} {{ $currency_symbol }}</span>
        </div>
        @if((float) $order->shipping_amount > 0)
            <div class="total-row">
                <span class="total-label">Shipping:</span>
                <span class="total-value">{{ number_format((float) $order->shipping_amount, 2) }} {{ $currency_symbol }}</span>
            </div>
        @endif
        <div class="total-row total">
            <span class="total-label">COD due:</span>
            <span class="total-value">{{ number_format((float) $order->cod_amount, 2) }} {{ $currency_symbol }}</span>
        </div>
    </div>

    {{-- NOTES — Arabic content via .rtl-text. Truncated to keep
         the label on one page. --}}
    @if($order->notes)
        <div class="box">
            <div class="label">Customer notes</div>
            <div class="value rtl-text">{{ \Illuminate\Support\Str::limit($order->notes, 60) }}</div>
        </div>
    @endif

    {{-- FOOTER — LTR ASCII. --}}
    <div class="footer">
        Generated <span class="ltr">{{ now()->toDateTimeString() }}</span>
        ·
        <span class="ltr">{{ $qr_value }}</span>
    </div>
</body>
</html>
