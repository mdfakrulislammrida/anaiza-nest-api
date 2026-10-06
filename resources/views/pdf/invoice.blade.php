<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    {{-- An invoice, not a brochure: one colour (black), one typeface, no decoration. --}}
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #000; font-size: 12px; line-height: 1.5; }
        .header { width: 100%; margin-bottom: 24px; }
        .header td { vertical-align: top; }
        .logo { max-height: 48px; }
        .site-name { font-size: 18px; font-weight: bold; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 16px; }
        table.items th { text-align: left; border-bottom: 1px solid #000; padding: 6px 4px; font-size: 11px; font-weight: bold; }
        table.items td { border-bottom: 1px solid #000; padding: 6px 4px; }
        table.totals { width: 260px; margin-left: auto; margin-top: 12px; border-collapse: collapse; }
        table.totals td { padding: 4px; }
        table.totals .grand { font-weight: bold; font-size: 14px; border-top: 1px solid #000; }
        .addr-box { margin-top: 24px; width: 100%; }
        .addr-box td { vertical-align: top; width: 50%; }
        .label { font-size: 11px; font-weight: bold; margin: 0 0 4px; }
        .footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid #000; text-align: center; font-size: 11px; }
    </style>
</head>
<body>
    @php($logo = \App\Support\MediaUrl::forPdf($siteSetting?->logo_mono ?: ($siteSetting?->logo_navy ?: $siteSetting?->logo_url)))
    <table class="header">
        <tr>
            <td>
                @if ($logo)
                    <img src="{{ $logo }}" class="logo">
                @else
                    <div class="site-name">{{ $siteSetting?->site_name ?? config('app.name') }}</div>
                @endif
                @if ($siteSetting?->address)
                    <div>{{ $siteSetting->address }}</div>
                @endif
                @if ($siteSetting?->contact_phone || $siteSetting?->contact_email)
                    <div>
                        {{ $siteSetting->contact_phone }}
                        @if ($siteSetting?->contact_phone && $siteSetting?->contact_email) &middot; @endif
                        {{ $siteSetting->contact_email }}
                    </div>
                @endif
            </td>
            <td align="right">
                <h1>Invoice</h1>
                <div>Order #{{ $order->id }}</div>
                <div>{{ $order->created_at->format('d M Y, h:i A') }}</div>
            </td>
        </tr>
    </table>

    <table class="addr-box">
        <tr>
            <td>
                <p class="label">Bill to</p>
                {{ $order->customer->name }}<br>
                {{ $order->customer->phone }}<br>
                @if ($order->customer->email){{ $order->customer->email }}<br>@endif
                {{ $order->customer->address }}<br>
                {{ $order->customer->thana }}, {{ $order->customer->district }}, {{ $order->customer->division }}
            </td>
            <td align="right">
                <p class="label">Payment method</p>
                {{ $order->payment_method === 'cod' ? 'Cash on delivery' : (\App\Support\WalletPayments::LABELS[$order->payment_method] ?? strtoupper($order->payment_method)) }}
                <p class="label" style="margin-top:12px;">Status</p>
                {{ ucfirst($order->status) }}
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Item</th>
                <th>Qty</th>
                <th align="right">Unit price</th>
                <th align="right">Line total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td>
                        {{ $item->product_name }}
                        @if ($item->variant_name)
                            <br>{{ $item->variant_name }}: {{ $item->variant_value }}
                        @endif
                    </td>
                    <td>{{ $item->quantity }}</td>
                    <td align="right">৳{{ number_format($item->price) }}</td>
                    <td align="right">৳{{ number_format($item->price * $item->quantity) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td align="right">৳{{ number_format($order->subtotal) }}</td>
        </tr>
        <tr>
            <td>Delivery fee</td>
            <td align="right">৳{{ number_format($order->delivery_fee) }}</td>
        </tr>
        <tr class="grand">
            <td>Total</td>
            <td align="right">৳{{ number_format($order->total) }}</td>
        </tr>
    </table>

    <div class="footer">
        {{ $siteSetting?->site_name ?? config('app.name') }} &middot; Thank you for your order.
    </div>
</body>
</html>
