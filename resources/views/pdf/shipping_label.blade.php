<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Shipping label — {{ $order->order_number }}</title>
    <style>
        /* 4x6 inches @ 72dpi = 288 x 432 points (set in PHP via setPaper) */
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 8px;
            font-family: Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #111;
        }
        .row { display: block; margin-bottom: 4px; }
        .header { border-bottom: 2px solid #111; padding-bottom: 4px; margin-bottom: 6px; }
        .header h1 { margin: 0; font-size: 16px; }
        .header .small { font-size: 9px; color: #555; }
        .tracking {
            font-family: Courier, monospace;
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
            font-family: Courier, monospace;
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
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ config('app.name') }}</h1>
        <span class="small">Order {{ $order->order_number }} · {{ now()->format('Y-m-d H:i') }}</span>
        @if($order->customer_risk_level === 'High')
            <span class="pill" style="float:right;border-color:#a00;color:#a00;">HIGH RISK</span>
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
        <div class="value">📞 {{ $order->customer_phone }}</div>
    </div>

    <div class="box">
        <div class="label">Carrier</div>
        <div class="value">{{ $shipment->shippingCompany?->name ?? '—' }}</div>
    </div>

    <div class="box">
        <div class="label">Items ({{ $order->items->count() }})</div>
        @foreach($order->items as $item)
            <div class="row">
                <span style="font-family:Courier,monospace;font-size:9px;">{{ $item->sku }}</span>
                · {{ $item->quantity }}× {{ \Illuminate\Support\Str::limit($item->product_name, 40) }}
            </div>
        @endforeach
    </div>

    <div class="totals">
        <div class="row">Subtotal: {{ $currency_symbol }}{{ number_format((float) $order->subtotal, 2) }}</div>
        @if((float) $order->shipping_amount > 0)
            <div class="row">Shipping: {{ $currency_symbol }}{{ number_format((float) $order->shipping_amount, 2) }}</div>
        @endif
        <div class="row total">
            COD due: {{ $currency_symbol }}{{ number_format((float) $order->cod_amount, 2) }}
        </div>
    </div>

    @if($order->notes)
        <div class="box">
            <div class="label">Customer notes</div>
            <div class="value">{{ \Illuminate\Support\Str::limit($order->notes, 120) }}</div>
        </div>
    @endif

    <div class="footer">
        Generated {{ now()->toDateTimeString() }} · {{ $qr_value }}
    </div>
</body>
</html>
